<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * An owner whose relationship refuses to be resolved.
 *
 * Standing in for the three measured failure modes at once — an abstract class, a
 * `booted()` that throws, a relation that reads request state and dies in console
 * — so that a catalogue walk that tried would be caught by the suite rather than
 * by somebody's production console.
 */
final class Ledger extends Model
{
    protected $guarded = [];

    public function explosive(): never
    {
        throw new RuntimeException('The catalogue resolved a relationship it promised not to touch.');
    }
}
