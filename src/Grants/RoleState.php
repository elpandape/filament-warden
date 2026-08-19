<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use ElPandaPe\FilamentWarden\Conditions\Narrowing;

/**
 * What a role says today, in the shape the grid holds it.
 *
 * `narrowings` is how far each cell reaches: every row, only what it owns, with
 * these conditions — or one of the two states this screen can read and cannot
 * draw, which are shown and left alone.
 *
 * `wider` is what the grid cannot hold at all — a rule written over `*`, every
 * entity at once. It owns no cell, so it is reported rather than drawn as one:
 * the role that holds everything must not read as a role that holds nothing.
 */
final readonly class RoleState
{
    /**
     * @param  array<string, array<string, string>>  $stances
     * @param  array<string, array<string, Narrowing>>  $narrowings
     * @param  array<string, string>  $wider  rules over every entity, keyed by permission name
     */
    public function __construct(
        public array $stances = [],
        public array $narrowings = [],
        public array $wider = [],
    ) {}

    /**
     * The cells whose rule needs a record in front of it, which is what the
     * inspector needs in order to say what `explain()` cannot.
     *
     * @return array<string, array<string, bool>>
     */
    public function narrowed(): array
    {
        return $this->map(static fn (Narrowing $narrowing): bool => $narrowing->isNarrowed());
    }

    /**
     * The cells this screen must not write.
     *
     * @return array<string, array<string, bool>>
     */
    public function locked(): array
    {
        return $this->map(static fn (Narrowing $narrowing): bool => ! $narrowing->isEditable());
    }

    /**
     * @param  callable(Narrowing): bool  $answers
     * @return array<string, array<string, bool>>
     */
    private function map(callable $answers): array
    {
        $mapped = [];

        foreach ($this->narrowings as $row => $actions) {
            foreach ($actions as $action => $narrowing) {
                if ($answers($narrowing)) {
                    $mapped[$row][$action] = true;
                }
            }
        }

        return $mapped;
    }
}
