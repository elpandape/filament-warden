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
 * A screen whose form state is `$elsewhere` and which ALSO keeps a protected
 * `$data` of its own, for reasons of its own.
 *
 * `property_exists()` answers true for a protected property, and reading one
 * then goes through Livewire's `__get()`, which resolves only public ones and
 * throws. A field that looked for a property called `data` died on mount here.
 * Nothing about this page is exotic: the name is common and the visibility is
 * the application's business.
 *
 * @property-read Schema $form
 */
final class AccountHostProtected extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var array<string, mixed>|null */
    public ?array $elsewhere = [];

    public int|string $accountKey = 0;

    /** @var array<string, mixed> */
    private array $data = ['mine' => true];

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

    /**
     * What this page keeps for itself, so a test can say the field left it be.
     *
     * @return array<string, mixed>
     */
    public function ownData(): array
    {
        return $this->data;
    }

    private function account(): User
    {
        return User::query()->findOrFail($this->accountKey);
    }
}
