<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages;

use ElPandaPe\FilamentWarden\Conditions\Columns;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Tables\PermissionsTable;
use ElPandaPe\FilamentWarden\Grants\Holders;
use ElPandaPe\FilamentWarden\Grants\Probe;
use ElPandaPe\FilamentWarden\Grants\Reach;
use ElPandaPe\FilamentWarden\Support\Config;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class ViewPermission extends ViewRecord
{
    /**
     * The columns an account can be searched by, each with the whole clause it
     * turns into.
     *
     * Whole clauses rather than names because the ESCAPE below has to be spelled
     * out and the query builder has no way to say it — and written out here
     * rather than built, so what reaches `orWhereRaw()` is a literal this file
     * contains and never a column name from anywhere else.
     *
     * @var array<string, literal-string>
     */
    private const array SEARCHABLE = [
        'name' => "name like ? escape '!'",
        'email' => "email like ? escape '!'",
        'title' => "title like ? escape '!'",
    ];

    /**
     * What the bench is being asked, and what it answered.
     *
     * Two properties and not one: the question survives the answer, so somebody
     * can change the record and ask again without retyping the account — which
     * is the whole shape of using this thing, and the reason it stopped being a
     * modal. A modal threw the question away on every submit.
     *
     * @var array<string, mixed>
     */
    public array $ask = ['account' => null, 'record' => null];

    /**
     * The card, already worded.
     *
     * Strings and not a `Probe`: a Livewire property survives the round trip by
     * being serialised, and a readonly object holding two models would not come
     * back the same object. The wording happens once, where the store is, and
     * what travels is what the page prints.
     *
     * @var array<string, string|null>|null
     */
    public ?array $answered = null;

    protected static string $resource = PermissionResource::class;

    public static function accountLabel(mixed $value): ?string
    {
        $account = self::account($value);

        return $account instanceof Model ? Holders::label($account) : null;
    }

    /**
     * @return array<int|string, string>
     */
    public static function accounts(string $search): array
    {
        $model = Columns::authorityModel();

        // The header action is not offered without one, so this is the crafted
        // request rather than the screen.
        if ($model === null) {
            return [];
        }

        $clauses = array_values(array_intersect_key(self::SEARCHABLE, array_flip(Columns::texts($model))));

        // With nothing to search, the closure below added no condition at all
        // and the query answered with the first twenty accounts — every search
        // returning the same twenty names and addresses, and none of them what
        // was typed. A search nobody can perform returns nothing.
        if ($clauses === []) {
            return [];
        }

        // `%` and `_` are wildcards to LIKE, so a search for `%` matched every
        // row and the box was a way to page through the account table rather
        // than a way to find one in it.
        //
        // Escaping them takes an ESCAPE clause, and the character in it is the
        // part that had to be measured on three engines rather than one.
        //
        // A backslash is not portable, and exactly ONE engine is why:
        // `escape '\'` is a syntax error on MySQL, which reads the backslash
        // inside the string literal unless `NO_BACKSLASH_ESCAPES` is set.
        // SQLite and Postgres both take it as it stands (Postgres under its
        // default `standard_conforming_strings = on`). Doubling it to
        // `escape '\\'` satisfies MySQL and then breaks the other two, which
        // see two characters where one is required — so there is no backslash
        // literal that works everywhere (§6.38).
        //
        // `!` needs no escaping in a string literal on any of them, so the
        // clause is the same text for every driver. Measured on SQLite,
        // Postgres 16 and MySQL 8.4; `sqlsrv` takes ESCAPE too but nobody here
        // has run it. It has to be escaped in the term itself like the
        // wildcards, or a person searching for `!` would be typing an escape
        // character.
        //
        // And the clause cannot be dropped: SQLite has no default escape
        // character, so an escaped term without it matches nothing at all —
        // the search would go from too wide to permanently empty.
        $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

        $records = $model::query()
            ->where(static function (mixed $query) use ($clauses, $term): void {
                foreach ($clauses as $clause) {
                    $query->orWhereRaw($clause, [$term]);
                }
            })
            ->limit(20)
            ->get();

        $options = [];

        foreach ($records as $record) {
            $key = $record->getKey();

            if (is_int($key) || is_string($key)) {
                $options[$key] = Holders::label($record);
            }
        }

        return $options;
    }

    /**
     * The account a key names, or nothing.
     *
     * Public because the role screen's own hand-out reaches for it: the search
     * above hands back keys and something has to turn one back into a model,
     * and having two answers to that would be two places for a key that does
     * not read as a key to be handled differently.
     */
    public static function accountFor(mixed $key): ?Model
    {
        return self::account($key);
    }

    /**
     * The page, with the bench between the record and its relation managers.
     *
     * Written out rather than appended to `parent::content()`, because a
     * `Schema` has no "add one more" — `components()` replaces. The parent's two
     * branches are kept as they are, including the one where relation managers
     * are combined into tabs with the content: there the bench rides inside the
     * content tab, by `getContentTabComponent()`, so an installation that turns
     * that on does not silently lose it.
     */
    public function content(Schema $schema): Schema
    {
        if ($this->hasCombinedRelationManagerTabsWithContent()) {
            return $schema->components([$this->getRelationManagersContentComponent()]);
        }

        return $schema->components([
            $this->getInfolistContentComponent(),
            ...$this->bench(),
            $this->getRelationManagersContentComponent(),
        ]);
    }

    public function getContentTabComponent(): Tab
    {
        return parent::getContentTabComponent()->schema([
            $this->getInfolistContentComponent(),
            ...$this->bench(),
        ]);
    }

    /**
     * The test bench: `explain()` asked the way the application asks it, with a
     * real account and — when the permission has a model — a real row.
     *
     * On the page since 3.0, and no longer in a modal. The reason the modal
     * carried is gone: it was there for the searchable select, which any schema
     * gives, and what it cost was the whole shape of using this thing. Every
     * submit threw the question away, so changing only the record meant finding
     * the account again — and the answer arrived as a notification, beside the
     * screen rather than under the question it answers.
     */
    public function probeForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('ask')
            ->components([
                Section::make(__('filament-warden::ui.resources.permissions.probe.label'))
                    ->icon(Heroicon::OutlinedBeaker)
                    ->description(__('filament-warden::ui.resources.permissions.probe.description'))
                    ->columns(3)
                    ->schema([
                        Select::make('account')
                            ->label(__('filament-warden::ui.resources.permissions.probe.account'))
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing($this->accounts(...))
                            ->getOptionLabelUsing(static fn (mixed $value): ?string => self::accountLabel($value)),

                        TextInput::make('record')
                            ->label(__('filament-warden::ui.resources.permissions.probe.record'))
                            ->helperText(__('filament-warden::ui.resources.permissions.probe.record_help'))
                            ->visible(fn (): bool => $this->getRecord()->getAttribute('entity_type') !== null),

                        SchemaActions::make([
                            Action::make('ask')
                                ->label(__('filament-warden::ui.resources.permissions.probe.submit'))
                                ->icon(Heroicon::OutlinedBeaker)
                                ->action(function (): void {
                                    $this->answer();
                                }),
                        ])->key('bench')->verticallyAlignEnd(),
                    ]),

                Section::make(__('filament-warden::ui.resources.permissions.probe.answer'))
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    // A card that is not there is the honest empty state: the
                    // question has not been asked, so there is nothing to say
                    // and nothing to leave stale under the next question.
                    ->visible(fn (): bool => $this->answered !== null)
                    ->schema([
                        TextEntry::make('verdict')
                            ->hiddenLabel()
                            ->badge()
                            ->color(fn (): string => match ($this->answered['status'] ?? '') {
                                'granted' => 'success',
                                'forbidden' => 'danger',
                                default => 'warning',
                            })
                            ->state(fn (): string => $this->answered['verdict'] ?? ''),

                        TextEntry::make('summary')
                            ->hiddenLabel()
                            ->state(fn (): string => $this->answered['summary'] ?? ''),

                        ...$this->rows(),
                    ]),
            ]);
    }

    /**
     * Two actions, and both visibilities are written by hand.
     *
     * An edit or delete button asks `getEditAuthorizationResponse()` /
     * `getDeleteAuthorizationResponse()`, which go straight to the policy, and
     * the resource's `canEdit()` and `canDelete()` — where `permissions.update`
     * and the orphan rule live — are never on that path. The modal description
     * is the table's own, because a delete takes the grants with it below
     * Eloquent and this is the last moment anybody is told.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (Model $record): bool => PermissionResource::canEdit($record)),

            DeleteAction::make()
                ->modalDescription(static fn (Model $record): string => PermissionsTable::warning($record))
                ->visible(fn (Model $record): bool => PermissionResource::canDelete($record)),
        ];
    }

    private static function account(mixed $key): ?Model
    {
        $model = Columns::authorityModel();

        return $model === null || ! is_int($key) && ! is_string($key)
            ? null
            : $model::query()->whereKey($key)->first();
    }

    /**
     * Whether the bench is on the page at all.
     *
     * Two conditions and neither is the other: `permissions.probe` is a choice
     * an installation makes, and an account model that does not resolve is a
     * question nobody could put — the select would have nothing to search.
     */
    /**
     * @return array<int, Component>
     */
    private function bench(): array
    {
        return Config::enabled('permissions.probe') && Columns::authorityModel() !== null
            ? [EmbeddedSchema::make('probeForm')]
            : [];
    }

    /**
     * The four rows under the summary, each of which is absent rather than empty
     * when the store had nothing to put in it.
     *
     * A row with no content is a promise the answer did not make: a grant with
     * no conditions has no rule, a direct grant has no role to reach through,
     * and a grant with no date does not end. Drawing an em dash in those slots
     * would say "we looked and found nothing", which is only true of one of the
     * three.
     *
     * @return array<int, Component>
     */
    private function rows(): array
    {
        $rows = [
            'note' => 'filament-warden::ui.probe.narrowed_label',
            'rule' => 'filament-warden::ui.explain.matched',
            'via' => 'filament-warden::ui.resources.permissions.probe.via',
            'until' => 'filament-warden::ui.resources.permissions.probe.until',
            'reach' => 'filament-warden::ui.resources.permissions.probe.reach',
        ];

        $components = [];

        foreach ($rows as $key => $label) {
            $components[] = TextEntry::make($key)
                ->label(__($label))
                ->visible(fn (ViewPermission $livewire): bool => ($livewire->answered[$key] ?? null) !== null)
                ->state(fn (ViewPermission $livewire): string => (string) ($livewire->answered[$key] ?? ''));
        }

        return $components;
    }

    /**
     * Ask the store, and word the answer once.
     *
     * The reach is worked out here and nowhere else: one `whereCan()` costs a
     * handful of queries with no cache and no memo, so it happens when somebody
     * asks and never on a render.
     */
    private function answer(): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->getSchema('probeForm')?->getState() ?? [];

        $account = self::account($data['account'] ?? null);

        // Unreachable from the page, and kept anyway. The select validates its
        // value with `getInValidationRuleValues()`, which for a single
        // searchable field calls `getOptionLabel()` — this class's own
        // `accountLabel()`, resolving through the same `account()` — and returns
        // an empty list when it comes back blank, so `getState()` above throws
        // before this line for any key nobody could pick. Measured: a probe set
        // to a key that names no row never enters this method's body past the
        // validation.
        //
        // What it still answers is the race the validation cannot: the row
        // going away between that check and this one, two queries apart. And
        // without it `Probe::run()` would be handed a `?Model`, which is the
        // other reason it is not a comment.
        if (! $account instanceof Model) {
            return; // @codeCoverageIgnore
        }

        $record = $data['record'] ?? null;

        $probe = Probe::run(
            $account,
            $this->getRecord(),
            is_string($record) && $record !== '' ? $record : null,
        );

        $this->answered = [
            'status' => $probe->verdict->value,
            'verdict' => (string) __('filament-warden::ui.stances.'.$probe->verdict->value),
            'summary' => $probe->summary,
            'note' => $probe->note,
            'rule' => $probe->rule,
            'via' => $probe->via,
            'until' => $probe->until,
            'reach' => Reach::of($this->getRecord(), $account)->sentence(),
        ];
    }
}
