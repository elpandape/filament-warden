<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament;

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Exceptions\PanelIsOpen;
use ElPandaPe\FilamentWarden\Support\Config;
use Filament\Panel;
use ReflectionException;
use ReflectionMethod;

/**
 * The screens of a panel that do not decide who gets in.
 *
 * `Page::canAccess()` and `Widget::canView()` return `true` literally, and
 * `->strictAuthorization()` only ever reaches a Resource. So a page an
 * application registers without a decision is open to everybody who can reach
 * the panel, in silence and with nothing to read.
 *
 * Reflection tells a decision from an inheritance, and it is worth saying why it
 * works: Filament's default arrives through a trait used by ITS OWN base class,
 * so `getDeclaringClass()` answers `Filament\Pages\Page` for a page that does
 * not override it, and the page itself for one that does — even when the
 * override arrives through a trait of the application's own, because a trait
 * never reports itself as the declaring class.
 *
 * The rule is therefore one line: if the declaring class is Filament's, nobody
 * decided.
 */
final class Guard
{
    /**
     * @return list<string>
     */
    public static function unguarded(Panel $panel): array
    {
        $open = [];

        if (Config::enabled('guard.pages')) {
            foreach (Catalog::pageClasses($panel) as $page) {
                if (self::isOurs($page) && ! self::decides($page, 'canAccess')) {
                    $open[] = $page;
                }
            }
        }

        if (Config::enabled('guard.widgets')) {
            foreach (Catalog::widgetClasses($panel) as $widget) {
                if (self::isOurs($widget) && ! self::decides($widget, 'canView')) {
                    $open[] = $widget;
                }
            }
        }

        return $open;
    }

    /**
     * @throws PanelIsOpen
     */
    public static function enforce(Panel $panel): void
    {
        $open = self::unguarded($panel);

        if ($open !== []) {
            throw PanelIsOpen::with($panel->getId(), $open);
        }
    }

    /**
     * Filament's own screens are its own business: a dashboard, a login page and
     * the tenancy screens come with the framework and are not what an application
     * forgot to guard. The promise is about the screens somebody registered.
     *
     * @param  class-string  $screen
     */
    private static function isOurs(string $screen): bool
    {
        return ! str_starts_with($screen, 'Filament\\');
    }

    /**
     * @param  class-string  $screen
     */
    private static function decides(string $screen, string $method): bool
    {
        try {
            $declaring = new ReflectionMethod($screen, $method)->getDeclaringClass()->getName();
        } catch (ReflectionException) {
            // A screen with no such method at all decides nothing, which is the
            // same answer and the safe one.
            return false;
        }

        return ! str_starts_with($declaring, 'Filament\\');
    }
}
