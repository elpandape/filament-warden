<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;

final class Config
{
    /** @var array<string, mixed>|null */
    private static ?array $defaults = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $repository = app(Repository::class);

        // `has()` distinguishes "absent" from "present and null": a cached config
        // never ran mergeConfigFrom, so every key of ours is absent, not null.
        if ($repository->has("filament-warden.{$key}")) {
            return $repository->get("filament-warden.{$key}");
        }

        return data_get(self::defaults(), $key, $default);
    }

    /**
     * The action names that belong to each scope, as scope => actions.
     *
     * @return array<string, list<string>>
     */
    public static function scopes(): array
    {
        $scopes = self::get('catalog.scopes');
        $normalized = [];

        foreach (is_array($scopes) ? $scopes : [] as $scope => $actions) {
            if (is_string($scope) && is_array($actions)) {
                $normalized[$scope] = array_values(array_filter($actions, is_string(...)));
            }
        }

        return $normalized;
    }

    /**
     * Models the application declares because they have a policy and no resource.
     *
     * @return list<class-string<Model>>
     */
    public static function models(): array
    {
        $models = self::get('catalog.models');

        /** @var list<class-string<Model>> $declared */
        $declared = array_values(array_filter(
            is_array($models) ? $models : [],
            static fn (mixed $model): bool => is_string($model) && is_subclass_of($model, Model::class),
        ));

        return $declared;
    }

    /**
     * Loose permissions the application declares, as name => scope.
     *
     * @return array<string, string>
     */
    public static function custom(): array
    {
        $custom = self::get('catalog.custom');
        $normalized = [];

        foreach (is_array($custom) ? $custom : [] as $name => $scope) {
            if (is_string($name) && is_string($scope)) {
                $normalized[$name] = $scope;
            }
        }

        return $normalized;
    }

    /**
     * The name an installation already uses for a panel's door, if any.
     */
    public static function panelPermission(string $panel): ?string
    {
        $overrides = self::get('guard.panel');
        $name = is_array($overrides) ? ($overrides[$panel] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** @return array<string, mixed> */
    private static function defaults(): array
    {
        if (self::$defaults === null) {
            /** @var array<string, mixed> $defaults */
            $defaults = require __DIR__.'/../../config/filament-warden.php';

            self::$defaults = $defaults;
        }

        return self::$defaults;
    }
}
