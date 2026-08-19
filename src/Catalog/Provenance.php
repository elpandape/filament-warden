<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Catalog;

use Illuminate\Database\Eloquent\Model;

/**
 * Where a row of the catalogue came from, which is the question the permissions
 * screen exists to answer.
 *
 * Warden stores rows; it does not remember who asked for them. This reads the
 * store back against the code and says which of the two put each one there.
 *
 * `Unknown` is the interesting one: nothing in the panel declares this
 * permission, so nothing will ever ask for it — a renamed policy method, a typo
 * in a seeder, a screen that was deleted. It is the silent mistake warden cannot
 * detect, and the audit command of a later version reports it in bulk.
 */
enum Provenance: string
{
    case Wildcard = 'wildcard';

    case Policy = 'policy';

    case Loose = 'loose';

    case Unknown = 'unknown';

    public static function of(Model $permission, Catalog $catalog): self
    {
        $name = $permission->getAttribute('name');
        $type = $permission->getAttribute('entity_type');

        if (! is_string($name)) {
            return self::Unknown;
        }

        // A row that is not one action over one entity: `*` on either side. It is
        // the widest thing the store can hold and it is never a cell of a grid,
        // so it has to be recognisable on sight.
        if ($name === '*' || $type === '*') {
            return self::Wildcard;
        }

        $key = $name.'|'.(is_string($type) ? $type : '');

        foreach ($catalog->entries as $entry) {
            if ($entry->key() === $key) {
                return match ($entry->origin) {
                    Origin::Resource, Origin::Model => self::Policy,
                    default => self::Loose,
                };
            }
        }

        return self::Unknown;
    }

    /**
     * Whether the code still declares this permission. What nothing declares,
     * nothing asks for.
     */
    public function isDeclared(): bool
    {
        return $this !== self::Unknown;
    }
}
