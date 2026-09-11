<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

/**
 * What a save did, including where somebody else was editing the same role,
 * or the same account, at once.
 *
 * A payload compared straight against the store reads every difference as
 * something this person wanted. Compared against the baseline the screen was
 * showing, a save has three outcomes instead of one:
 *
 * - `written`: cells this person moved, with nobody else in the way.
 * - `preserved`: cells this person did NOT move and somebody else did. Nothing
 *   was lost. What was wrong was the screen, which was showing what the store
 *   held when it was opened.
 * - `refused`: cells this person moved that somebody else moved too, to
 *   something else. Their intent was not applied, and that is the half a
 *   notification has to say out loud rather than count.
 *
 * Three more lists name cells the save left alone for a reason of their own:
 *
 * - `unresolved`: cells holding more than one rule, asked to become anything
 *   but empty. The screen cannot draw them, so it will not rebuild them.
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
 *   a column the model does not cast to bool, or the mirror of it. Refused
 *   before warden is asked, for the reason `RoleGrants::plan()` gives.
 *
 * `refused`, `unresolved`, `lapsed` and `impossible` carry the cell's own keys
 * rather than a sentence, because the words belong to the screen: the row and
 * action keys the grid is drawn from, so a caller looks their titles up in the
 * catalogue it already has.
 *
 * `granted`, `forbidden` and `revoked` split `written` by the stance each cell
 * was moved TO, so a save can say what it did rather than how much of it there
 * was. They are the grid's own breakdown and stay at zero on the account
 * screen, where a role is held or it is not and no stance exists to count.
 *
 * The account screen never fills `refused` either (`Assignment::apply()` says
 * why), and the list keeps the grid's shape anyway: typed as any map, a key
 * `PermissionGrid::refusedCells()` does not read would pass `level: max` and
 * fail at runtime instead.
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
     * Whether anybody else's edit met this one — what decides whether a save
     * says anything about other people at all.
     */
    public function metAnother(): bool
    {
        return $this->preserved > 0 || $this->refused !== [];
    }
}
