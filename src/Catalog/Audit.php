<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Catalog;

use Carbon\CarbonImmutable;
use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Conditions\Ownership;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\StateKey;
use ElPandaPe\FilamentWarden\Filament\Guard;
use ElPandaPe\FilamentWarden\FilamentWardenPlugin;
use ElPandaPe\FilamentWarden\Support\Config;
use ElPandaPe\FilamentWarden\Support\Morph;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Support\Config as WardenConfig;
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
     * @param  list<string>  $unownable  ownership rows whose model resolves no ownership, so they grant nothing
     * @param  list<string>  $stranded  grants and role assignments whose authority no longer exists
     * @param  list<string>  $unmigrated  what warden's own schema is missing
     * @param  list<string>  $misconfigured  config entries this package reads and drops
     * @param  list<string>  $unsatisfiable  catalogue rows whose condition can never be true
     * @param  list<string>  $dormant  role-to-role edges that would come alive if nesting were turned on
     * @param  list<string>  $expired  pivot rows past their date, counted per pivot
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
        public array $unownable = [],
        public array $stranded = [],
        public array $unmigrated = [],
        public array $misconfigured = [],
        public array $unsatisfiable = [],
        public array $dormant = [],
        public array $expired = [],
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
            // Only the panel that asked. A screen with no `canAccess()` is open
            // because FILAMENT answers true for one, not because this package
            // did anything — so on a panel that never registered the plugin it
            // is somebody else's decision, and reporting it turns an unrelated
            // build red over a screen this package was never asked to guard.
            //
            // Scoped to THIS bucket and no other, deliberately. Skipping a
            // pluginless panel's whole contribution also drops its catalogue
            // from `$declared`, and a permission only that panel declares then
            // moves from the informational `orphans` bucket to the red
            // `forgotten` one — measured. That would make a build REDDER, which
            // is the opposite of what this change is for.
            if ($panel->hasPlugin(FilamentWardenPlugin::make()->getId())) {
                foreach (Guard::unguarded($panel) as $screen) {
                    $open[] = $panel->getId().': '.$screen;
                }
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
            unownable: self::unownable(),
            stranded: self::stranded(),
            unmigrated: self::unmigrated(),
            misconfigured: self::misconfigured(),
            unsatisfiable: self::unsatisfiable(),
            dormant: self::dormant(),
            expired: self::expired(),
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
            && $this->unkeyable === []
            && $this->unownable === []
            && $this->misconfigured === []
            && $this->unsatisfiable === [];
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
        return $this->isClean() && $this->orphans === [] && $this->stranded === [] && $this->unwalkable === [] && $this->dormant === [] && $this->expired === [];
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
     * Config entries this package reads and silently drops.
     *
     * Every one of these readers filters what it cannot use and says nothing, so
     * a typo in `catalog.models` takes a whole entity out of every grid and the
     * only symptom is a screen that is quietly missing something. Nobody types
     * one of these keys by accident, so a build going red over one is a build
     * telling its author about a line they wrote and got wrong — clearable, and
     * therefore a gate rather than a permanent light (§6.39).
     *
     * All four are named because naming two would be the shape this package
     * keeps paying for: `catalog.models` and `catalog.custom` are what the plan
     * asked for, and `catalog.scopes` and `guard.panel` were measured to drop
     * the same way. What is NOT here is a `guard.panel` key naming a panel that
     * does not exist: that question needs the panel list, and this one is
     * answered from the config alone.
     *
     * @return list<string>
     */
    private static function misconfigured(): array
    {
        $findings = [];

        $models = Config::get('catalog.models');

        foreach (is_array($models) ? $models : [] as $model) {
            if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
                $findings[] = 'catalog.models: '.self::shown($model).' is not an Eloquent model class, so it declares nothing';
            }
        }

        $custom = Config::get('catalog.custom');

        foreach (is_array($custom) ? $custom : [] as $name => $scope) {
            if (! is_string($name) || ! is_string($scope)) {
                $findings[] = 'catalog.custom: '.self::shown($name).' => '.self::shown($scope).' is not a name and a scope, so it mints no permission';

                continue;
            }

            // Not dropped — ACCEPTED and quietly downgraded one layer down, in
            // `Catalog::fromCustom()`, where an unknown word falls back to
            // `Scope::Write`. A permission filed under the wrong heading is
            // worse than one that never appeared, so it is said separately.
            if (Scope::tryFrom($scope) === null) {
                $findings[] = 'catalog.custom: '.$name.' asks for the scope ['.$scope.'], which is not one of '
                    .implode(', ', array_column(Scope::cases(), 'value')).' — it is filed under write instead';
            }
        }

        $scopes = Config::get('catalog.scopes');

        foreach (is_array($scopes) ? $scopes : [] as $scope => $actions) {
            if (! is_string($scope) || ! is_array($actions)) {
                $findings[] = 'catalog.scopes: '.self::shown($scope).' does not name a list of actions, so its column is empty';
            }
        }

        $panels = Config::get('guard.panel');

        foreach (is_array($panels) ? $panels : [] as $panel => $name) {
            if (! is_string($name) || $name === '') {
                $findings[] = 'guard.panel: '.self::shown($panel).' => '.self::shown($name).' is not a permission name, so that door keeps the generated one';
            }
        }

        return $findings;
    }

    /**
     * A config value as a person would recognise it in their own file.
     */
    private static function shown(mixed $value): string
    {
        return match (true) {
            is_string($value) => '['.$value.']',
            is_int($value) => '['.$value.']',
            default => '['.get_debug_type($value).']',
        };
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
     * What warden 1.1.0 added is a listener, and it is narrower than it sounds.
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

        /** @var array<string, list<int|string>> $byType */
        $byType = [];

        // BOTH pivots since warden 3.0. Nesting made `assigned_roles` able to
        // hold an edge whose authority is a role, and no foreign key reaches
        // that side — so a deleted inner role leaves the same kind of orphan a
        // grant does, and `warden:clean --stranded` sweeps both. A bucket that
        // covered one of the two would call an installation clean while the
        // command it names still had work.
        /** @var list<Model> $rows */
        $rows = [
            ...$context->grantClass()::query()->withoutGlobalScopes()->get(['entity_type', 'entity_id'])->all(),
            ...$context->assignedRoleClass()::query()->withoutGlobalScopes()->get(['entity_type', 'entity_id'])->all(),
        ];

        foreach ($rows as $row) {
            $type = $row->getAttribute('entity_type');
            $key = $row->getAttribute('entity_id');

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
     * Rows that ran out, counted by which pivot they are in.
     *
     * Informational, and it is the third bucket with that shape rather than a
     * defect: an installation collects these by doing nothing wrong at all — a
     * date arrives, and warden stops reading the row without touching it.
     *
     * Worth naming anyway, because a dead row is not inert everywhere. It still
     * follows its permission or its role down a foreign key, so it still blocks
     * a delete under `roles.delete => 'unassigned'` and still locks a name under
     * `permissions.update => 'loose'` — both deliberately, since the cascade
     * takes it like any other. `warden:clean --expired` is what removes them,
     * and that is the sentence this bucket exists to be able to print.
     *
     * @return list<string>
     */
    private static function expired(): array
    {
        $context = Context::resolve();
        $now = CarbonImmutable::now();

        $findings = [];

        foreach (['grants' => $context->grantClass(), 'assignments' => $context->assignedRoleClass()] as $label => $class) {
            $count = $class::query()
                ->withoutGlobalScopes()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now)
                ->count();

            if ($count > 0) {
                $findings[] = $label.': '.$count;
            }
        }

        return $findings;
    }

    /**
     * Role-to-role edges sitting in the store with nesting switched off.
     *
     * They have always been writable and have always granted nothing, so an
     * installation can have collected them without knowing — and warden's own
     * UPGRADE asks for exactly this count before the flag is turned on, because
     * turning it on is what makes them live. A grant somebody wrote years ago as
     * a no-op becomes access on the next check.
     *
     * INFORMATIONAL, and it is the one bucket here that reports something which
     * is not a defect: it is what a switch WOULD do. There is nothing to fix and
     * nothing this package can clean, which is §6.28's own test for a bucket
     * that must never redden a build. With the flag on it reports nothing at
     * all — the edges are not dormant any more, they are the feature.
     *
     * @return list<string>
     */
    private static function dormant(): array
    {
        if (WardenConfig::nestedRoles()) {
            return [];
        }

        $context = Context::resolve();
        $roleMorph = new ($context->roleClass())()->getMorphClass();

        $rows = $context->assignedRoleClass()::query()
            ->withoutGlobalScopes()
            ->where('entity_type', $roleMorph)
            ->get(['entity_id', 'role_id']);

        $findings = [];

        foreach ($rows as $row) {
            $outer = $row->getAttribute('entity_id');
            $inner = $row->getAttribute('role_id');

            if ((is_int($outer) || is_string($outer)) && (is_int($inner) || is_string($inner))) {
                $findings[] = $outer.' inherits '.$inner;
            }
        }

        return array_values(array_unique($findings));
    }

    /**
     * Catalogue rows whose condition can never be true.
     *
     * Warden 3.0 refuses to write one, and `warden:doctor` lists the ones a 2.x
     * database already carries — none of them are migrated. This bucket exists
     * so the same fact reaches a build that already runs `filament-warden:audit`
     * and does not know to run a second command.
     *
     * RED, unlike `stranded` beside it, and the difference is §6.28's rule:
     * whoever installs this CAN empty it — correct the condition, or add the
     * cast the value expects. It only shrinks, too: nothing can write a new one
     * since 3.0, so a gate that goes green stays green. A tray the correct use
     * of the package cannot empty is the one that must not be a gate; this is
     * the opposite of that.
     *
     * The rule is warden's, asked through `Narrowing`, and it wants an instance
     * — the answer is the model's casts. A row whose entity resolves to nothing
     * is `drifted`'s finding, not this one's.
     *
     * @return list<string>
     */
    private static function unsatisfiable(): array
    {
        $rows = Context::resolve()->permissionClass()::query()
            ->withoutGlobalScopes()
            ->whereNotNull('options')
            ->get();

        $findings = [];

        foreach ($rows as $row) {
            $type = $row->getAttribute('entity_type');
            $model = is_string($type) ? Morph::model($type) : null;

            if ($model === null) {
                continue;
            }

            foreach (Narrowing::of($row)->unsatisfiableColumns($model) as $column) {
                $name = $row->getAttribute('name');

                $findings[] = (is_string($name) ? $name : '?').' on '.$type.'.'.$column;
            }
        }

        return array_values(array_unique($findings));
    }

    /**
     * Catalogue rows carrying `only_owned` whose model resolves no ownership.
     *
     * Such a row grants nothing and forbids nothing: `Context::isOwnedBy()` has
     * no attribute to compare, and on the query side warden fails closed —
     * `WhereCan::ownershipAttribute()` asks `resolvesOwnershipFor()`, demands a
     * string, and confirms the column, going through `inexpressible()` when any
     * of the three says no. So the row sits in the catalogue looking exactly
     * like a working one.
     *
     * Nothing on these screens can write one: `Conditions\Ownership::of()` is
     * asked before the checkbox is offered. Warden's own `toOwn()` asks nothing
     * at all, so a seeder, a console command or a migration mints them in
     * silence — which is the whole reason this is worth naming here rather than
     * waiting for somebody to notice a grant that never grants.
     *
     * A red finding and not an informational one: it is fixable, in two ways
     * that are both the operator's — register the ownership with `ownedVia()`,
     * or take the row out.
     *
     * @return list<string>
     */
    private static function unownable(): array
    {
        $context = Context::resolve();

        $rows = $context->permissionClass()::query()
            ->withoutGlobalScopes()
            ->where('only_owned', true)
            ->whereNotNull('entity_type')
            ->get();

        $unownable = [];

        foreach ($rows as $row) {
            $model = Morph::model($row->getAttribute('entity_type'));

            // A type nothing resolves is already `drifted`'s finding, and saying
            // it twice in two voices would have the reader fix the wrong thing.
            if ($model === null) {
                continue;
            }

            if (Ownership::of($model)->available) {
                continue;
            }

            $name = $row->getAttribute('name');
            $type = $row->getAttribute('entity_type');

            // Narrowed before concatenating: both come back `mixed`, and a name
            // that is not a string could not match anything anyway.
            if (is_string($name) && is_string($type)) {
                $unownable[] = $name.' on '.$type;
            }
        }

        return array_values(array_unique($unownable));
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
