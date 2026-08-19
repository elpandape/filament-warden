<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Conditions;

/**
 * How far a grant reaches, and whether this screen may change it.
 *
 * The first three are the ones offered. The last two are states the store can
 * hold and the grid cannot draw: they are shown, explained and left alone —
 * drawing one of them as one of the other three and then saving would rewrite
 * a rule nobody could see.
 */
enum Shape: string
{
    case All = 'all';

    case Owned = 'owned';

    case Conditions = 'conditions';

    case Unreadable = 'unreadable';

    case Tangled = 'tangled';

    public function isEditable(): bool
    {
        return in_array($this, [self::All, self::Owned, self::Conditions], true);
    }

    /**
     * Every rule that does not hold for every row needs a record in front of it,
     * and therefore never matches a class check.
     */
    public function isNarrowed(): bool
    {
        return $this !== self::All;
    }
}
