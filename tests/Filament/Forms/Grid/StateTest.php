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

test('a date comes back as a moment, and only from a shape the screen could have sent', function (): void {
    $state = ['until' => ['posts' => ['view' => '2026-09-14T12:00:00+00:00']]];

    expect(State::untils($state)['posts']['view']->toIso8601String())->toStartWith('2026-09-14T12:00:00');
});

test('a date that will not parse is dropped rather than guessed at', function (): void {
    // The browser is handed ISO 8601 and hands it back. Anything else did not
    // come from this screen, and inventing a date for it is the one direction
    // that widens a grant's life.
    $state = ['until' => [
        'posts' => ['view' => 'next tuesday-ish', 'edit' => '', 'delete' => ['not' => 'a string']],
        42 => ['view' => '2026-09-14T12:00:00+00:00'],
        'tags' => 'not a map',
    ]];

    expect(State::untils($state))->toBeEmpty();
});

test('no date map at all is not the same answer as an empty one', function (): void {
    // Both come back `[]` from here, and the caller is what tells them apart:
    // `PermissionGrid::gridUntils()` answers null when the screen never offered
    // dates, and this map only when it did. Pinned so the two stay separate
    // questions — collapsing them ends every timed grant on the grid.
    expect(State::untils([]))->toBeEmpty()
        ->and(State::untils(null))->toBeEmpty()
        ->and(State::untils(['until' => []]))->toBeEmpty();
});
