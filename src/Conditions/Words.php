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
 * form — say the same things, so they read them from the same place. What the
 * browser is never given is a rule: the only one it decides for itself is the
 * clause cut, and its authority is `Narrowing::clauses()`.
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
            // Written out by hand and never derived from `LogicalOperator::cases()`.
            // The enum carries a third case, `Not`, which warden refuses on the
            // way in and on the way out while a hand-built group still reads it
            // as a conjunction — and its 2.2.2 states that closing that needs a
            // published signature to move, so it cannot land in 2.x. Deriving
            // this list would offer an operator no save can accept. If the two
            // are ever aligned, align them the other way: write the operators
            // out too.
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
