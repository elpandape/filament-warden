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
     * The actions the browser is allowed to cycle on this row, which is also
     * what a granted wildcard reaches.
     *
     * @return list<string>
     */
    public function editableActions(): array
    {
        return $this->actionsOf(array_filter($this->cells, static fn (Cell $cell): bool => $cell->isEditable()));
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
     * The tally counts over this and not over what was written: a cell nobody
     * wrote still answers when a wildcard reaches it.
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
