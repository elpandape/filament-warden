<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\PermissionName;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages\WidgetHost;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Widgets\GuardedWidget;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Widgets\Summary;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;
use Livewire\Finder\Finder;

use function Pest\Livewire\livewire;

pest()->extend(TestCase::class);

/**
 * A nested livewire component renders into its parent as a placeholder carrying
 * its own name, so the name is what a page's own render can be read for. It is
 * asked of livewire rather than spelled out, because the name is derived from
 * the class and would go stale the moment a fixture moved.
 */
function componentName(string $class): string
{
    $name = app(Finder::class)->normalizeName($class);

    return is_string($name) ? $name : '';
}

test('a guarded widget stays hidden from an authority the store never granted it', function (): void {
    signIn();

    expect(GuardedWidget::canView())->toBeFalse();
});

test('a guarded widget shows for the permission the catalogue offers for it', function (): void {
    $user = signIn();

    Warden::allow($user)->to(PermissionName::widget(GuardedWidget::class));

    expect(GuardedWidget::canView())->toBeTrue();
});

test('an explicit denial hides a widget that a grant had shown', function (): void {
    $user = signIn();

    Warden::allow($user)->to(PermissionName::widget(GuardedWidget::class));
    Warden::forbid($user)->to(PermissionName::widget(GuardedWidget::class));

    expect(GuardedWidget::canView())->toBeFalse();
});

test('a widget that never took the guard is the open door filament ships', function (): void {
    signIn();

    expect(Summary::canView())->toBeTrue();
});

test('the screen carrying both widgets is shut until the store opens it', function (): void {
    signIn();

    livewire(WidgetHost::class)->assertForbidden();
});

test('a page renders the widget the store allows and leaves out the one it does not', function (): void {
    $user = signIn();

    Warden::allow($user)->to(PermissionName::page(WidgetHost::class));

    livewire(WidgetHost::class)
        ->assertOk()
        ->assertSee('wire:name="'.componentName(Summary::class).'"', escape: false)
        ->assertDontSee('wire:name="'.componentName(GuardedWidget::class).'"', escape: false);

    Warden::allow($user)->to(PermissionName::widget(GuardedWidget::class));

    livewire(WidgetHost::class)
        ->assertSee('wire:name="'.componentName(GuardedWidget::class).'"', escape: false);
});
