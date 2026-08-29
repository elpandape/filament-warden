<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages;

use ElPandaPe\FilamentWarden\Filament\Forms\PermissionGrid;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * The same sentence the edit screen says, for the same reason.
     *
     * A role created with cells already ticked wrote them here, so the save
     * that made it has as much to report as any later one — and saying nothing
     * on the way in would make the grid look like it did not take.
     */
    protected function getCreatedNotification(): ?Notification
    {
        $notification = parent::getCreatedNotification();
        $body = PermissionGrid::savedBody();

        return $notification instanceof Notification && $body !== null
            ? $notification->body($body)
            : $notification;
    }
}
