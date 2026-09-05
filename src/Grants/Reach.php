<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use ElPandaPe\FilamentWarden\Support\Line;
use ElPandaPe\FilamentWarden\Support\Morph;
use ElPandaPe\Warden\Context;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Over how many rows a permission actually falls, for one account.
 *
 * Asked and never volunteered: one `whereCan()` costs a handful of queries with
 * no memoisation and no cache, so a listing column would multiply it by every
 * row on the page. The count is deliberately not written down as one number —
 * it depends on the grant's shape, measured against a query log on warden 2.2.1
 * at five for a plain one, four of them preamble and one the caller's own
 * select, and more for a `toOwn()`, whose ownership check asks the schema and
 * pays twice for it on sqlite. Warden 2.2.0 partitions a single `assigned_roles`
 * read in PHP where it used to run three, so the preamble went from six to four.
 * It does not hydrate the whole candidate catalogue either: warden filters the
 * candidates in SQL by name, entity type and a `whereExists` on the grant.
 *
 * And the number is a LOWER BOUND, not the truth. `whereCan()` and the panel's
 * own checks do not answer the same thing, measured in both directions, and a
 * single assignment misses both ways: `WhereCan::activeKeys()` leaves a role
 * assigned with a context out of the grant pass and counts it in the forbid
 * pass, so such a role grants rows the query cannot see and blocks rows the
 * panel lets through. It also never consults the Gate, so a policy that denies
 * is invisible to it — and that one is true of every count, not only the
 * partial ones, which is why both sentences carry it. So this says when it
 * cannot be trusted, rather than printing a number and letting somebody decide
 * on it.
 */
final readonly class Reach
{
    public function __construct(
        public bool $available,
        public int $matched = 0,
        public int $total = 0,
        public ?string $reason = null,
        public bool $partial = false,
    ) {}

    public static function of(Model $permission, Model $authority): self
    {
        $model = self::model($permission);

        if ($model === null) {
            return new self(false, reason: self::line('no_model'));
        }

        // Asked the way Eloquent asks it, and asked BEFORE calling. A model that
        // does not compose the trait does not fail: `Query\Builder::__call` turns
        // any unknown `where*` into a dynamic where, so the call builds
        // `where "can" = ?` with the authority as the binding and answers zero
        // rows without a word. A screen that trusted the call would print a
        // number that means nothing.
        if (! method_exists($model, 'scopeWhereCan')) {
            return new self(false, reason: self::line('no_trait', ['model' => $model]));
        }

        $name = $permission->getAttribute('name');

        if (! is_string($name)) {
            return new self(false, reason: self::line('no_model'));
        }

        try {
            $total = $model::query()->count();

            // The scope the trait adds, which is exactly what was checked for
            // just above.
            $matched = $model::query()->whereCan($authority, $name)->count();
        } catch (Throwable $throwable) {
            // A stored condition naming a column the table does not have is
            // compiled into the query, and the database refuses the statement.
            //
            // Ownership used to land here too and no longer does: warden's
            // `1.1.0` put a `hasColumn()` in front of it in
            // `Checks/Queries/WhereCan.php`, so that half now fails closed and
            // answers no rows instead of throwing.
            return new self(false, reason: self::line('failed', ['message' => $throwable->getMessage()]));
        }

        return new self(
            available: true,
            matched: $matched,
            total: $total,
            partial: self::restricted($authority),
        );
    }

    /**
     * The sentence a person reads, with the caveat where the caveat belongs.
     */
    public function sentence(): string
    {
        if (! $this->available) {
            return (string) $this->reason;
        }

        return self::line($this->partial ? 'partial' : 'counted', [
            'matched' => (string) $this->matched,
            'total' => (string) $this->total,
        ]);
    }

    /**
     * The class this permission is about, when it is about one. Warden's wildcard
     * is not a model, and a loose permission is not about rows at all.
     *
     * @return class-string<Model>|null
     */
    private static function model(Model $permission): ?string
    {
        $type = $permission->getAttribute('entity_type');

        if (! is_string($type) || $type === '*') {
            return null;
        }

        return Morph::model($type);
    }

    /**
     * Whether this authority holds any role in a context.
     *
     * A restricted assignment is excluded from the grant pass of `whereCan()` and
     * included in its forbid pass, so either way the count can disagree with what
     * the panel answers. Measured both ways.
     */
    private static function restricted(Model $authority): bool
    {
        return Context::resolve()->assignedRoleClass()::query()
            ->where('entity_type', $authority->getMorphClass())
            ->where('entity_id', $authority->getKey())
            ->whereNotNull('restricted_to_type')
            ->exists();
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $replace
     */
    private static function line(string $key, array $replace = []): string
    {
        return Line::of('filament-warden::ui.probe.reach.'.$key, $replace);
    }
}
