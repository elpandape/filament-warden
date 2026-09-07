<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
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
     * Make the direct edges say what the screen says, and nothing more.
     *
     * A diff and never a sync: `Warden::sync()` forces `entity: null`, and the
     * whole point of these rows is that the entity is a role. So what is gone
     * gets `retract()`ed and what is new gets `assign()`ed, one at a time,
     * through the fluent API — which is what dispatches the events with an
     * actor, reports `retractedCount()`, and is the only place `until()` exists
     * at all. `nestedRoles()->sync()` would do none of the three and would
     * capture its write scope when the relation was built.
     *
     * `null` means the screen never offered the field — nesting off, or a form
     * that does not carry it — and keeps every edge the store holds. An empty
     * ARRAY means somebody cleared it. Reading the two as one would drop every
     * inheritance in the installation on the first save from a screen that never
     * showed one, which is the same shape `narrowings` and `until` already have.
     *
     * Typed `mixed` on purpose: it arrives from a form's raw state, which is
     * whatever the browser sent.
     */
    public static function apply(Model $role, mixed $wanted): void
    {
        if (! is_array($wanted)) {
            return;
        }

        $keys = array_values(array_filter(
            $wanted,
            static fn (mixed $one): bool => is_int($one) || is_string($one),
        ));

        $held = self::edges($role);

        $context = Context::resolve();

        foreach ($context->roleClass()::query()->whereKey([...$keys, ...$held])->get() as $inner) {
            $key = $inner->getKey();

            if (! is_int($key) && ! is_string($key)) {
                continue; // @codeCoverageIgnore
            }

            $shouldHold = in_array($key, $keys, true);
            $doesHold = in_array($key, $held, true);

            if ($shouldHold && ! $doesHold) {
                Warden::assign($inner)->to($role);
            }

            if ($doesHold && ! $shouldHold) {
                Warden::retract($inner)->from($role);
            }
        }
    }

    /**
     * The roles a screen must NOT offer this one as an inheritance.
     *
     * Two exclusions, and they are different questions rather than one written
     * twice. Itself: a role inheriting itself is a cycle with one link, and
     * warden caps rather than throws — so the store would take the row and
     * answer nothing, which is worse than a refusal. And everything that already
     * reaches it: offering one of those builds a cycle that stops expanding at
     * `warden.roles.max_depth`, leaving the two roles quietly holding less than
     * they look like they hold.
     *
     * `reaching()` answers the second and excludes the role itself, which is why
     * the first is written out here rather than assumed to come with it.
     *
     * @return list<int|string>
     */
    public static function barredFor(Model $role): array
    {
        $key = $role->getKey();

        return is_int($key) || is_string($key)
            ? [$key, ...self::reaching($role)]
            : [];
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
