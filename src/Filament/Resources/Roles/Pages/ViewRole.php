<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages;

use Carbon\CarbonImmutable;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\ViewPermission;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Tables\RolesTable;
use ElPandaPe\FilamentWarden\Grants\Hierarchy;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use ElPandaPe\Warden\Support\Config as WardenConfig;
use ElPandaPe\Warden\Support\Expiry;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ViewRole extends ViewRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * Who holds it, added below the resource's own infolist rather than inside
     * it: `RoleInfolist` is shared with nothing else that would need this
     * section, and it answers a question — who, under the tenant this request
     * is in — that only makes sense once a record is already resolved, which
     * is what this page, and not the schema, has.
     *
     * Tenant-scoped, and deliberately not the delete warning's wide read: this
     * section informs rather than decides a delete, and §6.24's rule for an
     * informing read is to keep the scope — reading wide here would name an
     * assignment this screen's own delete button, and `retract()`, cannot act
     * on (§6.21).
     */
    public function infolist(Schema $schema): Schema
    {
        $schema = parent::infolist($schema);

        return $schema->components([
            ...$schema->getComponents(),
            Section::make(__('filament-warden::ui.resources.roles.sections.hierarchy'))
                ->icon(Heroicon::OutlinedLink)
                ->description(__('filament-warden::ui.resources.roles.hierarchy.description'))
                ->visible(static fn (): bool => WardenConfig::nestedRoles())
                ->schema([
                    TextEntry::make('hierarchy')
                        ->hiddenLabel()
                        ->state(static fn (Model $record): string => self::hierarchy($record)),
                ]),
            Section::make(__('filament-warden::ui.resources.roles.sections.holders'))
                ->icon(Heroicon::OutlinedUsers)
                ->description(__('filament-warden::ui.resources.roles.holders.description'))
                ->schema([
                    TextEntry::make('holders')
                        ->hiddenLabel()
                        ->state(static fn (Model $record): string => self::holders($record)),
                ]),
        ]);
    }

    /**
     * The edit action needs nothing written by hand — the resource leaves
     * `canEdit()` alone, so the policy closes it on its own, and a protected role
     * opens with its form disabled rather than not opening at all.
     *
     * The delete action does, for the same reason as on the edit screen: its
     * authorization response never passes through `RoleResource::canDelete()`.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->handOut(),
            EditAction::make(),
            DeleteAction::make()
                ->modalDescription(static fn (Model $record): string => RolesTable::warning($record))
                ->visible(fn (Model $record): bool => RoleResource::canDelete($record)),
        ];
    }

    /**
     * The write itself, through the fluent API and in warden's own order.
     *
     * `until()` before `to()`: an assignment executes ON `to()`, and warden
     * throws rather than let a date be added to something already written —
     * exactly as `AssignsRoles::on()` is guarded. And `until(null)` rather than
     * skipping the call, because a role handed out again to somebody whose
     * earlier assignment lapsed would otherwise find the dead row and change
     * nothing: `firstOrCreate` finds it, and warden only moves the date when a
     * chain declared one.
     */
    private static function assign(Model $record, mixed $account, mixed $until): void
    {
        $holder = ViewPermission::accountFor($account);

        if (! $holder instanceof Model) {
            // Unreachable through the screen, and measured rather than assumed:
            // a `Select` adds an `in:` rule over its own options, so a key that
            // names no row is refused before the action runs. It stays because
            // the guarantee is about what gets WRITTEN, and that must not rest
            // on how another package derives a validation rule (§6.24, and the
            // same call the permission form's own server guard made).
            return; // @codeCoverageIgnore
        }

        Warden::assign($record)
            ->until(is_string($until) && $until !== '' ? CarbonImmutable::parse($until) : null)
            ->to($holder);
    }

    /**
     * Asked twice on purpose — once for the button, once for the write.
     */
    private static function mayHandOut(Model $record): bool
    {
        $account = Filament::auth()->user();

        return $account instanceof Model && Access::granted($account, 'update', $record);
    }

    /**
     * What this role reaches, and who reaches it, as counts rather than lists.
     *
     * A count and a folded chain, never the chain itself: the number of hops is
     * NOT available — warden's `RoleClosure::for()` returns
     * `[restriction type, restriction id, end date]` and carries no depth — and
     * deriving one would mean walking the closure a second time, which is the
     * one thing this package does not do with a rule warden already owns.
     *
     * Two figures and not one, because they are different facts: what somebody
     * chose, and what the choice brought along. A single total hides which is
     * which, and only the first is a thing anybody can change from a screen.
     */
    private static function hierarchy(Model $record): string
    {
        $hierarchy = Hierarchy::of($record);
        $reaching = Hierarchy::reaching($record);

        if ($hierarchy->direct === [] && $reaching === []) {
            return (string) __('filament-warden::ui.resources.roles.hierarchy.none');
        }

        $clauses = [];

        // Two clauses and not one sentence with four numbers in it: a single
        // line cannot pluralise four things at once, and `(s)` is a fake plural
        // this suite refuses outright. Composed the way `savedBody()` composes
        // its own, which is the shape this package already had.
        if ($hierarchy->direct !== []) {
            $clauses[] = trans_choice('filament-warden::ui.resources.roles.hierarchy.inherits', count($hierarchy->direct), [
                'brought' => count($hierarchy->inherited),
                'total' => count($hierarchy->all()),
            ]);
        }

        $clauses[] = trans_choice('filament-warden::ui.resources.roles.hierarchy.reaching', count($reaching));

        return implode('. ', $clauses).'.';
    }

    private static function holders(Model $record): string
    {
        $rows = self::assignments($record);

        if ($rows->isEmpty()) {
            return (string) __('filament-warden::ui.resources.roles.holders.nobody');
        }

        return (string) __('filament-warden::ui.resources.roles.holders.held', [
            'count' => $rows->count(),
            'names' => implode(', ', RolesTable::labels($rows)),
        ]);
    }

    /**
     * Kept to the tenant this request is in, unlike `RolesTable::warning()`'s
     * own wide read — see this class's `infolist()` docblock for why the two
     * disagree on purpose.
     *
     * @return Collection<int, Model>
     */
    private static function assignments(Model $record): Collection
    {
        /** @var Collection<int, Model> $rows */
        $rows = Context::resolve()->assignedRoleClass()::query()
            ->where('role_id', $record->getKey())
            // Who holds it, and a lapsed row is not one of them — unlike
            // `RolesTable::warning()`, which counts them because the cascade
            // takes them whatever the clock says.
            ->tap(Expiry::live(...))
            ->orderBy('id')
            ->get();

        return $rows;
    }

    /**
     * The way IN from the role, which until now only existed from the account.
     *
     * `update` on the role is what it asks for, and the choice is not arbitrary:
     * handing a role out is changing who holds it, which is the same power the
     * grid on the edit screen already needs. `create` would be a lie — nothing
     * is created — and `view` would let somebody who may only look hand out
     * every power the role has.
     *
     * The `visible()` is written by hand and it is not decoration: an action's
     * authorization response goes STRAIGHT to the policy through
     * `Page::getDefaultActionAuthorizationResponse()` and never passes through
     * `RoleResource::canEdit()` (§6.16, §6.23). And it is checked again inside
     * the action, because `visible()` decides whether the button exists and the
     * server is what decides whether the write happens.
     *
     * `until()` and `on()` go BEFORE `to()`, both of them: assignments execute
     * on `to()`, and warden throws rather than let either be added afterwards.
     */
    private function handOut(): Action
    {
        return Action::make('handOut')
            ->label(__('filament-warden::ui.resources.roles.hand_out.label'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->visible(static fn (Model $record): bool => self::mayHandOut($record))
            ->schema([
                Select::make('account')
                    ->label(__('filament-warden::ui.resources.roles.hand_out.account'))
                    ->required()
                    ->searchable()
                    // The permission screen's own search, called rather than
                    // copied: it already resolves the account model through the
                    // panel's guard, drops the columns a `LIKE` cannot be pointed
                    // at, and escapes its wildcards WITH the clause that keeps
                    // that from turning into an empty search (§6.38). A second
                    // copy would be a second thing to measure on three engines.
                    ->getSearchResultsUsing(static fn (string $search): array => ViewPermission::accounts($search))
                    ->getOptionLabelUsing(static fn (mixed $value): ?string => ViewPermission::accountLabel($value)),

                DatePicker::make('until')
                    ->label(__('filament-warden::ui.resources.roles.hand_out.until'))
                    ->helperText(__('filament-warden::ui.resources.roles.hand_out.until_help'))
                    // Today is not in the future, and warden reads the boundary
                    // exclusively: a row stops counting AT the instant it names,
                    // so a date of today would hand out something that ended
                    // before the notification was written.
                    ->after('today'),
            ])
            ->action(static function (Model $record, array $data): void {
                // The second of two, and the first is the `visible()` above.
                // Unreachable while that one is right: Filament refuses to mount
                // an action it will not show, measured with a bare
                // `mountAction`/`callMountedAction` pair. What this holds is the
                // day somebody removes the `visible()` while editing this file,
                // or reaches this action from a screen that never had one — the
                // shape §6.24 measured for the permission form.
                if (! self::mayHandOut($record)) {
                    return; // @codeCoverageIgnore
                }

                self::assign($record, $data['account'] ?? null, $data['until'] ?? null);
            });
    }
}
