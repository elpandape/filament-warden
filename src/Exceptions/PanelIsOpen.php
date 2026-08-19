<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Exceptions;

use RuntimeException;

/**
 * A panel that would have started with a screen nobody guards.
 *
 * Thrown at panel boot rather than reported, because reporting is what the
 * generation before this one did: a test that says who gets in, and nothing that
 * stops them. Filament's own `canAccess()` and `canView()` return `true`
 * literally and `->strictAuthorization()` never reaches a page or a widget, so
 * this is the only thing standing between an unguarded screen and everybody.
 *
 * The message names the screens. A dead panel that does not say which one is
 * worse than no guard at all.
 */
final class PanelIsOpen extends RuntimeException
{
    /**
     * @param  list<string>  $screens
     */
    public static function with(string $panel, array $screens): self
    {
        $list = implode(', ', $screens);

        return new self(
            "The panel [{$panel}] has screens that do not decide who gets in: {$list}. "
            .'Filament answers `true` for a page or a widget that does not override canAccess() or canView(), '
            .'so each of these is open to anybody who reaches the panel. Give each one a decision, '
            .'or turn this off with `filament-warden.guard.pages` / `guard.widgets`.',
        );
    }
}
