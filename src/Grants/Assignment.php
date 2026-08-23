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
use WeakMap;

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
     * request the answer cannot change out from under a caller.
     *
     * `null` and not `[]` as the empty sentinel: an installation can genuinely
     * have zero roles, and the memo has to tell that apart from "not read yet".
     *
     * @var array<int|string, Model>|null
     */
    private static ?array $rolesByKey = null;

    /**
     * One account's rows off `assigned_roles`, memoised — added in v1.5.0
     * ("Que no cueste"), where `byKey()` above was memoised a version earlier
     * (v1.4.0). Before this, `disableOptionWhen()` re-ran this query once per
     * option with no memo of its own, and `offers()` reads it twice
     * (`isRestricted()`, `isElsewhere()`): measured opening the assign modal
     * against 200 roles, 405 `assigned_roles` statements. After: 3, and the
     * same 3 against a 20-role catalogue, so what is left does not scale with
     * the option count. Two of those are this memo reading once for each of
     * the two simulated requests that opening a modal takes — the component
     * is constructed, then the action is mounted, and Livewire rehydrates a
     * fresh `ownerRecord` in between. The third is warden resolving whether
     * the SIGNED-IN account may `viewAny` on the role resource at all, which
     * this class never writes to and never needs to invalidate.
     *
     * A `WeakMap` on the `$account` instance, which is `Holders`' own
     * pattern and is chosen here for the same reason: an entry dies with the
     * object it was built for, so nothing has to guess when a request ended.
     * A long-lived worker holds none of these between requests, and a read
     * reached through a different instance re-queries rather than answering
     * from a snapshot somebody else's request took — which is the second of
     * those three statements, and is the honest price of not having a
     * request boundary to hook.
     *
     * What the map cannot do for itself is notice a write. That is
     * `forgetAssignments()`, called by all three writers this class has
     * (`give()`, `take()`, `apply()`) right after a write commits.
     *
     * The inner key is the tenant, because `assignments()` reads THROUGH
     * warden's `TenantScope`: the same account genuinely answers differently
     * depending on which tenant is active when it is asked, and one entry
     * per account would freeze whichever context asked first. Measured with
     * a role assigned under tenant 7 and no write in between — outside any
     * tenant `of()` answers with it, inside `onceTo(8)` it answers empty —
     * and pinned in `AssignmentTest.php`'s "the memo answers per tenant, not
     * once for the account". The key comes off `Tenancy::readFilter()`,
     * which is the whole of what that scope reads.
     *
     * The risk this was built to respect: memoising `assignments()` hands a
     * check made right after a write the row list from BEFORE that write,
     * unless every writer clears it. `apply()` is the third writer and the
     * least obvious one — it reads `of()` once before its own transaction,
     * and would otherwise leave a caller who reads again right after it
     * returns holding that same stale answer (pinned in `AssignmentTest.php`'s
     * "a key that arrives as text still names the same role", which was
     * already in that file before this memo existed).
     *
     * The test that does NOT cover this, and reads as though it does:
     * `AssignmentTest.php`'s "give() hands a role out and the store answers
     * for it straight away" reads back through `Access::granted()` — warden's
     * own resolver — and a raw row count, neither of which this memo can
     * make stale. The one written for it is "give()/take() invalidate the
     * assignments memo, a read right after a write sees it", which reads,
     * writes and reads again against the very same `$account` object.
     *
     * @var WeakMap<Model, array<string, Collection<int, Model>>>
     */
    private static WeakMap $assignmentsByAccount;

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
     * The state arrives as whatever livewire hands over, and so does the
     * baseline: neither is trusted to be an array.
     *
     * A forged baseline cannot escalate. It decides only WHETHER a change is
     * written, never what — every assignment still runs the same
     * `mayHandOut()`/`isRestricted()`/`isElsewhere()` guards above it — so the
     * worst a doctored one does is suppress the forger's own save.
     */
    public static function apply(Model $account, mixed $wanted, mixed $baseline = null): SaveReport
    {
        $wanted = is_array($wanted) ? array_values($wanted) : [];
        $held = self::of($account);

        // What the screen was showing when it opened, when it stamped one. The
        // same three-way comparison the grid makes, in the shape a set of role
        // keys takes: a checkbox nobody here touched is left as whoever did
        // touch it left it, instead of being unticked back.
        //
        // Null means no screen — a console script or a test asserting a state
        // outright — so every role counts as touched, which is what this method
        // did before there was a baseline.
        $was = is_array($baseline) ? array_values($baseline) : null;

        $written = 0;
        $preserved = 0;

        // Opened on warden's own connection, not the default one: every write
        // this loop makes goes through `Context::resolve()` already, and a
        // transaction on the wrong connection wraps queries that never run on
        // it while the ones that matter commit one at a time as they go.
        DB::connection(Context::resolve()->connection())->transaction(static function () use ($account, $wanted, $held, $was, &$written, &$preserved): void {
            foreach (self::byKey() as $key => $role) {
                if (! self::mayHandOut($role)
                    || self::isRestricted($account, $key)
                    || self::isElsewhere($account, $key)) {
                    continue;
                }

                $isHeld = in_array($key, $held, true);
                $isWanted = self::wants($wanted, $key);

                if ($isHeld === $isWanted) {
                    continue;
                }

                // Only one question here, where the grid asks two — and that is
                // a property of a checkbox, not an omission. A cell has three
                // stances, so two people can move one to DIFFERENT values and
                // genuinely collide. A role is held or it is not: reaching this
                // line means the store and the payload disagree, so if the
                // baseline also disagrees with the payload then this person did
                // not touch it, and if it agrees with the payload then whoever
                // moved the store moved it the same way this person did. Both
                // people moving it to different values is unreachable, because
                // there is no third value to differ about. `SaveReport::refused`
                // therefore stays empty from this screen, always.
                if ($was !== null && self::wants($was, $key) === $isWanted) {
                    $preserved++;

                    continue;
                }

                $written++;

                if ($isWanted) {
                    Warden::assign($role)->to($account);

                    continue;
                }

                Warden::retract($role)->from($account);
            }
        });

        // The one writer of the three that does not clear the memo inline as
        // it goes: `self::of($account)` above already populated
        // `$assignmentsByAccount` from BEFORE any of this transaction's
        // writes, and every `isRestricted()`/`isElsewhere()` call inside the
        // loop deliberately keeps reading that same pre-write snapshot — each
        // role in the catalogue is visited once, so nothing in the loop ever
        // needs to see an earlier iteration's write. Once the transaction
        // commits, though, that snapshot is exactly what a caller must not
        // be handed back; `AssignmentTest.php`'s "a key that arrives as text
        // still names the same role" reads `Assignment::of($account)`
        // straight after this method returns and is what pins it.
        self::forgetAssignments();

        return new SaveReport($written, $preserved);
    }

    /**
     * Hands one role to an account — the entry point a row or header action
     * reaches for, never `apply()`.
     *
     * `apply()` is a set diff over the WHOLE catalogue: `byKey()` plus
     * `mayHandOut()`/`isRestricted()`/`isElsewhere()` per role. `byKey()` and
     * `assignments()` (behind the latter two) are both memoised now, one
     * query apiece for the whole call regardless of catalogue size — before,
     * `apply()` read `assigned_roles` 47 times over a 21-role catalogue
     * reaching the same state `give()` reached in 6; after, 5 and 4 (both
     * measured in `AssignmentTest.php`'s own comparison test, which is what
     * still has to keep `give()` cheaper, not a hardcoded number for either
     * side). That is the right shape for `RoleAssignment`'s `CheckboxList`, which hands
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

            // The memo `offers()`/`isHeld()` just read from is exactly what
            // this line makes stale: a caller reading `Assignment::of()` (or
            // `isRestricted()`/`isElsewhere()`) for this same account right
            // after `give()` returns must see the row this just wrote, not
            // the snapshot from before it existed.
            self::forgetAssignments();
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

            // Same reason as `give()`'s own call: the memo just read by
            // `offers()`/`isHeld()` above is now the row list from BEFORE
            // this delete, and has to go.
            self::forgetAssignments();
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
     * Forgets both memoised reads: the catalogue and every account's
     * assignment rows. A suite raises a different one for every test case
     * and would otherwise read the one before — the same reason
     * `Conditions\Columns::forget()` exists, called from the same `setUp()`.
     *
     * The assignment half is a `WeakMap` and clears itself as instances go,
     * so this is the catalogue's escape hatch first; the second line is what
     * a test reaches for when it writes `assigned_roles` behind this class's
     * back and then asks the SAME instance again.
     */
    public static function forget(): void
    {
        self::$rolesByKey = null;
        self::$assignmentsByAccount = new WeakMap();
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
     * Memoised per account instance and per tenant: see
     * `$assignmentsByAccount`'s own docblock for both halves.
     *
     * Kept under warden's tenant scope on purpose (§6.24): this read
     * INFORMS a screen about what exists here, and reading wide would offer
     * an assignment `retract()` from this scope cannot remove.
     *
     * @return Collection<int, Model>
     */
    private static function assignments(Model $account): Collection
    {
        self::$assignmentsByAccount ??= new WeakMap();

        $tenant = self::tenantKey();
        $memo = self::$assignmentsByAccount[$account] ?? [];

        if (array_key_exists($tenant, $memo)) {
            return $memo[$tenant];
        }

        /** @var Collection<int, Model> $assignments */
        $assignments = Context::resolve()->assignedRoleClass()::query()
            ->where('entity_type', $account->getMorphClass())
            ->where('entity_id', $account->getKey())
            ->get();

        $memo[$tenant] = $assignments;
        self::$assignmentsByAccount[$account] = $memo;

        return $assignments;
    }

    /**
     * Drops EVERY memoised row list — called by every writer this class has
     * (`give()`, `take()`, `apply()`) right after a write commits, never
     * before.
     *
     * Not just the written account's entry, and not just the active tenant's.
     * One account can be behind more than one live instance in a request —
     * the object a screen was handed and the object a component rehydrated
     * from its own snapshot are two different keys in this map for the same
     * row in the store — so an invalidation aimed at the instance that
     * carried the write would leave the others answering from before it. A
     * write here is rare and a re-read is one query; being precise about
     * which entry to drop buys nothing but a way to be wrong.
     */
    private static function forgetAssignments(): void
    {
        self::$assignmentsByAccount = new WeakMap();
    }

    /**
     * The read context `assignments()` is memoised under. `readFilter()` is
     * the single source of truth for what warden's tenant scope adds to a
     * read, so it is the whole of what can make two reads of one account
     * disagree: no filter at all, this tenant's rows plus the global ones,
     * or the global ones alone.
     */
    private static function tenantKey(): string
    {
        $filter = app(Tenancy::class)->readFilter();

        if ($filter === null) {
            return '*';
        }

        return $filter[0].':'.($filter[1] ?? '');
    }
}
