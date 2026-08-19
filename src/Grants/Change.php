<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
use Illuminate\Database\Eloquent\Model;

/**
 * One cell that changed, in warden's own words.
 *
 * The wildcard needs no special case: `toManage($entity)` is literally
 * `to('*', $entity)`, so a manage cell is a change whose name is `*`.
 */
final readonly class Change
{
    /** @param  class-string<Model>|null  $entity */
    public function __construct(
        public string $name,
        public ?string $entity,
        public Stance $to,
    ) {}
}
