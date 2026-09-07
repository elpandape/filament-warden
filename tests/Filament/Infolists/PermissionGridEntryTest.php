<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Infolists\PermissionGridEntry;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\ViewRole;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;

use function Pest\Livewire\livewire;

pest()->extend(TestCase::class);

test('a grid that only reads can never report itself as a control', function (): void {
    $entry = PermissionGridEntry::make('permissions');

    expect(new ReflectionMethod($entry, 'gridInteracts')->invoke($entry))->toBeFalse();
});

test('the read-only host has no baseline, so its cells read live and never as an unsaved move', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);
    Warden::allow($role)->to('viewAny', roleClass());

    $html = livewire(ViewRole::class, ['record' => $role->getKey()])->html();

    // The setup this test is about: a role with a granted cell, read
    // through the infolist host. `ViewRoleTest.php`'s 'only the form
    // carries a baseline' already pins that this host's own payload is
    // `{stances, narrowing}` with no third key — the precondition that
    // makes `storedStance()`'s and `moved()`'s baseline-absent branch the
    // one that runs every time a cell here is opened.
    expect($html)->toContain('data-state="granted"');

    // Pest never runs Alpine, so what makes that branch matter is pinned
    // by its source and not by rendering it: reverting either guard
    // changes nothing in the HTML above — only in this file, and only
    // here.
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/permission-grid.js');

    expect($script)->toContain(
        "if (this.state.baseline === undefined) {\n".
        "                return this.stanceOf(row, action)\n".
        '            }',
    )
        ->and($script)->toContain('this.selected === null || this.state.baseline === undefined');
});
