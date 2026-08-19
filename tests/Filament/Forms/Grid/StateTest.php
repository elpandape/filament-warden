<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Forms\Grid\State;
use ElPandaPe\FilamentWarden\Tests\TestCase;

pest()->extend(TestCase::class);

test('what the role says arrives as it was written', function (): void {
    expect(State::normalize(['posts' => ['view' => 'granted', 'delete' => 'forbidden']]))
        ->toBe(['posts' => ['view' => 'granted', 'delete' => 'forbidden']]);
});

test('the three shapes of an empty field all mean the role says nothing', function (mixed $empty): void {
    expect(State::normalize($empty))->toBeEmpty();
})->with([[null], [[]], ['']]);

test('a row that is not a row is dropped', function (): void {
    expect(State::normalize(['posts' => 'granted', 'tags' => ['view' => 'granted']]))
        ->toBe(['tags' => ['view' => 'granted']]);
});

test('a stance nobody could have written is dropped, abstaining included', function (): void {
    expect(State::normalize(['posts' => ['view' => 'abstain', 'delete' => 'maybe', 'edit' => 'granted']]))
        ->toBe(['posts' => ['edit' => 'granted']]);
});
