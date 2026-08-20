<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Permissions;

use BackedEnum;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\CreatePermission;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\EditPermission;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\ListPermissions;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\ViewPermission;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Schemas\PermissionForm;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Schemas\PermissionInfolist;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Tables\PermissionsTable;
use ElPandaPe\FilamentWarden\Grants\Holders;
use ElPandaPe\FilamentWarden\Support\Config;
use ElPandaPe\Warden\Context;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The catalogue, with its provenance visible.
 *
 * Read-only out of the box, and deliberately: a fresh installation cannot mint a
 * permission nothing consults. Every one of the six switches under
 * `permissions.*` is read here — opening any of them is one line of config, and
 * closing it again afterwards means cleaning up whatever was created meanwhile.
 */
class PermissionResource extends Resource
{
    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    /**
     * Warden's models belong to no tenant of Filament's, and saying so is not
     * optional.
     *
     * A panel with `->tenant()` puts a global scope on every resource's MODEL —
     * not on the resource — and that scope demands a relationship named after the
     * tenant class, throwing `LogicException` when it is missing. Measured: with
     * this line gone, `Role::query()->count()`, `Role::create()` and the
     * resource's own query all throw. It does not break two screens; it poisons
     * warden's model for the whole request, its internals included.
     *
     * Declared as the property and never through `scopeToTenant()`, which is
     * static and would un-scope every resource of the consuming application — a
     * cross-tenant leak this package would have caused.
     */
    protected static bool $isScopedToTenant = false;

    public static function getModel(): string
    {
        return Context::resolve()->permissionClass();
    }

    /**
     * Overridden rather than set as `$slug`, because the base memoises the
     * resolved default per class and a config-driven one would freeze.
     */
    public static function getDefaultSlug(): string
    {
        $slug = Config::get('navigation.permissions.slug');

        return is_string($slug) && $slug !== '' ? $slug : 'permissions';
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        $icon = Config::get('navigation.permissions.icon');

        return is_string($icon) || $icon instanceof BackedEnum ? $icon : Heroicon::OutlinedKey;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $group = Config::get('navigation.group');

        return is_string($group) || $group instanceof UnitEnum
            ? $group
            : (string) __('filament-warden::ui.navigation.group');
    }

    public static function getNavigationSort(): ?int
    {
        $sort = Config::get('navigation.permissions.sort');

        return is_int($sort) ? $sort : null;
    }

    public static function getModelLabel(): string
    {
        return __('filament-warden::ui.resources.permissions.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-warden::ui.resources.permissions.models');
    }

    /**
     * The policy decides who may create; the config decides whether anyone may.
     */
    public static function canCreate(): bool
    {
        return Config::get('permissions.create') !== false && parent::canCreate();
    }

    public static function canEdit(Model $record): bool
    {
        return Config::get('permissions.update') !== false && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return self::isDeletable($record) && parent::canDelete($record);
    }

    /**
     * A permission nobody holds can go without taking anything with it. One that
     * somebody holds takes their grants down with it, in the database and
     * without a single Eloquent event, so it is closed by default.
     */
    public static function isDeletable(Model $record): bool
    {
        $rule = Config::get('permissions.delete');

        if ($rule === 'all') {
            return true;
        }

        return $rule === 'orphaned' && Holders::of($record)->isOrphaned();
    }

    /**
     * What the name is: `false` nothing, `'title'` only the label, `'loose'` the
     * permissions with no model behind them, `'all'` everything — including the
     * name of a derived one, which is what disconnects it from its policy.
     */
    public static function mayEditName(Model $record): bool
    {
        $rule = Config::get('permissions.update');

        return $rule === 'all'
            || ($rule === 'loose' && $record->getAttribute('entity_type') === null);
    }

    public static function mayEditConditions(Model $record): bool
    {
        return Config::enabled('permissions.constraints') && self::mayEditName($record);
    }

    public static function mayEditOwnership(Model $record): bool
    {
        return Config::enabled('permissions.only_owned') && self::mayEditName($record);
    }

    public static function form(Schema $schema): Schema
    {
        return PermissionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PermissionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PermissionsTable::configure($table);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPermissions::route('/'),
            'create' => CreatePermission::route('/create'),
            'view' => ViewPermission::route('/{record}'),
            'edit' => EditPermission::route('/{record}/edit'),
        ];
    }
}
