<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Support\Expiry;
use ElPandaPe\Warden\Support\RoleClosure;
use Illuminate\Database\Eloquent\Model;

/**
 * Which roles a role reaches, and which reach it.
 *
 * The walk is warden's — `RoleClosure::for()` and `::reaching()` — and it is
 * called, never copied: it honours `warden.roles.nested`, stops at
 * `warden.roles.max_depth` instead of throwing on a cycle, and filters expiry
 * on every edge. A second implementation here would be a second answer to the
 * question the engine already decides checks with.
 *
 * The direct edges are read separately rather than picked out of the closure,
 * because the closure cannot tell them apart: `for()` returns every reachable
 * role under one key space, and a role reached at two hops carries no marker
 * saying so. One query buys the distinction, and the distinction is what the
 * screens draw — "inherits from" is what somebody chose, the rest is what that
 * choice brought along.
 *
 * `Role::nestedRoles()` is deliberately not used for either. It is a plain
 * `belongsToMany` with a pivot condition and NO expiry filter, so it lists an
 * edge the engine has already stopped reading — and writing through it would
 * lose the events, the actor and `until()` (§6.18, and the watchfulness rule
 * pinned in `PackageTest`).
 */
final readonly class Hierarchy
{
    /**
     * @param  list<int|string>  $direct  roles this one was given
     * @param  list<int|string>  $inherited  roles those brought along
     */
    public function __construct(
        public array $direct = [],
        public array $inherited = [],
    ) {}

    public static function of(Model $role): self
    {
        $direct = self::edges($role);

        $reachable = array_values(array_filter(
            array_keys(RoleClosure::for($role)),
            static fn (int|string $key): bool => ! in_array($key, $direct, true),
        ));

        return new self($direct, $reachable);
    }

    /**
     * Every role that reaches this one, itself excluded.
     *
     * Warden's own `reaching()` includes the targets, because the readers that
     * ask it are filtering authorities and want the whole set. A screen saying
     * "inherited by" wants the opposite, so the role itself comes back out.
     *
     * @return list<int|string>
     */
    public static function reaching(Model $role): array
    {
        $key = $role->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return [];
        }

        return array_values(array_filter(
            RoleClosure::reaching([$key]),
            static fn (int|string $found): bool => (string) $found !== (string) $key,
        ));
    }

    /**
     * Every role whose grants answer for this one, nearest first.
     *
     * @return list<int|string>
     */
    public function all(): array
    {
        return [...$this->direct, ...$this->inherited];
    }

    /**
     * The roles assigned straight to this one, live edges only.
     *
     * @return list<int|string>
     */
    private static function edges(Model $role): array
    {
        $keys = Context::resolve()->assignedRoleClass()::query()
            ->where('entity_type', $role->getMorphClass())
            ->where('entity_id', $role->getKey())
            ->tap(Expiry::live(...))
            ->get(['role_id']);

        $direct = [];

        foreach ($keys as $row) {
            $key = $row->getAttribute('role_id');

            if ((is_int($key) || is_string($key)) && ! in_array($key, $direct, true)) {
                $direct[] = $key;
            }
        }

        return $direct;
    }
}
