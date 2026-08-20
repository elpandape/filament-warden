<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Models;

use ElPandaPe\Warden\Concerns\QueriesByPermission;
use Illuminate\Database\Eloquent\Model;

/**
 * The model a consuming application opts in with: one line, and warden can answer
 * "over which rows" about it.
 *
 * Its sibling `Post` deliberately does not compose it, which is what makes the
 * missing-trait case testable — and that case is silent, not loud.
 */
final class Document extends Model
{
    use QueriesByPermission;

    protected $guarded = [];
}
