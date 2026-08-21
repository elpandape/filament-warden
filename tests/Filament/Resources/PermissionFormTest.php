<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Conditions\Shape;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\CreatePermission;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\EditPermission;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Comment;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;
use Illuminate\Database\Eloquent\Model;

use function Pest\Livewire\livewire;

pest()->extend(TestCase::class);

function signInToEditPermissions(): void
{
    $user = signIn();

    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());
}

/**
 * The row a fluent chain just wrote, read back by the name it carries.
 */
function narrowedRow(string $name): Model
{
    return permissionClass()::query()
        ->withoutGlobalScopes()
        ->where('name', $name)
        ->orderByDesc('id')
        ->firstOrFail();
}

test('a stored rule that cannot be read survives a save that only touched the title', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    signInToEditPermissions();

    Warden::allow(makeRole())->to('publish', Post::class)->where('title', '=', 'alpha');

    $permission = narrowedRow('publish');
    $permission->update(['options' => ['v' => 42, 'g' => ['t' => 'group', 'i' => []]]]);

    $before = $permission->refresh()->getAttribute('options');

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->assertFormFieldDisabled('options')
        ->assertSee('The stored conditions cannot be read')
        ->fillForm(['title' => 'Publish a post'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($permission->refresh()->getAttribute('options'))->toBe($before);
});

test('a stored rule naming a column the table no longer has is left alone too', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    signInToEditPermissions();

    Warden::allow(makeRole())->to('publish', Post::class)->where('subtitle', '=', 'alpha');

    $permission = narrowedRow('publish');
    $before = $permission->getAttribute('options');

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->assertFormFieldDisabled('options')
        ->assertSee('a shape this builder cannot draw')
        ->fillForm(['title' => 'Publish a post'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($permission->refresh()->getAttribute('options'))->toBe($before);
});

test('a rule with no model behind it is left alone by the screen that cannot check its columns', function (): void {
    signInToEditPermissions();

    Warden::allow(makeRole())->to('export-reports')->where('id', '=', 1);

    $permission = narrowedRow('export-reports');
    $before = $permission->getAttribute('options');

    expect($permission->getAttribute('entity_type'))->toBeNull()
        ->and($before)->not->toBeNull();

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->assertFormFieldDisabled('options')
        ->assertSee('A condition on it would be stored, shown, and would never grant anything')
        ->fillForm(['title' => 'Export the reports'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($permission->refresh()->getAttribute('options'))->toBe($before);
});

test('a rule that is both ownership and conditions keeps both halves through a save', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    signInToEditPermissions();

    Warden::allow(makeRole())->toOwn(Comment::class, 'moderate')->where('body', '=', 'alpha');

    $permission = narrowedRow('moderate');
    $before = $permission->getAttribute('options');

    expect($permission->getAttribute('only_owned'))->toBeTrue()
        ->and($before)->not->toBeNull();

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->assertFormFieldDisabled('options')
        ->fillForm(['title' => 'Moderate a comment'])
        ->call('save')
        ->assertHasNoFormErrors();

    $permission->refresh();

    expect($permission->getAttribute('options'))->toBe($before)
        ->and($permission->getAttribute('only_owned'))->toBeTrue();
});

test('a rule this screen can read back is still edited from it', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    signInToEditPermissions();

    Warden::allow(makeRole())->to('publish', Post::class)->where('title', '=', 'alpha');

    $permission = narrowedRow('publish');

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->assertFormFieldEnabled('options')
        ->fillForm(['options' => ['mode' => 'conditions', 'rules' => [
            ['logic' => 'and', 'kind' => 'value', 'column' => 'title', 'operator' => '=', 'value' => 'beta'],
        ]]])
        ->call('save')
        ->assertHasNoFormErrors();

    $narrowing = Narrowing::of($permission->refresh());

    expect($narrowing->shape)->toBe(Shape::Conditions)
        ->and($narrowing->rules[0]->value)->toBe('beta');
});

test('a form with nothing stored yet leaves the builder open', function (): void {
    config()->set('filament-warden.permissions.create', true);
    config()->set('filament-warden.catalog.models', [Post::class]);

    $user = signIn();

    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('create', permissionClass());

    livewire(CreatePermission::class)
        ->assertFormFieldEnabled('options')
        ->fillForm([
            'name' => 'publish',
            'entity_type' => new Post()->getMorphClass(),
            'options' => ['mode' => 'conditions', 'rules' => [
                ['logic' => 'and', 'kind' => 'value', 'column' => 'title', 'operator' => '=', 'value' => 'alpha'],
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Narrowing::of(narrowedRow('publish'))->rules[0]->value)->toBe('alpha');
});

test('a loose row somebody holds says the holder is what locked its name', function (): void {
    config()->set('filament-warden.permissions.update', 'loose');

    signInToEditPermissions();

    Warden::allow(makeRole('one'))->to('export');

    livewire(EditPermission::class, ['record' => narrowedRow('export')->getKey()])
        ->assertFormFieldDisabled('name')
        ->assertFormFieldDisabled('entity_type')
        ->assertSee('Somebody holds this row')
        ->assertDontSee('It is what can() will ask for');
});

test('a loose row nobody holds keeps the sentence that says the name is yours', function (): void {
    config()->set('filament-warden.permissions.update', 'loose');

    signInToEditPermissions();

    livewire(EditPermission::class, ['record' => makePermission('archive')->getKey()])
        ->assertFormFieldEnabled('name')
        ->assertSee('It is what can() will ask for')
        ->assertDontSee('Somebody holds this row');
});

test('a derived row locked by the rule is not told a holder did it', function (): void {
    config()->set('filament-warden.permissions.update', 'loose');
    config()->set('filament-warden.catalog.models', [Post::class]);

    signInToEditPermissions();

    $derived = makePermission('publish');
    $derived->update(['entity_type' => new Post()->getMorphClass()]);

    Warden::allow(makeRole('one'))->to('publish', Post::class);

    livewire(EditPermission::class, ['record' => $derived->getKey()])
        ->assertFormFieldDisabled('name')
        ->assertSee('The policy method that declares it writes this')
        ->assertDontSee('Somebody holds this row');
});

test('a rule that locks every name says nothing about holders either', function (): void {
    config()->set('filament-warden.permissions.update', 'title');

    signInToEditPermissions();

    Warden::allow(makeRole('one'))->to('export');

    livewire(EditPermission::class, ['record' => narrowedRow('export')->getKey()])
        ->assertFormFieldDisabled('name')
        ->assertDontSee('Somebody holds this row');
});
