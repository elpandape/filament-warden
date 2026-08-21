<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Conditions\Ownership;
use ElPandaPe\FilamentWarden\Conditions\Rule;
use ElPandaPe\FilamentWarden\Conditions\Shape;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Comment;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Constraints\ConstraintSerializer;
use ElPandaPe\Warden\Constraints\Group;
use ElPandaPe\Warden\Constraints\ValueConstraint;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Enums\ComparisonOperator;
use ElPandaPe\Warden\Enums\LogicalOperator;
use ElPandaPe\Warden\Facades\Warden;
use Illuminate\Database\Eloquent\Model;

pest()->extend(TestCase::class);

/**
 * The narrowed row a chain just wrote, read back off the catalogue.
 */
function narrowedPermission(): Model
{
    return Context::resolve()->permissionClass()::query()
        ->withoutGlobalScopes()
        ->where(static fn ($query) => $query->whereNotNull('options')->orWhere('only_owned', true))
        ->orderByDesc('id')
        ->firstOrFail();
}

function ruleFor(string $column, string $value, LogicalOperator $logic = LogicalOperator::And): Rule
{
    return new Rule($logic, $column, ComparisonOperator::Equal, $value);
}

test('a permission scoped to what the authority owns reads as owned', function (): void {
    $role = makeRole();

    Warden::allow($role)->toOwn(Comment::class, 'update');

    expect(Narrowing::of(narrowedPermission())->shape)->toBe(Shape::Owned);
});

test('a permission with nothing written on it reaches every row', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('update', Post::class);

    $permission = Context::resolve()->permissionClass()::query()->withoutGlobalScopes()->firstOrFail();

    expect(Narrowing::of($permission)->shape)->toBe(Shape::All);
});

test('a stored condition is read back with its column, its operator and its typed value', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('update', Post::class)->where('id', '>=', 2);

    $narrowing = Narrowing::of(narrowedPermission());

    expect($narrowing->shape)->toBe(Shape::Conditions)
        ->and($narrowing->rules)->toHaveCount(1)
        ->and($narrowing->rules[0]->column)->toBe('id')
        ->and($narrowing->rules[0]->operator)->toBe(ComparisonOperator::GreaterThanOrEqual)
        ->and($narrowing->rules[0]->value)->toBe(2);
});

test('conditions that cannot be read are said out loud, not drawn as no conditions', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('update', Post::class)->where('id', 1);

    $permission = narrowedPermission();
    $permission->update(['options' => ['v' => 42, 'g' => ['t' => 'group', 'i' => []]]]);

    $narrowing = Narrowing::of($permission->fresh() ?? $permission);

    expect($narrowing->shape)->toBe(Shape::Unreadable)
        ->and($narrowing->reason)->toBe('corrupt');
});

test('an empty condition group is a rule that answers only with a record, and it says so', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('update', Post::class)->where('id', 1);

    $permission = narrowedPermission();
    $permission->update(['options' => ConstraintSerializer::serialize(new Group([]))]);

    expect(Narrowing::of($permission->fresh() ?? $permission)->reason)->toBe('empty');
});

test('a shape this builder cannot draw is said out loud too', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('update', Post::class)->where('id', 1);

    $permission = narrowedPermission();
    $permission->update(['options' => ConstraintSerializer::serialize(new Group([
        [LogicalOperator::And, new ValueConstraint('id', ComparisonOperator::Equal, null)],
    ]))]);

    expect(Narrowing::of($permission->fresh() ?? $permission)->reason)->toBe('shape');
});

test('the widest rule has no groups to draw', function (): void {
    expect(Narrowing::all()->clauses())->toBeEmpty();
});

test('a rule with no lines is every row again', function (): void {
    expect(Narrowing::conditions([])->shape)->toBe(Shape::All);
});

test('the first line is joined with and however it arrives, so one rule is one twin', function (): void {
    $narrowing = Narrowing::conditions([ruleFor('id', '1', LogicalOperator::Or)]);

    expect($narrowing->rules[0]->logic)->toBe(LogicalOperator::And);
});

test('the precedence sql uses is the precedence the builder draws', function (): void {
    $narrowing = Narrowing::conditions([
        ruleFor('id', '1'),
        ruleFor('title', 'a', LogicalOperator::Or),
        ruleFor('title', 'b'),
    ]);

    $clauses = $narrowing->clauses();

    expect($clauses)->toHaveCount(2)
        ->and($clauses[0])->toHaveCount(1)
        ->and($clauses[1])->toHaveCount(2);
});

test('a rule reads with brackets only where they change the reading', function (): void {
    $one = Narrowing::conditions([ruleFor('id', '1'), ruleFor('title', 'a')]);

    $two = Narrowing::conditions([
        ruleFor('id', '1'),
        ruleFor('title', 'a', LogicalOperator::Or),
        ruleFor('title', 'b'),
    ]);

    expect($one->preview('account', 'AND', 'OR'))->toBe('id = 1 AND title = a')
        ->and($two->preview('account', 'AND', 'OR'))->toBe('id = 1 OR (title = a AND title = b)');
});

test('a payload asking for every row asks for nothing else', function (): void {
    $narrowing = Narrowing::fromPayload(['mode' => 'all'], ['id'], ['id'], Ownership::unavailable());

    expect($narrowing?->shape)->toBe(Shape::All);
});

test('a payload asking for ownership where there is none to resolve is refused', function (): void {
    expect(Narrowing::fromPayload(['mode' => 'owned'], ['id'], ['id'], Ownership::unavailable()))->toBeNull();
});

test('a payload asking for ownership where there is some is granted it', function (): void {
    $narrowing = Narrowing::fromPayload(['mode' => 'owned'], ['user_id'], ['id'], Ownership::of(Comment::class));

    expect($narrowing?->shape)->toBe(Shape::Owned);
});

test('a payload with a line that does not add up takes the whole rule with it', function (): void {
    $payload = ['mode' => 'conditions', 'rules' => [
        ['logic' => 'and', 'kind' => 'value', 'column' => 'id', 'operator' => '=', 'value' => '1'],
        ['logic' => 'and', 'kind' => 'value', 'column' => 'nope', 'operator' => '=', 'value' => '1'],
    ]];

    expect(Narrowing::fromPayload($payload, ['id'], ['id'], Ownership::unavailable()))->toBeNull();
});

test('a payload in a shape nobody sends is refused', function (mixed $payload): void {
    expect(Narrowing::fromPayload($payload, ['id'], ['id'], Ownership::unavailable()))->toBeNull();
})->with([
    ['nope'],
    [['mode' => 'tangled']],
    [['mode' => 'conditions', 'rules' => 'nope']],
    [[]],
]);

test('a payload the browser sends whole becomes the rule it drew', function (): void {
    $payload = ['mode' => 'conditions', 'rules' => [
        ['logic' => 'and', 'kind' => 'value', 'column' => 'id', 'operator' => '=', 'value' => '1'],
    ]];

    $narrowing = Narrowing::fromPayload($payload, ['id'], ['id'], Ownership::unavailable());

    expect($narrowing?->shape)->toBe(Shape::Conditions)
        ->and($narrowing?->rules[0]->value)->toBe(1);
});

test('two rules that differ only in the order of their lines are two different rules', function (): void {
    $one = Narrowing::conditions([ruleFor('id', '1'), ruleFor('title', 'a')]);
    $other = Narrowing::conditions([ruleFor('title', 'a'), ruleFor('id', '1')]);

    expect($one->is($other))->toBeFalse()
        ->and($one->is(Narrowing::conditions([ruleFor('id', '1'), ruleFor('title', 'a')])))->toBeTrue();
});

test('a tangled cell says so, and every locked rule carries the reason', function (): void {
    expect(Narrowing::tangled()->shape)->toBe(Shape::Tangled)
        ->and(Narrowing::tangled()->reason)->toBe('tangled')
        ->and(Narrowing::tangled()->isEditable())->toBeFalse()
        ->and(Narrowing::tangled()->isNarrowed())->toBeTrue();
});

test('every row is neither narrowed nor locked', function (): void {
    expect(Narrowing::all()->isNarrowed())->toBeFalse()
        ->and(Narrowing::all()->isEditable())->toBeTrue()
        ->and(Narrowing::all()->toPayload())->toBe(['mode' => 'all', 'rules' => []]);
});

test('a rule that is both ownership and conditions is more reach than one cell can hold', function (): void {
    $role = makeRole();

    Warden::allow($role)->toOwn(Comment::class, 'update')->where('body', '=', 'alpha');

    $permission = narrowedPermission();

    expect($permission->getAttribute('only_owned'))->toBeTrue()
        ->and($permission->getAttribute('options'))->not->toBeNull();

    $narrowing = Narrowing::of($permission);

    expect($narrowing->shape)->toBe(Shape::Unreadable)
        ->and($narrowing->reason)->toBe('owned_with_conditions')
        ->and($narrowing->isEditable())->toBeFalse();
});

test('a rule that is both ownership and conditions keeps the rule it can read', function (): void {
    $role = makeRole();

    Warden::allow($role)->toOwn(Comment::class, 'update')->where('body', '=', 'alpha');

    $narrowing = Narrowing::of(narrowedPermission());

    expect($narrowing->reason)->toBe('owned_with_conditions')
        ->and($narrowing->shape)->toBe(Shape::Unreadable)
        ->and($narrowing->isEditable())->toBeFalse()
        ->and($narrowing->rules)->toHaveCount(1)
        ->and($narrowing->rules[0]->column)->toBe('body')
        ->and($narrowing->toGroup())->toBeNull();
});

test('an owned rule whose conditions cannot be read says that, not the wrong cause', function (): void {
    $role = makeRole();

    Warden::allow($role)->toOwn(Comment::class, 'update')->where('body', '=', 'alpha');

    $permission = narrowedPermission();
    $permission->update(['options' => ['v' => 42, 'g' => ['t' => 'group', 'i' => []]]]);

    $narrowing = Narrowing::of($permission->fresh() ?? $permission);

    expect($narrowing->reason)->toBe('corrupt')
        ->and($narrowing->shape)->toBe(Shape::Unreadable)
        ->and($narrowing->rules)->toBeEmpty();
});

test('an owned rule whose condition group is empty says the group is empty', function (): void {
    $role = makeRole();

    Warden::allow($role)->toOwn(Comment::class, 'update')->where('body', '=', 'alpha');

    $permission = narrowedPermission();
    $permission->update(['options' => ConstraintSerializer::serialize(new Group([]))]);

    $narrowing = Narrowing::of($permission->fresh() ?? $permission);

    expect($narrowing->reason)->toBe('empty')
        ->and($narrowing->rules)->toBeEmpty();
});
