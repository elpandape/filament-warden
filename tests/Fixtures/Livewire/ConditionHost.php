<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire;

use ElPandaPe\FilamentWarden\Filament\Forms\ConditionBuilder;
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
 * The smallest thing that can hold the condition builder: the field is tested
 * through a real livewire round trip, hydrating from a permission row and
 * writing back to it.
 *
 * @property-read Schema $form
 */
final class ConditionHost extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public int|string $permissionKey = 0;

    public ?string $entity = null;

    public function mount(int|string $permissionKey, ?string $entity = null): void
    {
        $this->permissionKey = $permissionKey;
        $this->entity = $entity;

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([ConditionBuilder::make('options')->entity(fn (): ?string => $this->entity)])
            ->record($this->permission())
            ->statePath('data');
    }

    public function save(): void
    {
        $this->permission()->update($this->form->getState());
    }

    public function render(): View
    {
        /** @var view-string $view */
        $view = 'filament-warden-tests::condition-host';

        return view($view);
    }

    private function permission(): Model
    {
        $class = Context::resolve()->permissionClass();

        return $class::query()->findOrFail($this->permissionKey);
    }
}
