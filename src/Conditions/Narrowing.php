<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Conditions;

use ElPandaPe\Warden\Constraints\ConstraintSerializer;
use ElPandaPe\Warden\Constraints\Group;
use ElPandaPe\Warden\Enums\LogicalOperator;
use Illuminate\Database\Eloquent\Model;

/**
 * How far a grant reaches: every row, only what it owns, or with these
 * conditions.
 *
 * It is what warden keeps on the permission row — `only_owned` and `options` —
 * read in the shape this screen knows how to draw. Whatever does not fit that
 * shape is said out loud; it is never approximated.
 */
final readonly class Narrowing
{
    /**
     * @param  list<Rule>  $rules
     * @param  string|null  $reason  the language key that says why it is locked
     */
    private function __construct(
        public Shape $shape,
        public array $rules = [],
        public ?string $reason = null,
    ) {}

    public static function all(): self
    {
        return new self(Shape::All);
    }

    public static function owned(): self
    {
        return new self(Shape::Owned);
    }

    /**
     * An empty group is not "no conditions": `Group::passes()` answers true for
     * one, but only after demanding an instance. Writing it would cost a twin
     * and say nothing, so an empty list is every row again — and the builder
     * says so before anybody saves.
     *
     * @param  list<Rule>  $rules
     */
    public static function conditions(array $rules): self
    {
        if ($rules === []) {
            return self::all();
        }

        // The first line's logic is ignored when the group is evaluated. It is
        // normalised so two identical rules cannot produce two different twins.
        $rules[0] = $rules[0]->with(LogicalOperator::And);

        return new self(Shape::Conditions, $rules);
    }

    public static function unreadable(string $reason): self
    {
        return new self(Shape::Unreadable, reason: $reason);
    }

    public static function tangled(): self
    {
        return new self(Shape::Tangled, reason: 'tangled');
    }

    /**
     * The grant lives at a scope this screen cannot write.
     *
     * Warden's writes target one exact scope: `disallow()` filters on it and
     * deletes nothing when the row belongs to another. A cell like that could be
     * switched off, saved, reported as saved — and come back green on reload.
     * Measured. So it is shown, marked and left alone.
     */
    public static function elsewhere(): self
    {
        return new self(Shape::Elsewhere, reason: 'elsewhere');
    }

    /**
     * What one catalogue row says about itself.
     */
    public static function of(Model $permission): self
    {
        $options = $permission->getAttribute('options');
        $owned = (bool) $permission->getAttribute('only_owned');

        if ($options === null) {
            return $owned ? self::owned() : self::all();
        }

        // Both halves on one row is something warden really writes — `toOwn()`
        // and then a chained `where()`, which copies `only_owned` onto the twin —
        // and both are honoured when it resolves: the candidate is filtered on
        // ownership and then run through the group. A cell draws one reach, so
        // reading this as plain ownership would drop the condition the next time
        // anybody saved. It is said out loud and left alone.
        if ($owned) {
            return self::unreadable('shape');
        }

        $group = ConstraintSerializer::deserialize($options);

        // Deserialising is total and silent: a corrupt shape answers null without
        // throwing. The engines handle that by returning the flag of the pass they
        // are running, so it fails closed in both polarities — which is why it can
        // be shown without fear, and why it must not be rewritten.
        if (! $group instanceof Group) {
            return self::unreadable('corrupt');
        }

        $rules = [];

        foreach ($group->items as [$logic, $constraint]) {
            $rule = Rule::of($logic, $constraint);

            if (! $rule instanceof Rule) {
                return self::unreadable('shape');
            }

            $rules[] = $rule;
        }

        return $rules === [] ? self::unreadable('empty') : self::conditions($rules);
    }

    /**
     * What arrives from the browser. Anything that does not add up answers null,
     * and the caller leaves the cell exactly as the store has it: half-writing a
     * rule nobody understood is worse than not writing it.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $authorityColumns
     */
    public static function fromPayload(mixed $payload, array $columns, array $authorityColumns, Ownership $ownership): ?self
    {
        if (! is_array($payload)) {
            return null;
        }

        $mode = $payload['mode'] ?? null;
        $shape = is_string($mode) ? Shape::tryFrom($mode) : null;

        if ($shape === Shape::All) {
            return self::all();
        }

        if ($shape === Shape::Owned) {
            return $ownership->available ? self::owned() : null;
        }

        $rules = $payload['rules'] ?? null;

        if ($shape !== Shape::Conditions || ! is_array($rules)) {
            return null;
        }

        $parsed = [];

        foreach ($rules as $rule) {
            $one = Rule::fromPayload($rule, $columns, $authorityColumns);

            if (! $one instanceof Rule) {
                return null;
            }

            $parsed[] = $one;
        }

        return self::conditions($parsed);
    }

    public function isEditable(): bool
    {
        return $this->shape->isEditable();
    }

    public function isNarrowed(): bool
    {
        return $this->shape->isNarrowed();
    }

    public function is(self $other): bool
    {
        return $this->toPayload() === $other->toPayload();
    }

    /**
     * The groups the precedence draws. `Group::passes()` is a disjunction of
     * conjunctions — `and` binds tighter than `or`, the way SQL does it — so the
     * flat list is cut at every `or` and each piece is a group.
     *
     * @return list<list<Rule>>
     */
    public function clauses(): array
    {
        $clauses = [];
        $clause = [];

        foreach ($this->rules as $index => $rule) {
            if ($index > 0 && $rule->logic === LogicalOperator::Or) {
                $clauses[] = $clause;
                $clause = [];
            }

            $clause[] = $rule;
        }

        if ($clause !== []) {
            $clauses[] = $clause;
        }

        return $clauses;
    }

    /**
     * The rule on one line, with brackets only where they change the reading.
     */
    public function preview(string $authority, string $and, string $or): string
    {
        $clauses = [];

        foreach ($this->clauses() as $clause) {
            $text = implode(' '.$and.' ', array_map(
                static fn (Rule $rule): string => $rule->text($authority),
                $clause,
            ));

            $clauses[] = count($clause) > 1 && count($this->clauses()) > 1 ? '('.$text.')' : $text;
        }

        return implode(' '.$or.' ', $clauses);
    }

    /**
     * The rule as warden's own group, or nothing when there is no rule. Only the
     * permission screen needs it: everywhere else a condition is written by the
     * fluent API, which serializes for itself.
     */
    public function toGroup(): ?Group
    {
        if ($this->shape !== Shape::Conditions) {
            return null;
        }

        $items = [];

        foreach ($this->rules as $rule) {
            $items[] = [$rule->logic, $rule->constraint()];
        }

        return new Group($items);
    }

    /**
     * @return array{mode: string, rules: list<array<string, string>>}
     */
    public function toPayload(): array
    {
        return [
            'mode' => $this->shape->value,
            'rules' => array_map(static fn (Rule $rule): array => $rule->toPayload(), $this->rules),
        ];
    }
}
