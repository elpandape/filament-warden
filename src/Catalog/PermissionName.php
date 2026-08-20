<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Catalog;

use ElPandaPe\FilamentWarden\Support\Config;
use ElPandaPe\Warden\Support\Titles\PermissionTitle;
use Filament\Panel;
use Illuminate\Support\Str;

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

    /**
     * The name read back into a title that says what the permission DOES, for the
     * names this package minted and for no others.
     *
     * Warden cannot do this and should not be expected to: its title generator
     * falls back to `Str::ucfirst()` of the name for a permission with no entity,
     * and a name like `widget:Filament\Widgets\AccountWidget` has no hyphens to
     * break on — so the title comes out as the name with one capital letter.
     *
     * The verb is `Access`, and it is not a free choice: it is the word the grid
     * already uses for a door — `StateKey::DOOR` is literally `access` — so the
     * title and the column say the same thing about the same cell.
     */
    public static function title(string $name): ?string
    {
        $screen = self::screen($name);

        return $screen === null ? null : 'Access '.$screen;
    }

    /**
     * Every title this package or warden has ever generated for this name.
     *
     * A row carrying one of these was nobody's writing and may be rewritten; a
     * row carrying anything else belongs to whoever wrote it. The list grows
     * rather than changes, because an installation upgraded from an older version
     * still has the older shape in its rows: `0.9.1` wrote the bare screen name.
     *
     * @return list<string>
     */
    public static function generated(string $name): array
    {
        $screen = self::screen($name);

        if ($screen === null) {
            return [];
        }

        return [
            PermissionTitle::generate($name, null, null, false),
            $screen,
        ];
    }

    /**
     * The screen a door opens, named the way a person would.
     */
    private static function screen(string $name): ?string
    {
        foreach (['page:', 'widget:'] as $kind) {
            if (str_starts_with($name, $kind)) {
                return Str::headline(class_basename(Str::after($name, $kind)));
            }
        }

        return str_starts_with($name, 'panel:')
            ? 'the '.Str::headline(Str::after($name, 'panel:')).' panel'
            : null;
    }
}
