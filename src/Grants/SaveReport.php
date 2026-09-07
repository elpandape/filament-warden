<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

/**
 * What a save did, once two people can be editing the same role at once.
 *
 * A payload compared straight against the store reads every difference as
 * something this person wanted. Compared against the baseline the screen was
 * showing, a save has three outcomes instead of one (§6.36):
 *
 * - `written`: cells this person moved, with nobody else in the way.
 * - `preserved`: cells this person did NOT move and somebody else did. Nothing
 *   was lost. What was wrong was the screen, which was showing what the store
 *   held when it was opened.
 * - `refused`: cells this person moved that somebody else moved too, to
 *   something else. Their intent was not applied, and that is the half a
 *   notification has to say out loud rather than count.
 *
 * - `lapsed`: cells this person asked to grant until a moment that has already
 *   passed. Nothing was written, and nothing could have been: warden stops
 *   reading a row at the instant it names, so the grant would have authorised
 *   nothing while the save reported success. It has a list of its own rather
 *   than joining `unresolved`, whose sentence names a rule the screen cannot
 *   read — a different thing said in the same words is how a person ends up
 *   chasing the wrong problem.
 *
 * - `impossible`: cells whose rule can never be true — a boolean value against
 *   a column the model does not cast to bool, or the mirror of it. Warden
 *   refuses the write, and this package refuses it FIRST, because warden's
 *   refusal arrives after the plain grant beneath the condition is already
 *   written and a catch meant for two other causes swallows it.
 *
 * `refused`, `unresolved`, `lapsed` and `impossible` carry the cell's own keys rather than a
 * sentence, because the words belong to the screen: the row and action keys the
 * grid is drawn from, so a caller looks their titles up in the catalogue it
 * already has.
 *
 * `granted`, `forbidden` and `revoked` split `written` by the stance each cell
 * was moved TO, so a save can say what it did rather than how much of it there
 * was. They are the grid's own breakdown and stay at zero on the account
 * screen, where a role is held or it is not and no stance exists to count.
 *
 * The account screen shares this class and never fills that list — a role is
 * held or it is not, so two people can only ever have moved one the same way,
 * and `Assignment::apply()` explains why the branch does not exist. The shape
 * stays the grid's for the reason a wider one cost: typed as any map, pushing
 * a key `PermissionGrid::refusedCells()` does not read passes `level: max` and
 * fails at runtime instead. A shape no code produces is not worth a static guarantee.
 */
final readonly class SaveReport
{
    /**
     * @param  list<array{row: string, action: string}>  $refused
     * @param  list<array{row: string, action: string}>  $unresolved
     * @param  list<array{row: string, action: string}>  $lapsed
     * @param  list<array{row: string, action: string}>  $impossible
     */
    public function __construct(
        public int $written = 0,
        public int $preserved = 0,
        public array $refused = [],
        public array $unresolved = [],
        public int $granted = 0,
        public int $forbidden = 0,
        public int $revoked = 0,
        public array $lapsed = [],
        public array $impossible = [],
    ) {}

    /**
     * Whether anybody else's edit met this one.
     *
     * A save that met nobody keeps the notification it has always had; only
     * this answers whether there is anything more to say.
     */
    public function metAnother(): bool
    {
        return $this->preserved > 0 || $this->refused !== [];
    }
}
