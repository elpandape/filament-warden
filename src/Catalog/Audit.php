<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Catalog;

use ElPandaPe\FilamentWarden\Filament\Forms\Grid\StateKey;
use ElPandaPe\FilamentWarden\Filament\Guard;
use ElPandaPe\FilamentWarden\Support\Morph;
use ElPandaPe\Warden\Context;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * What is wrong, said out loud and never fixed.
 *
 * Warden creates a permission on the first grant that names it, so there is no
 * seeding half left to this: what remains is the half that reads. It writes
 * nothing — deleting is `warden:clean`'s job, and putting deletion in a
 * deployment command turns a configuration mistake into lost data.
 */
final readonly class Audit
{
    /**
     * @param  list<string>  $open  screens that do not decide who gets in
     * @param  list<string>  $unpoliced  resources whose model has no policy
     * @param  list<string>  $orphans  permissions the catalogue declares that no grant points at
     * @param  list<string>  $forgotten  permissions nothing declares that no grant points at
     * @param  list<string>  $strays  grants for actions nothing declares
     * @param  list<string>  $drifted  the same, but a whole entity type at once
     * @param  list<string>  $unwalkable  models only a relation manager reaches
     * @param  list<string>  $unkeyable  catalogue names the grid cannot key, which throw when a role screen renders
     * @param  list<string>  $stranded  grants whose authority no longer exists
     * @param  list<string>  $unmigrated  what warden's own schema is missing
     */
    public function __construct(
        public array $open = [],
        public array $unpoliced = [],
        public array $orphans = [],
        public array $forgotten = [],
        public array $strays = [],
        public array $drifted = [],
        public array $unwalkable = [],
        public array $unkeyable = [],
        public array $stranded = [],
        public array $unmigrated = [],
    ) {}

    public static function run(): self
    {
        return self::of(array_values(Filament::getPanels()));
    }

    /**
     * @param  list<Panel>  $panels
     */
    public static function of(array $panels): self
    {

        $open = [];
        $unpoliced = [];
        $unwalkable = [];
        $unkeyable = [];
        $declared = [];
        $types = [];

        foreach ($panels as $panel) {
            foreach (Guard::unguarded($panel) as $screen) {
                $open[] = $panel->getId().': '.$screen;
            }

            foreach (self::unpoliced($panel) as $finding) {
                $unpoliced[] = $finding;
            }

            foreach (self::unwalkable($panel) as $finding) {
                $unwalkable[] = $finding;
            }

            foreach (Catalog::for($panel)->entries as $entry) {
                $declared[$entry->key()] = true;

                if ($entry->entityType !== null) {
                    $types[$entry->entityType] = true;
                }

                // Livewire splits a state path on dots, so a name carrying one
                // cannot be a cell. `StateKey` throws when the grid meets it —
                // which is a 500 on somebody's role screen, at the moment they
                // open it. A build has no business finding out that way.
                foreach ([$entry->model ?? $entry->name, $entry->model === null ? null : $entry->name] as $key) {
                    if (is_string($key) && ! StateKey::keyable($key)) {
                        $unkeyable[] = $panel->getId().': '.$entry->name;
                    }
                }
            }
        }

        [$orphans, $forgotten] = self::orphans($declared);
        [$strays, $drifted] = self::strays($declared, $types);

        return new self(
            open: $open,
            unpoliced: $unpoliced,
            orphans: $orphans,
            forgotten: $forgotten,
            strays: $strays,
            drifted: $drifted,
            unwalkable: array_values(array_unique($unwalkable)),
            unkeyable: array_values(array_unique($unkeyable)),
            stranded: self::stranded(),
            unmigrated: self::unmigrated(),
        );
    }

    /**
     * The exit code's only source of truth, and `orphans` and `unwalkable` are
     * deliberately not in it.
     *
     * `unwalkable` names a relation manager whose model this cannot reach. Nothing
     * an operator does clears it unless the manager declares `$relatedResource`,
     * and this package's own `RolesRelationManager` cannot: pointing it at
     * `RoleResource` leaks that resource's `edit` and `delete` into the tab through
     * `flatActions`, which is why it was set back to null. So the integration the
     * README documents turned `--check` red the moment anybody wired it, for good,
     * and the line it printed named a remedy that never applied. A term correct use
     * can never clear is not a gate. It is the only one of these buckets with that
     * shape: every other term names something the operator can go and fix.
     *
     * A permission the catalogue declares that nobody holds is what normal use of
     * the grid leaves behind on every cell turned off: warden's `revoke()` deletes
     * the grant and never the row. Putting that bucket back into this conjunction
     * makes `--check` exactly as red as it was before the split, with every test
     * still green — this class has one consumer, and it is the command.
     *
     * Removing one term and adding another leaves the count unchanged, so no test
     * that counts can catch the mistake. `AuditCommandTest` asserts the exit code
     * on a declared orphan instead.
     */
    public function isClean(): bool
    {
        return $this->open === []
            && $this->unmigrated === []
            && $this->unpoliced === []
            && $this->forgotten === []
            && $this->strays === []
            && $this->drifted === []
            && $this->unkeyable === [];
    }

    /**
     * Nothing was printed at all, which is a different question from `isClean()`.
     *
     * A run that reports only the informational bucket exits 0 and still has a
     * heading and a table on screen, so answering "Nothing to report." underneath
     * them would contradict the lines above it.
     */
    public function isSilent(): bool
    {
        return $this->isClean() && $this->orphans === [] && $this->stranded === [] && $this->unwalkable === [];
    }

    /**
     * Whether warden's catalogue is still in its pre-2.0 shape.
     *
     * Warden 2.0 added `permissions.identity_key` and a unique index over
     * `(name, identity_key)`, and stamps that key on every save. An installation
     * that took the composer update without publishing and running the migration
     * gets `no column named identity_key` the first time anybody writes a
     * permission — from the grid, from the permission screen, from a seeder. The
     * dependency resolves cleanly and the application breaks on first use.
     *
     * Red, unlike the other two additions of recent versions, and for a reason
     * that is about WHEN rather than about severity: a bucket that lands in a
     * later release arrives after everybody has already hit the error. Here it
     * can turn a deploy pipeline red before the deploy.
     *
     * Permanently empty once migrated, which is correct and is said in the
     * README, because an empty bucket looks like dead code to whoever reads this
     * class next.
     *
     * The index is not asked about on sqlite through `hasIndex()` alone: the
     * column is what every write touches, and a catalogue carrying the column
     * without the index is a hand-built database rather than a missed migration.
     *
     * @return list<string>
     */
    private static function unmigrated(): array
    {
        $context = Context::resolve();
        $permissions = new ($context->permissionClass());

        $schema = $permissions->getConnection()->getSchemaBuilder();
        $table = $permissions->getTable();

        if (! $schema->hasTable($table) || $schema->hasColumn($table, 'identity_key')) {
            return [];
        }

        return [$table];
    }

    /**
     * The case Filament fails open on, in the three shapes it actually takes.
     *
     * `Resource::getModel()` guesses `App\Models\{Basename}` when the resource
     * does not declare one, so the first shape is a class nobody wrote. And
     * `Gate::getPolicyFor()` is not total: it can throw when the registered
     * policy class does not exist, or when its constructor does.
     *
     * @return list<string>
     */
    private static function unpoliced(Panel $panel): array
    {
        $findings = [];
        $strict = $panel->isAuthorizationStrict() ? '' : ' (and this panel does not fail closed)';

        foreach (Catalog::resourceClasses($panel) as $resource) {
            $model = $resource::getModel();

            if (! class_exists($model)) {
                $findings[] = "{$resource} points at [{$model}], which does not exist".$strict;

                continue;
            }

            try {
                $policy = Gate::getPolicyFor($model);
            } catch (Throwable $exception) {
                $findings[] = "{$resource}: the policy for [{$model}] could not be built — ".$exception->getMessage();

                continue;
            }

            if (! is_object($policy)) {
                $findings[] = "{$resource}: [{$model}] has no policy".$strict;

                continue;
            }

            if (Abilities::of($model) === []) {
                $findings[] = "{$resource}: the policy for [{$model}] declares no action".$strict;
            }
        }

        return $findings;
    }

    /**
     * A relation manager that says only a relationship name.
     *
     * Reaching its model would mean building the owner and running the relation,
     * which can hit an abstract class, a `booted()` that throws, or a relation
     * that reads request state — and a `MorphTo` answers with the OWNER's model
     * without failing at all. So it is named here instead, with the line that
     * settles it.
     *
     * @return list<string>
     */
    private static function unwalkable(Panel $panel): array
    {
        $findings = [];

        foreach (Catalog::resourceClasses($panel) as $resource) {
            foreach (Catalog::relationManagers($resource) as $manager) {
                if ($manager::getRelatedResource() === null) {
                    $findings[] = "{$manager}: it declares no \$relatedResource, so the walk stops here — `catalog.models` puts the model in the catalogue, it does not clear this line";
                }
            }
        }

        return $findings;
    }

    /**
     * A permission no grant points at, in two severities.
     *
     * The predicate is `warden:clean`'s, without the global scopes, because a
     * row nobody uses belongs to no tenant. The split is this class's: turning a
     * grid cell off revokes the grant and leaves the row, so a DECLARED unheld
     * row is what normal use produces on every save. Reported, never red — a
     * build failing on it could only be fixed by not using the grid.
     *
     * Red needs a row that can be PROVEN undeclared, which needs a string name,
     * no wildcard on either side and no `entity_id`. Those three land
     * informational rather than silent — unlike a stray, these rows really are
     * unused and `warden:clean` really will delete them:
     *
     * - a row clamped to one record cannot be looked up: the catalogue holds
     *   classes, so a class-keyed map would call every one of them undeclared;
     * - `everything()` writes `*` as the entity type on purpose;
     * - a name that is not a string matches nothing anyway.
     *
     * `$declared` is the UNION of every panel's catalogue: it is per panel, so a
     * name one declares would read as unknown against another and a two-panel
     * installation would go red on rows both are happy with.
     *
     * The correlated column is qualified off the model, not off the configured
     * table name: the table half agrees either way, and the key half is only
     * called `id` until an installation swaps the model.
     *
     * @param  array<string, bool>  $declared
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function orphans(array $declared): array
    {
        $context = Context::resolve();
        $grants = $context->table('grants');
        $permissionClass = $context->permissionClass();
        $permissionKey = (new $permissionClass)->getQualifiedKeyName();

        $rows = $permissionClass::query()
            ->withoutGlobalScopes()
            ->whereNotExists(
                static fn (\Illuminate\Database\Query\Builder $query): \Illuminate\Database\Query\Builder => $query
                    ->from($grants)
                    ->whereColumn($grants.'.permission_id', $permissionKey),
            )
            ->get();

        $orphans = [];
        $forgotten = [];

        foreach ($rows as $row) {
            $name = $row->getAttribute('name');
            $type = $row->getAttribute('entity_type');

            // The three shapes declaredness cannot be asked about. They share one
            // line, and `! is_string($name)` shares it for the same reason it does
            // in `RoleGrants::of()`: `name` is `NOT NULL` in warden's schema, so
            // that check can never fail on its own — only PHPStan needs it — and a
            // branch nothing can reach is a branch coverage cannot ask a test to
            // hit honestly. It also has to stay an `if` with a `continue` rather
            // than a precomputed boolean, because a boolean does not carry the
            // `is_string()` narrowing forward to the concatenation below.
            if (! is_string($name) || $name === '*' || $type === '*' || $row->getAttribute('entity_id') !== null) {
                $orphans[] = self::label($row);

                continue;
            }

            if (($declared[$name.'|'.(is_string($type) ? $type : '')] ?? false) === true) {
                $orphans[] = self::label($row);

                continue;
            }

            $forgotten[] = self::label($row);
        }

        return [$orphans, $forgotten];
    }

    /**
     * Grants whose authority is gone.
     *
     * The database still cascades nothing: warden's schema puts foreign keys on
     * exactly two columns — `assigned_roles.role_id` and `grants.permission_id`
     * — and both polymorphic authority pairs are plain columns with an index
     * and no constraint.
     *
     * What warden 2.0 added is a listener, and it is narrower than it sounds.
     * `CacheInvalidations::markCascade()` calls `sweepStrandedGrants()`, which
     * returns immediately unless the deleted model's class is EXACTLY
     * `Context::roleClass()`: an account, any other authority, and a role
     * SUBCLASS are all left behind. And it hangs off `eloquent.deleted`, so a
     * `delete()` on a query builder, raw SQL and a truncate never reach it.
     * `warden:clean --stranded` sweeps the rest, but it is opt-in and it is a
     * command somebody has to run.
     *
     * So this bucket still has work, and the work is what is left over: the
     * authority that went away without a model event, or that was never a role
     * to begin with.
     *
     * Informational, never red — a row this package can neither write nor
     * delete is the noisy gate this command already demoted a bucket for once,
     * and the cure that exists now is a command, not a code change here.
     *
     * Read across every tenant, for the same reason `Holders` does: an
     * authority is gone or it is not, and that question has no scope.
     *
     * @return list<string>
     */
    private static function stranded(): array
    {
        $context = Context::resolve();

        $grants = $context->grantClass()::query()
            ->withoutGlobalScopes()
            ->get(['entity_type', 'entity_id']);

        /** @var array<string, list<int|string>> $byType */
        $byType = [];

        foreach ($grants as $grant) {
            $type = $grant->getAttribute('entity_type');
            $key = $grant->getAttribute('entity_id');

            // Both null is a grant to everyone, and a type with no key is
            // warden's wildcard authority: neither names a row that could be
            // missing.
            if (is_string($type) && (is_int($key) || is_string($key))) {
                $byType[$type][] = $key;
            }
        }

        $findings = [];

        foreach ($byType as $type => $keys) {
            $class = Morph::model($type);

            // `drifted` reads permissions.entity_type, the ability's TARGET —
            // a column `Catalog` builds from the resources it walks. This
            // reads grants.entity_type, the grant's HOLDER, which the
            // catalogue never touches at all. So an authority type that
            // cannot be resolved is not told anywhere else: it is reported
            // here, once for the type, because with no class to query there
            // is no way to say which of its keys are still alive.
            if ($class === null) {
                $findings[] = $type;

                continue;
            }

            $wanted = array_values(array_unique($keys));

            $alive = [];

            foreach ($class::query()->withoutGlobalScopes()->whereKey($wanted)->get() as $record) {
                $found = $record->getKey();

                if (is_int($found) || is_string($found)) {
                    $alive[(string) $found] = true;
                }
            }

            foreach ($wanted as $key) {
                if (! array_key_exists((string) $key, $alive)) {
                    $findings[] = $type.':'.$key;
                }
            }
        }

        return $findings;
    }

    /**
     * The silent typo: a grant pointing at an action nothing declares.
     *
     * Computed in PHP against the union of every panel's catalogue, because the
     * catalogue is built per panel and a loose name declared in one would read as
     * unknown against another.
     *
     * Two findings, not one, and the difference is a count: a whole entity type
     * nothing declares is a stale morph alias — the map moved and every row of it
     * stopped matching — while a single row among healthy siblings is somebody's
     * typo. The fixes are opposite.
     *
     * @param  array<string, bool>  $declared
     * @param  array<string, bool>  $types
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function strays(array $declared, array $types): array
    {
        $context = Context::resolve();
        $grants = $context->table('grants');
        $permissionClass = $context->permissionClass();
        $permissionKey = (new $permissionClass)->getQualifiedKeyName();

        $rows = $permissionClass::query()
            ->withoutGlobalScopes()
            // The catalogue holds classes, never rows: a grant over one record is
            // not a typo.
            ->whereNull('entity_id')
            ->whereExists(
                static fn (\Illuminate\Database\Query\Builder $query): \Illuminate\Database\Query\Builder => $query
                    ->from($grants)
                    ->whereColumn($grants.'.permission_id', $permissionKey),
            )
            ->get();

        $strays = [];
        $drifted = [];

        foreach ($rows as $row) {
            $name = $row->getAttribute('name');
            $type = $row->getAttribute('entity_type');

            // The widest rule in the store is not a mistake, and a row with no
            // readable name could match nothing anyway.
            if (! is_string($name) || $name === '*' || $type === '*') {
                continue;
            }

            if (($declared[$name.'|'.(is_string($type) ? $type : '')] ?? false) === true) {
                continue;
            }

            if (is_string($type) && ! ($types[$type] ?? false)) {
                $drifted[$type] = true;

                continue;
            }

            $strays[] = self::label($row);
        }

        return [$strays, array_keys($drifted)];
    }

    private static function label(Model $permission): string
    {
        $name = $permission->getAttribute('name');
        $type = $permission->getAttribute('entity_type');

        return (is_string($name) ? $name : '?').' on '.(is_string($type) ? $type : 'nothing');
    }
}
