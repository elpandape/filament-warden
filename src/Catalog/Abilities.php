<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Catalog;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Support\Facades\Gate;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * The actions that exist for a model are the methods its policy declares, and
 * nothing else. A model with no policy contributes nothing, on purpose: its
 * permissions would be ones no code ever consults.
 */
final class Abilities
{
    /** @var list<string>|null */
    private static ?array $reserved = null;

    /**
     * @param  class-string  $model
     * @return list<string>
     */
    public static function of(string $model): array
    {
        // Laravel hands back an instance, built through the container, or null —
        // and it is not total: a policy registered against a class that is not
        // there throws a BindingResolutionException, and so does anything the
        // policy's constructor throws. A catalogue that let that out would take
        // the whole grid with it; the audit names it instead.
        try {
            $policy = Gate::getPolicyFor($model);
        } catch (Throwable) {
            return [];
        }

        if (! is_object($policy)) {
            return [];
        }

        $abilities = [];

        foreach (new ReflectionClass($policy)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();

            if ($method->isStatic() || str_starts_with($name, '__') || in_array($name, self::reserved(), true)) {
                continue;
            }

            $abilities[] = $name;
        }

        return $abilities;
    }

    /**
     * `before` is a gate hook, and `HandlesAuthorization` leaves two public
     * helpers behind. Trait methods report the using class as their declaring
     * class, so they can only be told apart by name — read off the trait itself so
     * a new Laravel release cannot leave this list behind.
     *
     * @return list<string>
     */
    private static function reserved(): array
    {
        return self::$reserved ??= [
            'before',
            ...array_map(
                static fn (ReflectionMethod $method): string => $method->getName(),
                new ReflectionClass(HandlesAuthorization::class)->getMethods(),
            ),
        ];
    }
}
