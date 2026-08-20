<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages;

use ElPandaPe\FilamentWarden\Catalog\PermissionName;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Tables\PermissionsTable;
use ElPandaPe\Warden\Facades\Warden;
use ElPandaPe\Warden\Support\Titles\PermissionTitle;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPermission extends EditRecord
{
    protected static string $resource = PermissionResource::class;

    /**
     * The visibility is the guarantee: a delete button asks
     * `getDeleteAuthorizationResponse()`, which goes straight to the policy, and
     * the orphan rule lives in `PermissionResource::canDelete()`, off that path.
     * The description is the table's own — the grants go with it by a foreign
     * key, below Eloquent and with no event of their own.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription(static fn (Model $record): string => PermissionsTable::warning($record))
                ->visible(fn (Model $record): bool => PermissionResource::canDelete($record)),
        ];
    }

    /**
     * The title never catches up on its own: warden writes it in the `creating`
     * hook and only when it is null, so a rename leaves the old one in place,
     * lying. Blanking it does not help either — the hook is not consulted on an
     * update at all.
     *
     * So it is regenerated here, and only when nobody had written one by hand:
     * if what is being saved is exactly what warden would have generated for the
     * row as it was, then it was warden's and it may be warden's again.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        $was = PermissionTitle::generate(
            $this->text($record->getAttribute('name')),
            $this->nullableText($record->getAttribute('entity_type')),
            null,
            (bool) $record->getAttribute('only_owned'),
        );

        if (($data['title'] ?? null) !== $was) {
            return $data;
        }

        $name = $this->text($data['name'] ?? null);

        // A name this package minted has a title only this package can read back:
        // warden's generator has no way to know that `widget:` means anything.
        $data['title'] = PermissionName::title($name) ?? PermissionTitle::generate(
            $name,
            $this->nullableText($data['entity_type'] ?? null),
            null,
            (bool) ($data['only_owned'] ?? false),
        );

        return $data;
    }

    /**
     * Nothing in warden invalidates the check cache for a write made through the
     * model layer: only its own fluent actions bump the version, and the
     * `PermissionCreated`/`PermissionDeleted` events have no listener anywhere.
     * Without this, every check goes on answering the old way — silently, and
     * with no expiry.
     */
    protected function afterSave(): void
    {
        Warden::refresh();
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function nullableText(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
