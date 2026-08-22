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
     * `InteractsWithRelationshipTable::getRelationshipName()` falls back to
     * `static::getRelatedResource()::getParentResourceRegistration()` when
     * this is unset — a fatal, not a `null` a `??` could catch
     * (`InteractsWithRelationshipTable.php:63-70`). Load-bearing again as of
     * this docblock, corrected from an earlier claim that it was not:
     * `$relatedResource` below used to route every caller of
     * `getRelationshipName()` around this property entirely (`canViewForRecord()`
     * took a different branch, `getTitle()` is overridden below regardless).
     * With `$relatedResource` now `null`, `canViewForRecord()` is overridden
     * directly instead and still never reaches `getRelationshipName()` — but
     * `getRelationship()`'s lazy closure, installed by the base `makeTable()`,
     * is still overwritten by `->relationship(null)` in `table()` below
     * before Livewire ever evaluates it, and no other caller in this class's
     * own render or action path reaches for it either. Re-measured against
     * this exact source: removing this property leaves every test in
     * `RolesRelationManagerTest.php` green and `stan` clean, the same result
     * as before `$relatedResource` changed. Kept anyway, for the reason that
     * was always the real one: this is the property Filament's own scaffold
     * expects declared, and the documented contract of the base class this
     * extends.
     */
    protected static string $relationship = 'roles';

    /**
     * `null`, not `RoleResource::class` — B1/B2 of the v1.4.0 whole-branch
     * review, both closed by the same line.
     *
     * `InteractsWithRelationshipTable::makeTable()` calls
     * `$relatedResource::configureTable($table)` whenever this is set
     * (`InteractsWithRelationshipTable.php:184-189`), which runs
     * `RolesTable::configure()` and registers ITS `EditAction` and
     * `DeleteAction` into this table — actions this class never lists in
     * `table()` below, which sets only `assign`/`retract`. `recordActions()`
     * resets `$recordActions`, the array a render walks, but NOT
     * `$flatActions`, the array `resolveTableAction()` resolves a mounted
     * action's NAME against (`HasRecordActions.php:32-42` has no
     * `removeCachedActions()` call; `headerActions()` does). Measured on the
     * class as it stood with `$relatedResource = RoleResource::class`:
     * `getFlatActions()` on a mounted instance returned
     * `edit, delete, assign, retract` — two actions this screen never shows
     * and never intends to serve, reachable anyway through a raw
     * `mountAction`/`callMountedAction` call (B1). The leaked `edit` opens
     * `RoleResource::form()` — the permission grid — on a path that bypasses
     * `EditRole::mutateFormDataBeforeSave()`, so the protected-role rename
     * guard AGENTS.md §6.24 built into that page is not on this one.
     *
     * The same setting also installs a default `recordUrl` closure
     * (`InteractsWithRelationshipTable.php:146-181`) that walks the leaked
     * `edit`/`view` actions looking for a URL, which reaches
     * `RelationManager::getDefaultActionUrl()` →
     * `RoleResource::getUrl('edit', …)`. On a panel that never registered
     * `RoleResource` — `->roles(false)`, or simply a panel this package's
     * plugin was never attached to — that throws
     * `Route [filament.{panel}.resources.roles.edit] not defined` as soon as
     * the table has one row, measured the same way (B2).
     *
     * With this `null`, neither leak exists: `configureTable()` never runs,
     * so `flatActions` holds only `assign` and `retract`, and the default
     * `recordUrl` closure finds no `edit`/`view` action to build a URL from
     * and returns `null` — this table draws no row link at all, a
     * capability this branch never released (see the CHANGELOG entry for
     * this fix). The same `if ($relatedResource = …)` block in
     * `makeTable()` does two MORE things this `null` also switches off —
     * `$table->modelLabel()`/`->pluralModelLabel()` from
     * `$relatedResource::getModelLabel()`/`::getPluralModelLabel()` — caught
     * only in a later pass over this same fix (see `table()` below for the
     * measurement). Everything `$relatedResource` used to buy for free is
     * bought back explicitly instead, never by leaving this `null` and
     * hoping a caller notices what silently changed: `canViewForRecord()`
     * overridden below to answer `RoleResource::canAccess()`, and
     * `->modelLabel()`/`->pluralModelLabel()` set in `table()` below from
     * the same `RoleResource` methods.
     */
    protected static ?string $relatedResource = null;

    /**
     * Closes with this package's own `RolePolicy`, registered by
     * `FilamentWardenServiceProvider`, and never with a guess at the account
     * model's own relation — the job `$relatedResource` used to do for free
     * before B1/B2 (its own docblock above) forced it to `null`.
     *
     * The base implementation's `null`-`$relatedResource` branch would do
     * `$ownerRecord->{static::getRelationshipName()}()->getQuery()->getModel()`
     * (`RelationManager.php:296`) — reachable on this account for real, since
     * `$relationship` is still `'roles'` — but that is still the wrong
     * question: it asks what an arbitrary consuming application named its
     * relation, not what this package's own Policy says. Overriding here
     * skips that branch entirely rather than relying on it to guess right.
     *
     * Pinned — `RolesRelationManagerTest.php`'s "canViewForRecord() closes
     * with the packaged Policy" test calls this directly against a `Post`
     * fixture, which declares no `roles()` method at all: removing this
     * override throws `BadMethodCallException` reaching the base branch,
     * confirmed by deleting it and running that exact test.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return RoleResource::canAccess();
    }

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
            // A fourth thing `$relatedResource` used to do for free, beyond
            // the three named in its own docblock:
            // `InteractsWithRelationshipTable::makeTable()` also called
            // `$table->modelLabel($relatedResource::getModelLabel())` and the
            // plural sibling (`InteractsWithRelationshipTable.php:184-189`),
            // which read THIS package's own translated
            // `ui.resources.roles.model`/`.models` through `RoleResource`.
            // With `$relatedResource` forced to `null` for B1/B2, that stopped
            // running — measured: `getPluralModelLabel()` fell back to
            // `get_model_label()` (`Support/helpers.php:55-60`), a bare
            // `Str::plural(kebab(class_basename($model)))`, always English and
            // always lowercase whatever the application's locale is set to.
            // `HasEmptyState::getEmptyStateHeading()` reads exactly that
            // label for "No :model" — the empty state every new account is
            // in, not an edge case. Reading `RoleResource::getModelLabel()`/
            // `::getPluralModelLabel()` here restores the same translated
            // strings without duplicating the keys they read.
            ->modelLabel(RoleResource::getModelLabel())
            ->pluralModelLabel(RoleResource::getPluralModelLabel())
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
                    ->label(__('filament-warden::ui.relations.roles.held_column'))
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

    private function offered(Model $account, Model $record): bool
    {
        $key = $record->getKey();

        return (is_int($key) || is_string($key)) && Assignment::offers($account, $key);
    }

    /**
     * The header action: pick a role, hand it out.
     *
     * `->visible()` closes the WHOLE button on `ViewRecord` only — there is
     * no single record to check at this level the way `retractAction()` has
     * one, so `isReadOnly()` is the only thing it checks. Everything else the
     * earlier docblock here said still holds: this button opens the modal
     * whenever the tab itself is open (already closed by `canViewForRecord()`,
     * § the class docblock), and what closes each OPTION inside the modal is
     * two SEPARATE layers — `disableOptionWhen()`, which is UX only, and
     * `Assignment::give()`'s own `offers()` re-check, the real, sole
     * server-side guard for WHICH role, and must not be removed on the
     * grounds that this screen already checks. Never `->authorize()` either,
     * which resolves through `Gate::check()` and is invisible to
     * `strictAuthorization()` (AGENTS.md §6.2, §6.18): "assigning" and
     * "retracting" are not abilities any policy declares — they are the
     * compound judgement `Assignment::offers()` already makes — so a policy
     * has nothing to be asked here.
     *
     * The `Select` has no `->descriptions()`: unlike `CheckboxList`, which
     * `RoleAssignment` uses and which does carry that method, `Select` does not
     * implement `Concerns\HasDescriptions` at all — calling it would be a fatal
     * error, not a missing hint. `disableOptionWhen()` is what this screen has,
     * and what it had to rest on.
     *
     * The notification only fires when `give()` reports it actually wrote
     * something: `offers()` does not exclude a role the account already
     * holds, so without this check selecting an already-held role would
     * report success for a no-op — the "reports success while writing
     * nothing" shape this package built `Shape::Elsewhere` to stop drawing
     * elsewhere in the product.
     */
    private function assignAction(Model $account): Action
    {
        return Action::make('assign')
            ->label(__('filament-warden::ui.relations.roles.assign.label'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->modalHeading(__('filament-warden::ui.relations.roles.assign.heading'))
            ->visible(fn (): bool => ! $this->isReadOnly())
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

                // Repeated here and not only in the `->visible()` above —
                // CORRECTED: an earlier version of this comment claimed the
                // repeat could not be shown to discriminate. Measured in
                // `RolesRelationManagerTest.php`'s three-way breakage (see its
                // docblock): with `->visible()`'s own `isReadOnly()` check
                // removed and this line intact, a raw
                // `mountAction`/`callMountedAction` call on `ViewRecord` still
                // wrote nothing — this line caught it alone. `Action::call()`
                // itself checks neither `isDisabled()` nor `isVisible()`
                // (`vendor/filament/actions/src/Action.php:675-684`; that gate
                // lives only in `mountAction()`/`callMountedAction()`), so
                // anything reaching `->call()` by another route has only this
                // line standing between it and the write.
                $isOffered = ! $this->isReadOnly() && (is_int($role) || is_string($role));

                if (! $isOffered) {
                    return;
                }

                // Repeated here and not only in `disableOptionWhen()`: a
                // disabled option reaches the state exactly like a disabled
                // field does (AGENTS.md §6.11, §6.18), so the guarantee over
                // who may hand out a role cannot rest on how the `Select` was
                // drawn.
                if (! Assignment::give($account, $role)) {
                    return;
                }

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
     * `isDisabled()` check. A copy of `$this->offered()` inside this closure
     * would be unreachable dead code, in permanent tension with this project's
     * 100% line coverage gate. `Assignment::take()` is what actually keeps the
     * guarantee off the button: it re-checks `offers()` itself
     * (`Grants/Assignment.php`) before writing anything, independent of
     * whichever screen called it.
     *
     * `Assignment::take()`'s bool return is read below and would gate the
     * notification on it — CORRECTED after checking whether that branch can
     * ever go the other way through THIS wiring, and it cannot. A role
     * retracted between mount and call does not reach `take()`'s own
     * `isHeld()` guard at all: it never reaches this closure. `getTableRecord()`
     * resolves against this same table's query, scoped to
     * `Assignment::of($account)`, so a role no longer held cannot be resolved
     * either — `resolveTableAction()` throws `ActionNotResolvableException`
     * and `mountAction()`/`callMountedAction()` swallow it, silently, before
     * `$action->call()` (`InteractsWithActions.php:651-659`). Proved the same
     * way as the paragraph above: a thrown exception planted at the top of
     * this closure, mounted on a held role, retracted directly before calling
     * — the exception never surfaced. Kept anyway, for the same reason
     * `isHeld()` in `give()` is kept even though its own duplicate-row worry
     * turned out to be false: `Assignment::take()` is a public method other
     * callers will reach for, and its own correctness — reporting `false`
     * rather than a false "success" — does not depend on which screen calls
     * it. It costs nothing here: `$written` below always executes regardless
     * of which way it resolves, so there is no line only an unreachable
     * branch reaches. What DOES reach `take()`'s bool return through this
     * screen, and is pinned in `AssignmentTest.php` rather than here, is a
     * plain call bypassing Livewire entirely — `Assignment::take()` on a role
     * never held answers `false`.
     *
     * `isReadOnly()` is a SEPARATE story from everything above, about
     * `ViewRecord` rather than a restricted or vanished role, and it does NOT
     * end the same way: the closure's own `! $this->isReadOnly()` copy below
     * IS independently sufficient, measured — see its own inline comment and
     * `RolesRelationManagerTest.php`'s three-way breakage.
     */
    private function retractAction(Model $account): Action
    {
        return Action::make('retract')
            ->label(__('filament-warden::ui.relations.roles.retract.label'))
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Model $record): bool => ! $this->isReadOnly() && $this->offered($account, $record))
            ->action(function (Model $record) use ($account): void {
                $key = $record->getKey();

                // `! $this->isReadOnly()` is repeated here and not only in
                // `->visible()` above — CORRECTED: an earlier version of this
                // comment claimed the repeat could not be shown to
                // discriminate. Measured in `RolesRelationManagerTest.php`'s
                // three-way breakage (see its docblock): with `->visible()`'s
                // own `isReadOnly()` check removed and this line intact, a
                // raw `mountAction`/`callMountedAction` call on `ViewRecord`
                // still wrote nothing — this line caught it alone.
                // `Action::call()` itself checks neither `isDisabled()` nor
                // `isVisible()` (`vendor/filament/actions/src/Action.php:675-684`;
                // that gate lives only in `mountAction()`/`callMountedAction()`),
                // so anything reaching `->call()` by another route has only
                // this line standing between it and the write.
                $written = ! $this->isReadOnly()
                    && (is_int($key) || is_string($key))
                    && Assignment::take($account, $key);

                if ($written) {
                    Notification::make()
                        ->title(__('filament-warden::ui.relations.roles.retract.notified'))
                        ->success()
                        ->send();
                }
            });
    }
}
