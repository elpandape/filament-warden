<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Conditions;

/**
 * How far a grant reaches, and whether this screen may change it.
 *
 * The first three are the ones offered. The last three are states the store can
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

    case Elsewhere = 'elsewhere';

    public function isEditable(): bool
    {
        return in_array($this, [self::All, self::Owned, self::Conditions], true);
    }

    /**
     * Whether the screen may take this reach AWAY, which is a smaller question
     * than whether it may rewrite it.
     *
     * `Tangled` is the one shape where the two answers differ. It means the
     * store holds two rules of one polarity for one cell, so the screen cannot
     * draw it and must not round it off into either — but emptying it reads
     * nothing and rebuilds nothing, and revoking by permission model reaches
     * both rows precisely. Without this, a tangled cell would have no way out
     * of the panel at all.
     *
     * The other two unwritable shapes stay unwritable in both directions, for
     * reasons that are not symmetric with this one. `Elsewhere` is another
     * tenant's row, where a write deletes nothing and reports success (see
     * `Narrowing::elsewhere()`). `Unreadable` is a rule this version cannot
     * parse — and a corrupt PROHIBITION fails closed, so taking it away removes
     * protection nobody could read well enough to consent to losing.
     */
    public function isClearable(): bool
    {
        return $this === self::Tangled;
    }

    /**
     * Every rule that does not hold for every row needs a record in front of it:
     * a class check resolves no ownership and evaluates no condition, so such a
     * grant never matches one.
     */
    public function isNarrowed(): bool
    {
        return $this !== self::All;
    }
}
