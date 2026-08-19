<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Policies;

use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Tag;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;

/**
 * Two actions and no more, which is what a read-only screen declares.
 */
final class TagPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Tag $tag): bool
    {
        return false;
    }
}
