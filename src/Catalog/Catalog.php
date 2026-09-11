<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Catalog;

use ElPandaPe\FilamentWarden\Support\Config;
use ElPandaPe\Warden\Context;
use Filament\Pages\PageConfiguration;
use Filament\Panel;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;
use Filament\Resources\Resource;
use Filament\Resources\ResourceConfiguration;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Everything a panel can be asked about. Derived from code and never from the
 * database: the code declares, the store obeys.
 *
 * Built on demand and not at boot. `Plugin::register()` runs mid-chain, when a
 * panel may still be missing half its components, and `Plugin::boot()` never runs
 * at all outside an HTTP request.
 *
 * `for()` is memoised per panel id, the shape `Conditions\Columns` uses for a
 * model's schema. Every call otherwise reflects a Policy afresh — the container
 * resolves one with no cache of its own — and walks a panel's resources, pages
 * and widgets besides, none of which changes while the process runs.
 *
 * Safe under Octane for the same reason `Columns` is: panels, Policies and the
 * `catalog.*` config all come from code loaded once at boot, none of it request
 * state. Two things would invalidate it and neither happens here — setting
 * `catalog.*` config mid-worker, or editing the resource list of a `Panel`
 * object already handed to `for()`. Read from `PanelRegistry::register()` and
 * `Panel`'s fluent setters rather than exercised on a live worker.
 */
final class Catalog
{
    /** @var array<string, array{panel: Panel, catalog: self}> */
    private static array $memo = [];

    /** @var list<array{panels: list<Panel>, catalog: self}> */
    private static array $unions = [];

    /** @param  list<Entry>  $entries */
    private function __construct(public readonly array $entries) {}

    public static function for(Panel $panel): self
    {
        return self::read($panel);
    }

    /**
     * `getRelations()` holds three shapes: a class-string, a `RelationGroup`, and
     * a `RelationManagerConfiguration` whose `$relationManager` is public and
     * readonly — the same unwrapping a widget configuration already gets.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resource
     * @return list<class-string<RelationManager>>
     */
    public static function relationManagers(string $resource): array
    {
        $managers = [];

        foreach ($resource::getRelations() as $relation) {
            foreach (self::unwrap($relation) as $manager) {
                $managers[] = $manager;
            }
        }

        return $managers;
    }

    /**
     * Public for the same reason as `pageClasses()`: the audit reports on
     * exactly the resources the catalogue walks.
     *
     * @return list<class-string<\Filament\Resources\Resource>>
     */
    public static function resourceClasses(Panel $panel): array
    {
        /** @var list<class-string<\Filament\Resources\Resource>> $classes */
        $classes = array_values(array_unique([
            ...array_values($panel->getResources()),
            ...array_map(
                static fn (ResourceConfiguration $configuration): string => $configuration->resource,
                $panel->getResourceConfigurations(),
            ),
        ]));

        return $classes;
    }

    /**
     * Public because the guard walks the same screens the catalogue does, and two
     * walks that could disagree about what a panel contains would be worse than
     * one method with a wider door.
     *
     * @return list<class-string>
     */
    public static function pageClasses(Panel $panel): array
    {
        /** @var list<class-string> $classes */
        $classes = array_values(array_unique([
            ...array_values($panel->getPages()),
            ...array_map(
                static fn (PageConfiguration $configuration): string => $configuration->page,
                $panel->getPageConfigurations(),
            ),
        ]));

        return $classes;
    }

    /** @return list<class-string<Widget>> */
    public static function widgetClasses(Panel $panel): array
    {
        $widgets = array_map(
            static fn (string|WidgetConfiguration $widget): string => $widget instanceof WidgetConfiguration
                ? $widget->widget
                : $widget,
            array_values($panel->getWidgets()),
        );

        foreach (self::resourceClasses($panel) as $resource) {
            $widgets = [...$widgets, ...$resource::getWidgets()];
        }

        /** @var list<class-string<Widget>> $classes */
        $classes = array_values(array_unique($widgets));

        return $classes;
    }

    /**
     * Every panel's catalogue at once, deduplicated.
     *
     * The permissions table is not per panel and the catalogue is: asked of one
     * panel, a row derived from another panel's resource would read as declared
     * by nobody, while the audit, which walks every panel, says otherwise. The
     * union is the question both ask.
     *
     * The panel list arrives explicitly, the way `Audit::of()` takes it, so
     * this is testable against panels built by hand; a method that reached for
     * `Filament::getPanels()` itself could only ever be asked about whatever
     * the process happens to have registered.
     *
     * Memoised on the panel OBJECTS and never on their ids, for the reason
     * `read()` sets out at length: an id names a different panel every time a
     * suite builds one. Each panel's own memo is reused, so this costs a walk
     * only the first time a given list is asked about.
     *
     * @param  list<Panel>  $panels
     */
    public static function union(array $panels): self
    {
        foreach (self::$unions as $union) {
            if ($union['panels'] === $panels) {
                return $union['catalog'];
            }
        }

        $entries = [];

        foreach ($panels as $panel) {
            foreach (self::read($panel)->entries as $entry) {
                $entries[] = $entry;
            }
        }

        $catalog = new self(self::deduplicate($entries));

        self::$unions[] = ['panels' => $panels, 'catalog' => $catalog];

        return $catalog;
    }

    /**
     * NOT what protects a suite rebuilding a panel under the same id per test:
     * that is `read()`'s `===` check.
     *
     * What it bounds is how many entries a long-running process minting
     * short-lived ids can accumulate. And it is the only reset for a test
     * reusing the very same `Panel` object across two cases with different
     * config, which defeats the identity check by construction.
     */
    public static function forget(): void
    {
        self::$memo = [];
        self::$unions = [];
    }

    /**
     * Keyed by panel id, the way `PanelRegistry::register()` keys its own
     * array, so a later `Panel` under the same id replaces the slot rather than
     * adding one: growth is bounded by distinct ids. But an id alone can lie —
     * this very suite builds a fresh `Panel::make()->id('scratch')` in dozens
     * of tests — so the stored panel is compared with `===` on every read: a
     * second object sharing an old id is never served the first one's
     * catalogue. A memo that could hand back the wrong panel's rows would not
     * be a slow screen, it would be a permission grid answering for someone
     * else's panel. `Panel` has no `Stringable` contract and no value-equality
     * of its own to fall back on, so identity is the only honest comparison
     * available.
     *
     * The slot holds `$panel` itself, so its handle cannot be recycled while
     * the entry lives, and `===` compares instances rather than an id PHP can
     * hand to a new object once the old one is gone.
     */
    private static function read(Panel $panel): self
    {
        $id = $panel->getId();
        $cached = self::$memo[$id] ?? null;

        if ($cached !== null && $cached['panel'] === $panel) {
            return $cached['catalog'];
        }

        $catalog = new self(self::deduplicate([
            self::panelEntry($panel),
            ...self::fromResources($panel),
            ...self::fromRelations($panel),
            ...self::fromOwnModels(),
            ...self::fromDeclaredModels(),
            ...self::fromPages($panel),
            ...self::fromWidgets($panel),
            ...self::fromCustom(),
        ]));

        self::$memo[$id] = ['panel' => $panel, 'catalog' => $catalog];

        return $catalog;
    }

    private static function panelEntry(Panel $panel): Entry
    {
        return new Entry(
            name: PermissionName::panel($panel),
            entityType: null,
            model: null,
            scope: Scope::Read,
            origin: Origin::Panel,
        );
    }

    /** @return list<Entry> */
    private static function fromResources(Panel $panel): array
    {
        $entries = [];

        foreach (self::resourceClasses($panel) as $resource) {
            foreach (self::forModel($resource::getModel(), Origin::Resource, $resource) as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * The models a resource reaches through a relation manager, when it says so
     * without being asked twice.
     *
     * A manager that declares `$relatedResource` hands over a Resource — and with
     * it a model — through two public statics, with nothing instantiated. And for
     * those managers that resource IS the whole authorization story:
     * `canViewForRecord()` short-circuits to its `canAccess()`.
     *
     * One that only declares `$relationship` does not. Resolving it needs the
     * owner model built and the relation method run, which can hit an abstract
     * class, a `booted()` that throws, or a relation that reads request state and
     * dies in console — and a `MorphTo` does not fail at all: it quietly answers
     * with the OWNER's model. So the free half is walked here, and
     * `Audit::unwalkable()` names the rest.
     *
     * @return list<Entry>
     */
    private static function fromRelations(Panel $panel): array
    {
        $entries = [];

        foreach (self::resourceClasses($panel) as $resource) {
            foreach (self::relatedResources($resource) as $related) {
                foreach (self::forModel($related::getModel(), Origin::Resource, $related) as $entry) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     * @return list<class-string<\Filament\Resources\Resource>>
     */
    private static function relatedResources(string $resource): array
    {
        $related = [];

        foreach (self::relationManagers($resource) as $manager) {
            $candidate = $manager::getRelatedResource();

            if ($candidate !== null && is_subclass_of($candidate, \Filament\Resources\Resource::class)) {
                $related[] = $candidate;
            }
        }

        return $related;
    }

    /**
     * @return list<class-string<RelationManager>>
     */
    private static function unwrap(mixed $relation): array
    {
        if ($relation instanceof RelationGroup) {
            $managers = [];

            try {
                // A group built with a closure instead of an array makes this
                // fatal, and a fatal here would take the whole grid with it.
                $group = $relation->getManagers();
            } catch (Throwable) {
                $group = [];
            }

            foreach ($group as $manager) {
                foreach (self::unwrap($manager) as $unwrapped) {
                    $managers[] = $unwrapped;
                }
            }

            return $managers;
        }

        if ($relation instanceof RelationManagerConfiguration) {
            $relation = $relation->relationManager;
        }

        return is_string($relation) && is_subclass_of($relation, RelationManager::class) ? [$relation] : [];
    }

    /**
     * Warden's role and permission models, which carry this package's
     * policies. Where the plugin's resources are registered their resource
     * entries win the deduplication, since `fromResources()` runs before this in
     * `read()` and `deduplicate()` keeps the first entry per key; on a panel
     * without them — `roles(false)`, `permissions(false)` or no plugin at all —
     * this is what keeps their actions in the catalogue.
     *
     * @return list<Entry>
     */
    private static function fromOwnModels(): array
    {
        $context = Context::resolve();

        return [
            ...self::forModel($context->roleClass(), Origin::Model, null),
            ...self::forModel($context->permissionClass(), Origin::Model, null),
        ];
    }

    /** @return list<Entry> */
    private static function fromDeclaredModels(): array
    {
        $entries = [];

        foreach (Config::models() as $model) {
            foreach (self::forModel($model, Origin::Model, null) as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** @return list<Entry> */
    private static function fromPages(Panel $panel): array
    {
        return array_map(
            static fn (string $page): Entry => new Entry(
                name: PermissionName::page($page),
                entityType: null,
                model: null,
                scope: Scope::Read,
                origin: Origin::Page,
                source: $page,
            ),
            self::pageClasses($panel),
        );
    }

    /** @return list<Entry> */
    private static function fromWidgets(Panel $panel): array
    {
        return array_map(
            static fn (string $widget): Entry => new Entry(
                name: PermissionName::widget($widget),
                entityType: null,
                model: null,
                scope: Scope::Read,
                origin: Origin::Widget,
                source: $widget,
            ),
            self::widgetClasses($panel),
        );
    }

    /** @return list<Entry> */
    private static function fromCustom(): array
    {
        $entries = [];

        foreach (Config::custom() as $name => $scope) {
            $entries[] = new Entry(
                name: $name,
                entityType: null,
                model: null,
                scope: Scope::tryFrom($scope) ?? Scope::Write,
                origin: Origin::Custom,
            );
        }

        return $entries;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  class-string|null  $source
     * @return list<Entry>
     */
    private static function forModel(string $model, Origin $origin, ?string $source): array
    {
        // `Resource::getModel()` GUESSES `App\Models\{Basename}` when the
        // resource does not declare one, so a resource that forgot it points at a
        // class nobody wrote. Without this the whole grid dies on an `Error`
        // naming that class; the audit names the resource instead.
        if (! class_exists($model)) {
            return [];
        }

        // Never written by hand: the morph map decides what lands in entity_type,
        // and it is `warden.role` here and a class name there.
        $entityType = new $model()->getMorphClass();

        return array_map(
            static fn (string $action): Entry => new Entry(
                name: $action,
                entityType: $entityType,
                model: $model,
                scope: Scope::forAction($action),
                origin: $origin,
                source: $source,
            ),
            Abilities::of($model),
        );
    }

    /**
     * @param  list<Entry>  $entries
     * @return list<Entry>
     */
    private static function deduplicate(array $entries): array
    {
        $unique = [];

        foreach ($entries as $entry) {
            $unique[$entry->key()] ??= $entry;
        }

        return array_values($unique);
    }
}
