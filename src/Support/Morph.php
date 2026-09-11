<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The class an `entity_type` names, when it names one at all.
 *
 * The answer is two steps, and what makes them subtle does not vary:
 *
 * - warden stores the alias, not the class, and the map is the application's, so
 *   the same column holds `warden.role` in one installation and
 *   `ElPandaPe\Warden\Models\Role` in another;
 * - a stale alias does not throw, it stops resolving — `getMorphedModel()`
 *   answers null and the raw string falls through;
 * - and the column also holds warden's `*` wildcard, which is not an entity at
 *   all.
 *
 * So "is this a model?" is the question every caller actually has, and the ones
 * that only want the class get null rather than a string they would have to
 * check again.
 */
final class Morph
{
    /**
     * @return class-string<Model>|null
     */
    public static function model(mixed $type): ?string
    {
        if (! is_string($type) || $type === '') {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        return is_subclass_of($class, Model::class) ? $class : null;
    }
}
