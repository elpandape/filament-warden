<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms\Grid;

use ElPandaPe\FilamentWarden\Catalog\Entry;
use ElPandaPe\FilamentWarden\Catalog\Scope;

/**
 * One control of the grid, or the absence of one.
 *
 * `declared` false is the grey dot: the policy does not declare that action, so
 * nobody can grant it and there is nothing to draw. `narrowed` true is the amber
 * mark: the permission carries conditions or an ownership flag, so it is shown
 * and left alone — revoking by name would delete every twin that shares it.
 */
final readonly class Cell
{
    public function __construct(
        public string $row,
        public string $action,
        public string $label,
        public Stance $stance,
        public bool $declared = true,
        public bool $narrowed = false,
        public ?Scope $scope = null,
        public ?Entry $entry = null,
        public ?Stance $reach = null,
    ) {}

    public static function undeclared(string $row, string $action, string $label, ?Scope $scope = null): self
    {
        return new self($row, $action, $label, Stance::Abstain, declared: false, scope: $scope);
    }

    public function isGranted(): bool
    {
        return $this->stance === Stance::Granted;
    }

    /**
     * What the server draws without javascript, which is also what the browser
     * starts from: the stance the role wrote, or the dashed mark of a rule this
     * cell never asked for.
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
     * A narrowed cell is shown, explained and left untouched by the save.
     */
    public function isEditable(): bool
    {
        return $this->declared && ! $this->narrowed;
    }
}
