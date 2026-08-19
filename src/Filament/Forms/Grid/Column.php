<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms\Grid;

use ElPandaPe\FilamentWarden\Catalog\Scope;

final readonly class Column
{
    public function __construct(
        public string $action,
        public string $label,
        public Scope $scope,
    ) {}
}
