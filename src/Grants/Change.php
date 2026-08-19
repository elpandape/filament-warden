<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
use Illuminate\Database\Eloquent\Model;

/**
 * One cell that changed, in warden's own words.
 *
 * A cell carries two things and they are written together: the stance, and how
 * far it reaches. Warden cannot change the conditions of a grant — it can only
 * make the grant again — so there is no such thing as changing one without the
 * other.
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
        public Narrowing $narrowing,
    ) {}
}
