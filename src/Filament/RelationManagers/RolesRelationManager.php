<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\RelationManagers;

use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\Grants\Assignment;
use ElPandaPe\Warden\Context;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The roles an account holds, with a header action to hand one out and a row
 * action to take one back.
 *
 * A package cannot attach a relation manager to a resource it does not own —
 * `Resource::getRelations()` is a concrete static and nothing outside the
 * consuming application's own resource can write to it (AGENTS.md §6.18). What
 * a package CAN do is hand over the class, and that is the whole of this one:
 * a consuming application's `UserResource` adds one line —
 *
 *     public static function getRelations(): array
 *     {
 *         return [RolesRelationManager::class];
 *     }
 *
 * NOT `final`: Filament instantiates a relation manager by its class name, and
 * an installation may want to extend this one — a different icon, an extra
 * column — for its own account resource. This is the one deliberate exception
 * to this project's `final`-by-default convention (AGENTS.md §5).
 *
 * Reads through `Assignment::of()`, which already dedupes a role held both with
 * and without a context to one key (AGENTS.md §6.18), and writes through
 * `Assignment::give()`/`take()`, never `attach()`/`detach()`/`sync()`: those
 * three skip warden's cache bump and `detach()` ignores tenancy outright
 * (§6.18 again).
 */
class RolesRelationManager extends RelationManager
{
    /**
     * Without this, `getRelationshipName()` falls through to
     * `static::getRelatedResource()::getParentResourceRegistration()` — and
     * with no `$relatedResource` either, that call is
     * `null::getParentResourceRegistration()`: a fatal error, not a `null` a
     * `??` could catch (`InteractsWithRelationshipTable.php:63-70`).
     */
    protected static string $relationship = 'roles';

    /**
     * With this declared, `canViewForRecord()` answers
     * `RoleResource::canAccess()` and never runs the account model's own
     * `roles()` relation to guess a class (`RelationManager.php:287-292`). The
     * tab closes with this package's own `RolePolicy`, registered by
     * `FilamentWardenServiceProvider`, and never depends on how a consuming
     * application happens to have named that relation.
     */
    protected static ?string $relatedResource = RoleResource::class;

    /**
     * Not `$title`: it is a static property and would be evaluated before
     * translations are loaded.
     */
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('filament-warden::ui.relations.roles.label');
    }

    public function table(Table $table): Table
    {
        $account = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('name')
            // `->query()` alone is not enough: the base table keeps
            // `->relationship(fn () => $this->getRelationship())` even with a
            // query set on top of it, and `resolveTableRecord()` — the call that
            // routes a row action to its record — resolves a `BelongsToMany`
            // relationship (which `MorphToMany` is) through the RELATIONSHIP,
            // never the plain query: straight back through `applyPivotTenancy()`
            // and, for a role held both with and without a context, to whichever
            // of the two duplicate pivot rows it finds first. Clearing the
            // relationship makes `resolveTableRecord()` fall back to the query
            // below instead.
            ->relationship(null)
            ->query(static fn (): Builder => Context::resolve()->roleClass()::query()->whereKey(Assignment::of($account)))
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament-warden::ui.resources.roles.columns.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('title')
                    ->label(__('filament-warden::ui.resources.roles.columns.title'))
                    ->placeholder('—'),

                // Calculated, not a database column: `sortable()`/`searchable()`
                // would fall back to a `held_as` column the roles table does not
                // have, and the error would surface on click, not on build
                // (AGENTS.md §6.17).
                TextColumn::make('held_as')
                    ->label(__('filament-warden::ui.relations.roles.held.column'))
                    ->badge()
                    ->state(static fn (Model $record): string => self::heldAs($account, $record))
                    ->formatStateUsing(static fn (string $state): string => __('filament-warden::ui.relations.roles.held.'.$state))
                    ->color(static fn (string $state): string => match ($state) {
                        'restricted' => 'warning',
                        'elsewhere' => 'gray',
                        default => 'success',
                    }),
            ])
            ->headerActions([$this->assignAction($account)])
            ->recordActions([$this->retractAction($account)]);
    }

    /**
     * Which of the three badges a row draws, in the same priority
     * `Assignment::descriptions()` already uses for the reason a checkbox is
     * disabled: restricted first, then elsewhere. What is left over covers two
     * different things `Assignment` has no public way to tell apart — held with
     * no scope at all, and held at exactly the scope this screen writes to —
     * and both read the same here: neither is locked, so both are retractable
     * from this row.
     */
    private static function heldAs(Model $account, Model $record): string
    {
        $key = $record->getKey();
        $key = is_int($key) || is_string($key) ? $key : '';

        return match (true) {
            Assignment::isRestricted($account, $key) => 'restricted',
            Assignment::isElsewhere($account, $key) => 'elsewhere',
            default => 'here',
        };
    }

    private static function offered(Model $account, Model $record): bool
    {
        $key = $record->getKey();

        return (is_int($key) || is_string($key)) && Assignment::offers($account, $key);
    }

    /**
     * The header action: pick a role, hand it out.
     *
     * Closed with `->visible()` and never `->authorize()`, which resolves
     * through `Gate::check()` and is invisible to `strictAuthorization()`
     * (AGENTS.md §6.2, §6.18). "Asigning" and "retracting" are not abilities any
     * policy declares — they are the compound judgement `Assignment::offers()`
     * already makes — so a policy has nothing to be asked here.
     *
     * The `Select` has no `->descriptions()`: unlike `CheckboxList`, which
     * `RoleAssignment` uses and which does carry that method, `Select` does not
     * implement `Concerns\HasDescriptions` at all — calling it would be a fatal
     * error, not a missing hint. `disableOptionWhen()` is what this screen has,
     * and what it had to rest on.
     */
    private function assignAction(Model $account): Action
    {
        return Action::make('assign')
            ->label(__('filament-warden::ui.relations.roles.assign.label'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->modalHeading(__('filament-warden::ui.relations.roles.assign.heading'))
            ->schema([
                Select::make('role')
                    ->label(__('filament-warden::ui.relations.roles.assign.field'))
                    ->required()
                    ->searchable()
                    ->options(static fn (): array => Assignment::options())
                    ->disableOptionWhen(static fn (mixed $value): bool => ! Assignment::offers($account, $value)),
            ])
            ->action(function (array $data) use ($account): void {
                $role = $data['role'] ?? null;

                if (! is_int($role) && ! is_string($role)) {
                    return;
                }

                // Repeated here and not only in `disableOptionWhen()`: a
                // disabled option reaches the state exactly like a disabled
                // field does (AGENTS.md §6.11, §6.18), so the guarantee over
                // who may hand out a role cannot rest on how the `Select` was
                // drawn.
                Assignment::give($account, $role);

                Notification::make()
                    ->title(__('filament-warden::ui.relations.roles.assign.notified'))
                    ->success()
                    ->send();
            });
    }

    /**
     * The row action: take a role back.
     *
     * `Assignment::offers()` alone decides whether it shows — it already
     * answers three questions at once: a real account, a role this account may
     * edit, and one narrowed to neither a context nor another scope
     * (`Grants/Assignment.php`).
     *
     * The closure does NOT repeat that check on its own — measured, and unlike
     * the header action above. `InteractsWithActions::mountAction()` and
     * `::callMountedAction()` both call `$action->isDisabled()` before this
     * closure ever runs (`vendor/filament/actions/src/Concerns/InteractsWithActions.php:158,244`),
     * and `isDisabled()` itself returns true whenever `isHidden()` does
     * (`Concerns/CanBeDisabled.php:24-26`) — the exact `visible()` closure
     * above, evaluated fresh both times. Proved by mounting this action on an
     * offered role, THEN restricting the assignment before calling it: the
     * closure body never ran (a thrown exception inside it never surfaced),
     * because `callMountedAction()` had already returned `null` at its own
     * `isDisabled()` check. A copy of `self::offered()` inside this closure
     * would be unreachable dead code, in permanent tension with this project's
     * 100% line coverage gate. `Assignment::take()` is what actually keeps the
     * guarantee off the button: it re-checks `offers()` itself
     * (`Grants/Assignment.php`) before writing anything, independent of
     * whichever screen called it.
     */
    private function retractAction(Model $account): Action
    {
        return Action::make('retract')
            ->label(__('filament-warden::ui.relations.roles.retract.label'))
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(static fn (Model $record): bool => self::offered($account, $record))
            ->action(function (Model $record) use ($account): void {
                $key = $record->getKey();

                if (is_int($key) || is_string($key)) {
                    Assignment::take($account, $key);
                }

                Notification::make()
                    ->title(__('filament-warden::ui.relations.roles.retract.notified'))
                    ->success()
                    ->send();
            });
    }
}
