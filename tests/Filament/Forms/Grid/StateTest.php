<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Forms\Grid\State;
use ElPandaPe\FilamentWarden\Tests\TestCase;

pest()->extend(TestCase::class);

test('what the role says arrives as it was written', function (): void {
    expect(State::stances(['stances' => ['posts' => ['view' => 'granted', 'delete' => 'forbidden']]]))
        ->toBe(['posts' => ['view' => 'granted', 'delete' => 'forbidden']]);
});

test('the three shapes of an empty field all mean the role says nothing', function (mixed $empty): void {
    expect(State::stances($empty))->toBeEmpty()
        ->and(State::narrowings($empty))->toBeEmpty();
})->with([[null], [[]], ['']]);

test('a row that is not a row is dropped', function (): void {
    expect(State::stances(['stances' => ['posts' => 'granted', 'tags' => ['view' => 'granted']]]))
        ->toBe(['tags' => ['view' => 'granted']]);
});

test('a stance nobody could have written is dropped, abstaining included', function (): void {
    expect(State::stances(['stances' => ['posts' => ['view' => 'abstain', 'delete' => 'maybe', 'edit' => 'granted']]]))
        ->toBe(['posts' => ['edit' => 'granted']]);
});

test('how far a cell reaches travels beside what it says', function (): void {
    $state = ['narrowing' => ['posts' => ['view' => ['mode' => 'owned', 'rules' => []]]]];

    expect(State::narrowings($state))
        ->toBe(['posts' => ['view' => ['mode' => 'owned', 'rules' => []]]]);
});

test('a reach filed under something that is not a cell is dropped', function (): void {
    $state = ['narrowing' => ['posts' => 'owned', 'tags' => [3 => ['mode' => 'owned'], 'view' => ['mode' => 'owned']]]];

    expect(State::narrowings($state))->toBe(['tags' => ['view' => ['mode' => 'owned']]]);
});
