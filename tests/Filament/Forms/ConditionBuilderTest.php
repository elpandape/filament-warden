<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Conditions\Shape;
use ElPandaPe\FilamentWarden\Filament\Forms\ConditionBuilder;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire\ConditionHost;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Comment;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Constraints\ConstraintSerializer;
use ElPandaPe\Warden\Constraints\Group;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;

use function Pest\Livewire\livewire;

pest()->extend(TestCase::class);

function builderFor(?string $entity): ConditionBuilder
{
    return ConditionBuilder::make('options')->entity($entity);
}

test('the columns a condition may name are the columns of the entity', function (): void {
    $source = builderFor(Post::class)->getSource();

    expect($source['model'])->toBe(Post::class)
        ->and($source['columns'])->toContain('title')
        ->and($source['authority'])->toContain('email');
});

test('a permission with no model offers nothing to compare', function (): void {
    $source = builderFor(null)->getSource();

    expect($source['model'])->toBeNull()
        ->and($source['columns'])->toBeEmpty()
        ->and($source['ownership']['available'])->toBeFalse();
});

test('an entity that is not a model is no entity at all', function (): void {
    expect(builderFor('not-a-class')->getEntity())->toBeNull();
});

test('the entity is worked out when the screen asks, because it changes live', function (): void {
    $field = ConditionBuilder::make('options')->entity(fn (): string => Comment::class);

    expect($field->getEntity())->toBe(Comment::class)
        ->and($field->getSource()['columns'])->toContain('user_id');
});

test('ownership is offered only where it could resolve', function (): void {
    expect(builderFor(Comment::class)->getSource()['ownership']['available'])->toBeTrue()
        ->and(builderFor(Post::class)->getSource()['ownership']['available'])->toBeFalse();
});

test('every word the browser says arrives already written', function (): void {
    $words = builderFor(Post::class)->getWords();

    expect($words['operators'])->toBe(['=', '!=', '<', '>', '<=', '>='])
        ->and($words['joiners'])->toHaveKeys(['and', 'or'])
        ->and($words['authority'])->toBeString();
});

test('what the browser drew is checked against the table before it is a rule', function (): void {
    $field = builderFor(Post::class);

    $good = ['mode' => 'conditions', 'rules' => [
        ['logic' => 'and', 'kind' => 'value', 'column' => 'title', 'operator' => '=', 'value' => 'a'],
    ]];

    $bad = ['mode' => 'conditions', 'rules' => [
        ['logic' => 'and', 'kind' => 'value', 'column' => 'nope', 'operator' => '=', 'value' => 'a'],
    ]];

    expect($field->narrowing($good)?->shape)->toBe(Shape::Conditions)
        ->and($field->narrowing($bad))->toBeNull();
});

test('a rule with no model behind it is refused, whatever it says', function (): void {
    $payload = ['mode' => 'conditions', 'rules' => [
        ['logic' => 'and', 'kind' => 'value', 'column' => 'title', 'operator' => '=', 'value' => 'a'],
    ]];

    expect(builderFor(null)->narrowing($payload))->toBeNull();
});

test('an empty list is every row again, and writes no group', function (): void {
    $narrowing = builderFor(Post::class)->narrowing(['mode' => 'all', 'rules' => []]);

    expect($narrowing?->shape)->toBe(Shape::All)
        ->and($narrowing?->toGroup())->toBeNull();
});

test('a rule becomes the group warden evaluates, in the order it was drawn', function (): void {
    $payload = ['mode' => 'conditions', 'rules' => [
        ['logic' => 'and', 'kind' => 'value', 'column' => 'title', 'operator' => '=', 'value' => 'a'],
        ['logic' => 'or', 'kind' => 'value', 'column' => 'id', 'operator' => '>=', 'value' => '2'],
    ]];

    $group = builderFor(Post::class)->narrowing($payload)?->toGroup();

    expect($group?->items)->toHaveCount(2)
        ->and($group?->items[1][0]->value)->toBe('or')
        ->and($group?->items[1][1]->toArray()['v'])->toBe(2);
});

test('a comparison against the account becomes warden own column constraint', function (): void {
    $payload = ['mode' => 'conditions', 'rules' => [
        ['logic' => 'and', 'kind' => 'column', 'column' => 'user_id', 'operator' => '=', 'authority' => 'id'],
    ]];

    $group = builderFor(Comment::class)->narrowing($payload)?->toGroup();

    expect($group?->items[0][1]->toArray()['t'])->toBe('column')
        ->and($group?->items[0][1]->toArray()['a'])->toBe('id');
});

test('the field fills itself from the row it is editing', function (): void {
    $permission = makePermission('update');

    Warden::allow(makeRole())->to('update', Post::class)->where('title', 'alpha');

    $twin = Context::resolve()->permissionClass()::query()
        ->withoutGlobalScopes()
        ->whereNotNull('options')
        ->firstOrFail();

    livewire(ConditionHost::class, ['permissionKey' => $twin->getKey(), 'entity' => Post::class])
        ->assertSet('data.options.mode', 'conditions')
        ->assertSet('data.options.rules.0.column', 'title');

    expect($permission->getAttribute('options'))->toBeNull();
});

test('a row with no conditions opens on every row', function (): void {
    $permission = makePermission('update');

    livewire(ConditionHost::class, ['permissionKey' => $permission->getKey(), 'entity' => Post::class])
        ->assertSet('data.options.mode', 'all');
});

test('saving writes the group through the serializer, never by hand', function (): void {
    $permission = makePermission('update');

    livewire(ConditionHost::class, ['permissionKey' => $permission->getKey(), 'entity' => Post::class])
        ->fillForm(['options' => ['mode' => 'conditions', 'rules' => [
            ['logic' => 'and', 'kind' => 'value', 'column' => 'title', 'operator' => '=', 'value' => 'alpha'],
        ]]])
        ->call('save');

    $stored = $permission->refresh()->getAttribute('options');

    expect($stored)->toBeArray()
        ->and(partOf(is_array($stored) ? $stored : [], 'g'))->toHaveKey('t')
        ->and(ConstraintSerializer::deserialize($stored))->toBeInstanceOf(Group::class);
});

test('clearing the conditions writes nothing rather than an empty group', function (): void {
    $permission = makePermission('update');

    Warden::allow(makeRole())->to('update', Post::class)->where('title', 'alpha');

    livewire(ConditionHost::class, ['permissionKey' => $permission->getKey(), 'entity' => Post::class])
        ->fillForm(['options' => ['mode' => 'all', 'rules' => []]])
        ->call('save');

    expect($permission->refresh()->getAttribute('options'))->toBeNull();
});

test('a row with no model draws no builder at all', function (): void {
    $permission = makePermission('export-reports');

    livewire(ConditionHost::class, ['permissionKey' => $permission->getKey()])
        ->assertDontSee('fw-conditions', escape: false);
});
