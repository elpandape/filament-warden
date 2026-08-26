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
     * Declared because the base class documents it, not because a caller here
     * reaches it: nothing in this class's render or action path calls
     * `getRelationshipName()`. Reading it when it is unset is a fatal rather
     * than a `null`, so it is never left off.
     */
    protected static string $relationship = 'roles';

    /**
     * `null`, and it closes two leaks at once.
     *
     * Set, `makeTable()` runs `$relatedResource::configureTable()`, which
     * registers `RolesTable`'s own `EditAction` and `DeleteAction` into this
     * table. `recordActions()` resets the array a render walks but NOT
     * `flatActions`, which is what a mounted action's name resolves against —
     * so both stay reachable through a raw `mountAction` call, and the leaked
     * `edit` opens the permission grid on a path that bypasses `EditRole`'s
     * own guards. Set, it also installs a default `recordUrl` closure that
     * builds `RoleResource::getUrl('edit', …)`, which throws on a panel that
     * never registered the resource.
     *
     * Everything it bought for free is bought back explicitly rather than
     * left to a caller noticing what went quiet: `canViewForRecord()` below,
     * and the two model labels in `table()`.
     *
     * The price is that `Audit::unwalkable()` lists this class for as long as it
     * exists, since that finding asks exactly this question. It is why that
     * bucket informs rather than reddens `--check`: an installation following
     * the README cannot clear it, and should not have to.
     */
    protected static ?string $relatedResource = null;

    /**
     * Closes with this package's own Policy, never with a guess at the account
     * model's relation. The base branch would run
     * `$ownerRecord->{getRelationshipName()}()` to find the model, which asks
     * what a consuming application named its relation rather than what the
     * Policy says — and throws outright on an owner that has no such method.
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
            // `->query()` alone is not enough: the base table keeps its own
            // relationship closure, and `resolveTableRecord()` — what routes a
            // row action to its record — resolves a `MorphToMany` through the
            // RELATIONSHIP rather than the query, back through
            // `applyPivotTenancy()` and, for a role held both with and without
            // a context, to whichever duplicate pivot row it finds first.
            ->relationship(null)
            // Bought back from `$relatedResource` being null. Without them
            // the label falls to `get_model_label()`, a bare
            // `Str::plural(kebab(class_basename($model)))` — always English
            // and lowercase whatever the locale — and the empty state heading
            // reads exactly that label.
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
     * `->visible()` closes the whole button on a read-only page; WHICH role may
     * be handed out is decided server-side by `Assignment::give()`, since a
     * disabled option reaches the payload like any disabled field.
     *
     * Never `->authorize()`: it resolves through `Gate::check()` and is
     * invisible to `strictAuthorization()`. Assigning is not an ability any
     * policy declares — it is the compound judgement `offers()` makes.
     *
     * `Select` does not implement `HasDescriptions`, so there is no
     * `->descriptions()` here as there is on the `CheckboxList` field.
     *
     * The notification waits on `give()`'s return: `offers()` does not exclude
     * a role already held, so an unguarded notice would report success for a
     * no-op.
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

                // Repeated, not only in `->visible()`: `Action::call()` checks
                // neither `isDisabled()` nor `isVisible()` — that gate lives in
                // `mountAction()`/`callMountedAction()` — so anything reaching
                // `->call()` by another route has only this line in front of it.
                $isOffered = ! $this->isReadOnly() && (is_int($role) || is_string($role));

                if (! $isOffered) {
                    return;
                }

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
     * `Assignment::offers()` alone decides whether it shows, answering three
     * questions at once: a real account, a role this account may edit, and one
     * narrowed to neither a context nor another scope.
     *
     * The closure does not repeat that check, unlike the header action:
     * `mountAction()` and `callMountedAction()` both consult `isDisabled()`,
     * which is true whenever `isHidden()` is, so a copy here would be
     * unreachable — and this project's coverage gate is 100 % of lines.
     * `Assignment::take()` re-checks `offers()` itself before writing,
     * whichever screen called it.
     *
     * `isReadOnly()` is a separate question and does NOT end the same way: the
     * copy below is independently sufficient.
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

                // Repeated, not only in `->visible()`: `Action::call()` checks
                // neither `isDisabled()` nor `isVisible()`, so anything reaching
                // it by another route has only this line in front of it.
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
