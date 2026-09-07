<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use Carbon\CarbonImmutable;
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
 *
 * `records` is the opposite mistake waiting to happen — a rule narrower than a
 * cell, pinned to one row. It answers no class check, so it is neither a cell
 * nor a wider rule: it is reported, and nothing here offers to change it.
 *
 * `inherited` is a cell this role answers through another: nesting puts an
 * inner role's grants behind the outer one's, so a cell nobody wrote here can
 * still say yes. It carries the LENDER's name, because a hollow tick with no
 * link is the wildcard mistake of §6.11 wearing another hat — the most
 * dangerous role in an installation reading as a role that holds nothing. Only
 * cells with no rule of their own are in it: a rule of its own is what is in
 * force, and an inherited answer underneath it is noise.
 *
 * `untils` is when a cell stops. It is per GRANT, never per catalogue row: two
 * roles pointing at the same permission can end on different days, which is the
 * whole reason the date lives on the pivot. A cell whose stance is a written one
 * ends then; a cell that abstains and still carries a date ended then, and the
 * difference between those two is the only thing that tells an access that
 * lapsed from one nobody ever wrote.
 */
final readonly class RoleState
{
    /**
     * @param  array<string, array<string, string>>  $stances
     * @param  array<string, array<string, Narrowing>>  $narrowings
     * @param  array<string, string>  $wider  rules over every entity, keyed by permission name
     * @param  list<RecordGrant>  $records  rules pinned to one row, which own no cell and cannot be written from here
     * @param  array<string, array<string, CarbonImmutable>>  $untils  when a cell's grant stops, by row and action
     * @param  array<string, array<string, array{role: string, stance: string}>>  $inherited  cells an inner role answers, and which
     */
    public function __construct(
        public array $stances = [],
        public array $narrowings = [],
        public array $wider = [],
        public array $records = [],
        public array $untils = [],
        public array $inherited = [],
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
     * `until` travels as ISO 8601 rather than as an object, because the browser
     * only ever prints it: whether a date has passed is already answered by the
     * stance beside it, decided against the server's clock. Letting the browser
     * compare would put the answer in the one place whose clock nobody controls.
     *
     * @return array{stances: array<string, array<string, string>>, narrowing: array<string, array<string, array{mode: string, rules: list<array<string, string>>}>>, until: array<string, array<string, string>>, inherited: array<string, array<string, array{role: string, stance: string}>>}
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

        $until = [];

        foreach ($this->untils as $row => $actions) {
            foreach ($actions as $action => $ends) {
                $until[$row][$action] = $ends->toIso8601String();
            }
        }

        return [
            'stances' => $this->stances,
            'narrowing' => $narrowing,
            'until' => $until,
            'inherited' => $this->inherited,
        ];
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
