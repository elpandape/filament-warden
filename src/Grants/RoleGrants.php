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
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\State;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\StateKey;
use ElPandaPe\Warden\Actions\GrantsPermissions;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Facades\Warden;
use ElPandaPe\Warden\Tenancy\Tenancy;
use ElPandaPe\Warden\Tenancy\TenantScope;
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
        $records = [];

        foreach (self::held($role) as [$permission, $forbidden, $scope]) {
            $type = $permission->getAttribute('entity_type');
            $name = $permission->getAttribute('name');
            $id = $permission->getAttribute('entity_id');

            // Ahead of the wildcard branch on purpose: warden never writes a
            // `*` row with a key on it, so counting one as wider would draw a
            // tick for a rule nobody can produce. `is_string($name)` shares the
            // line because `name` is NOT NULL — only PHPStan needs it, and a
            // branch nothing reaches is one coverage cannot honestly ask for.
            if (! is_string($name) || $id !== null) {
                if (is_string($name) && is_string($type) && isset($models[$type])) {
                    $records[] = new RecordGrant(
                        name: $name,
                        model: $models[$type],
                        id: self::identifier($id),
                        stance: $forbidden ? Stance::Forbidden : Stance::Granted,
                        narrowing: self::writable($scope, $forRoleGrant)
                            ? Narrowing::of($permission)
                            : Narrowing::elsewhere(),
                    );
                }

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

            // No translation from warden's stored name to the grid's state key,
            // because there is none to make: `StateKey::MANAGE` is DEFINED as the
            // very name warden stores the extra column under, and the write half
            // in `cells()` pairs the two on purpose. A ternary between them reads
            // as a guard while both sides answer the same string, and no test can
            // tell its branches apart — worse, it would absorb the one change that
            // must not pass quietly. Measured: with the ternary in place, moving
            // `MANAGE` off `'*'` kept every test green, and a policy declaring its
            // own `manage` action would then have driven two writes from one cell.
            // Without it, the pin in `RoleGrantsTest` goes red.
            [$row, $action] = match (true) {
                $type === null && in_array($name, $doors, true) => [$name, StateKey::DOOR],
                is_string($type) && isset($models[$type]) => [$models[$type], $name],
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

        return new RoleState($stances, $narrowings, $wider, $records);
    }

    /**
     * Only what changed, and only what is safe to change.
     *
     * Never a sync: `Warden::sync()` forces `entity: null`, so it would rewrite
     * every entity-scoped cell as a bare name and delete the rest.
     *
     * @param  array<string, array<string, string>>  $desired
     * @param  array<string, array<string, mixed>>|null  $narrowings  null when the screen does not offer them
     * @param  array{stances?: mixed, narrowing?: mixed}|null  $baseline  what the screen was showing when it opened
     */
    public static function apply(Model $role, Catalog $catalog, array $desired, ?array $narrowings = null, ?array $baseline = null): SaveReport
    {
        [$changes, $report] = self::plan($role, $catalog, $desired, $narrowings, $baseline);

        if ($changes === []) {
            return $report;
        }

        // One transaction for the whole grid, opened on warden's own
        // connection rather than the default one. `Context::resolve()` is
        // where every write in this class already asks, so a transaction on
        // the wrong connection wraps queries that never run on it, leaving the
        // ones that matter to commit one at a time as they go — and it
        // silently disables a promise warden already makes:
        // `CacheInvalidations::bump()` only schedules its after-commit second
        // bump when `Context::resolve()->grantClass()`'s OWN connection reports
        // `transactionLevel() > 0`. On a split-connection install a transaction
        // on the default connection leaves that check reading zero always, so
        // the second bump — the one that orphans a payload a concurrent reader
        // rebuilt from pre-commit rows — never registers.
        //
        // Warden opens one of its own INSIDE this, and only sometimes:
        // `GrantsPermissions::reconstrain()` wraps its per-permission loop in a
        // transaction on that same connection, so a narrowing chain nests here
        // rather than running bare. It arrives too late to replace this one.
        // `to()` and `toOwn()` are wrapped only in `asOneWrite()`, which
        // coalesces cache bumps and is not a database transaction, so without
        // this wrapper the UNCONSTRAINED grant would already be committed —
        // visible, and authorizing every instance — before `reconstrain()`
        // opened anything. And a plain grant or revoke never calls
        // `reconstrain()` at all, so it is this wrapper or nothing for them.
        DB::connection(Context::resolve()->connection())->transaction(static function () use ($role, $changes): void {
            self::revoke($role, $changes);
            self::grant($role, $changes);
        });

        return $report;
    }

    /**
     * @param  array<string, array<string, string>>  $desired
     * @param  array<string, array<string, mixed>>|null  $narrowings  null when the screen does not offer them
     * @param  array{stances?: mixed, narrowing?: mixed}|null  $baseline  what the screen was showing when it opened
     * @return list<Change>
     */
    public static function changes(Model $role, Catalog $catalog, array $desired, ?array $narrowings = null, ?array $baseline = null): array
    {
        return self::plan($role, $catalog, $desired, $narrowings, $baseline)[0];
    }

    /**
     * The difference between three things, not two.
     *
     * A payload is not an intent. It is what the store held when the screen
     * opened PLUS whatever this person changed, and separating the two halves
     * is what keeps a cell somebody else moved from being quietly moved back —
     * over every drawn cell, not only the ones this person touched (§6.36).
     *
     * With the baseline the screen was showing, each cell answers two questions
     * instead of one — did THIS person move it, and did the store move under
     * them — and the four answers are four different outcomes:
     *
     * - untouched and undrifted: nothing to do, and it never reaches here.
     * - untouched and drifted: somebody else's edit. Left alone and counted.
     * - touched and undrifted: written.
     * - touched and drifted: refused, and named.
     *
     * The fifth case needs no branch and that is worth saying, because its
     * absence looks like an omission: two people who moved the same cell to the
     * SAME value leave payload and store agreeing, so the guard above returns
     * before any of this — no write, no complaint, nothing to report. Giving it
     * a branch would make two people who agree annoy each other.
     *
     * A caller that passes no baseline is asserting a state outright rather
     * than relaying a form somebody had open — a console script, a seeder, a
     * test. There is no earlier screen to have drifted under, so every cell
     * counts as touched, which is what this method did before there was a
     * baseline at all.
     *
     * @param  array<string, array<string, string>>  $desired
     * @param  array<string, array<string, mixed>>|null  $narrowings
     * @param  array{stances?: mixed, narrowing?: mixed}|null  $baseline
     * @return array{0: list<Change>, 1: SaveReport}
     */
    private static function plan(Model $role, Catalog $catalog, array $desired, ?array $narrowings, ?array $baseline): array
    {
        $current = self::of($role, $catalog);

        $wasStances = $baseline === null ? null : State::stances($baseline);
        $wasNarrowings = $baseline === null ? [] : State::narrowings($baseline);

        $changes = [];
        $preserved = 0;
        $refused = [];
        $unresolved = [];

        foreach (self::cells($catalog) as [$row, $action, $name, $entity]) {
            $stored = $current->narrowings[$row][$action] ?? Narrowing::all();

            $from = self::stanceIn($current->stances, $row, $action);
            $to = self::stanceIn($desired, $row, $action);

            // A cell the grid cannot draw is a cell it must not write: rewriting
            // it would round it off into something it is not. Emptying one is a
            // different question — it reads no reach and rebuilds none — and for
            // a tangled cell it is the only way out of the panel there has ever
            // been. Anything else asked of a tangled cell is reported rather
            // than skipped: the screen let the stance move, so silence would
            // read as a save that worked.
            if (! $stored->isEditable()) {
                if (! $stored->isClearable() || $from === $to) {
                    continue;
                }

                if ($to !== Stance::Abstain) {
                    $unresolved[] = ['row' => $row, 'action' => $action];

                    continue;
                }

                $changes[] = new Change($name, $entity, $to, Narrowing::all());

                continue;
            }

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

            $moved = ! $stored->is($wanted);

            if ($from === $to && ! $moved) {
                continue;
            }

            if ($wasStances !== null) {
                $was = self::stanceIn($wasStances, $row, $action);

                // Two questions, and the reach is only allowed to answer either
                // of them when the baseline actually holds one.
                //
                // It does not when the builder is switched off — the screen never
                // sent a reach, so it cannot have been touched — nor when the
                // stored reach is one this version can read and cannot rebuild
                // from a payload. Both are a flag rather than a stand-in value,
                // and that is the whole point: a substituted `Narrowing` can
                // neutralise `is($wanted)` or `is($stored)` but never both, and
                // the one it misses collapses into "the reach changed", which is
                // true of every cleared cell. That refused a lone administrator's
                // own revoke and told them a colleague had been editing.
                $wasReach = $narrowings === null
                    ? null
                    : self::wanted($wasNarrowings[$row][$action] ?? null, $entity);

                $reachAnswers = $wasReach instanceof Narrowing;

                $touched = $was !== $to || ($reachAnswers && ! $wasReach->is($wanted));
                $drifted = $was !== $from || ($reachAnswers && ! $wasReach->is($stored));

                if (! $touched) {
                    $preserved++;

                    continue;
                }

                if ($drifted) {
                    $refused[] = ['row' => $row, 'action' => $action];

                    continue;
                }
            }

            // What the browser sent decides WHETHER the rule moved; what the
            // store holds decides WHAT gets written when it did not. The two
            // are not the same object: `is()` compares payloads, and a payload
            // carries every value as text — which is right for a screen that
            // only knows text, and wrong as the source of a write. Reading it as
            // both turns a condition stored as the string `'true'`, which matches
            // nothing, into the boolean `true`, which matches every row, on a
            // click that only meant to forbid instead of grant.
            $changes[] = new Change($name, $entity, $to, $moved ? $wanted : $stored);
        }

        $tally = array_count_values(array_map(
            static fn (Change $change): string => $change->to->value,
            $changes,
        ));

        return [$changes, new SaveReport(
            count($changes),
            $preserved,
            $refused,
            $unresolved,
            $tally[Stance::Granted->value] ?? 0,
            $tally[Stance::Forbidden->value] ?? 0,
            $tally[Stance::Abstain->value] ?? 0,
        )];
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
        // A condition on a permission with no model behind it can never be
        // tested: with no instance to compare, `passesConstraints()` answers the
        // polarity of the pass, so as a grant it never grants and as a
        // prohibition it always forbids. Warden's own chain refuses to write one
        // — `GrantsPermissions::reconstrain()` throws — and the screen does not
        // offer it, so this does not accept it either.
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
     * Every step takes away whatever was there before it writes, for every
     * change in the batch at once — and always before `grant()` runs, which
     * grouping makes structural rather than a habit `write()` happened to
     * follow per cell.
     *
     * A grant chain does not make this redundant.
     * `GrantsPermissions::reconstrain()` does sweep every sibling twin of the
     * shape it narrows — its delete is a `whereIn()` over
     * `GrantsPermissions::siblingKeys()`, which matches every catalogue row of
     * that name, entity type, entity id, ownership and scope, conditions aside
     * — but it pins the polarity and the ownership of the chain it belongs to
     * while it does so, and nothing reaches it except `where()`, `orWhere()`,
     * `whereColumn()` and `orWhereColumn()`. So it never crosses a polarity,
     * never crosses `to()`/`toOwn()`, and does not run at all for a cell that is
     * being cleared or widened back to a plain grant. Those are the shapes
     * below, and they are why the order stands. Pinned in
     * `RoleGrantsTest.php` ("widening a narrowed cell answers for every record,
     * and keeps no twin grant").
     *
     * `forbidden` is part of the unique key on grants, so granted and forbidden
     * coexist as two rows: allowing without revoking the forbid leaves both, and
     * the cell reads as forbidden for good.
     *
     * And a cell holds one rule, so every shape the store could be holding it in
     * comes off first. `to()` and `toOwn()` are disjoint revokes — warden filters
     * hard on `only_owned` — so both are needed.
     *
     * A name reaches the plain row only — `findPermissions()` filters on
     * `whereNull('options')` — so the batch also carries the twin MODELS the
     * role holds, which is the one way to reach them. Read from the store and
     * not from the `Change`: this takes away what is there, not what the screen
     * believed was there.
     *
     * Grouped by entity rather than run once per changed cell:
     * `RevokesPermissions::revoke()` accepts a list of names, resolves it with
     * one `whereIn()` lookup through `ResolvesPermissions::findPermissions()`
     * and, when there is anything
     * to remove, one `delete()` — so four warden calls clear an entire
     * entity's worth of changed cells instead of four calls PER cell. This is
     * free of the TWIN problem specifically: revoking never creates one, so
     * there is no `reconstrain()` to confuse by handing it more than one
     * permission at a time.
     *
     * It is not free of everything else. `revoke()`'s own cache bump and
     * `PermissionRevoked`/`PermissionUnforbidden` event are gated on whether
     * the `delete()` removed at least one row (`RevokesPermissions::revoke()`),
     * and that gate now covers the WHOLE group. A name with nothing to revoke
     * used to mean no bump and no event for it at all;
     * grouped, that same name can ride inside a bump and an event that fire
     * only because a sibling in the same call had a row removed — the
     * event's collection still names every permission the group resolved,
     * not only the one actually deleted.
     *
     * This path carries a pre-event, and it can veto a whole group.
     * `RevokesPermissions::revoke()` fires
     * `RevokingPermission`/`UnforbiddingPermission` as its first act, before it
     * resolves the authority and before it looks a permission up, carrying the
     * whole group's name list — so a listener refusing one name aborts the
     * removal for every cell of that entity, and only where the application
     * turns cancellable events on, which ships off. The refusal is silent:
     * warden returns, nothing here reads a return value, and `grant()` runs
     * next inside the same transaction. Which way that lands depends on the
     * cell. A flip between granted and forbidden still fails closed, because
     * both rows then exist and forbidden wins. A cell cleared to abstain, or
     * one whose narrowing changed, does NOT: the old grant survives beside
     * whatever `grant()` writes, so the cell keeps power the screen says it
     * gave up. `SaveReport` cannot see it either — `plan()` counts what it
     * intended before any of this runs.
     *
     * @param  list<Change>  $changes
     */
    private static function revoke(Model $role, array $changes): void
    {
        /** @var array<string, list<string>> $byEntity */
        $byEntity = [];

        // Carried beside the group: an array key is a plain string, and
        // reconstructing the class from one launders the type away.
        /** @var array<string, class-string<Model>|null> $entities */
        $entities = [];

        foreach ($changes as $change) {
            $key = $change->entity ?? '';

            $byEntity[$key][] = $change->name;
            $entities[$key] = $change->entity;
        }

        $twins = self::twinsHeld($role);

        foreach ($byEntity as $key => $names) {
            $entity = $entities[$key];

            $targets = [...$names, ...self::twinsFor($twins, $entity, $names)];

            Warden::disallow($role)->to($targets, $entity);
            Warden::unforbid($role)->to($targets, $entity);

            if ($entity !== null) {
                Warden::disallow($role)->toOwn($entity, $names);
                Warden::unforbid($role)->toOwn($entity, $names);
            }
        }
    }

    /**
     * Every narrowed row this role holds a grant for, deduplicated: `held()`
     * yields one entry per grant, so a row both granted and forbidden is there
     * twice.
     *
     * @return list<Model>
     */
    private static function twinsHeld(Model $role): array
    {
        $twins = [];

        foreach (self::held($role) as [$permission]) {
            // The column, not the cast: a twin whose blob does not decode casts
            // to null and would be skipped here, so its model never reaches
            // `revoke()` and clearing the cell leaves the grant standing. A
            // narrowed row is a twin whatever its blob says.
            if (($permission->getAttributes()['options'] ?? null) === null) {
                continue;
            }

            $twins[self::identifier($permission->getKey())] = $permission;
        }

        return array_values($twins);
    }

    /**
     * Matched by morph alias, which is what the column holds, never by class
     * name. A row pinned to a record is left out: it answers no check this grid
     * makes, so this screen neither draws it nor deletes it.
     *
     * @param  list<Model>  $twins
     * @param  class-string<Model>|null  $entity
     * @param  list<string>  $names
     * @return list<Model>
     */
    private static function twinsFor(array $twins, ?string $entity, array $names): array
    {
        $morph = $entity === null ? null : (new $entity)->getMorphClass();

        return array_values(array_filter(
            $twins,
            static fn (Model $twin): bool => $twin->getAttribute('entity_type') === $morph
                && $twin->getAttribute('entity_id') === null
                && in_array($twin->getAttribute('name'), $names, true),
        ));
    }

    /**
     * Every change that is not abstaining, written in up to two passes.
     *
     * The honest promise is not "one write for the whole grid": it is that
     * the warden calls a changed cell used to cost — up to four revokes
     * (two for a door or loose name, which has no entity and so no
     * `toOwn()` pair to revoke) plus one grant — become up to that same
     * count per GROUP instead of per cell. A cell narrowed to `Shape::All`
     * never reaches `toOwn()` at all — that call belongs to `Shape::Owned`,
     * which is not grouped — so it costs nothing past its one `to()` call,
     * and every such cell that shares (entity, stance) is asked for in one
     * call. A cell narrowed any other way — `Shape::Owned`,
     * `Shape::Conditions` — still runs alone, through `narrow()`, exactly as
     * before: `where()`'s `reconstrain()` re-points EVERY permission
     * in the chain's `lastGranted` at the same twin, and two different cells
     * asking for two different conditions must never share one.
     *
     * Grouping a grant changes more than its query count:
     * `GrantsPermissions::to()` fires one
     * `GrantingPermission`/`ForbiddingPermission` event per call, carrying
     * every name it resolved, not one event per name. An application listening for that
     * event to veto a single cell vetoes the whole group its cell landed in.
     * Pinned by "a veto scoped to one name in the list kills every name
     * grouped with it".
     *
     * @param  list<Change>  $changes
     */
    private static function grant(Model $role, array $changes): void
    {
        /** @var array<string, list<Change>> $granted */
        $granted = [];

        /** @var array<string, list<Change>> $forbidden */
        $forbidden = [];

        foreach ($changes as $change) {
            if (! $change->to->isWritten()) {
                continue;
            }

            if ($change->narrowing->shape !== Shape::All) {
                self::narrow(
                    $change->to === Stance::Granted ? Warden::allow($role) : Warden::forbid($role),
                    $change,
                );

                continue;
            }

            if ($change->to === Stance::Granted) {
                $granted[$change->entity ?? ''][] = $change;
            } else {
                $forbidden[$change->entity ?? ''][] = $change;
            }
        }

        self::grantGroup(static fn (): GrantsPermissions => Warden::allow($role), $granted);
        self::grantGroup(static fn (): GrantsPermissions => Warden::forbid($role), $forbidden);
    }

    /**
     * One `to()` call per entity, for cells that share it with nothing left to
     * narrow. `settleTitle()` still runs once per name: it is a catalogue
     * concern, not a grant one, and grouping the write must not skip it for
     * any name that was in the group.
     *
     * @param  callable(): GrantsPermissions  $chain
     * @param  array<string, list<Change>>  $byEntity
     */
    private static function grantGroup(callable $chain, array $byEntity): void
    {
        foreach ($byEntity as $key => $group) {
            $entity = $key === '' ? null : $key;
            $names = array_map(static fn (Change $one): string => $one->name, $group);

            $chain()->to($names, $entity);

            foreach ($group as $one) {
                self::settleTitle($one);
            }
        }
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
            // TWO causes reach here, not one, and neither is an error of this
            // screen. A narrowing needs a grant in front of it, and an
            // application listening to `GrantingPermission` can veto the one
            // just asked for: nothing was granted, so there is nothing to
            // narrow, and the veto is the application's answer. And a narrowing
            // needs an entity to test against — a door or loose row carries no
            // `entity_type`, yet `Narrowing::of()` reads `options` without ever
            // asking for one, so a hand-written blob on such a row arrives here
            // as `Shape::Conditions` with nothing to compare against.
            //
            // Catching is the whole handling because warden refuses BEFORE it
            // writes: `reconstrain()` walks `lastGranted` and throws ahead of
            // its own transaction, so the plain grant this method already asked
            // for stands and only the condition is dropped. Guarding the call
            // site instead was tried and measured to change nothing.
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
            ->withoutGlobalScope(TenantScope::class)
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

            $cells[] = [StateKey::row($entry), StateKey::of($entry->name), $entry->name, $entry->model];
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
