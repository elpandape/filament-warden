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
     * The verb is not a free choice, and it is not one verb either: it is the
     * word Filament itself asks with. A page and a panel answer `canAccess()`,
     * a widget answers `canView()` — which is why this package's own traits are
     * called `AuthorizesPageAccess` and `AuthorizesWidgetView`. A widget is seen;
     * a page is entered. The title says the same.
     */
    public static function title(string $name): ?string
    {
        $screen = self::screen($name);

        return $screen === null ? null : self::verb($name).' '.$screen;
    }

    /**
     * Every title this package or warden has ever generated for this name.
     *
     * A row carrying one of these was nobody's writing and may be rewritten; a
     * row carrying anything else belongs to whoever wrote it. There are three,
     * because an installation upgraded from an older version still has the older
     * shape in its rows: `0.9.1` wrote the bare screen name and `0.10.1` wrote
     * one verb for all three kinds.
     *
     * CLOSED at `1.0.0`. It grew three times in two days while the wording was
     * still being settled, and every entry added here is a licence to rewrite
     * rows in somebody else's database because we changed our minds about a verb.
     * A fourth shape is a MAJOR, and `tests/FrozenTest.php` is what says so.
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
            // What warden writes, for a permission with no entity.
            PermissionTitle::generate($name, null, null, false),
            // What `0.9.1` wrote: the screen, with no verb at all.
            $screen,
            // What `0.10.1` wrote: one verb for all three kinds.
            'Access '.$screen,
        ];
    }

    /**
     * `canAccess()` for a page and a panel, `canView()` for a widget — Filament's
     * own two questions, and the reason this package has two traits and not one.
     */
    private static function verb(string $name): string
    {
        return str_starts_with($name, 'widget:') ? 'View' : 'Access';
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
