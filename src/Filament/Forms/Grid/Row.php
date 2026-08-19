<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms\Grid;

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
        return array_values(array_map(
            static fn (Cell $cell): string => $cell->action,
            array_filter($this->cells, static fn (Cell $cell): bool => $cell->isEditable()),
        ));
    }
}
