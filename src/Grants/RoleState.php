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
     * The store, in the shape the browser holds it.
     *
     * Both screens hand alpine this and nothing else: the form as a live binding
     * it writes back, the screen that only reads as a literal. It has to be the
     * whole thing either way, because every cell is re-derived from it the
     * moment alpine boots — a screen that handed over an empty object drew an
     * empty grid over a correct one and tallied zero.
     *
     * Only the narrowed cells travel: one that is not in the map reaches every
     * row. And only the ones a screen can draw — a rule it cannot is drawn from
     * the server once and never touched again, because putting it here would
     * offer the browser something to edit that nothing would accept back.
     *
     * @return array{stances: array<string, array<string, string>>, narrowing: array<string, array<string, array{mode: string, rules: list<array<string, string>>}>>}
     */
    public function toPayload(): array
    {
        $narrowing = [];

        foreach ($this->narrowings as $row => $actions) {
            foreach ($actions as $action => $narrowed) {
                if ($narrowed->isNarrowed() && $narrowed->isEditable()) {
                    $narrowing[$row][$action] = $narrowed->toPayload();
                }
            }
        }

        return ['stances' => $this->stances, 'narrowing' => $narrowing];
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
