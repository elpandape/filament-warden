<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire;

use ElPandaPe\FilamentWarden\Filament\Forms\RoleAssignment;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * A screen with no state path of its own, so the field's path is a bare name.
 *
 * There is then nothing for the copy of the store's answer to sit beside, and
 * this page gets no baseline — it saves the way every page did before there was
 * one. That is a branch, so it needs a page like this to walk it.
 *
 * @property-read Schema $form
 */
final class AccountHostFlat extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var array<int, mixed>|null */
    public ?array $roles = [];

    public int|string $accountKey = 0;

    public function mount(int|string $accountKey): void
    {
        $this->accountKey = $accountKey;

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([RoleAssignment::make('roles')])
            ->record($this->account());
    }

    public function save(): void
    {
        $this->form->getState();
        $this->form->saveRelationships();
    }

    public function render(): View
    {
        /** @var view-string $view */
        $view = 'filament-warden-tests::account-host';

        return view($view);
    }

    private function account(): User
    {
        return User::query()->findOrFail($this->accountKey);
    }
}
