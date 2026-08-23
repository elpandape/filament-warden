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
use ElPandaPe\Warden\Facades\Warden;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
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

        $clauses = array_values(array_intersect_key(self::SEARCHABLE, array_flip(Columns::of($model))));

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
        // A backslash — the obvious choice, and what this was written with
        // first — is not portable, and exactly ONE engine is why: `escape '\'`
        // is a syntax error on MySQL, which reads the backslash inside the
        // string literal unless `NO_BACKSLASH_ESCAPES` is set. SQLite and
        // Postgres both take it as it stands (Postgres under its default
        // `standard_conforming_strings = on`), so a first draft of this comment
        // blamed two engines and was measured wrong. Doubling it to
        // `escape '\\'` satisfies MySQL and then breaks the other two, which
        // see two characters where one is required — so there is no backslash
        // literal that works everywhere, which is the part that stands.
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
     * Three actions, and only the probe is optional.
     *
     * Both visibilities are written by hand: an edit or delete button asks
     * `getEditAuthorizationResponse()` / `getDeleteAuthorizationResponse()`,
     * which go straight to the policy, and the resource's `canEdit()` and
     * `canDelete()` — where `permissions.update` and the orphan rule live — are
     * never on that path. The modal description is the table's own, because a
     * delete takes the grants with it below Eloquent and this is the last moment
     * anybody is told.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (Model $record): bool => PermissionResource::canEdit($record)),

            // The delete takes its grants with it below Eloquent, and nothing in
            // warden bumps the version for a write made through the model layer:
            // without the hook every check goes on answering the old way,
            // silently and with no expiry. Void on purpose — whatever `after()`
            // returns stands in for the action's own result.
            DeleteAction::make()
                ->modalDescription(static fn (Model $record): string => PermissionsTable::warning($record))
                ->visible(fn (Model $record): bool => PermissionResource::canDelete($record))
                ->after(static function (): void {
                    Warden::refresh();
                }),

            ...$this->probe(),
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
     * The test bench: `explain()` asked the way the application asks it, with a
     * real account and — when the permission has a model — a real row.
     *
     * It lives in a modal rather than on the page because that is where Filament
     * gives a searchable select for free, and an installation's account table can
     * be very large. The answer comes back as a notification that stays put.
     *
     * @return array<int, Action>
     */
    private function probe(): array
    {
        if (! Config::enabled('permissions.probe') || Columns::authorityModel() === null) {
            return [];
        }

        return [
            Action::make('probe')
                ->label(__('filament-warden::ui.resources.permissions.probe.label'))
                ->icon(Heroicon::OutlinedBeaker)
                ->modalSubmitActionLabel(__('filament-warden::ui.resources.permissions.probe.submit'))
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
                ])
                ->action(function (array $data): void {
                    $this->answer($data);
                }),
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    private function answer(array $data): void
    {
        $account = self::account($data['account'] ?? null);
        $record = $data['record'] ?? null;

        // The select validates against its own options, so an account that does
        // not resolve is one whose row went away between opening the modal and
        // submitting it. There is nothing to answer, and nothing to say.
        if ($account instanceof Model) {
            $this->tell(
                Probe::run(
                    $account,
                    $this->getRecord(),
                    is_string($record) && $record !== '' ? $record : null,
                ),
                Reach::of($this->getRecord(), $account),
            );
        }
    }

    /**
     * The verdict, and — where it can be counted — how far it reaches.
     *
     * The reach is worked out here and nowhere else: one `whereCan()` is six
     * queries with no cache, so it happens when somebody asks and never on a
     * render.
     */
    private function tell(Probe $probe, Reach $reach): void
    {
        Notification::make()
            ->title(__('filament-warden::ui.stances.'.$probe->verdict->value))
            ->body(mb_trim($probe->summary.' '.($probe->note ?? '').' '.$reach->sentence()))
            ->status(match ($probe->verdict->value) {
                'granted' => 'success',
                'forbidden' => 'danger',
                default => 'warning',
            })
            ->persistent()
            ->send();
    }
}
