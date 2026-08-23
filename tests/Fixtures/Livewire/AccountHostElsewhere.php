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
 * The same screen, with its state under a property of another name.
 *
 * Filament does not require a page's state to live in `$data`, and this field
 * stashes what the store said beside the list it draws. When there is no `$data`
 * to stash it in there is simply no baseline, and the save behaves the way it
 * did before there was one — which is a branch, so it needs a page like this to
 * walk it.
 *
 * @property-read Schema $form
 */
final class AccountHostElsewhere extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var array<string, mixed>|null */
    public ?array $elsewhere = [];

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
            ->record($this->account())
            ->statePath('elsewhere');
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
