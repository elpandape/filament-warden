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
     * upgraded from an older version still has the older shape in its rows:
     * `0.9.1` wrote the bare screen name, `0.10.1` wrote one verb for all three
     * kinds, and warden itself changed its generator in 2.0.
     *
     * CLOSED at `1.0.0`, and OPENED once, here, at `2.0.0`. Every entry added is
     * a licence to rewrite rows in somebody else's database, so a fourth shape is
     * a MAJOR and `tests/FrozenTest.php` is what says so. This one is not a
     * change of mind about a verb: the list was supposed to GROW and it MUTATED,
     * because one of its entries delegated to warden's generator LIVE instead of
     * being written down. Warden 2.0 put a `Str::snake()` in that generator, so
     * `ViewAny posts` and `Page:App\Filament\Pages\Settings` — titles warden
     * itself wrote — silently stopped being recognised as generated, and rows an
     * upgraded installation already carries stopped being rewritable. The entry
     * that moved is now transcribed and frozen; nothing here delegates to a
     * moving target again except warden's answer for TODAY, which is the one
     * entry that is supposed to move.
     *
     * A row this package did NOT mint gets warden's own answer for the row as
     * it stands — one title, not three, because warden has only ever written the
     * one. That branch is here rather than at the two call sites so that "did we
     * write this?" is asked in one place for both families of row: the doors
     * this package mints, and everything warden titles.
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
     * Warden's own answer for this row, in every shape warden has written it.
     *
     * Deduplicated because most rows read the same under both generators — only
     * a name `Str::snake()` moves comes out differently — and a list with the
     * same title twice reads as a mistake.
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
     * Warden's generator as it stood before 2.0, transcribed here and FROZEN.
     *
     * Copied from `Support\Titles\PermissionTitle` at warden `1.3.0` — verified
     * against the published archive, not remembered — and it must never be made
     * to track warden again. Tracking it live is the defect this exists to undo:
     * a row titled by an installation running warden 1.x keeps that title after
     * the upgrade, so the only way to still recognise it is to have written the
     * old rule down. If warden changes its generator a third time, a fifth shape
     * gets transcribed beside this one; this method does not move.
     *
     * The two generators differ in exactly one place — 1.x spelled the action
     * `Str::ucfirst(str_replace(['-', '_'], ' ', $name))` and 2.0 wrapped the
     * name in `Str::snake($name, ' ')` first — but the whole shape is copied
     * rather than the delta, because a delta has to be re-derived from whatever
     * warden happens to say today, which is the very thing that broke.
     *
     * One arm of the original is left out: the one for a row pinned to a record
     * (`$entityId !== null`). No caller here has an id to pass — the parameter
     * does not exist on `generated()` — so transcribing it would be a branch no
     * test could honestly reach, and `make coverage` is a 100 % line gate.
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
     * Warden 1.x's entity word. Frozen with `beforeTwo()` — unchanged in 2.0,
     * and copied anyway so the transcription stands on its own.
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
