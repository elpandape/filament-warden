<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The class an `entity_type` names, when it names one at all.
 *
 * Six places asked this question and each wrote the answer out again, with the
 * same two steps and the same reasoning in six different comments. The steps do
 * not vary, and neither does what makes them subtle:
 *
 * - warden stores the alias, not the class, and the map is the application's, so
 *   the same column holds `warden.role` in one installation and
 *   `ElPandaPe\Warden\Models\Role` in another;
 * - a stale alias does not throw, it stops resolving — `getMorphedModel()`
 *   answers null and the raw string falls through;
 * - and the column also holds things that are not entities at all: warden's `*`
 *   wildcard, and a loose name like `page:App\Filament\Pages\Settings`.
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
