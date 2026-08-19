<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Conditions\Value;
use ElPandaPe\FilamentWarden\Tests\TestCase;

pest()->extend(TestCase::class);

test('a whole number is stored as one, and a decimal as one', function (): void {
    expect(Value::cast('2'))->toBe(2)
        ->and(Value::cast('-7'))->toBe(-7)
        ->and(Value::cast('2.5'))->toBe(2.5);
});

test('the two boolean words are the named exception', function (): void {
    expect(Value::cast('true'))->toBeTrue()
        ->and(Value::cast('false'))->toBeFalse();
});

test('a number that would not come back the same stays text', function (): void {
    expect(Value::cast('007'))->toBe('007')
        ->and(Value::cast('2.50'))->toBe('2.50')
        ->and(Value::cast('1e3'))->toBe('1e3')
        ->and(Value::cast(' 2'))->toBe(' 2');
});

test('anything that is not a number is text', function (): void {
    expect(Value::cast(''))->toBe('')
        ->and(Value::cast('published'))->toBe('published');
});

test('every value the builder writes comes back exactly as it was typed', function (string $typed): void {
    expect(Value::text(Value::cast($typed)))->toBe($typed);
})->with([['2'], ['-7'], ['2.5'], ['true'], ['false'], ['007'], ['2.50'], ['published'], ['']]);

test('a value that never came from the builder is refused instead of blanked', function (mixed $value): void {
    expect(Value::text($value))->toBeNull();
})->with([[null], [[]], [['a' => 1]]]);
