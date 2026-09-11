<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms\Grid;

use ElPandaPe\FilamentWarden\Catalog\Scope;
use Illuminate\Database\Eloquent\Model;

/**
 * One entity of the grid, or one door.
 *
 * An entity row carries the wildcard cell first — warden's `*` over the whole
 * class — and then one cell per column, declared or not. A door row carries a
 * single cell and no wildcard: a page is not a model.
 */
final readonly class Row
{
    /**
     * @param  class-string<Model>|null  $model
     * @param  list<Cell>  $cells
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $model,
        public array $cells,
        public ?Cell $manage = null,
    ) {}

    /**
     * @return list<Cell>
     */
    public function allCells(): array
    {
        return $this->manage instanceof Cell ? [$this->manage, ...$this->cells] : $this->cells;
    }

    /**
     * What this row's cells ANSWER, for the reading that folds them away.
     *
     * The same count `Tab::granted()` makes, over one entity instead of a tab
     * and with one stance more: a tab counts granted, this counts granted and
     * forbidden, because a collapsed entity hides a prohibition just as well
     * as it hides a grant. The browser re-derives it in `answered()` — a click
     * has to redraw the number without asking the server — and both are pinned
     * against the same sentence, through `GridView::summaryOf()` and the
     * script's `stackSummary()`.
     *
     * @return array{granted: int, forbidden: int, total: int}
     */
    public function answered(): array
    {
        $granted = 0;
        $forbidden = 0;
        $total = 0;

        foreach ($this->allCells() as $cell) {
            if (! $cell->declared) {
                continue;
            }

            $total++;

            match ($cell->answers()) {
                Stance::Granted => $granted++,
                Stance::Forbidden => $forbidden++,
                default => null,
            };
        }

        return ['granted' => $granted, 'forbidden' => $forbidden, 'total' => $total];
    }

    /**
     * The actions the decision buttons and the `all` and `clear` presets may
     * write on this row: the script's `decisionEnabled()` and `apply()` read
     * this list. A tangled cell is not in it, though a click still cycles one,
     * and the save writes only its clearing.
     *
     * @return list<string>
     */
    public function editableActions(): array
    {
        return $this->actionsOf(array_filter($this->cells, static fn (Cell $cell): bool => $cell->isEditable()));
    }

    /**
     * The row's cells that belong to one scope, declared or not.
     *
     * The wildcard cell is built with no scope at all, so a `=== $scope` filter
     * leaves it outside every group — which is where the folded reading wants
     * it: above them, not inside one.
     *
     * @return list<Cell>
     */
    public function inScope(Scope $scope): array
    {
        return array_values(array_filter(
            $this->cells,
            static fn (Cell $cell): bool => $cell->scope === $scope,
        ));
    }

    /**
     * The reading half of the row, so the shortcut does not have to carry a copy
     * of the scope map into the browser.
     *
     * @return list<string>
     */
    public function readActions(): array
    {
        return $this->actionsOf(array_filter(
            $this->cells,
            static fn (Cell $cell): bool => $cell->isEditable() && $cell->scope === Scope::Read,
        ));
    }

    /**
     * Every cell the browser draws on this row, with the permission name each one
     * stands for — which is what a rule written over every entity is keyed by.
     *
     * The tally counts over this and not over what was written — see
     * `Cell::answers()`.
     *
     * @return list<array{action: string, name: string|null}>
     */
    public function drawnCells(): array
    {
        $cells = [];

        foreach ($this->allCells() as $cell) {
            if ($cell->declared) {
                $cells[] = ['action' => $cell->action, 'name' => $cell->entry?->name];
            }
        }

        return $cells;
    }

    /**
     * @param  array<int, Cell>  $cells
     * @return list<string>
     */
    private function actionsOf(array $cells): array
    {
        return array_values(array_map(static fn (Cell $cell): string => $cell->action, $cells));
    }
}
