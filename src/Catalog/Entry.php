<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Catalog;

use Illuminate\Database\Eloquent\Model;

final readonly class Entry
{
    /**
     * @param  class-string<Model>|null  $model
     * @param  class-string|null  $source
     */
    public function __construct(
        public string $name,
        public ?string $entityType,
        public ?string $model,
        public Scope $scope,
        public Origin $origin,
        public ?string $source = null,
    ) {}

    /**
     * Two entries are the same permission when they name the same action over the
     * same entity, whichever screen each of them was derived from.
     */
    public function key(): string
    {
        return $this->name.'|'.($this->entityType ?? '');
    }
}
