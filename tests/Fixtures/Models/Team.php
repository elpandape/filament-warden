<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A tenant, in Filament's sense of the word — which is not warden's.
 *
 * Filament acots a panel by putting a global scope on every resource's MODEL,
 * and that scope demands a relationship named after this class. Warden's own
 * models have no such relationship and never will.
 */
final class Team extends Model
{
    protected $guarded = [];
}
