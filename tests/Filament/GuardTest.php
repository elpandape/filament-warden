<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Exceptions\PanelIsOpen;
use ElPandaPe\FilamentWarden\Filament\Guard;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages\GuardedPage;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages\InheritsGuard;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages\Reports;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Widgets\GuardedWidget;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Widgets\Summary;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use Filament\Pages\Dashboard;
use Filament\Panel;

pest()->extend(TestCase::class);

/**
 * @param  array<int, class-string>  $pages
 * @param  array<int, class-string<Filament\Widgets\Widget>>  $widgets
 */
function panelWith(array $pages = [], array $widgets = []): Panel
{
    return Panel::make()->id('scratch')->pages($pages)->widgets($widgets);
}

test('a page that decides nothing is named', function (): void {
    expect(Guard::unguarded(panelWith([Reports::class])))->toBe([Reports::class]);
});

test('a page that decides is not', function (): void {
    expect(Guard::unguarded(panelWith([GuardedPage::class])))->toBeEmpty();
});

test('a decision inherited from the application own base counts as a decision', function (): void {
    expect(Guard::unguarded(panelWith([InheritsGuard::class])))->toBeEmpty();
});

test('a screen filament ships is filament own business', function (): void {
    expect(Guard::unguarded(panelWith([Dashboard::class])))->toBeEmpty();
});

test('a widget that decides nothing is named, and one that decides is not', function (): void {
    expect(Guard::unguarded(panelWith([], [Summary::class])))->toBe([Summary::class])
        ->and(Guard::unguarded(panelWith([], [GuardedWidget::class])))->toBeEmpty();
});

test('the panel does not start, and the message says which screen', function (): void {
    $panel = panelWith([Reports::class]);

    expect(static function () use ($panel): void {
        Guard::enforce($panel);
    })->toThrow(PanelIsOpen::class, Reports::class);
});

test('a panel where every screen decides starts', function (): void {
    $panel = panelWith([GuardedPage::class], [GuardedWidget::class]);

    Guard::enforce($panel);

    expect(Guard::unguarded($panel))->toBeEmpty();
});

test('an installation can turn each half off, and an absent key cannot', function (): void {
    config()->set('filament-warden.guard.pages', false);

    expect(Guard::unguarded(panelWith([Reports::class], [Summary::class])))->toBe([Summary::class]);

    config()->set('filament-warden.guard.widgets', false);

    expect(Guard::unguarded(panelWith([Reports::class], [Summary::class])))->toBeEmpty();

    // Every key of this package is ABSENT under `config:cache` without a
    // published file, and absent must not read as an open door.
    config()->set('filament-warden', []);

    expect(Guard::unguarded(panelWith([Reports::class])))->toBe([Reports::class]);
});

test('the plugin arms the guard, so a panel it is on cannot start open', function (): void {
    $panel = Panel::make()->id('scratch')->pages([Reports::class]);

    $panel->plugin(ElPandaPe\FilamentWarden\FilamentWardenPlugin::make());

    expect(static function () use ($panel): void {
        $panel->boot();
    })->toThrow(PanelIsOpen::class);
});

test('a screen with no such method at all decides nothing either', function (): void {
    $panel = Panel::make()->id('scratch')->pages([ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages\NotAPage::class]);

    expect(Guard::unguarded($panel))->toBe([ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages\NotAPage::class]);
});
