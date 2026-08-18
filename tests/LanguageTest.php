<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Tests\TestCase;
use Illuminate\Support\Arr;

pest()->extend(TestCase::class);

/**
 * @return array<string, string>
 */
function translations(string $locale): array
{
    /** @var array<string, mixed> $lines */
    $lines = require dirname(__DIR__)."/lang/{$locale}/ui.php";

    /** @var array<string, string> $flat */
    $flat = Arr::dot($lines);

    return $flat;
}

test('both languages declare exactly the same keys', function (): void {
    $english = array_keys(translations('en'));
    $spanish = array_keys(translations('es'));

    sort($english);
    sort($spanish);

    expect($spanish)->toBe($english);
});

test('no translation is left empty', function (): void {
    foreach (['en', 'es'] as $locale) {
        foreach (translations($locale) as $key => $line) {
            expect(mb_trim($line))->not->toBe('', "[{$locale}] {$key} is empty");
        }
    }
});

test('a line and its translation ask for the same placeholders', function (): void {
    $english = translations('en');

    foreach (translations('es') as $key => $line) {
        preg_match_all('/:\w+/', $english[$key], $expected);
        preg_match_all('/:\w+/', $line, $actual);

        sort($expected[0]);
        sort($actual[0]);

        expect($actual[0])->toBe($expected[0], "[{$key}] placeholders differ");
    }
});

test('the package answers under its own namespace, in both languages', function (): void {
    expect(trans('filament-warden::ui.navigation.group'))->toBe('Security');

    app()->setLocale('es');

    expect(trans('filament-warden::ui.navigation.group'))->toBe('Seguridad');
});
