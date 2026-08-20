<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use ElPandaPe\Warden\Context;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

/**
 * Over how many rows a permission actually falls, for one account.
 *
 * Asked and never volunteered: one `whereCan()` is six queries with no
 * memoisation and no cache, and it hydrates the whole candidate catalogue every
 * time. Three calls in a request were measured at eighteen queries.
 *
 * And the number is a LOWER BOUND, not the truth. `whereCan()` and the panel's
 * own checks do not answer the same thing, measured in both directions: a role
 * assigned with a context grants rows the query cannot see, and a permission
 * whose conditions do not deserialize loses its row scope on the forbid side and
 * blackens the whole table. So this says when it cannot be trusted, rather than
 * printing a number and letting somebody decide on it.
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
            // `only_owned` on a model whose ownership attribute is not a column
            // does not fail closed — it emits invalid SQL and throws at execution.
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

        $class = Relation::getMorphedModel($type) ?? $type;

        return is_subclass_of($class, Model::class) ? $class : null;
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
     * @param  array<string, string>  $replace
     */
    private static function line(string $key, array $replace = []): string
    {
        $line = __('filament-warden::ui.probe.reach.'.$key, $replace);

        return is_string($line) ? $line : $key;
    }
}
