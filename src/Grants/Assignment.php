<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use ElPandaPe\Warden\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Handing a role to an account, and taking it back.
 *
 * Written through warden's fluent API and never through the `roles()` relation.
 * `attach()`, `detach()` and `sync()` all skip the cache bump — only warden's own
 * action classes make it — so a role handed out through the relation goes on
 * answering the old way, silently and with no expiry. Measured: after an
 * `attach()`, a permission granted through the attached role still resolves to
 * false, even from a fresh resolver.
 *
 * `detach()` is worse than that: it ignores tenancy and restrictions, and removes
 * every assignment row for (authority, role) whatever its scope or context.
 */
final class Assignment
{
    /**
     * The whole catalogue, read once per request.
     *
     * Nothing in this class ever creates or deletes a role — only `RoleResource`
     * does, on a screen entirely separate from this one — so within a single
     * request the answer cannot change out from under a caller, unlike
     * `assignments()` below, which this class DOES write to (`give()`,
     * `take()`), and which is deliberately left unmemoised: a memo there would
     * hand a stale row list to a check made right after a write in the same
     * request. CORRECTED citation: not `AssignmentTest.php`'s own "give()
     * hands a role out…" test — that one reads back through `Access::granted()`
     * (warden's Resolver, unrelated to this class) and a raw `assignmentCount()`,
     * neither of which this memo could make stale. The one that actually
     * exercises the risk is `RolesRelationManagerTest.php`'s "assigning
     * through the header action writes one row the store honours at once",
     * which calls `Assignment::of($account)` immediately after a write that
     * went through `give()` in the same request.
     *
     * `null` and not `[]` as the empty sentinel: an installation can genuinely
     * have zero roles, and the memo has to tell that apart from "not read yet".
     *
     * @var array<int|string, Model>|null
     */
    private static ?array $rolesByKey = null;

    /**
     * Every role there is, by key, named the way a person would recognise it.
     *
     * Not stripped of the tenant scope, exactly like the roles screen: which
     * roles exist is one decision, and it is taken in one place.
     *
     * @return array<int|string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::byKey() as $key => $role) {
            $options[$key] = Holders::label($role);
        }

        return $options;
    }

    /**
     * Why a role cannot be handed out from here, when it cannot.
     *
     * A checkbox that greys out and explains nothing is a checkbox somebody
     * works around.
     *
     * @return array<int|string, string>
     */
    public static function descriptions(?Model $account): array
    {
        if (! $account instanceof Model) {
            return [];
        }

        $descriptions = [];

        foreach (self::byKey() as $key => $role) {
            $reason = match (true) {
                ! self::mayHandOut($role) => 'protected',
                self::isRestricted($account, $key) => 'restricted',
                self::isElsewhere($account, $key) => 'elsewhere',
                default => null,
            };

            if ($reason !== null) {
                $descriptions[$key] = (string) __('filament-warden::ui.relations.roles.'.$reason);
            }
        }

        return $descriptions;
    }

    /**
     * The roles this account holds, a restricted assignment counting as held.
     *
     * Read off `assigned_roles` rather than through `roles()`: the relation welds
     * a tenant predicate no scope removal can strip, and returns a role once per
     * assignment — so a role held both restricted and unrestricted arrives twice,
     * with the same key.
     *
     * @return list<int|string>
     */
    public static function of(Model $account): array
    {
        $held = [];

        foreach (self::assignments($account) as $assignment) {
            $key = $assignment->getAttribute('role_id');

            if ((is_int($key) || is_string($key)) && ! in_array($key, $held, true)) {
                $held[] = $key;
            }
        }

        return $held;
    }

    /**
     * Whether the signed-in account may hand this role out — which is whether it
     * may edit it.
     *
     * Property 3 of this package says whoever may edit roles hands out
     * everything, and this is that sentence as a check. It needs no permission of
     * its own: `update` over a role is already in the catalogue.
     */
    public static function mayHandOut(Model $role): bool
    {
        return Access::grantedToCurrentUser('update', $role);
    }

    /**
     * Whether this screen may hand that role out at all: a real account, a key
     * that names a role, a role this account may edit, and no assignment of it
     * narrowed to a context.
     */
    public static function offers(?Model $account, mixed $value): bool
    {
        if (! $account instanceof Model || (! is_int($value) && ! is_string($value))) {
            return false;
        }

        $role = self::role($value);

        return $role instanceof Model
            && self::mayHandOut($role)
            && ! self::isRestricted($account, $value)
            && ! self::isElsewhere($account, $value);
    }

    /**
     * An assignment narrowed to a context.
     *
     * Shown, marked, and left alone: `retract()` without `on()` takes every
     * restricted row of the scope with it, which is not what a checkbox says.
     */
    public static function isRestricted(Model $account, int|string $role): bool
    {
        foreach (self::assignments($account) as $assignment) {
            if ($assignment->getAttribute('role_id') === $role
                && $assignment->getAttribute('restricted_to_type') !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * An assignment written at a scope this screen cannot delete.
     *
     * `retract()->from()` filters on `Tenancy::writeScope()` exactly and only
     * bumps the cache when it deleted something: unticking a global assignment
     * from inside a tenant removes nothing, reports success, and comes back
     * ticked on reload. Measured. So it is shown, marked and left alone — the
     * same answer the grid gives a grant from another tenant.
     *
     * A role held both globally and here answers true as well, and that is
     * right: retracting would leave the global row behind and the box would come
     * back ticked anyway.
     */
    public static function isElsewhere(Model $account, int|string $role): bool
    {
        $scope = app(Tenancy::class)->writeScope();

        foreach (self::assignments($account) as $assignment) {
            if ($assignment->getAttribute('role_id') === $role
                && ! self::sameScope($assignment->getAttribute('scope'), $scope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The difference between what the screen says and what the store has.
     *
     * A role this account may not hand out is skipped here and not only in the
     * markup: a disabled option reaches the state exactly like a disabled field
     * does, so the guarantee cannot rest on how the checkbox was drawn.
     *
     * The state arrives as whatever livewire hands over.
     */
    public static function apply(Model $account, mixed $wanted): void
    {
        $wanted = is_array($wanted) ? array_values($wanted) : [];
        $held = self::of($account);

        // Opened on warden's own connection, not the default one: every write
        // this loop makes goes through `Context::resolve()` already, and a
        // transaction on the wrong connection wraps queries that never run on
        // it while the ones that matter commit one at a time as they go.
        DB::connection(Context::resolve()->connection())->transaction(static function () use ($account, $wanted, $held): void {
            foreach (self::byKey() as $key => $role) {
                if (! self::mayHandOut($role)
                    || self::isRestricted($account, $key)
                    || self::isElsewhere($account, $key)) {
                    continue;
                }

                $isHeld = in_array($key, $held, true);
                $isWanted = self::wants($wanted, $key);

                if ($isWanted && ! $isHeld) {
                    Warden::assign($role)->to($account);
                }

                if ($isHeld && ! $isWanted) {
                    Warden::retract($role)->from($account);
                }
            }
        });
    }

    /**
     * Hands one role to an account — the entry point a row or header action
     * reaches for, never `apply()`.
     *
     * `apply()` is a set diff over the WHOLE catalogue: `byKey()` plus
     * `mayHandOut()`/`isRestricted()`/`isElsewhere()` per role, none memoised.
     * That is the right shape for `RoleAssignment`'s `CheckboxList`, which hands
     * over the entire wanted state and has no way to say what changed. A row
     * action already knows exactly which role it touched, so paying for every
     * other role in the catalogue on every click would defeat the reason this
     * screen exists — the 200-role installation a `CheckboxList` cannot serve.
     * `offers()` is checked once, and only a role already offered but not yet
     * held is written: the header action's `Select` lists the whole catalogue
     * unfiltered, and a role already held is still "offered" by that check.
     * Writing again would NOT insert a duplicate row — `AssignsRoles::to()`
     * writes through `firstOrCreate()`, which finds the existing one — but it
     * WOULD still call `bumpCacheVersion()` unconditionally, invalidating
     * every cached check at that scope for nothing changed. `isHeld()` is what
     * stops that, and its own docblock has the measurement.
     *
     * This `offers()` check is the sole server-side authorization for WHICH
     * role gets handed out through this class — CORRECTED, an earlier version
     * of this paragraph said the header action has no `->visible()` at all,
     * which stopped being true once it gained one gating `isReadOnly()`
     * (`RolesRelationManager.php`). That `->visible()` decides only whether
     * the button exists on the page at all; it says nothing about which role
     * was submitted, on any page. The `Select`'s `disableOptionWhen()` is UX
     * for that question, not a guard, so removing this check on the grounds
     * that "the screen already checks" would open the write to anyone who can
     * reach the action, whatever value they submit.
     *
     * Returns whether it actually wrote something: `offers()` alone does not
     * exclude a role already held, so a caller that reports success on the
     * strength of "no exception was thrown" would report success for a no-op.
     */
    public static function give(Model $account, int|string $role): bool
    {
        if (! self::offers($account, $role) || self::isHeld($account, $role)) {
            return false;
        }

        $model = self::role($role);

        if ($model instanceof Model) {
            Warden::assign($model)->to($account);
        }

        // Never actually false here: `offers()` already resolved this same
        // `$role` through `role()` and answered true, so `$model` cannot be
        // null on this path. Written as a plain boolean rather than a second
        // early return so there is no line only the impossible branch reaches
        // — an explicit `if (! $model instanceof Model) { return false; }`
        // measured uncoverable, and this project runs the coverage gate at
        // 100% with no baseline.
        return $model instanceof Model;
    }

    /**
     * Takes one role back from an account — the entry point a row action
     * reaches for, never `apply()`. See `give()` for the cost this avoids.
     *
     * A guard against "not held at all" is added here, for a different reason
     * than `give()`'s "already held" one: `offers()` does not distinguish
     * "held, unrestricted, this scope" from "not held at all" —
     * `isRestricted()`/`isElsewhere()` both loop `assignments()` looking for a
     * row that matches this role, and both answer `false` when no row matches
     * at all, same as when one matches and clears. Without this, a caller
     * that already checked `offers()` and got `true` could still call
     * `retract()->from()` on nothing and have this method report success
     * (`true`) for a write that never happened — `retract()->from()` itself
     * deletes nothing silently, and warden bumps no cache version either
     * (unlike `to()`, it only bumps when a row was actually removed, so past
     * this guard there is nothing left to waste). `isHeld()` is reused here
     * inverted, and its own docblock explains why the check is cheap: it is
     * `Assignment::of()`, already computed to answer `offers()`'s own
     * `isRestricted()`/`isElsewhere()` a line above.
     *
     * NOT reachable through `RolesRelationManager`'s row action, though — see
     * `retractAction()`'s docblock for the measurement. Kept for `take()`'s
     * own correctness as a public method other callers reach for, and pinned
     * directly here, bypassing Livewire, in `AssignmentTest.php`'s "take()
     * writes nothing for a role not held".
     *
     * This `offers()` check is the sole server-side authorization for taking a
     * role back through this class: `RolesRelationManager`'s row action
     * carries no repeated check of its own — measured unreachable, see
     * `retractAction()`'s docblock — so this is where the guarantee actually
     * lives, and it must not be removed on the grounds that a screen's
     * `->visible()` already checked.
     *
     * Returns whether it actually wrote something, for the same reason
     * `give()` does: a caller that reports success on "no exception was
     * thrown" would report success for a no-op.
     */
    public static function take(Model $account, int|string $role): bool
    {
        if (! self::offers($account, $role) || ! self::isHeld($account, $role)) {
            return false;
        }

        $model = self::role($role);

        if ($model instanceof Model) {
            Warden::retract($model)->from($account);
        }

        // Never actually false here, the same reason `give()`'s tail comment
        // gives: `offers()` and `isHeld()` already resolved this same `$role`
        // through `role()` twice over, so `$model` cannot be null on this
        // path — and an explicit early return for that case would be a line
        // only an impossible branch reaches, uncoverable under this project's
        // 100% line gate.
        return $model instanceof Model;
    }

    /**
     * One role by key, or nothing when the key names none.
     */
    public static function role(int|string $key): ?Model
    {
        foreach (self::byKey() as $candidate => $role) {
            if ((string) $candidate === (string) $key) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Forgets the memoised catalogue. A suite raises a different one for every
     * test case and would otherwise read the one before — the same reason
     * `Conditions\Columns::forget()` exists, called from the same `setUp()`.
     */
    public static function forget(): void
    {
        self::$rolesByKey = null;
    }

    /**
     * Whether the account already holds this role, compared as text: a key
     * arriving from a `Select` is a string even where the column is not.
     *
     * Two callers, opposite polarity. In `give()`, NOT because a second
     * `assign()->to()` would insert a duplicate row — it would not. Warden's
     * own `AssignsRoles::to()` writes through `firstOrCreate()`, and
     * `Query\Builder::where()` redirects a `null` value to `whereNull()`, so a
     * search array carrying `restricted_to_type: null` FINDS the existing
     * unrestricted row rather than missing it — the same Laravel behaviour
     * AGENTS.md §6.24 already corrected once, in the opposite direction. What
     * `to()` does unconditionally, found row or new one, is
     * `bumpCacheVersion($scope)` — so a `give()` on an already-held role would
     * still invalidate every cached check at that scope for nothing changed.
     * That is the real saving this guard buys there, pinned in
     * `AssignmentTest.php`'s "give() does not bump the cache version for a
     * role already held".
     *
     * In `take()`, inverted (`! isHeld()`): `offers()` cannot tell "held,
     * unrestricted, this scope" apart from "not held at all", so without this
     * a caller that had already checked `offers()` could still get a `true`
     * report for a `retract()->from()` that matched and deleted nothing. NOT
     * reachable through `RolesRelationManager`'s row action — measured, see
     * `retractAction()`'s docblock — because that screen's own record
     * resolution already requires the role to be held before the closure can
     * run at all. Kept for `take()`'s own correctness as a public method, and
     * pinned directly against it, bypassing Livewire, in `AssignmentTest.php`'s
     * "take() writes nothing for a role not held".
     */
    private static function isHeld(Model $account, int|string $role): bool
    {
        return array_any(self::of($account), fn (int|string $key): bool => (string) $key === (string) $role);
    }

    /**
     * Compared as text on purpose, mirroring `RoleGrants::writable()`: warden
     * types a tenant `int|string` while `assigned_roles.scope` is an uncast
     * integer column, so a resolver handing back `'5'` still has to match a
     * row stamped `5`.
     */
    private static function sameScope(mixed $rowScope, int|string|null $writeScope): bool
    {
        $rowScope = is_int($rowScope) || is_string($rowScope) ? $rowScope : null;

        return $rowScope === null && $writeScope === null
            ? true
            : (string) $rowScope === (string) $writeScope;
    }

    /**
     * Every role, by a key that reads as one. A key that does not could match no
     * row anyway, which is the safe way to lose it.
     *
     * Memoised: `options()` reads it once and a `Select`'s `disableOptionWhen()`
     * reads it once per option with no memo of its own
     * (`CanDisableOptions::isOptionDisabled()`), so an unmemoised `byKey()` paid
     * a full `roles` table query per option in the assign modal — for the
     * 200-role installation this screen exists to serve, hundreds of identical
     * queries to open one modal, the exact shape of cost the `give()`/`take()`
     * ruling existed to avoid. Measured before this memo: 202 `roles` reads
     * mounting that modal against 200 roles. After: 2, and neither of those two
     * scales with the catalogue — one is `options()`'s own first read, the
     * other the table's separate pagination count query.
     *
     * @return array<int|string, Model>
     */
    private static function byKey(): array
    {
        if (self::$rolesByKey !== null) {
            return self::$rolesByKey;
        }

        $roles = [];

        foreach (self::roles() as $role) {
            $key = $role->getKey();

            if (is_int($key) || is_string($key)) {
                $roles[$key] = $role;
            }
        }

        return self::$rolesByKey = $roles;
    }

    /**
     * A key arrives from the browser as text even when the column is an integer.
     *
     * @param  array<int, mixed>  $wanted
     */
    private static function wants(array $wanted, int|string $key): bool
    {
        return array_any($wanted, fn (mixed $candidate): bool => (is_int($candidate) || is_string($candidate)) && (string) $candidate === (string) $key);
    }

    /**
     * @return Collection<int, Model>
     */
    private static function roles(): Collection
    {
        /** @var Collection<int, Model> $roles */
        $roles = Context::resolve()->roleClass()::query()->orderBy('name')->get();

        return $roles;
    }

    /**
     * @return Collection<int, Model>
     */
    private static function assignments(Model $account): Collection
    {
        /** @var Collection<int, Model> $assignments */
        $assignments = Context::resolve()->assignedRoleClass()::query()
            ->where('entity_type', $account->getMorphClass())
            ->where('entity_id', $account->getKey())
            ->get();

        return $assignments;
    }
}
