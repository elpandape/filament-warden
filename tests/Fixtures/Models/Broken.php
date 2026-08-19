<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Models;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A model whose schema cannot be read.
 *
 * A database driver with no `compileColumns` raises rather than degrading —
 * `RuntimeException('This database driver does not support retrieving
 * columns.')` — and so does a connection that will not resolve. Standing in for
 * one here is shorter than registering a driver, and it fails in the same place.
 */
final class Broken extends Model
{
    protected $guarded = [];

    public function getConnection(): Connection
    {
        throw new RuntimeException('This database driver does not support retrieving columns.');
    }
}
