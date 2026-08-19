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
     * payload: the script reads this order, it never carries one of its own.
     * The generation before this one implemented the same rule twice, in two
     * languages, and the two could disagree without a test noticing.
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

    public function next(): self
    {
        return match ($this) {
            self::Abstain => self::Granted,
            self::Granted => self::Forbidden,
            self::Forbidden => self::Abstain,
        };
    }

    public function previous(): self
    {
        return match ($this) {
            self::Abstain => self::Forbidden,
            self::Forbidden => self::Granted,
            self::Granted => self::Abstain,
        };
    }

    /**
     * Only a written stance reaches the store.
     */
    public function isWritten(): bool
    {
        return $this !== self::Abstain;
    }
}
