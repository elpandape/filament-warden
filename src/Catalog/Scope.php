<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Catalog;

use ElPandaPe\FilamentWarden\Support\Config;

/**
 * What a screen risks when it lets an action through. Not to be confused with
 * warden's `scope` column, which is the tenant a row belongs to.
 */
enum Scope: string
{
    case Read = 'read';

    case Write = 'write';

    case Withdraw = 'withdraw';

    case Irreversible = 'irreversible';

    /**
     * Whatever the scope map does not name counts as a write: the safe guess for
     * an action nobody classified is that it changes something.
     */
    public static function forAction(string $action): self
    {
        foreach (Config::scopes() as $scope => $actions) {
            if (in_array($action, $actions, true)) {
                return self::tryFrom($scope) ?? self::Write;
            }
        }

        return self::Write;
    }
}
