<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\RelationManagers;

use Carbon\CarbonImmutable;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\Grants\Assignment;
use ElPandaPe\Warden\Context;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
 * consuming application's own resource can write to it. What a package CAN do
 * is hand over the class, and that is the whole of this one: a consuming
 * application's `UserResource` adds one line —
 *
 *     public static function getRelations(): array
 *     {
 *         return [RolesRelationManager::class];
 *     }
 *
 * NOT `final`: Filament instantiates a relation manager by its class name, and
 * an installation may want to extend this one — a different icon, an extra
 * column — for its own account resource.
 *
 * Reads through `Assignment::of()`, which already dedupes a role held both with
 * and without a context to one key, and writes through
 * `Assignment::give()`/`take()`, never `attach()`/`detach()`/`sync()`, for the
 * reasons written on `Assignment`.
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
            ->extraAttributes(['class' => 'fw-resource-table'])
            ->recordTitleAttribute('name')
            // `->query()` alone is not enough: the base table keeps its own
            // relationship closure, and `resolveTableRecord()` — what routes a
            // row action to its record — resolves a `MorphToMany` through the
            // RELATIONSHIP rather than the query, back through
            // `applyPivotTenancy()` and, for a role held both with and without
            // a context, to whichever duplicate pivot row it finds first.
            ->relationship(null)
            // Bought back from `$relatedResource` being null. Without them the
            // singular label falls to `get_model_label()` — the class basename,
            // kebab-cased with spaces and lowercase whatever the locale — the
            // plural to its English `Str::plural()`, and the empty state heading
            // reads that plural.
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
                // have, and the error would surface on click, not on build.
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

                // Calculated like the badge above it, and for the same reason
                // it carries no `sortable()`: there is no `ends_at` column on
                // the roles table, and the date lives on the pivot row.
                //
                // There is no «expired» reading to draw. `Assignment` reads
                // through `Expiry::live()`, so a lapsed assignment is invisible
                // to the whole class and a role held only by one is not in this
                // table at all — which is the honest answer: it is not held.
                TextColumn::make('ends_at')
                    ->label(__('filament-warden::ui.relations.roles.ends_column'))
                    ->badge()
                    ->color('info')
                    ->placeholder('—')
                    ->state(static fn (Model $record): ?string => self::endsAt($account, $record)),
            ])
            ->headerActions([$this->assignAction($account)])
            ->recordActions([$this->renewAction($account), $this->retractAction($account)]);
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

    private static function endsAt(Model $account, Model $record): ?string
    {
        $key = $record->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return null; // @codeCoverageIgnore
        }

        $ends = Assignment::endsAt($account, $key);

        return $ends instanceof CarbonImmutable
            ? $ends->toFormattedDayDateString().' · '.$ends->diffForHumans()
            : null;
    }

    private static function currentEnd(Model $account, Model $record): ?string
    {
        $key = $record->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return null; // @codeCoverageIgnore
        }

        return Assignment::endsAt($account, $key)?->toDateString();
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
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
     * The notification waits on `give()`'s return, for the reason given on
     * `Assignment::give()`.
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

                // A date, and deliberately NOT a context: this package shows a
                // restricted assignment, marks it and leaves it alone. Offering
                // to create one here would mint rows this very screen then
                // refuses to take back — `offers()` answers false for a
                // restricted role, so both the retract action and the checkbox
                // would go quiet on a row somebody had just made.
                DatePicker::make('until')
                    ->label(__('filament-warden::ui.relations.roles.assign.until'))
                    ->helperText(__('filament-warden::ui.relations.roles.assign.until_help'))
                    // Today is not in the future: warden reads the boundary
                    // exclusively, so a row dated today stops counting at the
                    // instant it names and would be handed out already over.
                    ->after('today'),
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

                if (! Assignment::give($account, $role, $this->date($data['until'] ?? null))) {
                    return;
                }

                Notification::make()
                    ->title(__('filament-warden::ui.relations.roles.assign.notified'))
                    ->success()
                    ->send();
            });
    }

    /**
     * The row action: move the end date on a role already held.
     *
     * A second action and not a flag on the first, because they answer opposite
     * questions — `give()` refuses a role already held, so once handed out
     * nothing on this screen could reach the date again. It is offered under
     * exactly the same conditions as the retract beside it: `offers()` is what
     * says this screen may write to that assignment at all.
     *
     * Clearing the field is a real answer and not a no-op: warden moves the date
     * only when a chain declared one, so an empty picker sends `until(null)` and
     * turns an ending assignment into one with no end.
     */
    private function renewAction(Model $account): Action
    {
        return Action::make('renew')
            ->label(__('filament-warden::ui.relations.roles.renew.label'))
            ->icon(Heroicon::OutlinedClock)
            ->modalHeading(__('filament-warden::ui.relations.roles.renew.heading'))
            ->visible(fn (Model $record): bool => ! $this->isReadOnly() && $this->offered($account, $record))
            ->schema([
                DatePicker::make('until')
                    ->label(__('filament-warden::ui.relations.roles.assign.until'))
                    ->helperText(__('filament-warden::ui.relations.roles.renew.help'))
                    ->after('today')
                    ->default(static fn (Model $record): ?string => self::currentEnd($account, $record)),
            ])
            ->action(function (Model $record, array $data) use ($account): void {
                $key = $record->getKey();

                // Repeated, for the reason the header action gives.
                $moved = ! $this->isReadOnly()
                    && (is_int($key) || is_string($key))
                    && Assignment::renew($account, $key, $this->date($data['until'] ?? null));

                if ($moved) {
                    Notification::make()
                        ->title(__('filament-warden::ui.relations.roles.renew.notified'))
                        ->success()
                        ->send();
                }
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

                // Repeated, for the reason the header action gives.
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
