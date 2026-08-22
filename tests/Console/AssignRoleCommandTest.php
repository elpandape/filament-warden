<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;

pest()->extend(TestCase::class);

function roleCount(): int
{
    return roleClass()::query()->count();
}

/**
 * The command's exit code. `artisan()` answers `PendingCommand|int` and only the
 * second half of that can be typed here.
 */
function handOut(string $role, string $authority): int
{
    return Artisan::call('filament-warden:assign', ['role' => $role, 'authority' => $authority]);
}

test('the way back hands a role out, and the store answers for it straight away', function (): void {
    $account = makeUser('Locked Out');
    $role = makeRole('super-admin');

    Warden::allow($role)->to('viewAny', Post::class);

    expect(Access::granted($account, 'viewAny', Post::class))->toBeFalse()
        ->and(handOut('super-admin', User::class.':'.recordKey($account)))->toBe(0)
        ->and(Access::granted($account, 'viewAny', Post::class))->toBeTrue();
});

test('the recipe the readme prints opens the panel door it promises to open', function (): void {
    $account = makeUser('Locked Out');
    $role = makeRole('super-admin');

    Warden::allow($role)->everything();

    expect($account->canAccessPanel(Filament::getPanel('test')))->toBeFalse()
        ->and(handOut('super-admin', User::class.':'.recordKey($account)))->toBe(0)
        ->and($account->canAccessPanel(Filament::getPanel('test')))->toBeTrue()
        ->and(Access::granted($account, 'viewAny', roleClass()))->toBeTrue();
});

test('a role that does not exist is refused, never invented', function (): void {
    $account = makeUser();

    $before = roleCount();

    expect(handOut('super-admn', User::class.':'.recordKey($account)))->toBe(1)
        ->and(roleCount())->toBe($before);
});

test('an authority that cannot be read is refused rather than guessed', function (string $reference): void {
    makeRole('editor');

    expect(handOut('editor', $reference))->toBe(1);
})->with([
    'no key' => ['App\\Models\\User'],
    'not a model' => ['stdClass:1'],
    'no such row' => [User::class.':9999'],
    'empty key' => [User::class.':'],
]);

test('handing the same role out twice writes it once', function (): void {
    $account = makeUser();
    makeRole('editor');

    foreach ([1, 2] as $ignored) {
        expect(handOut('editor', User::class.':'.recordKey($account)))->toBe(0);
    }

    expect(ElPandaPe\Warden\Context::resolve()->assignedRoleClass()::query()->count())->toBe(1);
});

test('the command is registered, so it can be reached at all', function (): void {
    expect(array_keys(app(Illuminate\Contracts\Console\Kernel::class)->all()))
        ->toContain('filament-warden:assign');
});
