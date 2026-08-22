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
 *
 * `$instantiations` counts how many times the container has built this class,
 * which is the only honest measure of how many times something reflected it:
 * `Gate::getPolicyFor()` resolves through the container fresh on every call, with
 * no cache of its own (§6.9), so a policy's own constructor is the one place a
 * suite can watch the reflection path run without inventing a counter that lives
 * in `src/` for no reason other than being watched.
 */
final class PostPolicy
{
    use HandlesAuthorization;

    public static int $instantiations = 0;

    public function __construct()
    {
        self::$instantiations++;
    }

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
