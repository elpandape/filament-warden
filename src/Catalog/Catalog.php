<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Catalog;

use ElPandaPe\FilamentWarden\Support\Config;
use ElPandaPe\Warden\Context;
use Filament\Pages\PageConfiguration;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Resources\ResourceConfiguration;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything a panel can be asked about. Derived from code and never from the
 * database: the code declares, the store obeys.
 *
 * Built on demand and not at boot. `Plugin::register()` runs mid-chain, when a
 * panel may still be missing half its components, and `Plugin::boot()` never runs
 * at all outside an HTTP request.
 */
final readonly class Catalog
{
    /** @param  list<Entry>  $entries */
    private function __construct(public array $entries) {}

    public static function for(Panel $panel): self
    {
        return new self(self::deduplicate([
            self::panelEntry($panel),
            ...self::fromResources($panel),
            ...self::fromOwnModels(),
            ...self::fromDeclaredModels(),
            ...self::fromPages($panel),
            ...self::fromWidgets($panel),
            ...self::fromCustom(),
        ]));
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
     * The package's own two models. Today they have a policy and no resource,
     * which is exactly what `catalog.models` describes; from the version that
     * registers their resources, the resource entry wins the deduplication.
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

    /** @return list<class-string<\Filament\Resources\Resource>> */
    private static function resourceClasses(Panel $panel): array
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

    /** @return list<class-string> */
    private static function pageClasses(Panel $panel): array
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
    private static function widgetClasses(Panel $panel): array
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
