<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms\Grid;

use ElPandaPe\FilamentWarden\Catalog\Scope;

/**
 * The columns of one scope, which is what lets "list records" stop looking like
 * "delete for good" on screen.
 */
final readonly class ColumnGroup
{
    /** @param  list<Column>  $columns */
    public function __construct(
        public Scope $scope,
        public string $label,
        public array $columns,
    ) {}
}
