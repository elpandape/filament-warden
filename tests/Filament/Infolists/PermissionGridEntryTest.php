<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Infolists\PermissionGridEntry;
use ElPandaPe\FilamentWarden\Tests\TestCase;

pest()->extend(TestCase::class);

test('a grid that only reads can never report itself as a control', function (): void {
    $entry = PermissionGridEntry::make('permissions');

    expect(new ReflectionMethod($entry, 'gridInteracts')->invoke($entry))->toBeFalse();
});
