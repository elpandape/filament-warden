<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms\Grid;

use ElPandaPe\FilamentWarden\Catalog\Entry;
use ElPandaPe\FilamentWarden\Catalog\Scope;
use ElPandaPe\FilamentWarden\Conditions\Narrowing;

/**
 * One control of the grid, or the absence of one.
 *
 * `declared` false is the grey dot: the policy does not declare that action, so
 * nobody can grant it and there is nothing to draw.
 *
 * The narrowing is the mark in the corner. Amber is a rule that needs a record
 * in front of it — conditions, or ownership — and it can be changed from here.
 * Red is a rule this screen can read and cannot draw, and it is shown, explained
 * and left exactly as it is.
 */
final readonly class Cell
{
    public Narrowing $narrowing;

    public function __construct(
        public string $row,
        public string $action,
        public string $label,
        public Stance $stance,
        public bool $declared = true,
        ?Narrowing $narrowing = null,
        public ?Scope $scope = null,
        public ?Entry $entry = null,
        public ?Stance $reach = null,
    ) {
        $this->narrowing = $narrowing ?? Narrowing::all();
    }

    public static function undeclared(string $row, string $action, string $label, ?Scope $scope = null): self
    {
        return new self($row, $action, $label, Stance::Abstain, declared: false, scope: $scope);
    }

    /**
     * What the cell ANSWERS, which is not what the role wrote on it.
     *
     * A role holding the wildcard has written nothing on any cell — `*` over `*`
     * is not a cell — and every one of them still answers granted. Counting what
     * was written made the most dangerous role in the installation tally zero
     * while the whole grid was ticks.
     */
    public function answers(): Stance
    {
        return $this->stance->isWritten() ? $this->stance : ($this->reach ?? Stance::Abstain);
    }

    /**
     * What the server draws without javascript: the stance the role wrote, or
     * the dashed mark of a rule this cell never asked for.
     *
     * The browser reaches the same answer, but it does not read it here — it
     * re-derives it from `RoleState::toPayload()`, which is why a screen that
     * handed over an empty payload drew empty boxes over correct ones.
     */
    public function drawn(): string
    {
        return $this->stance->isWritten() || ! $this->reach instanceof Stance
            ? $this->stance->value
            : 'broader';
    }

    public function broader(): ?string
    {
        return $this->reach?->value;
    }

    /**
     * A narrowed cell can be changed; one this screen cannot draw cannot.
     */
    public function isEditable(): bool
    {
        return $this->declared && $this->narrowing->isEditable();
    }

    public function isNarrowed(): bool
    {
        return $this->narrowing->isNarrowed();
    }

    public function isLocked(): bool
    {
        return ! $this->narrowing->isEditable();
    }
}
