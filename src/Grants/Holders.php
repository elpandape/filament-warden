<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use ElPandaPe\FilamentWarden\Support\Morph;
use ElPandaPe\Warden\Context;
use Illuminate\Database\Eloquent\Model;
use WeakMap;

/**
 * Who holds one permission, and who is explicitly denied it.
 *
 * Built by hand against the grants table. `Permission::roles()` exists and
 * covers only a third of the question: it reaches roles and not accounts, it
 * mixes the denials in with the grants, and under an active tenant it welds a
 * raw predicate on `grants.scope` that no scope removal can strip.
 *
 * A denial is a state and not an absence, so it is counted apart rather than
 * left out.
 *
 * Memoised per record, by identity: `PermissionInfolist` alone asks `of()`
 * five times over the same `$record`, and the two lock checks on
 * `PermissionResource` ask `anyFor()` again on every field they gate — three
 * times over for `name` alone once Filament re-evaluates its `helperText()`.
 *
 * The tool is a `WeakMap<Model, self>` and not `once()` called from here,
 * because this is a static method and `once()` from a static context is a
 * trap, not a shortcut. `Onceable::objectFromTrace()` reads
 * `$trace[1]['object']` (`vendor/laravel/framework/src/Illuminate/Support/
 * Onceable.php:47-50`), and PHP's own backtrace carries an `object` entry
 * only for a `$this->method()` frame — a `self::method()` call has none. So
 * `Once::value()` falls back to `$onceable->object ?: $this`
 * (`Once.php:55`), where `$this` is the ONE shared `Once` singleton every
 * static call site in the process reaches through `Once::instance()`
 * (`Once.php:38-41`), and the per-call disambiguation is a hash folding in
 * `spl_object_hash($permission)` (`Onceable::hashFromTrace()`, `:71`) — a
 * value PHP is free to reuse the moment the object it named is collected. A
 * permission read, freed, and replaced at the same address by an unrelated
 * one would silently inherit the first one's holders. A `WeakMap` keys on
 * the object itself, never a recyclable string, and drops its entry the
 * instant the model it was built for is collected — nothing to reuse and
 * nothing to flush.
 *
 * Nothing in this class ever writes a grant: every read here is pure, so for
 * as long as no other code writes one and hands this class the very same
 * `$permission` instance back, the memoised answer cannot go stale under a
 * caller. `forget()` is the escape hatch for the one case that would: it is
 * exercised, not decorative, in the test that pins this guarantee.
 */
final class Holders
{
    /**
     * How many account labels are read before the rest become a tally. A role
     * screen has a handful of roles; a permission can be held by every account
     * in the installation.
     */
    public const int LABELS = 10;

    /** @var WeakMap<Model, self> */
    private static WeakMap $memo;

    /** @var WeakMap<Model, bool> */
    private static WeakMap $anyMemo;

    /**
     * @param  list<string>  $roles  every role that holds it, named
     * @param  list<string>  $accounts  the first accounts that hold it, named
     */
    public function __construct(
        public readonly array $roles = [],
        public readonly int $roleCount = 0,
        public readonly array $accounts = [],
        public readonly int $accountCount = 0,
        public readonly bool $everyone = false,
        public readonly int $forbidden = 0,
    ) {}

    public static function of(Model $permission): self
    {
        self::$memo ??= new WeakMap();

        return self::$memo[$permission] ??= self::build($permission);
    }

    /**
     * Whether any grant at all points at this permission — held or
     * forbidden, to a role, an account or everyone — without building a
     * single label.
     *
     * `isOrphaned()` answers the same question for every row this class ever
     * reads for real: `build()` folds a forbidding-only grant into the tally
     * exactly like a granting one, and BOTH authorities are counted from the
     * keys the grants carry rather than from the records those keys resolve to
     * — so a grant whose role or account has since been deleted still counts,
     * and the only way `isOrphaned()` comes back true is that no `grants` row
     * named this permission at all, which is this method's `EXISTS`,
     * unqualified. Counting the roles from the records instead is what made
     * this sentence false: nothing cascades a role's own grants (there is no
     * foreign key on `grants.entity_type`/`entity_id`), so those rows outlive
     * their authority and a screen that counted labels called them nobody.
     */
    public static function anyFor(Model $permission): bool
    {
        self::$anyMemo ??= new WeakMap();

        return self::$anyMemo[$permission] ??= Context::resolve()->grantClass()::query()
            ->withoutGlobalScopes()
            ->where('permission_id', $permission->getKey())
            ->exists();
    }

    /**
     * Drops the memoised answer for one record, so the next `of()` or
     * `anyFor()` reads the store again.
     *
     * Nothing in `src/` calls this today: no screen this class serves writes
     * a grant and then asks about the same permission again in the same
     * request. It exists because a memo that cannot be told "that answer is
     * stale now" is not a cache, it is a trap with the same shape as the one
     * this class's own docblock rejects — and the test that pins the
     * read-write-read guarantee calls it directly to prove the escape hatch
     * actually opens.
     */
    public static function forget(Model $permission): void
    {
        unset(self::$memo[$permission], self::$anyMemo[$permission]);
    }

    /**
     * The first attribute the record actually carries, in the order a person
     * would recognise it.
     *
     * Read through `getAttributes()` and never through `getAttribute()`: these
     * are the consuming application's models, and under `Model::shouldBeStrict()`
     * asking one for a column it does not have throws.
     */
    public static function label(Model $record): string
    {
        $attributes = $record->getAttributes();

        foreach (['title', 'name', 'email'] as $candidate) {
            $value = $attributes[$candidate] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $key = $record->getKey();

        return '#'.(is_int($key) || is_string($key) ? $key : '?');
    }

    public function isOrphaned(): bool
    {
        return $this->roleCount === 0 && $this->accountCount === 0 && ! $this->everyone;
    }

    public function total(): int
    {
        return $this->roleCount + $this->accountCount + ($this->everyone ? 1 : 0);
    }

    private static function build(Model $permission): self
    {
        $context = Context::resolve();

        // Every tenant's grants, on purpose and not by accident: deleting a
        // permission takes its grants with it through a foreign key, and THE
        // CASCADE IS BLIND TO THE SCOPE. Counting only the active tenant's would
        // promise a smaller loss than the delete actually causes.
        //
        // It is the one place in this package that reads wider than warden would
        // answer, and the screen says so.
        $grants = $context->grantClass()::query()
            ->withoutGlobalScopes()
            ->where('permission_id', $permission->getKey())
            ->get();

        $roleAlias = new ($context->roleClass())()->getMorphClass();

        /** @var array<string, list<int|string>> $byType */
        $byType = [];
        $everyone = false;
        $forbidden = 0;

        foreach ($grants as $grant) {
            if ((bool) $grant->getAttribute('forbidden')) {
                $forbidden++;
            }

            $type = $grant->getAttribute('entity_type');
            $key = $grant->getAttribute('entity_id');

            // Both null is a grant to everyone. A type with no key is warden's
            // wildcard authority — also everyone, of that kind.
            if (! is_string($type) || $key === null) {
                $everyone = true;

                continue;
            }

            if (is_int($key) || is_string($key)) {
                $byType[$type][] = $key;
            }
        }

        $roleKeys = $byType[$roleAlias] ?? [];
        unset($byType[$roleAlias]);

        [$accounts, $accountCount] = self::accounts($byType);

        return new self(
            roles: self::labels($context->roleClass(), $roleKeys, self::LABELS),
            roleCount: count($roleKeys),
            accounts: $accounts,
            accountCount: $accountCount,
            everyone: $everyone,
            forbidden: $forbidden,
        );
    }

    /**
     * Everything that is not a role, whatever morph type it arrived under: an
     * installation can hand permissions to more than one kind of authority.
     *
     * @param  array<string, list<int|string>>  $byType
     * @return array{0: list<string>, 1: int}
     */
    private static function accounts(array $byType): array
    {
        $labels = [];
        $total = 0;

        foreach ($byType as $type => $keys) {
            $total += count($keys);

            // An authority nobody can name still counts, it just goes unnamed.
            $class = Morph::model($type);

            if ($class !== null) {
                $labels = [...$labels, ...self::labels($class, $keys, self::LABELS - count($labels))];
            }
        }

        return [$labels, $total];
    }

    /**
     * @param  class-string<Model>  $class
     * @param  list<int|string>  $keys
     * @return list<string>
     */
    private static function labels(string $class, array $keys, int $limit): array
    {
        if ($keys === [] || $limit <= 0) {
            return [];
        }

        $records = $class::query()
            ->withoutGlobalScopes()
            ->whereKey(array_slice($keys, 0, $limit))
            ->get();

        $labels = [];

        foreach ($records as $record) {
            $labels[] = self::label($record);
        }

        return $labels;
    }
}
