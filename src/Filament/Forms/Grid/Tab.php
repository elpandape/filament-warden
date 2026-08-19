<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms\Grid;

/**
 * One group of the grid, with the tally that lights up the moment it grants
 * anything: one save writes every tab, and a role reaching a dangerous page
 * would otherwise read as harmless from whichever tab happens to be open.
 */
final readonly class Tab
{
    /** @param  list<Row>  $rows */
    public function __construct(
        public string $key,
        public string $label,
        public array $rows,
        public bool $matrix,
    ) {}

    public function granted(): int
    {
        $granted = 0;

        foreach ($this->rows as $row) {
            foreach ($row->allCells() as $cell) {
                if ($cell->isGranted()) {
                    $granted++;
                }
            }
        }

        return $granted;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
