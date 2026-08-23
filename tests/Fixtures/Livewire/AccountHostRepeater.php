<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire;

use ElPandaPe\FilamentWarden\Filament\Forms\RoleAssignment;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The field inside a repeating container, which is the fourth place it can live
 * and the one that put the copy into what the record is updated with.
 *
 * A repeater registers its validation rule on the CONTAINER, so Laravel returns
 * the whole item sub-array as validated data, and `Schema::getState()` prunes
 * the raw state against that as a template — so anything inside an item survives
 * into `$record->update()`. Two rows also share a path prefix, so a copy kept
 * per item is a copy per row.
 *
 * @property-read Schema $form
 */
final class AccountHostRepeater extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public int|string $accountKey = 0;

    public function mount(int|string $accountKey): void
    {
        $this->accountKey = $accountKey;

        $this->form->fill(['rows' => [['roles' => []], ['roles' => []]]]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Repeater::make('rows')->schema([RoleAssignment::make('roles')]),
            ])
            ->record($this->account())
            ->statePath('data');
    }

    /**
     * @return array<string, mixed>
     */
    public function saved(): array
    {
        /** @var array<string, mixed> $state */
        $state = $this->form->getState();

        return $state;
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
