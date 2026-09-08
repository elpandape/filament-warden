<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use ElPandaPe\FilamentWarden\Support\Morph;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Tenancy\TenantScope;
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
 * A `WeakMap<Model, self>` and never `once()`, which is a trap from a static
 * context: `Onceable::objectFromTrace()` reads `$trace[1]['object']`, which a
 * `self::method()` frame does not carry, so `Once::value()` falls back to the
 * one shared singleton and disambiguates on a hash folding in
 * `spl_object_hash()` — a value PHP reuses once the object it named is
 * collected. A permission read, freed and replaced at the same address would
 * inherit the first one's holders. A `WeakMap` keys on the object itself and
 * drops its entry when the model is collected.
 *
 * Every read here is pure, so the answer can only go stale if other code
 * writes a grant and hands back the same instance. `forget()` is the escape
 * hatch for that, and it is exercised rather than decorative.
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
     * @param  int  $ending  live grants that carry a date
     * @param  int  $lapsed  grants that already ran out
     */
    public function __construct(
        public readonly array $roles = [],
        public readonly int $roleCount = 0,
        public readonly array $accounts = [],
        public readonly int $accountCount = 0,
        public readonly bool $everyone = false,
        public readonly int $forbidden = 0,
        public readonly int $ending = 0,
        public readonly int $lapsed = 0,
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
            ->withoutGlobalScope(TenantScope::class)
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
        //
        // Wider in the other axis too, since 3.0: no `Expiry::live()` here
        // either. A lapsed grant authorises nothing and the cascade still takes
        // it, so counting it over-warns about a delete and over-locks a name —
        // both in the safe direction. Splitting this into a live count and a
        // doomed one is a screen's question, not this class's: it answers what
        // a delete destroys.
        $grants = $context->grantClass()::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('permission_id', $permission->getKey())
            ->get();

        $roleAlias = new ($context->roleClass())()->getMorphClass();

        /** @var array<string, list<int|string>> $byType */
        $byType = [];
        $everyone = false;
        $forbidden = 0;
        $ending = 0;
        $lapsed = 0;
        $now = CarbonImmutable::now();

        foreach ($grants as $grant) {
            if ((bool) $grant->getAttribute('forbidden')) {
                $forbidden++;
            }

            // Both tallies come off the rows this pass already loaded, so they
            // cost nothing beyond the loop — which is why the screen asks here
            // rather than running its own count.
            //
            // The boundary is warden's: exclusive, so a row expires AT the
            // instant it names rather than a tick later. Read in PHP and not in
            // SQL because this query is deliberately the wide one — it answers
            // what a delete destroys — and adding `Expiry::live()` to it would
            // change that answer rather than add to it.
            $ends = $grant->getAttribute('expires_at');

            if ($ends instanceof DateTimeInterface) {
                CarbonImmutable::instance($ends)->greaterThan($now) ? $ending++ : $lapsed++;
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

        // One authority can name more than one `grants` row for this permission:
        // `forbidden` and `scope` are both part of the unique index, so a role
        // granted globally AND forbidden, or granted both globally and under a
        // tenant, reads back as two rows. Deduplicated once here, before either
        // the count or the labels are built from it.
        $roleKeys = array_values(array_unique($byType[$roleAlias] ?? []));
        unset($byType[$roleAlias]);

        [$accounts, $accountCount] = self::accounts($byType);

        return new self(
            roles: self::labels($context->roleClass(), $roleKeys, self::LABELS),
            roleCount: count($roleKeys),
            accounts: $accounts,
            accountCount: $accountCount,
            everyone: $everyone,
            forbidden: $forbidden,
            ending: $ending,
            lapsed: $lapsed,
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
            $keys = array_values(array_unique($keys));
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
            ->withoutGlobalScope(TenantScope::class)
            ->whereKey(array_slice($keys, 0, $limit))
            ->get();

        $labels = [];

        foreach ($records as $record) {
            $labels[] = self::label($record);
        }

        return $labels;
    }
}
