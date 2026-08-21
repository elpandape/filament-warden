<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire;

use ElPandaPe\FilamentWarden\Filament\Forms\PermissionGrid;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * The grid hung on a record that is not a role, which the field's name being
 * frozen makes a supported thing for an application to do.
 *
 * The record is re-read from the database rather than reused: a model that was
 * just created answers a missing attribute with null, and one that was fetched
 * throws. Only the second is the shape an application would meet.
 *
 * @property-read Schema $form
 */
final class ForeignGridHost extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public string $recordClass = '';

    public int|string $recordKey = 0;

    public function mount(string $recordClass, int|string $recordKey): void
    {
        $this->recordClass = $recordClass;
        $this->recordKey = $recordKey;

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([PermissionGrid::make('permissions')])
            ->record($this->record())
            ->statePath('data');
    }

    public function render(): View
    {
        /** @var view-string $view */
        $view = 'filament-warden-tests::grid-host';

        return view($view);
    }

    private function record(): Model
    {
        /** @var class-string<Model> $class */
        $class = $this->recordClass;

        return $class::query()->findOrFail($this->recordKey);
    }
}
