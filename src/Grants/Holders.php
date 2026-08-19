<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use ElPandaPe\Warden\Context;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

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
 */
final readonly class Holders
{
    /**
     * How many account labels are read before the rest become a tally. A role
     * screen has a handful of roles; a permission can be held by every account
     * in the installation.
     */
    public const int LABELS = 10;

    /**
     * @param  list<string>  $roles  every role that holds it, named
     * @param  list<string>  $accounts  the first accounts that hold it, named
     */
    public function __construct(
        public array $roles = [],
        public array $accounts = [],
        public int $accountCount = 0,
        public bool $everyone = false,
        public int $forbidden = 0,
    ) {}

    public static function of(Model $permission): self
    {
        $context = Context::resolve();

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
            roles: self::labels($context->roleClass(), $roleKeys, count($roleKeys)),
            accounts: $accounts,
            accountCount: $accountCount,
            everyone: $everyone,
            forbidden: $forbidden,
        );
    }

    public function isOrphaned(): bool
    {
        return $this->roles === [] && $this->accountCount === 0 && ! $this->everyone;
    }

    public function total(): int
    {
        return count($this->roles) + $this->accountCount + ($this->everyone ? 1 : 0);
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

            // A stale morph alias does not throw, it stops resolving — and an
            // authority nobody can name still counts, it just goes unnamed.
            $class = Relation::getMorphedModel($type) ?? $type;

            if (is_subclass_of($class, Model::class)) {
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

    /**
     * The first attribute the record actually carries, in the order a person
     * would recognise it.
     *
     * Read through `getAttributes()` and never through `getAttribute()`: these
     * are the consuming application's models, and under `Model::shouldBeStrict()`
     * asking one for a column it does not have throws.
     */
    private static function label(Model $record): string
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
}
