<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Conditions;

use ElPandaPe\Warden\Actions\GrantsPermissions;
use ElPandaPe\Warden\Constraints\ColumnConstraint;
use ElPandaPe\Warden\Constraints\ValueConstraint;
use ElPandaPe\Warden\Contracts\Constraint;
use ElPandaPe\Warden\Enums\ComparisonOperator;
use ElPandaPe\Warden\Enums\LogicalOperator;

/**
 * One line of the builder: a column of the entity, an operator, and on the
 * right either a value typed by hand or a column of whoever is being checked.
 *
 * `logic` is what joins this line to the one above it. The line carries it,
 * rather than the gap between lines, because that is how warden stores it — and
 * the first line's is ignored when the group is evaluated, so it is normalised
 * to `and` on the way in and never drawn.
 */
final readonly class Rule
{
    public function __construct(
        public LogicalOperator $logic,
        public string $column,
        public ComparisonOperator $operator,
        public string|int|float|bool $value = '',
        public ?string $authorityColumn = null,
    ) {}

    /**
     * Reading one back. What this builder cannot draw — a nested group, a null,
     * an array — answers null, and the rule set is then reported as unreadable
     * rather than drawn as something it is not.
     */
    public static function of(LogicalOperator $logic, Constraint $constraint): ?self
    {
        if ($constraint instanceof ColumnConstraint) {
            return new self($logic, $constraint->column, $constraint->operator, '', $constraint->authorityColumn);
        }

        if (! $constraint instanceof ValueConstraint) {
            return null;
        }

        $value = $constraint->value;

        return is_string($value) || is_int($value) || is_float($value) || is_bool($value)
            ? new self($logic, $constraint->column, $constraint->operator, $value)
            : null;
    }

    /**
     * What arrives from the browser, checked against what actually exists.
     *
     * A column the table does not have cannot be read and would never match:
     * writing it would store a lie, so the line is refused and the caller leaves
     * the cell exactly as the store has it.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $authorityColumns
     */
    public static function fromPayload(mixed $payload, array $columns, array $authorityColumns): ?self
    {
        if (! is_array($payload)) {
            return null;
        }

        $logic = LogicalOperator::tryFrom(self::read($payload, 'logic'));
        $operator = ComparisonOperator::tryFrom(self::read($payload, 'operator'));
        $column = self::read($payload, 'column');

        // `Not` is in the enum and the serializer refuses any group carrying it:
        // letting one through would store a rule that stops being readable.
        if ($logic === null || $logic === LogicalOperator::Not || $operator === null) {
            return null;
        }

        if (! in_array($column, $columns, true)) {
            return null;
        }

        if (self::read($payload, 'kind') !== 'column') {
            return new self($logic, $column, $operator, Value::cast(self::read($payload, 'value')));
        }

        $authority = self::read($payload, 'authority');

        return in_array($authority, $authorityColumns, true)
            ? new self($logic, $column, $operator, '', $authority)
            : null;
    }

    public function comparesColumns(): bool
    {
        return $this->authorityColumn !== null;
    }

    public function with(LogicalOperator $logic): self
    {
        return new self($logic, $this->column, $this->operator, $this->value, $this->authorityColumn);
    }

    /**
     * Three arguments, always: warden reads the arity, and with two it would
     * take the operator for the value and compare by equality without saying so.
     */
    public function applyTo(GrantsPermissions $chain, bool $first): void
    {
        $or = ! $first && $this->logic === LogicalOperator::Or;

        if ($this->authorityColumn !== null) {
            $or
                ? $chain->orWhereColumn($this->column, $this->operator->value, $this->authorityColumn)
                : $chain->whereColumn($this->column, $this->operator->value, $this->authorityColumn);

            return;
        }

        $or
            ? $chain->orWhere($this->column, $this->operator->value, $this->value)
            : $chain->where($this->column, $this->operator->value, $this->value);
    }

    /**
     * @return array<string, string>
     */
    public function toPayload(): array
    {
        return [
            'logic' => $this->logic->value,
            'kind' => $this->comparesColumns() ? 'column' : 'value',
            'column' => $this->column,
            'operator' => $this->operator->value,
            'value' => $this->comparesColumns() ? '' : (Value::text($this->value) ?? ''),
            'authority' => $this->authorityColumn ?? '',
        ];
    }

    /**
     * The line as a person reads it.
     */
    public function text(string $authority): string
    {
        $right = $this->authorityColumn === null
            ? (Value::text($this->value) ?? '')
            : $authority.'.'.$this->authorityColumn;

        return $this->column.' '.$this->operator->value.' '.$right;
    }

    /**
     * @param  array<mixed>  $payload
     */
    private static function read(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
