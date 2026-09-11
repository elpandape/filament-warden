<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Conditions;

use ElPandaPe\FilamentWarden\Support\Line;
use ElPandaPe\Warden\Enums\ComparisonOperator;

/**
 * Every word the condition builder says, worked out once and handed to the
 * browser.
 *
 * The two screens that draw a condition — the cell inspector and the permission
 * form — say the same things, so they read them from the same place. Only the
 * words, though: what the browser re-derives between keystrokes — the clause
 * cut, the preview line, the boolean warnings — keeps its authority in
 * `Narrowing`.
 */
final class Words
{
    /**
     * @return array{
     *     operators: list<string>,
     *     authority: string,
     *     boolean: string,
     *     boolean_column: string,
     *     joiners: array{and: string, or: string},
     *     modes: array<string, array{name: string, hint: string}>,
     * }
     */
    public static function all(): array
    {
        return [
            'operators' => array_map(
                static fn (ComparisonOperator $operator): string => $operator->value,
                ComparisonOperator::cases(),
            ),
            'authority' => self::line('authority'),
            'boolean' => self::line('boolean'),
            'boolean_column' => self::line('boolean_column'),
            // Written out by hand and never derived from `LogicalOperator::cases()`,
            // as the enum's own docblock in warden asks: "Do not derive a
            // connector list from cases(): the third one is not a connector
            // anything will store." `Not` is refused on the way to disk and
            // throws when a group is evaluated.
            'joiners' => [
                'and' => self::line('and'),
                'or' => self::line('or'),
            ],
            'modes' => [
                'all' => ['name' => self::line('modes.all.name'), 'hint' => self::line('modes.all.hint')],
                'owned' => ['name' => self::line('modes.owned.name'), 'hint' => self::line('modes.owned.hint')],
                'conditions' => ['name' => self::line('modes.conditions.name'), 'hint' => self::line('modes.conditions.hint')],
            ],
        ];
    }

    /**
     * Falls back to the humanised key when nothing translates it, the same way
     * the grid does: a screen with a missing line still reads as words.
     */
    private static function line(string $key): string
    {
        return Line::orHumanize('filament-warden::ui.conditions.'.$key, $key);
    }
}
