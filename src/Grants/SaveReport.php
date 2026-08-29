<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

/**
 * What a save did, once two people can be editing the same role at once.
 *
 * A save used to be one outcome — it happened — because the screen's payload
 * was compared straight against the store, so every difference was read as
 * something this person wanted. With a baseline there are three, and only the
 * first is what the old notification meant:
 *
 * - `written`: cells this person moved, with nobody else in the way.
 * - `preserved`: cells this person did NOT move and somebody else did. Nothing
 *   was lost. What was wrong was the screen, which was showing what the store
 *   held when it was opened.
 * - `refused`: cells this person moved that somebody else moved too, to
 *   something else. Their intent was not applied, and that is the half a
 *   notification has to say out loud rather than count.
 *
 * `refused` carries the cell's own keys rather than a sentence, because the
 * words belong to the screen: the row and action keys the grid is drawn from, so
 * a caller looks their titles up in the catalogue it already has.
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
     */
    public function __construct(
        public int $written = 0,
        public int $preserved = 0,
        public array $refused = [],
        public array $unresolved = [],
        public int $granted = 0,
        public int $forbidden = 0,
        public int $revoked = 0,
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
