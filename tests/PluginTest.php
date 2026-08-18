<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\FilamentWardenPlugin;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Panel;

pest()->extend(TestCase::class);

test('the plugin answers to the id a panel files it under', function (): void {
    expect(FilamentWardenPlugin::make()->getId())->toBe('filament-warden')
        ->and(Filament::getPanel('test')->getPlugin('filament-warden'))
        ->toBeInstanceOf(FilamentWardenPlugin::class);
});

test('registering the plugin does not break a panel', function (): void {
    $panel = Panel::make()->id('scratch');

    $panel->plugin(FilamentWardenPlugin::make());

    expect($panel->getPlugin('filament-warden'))->toBeInstanceOf(FilamentWardenPlugin::class);
});

test('this version registers no screens at all', function (): void {
    $panel = Panel::make()->id('scratch');

    $panel->plugin(FilamentWardenPlugin::make());

    expect($panel->getResources())->toBeEmpty()
        ->and($panel->getPages())->toBeEmpty()
        ->and($panel->getWidgets())->toBeEmpty();
});

test('booting the plugin leaves the panel exactly as it found it', function (): void {
    $panel = Panel::make()->id('scratch');

    $panel->plugin(FilamentWardenPlugin::make());

    $resources = $panel->getResources();
    $plugins = $panel->getPlugins();

    $panel->getPlugin('filament-warden')->boot($panel);

    expect($panel->getResources())->toBe($resources)
        ->and($panel->getPlugins())->toBe($plugins);
});

test('the plugin is built through the container, so an application can put its own in its place', function (): void {
    $substitute = new FilamentWardenPlugin;

    expect(FilamentWardenPlugin::make())->not->toBe($substitute);

    $this->app->instance(FilamentWardenPlugin::class, $substitute);

    expect(FilamentWardenPlugin::make())->toBe($substitute);
});

test('a panel that never took the plugin does not answer for it', function (): void {
    expect(Filament::getPanel('bare')->getPlugins())->not->toHaveKey('filament-warden');
});
