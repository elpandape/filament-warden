<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages;

use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use ElPandaPe\Warden\Facades\Warden;
use ElPandaPe\Warden\Support\Titles\PermissionTitle;
use Filament\Resources\Pages\EditRecord;

class EditPermission extends EditRecord
{
    protected static string $resource = PermissionResource::class;

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

        $data['title'] = PermissionTitle::generate(
            $this->text($data['name'] ?? null),
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
