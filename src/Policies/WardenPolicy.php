<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Policies;

use BackedEnum;
use ElPandaPe\Warden\Contracts\Resolver;
use ElPandaPe\Warden\Support\Name;
use Illuminate\Database\Eloquent\Model;

/**
 * What a policy in a warden application is built on.
 *
 * It brings no abilities of its own, and that is the point: the methods a policy
 * declares are exactly the actions that exist for its model. A base serving
 * twelve as a matter of course would put restore and force delete in front of an
 * administrator for a model that has neither.
 */
abstract class WardenPolicy
{
    public function __construct(private readonly Resolver $resolver) {}

    /**
     * Asks the store, not the gate.
     *
     * Going through the gate would resolve this very policy and ask it the same
     * question, and the question would never end. `Warden::can()`, `cannot()`,
     * `canAny()` and `authorize()` all go through the gate: none of them can be
     * called from here.
     *
     * An abstained verdict answers false, like a forbidden one: from the gate's
     * side there is nothing to tell apart, and failing closed is the answer.
     */
    protected function allows(
        Model $authority,
        string|BackedEnum $permission,
        Model|string|null $entity = null,
    ): bool {
        return $this->resolver->resolve($authority, Name::of($permission), $entity)->isGranted();
    }
}
