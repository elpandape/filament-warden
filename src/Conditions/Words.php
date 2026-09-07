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
            // Written out by hand and never derived from `LogicalOperator::cases()`,
            // and warden 3.0 says so itself: the enum's own docblock now reads
            // "Do not derive a connector list from cases(): the third one is not
            // a connector anything will store."
            //
            // The reason changed with that release and got sharper, so the old
            // one is not worth keeping: `Not` used to read as a conjunction in a
            // hand-built group and be refused on the way to disk, which made a
            // derived list an operator no SAVE could accept. It now THROWS when
            // the group is evaluated. So a derived list would offer an operator
            // that saves fine and blows up on the next check — later, and worse.
            //
            // The 2.x note said closing this needed a published signature to
            // move and therefore could not land in a minor. It landed in 3.0
            // without moving one: `Contracts\Constraint::passes()` kept its
            // signature. Aligning the two lists is not the answer either way —
            // warden has ruled the third case out of any list, permanently.
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
