<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

/**
 * What a role says today, in the shape the grid holds it.
 *
 * `narrowed` is a permission carrying conditions or an ownership flag: shown and
 * never touched, because revoking by name would delete every twin that shares it.
 *
 * `wider` is what the grid cannot hold at all — a rule written over `*`, every
 * entity at once. It owns no cell, so it is reported rather than drawn as one:
 * the role that holds everything must not read as a role that holds nothing.
 */
final readonly class RoleState
{
    /**
     * @param  array<string, array<string, string>>  $stances
     * @param  array<string, array<string, bool>>  $narrowed
     * @param  array<string, string>  $wider  rules over every entity, keyed by permission name
     */
    public function __construct(
        public array $stances = [],
        public array $narrowed = [],
        public array $wider = [],
    ) {}
}
