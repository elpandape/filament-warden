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
 * words belong to the screen: the same row and action keys the grid is drawn
 * from, so a caller can look their titles up in the catalogue it already has.
 */
final readonly class SaveReport
{
    /**
     * @param  list<array{row: string, action: string}>  $refused
     */
    public function __construct(
        public int $written = 0,
        public int $preserved = 0,
        public array $refused = [],
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
