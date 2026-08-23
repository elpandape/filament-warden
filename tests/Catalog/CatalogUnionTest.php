<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Panel;

pest()->extend(TestCase::class);

/**
 * The memo is keyed on the panel objects themselves and never on their ids:
 * this suite builds many different panels under the same id, so an id-keyed
 * memo would serve one panel's catalogue for another's. The identity check is
 * what protects it; `forget()` only bounds how many entries a long-lived
 * process can pile up.
 */
test('the union carries what either panel declares', function (): void {
    $first = Filament::getPanel('test');
    $second = Filament::getPanel('bare');

    $union = Catalog::union([$first, $second]);

    $keys = array_map(static fn (object $entry): string => $entry->key(), $union->entries);

    expect($keys)->toContain('panel:test|')
        ->and($keys)->toContain('panel:bare|');
});

test('the union deduplicates what both panels declare', function (): void {
    $panel = Filament::getPanel('test');

    $once = Catalog::for($panel)->entries;
    $twice = Catalog::union([$panel, $panel])->entries;

    expect($twice)->toHaveSameSize($once);
});

test('the union memo answers about the panels it was given, not their ids', function (): void {
    $first = Panel::make()->id('scratch')->path('scratch-one');
    $second = Panel::make()->id('scratch')->path('scratch-two');

    $one = Catalog::union([$first]);
    $two = Catalog::union([$second]);

    expect($one)->not->toBe($two)
        ->and(Catalog::union([$first]))->toBe($one);
});
