<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Catalog;

use ElPandaPe\FilamentWarden\Filament\Guard;
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
     * @param  list<string>  $orphans  permissions no grant points at
     * @param  list<string>  $strays  grants for actions nothing declares
     * @param  list<string>  $drifted  the same, but a whole entity type at once
     * @param  list<string>  $unwalkable  models only a relation manager reaches
     */
    public function __construct(
        public array $open = [],
        public array $unpoliced = [],
        public array $orphans = [],
        public array $strays = [],
        public array $drifted = [],
        public array $unwalkable = [],
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
            }
        }

        [$strays, $drifted] = self::strays($declared, $types);

        return new self(
            open: $open,
            unpoliced: $unpoliced,
            orphans: self::orphans(),
            strays: $strays,
            drifted: $drifted,
            unwalkable: array_values(array_unique($unwalkable)),
        );
    }

    public function isClean(): bool
    {
        return $this->open === []
            && $this->unpoliced === []
            && $this->orphans === []
            && $this->strays === []
            && $this->drifted === []
            && $this->unwalkable === [];
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
                    $findings[] = "{$manager}: its model is not in the catalogue — declare it in `catalog.models`";
                }
            }
        }

        return $findings;
    }

    /**
     * A permission no grant points at. The shape `warden:clean` uses, so the
     * screen, the console and this agree on the word — and without the global
     * scopes, because a row nobody uses belongs to no tenant.
     *
     * @return list<string>
     */
    private static function orphans(): array
    {
        $context = Context::resolve();
        $grants = $context->table('grants');
        $permissions = $context->table('permissions');

        $rows = $context->permissionClass()::query()
            ->withoutGlobalScopes()
            ->whereNotExists(
                static fn (\Illuminate\Database\Query\Builder $query): \Illuminate\Database\Query\Builder => $query
                    ->from($grants)
                    ->whereColumn($grants.'.permission_id', $permissions.'.id'),
            )
            ->get();

        $findings = [];

        foreach ($rows as $row) {
            $findings[] = self::label($row);
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
        $permissions = $context->table('permissions');

        $rows = $context->permissionClass()::query()
            ->withoutGlobalScopes()
            // The catalogue holds classes, never rows: a grant over one record is
            // not a typo.
            ->whereNull('entity_id')
            ->whereExists(
                static fn (\Illuminate\Database\Query\Builder $query): \Illuminate\Database\Query\Builder => $query
                    ->from($grants)
                    ->whereColumn($grants.'.permission_id', $permissions.'.id'),
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
