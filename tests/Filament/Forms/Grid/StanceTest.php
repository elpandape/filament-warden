<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
use ElPandaPe\FilamentWarden\Tests\TestCase;

pest()->extend(TestCase::class);

test('a click walks the cycle forward', function (Stance $from, Stance $to): void {
    expect($from->next())->toBe($to);
})->with([
    [Stance::Abstain, Stance::Granted],
    [Stance::Granted, Stance::Forbidden],
    [Stance::Forbidden, Stance::Abstain],
]);

test('shift walks it backward, so a denial is one step away', function (Stance $from, Stance $to): void {
    expect($from->previous())->toBe($to);
})->with([
    [Stance::Abstain, Stance::Forbidden],
    [Stance::Forbidden, Stance::Granted],
    [Stance::Granted, Stance::Abstain],
]);

test('the order handed to the browser is the order php walks', function (): void {
    expect(Stance::order())->toBe(['abstain', 'granted', 'forbidden']);
});

test('abstaining is the one stance the store never holds', function (): void {
    expect(Stance::Abstain->isWritten())->toBeFalse()
        ->and(Stance::Granted->isWritten())->toBeTrue()
        ->and(Stance::Forbidden->isWritten())->toBeTrue();
});
