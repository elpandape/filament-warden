<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Catalog;

use ElPandaPe\FilamentWarden\Support\Config;
use Filament\Panel;

/**
 * The one place loose permission names are minted. The catalogue offers them and
 * the guard asks for them: if the two ever disagreed, the permission would exist,
 * be grantable, and open nothing at all.
 */
final class PermissionName
{
    public static function page(string $page): string
    {
        return 'page:'.$page;
    }

    public static function widget(string $widget): string
    {
        return 'widget:'.$widget;
    }

    public static function panel(Panel $panel): string
    {
        $id = $panel->getId();

        return Config::panelPermission($id) ?? 'panel:'.$id;
    }
}
