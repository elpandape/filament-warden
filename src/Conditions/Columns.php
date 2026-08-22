<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Conditions;

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The columns a condition can compare.
 *
 * Laravel ships no schema cache and `getColumns()` costs one query — two on
 * sqlite — every time it is called, so the answer is memoised per process: a
 * model's table does not change inside a request.
 *
 * Asked of the model's own connection and not of the `Schema::` facade, which
 * would resolve the default one; and through `getColumns()` rather than
 * `getColumnListing()`, which pays the same query to throw the types away.
 */
final class Columns
{
    /** @var array<string, array{columns: list<string>, booleans: list<string>}> */
    private static array $memo = [];

    /**
     * @param  class-string<Model>  $model
     * @return list<string>
     */
    public static function of(string $model): array
    {
        return self::read($model)['columns'];
    }

    /**
     * The columns this model reads back as booleans, which is the only thing
     * that makes a `true` or a `false` in a condition able to match.
     *
     * Warden compares with `===` and rescues only numeric pairs, and
     * `is_numeric(true)` is false — so against a column with no boolean cast the
     * attribute arrives as `1` and the condition never matches, silently. The
     * question is asked of the model's casts and never of the schema: sqlite has
     * no boolean type at all, and a postgres `boolean` column with no cast still
     * comes back as `1`.
     *
     * @param  class-string<Model>  $model
     * @return list<string>
     */
    public static function booleans(string $model): array
    {
        return self::read($model)['booleans'];
    }

    /**
     * The columns of whoever is being checked, which is the panel's own
     * authenticatable — not the role being edited: a condition resolves against
     * the account asking the question.
     *
     * @return list<string>
     */
    public static function authority(): array
    {
        $model = self::authorityModel();

        return $model === null ? [] : self::of($model);
    }

    /**
     * The class behind that guard, when there is one to name.
     *
     * @return class-string<Model>|null
     */
    public static function authorityModel(): ?string
    {
        // Asked of the auth manager and not of the guard: `getProvider()` is not
        // on the `Guard` contract, so a panel guard that is not a session guard
        // would not answer it.
        // Nullable in the signature and never null in fact: it throws when there
        // is no panel at all, which is the only way it could answer null.
        /** @var Panel $panel */
        $panel = Filament::getCurrentOrDefaultPanel();
        $name = config('auth.guards.'.$panel->getAuthGuard().'.provider');

        $provider = Auth::createUserProvider(is_string($name) ? $name : null);

        // Only an eloquent provider knows a model, and only a model has a table.
        // Anything else — a provider over a bare table, a custom one — leaves the
        // builder with no account columns to offer, and it says so.
        return $provider instanceof EloquentUserProvider ? $provider->getModel() : null;
    }

    /**
     * A schema does not change in production; it does in a suite, between one
     * case and the next.
     */
    public static function forget(): void
    {
        self::$memo = [];
    }

    /**
     * Both answers, worked out together: they need the same instance and the
     * same one failure.
     *
     * @param  class-string<Model>  $model
     * @return array{columns: list<string>, booleans: list<string>}
     */
    private static function read(string $model): array
    {
        if (array_key_exists($model, self::$memo)) {
            return self::$memo[$model];
        }

        try {
            $instance = new $model;

            $columns = array_column(
                $instance->getConnection()->getSchemaBuilder()->getColumns($instance->getTable()),
                'name',
            );

            $booleans = array_keys(array_filter(
                $instance->getCasts(),
                static fn (mixed $cast): bool => $cast === 'bool' || $cast === 'boolean',
            ));
        } catch (Throwable) {
            // A driver that cannot list columns raises rather than degrading, and
            // so does a connection that will not resolve. The builder is left with
            // nothing to compare, and says so.
            $columns = [];
            $booleans = [];
        }

        return self::$memo[$model] = ['columns' => $columns, 'booleans' => $booleans];
    }
}
