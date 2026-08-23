<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
use ElPandaPe\FilamentWarden\Tests\TestCase;

pest()->extend(TestCase::class);

test('the cycle is declared once, and this is it', function (): void {
    // There was a `next()`/`previous()` pair spelling the same cycle out a
    // second time, in the same file, called by nothing but its own two tests.
    // `order()` is what travels to the browser, so it is the only one that can
    // disagree with anything.
    expect(Stance::order())->toBe(['abstain', 'granted', 'forbidden'])
        ->and(get_class_methods(Stance::class))->toBe(['order', 'isWritten', 'cases', 'from', 'tryFrom']);
});

test('abstaining is the one stance the store never holds', function (): void {
    expect(Stance::Abstain->isWritten())->toBeFalse()
        ->and(Stance::Granted->isWritten())->toBeTrue()
        ->and(Stance::Forbidden->isWritten())->toBeTrue();
});
