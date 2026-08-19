<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles;

use BackedEnum;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\CreateRole;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\EditRole;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\ListRoles;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Schemas\RoleForm;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Tables\RolesTable;
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
 * Roles: a list, a form, and the grid inside it.
 *
 * The model is resolved at runtime and never declared as `$model`: Filament's
 * fallback builds the class from the CONSUMING application's namespace, so a
 * package resource that leaves it unset points at a class that does not exist.
 */
class RoleResource extends Resource
{
    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    public static function getModel(): string
    {
        return Context::resolve()->roleClass();
    }

    /**
     * Overridden rather than set as `$slug`, because the base memoises the
     * resolved default per class and a config-driven one would freeze.
     */
    public static function getDefaultSlug(): string
    {
        $slug = Config::get('navigation.roles.slug');

        return is_string($slug) && $slug !== '' ? $slug : 'roles';
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        $icon = Config::get('navigation.roles.icon');

        return is_string($icon) || $icon instanceof BackedEnum ? $icon : Heroicon::OutlinedShieldCheck;
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
        $sort = Config::get('navigation.roles.sort');

        return is_int($sort) ? $sort : null;
    }

    /**
     * Without these two the navigation item, the breadcrumb and the list heading
     * are named from the class while everything else on the screen is translated.
     */
    public static function getModelLabel(): string
    {
        return __('filament-warden::ui.resources.roles.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-warden::ui.resources.roles.models');
    }

    /**
     * The policy decides who may create; the config decides whether anyone may.
     */
    public static function canCreate(): bool
    {
        return Config::get('roles.create') !== false && parent::canCreate();
    }

    /**
     * A protected role never leaves, whatever the policy says, and an assigned
     * one only leaves when the installation said it could.
     */
    public static function canDelete(Model $record): bool
    {
        return self::isDeletable($record) && parent::canDelete($record);
    }

    public static function isProtected(Model $record): bool
    {
        $protected = Config::get('roles.protected');
        $name = $record->getAttribute('name');

        return is_array($protected) && is_string($name) && in_array($name, $protected, true);
    }

    public static function isDeletable(Model $record): bool
    {
        if (self::isProtected($record)) {
            return false;
        }

        $rule = Config::get('roles.delete');

        if ($rule === 'all') {
            return true;
        }

        if ($rule !== 'unassigned') {
            return false;
        }

        return ! Context::resolve()->assignedRoleClass()::query()
            ->where('role_id', $record->getKey())
            ->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
