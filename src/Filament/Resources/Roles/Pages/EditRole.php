<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages;

use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * The visibility is the guarantee, not decoration.
     *
     * A delete button asks `getDeleteAuthorizationResponse()`, which goes
     * straight to the policy: the resource's `canDelete()` — where the protected
     * list and the `roles.delete` rule live — is never on that path. Measured
     * with the plain `DeleteAction::make()` this page first carried: a protected
     * role was deleted outright from its own edit screen, and the next assertion
     * died with `ModelNotFoundException`.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (Model $record): bool => RoleResource::canDelete($record)),
        ];
    }
}
