<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Support\Expiry;
use ElPandaPe\Warden\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use WeakMap;

/**
 * Who holds one role, counted the way `Holders` counts a permission.
 *
 * A second class rather than a branch inside that one: `Holders` reads `grants`
 * and answers about roles, accounts, prohibitions and the wildcard, and this
 * reads `assigned_roles`, where there is no polarity and no wildcard and the
 * interesting axis is the one warden's own writes are filtered on — the scope
 * the row was written at.
 *
 * Kept to the tenant this request is in, which is §6.24's informing read rather
 * than its deciding one: `RolesTable::warning()` reads wide because a delete's
 * cascade is blind to scope, and this says who holds the role today under the
 * tenant somebody is looking from. Reading wide here would name an assignment
 * that this screen's own hand-out and retract cannot act on.
 *
 * The three words are `RolesRelationManager::heldAs()`'s, on purpose and to the
 * letter: that screen says "here", "restricted" and "elsewhere" about one
 * account's row, this counts the same three over a role's rows, and two
 * redactions of the same fact is what §6.24 measures going wrong. The reading is
 * the rows' own columns rather than `Assignment::isRestricted()` /
 * `isElsewhere()`, which take an ACCOUNT and hydrate that account's whole
 * assignment list — asked once per holder, that is a read per person on a screen
 * that already has every row in hand.
 *
 * Not a `readonly class`, and the memo is why: §6.35 measured that one cannot
 * hold a mutable static, so the immutability drops to the promoted properties
 * — which is the shape `Holders` beside it already has.
 *
 * Memoised on the instance and not on its key, for the reason §6.35 measured:
 * `spl_object_hash()` is reusable the moment the object it named is collected,
 * so a role read, released and replaced at the same address would inherit the
 * first one's holders. A `WeakMap` clings to identity and lets go on its own.
 */
final class RoleHolders
{
    /** @var WeakMap<Model, self>|null */
    private static ?WeakMap $memo = null;

    /**
     * @param  Collection<int, Model>  $rows  the live assignments themselves, so
     *                                        a caller that wants names does not read again
     */
    public function __construct(
        public readonly Collection $rows,
        public readonly int $total = 0,
        public readonly int $here = 0,
        public readonly int $restricted = 0,
        public readonly int $elsewhere = 0,
        public readonly int $ending = 0,
    ) {}

    public static function of(Model $role): self
    {
        self::$memo ??= new WeakMap();

        return self::$memo[$role] ??= self::build($role);
    }

    /**
     * Drops the memoised answer, so the next `of()` reads the store again.
     *
     * The hand-out action on the view screen writes an assignment and the page
     * redraws in the same request — without this the tally beside it would
     * still be the one read before the write.
     */
    public static function forget(Model $role): void
    {
        unset(self::$memo[$role]);
    }

    private static function build(Model $role): self
    {
        $rows = self::read($role);
        $writeScope = app(Tenancy::class)->writeScope();

        $here = 0;
        $restricted = 0;
        $elsewhere = 0;
        $ending = 0;

        foreach ($rows as $row) {
            // The same priority `Assignment::descriptions()` and `heldAs()`
            // already use: restricted first, then elsewhere. A row can be both,
            // and restricted is the more useful of the two to say — it is the
            // half somebody can do something about from this tenant.
            match (true) {
                $row->getAttribute('restricted_to_type') !== null => $restricted++,
                ! self::sameScope($row->getAttribute('scope'), $writeScope) => $elsewhere++,
                default => $here++,
            };

            if ($row->getAttribute('expires_at') !== null) {
                $ending++;
            }
        }

        return new self(
            rows: $rows,
            total: $rows->count(),
            here: $here,
            restricted: $restricted,
            elsewhere: $elsewhere,
            // A separate axis from the three above rather than a fourth kind of
            // holder, exactly as `Holders::ending` is: a row that ends is held
            // today by whoever holds it, and counted in whichever of the three
            // names them. What it says is when that stops being true with
            // nobody doing anything.
            ending: $ending,
        );
    }

    /**
     * @return Collection<int, Model>
     */
    private static function read(Model $role): Collection
    {
        /** @var Collection<int, Model> $rows */
        $rows = Context::resolve()->assignedRoleClass()::query()
            ->where('role_id', $role->getKey())
            // Who holds it, and a lapsed row is not one of them — unlike
            // `RolesTable::warning()`, which counts them because the cascade
            // takes them whatever the clock says.
            ->tap(Expiry::live(...))
            ->orderBy('id')
            ->get();

        return $rows;
    }

    /**
     * Warden's own comparison, which is the one its writes are filtered on:
     * `retract()->from()` matches `scope` exactly, so a row that fails this is a
     * row this screen cannot remove.
     */
    private static function sameScope(mixed $rowScope, int|string|null $writeScope): bool
    {
        $rowScope = is_int($rowScope) || is_string($rowScope) ? $rowScope : null;

        return $rowScope === null && $writeScope === null
            ? true
            : (string) $rowScope === (string) $writeScope;
    }
}
