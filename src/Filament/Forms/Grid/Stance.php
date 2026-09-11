<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms\Grid;

/**
 * What a role says about one permission. Abstaining is a stance too, and it is
 * the one that is never written to the store: it is the absence of a row.
 */
enum Stance: string
{
    case Abstain = 'abstain';

    case Granted = 'granted';

    case Forbidden = 'forbidden';

    /**
     * The cycle a cell walks on click, and backwards on shift-click.
     *
     * Declared once, here, and handed to the browser in the component's own
     * payload: the script reads this order and never carries one of its own,
     * because two copies of one cycle can disagree without a test noticing.
     *
     * @return list<string>
     */
    public static function order(): array
    {
        return array_map(
            static fn (self $stance): string => $stance->value,
            [self::Abstain, self::Granted, self::Forbidden],
        );
    }

    /**
     * Only a written stance reaches the store.
     */
    public function isWritten(): bool
    {
        return $this !== self::Abstain;
    }
}
