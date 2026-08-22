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

/**
 * @return list<string>
 */
function referencedKeys(): array
{
    $root = dirname(__DIR__);
    $files = [];

    foreach (['src', 'resources'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = (string) $file->getRealPath();
            }
        }
    }

    $referenced = [];

    foreach ($files as $file) {
        preg_match_all('/filament-warden::ui\.([a-zA-Z0-9_.\-]*)/', (string) file_get_contents($file), $matches);

        foreach ($matches[1] as $key) {
            $referenced[] = $key;
        }
    }

    return array_values(array_unique($referenced));
}

test('no line is declared that nothing reads', function (): void {
    $referenced = referencedKeys();

    foreach (array_keys(translations('en')) as $key) {
        $read = in_array($key, $referenced, true);

        foreach ($referenced as $reference) {
            // A key composed at runtime — `ui.actions.` . $action — is referenced
            // by its prefix, and the catalogue decides the rest.
            $read = $read || (str_ends_with($reference, '.') && str_starts_with($key, $reference));
        }

        expect($read)->toBeTrue("[{$key}] is declared and nothing reads it");
    }
});

test('every shape a rule can take has a word, in both languages', function (): void {
    $shapes = array_map(
        static fn (ElPandaPe\FilamentWarden\Conditions\Shape $shape): string => $shape->value,
        ElPandaPe\FilamentWarden\Conditions\Shape::cases(),
    );

    foreach (['en', 'es'] as $locale) {
        $declared = [];

        foreach (array_keys(translations($locale)) as $key) {
            if (str_starts_with($key, 'reach.')) {
                $declared[] = mb_substr($key, mb_strlen('reach.'));
            }
        }

        sort($declared);
        sort($shapes);

        expect($declared)->toBe($shapes, "[{$locale}] the reach map and the Shape enum disagree");
    }
});

test('every stance a cell can take has a word too', function (): void {
    $stances = array_map(
        static fn (ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance $stance): string => $stance->value,
        ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance::cases(),
    );

    foreach (['en', 'es'] as $locale) {
        $declared = [];

        foreach (array_keys(translations($locale)) as $key) {
            if (str_starts_with($key, 'stances.')) {
                $declared[] = mb_substr($key, mb_strlen('stances.'));
            }
        }

        sort($declared);
        sort($stances);

        expect($declared)->toBe($stances, "[{$locale}] the stance map and the Stance enum disagree");
    }
});

test('every reason the builder can be locked with has a sentence, in both languages', function (): void {
    $form = (string) file_get_contents(dirname(__DIR__).'/src/Filament/Resources/Permissions/Schemas/PermissionForm.php');
    $narrowing = (string) file_get_contents(dirname(__DIR__).'/src/Conditions/Narrowing.php');

    preg_match_all("/return '([a-z_]+)';/", $form, $returned);
    preg_match_all("/\\?\\? '([a-z_]+)'/", $form, $fallback);
    preg_match_all("/unreadable\('([a-z_]+)'/", $narrowing, $unreadable);
    preg_match_all("/reason: '([a-z_]+)'/", $narrowing, $named);

    $reasons = array_values(array_unique([
        ...$returned[1], ...$fallback[1], ...$unreadable[1], ...$named[1],
    ]));

    sort($reasons);

    foreach (['en', 'es'] as $locale) {
        /** @var array<string, array<string, array<string, string>>> $lines */
        $lines = require dirname(__DIR__)."/lang/{$locale}/ui.php";
        $keys = array_keys($lines['conditions']['locked']);
        sort($keys);

        expect($keys)->toBe($reasons, "the {$locale} locked reasons and the ones the code can produce have drifted");
    }
});

test('no line fakes a plural with a parenthesis', function (): void {
    foreach (['en', 'es'] as $locale) {
        foreach (translations($locale) as $key => $line) {
            expect($line)->not->toMatch('/\w\(e?s\)/', "[{$locale}] {$key} fakes a plural");
        }
    }
});

test('the two sentences about a class check say it the same way', function (): void {
    $closings = ['en' => 'fails closed', 'es' => 'falla cerrada'];

    foreach ($closings as $locale => $closing) {
        $lines = translations($locale);

        expect($lines['conditions.warning'])->toContain($closing)
            ->and($lines['explain.narrowed'])->toContain($closing);
    }
});
