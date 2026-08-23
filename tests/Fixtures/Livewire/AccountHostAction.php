<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire;

use ElPandaPe\FilamentWarden\Filament\Forms\RoleAssignment;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The field inside an action modal, which is the second place anybody would put
 * it and the one that broke.
 *
 * Filament gives an action's schema the state path `mountedActions.{i}.data`, so
 * the ROOT of that path is `mountedActions` — a public array Filament reads and
 * writes, and still not a state bag. A string key in it breaks
 * `array_key_last()`, `array_pop()` and `getMountedActionSchemaName()`.
 *
 * @property-read Schema $form
 */
final class AccountHostAction extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public int|string $accountKey = 0;

    public function mount(int|string $accountKey): void
    {
        $this->accountKey = $accountKey;

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([])->statePath('data');
    }

    public function rolesAction(): Action
    {
        return Action::make('roles')
            ->record($this->account())
            ->schema([RoleAssignment::make('roles')])
            ->action(function (): void {});
    }

    /**
     * @return list<int|string>
     */
    public function mountedKeys(): array
    {
        return array_keys($this->mountedActions ?? []);
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
