<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use ElPandaPe\Warden\Support\Expiry;
use ElPandaPe\Warden\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use WeakMap;

/**
 * Handing a role to an account, and taking it back.
 *
 * Written through warden's fluent API and never through the `roles()` relation,
 * for three reasons a pivot write cannot give back.
 *
 * The audit trail is the first and the largest. `AssignsRoles::to()` dispatches
 * `AssigningRole` — which a listener may refuse, where the application leaves
 * cancellable events on — and then `RoleAssigned` carrying whoever the
 * registered `ActorResolver` names; `RetractsRoles::from()` does the same with
 * `RetractingRole` and `RoleRetracted`. An `attach()`, `detach()` or `sync()`
 * announces none of the four, so nothing downstream can say who moved the role,
 * and nothing can decline the move.
 *
 * The second is the answer. `RetractsRoles::retractedCount()` reports how many
 * rows the delete actually removed, which is what makes `take()` honest: a
 * retraction targets one exact scope, so a role held only at another one is
 * deleted from nowhere and has to be reported as such.
 *
 * The third is WHEN the scope is decided.
 * `AppliesPivotTenancy::scopedMorphToMany()` reads `Tenancy::writeScope()` once,
 * as the relation is built, and hands it to `ScopedMorphToMany::writingWithin()`;
 * the fluent actions ask for it at the moment of the write. A relation object
 * held across a tenant switch therefore writes at the scope it was born with.
 *
 * What does NOT separate the two paths is restrictions. `RetractsRoles::from()`
 * filters `restricted_to_type`/`restricted_to_id` only when `on()` named a
 * context, so a bare retraction takes every restricted row of the scope with it
 * exactly as a detach would — which is why `isRestricted()` shows such a row and
 * leaves it alone rather than trusting the writer. Warden documents narrowing
 * by scope and not by restriction as deliberate, on
 * `ScopedMorphToMany::newPivotQuery()`, so the three reasons above are the
 * whole of it.
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
     * One account's rows off `assigned_roles`, memoised. Without it
     * `disableOptionWhen()` re-runs the query once per option and `offers()`
     * reads it twice, so the cost of opening the assign modal grows with the
     * catalogue — `RolesRelationManagerTest` caps it against 200 roles.
     *
     * A `WeakMap` on the instance, the pattern `Holders` uses: an entry dies
     * with the object it was built for, so nothing has to guess when a request
     * ended — and a read through a different instance re-queries rather than
     * answering from somebody else's snapshot.
     *
     * The inner key is the tenant, because `assignments()` reads THROUGH
     * warden's `TenantScope`: the same account genuinely answers differently
     * depending on which tenant is active, and one entry per account would
     * freeze whichever context asked first.
     *
     * What the map cannot notice is a write. That is `forgetAssignments()`,
     * called by every writer here — `give()`, `take()`, `renew()` and
     * `apply()` — once a write commits.
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

                continue;
            }

            // A date on a box that cannot carry one. The field writes "held,
            // with no end" and says so in its own help, but a role that ALREADY
            // ends is a fact about this account that the box would otherwise
            // hide — and the person unticking it deserves to know it was going
            // to lapse anyway. Only on rows with nothing else to say: a reason
            // is why the box is closed, and that outranks a date on a box
            // nobody can move.
            $ends = self::endsAt($account, $key);

            if ($ends instanceof CarbonImmutable) {
                $descriptions[$key] = (string) __('filament-warden::ui.relations.roles.ends', [
                    'date' => $ends->toFormattedDayDateString(),
                    'human' => $ends->diffForHumans(),
                ]);
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
     * Whoever may edit roles hands out everything, and this is that rule as a
     * check. It needs no permission of its own: `update` over a role is already
     * in the catalogue.
     */
    public static function mayHandOut(Model $role): bool
    {
        return Access::grantedToCurrentUser('update', $role);
    }

    /**
     * Whether this screen may hand that role out at all.
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
     * ticked on reload. So it is shown, marked and left alone — the same answer
     * the grid gives a grant from another tenant.
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
        // Null means no screen behind the call — a test asserting a state
        // outright, or a caller an application writes — so every role counts as
        // touched. Nothing in this package calls it that way; the field always
        // sends one when it has anywhere to keep it.
        $was = is_array($baseline) ? array_values($baseline) : null;

        $written = 0;
        $preserved = 0;

        // On warden's own connection, for the reason `RoleGrants::apply()`
        // gives: a transaction anywhere else wraps none of these writes. And in
        // one warden operation, for the other reason it gives.
        $save = static function () use ($account, $wanted, $held, $was, &$written, &$preserved): void {
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
        };

        Warden::operation(static function () use ($save): void {
            DB::connection(Context::resolve()->connection())->transaction($save);
        });

        // Cleared once the transaction commits, not per write: every
        // `isRestricted()`/`isElsewhere()` call in the loop deliberately reads
        // the snapshot `of()` took before it — each role is visited once, so no
        // iteration needs an earlier one's write — and that snapshot is exactly
        // what a caller must not be handed back. `AssignmentTest`'s "a key that
        // arrives as text still names the same role" reads `of()` straight
        // after this returns.
        self::forgetAssignments();

        return new SaveReport($written, $preserved);
    }

    /**
     * Hands one role to an account — what a row or header action reaches for,
     * never `apply()`, which is a set diff over the whole catalogue and is the
     * right shape only for a `CheckboxList` that cannot say what changed. A row
     * action already knows which role it touched.
     *
     * `offers()` here is the sole server-side authorization for WHICH role goes
     * out. A `->visible()` decides whether the button exists, never what was
     * submitted, and `disableOptionWhen()` is UX: removing this check would
     * open the write to any value that reaches the action.
     *
     * Returns whether it wrote anything, because `offers()` does not exclude a
     * role already held and a caller reporting success on "no exception" would
     * report it for a no-op.
     */
    public static function give(Model $account, int|string $role, ?DateTimeInterface $until = null): bool
    {
        if (! self::offers($account, $role) || self::isHeld($account, $role)) {
            return false;
        }

        $model = self::role($role);

        if ($model instanceof Model) {
            // `until(null)` and not a bare `to()`, because `firstOrCreate` FINDS
            // an expired row rather than making a new one, and warden only moves
            // the date when a chain declared one: a bare `to()` on a lapsed
            // assignment would find the dead row, change nothing, and report
            // success. A checkbox carries no date, so what it can mean is "held,
            // with no end" — which is what the default argument keeps saying for
            // every caller that carries no date.
            Warden::assign($model)->until($until)->to($account);

            // The memo `offers()`/`isHeld()` just read from is exactly what
            // this line makes stale: a caller reading `Assignment::of()` (or
            // `isRestricted()`/`isElsewhere()`) for this same account right
            // after `give()` returns must see the row this just wrote, not
            // the snapshot from before it existed.
            self::forgetAssignments();
        }

        // Never actually false here: `offers()` already resolved this same
        // `$role` through `role()` and answered true. Written as a plain
        // boolean rather than a second early return, so no line exists that
        // only the impossible branch reaches — a line the 100% coverage gate
        // would demand and no test can reach.
        return $model instanceof Model;
    }

    /**
     * Takes one role back — what a row action reaches for, never `apply()`.
     *
     * `offers()` here is the sole server-side authorization for taking a role
     * back through this class: the row action carries no repeated check, so
     * this is where the guarantee lives.
     *
     * `! isHeld()` is a second guard for a different reason than `give()`'s:
     * `offers()` cannot tell "held here and unrestricted" apart from "not held
     * at all", so it is what decides whether the row action is offered at all.
     * It is NOT what makes the answer honest — `retractedCount()` is. Warden
     * reports how many rows the delete actually removed, and a retraction
     * targets one exact scope, so a role held only at another one is deleted
     * from nowhere and counted zero.
     *
     * `isElsewhere()` inside `offers()` makes the same scope comparison, and
     * the two are INDEPENDENT defences of the same answer: without
     * `isElsewhere()` the count still refuses a role at another scope, and
     * with the count replaced by an inference `isElsewhere()` still does. Only
     * removing both lets a no-op report success.
     *
     * The null arm is unreachable for the reason `give()` gives, and is written
     * as a guarded block for the same reason.
     */
    public static function take(Model $account, int|string $role): bool
    {
        if (! self::offers($account, $role) || ! self::isHeld($account, $role)) {
            return false;
        }

        $model = self::role($role);

        $retracted = 0;

        if ($model instanceof Model) {
            $retracted = Warden::retract($model)->from($account)->retractedCount();

            // Same reason as `give()`'s own call: the memo just read by
            // `offers()`/`isHeld()` above is now the row list from BEFORE
            // this delete, and has to go.
            self::forgetAssignments();
        }

        return $retracted > 0;
    }

    /**
     * When this account's assignment of one role runs out, if it does.
     *
     * The SOONEST of the live rows and not the last, because the soonest is the
     * one that changes something: a role held both globally and under a tenant
     * is two rows, and the earlier date is when half of what it answers goes
     * away. There is no row here that has already lapsed — `assignments()` reads
     * through `Expiry::live()`, so a dead assignment is invisible to this whole
     * class, which is also why the screen has no "expired" badge to draw.
     */
    public static function endsAt(Model $account, int|string $role): ?CarbonImmutable
    {
        $soonest = null;

        foreach (self::assignments($account) as $assignment) {
            $key = $assignment->getAttribute('role_id');

            // Compared as text, like `isHeld()` and unlike the two sibling
            // readers above: a key arriving from a `Select` is a string even
            // where the column holds an int, and this method is reached from
            // both directions.
            if (! is_int($key) && ! is_string($key)) {
                continue; // @codeCoverageIgnore
            }

            if ((string) $key !== (string) $role) {
                continue;
            }

            $ends = $assignment->getAttribute('expires_at');

            if (! $ends instanceof DateTimeInterface) {
                // A row with no date outlives every row that has one, so the
                // whole role does: nothing this account holds of it runs out.
                return null;
            }

            $ends = CarbonImmutable::instance($ends);

            if (! $soonest instanceof CarbonImmutable || $ends->lessThan($soonest)) {
                $soonest = $ends;
            }
        }

        return $soonest;
    }

    /**
     * Moves the end date on an assignment this account already holds.
     *
     * A second entrance and not a flag on `give()`, because they answer opposite
     * questions: `give()` refuses a role already held (`isHeld()` says why) and
     * this one refuses a role that is NOT held, since there is no date to move
     * on an assignment that does not exist.
     *
     * `until()` before `to()`, and `until(null)` written out rather than skipped:
     * warden moves the date only when a chain declared one, so clearing an end
     * date has to say so. Handing back the same date writes nothing on warden's
     * side (`Expiry::apply()`), and since `to()` does not report that, this
     * compares the two dates itself and returns false.
     */
    public static function renew(Model $account, int|string $role, ?DateTimeInterface $until): bool
    {
        if (! self::offers($account, $role) || ! self::isHeld($account, $role)) {
            return false;
        }

        $model = self::role($role);

        $moved = false;

        if ($model instanceof Model) {
            $moved = self::endsAt($account, $role)?->toDateTimeString() !== ($until instanceof DateTimeInterface
                ? CarbonImmutable::instance($until)->toDateTimeString()
                : null);

            Warden::assign($model)->until($until)->to($account);

            self::forgetAssignments();
        }

        return $moved;
    }

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
     * In `give()` it is not about a duplicate row — `AssignsRoles::to()` writes
     * through `firstOrCreate()` and finds the existing one. It keeps `give()`
     * from reaching `until($until)->to()` on a role already held, which would
     * move the end date that assignment has — or clear it, with the default
     * `null` — while reporting a hand-out.
     */
    private static function isHeld(Model $account, int|string $role): bool
    {
        return array_any(self::of($account), fn (int|string $key): bool => (string) $key === (string) $role);
    }

    /**
     * Compared as text for the reason `RoleGrants::writable()` gives, here for
     * `assigned_roles.scope`, an uncast integer column.
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
     * (`CanDisableOptions::isOptionDisabled()`), so unmemoised the assign modal
     * would pay a `roles` query per option. `AssignmentTest` caps the reads.
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
     * Kept under warden's tenant scope on purpose: this read INFORMS a screen
     * about what exists here, and reading wide would offer an assignment
     * `retract()` from this scope cannot remove.
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
            // Warden's own boundary, called and not copied: a row past its date
            // stops authorising, so a screen still listing it would offer a
            // revoke for access nobody has. `Expiry::live()` is public for this,
            // and it is where the exclusive frontier is decided.
            ->tap(Expiry::live(...))
            ->get();

        $memo[$tenant] = $assignments;
        self::$assignmentsByAccount[$account] = $memo;

        return $assignments;
    }

    /**
     * Drops EVERY memoised row list, after a write and never before it.
     *
     * Not just the written account's entry, and not just the active tenant's:
     * the object a screen was handed and the one a component rehydrated from
     * its own snapshot are two keys in this map for the same row in the store,
     * so dropping only the instance that carried the write would leave the
     * other answering from before it. A write here is rare and a re-read is
     * one query; being precise about which entry to drop buys nothing but a
     * way to be wrong.
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
