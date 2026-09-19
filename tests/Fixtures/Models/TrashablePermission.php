<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Models;

use ElPandaPe\Warden\Models\Permission;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A permission model an application swapped in with a trash.
 *
 * Warden refuses to mint a namesake of a row in the trash and throws the refusal
 * as a `ConfigurationException`, the same class as refusals a save may shrug
 * off. This package offers neither restore nor force delete, so what the fixture
 * is for is proving that the refusal reaches the person saving.
 *
 * Warden's schema has no `deleted_at`: the test that swaps this in adds it.
 */
final class TrashablePermission extends Permission
{
    use SoftDeletes;
}
