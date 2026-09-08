<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\RelationManagers;

use Carbon\CarbonImmutable;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use ElPandaPe\FilamentWarden\Grants\DirectGrants;
use ElPandaPe\FilamentWarden\Grants\Holders;
use ElPandaPe\FilamentWarden\Support\Config;
use ElPandaPe\Warden\Context;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What an account holds without a role in between — the way back from the
 * permission screen's own hand-out.
 *
 * A direct grant is the hardest access in an installation to find again: it
 * belongs to no role, so no role's grid draws it, and until 3.0 the only screen
 * that could name one was the permission's own, one row at a time. That is
 * exactly why it is off out of the box — `permissions.direct` — and why turning
 * it on is a decision rather than a default: the screen does not only show
 * direct grants, it hands them out.
 *
 * A package cannot attach a relation manager to a resource it does not own
 * (§6.18), so this is handed over the same way the roles one is: a consuming
 * application's own account resource names it in `getRelations()`.
 *
 * NOT `final`, for the same reason its sibling is not: Filament instantiates a
 * relation manager by class name and an installation may want to extend this one.
 */
class PermissionsRelationManager extends RelationManager
{
    /**
     * Declared because the base class documents it and reading it unset is a
     * fatal rather than a null. Nothing on this screen's render or action path
     * reaches `getRelationshipName()`: the query is built by hand.
     */
    protected static string $relationship = 'permissions';

    /**
     * `null`, for the reasons its sibling's own docblock spells out:
     * `configureTable()` would register `PermissionsTable`'s edit and delete
     * into `flatActions`, where a raw `mountAction` still reaches them, and it
     * installs a `recordUrl` that throws on a panel which never registered the
     * resource.
     */
    protected static ?string $relatedResource = null;

    /**
     * Two questions and both have to answer yes. `permissions.direct` is the
     * installation's decision that this screen exists at all, and
     * `PermissionResource::canAccess()` is whether this person may look at
     * permissions — asked through the resource rather than guessed at the
     * account model's relation, which the base branch would do.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Config::enabled('permissions.direct') && PermissionResource::canAccess();
    }

    /**
     * Not `$title`: a static property is evaluated before translations load.
     */
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('filament-warden::ui.relations.permissions.label');
    }

    public function table(Table $table): Table
    {
        $account = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('name')
            // The base table keeps its own relationship closure otherwise, and
            // `resolveTableRecord()` routes a row action through the RELATION
            // rather than the query — back through a tenant predicate this
            // screen deliberately reads around.
            ->relationship(null)
            ->modelLabel(PermissionResource::getModelLabel())
            ->pluralModelLabel(PermissionResource::getPluralModelLabel())
            ->query(static fn (): Builder => Context::resolve()->permissionClass()::query()
                ->withoutGlobalScopes()
                ->whereKey(self::heldKeys($account)))
            ->columns([
                TextColumn::make('title')
                    ->label(__('filament-warden::ui.resources.permissions.columns.title'))
                    // The name, and — on a row this screen cannot write to —
                    // why. A row that is shown, marked and left alone is the one
                    // that most needs explaining: without the sentence the
                    // revoke action is simply missing, which reads as a bug
                    // rather than as a decision (§6.15).
                    ->description(static fn (Model $record): string => self::describe($account, $record))
                    ->searchable(['name', 'title']),

                TextColumn::make('entity_type')
                    ->label(__('filament-warden::ui.resources.permissions.fields.entity'))
                    ->placeholder(__('filament-warden::ui.resources.permissions.entity.none')),

                // Calculated, so no `sortable()`/`searchable()`: there is no
                // such column on the permissions table and the failure would
                // surface on click rather than on build (§6.17).
                TextColumn::make('polarity')
                    ->label(__('filament-warden::ui.relations.permissions.polarity'))
                    ->badge()
                    ->state(static fn (Model $record): string => self::row($account, $record)?->forbidden === true ? 'forbidden' : 'granted')
                    ->formatStateUsing(static fn (string $state): string => __('filament-warden::ui.stances.'.$state))
                    ->color(static fn (string $state): string => $state === 'forbidden' ? 'danger' : 'success'),

                TextColumn::make('reach')
                    ->label(__('filament-warden::ui.resources.permissions.columns.reach'))
                    ->badge()
                    ->color('gray')
                    ->state(static fn (Model $record): string => self::reach($account, $record)),

                TextColumn::make('ends_at')
                    ->label(__('filament-warden::ui.relations.roles.ends_column'))
                    ->badge()
                    ->color('info')
                    ->placeholder('—')
                    ->state(static fn (Model $record): ?string => self::endsAt($account, $record)),
            ])
            ->headerActions([$this->grantAction($account)])
            ->recordActions([$this->revokeAction($account)]);
    }

    /**
     * The permission keys this account holds directly.
     *
     * @return list<int|string>
     */
    private static function heldKeys(Model $account): array
    {
        $keys = [];

        foreach (DirectGrants::of($account) as $row) {
            $key = $row->permission->getKey();

            if (is_int($key) || is_string($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The grant behind one table row, or nothing when it went away between the
     * query and the column.
     */
    private static function row(Model $account, Model $record): ?DirectGrants
    {
        foreach (DirectGrants::of($account) as $row) {
            if (self::text($row->permission->getKey()) === self::text($record->getKey())) {
                return $row;
            }
        }

        return null; // @codeCoverageIgnore
    }

    /**
     * The row's own name, plus the reason nothing here can touch it.
     */
    private static function describe(Model $account, Model $record): string
    {
        $name = self::text($record->getAttribute('name'));

        return self::row($account, $record)?->elsewhere === true
            ? $name.' · '.__('filament-warden::ui.relations.permissions.elsewhere')
            : $name;
    }

    private static function reach(Model $account, Model $record): string
    {
        $row = self::row($account, $record);

        return $row instanceof DirectGrants
            ? (string) __('filament-warden::ui.'.($row->reach() === 'record'
                ? 'resources.permissions.entity.record'
                : 'reach.'.$row->reach()))
            : ''; // @codeCoverageIgnore
    }

    private static function endsAt(Model $account, Model $record): ?string
    {
        $ends = self::row($account, $record)?->ends;

        return $ends instanceof CarbonImmutable
            ? $ends->toFormattedDayDateString().' · '.$ends->diffForHumans()
            : null;
    }

    /**
     * Whether a polarity value is the forbidding one.
     *
     * Compared against the keys the field declares rather than cast: the state
     * arrives as an int from the browser and a bool from the default, and
     * `(bool) '0'` is false while `(bool) 'false'` is true.
     */
    private static function forbidding(mixed $value): bool
    {
        return in_array($value, [1, '1', true], true);
    }

    /**
     * Anything that reads as a key or a name, as text; everything else as the
     * empty string, which matches no row and shows no name.
     */
    private static function text(mixed $value): string
    {
        return is_int($value) || is_string($value) ? (string) $value : '';
    }

    private static function label(mixed $value): ?string
    {
        $permission = DirectGrants::permission($value);

        return $permission instanceof Model ? Holders::label($permission) : null;
    }

    /**
     * Whether this screen may write to one row at all: not read-only, and the
     * grant not written at a scope a write from here cannot reach.
     *
     * A grant from another tenant is shown, marked and left alone, exactly as
     * the grid treats one: `disallow()` targets one exact scope, so a revoke
     * from here would delete nothing, report success and come back unchanged
     * (§6.21).
     */
    private function writable(Model $account, Model $record): bool
    {
        $row = self::row($account, $record);

        return ! $this->isReadOnly()
            && $row instanceof DirectGrants
            && ! $row->elsewhere
            && DirectGrants::mayWrite($record);
    }

    /**
     * The header action: hand a permission straight to this account.
     *
     * `->visible()` closes the button on a read-only page and the closure does
     * NOT repeat that check, unlike the roles manager's own header action.
     * `mountAction()` and `callMountedAction()` both consult `isDisabled()`,
     * which is true whenever `isHidden()` is, so a copy would be a line nothing
     * can reach — measured, with a bare mount pair on a `ViewRecord`, and this
     * project runs the coverage gate at 100% with no baseline.
     *
     * What holds the write is not the page mode anyway: it is
     * `DirectGrants::write()`, which asks `mayWrite()` whatever screen called
     * it. That is where the guarantee lives, and it has a test of its own that
     * never renders a page at all.
     */
    private function grantAction(Model $account): Action
    {
        return Action::make('grant')
            ->label(__('filament-warden::ui.relations.permissions.grant.label'))
            ->icon(Heroicon::OutlinedKey)
            ->modalHeading(__('filament-warden::ui.relations.permissions.grant.heading'))
            ->modalDescription(__('filament-warden::ui.relations.permissions.grant.description'))
            ->visible(fn (): bool => ! $this->isReadOnly())
            ->schema([
                Select::make('permission')
                    ->label(__('filament-warden::ui.relations.permissions.grant.field'))
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(static fn (string $search): array => DirectGrants::offerable($search))
                    ->getOptionLabelUsing(static fn (mixed $value): ?string => self::label($value)),

                ToggleButtons::make('forbidden')
                    ->label(__('filament-warden::ui.resources.permissions.grant.polarity'))
                    ->inline()
                    ->live()
                    ->default(false)
                    ->options([
                        0 => __('filament-warden::ui.resources.permissions.grant.granted'),
                        1 => __('filament-warden::ui.resources.permissions.grant.forbidden'),
                    ])
                    ->colors([0 => 'success', 1 => 'danger']),

                DatePicker::make('until')
                    ->label(__('filament-warden::ui.relations.roles.assign.until'))
                    ->helperText(__('filament-warden::ui.relations.permissions.grant.until_help'))
                    ->after('today')
                    // A prohibition cannot carry one: `ForbidsPermissions::until()`
                    // throws unconditionally, `null` included. The reason is on
                    // the permission screen's own modal, which is the same write.
                    ->visible(static fn (Get $get): bool => ! self::forbidding($get('forbidden'))),
            ])
            ->action(function (array $data) use ($account): void {
                /** @var array<string, mixed> $data */
                $this->hand($account, $data);
            });
    }

    /**
     * The write behind the modal, once the screen has agreed to it.
     *
     * @param  array<string, mixed>  $data
     */
    private function hand(Model $account, array $data): void
    {
        $permission = DirectGrants::permission($data['permission'] ?? null);

        if (! $permission instanceof Model) {
            return; // @codeCoverageIgnore
        }

        $forbidding = self::forbidding($data['forbidden'] ?? false);
        $until = $data['until'] ?? null;

        $written = DirectGrants::write(
            $account,
            $permission,
            $forbidding,
            is_string($until) && $until !== '' ? CarbonImmutable::parse($until) : null,
        );

        if ($written) {
            Notification::make()
                ->title(__('filament-warden::ui.relations.permissions.grant.notified'))
                ->success()
                ->send();
        }
    }

    /**
     * The row action: take a direct grant back, whichever polarity it is in.
     */
    private function revokeAction(Model $account): Action
    {
        return Action::make('revoke')
            ->label(__('filament-warden::ui.relations.permissions.revoke.label'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('filament-warden::ui.relations.permissions.revoke.description'))
            ->visible(fn (Model $record): bool => $this->writable($account, $record))
            ->action(function (Model $record) use ($account): void {
                // Repeated for the same reason the header action repeats its
                // own, and it is not the same check: `writable()` also answers
                // whether the row is one this scope can delete at all.
                if ($this->writable($account, $record) && DirectGrants::revoke($account, $record)) {
                    Notification::make()
                        ->title(__('filament-warden::ui.relations.permissions.revoke.notified'))
                        ->success()
                        ->send();
                }
            });
    }
}
