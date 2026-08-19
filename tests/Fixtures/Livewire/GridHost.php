<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire;

use ElPandaPe\FilamentWarden\Filament\Forms\PermissionGrid;
use ElPandaPe\Warden\Context;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * The smallest thing that can hold a form field: the grid is tested through a
 * real livewire round trip long before there is a resource page to hang it on.
 *
 * @property-read Schema $form
 */
final class GridHost extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public int|string $roleKey = 0;

    public function mount(int|string $roleKey): void
    {
        $this->roleKey = $roleKey;

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([PermissionGrid::make('permissions')])
            ->record($this->role())
            ->statePath('data');
    }

    public function save(): void
    {
        $this->form->getState();
        $this->form->saveRelationships();
    }

    public function render(): View
    {
        /** @var view-string $view */
        $view = 'filament-warden-tests::grid-host';

        return view($view);
    }

    private function role(): Model
    {
        $class = Context::resolve()->roleClass();

        return $class::query()->findOrFail($this->roleKey);
    }
}
