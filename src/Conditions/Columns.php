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
    /** @var array<string, list<string>> */
    private static array $columns = [];

    /**
     * @param  class-string<Model>  $model
     * @return list<string>
     */
    public static function of(string $model): array
    {
        if (array_key_exists($model, self::$columns)) {
            return self::$columns[$model];
        }

        try {
            $instance = new $model;

            $columns = $instance->getConnection()->getSchemaBuilder()->getColumns($instance->getTable());
        } catch (Throwable) {
            // A driver that cannot list columns raises rather than degrading, and
            // so does a connection that will not resolve. The builder is left with
            // nothing to compare, and says so.
            $columns = [];
        }

        return self::$columns[$model] = array_column($columns, 'name');
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
        self::$columns = [];
    }
}
