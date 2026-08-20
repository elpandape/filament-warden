<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages;

use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    /**
     * Filament never adds this by itself: `getHeaderActions()` defaults to an
     * empty array, so a listing that does not declare one has no way in to its
     * own create page. Reported from an installation where the page answered 200
     * and nothing on the screen led to it.
     *
     * The policy closes the button on its own — `isHidden()` ends in
     * `isAuthorizedOrNotHiddenWhenUnauthorized()`, and the response comes from
     * `getCreateAuthorizationResponse()`. The config does not: that path never
     * consults the resource's `canCreate()`, which is where `roles.create` lives.
     * Hence the visibility written by hand, the same way the delete action's is.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->visible(fn (): bool => RoleResource::canCreate()),
        ];
    }
}
