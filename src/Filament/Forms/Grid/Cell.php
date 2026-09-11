<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms\Grid;

use Carbon\CarbonImmutable;
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
 *
 * `inheritedFrom` names the role lending this cell its answer. The answer
 * itself is already folded into `reach` — `GridView::reach()` says why — so
 * this carries only WHICH role, which no counter needs and every reader does.
 *
 * `until` is when the grant behind the cell stops. It arrives already judged
 * against the server's clock: a written stance beside a date means the access
 * ends then, and an abstention beside one means it already ended. The cell never
 * compares the date itself, because the browser holds the same object and its
 * clock is not ours to trust.
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
        public ?CarbonImmutable $until = null,
        public ?string $inheritedFrom = null,
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
     * was written would tally the most dangerous role in the installation at
     * zero while the whole grid is ticks.
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
     * re-derives it from `RoleState::toPayload()`, so a screen that hands over
     * an empty payload draws empty boxes over correct ones.
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

    /**
     * Whether this cell's access has already ended.
     *
     * Not a date comparison: `RoleGrants::of()` already made it, against the
     * server's clock, and the answer is in the stance. A cell that carries a
     * date and writes nothing is a grant the clock retired — nothing else in
     * this package produces that pair, because a cell nobody wrote carries no
     * date at all.
     */
    public function hasExpired(): bool
    {
        return $this->until instanceof CarbonImmutable && ! $this->stance->isWritten();
    }

    public function isLocked(): bool
    {
        return ! $this->narrowing->isEditable();
    }

    /**
     * Whether a rule wider than this cell is what answers it.
     *
     * Asked here so the template never compares `drawn()` against the literal
     * `'broader'`: that would put one of this class's own words in a file that
     * decides nothing, free to drift silently if the word ever changed.
     */
    public function isBroader(): bool
    {
        return $this->drawn() === 'broader';
    }

    /**
     * Whether a person can work this cell at all.
     *
     * The screen has to be interactive — an application may have disabled the
     * field, and the read-only page never is — and the cell has to be one this
     * screen can draw, or a tangled one, which accepts being emptied. Neither
     * half is a rendering decision.
     */
    public function isOperable(bool $interactive): bool
    {
        return $interactive && (! $this->isLocked() || $this->isClearable());
    }

    /**
     * Whether the only thing this cell will accept is being emptied.
     *
     * A tangled cell is drawn locked and still is: it carries no reach the
     * builder can open and the browser is handed none. What it accepts is the
     * one move that needs neither — going off — and the server is what holds
     * that line, not this flag. Asked for anything else it writes nothing and
     * the save says which cells it left alone.
     */
    public function isClearable(): bool
    {
        return $this->declared && $this->narrowing->isClearable();
    }
}
