<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms;

use Closure;
use ElPandaPe\FilamentWarden\Conditions\Columns;
use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Conditions\Ownership;
use ElPandaPe\FilamentWarden\Conditions\Words;
use ElPandaPe\Warden\Constraints\ConstraintSerializer;
use ElPandaPe\Warden\Constraints\Group;
use Filament\Forms\Components\Field;
use Illuminate\Database\Eloquent\Model;

/**
 * The condition builder on its own, over one permission row.
 *
 * The grid narrows a grant by pointing it at a twin; this screen edits the row
 * itself, which is the only place it can be done — and it changes the rule for
 * everybody holding that row, because a twin is shared. That is correct for a
 * catalogue, where the row IS the rule, and it is said out loud on screen.
 *
 * Three alternatives become two here: ownership is a checkbox of its own on
 * this form, so an empty list of conditions simply means every row.
 */
final class ConditionBuilder extends Field
{
    protected string $view = 'filament-warden::forms.condition-builder';

    private Closure|string|null $entity = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->default([]);

        $this->afterStateHydrated(static function (self $component): void {
            $record = $component->getRecord();

            $component->state($record instanceof Model
                ? Narrowing::of($record)->toPayload()
                : Narrowing::all()->toPayload());
        });

        // Written through the serializer, always: a shape it did not produce
        // deserializes to null without throwing, and the engines then answer
        // with whichever direction is safe for the pass they are running.
        $this->dehydrateStateUsing(static function (self $component, mixed $state): ?array {
            $group = $component->narrowing($state)?->toGroup();

            return $group instanceof Group ? ConstraintSerializer::serialize($group) : null;
        });
    }

    /**
     * The model whose columns a condition may name, worked out when the screen
     * asks — it is a sibling field of this one and it changes live.
     */
    public function entity(Closure|string|null $entity): static
    {
        $this->entity = $entity;

        return $this;
    }

    /**
     * What this screen can build a condition from, and every word it says.
     *
     * @return array{
     *     model: class-string<Model>|null,
     *     columns: list<string>,
     *     authority: list<string>,
     *     ownership: array{available: bool},
     * }
     */
    public function getSource(): array
    {
        $model = $this->getEntity();

        if ($model === null) {
            return ['model' => null, 'columns' => [], 'authority' => [], 'ownership' => ['available' => false]];
        }

        return [
            'model' => $model,
            'columns' => Columns::of($model),
            'authority' => Columns::authority(),
            'ownership' => ['available' => Ownership::of($model)->available],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getWords(): array
    {
        return Words::all();
    }

    /**
     * A permission with no model behind it can hold conditions, and they would
     * never grant anything: the check that reaches them has no row to compare.
     *
     * @return class-string<Model>|null
     */
    public function getEntity(): ?string
    {
        $entity = $this->evaluate($this->entity);

        return is_string($entity) && is_subclass_of($entity, Model::class) ? $entity : null;
    }

    /**
     * What the browser drew, checked against what the table actually has.
     */
    public function narrowing(mixed $state): ?Narrowing
    {
        $model = $this->getEntity();

        if ($model === null) {
            return null;
        }

        return Narrowing::fromPayload($state, Columns::of($model), Columns::authority(), Ownership::of($model));
    }
}
