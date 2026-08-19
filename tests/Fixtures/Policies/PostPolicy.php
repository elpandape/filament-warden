<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Policies;

use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * The three things a catalogue must not mistake for actions are here on purpose:
 * the gate hook, the public helpers the trait leaves behind, and a static of the
 * policy's own.
 */
final class PostPolicy
{
    use HandlesAuthorization;

    public static function label(): string
    {
        return 'posts';
    }

    public function before(User $user, string $ability): null
    {
        return null;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Post $post): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Post $post): bool
    {
        return false;
    }

    public function delete(User $user, Post $post): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
