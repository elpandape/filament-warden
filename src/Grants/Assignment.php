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

        DB::transaction(static function () use ($account, $wanted, $held): void {
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
     * unfiltered, and a role already held is still "offered" by that check, so
     * writing again here would insert a second `assigned_roles` row — `NULL` in
     * `restricted_to_type`/`restricted_to_id` is never equal to itself, so
     * nothing in the schema would stop it.
     */
    public static function give(Model $account, int|string $role): void
    {
        if (! self::offers($account, $role) || self::isHeld($account, $role)) {
            return;
        }

        $model = self::role($role);

        if ($model instanceof Model) {
            Warden::assign($model)->to($account);
        }
    }

    /**
     * Takes one role back from an account — the entry point a row action
     * reaches for, never `apply()`. See `give()` for the cost this avoids.
     *
     * No "already gone" guard is needed the way `give()` needs one against a
     * duplicate row: a `retract()->from()` that matches nothing deletes nothing,
     * and the row action this calls from only ever names a role the table
     * itself already scoped to what the account holds.
     */
    public static function take(Model $account, int|string $role): void
    {
        if (! self::offers($account, $role)) {
            return;
        }

        $model = self::role($role);

        if ($model instanceof Model) {
            Warden::retract($model)->from($account);
        }
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
     * Whether the account already holds this role, compared as text: a key
     * arriving from a `Select` is a string even where the column is not.
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
     * @return array<int|string, Model>
     */
    private static function byKey(): array
    {
        $roles = [];

        foreach (self::roles() as $role) {
            $key = $role->getKey();

            if (is_int($key) || is_string($key)) {
                $roles[$key] = $role;
            }
        }

        return $roles;
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
