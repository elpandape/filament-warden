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
     * row carrying anything else belongs to whoever wrote it. An installation
     * upgraded from an older version still carries the older shapes.
     *
     * This list may only GROW: an entry added is a licence to rewrite rows in
     * somebody else's database, so a new shape is a MAJOR and `FrozenTest` says
     * so. Nothing here may delegate to a moving target either, except warden's
     * answer for TODAY — one entry did, and warden 2.0 then replaced a shape
     * rather than adding one, so titles warden itself wrote stopped being
     * recognised.
     *
     * A row this package did NOT mint still comes through here rather than
     * being asked at the two call sites, so "did we write this?" has one answer
     * for both families of row.
     *
     * @return list<string>
     */
    public static function generated(string $name, ?string $entityType = null, bool $onlyOwned = false): array
    {
        $screen = self::screen($name);

        if ($screen === null) {
            return self::wardens($name, $entityType, $onlyOwned);
        }

        return [
            // What warden writes for a permission with no entity, in both the
            // shape it writes today and the one it wrote before 2.0.
            ...self::wardens($name, null, false),
            // What `0.9.1` wrote: the screen, with no verb at all.
            $screen,
            // What `0.10.1` wrote: one verb for all three kinds.
            'Access '.$screen,
        ];
    }

    /**
     * Warden's answer for this row, in every shape warden has written it.
     * Deduplicated: most names read the same under both generators.
     *
     * @return list<string>
     */
    private static function wardens(string $name, ?string $entityType, bool $onlyOwned): array
    {
        return array_values(array_unique([
            PermissionTitle::generate($name, $entityType, null, $onlyOwned),
            self::beforeTwo($name, $entityType, $onlyOwned),
        ]));
    }

    /**
     * Warden's generator as it stood before 2.0, transcribed and FROZEN.
     *
     * Copied from `PermissionTitle` at warden `1.3.0`, verified against the
     * published archive. It must never be made to track warden again: a row
     * titled under 1.x keeps that title after the upgrade, so the only way to
     * recognise it is to have written the old rule down. A third generator gets
     * a third transcription beside this one; this method does not move.
     *
     * The whole shape is copied rather than the one-line delta, because a delta
     * would have to be re-derived from whatever warden says today.
     *
     * The arm for a row pinned to a record is left out: `generated()` has no id
     * parameter, so it would be a branch no test could honestly reach.
     */
    private static function beforeTwo(string $name, ?string $entityType, bool $onlyOwned): string
    {
        return match (true) {
            $name === '*' && $entityType === '*' && $onlyOwned => 'Manage everything owned',
            $name === '*' && $entityType === '*' => 'All permissions',
            $name === '*' && $entityType === null => 'All simple permissions',
            $entityType === '*' && $onlyOwned => self::action($name).' everything owned',
            $entityType === '*' => self::action($name).' everything',
            $entityType !== null && $name === '*' => 'Manage '.Str::plural(self::entity($entityType)),
            $entityType !== null => self::action($name).' '.Str::plural(self::entity($entityType)),
            default => self::action($name),
        };
    }

    /**
     * Warden 1.x's action verb. Frozen with `beforeTwo()`.
     */
    private static function action(string $name): string
    {
        return $name === '*' ? 'Manage' : Str::ucfirst(str_replace(['-', '_'], ' ', $name));
    }

    /**
     * Warden 1.x's entity word. Unchanged in 2.0, copied anyway so the
     * transcription stands on its own.
     */
    private static function entity(string $entityType): string
    {
        $basename = Str::afterLast(Str::afterLast($entityType, '\\'), '.');

        return Str::lower(Str::snake($basename, ' '));
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
