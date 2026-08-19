<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Scope;
use ElPandaPe\FilamentWarden\Tests\TestCase;

pest()->extend(TestCase::class);

test('an action the scope map names lands in the scope that names it', function (string $action, Scope $scope): void {
    expect(Scope::forAction($action))->toBe($scope);
})->with([
    ['viewAny', Scope::Read],
    ['view', Scope::Read],
    ['create', Scope::Write],
    ['update', Scope::Write],
    ['delete', Scope::Withdraw],
    ['restore', Scope::Withdraw],
    ['forceDelete', Scope::Irreversible],
]);

test('an action nobody classified counts as a write', function (): void {
    expect(Scope::forAction('replicate'))->toBe(Scope::Write);
});

test('a scope the enum does not know counts as a write too', function (): void {
    config()->set('filament-warden.catalog.scopes', ['catastrophic' => ['nuke']]);

    expect(Scope::forAction('nuke'))->toBe(Scope::Write);
});
