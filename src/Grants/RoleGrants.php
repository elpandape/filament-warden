<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\PermissionName;
use ElPandaPe\FilamentWarden\Conditions\Columns;
use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Conditions\Ownership;
use ElPandaPe\FilamentWarden\Conditions\Shape;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\StateKey;
use ElPandaPe\Warden\Actions\GrantsPermissions;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Facades\Warden;
use ElPandaPe\Warden\Tenancy\Tenancy;
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

        // Warden picks the scope of a write from the authority, and every write
        // this class makes has the role as that authority. The read has to ask
        // the same question: an installation keeping role grants global writes
        // them at NULL while a tenant is active, and comparing them against the
        // tenant reads a writable row as somebody else's.
        $forRoleGrant = $role instanceof (Context::resolve()->roleClass());

        /** @var array<string, array<string, list<array{0: Narrowing, 1: bool, 2: int|string|null}>>> $variants */
        $variants = [];
        $wider = [];

        foreach (self::held($role) as [$permission, $forbidden, $scope]) {
            $type = $permission->getAttribute('entity_type');
            $name = $permission->getAttribute('name');

            if (! is_string($name) || $permission->getAttribute('entity_id') !== null) {
                continue;
            }

            if ($type === '*') {
                // Forbidden wins wherever it is written, so it never loses to a
                // grant that arrives later in the loop.
                if ($forbidden || ! isset($wider[$name])) {
                    $wider[$name] = $forbidden ? Stance::Forbidden->value : Stance::Granted->value;
                }

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

            $variants[$row][$action][] = [Narrowing::of($permission), $forbidden, $scope];
        }

        $stances = [];
        $narrowings = [];

        foreach ($variants as $row => $actions) {
            foreach ($actions as $action => $held) {
                [$stance, $narrowing] = self::resolve($held, $forRoleGrant);

                $stances[$row][$action] = $stance->value;
                $narrowings[$row][$action] = $narrowing;
            }
        }

        return new RoleState($stances, $narrowings, $wider);
    }

    /**
     * Only what changed, and only what is safe to change.
     *
     * Never a sync: `Warden::sync()` forces `entity: null`, so it would rewrite
     * every entity-scoped cell as a bare name and delete the rest.
     *
     * @param  array<string, array<string, string>>  $desired
     * @param  array<string, array<string, mixed>>|null  $narrowings  null when the screen does not offer them
     */
    public static function apply(Model $role, Catalog $catalog, array $desired, ?array $narrowings = null): void
    {
        $changes = self::changes($role, $catalog, $desired, $narrowings);

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
     * @param  array<string, array<string, mixed>>|null  $narrowings  null when the screen does not offer them
     * @return list<Change>
     */
    public static function changes(Model $role, Catalog $catalog, array $desired, ?array $narrowings = null): array
    {
        $current = self::of($role, $catalog);
        $changes = [];

        foreach (self::cells($catalog) as [$row, $action, $name, $entity]) {
            $stored = $current->narrowings[$row][$action] ?? Narrowing::all();

            // A cell the grid cannot draw is a cell it must not write: rewriting
            // it would round it off into something it is not.
            if (! $stored->isEditable()) {
                continue;
            }

            $from = self::stanceIn($current->stances, $row, $action);
            $to = self::stanceIn($desired, $row, $action);

            // Abstaining is the absence of a row, and a row that does not exist
            // has no reach to narrow.
            //
            // And a screen with the builder switched off keeps what the store
            // has: reading "no reach on screen" as "every row" would widen every
            // narrowed cell of the grid the first time somebody saved it.
            $wanted = match (true) {
                $to === Stance::Abstain => Narrowing::all(),
                $narrowings === null => $stored,
                default => self::wanted($narrowings[$row][$action] ?? null, $entity),
            };

            if (! $wanted instanceof Narrowing) {
                continue;
            }

            if ($from !== $to || ! $stored->is($wanted)) {
                $changes[] = new Change($name, $entity, $to, $wanted);
            }
        }

        return $changes;
    }

    /**
     * What a cell says when the store holds more than one row for it.
     *
     * Forbidden wins, the same way it wins when the engine resolves the check.
     * And two rows of the same polarity differing only in how they are narrowed
     * are a state the grid cannot draw — which is exactly what an edit made with
     * the wrong sequence leaves behind, so the honest answer is to say so and
     * keep hands off.
     *
     * @param  list<array{0: Narrowing, 1: bool, 2: int|string|null}>  $held
     * @return array{0: Stance, 1: Narrowing}
     */
    private static function resolve(array $held, bool $forRoleGrant): array
    {
        $forbidden = array_values(array_filter($held, static fn (array $one): bool => $one[1]));
        $chosen = $forbidden === [] ? $held : $forbidden;

        $stance = $forbidden === [] ? Stance::Granted : Stance::Forbidden;

        if (count($chosen) !== 1) {
            return [$stance, Narrowing::tangled()];
        }

        // A grant that lives at another scope is read — warden answers with it —
        // and cannot be written: a write targets one exact scope, so switching
        // this cell off would delete nothing and report success.
        return [$stance, self::writable($chosen[0][2], $forRoleGrant) ? $chosen[0][0] : Narrowing::elsewhere()];
    }

    /**
     * Whether a row at this scope is one this screen could write.
     *
     * Asked the way warden asks it. `writeScope()` bare answers the active
     * tenant even for a grant warden itself would write at NULL, so an
     * installation keeping role grants global had every one of them judged
     * somebody else's: drawn locked, marked as another tenant's, and dropped
     * from the diff — while `disallow()` would have deleted it. The pessimism
     * was not conservative, it was wrong in both directions.
     *
     * Compared as text on purpose: warden types a tenant `int|string` while the
     * column is an integer, so a resolver handing back `'5'` must still match a
     * row stamped `5`.
     */
    private static function writable(int|string|null $scope, bool $forRoleGrant): bool
    {
        $writeScope = app(Tenancy::class)->writeScope(forRoleGrant: $forRoleGrant);

        return $scope === null && $writeScope === null
            ? true
            : (string) $scope === (string) $writeScope;
    }

    /**
     * How far the screen is asking this cell to reach, checked against what the
     * table actually has. Anything that does not add up answers null and the
     * cell is left exactly as the store has it.
     *
     * @param  class-string<Model>|null  $entity
     */
    private static function wanted(mixed $payload, ?string $entity): ?Narrowing
    {
        // A permission with no model behind it and conditions on it is created,
        // shown, and grants nothing ever: `passesConstraints()` wants an
        // instance. The screen does not offer it and this does not accept it.
        if ($payload === null || $entity === null) {
            return Narrowing::all();
        }

        return Narrowing::fromPayload(
            $payload,
            Columns::of($entity),
            Columns::authority(),
            Ownership::of($entity),
        );
    }

    /**
     * Every step takes away whatever was there before it writes.
     *
     * `forbidden` is part of the unique key on grants, so granted and forbidden
     * coexist as two rows: allowing without revoking the forbid leaves both, and
     * the cell reads as forbidden for good.
     *
     * And a cell holds one rule, so every shape the store could be holding it in
     * comes off first. `to()` and `toOwn()` are disjoint revokes — warden filters
     * hard on `only_owned` — so both are needed; and `to()` is the one that also
     * takes the grants of every twin sharing the name.
     *
     * Editing with a fresh `allow()->to()->where()` instead of this would leave
     * the previous twin's grant standing: the old condition would go on
     * authorizing and nothing would say so. Measured.
     */
    private static function write(Model $role, Change $change): void
    {
        Warden::disallow($role)->to($change->name, $change->entity);
        Warden::unforbid($role)->to($change->name, $change->entity);

        if ($change->entity !== null) {
            Warden::disallow($role)->toOwn($change->entity, $change->name);
            Warden::unforbid($role)->toOwn($change->entity, $change->name);
        }

        if ($change->to === Stance::Abstain) {
            return;
        }

        self::narrow(
            $change->to === Stance::Granted ? Warden::allow($role) : Warden::forbid($role),
            $change,
        );
    }

    /**
     * `toOwn()` takes the entity FIRST, the reverse of `to()`. Both parameters
     * accept strings, so swapping them does not throw: it writes a permission
     * named after the class.
     */
    private static function narrow(GrantsPermissions $chain, Change $change): void
    {
        $entity = $change->entity;

        if ($entity !== null && $change->narrowing->shape === Shape::Owned) {
            $chain->toOwn($entity, $change->name);

            return;
        }

        $chain->to($change->name, $entity);

        self::settleTitle($change);

        try {
            foreach ($change->narrowing->rules as $index => $rule) {
                $rule->applyTo($chain, $index === 0);
            }
        } catch (ConfigurationException) {
            // Narrowing needs a grant in front of it, and an application
            // listening to `GrantingPermission` can veto the one just asked for.
            // Nothing was granted, so there is nothing to narrow — and the veto
            // is the application's answer, not an error of this screen.
        }
    }

    /**
     * Give a permission this package minted the title it deserves.
     *
     * Warden writes the title in the `creating` hook, and for a permission with
     * no entity that title is `Str::ucfirst()` of the name — for
     * `widget:Filament\Widgets\AccountWidget` that is the name with one capital
     * letter, which is what a person then reads on the permission screen. Warden
     * is not wrong: it has no way to know that `widget:` means anything.
     *
     * Only a row whose title is EXACTLY what warden would have generated is
     * touched, which is the same rule the permission screen uses when it renames:
     * a title somebody wrote by hand is theirs.
     */
    private static function settleTitle(Change $change): void
    {
        if ($change->entity !== null) {
            return;
        }

        $title = PermissionName::title($change->name);

        if ($title === null) {
            return;
        }

        Context::resolve()->permissionClass()::query()
            ->withoutGlobalScopes()
            ->where('name', $change->name)
            ->whereNull('entity_type')
            // Every shape this package or warden has ever generated, because an
            // installation upgraded from an older version still carries the older
            // one — and a title somebody wrote is in none of them.
            ->whereIn('title', PermissionName::generated($change->name))
            ->update(['title' => $title]);
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
     * @return list<array{0: Model, 1: bool, 2: int|string|null}>
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
                $scope = $grant->getAttribute('scope');

                $held[] = [
                    $permission,
                    (bool) $grant->getAttribute('forbidden'),
                    is_int($scope) || is_string($scope) ? $scope : null,
                ];
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
