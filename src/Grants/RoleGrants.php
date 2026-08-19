<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\StateKey;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Between what the grid says and what warden stores.
 *
 * It writes through the fluent API and never through the relation: the seven
 * places that invalidate the check cache all live inside warden's action
 * classes, so an `attach()` would leave every authority answering from a stale
 * payload.
 */
final class RoleGrants
{
    /**
     * The wildcard warden stores for "everything on this entity".
     */
    private const string WILDCARD = '*';

    public static function of(Model $role, Catalog $catalog): RoleState
    {
        $models = self::modelsByMorph($catalog);
        $doors = self::doorNames($catalog);

        $stances = [];
        $narrowed = [];

        foreach (self::held($role) as [$permission, $forbidden]) {
            $type = $permission->getAttribute('entity_type');
            $name = $permission->getAttribute('name');

            if (! is_string($name) || $permission->getAttribute('entity_id') !== null) {
                continue;
            }

            [$row, $action] = match (true) {
                $type === null && in_array($name, $doors, true) => [$name, StateKey::DOOR],
                is_string($type) && isset($models[$type]) => [
                    $models[$type],
                    $name === self::WILDCARD ? StateKey::MANAGE : $name,
                ],
                default => [null, null],
            };

            if ($row === null || $action === null) {
                continue;
            }

            $stances[$row][$action] = $forbidden ? Stance::Forbidden->value : Stance::Granted->value;

            if ($permission->getAttribute('options') !== null || (bool) $permission->getAttribute('only_owned')) {
                $narrowed[$row][$action] = true;
            }
        }

        return new RoleState($stances, $narrowed);
    }

    /**
     * Only what changed, and only what is safe to change.
     *
     * Never a sync: `Warden::sync()` forces `entity: null`, so it would rewrite
     * every entity-scoped cell as a bare name and delete the rest.
     *
     * @param  array<string, array<string, string>>  $desired
     */
    public static function apply(Model $role, Catalog $catalog, array $desired): void
    {
        $changes = self::changes($role, $catalog, $desired);

        if ($changes === []) {
            return;
        }

        // One transaction for the whole grid: each write bumps the cache version
        // on its own, and the trait registers one more after the commit.
        DB::transaction(static function () use ($role, $changes): void {
            foreach ($changes as $change) {
                self::write($role, $change);
            }
        });
    }

    /**
     * @param  array<string, array<string, string>>  $desired
     * @return list<Change>
     */
    public static function changes(Model $role, Catalog $catalog, array $desired): array
    {
        $current = self::of($role, $catalog);
        $changes = [];

        foreach (self::cells($catalog) as [$row, $action, $name, $entity]) {
            if ($current->narrowed[$row][$action] ?? false) {
                continue;
            }

            $from = self::stanceIn($current->stances, $row, $action);
            $to = self::stanceIn($desired, $row, $action);

            if ($from !== $to) {
                $changes[] = new Change($name, $entity, $to);
            }
        }

        return $changes;
    }

    /**
     * Every step pairs a write with the delete of its opposite.
     *
     * `forbidden` is part of the unique key on grants, so granted and forbidden
     * coexist as two rows: allowing without revoking the forbid leaves both, and
     * the cell reads as forbidden for good.
     */
    private static function write(Model $role, Change $change): void
    {
        if ($change->to === Stance::Granted) {
            Warden::unforbid($role)->to($change->name, $change->entity);
            Warden::allow($role)->to($change->name, $change->entity);

            return;
        }

        if ($change->to === Stance::Forbidden) {
            Warden::disallow($role)->to($change->name, $change->entity);
            Warden::forbid($role)->to($change->name, $change->entity);

            return;
        }

        Warden::disallow($role)->to($change->name, $change->entity);
        Warden::unforbid($role)->to($change->name, $change->entity);
    }

    /**
     * Every cell the grid offers, as (row, action, permission name, entity).
     *
     * The entity handed to warden is the model CLASS, never the morph alias: a
     * string that is not a model class throws.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: class-string<Model>|null}>
     */
    private static function cells(Catalog $catalog): array
    {
        $cells = [];
        $wildcards = [];

        foreach ($catalog->entries as $entry) {
            if ($entry->model === null) {
                $cells[] = [StateKey::row($entry), StateKey::DOOR, $entry->name, null];

                continue;
            }

            $cells[] = [StateKey::row($entry), $entry->name, $entry->name, $entry->model];
            $wildcards[$entry->model] = StateKey::row($entry);
        }

        foreach ($wildcards as $model => $row) {
            /** @var class-string<Model> $model */
            $cells[] = [$row, StateKey::MANAGE, self::WILDCARD, $model];
        }

        return $cells;
    }

    /**
     * The grant rows this role holds, each with its forbidden flag.
     *
     * Read straight off the grants table rather than through the relation:
     * `permissions()` welds a raw tenant predicate that no scope removal can
     * strip, and typing the authority as a plain model says nothing about it.
     *
     * @return list<array{0: Model, 1: bool}>
     */
    private static function held(Model $role): array
    {
        $context = Context::resolve();

        $grants = $context->grantClass()::query()
            ->where('entity_type', $role->getMorphClass())
            ->where('entity_id', $role->getKey())
            ->get();

        if ($grants->isEmpty()) {
            return [];
        }

        $permissionClass = $context->permissionClass();

        $permissions = $permissionClass::query()
            ->whereIn((new $permissionClass)->getKeyName(), $grants->pluck('permission_id')->all())
            ->get()
            ->keyBy(static fn (Model $permission): string => self::identifier($permission->getKey()));

        $held = [];

        foreach ($grants as $grant) {
            $permission = $permissions->get(self::identifier($grant->getAttribute('permission_id')));

            if ($permission instanceof Model) {
                $held[] = [$permission, (bool) $grant->getAttribute('forbidden')];
            }
        }

        return $held;
    }

    /**
     * A key that does not read as a key matches nothing, which is the safe way
     * to lose a row: the cell stays as the grid found it.
     */
    private static function identifier(mixed $key): string
    {
        return is_int($key) || is_string($key) ? (string) $key : '';
    }

    /**
     * @return array<string, class-string<Model>>
     */
    private static function modelsByMorph(Catalog $catalog): array
    {
        $models = [];

        foreach ($catalog->entries as $entry) {
            if ($entry->entityType !== null && $entry->model !== null) {
                $models[$entry->entityType] = $entry->model;
            }
        }

        return $models;
    }

    /**
     * @return list<string>
     */
    private static function doorNames(Catalog $catalog): array
    {
        $names = [];

        foreach ($catalog->entries as $entry) {
            if ($entry->model === null) {
                $names[] = $entry->name;
            }
        }

        return $names;
    }

    /**
     * @param  array<string, array<string, string>>  $stances
     */
    private static function stanceIn(array $stances, string $row, string $action): Stance
    {
        return Stance::tryFrom($stances[$row][$action] ?? '') ?? Stance::Abstain;
    }
}
