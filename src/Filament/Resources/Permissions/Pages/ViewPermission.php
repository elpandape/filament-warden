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
     * can change the record and ask again without retyping the account.
     *
     * @var array<string, mixed>
     */
    public array $ask = ['account' => null, 'record' => null];

    /**
     * The card, already worded.
     *
     * Strings and not a `Probe`: the wording happens once, where the store is,
     * and what travels between requests is what the page prints.
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

        // This page offers no action without one, but the role screen's
        // hand-out searches through here without checking for one.
        if ($model === null) {
            return [];
        }

        $clauses = array_values(array_intersect_key(self::SEARCHABLE, array_flip(Columns::texts($model))));

        // With nothing to search, the closure below would add no condition and
        // every search would answer with the same first twenty accounts, none of
        // them what was typed. A search nobody can perform returns nothing.
        if ($clauses === []) {
            return [];
        }

        // `%` and `_` are wildcards to LIKE, so unescaped, a search for `%`
        // would match every row and page through the account table instead of
        // finding one in it.
        //
        // The escape character is `!` and not a backslash: `escape '\'` is a
        // syntax error on MySQL, which reads the backslash inside the string
        // literal unless `NO_BACKSLASH_ESCAPES` is set, while SQLite and Postgres
        // (under its default `standard_conforming_strings = on`) take it as it
        // stands — and doubling it to `escape '\\'` leaves those two with two
        // characters where one is required. `!` needs no escaping in a string
        // literal on any of them, so the clause is the same text for every
        // driver. No test here runs MySQL, Postgres or `sqlsrv`. `!` itself is
        // escaped in the term too, or a person searching for it would be typing
        // an escape character.
        //
        // And the clause cannot be dropped: SQLite has no default escape
        // character, so an escaped term without it matches nothing at all.
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
     * The title, under the heading, because the heading is the code name:
     * `recordTitleAttribute` is `name`, the word the application's code asks
     * for, so it is what a breadcrumb has to say. The infolist draws no title of
     * its own, which leaves this the one place it shows.
     */
    public function getSubheading(): ?string
    {
        $title = $this->getRecord()->getAttribute('title');

        return is_string($title) && $title !== '' ? $title : null;
    }

    /**
     * Every visibility here is written by hand.
     *
     * An edit or delete button asks `getEditAuthorizationResponse()` /
     * `getDeleteAuthorizationResponse()`, which go straight to the policy, and
     * the resource's `canEdit()` and `canDelete()` — where `permissions.update`
     * and the orphan rule live — are never on that path. The bench and the
     * hand-out are plain actions, which Filament authorizes against nothing
     * (see `give()`). The delete description is `PermissionsTable::warning()`.
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
     * Compared against the keys the field declares rather than cast: the state
     * arrives as the option key, which is an int on the way out of the browser
     * and a bool on a default, and `(bool) '0'` is false while `(bool) 'false'`
     * is true. Only the declared keys cannot drift from the options above.
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

    private function mayGive(Model $record): bool
    {
        $account = Filament::auth()->user();

        return $account instanceof Model && Access::granted($account, 'update', $record);
    }

    /**
     * The way in from the permission: a grant straight to an account, with no
     * role in between.
     *
     * `update` on the permission is what it asks for, the same choice the role
     * screen's hand-out makes: handing this row out is changing who holds it,
     * which is the power the edit screen already needs. `view` would let
     * somebody who may only look hand out everything the row carries, and
     * `create` would be a lie: nothing is created.
     *
     * The `visible()` is the only gate: for an action of the page's own,
     * `Page::getDefaultActionAuthorizationResponse()` answers null and no policy
     * is asked. So it is asked twice — once for the button, once inside the
     * action for the write.
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
                    // to pass one along. The reason is said on the toggle
                    // rather than left as a field that quietly disappeared.
                    ->visible(static fn (Get $get): bool => ! self::forbidding($get)),
            ])
            ->action(function (Model $record, array $data): void {
                /** @var array<string, mixed> $data */
                // Unreachable while the `visible()` above is right — Filament
                // refuses to mount an action it will not show — and kept for
                // the day somebody edits one without the other.
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
     * Whether the bench is offered at all.
     *
     * Two conditions and neither is the other: `permissions.probe` is a choice
     * an installation makes, and an account model that does not resolve is a
     * question nobody could put — the select would have nothing to search.
     *
     * @return array<int, Component>
     */
    private function bench(): array
    {
        return Config::enabled('permissions.probe') && Columns::authorityModel() !== null
            ? [EmbeddedSchema::make('probeForm')]
            : [];
    }

    /**
     * The rows under the summary, each of which is absent rather than empty when
     * the store had nothing to put in it.
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
        // value with `getInValidationRuleValues()`, which for a single field
        // calls `getOptionLabel()` — this class's own `accountLabel()`, through
        // the same `account()` — and returns an empty list when it comes back
        // blank, so `getState()` above throws first for any key nobody could
        // pick. What the guard still answers is the row going away between that
        // check and this one, and without it `Probe::run()` would be handed a
        // `?Model`.
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
