<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Conditions\Rule;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Constraints\ColumnConstraint;
use ElPandaPe\Warden\Constraints\Group;
use ElPandaPe\Warden\Constraints\ValueConstraint;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Enums\ComparisonOperator;
use ElPandaPe\Warden\Enums\LogicalOperator;
use ElPandaPe\Warden\Facades\Warden;

pest()->extend(TestCase::class);

/**
 * What the store ended up holding, read off the twin that carries it.
 *
 * @return list<array{0: string, 1: array<string, mixed>}>
 */
function writtenConstraints(): array
{
    $permission = Context::resolve()->permissionClass()::query()
        ->withoutGlobalScopes()
        ->whereNotNull('options')
        ->orderByDesc('id')
        ->firstOrFail();

    /** @var array{g: array{i: list<array{0: string, 1: array<string, mixed>}>}} $options */
    $options = $permission->getAttribute('options');

    return $options['g']['i'];
}

test('a nested group is read as nothing at all, because this builder cannot draw one', function (): void {
    expect(Rule::of(LogicalOperator::And, new Group([])))->toBeNull();
});

test('a value that is not a scalar is read as nothing at all', function (): void {
    $constraint = new ValueConstraint('id', ComparisonOperator::Equal, null);

    expect(Rule::of(LogicalOperator::And, $constraint))->toBeNull();
});

test('a comparison against the account is read whole', function (): void {
    $rule = Rule::of(LogicalOperator::Or, new ColumnConstraint('user_id', ComparisonOperator::Equal, 'id'));

    expect($rule?->comparesColumns())->toBeTrue()
        ->and($rule?->authorityColumn)->toBe('id')
        ->and($rule?->logic)->toBe(LogicalOperator::Or);
});

test('a line naming a column the table does not have is refused', function (): void {
    $payload = ['logic' => 'and', 'kind' => 'value', 'column' => 'nope', 'operator' => '=', 'value' => '1'];

    expect(Rule::fromPayload($payload, ['id', 'title'], ['id']))->toBeNull();
});

test('a line with an operator warden does not know is refused', function (): void {
    $payload = ['logic' => 'and', 'kind' => 'value', 'column' => 'id', 'operator' => 'like', 'value' => '1'];

    expect(Rule::fromPayload($payload, ['id'], ['id']))->toBeNull();
});

test('a line joined with not is refused, because the serializer would drop the whole group', function (): void {
    $payload = ['logic' => 'not', 'kind' => 'value', 'column' => 'id', 'operator' => '=', 'value' => '1'];

    expect(Rule::fromPayload($payload, ['id'], ['id']))->toBeNull();
});

test('a line comparing against a column the account does not have is refused', function (): void {
    $payload = ['logic' => 'and', 'kind' => 'column', 'column' => 'id', 'operator' => '=', 'authority' => 'nope'];

    expect(Rule::fromPayload($payload, ['id'], ['id', 'email']))->toBeNull();
});

test('a line that is not even a list of fields is refused', function (): void {
    expect(Rule::fromPayload('nope', ['id'], ['id']))->toBeNull();
});

test('a line the browser sends whole is read whole, and its value typed', function (): void {
    $payload = ['logic' => 'or', 'kind' => 'value', 'column' => 'id', 'operator' => '>=', 'value' => '2'];

    $rule = Rule::fromPayload($payload, ['id'], ['id']);

    expect($rule?->logic)->toBe(LogicalOperator::Or)
        ->and($rule?->operator)->toBe(ComparisonOperator::GreaterThanOrEqual)
        ->and($rule?->value)->toBe(2);
});

test('a line comparing against the account is read whole from the browser too', function (): void {
    $payload = ['logic' => 'and', 'kind' => 'column', 'column' => 'user_id', 'operator' => '=', 'authority' => 'id'];

    $rule = Rule::fromPayload($payload, ['user_id'], ['id', 'email']);

    expect($rule?->comparesColumns())->toBeTrue()
        ->and($rule?->authorityColumn)->toBe('id')
        ->and($rule?->column)->toBe('user_id');
});

test('the operator reaches the store, which two arguments would have swallowed', function (): void {
    $role = makeRole();
    $chain = Warden::allow($role)->to('update', Post::class);

    new Rule(LogicalOperator::And, 'id', ComparisonOperator::GreaterThanOrEqual, 2)->applyTo($chain, true);

    expect(writtenConstraints()[0][1]['o'])->toBe('>=')
        ->and(writtenConstraints()[0][1]['v'])->toBe(2);
});

test('a second line joined with or reaches the store joined with or', function (): void {
    $role = makeRole();
    $chain = Warden::allow($role)->to('update', Post::class);

    new Rule(LogicalOperator::And, 'id', ComparisonOperator::Equal, 1)->applyTo($chain, true);
    new Rule(LogicalOperator::Or, 'title', ComparisonOperator::Equal, 'draft')->applyTo($chain, false);

    expect(writtenConstraints()[1][0])->toBe('or');
});

test('the first line is joined with and however it arrives, because evaluating ignores it', function (): void {
    $role = makeRole();
    $chain = Warden::allow($role)->to('update', Post::class);

    new Rule(LogicalOperator::Or, 'id', ComparisonOperator::Equal, 1)->applyTo($chain, true);

    expect(writtenConstraints()[0][0])->toBe('and');
});

test('a comparison against the account reaches the store as one', function (): void {
    $role = makeRole();
    $chain = Warden::allow($role)->to('update', Post::class);

    new Rule(LogicalOperator::And, 'id', ComparisonOperator::Equal, '', 'id')->applyTo($chain, true);

    expect(writtenConstraints()[0][1]['t'])->toBe('column')
        ->and(writtenConstraints()[0][1]['a'])->toBe('id');
});

test('a comparison against the account joined with or reaches the store joined with or', function (): void {
    $role = makeRole();
    $chain = Warden::allow($role)->to('update', Post::class);

    new Rule(LogicalOperator::And, 'id', ComparisonOperator::Equal, 1)->applyTo($chain, true);
    new Rule(LogicalOperator::Or, 'id', ComparisonOperator::Equal, '', 'id')->applyTo($chain, false);

    expect(writtenConstraints()[1][0])->toBe('or');
});

test('a line reads with the account named', function (): void {
    $rule = new Rule(LogicalOperator::And, 'user_id', ComparisonOperator::Equal, '', 'id');

    expect($rule->text('account'))->toBe('user_id = account.id');
});

test('a line comparing with a value reads with the value', function (): void {
    $rule = new Rule(LogicalOperator::And, 'title', ComparisonOperator::NotEqual, 'draft');

    expect($rule->text('account'))->toBe('title != draft');
});

test('the payload of a line carries every field the browser draws', function (): void {
    $rule = new Rule(LogicalOperator::And, 'id', ComparisonOperator::Equal, 2);

    expect($rule->toPayload())->toBe([
        'logic' => 'and',
        'kind' => 'value',
        'column' => 'id',
        'operator' => '=',
        'value' => '2',
        'authority' => '',
    ]);
});

test('a line that compares columns carries no value of its own', function (): void {
    $compared = new Rule(LogicalOperator::And, 'user_id', ComparisonOperator::Equal, '', 'id');

    expect($compared->toPayload())->toBe([
        'logic' => 'and',
        'kind' => 'column',
        'column' => 'user_id',
        'operator' => '=',
        'value' => '',
        'authority' => 'id',
    ]);
});
