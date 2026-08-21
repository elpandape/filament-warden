<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Conditions\Shape;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;

/**
 * A rule pinned to one row of one table.
 *
 * It is not a cell and it never can be: warden filters a check made against a
 * class to `entity_type = morph and entity_id is null`, so a rule with an id on
 * it answers nothing the grid asks. It is not `wider` either — reporting it
 * there would paint the hollow tick, and raise the tab tally, on every cell of
 * the same name, cells the role provably does not have.
 *
 * So it is said once, above the grid, and left alone. That is not modesty: a
 * save revokes with a class entity, which warden compiles down to
 * `entity_id is null`, so this screen can neither delete it nor rewrite it.
 */
final readonly class RecordGrant
{
    /**
     * @param  string  $model  the class behind the morph alias, as the grid names one
     * @param  string  $id  the record key, as text: a key that does not read as a key matches nothing
     */
    public function __construct(
        public string $name,
        public string $model,
        public string $id,
        public Stance $stance,
        public Narrowing $narrowing,
    ) {}

    /**
     * The shape word this line still needs, or null when there is nothing left
     * to say.
     *
     * A rule pinned to one record and reaching every row of it is already what
     * the line reads as, so repeating "Every row" beside it would be the same
     * lie the permission screen's badge told.
     */
    public function reach(): ?string
    {
        return $this->narrowing->shape === Shape::All ? null : $this->narrowing->shape->value;
    }
}
