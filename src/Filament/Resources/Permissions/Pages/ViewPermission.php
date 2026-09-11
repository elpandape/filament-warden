<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages;

use Carbon\CarbonImmutable;
use ElPandaPe\FilamentWarden\Conditions\Columns;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Tables\PermissionsTable;
use ElPandaPe\FilamentWarden\Grants\Holders;
use ElPandaPe\FilamentWarden\Grants\Probe;
use ElPandaPe\FilamentWarden\Grants\Reach;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Support\Config;
use ElPandaPe\FilamentWarden\Support\Line;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
     * is why the modal embeds a separately named schema: submitting a test
     * preserves the account and keeps the modal open for the next question.
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
     * The test bench: `explain()` asked the way the application asks it, with a
     * real account and — when the permission has a model — a real row.
     *
     * The named schema stores its question on the page, independently of the
     * enclosing action. Submitting the nested test action keeps the question
     * and its answer available for the next comparison inside the same modal.
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
     * The title, under the heading, because the heading is the code name.
     *
     * `recordTitleAttribute` is `name` — a grant points at it, so it is what a
     * breadcrumb and a global search have to say — which leaves the title with
     * nowhere to go once the identity card is gone. The sketch's header carries
     * both, and this is where Filament puts the second one.
     *
     * Null and not an empty string when there is none: a subheading that is `''`
     * still draws its paragraph, and warden only generates a title on `creating`
     * and only when one was not given.
     */
    public function getSubheading(): ?string
    {
        $title = $this->getRecord()->getAttribute('title');

        return is_string($title) && $title !== '' ? $title : null;
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
            Action::make('test')
                ->label(__('filament-warden::ui.resources.permissions.probe.label'))
                ->icon(Heroicon::OutlinedBeaker)
                ->schema(fn (): array => $this->bench())
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('filament-warden::ui.explain.close'))
                ->visible(fn (): bool => $this->bench() !== []),

            $this->give(),

            EditAction::make()
                ->visible(fn (Model $record): bool => PermissionResource::canEdit($record)),

            DeleteAction::make()
                ->modalDescription(static fn (Model $record): string => PermissionsTable::warning($record))
                ->visible(fn (Model $record): bool => PermissionResource::canDelete($record)),
        ];
    }

    /**
     * Whether the toggle is on the forbidding side.
     *
     * Read through `filled()`-free comparison rather than a cast: the state
     * arrives as the option key, which is an int on the way out of the browser
     * and a bool on a default, and `(bool) '0'` is false while `(bool) 'false'`
     * is true. Comparing against the two keys the field actually declares is
     * the only reading that cannot drift from the options above it.
     */
    private static function forbidding(Get $get): bool
    {
        return in_array($get('forbidden'), [1, '1', true], true);
    }

    private static function account(mixed $key): ?Model
    {
        $model = Columns::authorityModel();

        return $model === null || ! is_int($key) && ! is_string($key)
            ? null
            : $model::query()->whereKey($key)->first();
    }

    /**
     * Asked twice on purpose — once for the button, once for the write.
     */
    private function mayGive(Model $record): bool
    {
        $account = Filament::auth()->user();

        return $account instanceof Model && Access::granted($account, 'update', $record);
    }

    /**
     * The way in from the permission: a grant straight to an account, with no
     * role in between.
     *
     * `update` on the permission is what it asks for, and it is the same choice
     * the role screen made for the same reason: handing this row out is changing
     * who holds it, which is the power the edit screen already needs — and
     * re-pointing a row somebody holds moves what they hold without touching a
     * single grant of theirs, so that ability is already this heavy. `view`
     * would let somebody who may only look hand out everything the row carries,
     * and `create` would be a lie: nothing is created.
     *
     * The `visible()` is written by hand and checked again inside, because an
     * action's authorization response goes straight to the policy through
     * `Page::getDefaultActionAuthorizationResponse()` and never passes through
     * the resource (§6.16, §6.23).
     */
    private function give(): Action
    {
        return Action::make('give')
            ->label(__('filament-warden::ui.resources.permissions.grant.label'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->modalDescription(__('filament-warden::ui.resources.permissions.grant.description'))
            ->visible(fn (Model $record): bool => Columns::authorityModel() !== null && $this->mayGive($record))
            ->schema([
                Select::make('account')
                    ->label(__('filament-warden::ui.resources.permissions.grant.account'))
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(static fn (string $search): array => self::accounts($search))
                    ->getOptionLabelUsing(static fn (mixed $value): ?string => self::accountLabel($value)),

                ToggleButtons::make('forbidden')
                    ->label(__('filament-warden::ui.resources.permissions.grant.polarity'))
                    ->inline()
                    ->live()
                    ->default(false)
                    ->options([
                        0 => __('filament-warden::ui.resources.permissions.grant.granted'),
                        1 => __('filament-warden::ui.resources.permissions.grant.forbidden'),
                    ])
                    ->colors([0 => 'success', 1 => 'danger'])
                    // `Line::of()` and not `__()`: the latter is declared
                    // `array|string`, which a closure typed `?string` cannot
                    // return at level max.
                    ->helperText(static fn (Get $get): ?string => self::forbidding($get)
                        ? Line::of('filament-warden::ui.resources.permissions.grant.forbidden_help')
                        : null),

                DatePicker::make('until')
                    ->label(__('filament-warden::ui.resources.permissions.grant.until'))
                    ->helperText(__('filament-warden::ui.resources.permissions.grant.until_help'))
                    // Today is not in the future and warden reads the boundary
                    // exclusively: a row stops counting AT the instant it names,
                    // so a date of today would write something already over.
                    ->after('today')
                    // Not merely hidden: a prohibition CANNOT carry one.
                    // `ForbidsPermissions::until()` throws unconditionally —
                    // `null` included — so there is no date to offer and no way
                    // to pass one along. The reason is said above the toggle
                    // rather than left as a field that quietly disappeared.
                    ->visible(static fn (Get $get): bool => ! self::forbidding($get)),
            ])
            ->action(function (Model $record, array $data): void {
                /** @var array<string, mixed> $data */
                // The second of two, and the first is the `visible()` above.
                // Unreachable while that one is right — Filament refuses to
                // mount an action it will not show — and kept for the day
                // somebody edits one without the other (§6.24).
                if (! $this->mayGive($record)) {
                    return; // @codeCoverageIgnore
                }

                $this->write($record, $data);
            });
    }

    /**
     * The write, through the fluent API and in warden's own order.
     *
     * `until()` before `to()`, because a grant executes ON `to()` and warden
     * throws rather than let a date be added afterwards — and `until(null)`
     * rather than skipping the call, so a row whose earlier grant lapsed has its
     * date moved instead of being found dead and left alone.
     *
     * @param  array<string, mixed>  $data
     */
    private function write(Model $record, array $data): void
    {
        $account = self::account($data['account'] ?? null);

        if (! $account instanceof Model) {
            return; // @codeCoverageIgnore
        }

        $until = $data['until'] ?? null;
        $forbidding = in_array($data['forbidden'] ?? false, [1, '1', true], true);

        if ($forbidding) {
            Warden::forbid($account)->to($record);
        } else {
            Warden::allow($account)
                ->until(is_string($until) && $until !== '' ? CarbonImmutable::parse($until) : null)
                ->to($record);
        }

        // The counts on this page are read per render and `Holders` memoises by
        // instance, so the figures beside the button would still be the ones
        // from before the write.
        Holders::forget($record);

        Notification::make()
            ->title(__('filament-warden::ui.resources.permissions.grant.done'))
            // Both keys written out rather than composed: a key built by
            // concatenation is one `LanguageTest` cannot see being read, and a
            // sentence nothing reads is how a translation goes stale in silence.
            ->body($forbidding
                ? Line::of('filament-warden::ui.resources.permissions.grant.done_forbidden', ['account' => Holders::label($account)])
                : Line::of('filament-warden::ui.resources.permissions.grant.done_granted', ['account' => Holders::label($account)]))
            ->success()
            ->send();
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
