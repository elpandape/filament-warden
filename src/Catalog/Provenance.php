<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Catalog;

use Illuminate\Database\Eloquent\Builder;
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
     * The same four answers, as a predicate.
     *
     * It lives beside `of()` on purpose: a badge and a filter that disagree are
     * worse than either alone, and there is a test that walks every row of a
     * catalogue through both.
     *
     * The declared half is an OR of exact pairs, which is the only portable way
     * to ask `(name, entity_type) in (…)`. A catalogue is dozens of entries, not
     * thousands, and the chain is built once per request.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function applyTo(Builder $query, Catalog $catalog): void
    {
        // Null-safe on purpose. `entity_type = '*'` is UNKNOWN and not false for
        // a loose permission, so `NOT (… OR …)` is UNKNOWN too and SQL drops the
        // row — every loose permission would fall out of every filter but the
        // wildcard's, silently. Same family as `where('col', null)`.
        $wildcard = static function (Builder $query): void {
            $query->where('name', '*')
                ->orWhere(static function (Builder $query): void {
                    $query->whereNotNull('entity_type')->where('entity_type', '*');
                });
        };

        if ($this === self::Wildcard) {
            $query->where($wildcard);

            return;
        }

        // Everything below is "not the wildcard, and declared like this".
        $query->whereNot($wildcard);

        $everything = $this === self::Unknown;

        $origins = $this === self::Policy
            ? [Origin::Resource, Origin::Model]
            : [Origin::Page, Origin::Widget, Origin::Custom, Origin::Panel];

        $declared = static function (Builder $query) use ($catalog, $origins, $everything): void {
            foreach ($catalog->entries as $entry) {
                if ($everything || in_array($entry->origin, $origins, true)) {
                    $query->orWhere(static function (Builder $query) use ($entry): void {
                        // `where('entity_type', null)` compiles to `= null`,
                        // which is never true. A loose permission would then
                        // never match its own row and the badge and the filter
                        // would answer differently about it.
                        $entry->entityType === null
                            ? $query->where('name', $entry->name)->whereNull('entity_type')
                            : $query->where('name', $entry->name)->where('entity_type', $entry->entityType);
                    });
                }
            }
        };

        $everything ? $query->whereNot($declared) : $query->where($declared);
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
