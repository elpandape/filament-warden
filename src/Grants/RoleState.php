<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

/**
 * What a role says today, in the shape the grid holds it.
 *
 * `narrowed` is the half that matters most: a permission carrying conditions or
 * an ownership flag is shown and never touched, because revoking by name would
 * delete every twin that shares it.
 */
final readonly class RoleState
{
    /**
     * @param  array<string, array<string, string>>  $stances
     * @param  array<string, array<string, bool>>  $narrowed
     */
    public function __construct(
        public array $stances = [],
        public array $narrowed = [],
    ) {}
}
