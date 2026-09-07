<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages;

use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Tables\RolesTable;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Support\Expiry;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ViewRole extends ViewRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * Who holds it, added below the resource's own infolist rather than inside
     * it: `RoleInfolist` is shared with nothing else that would need this
     * section, and it answers a question — who, under the tenant this request
     * is in — that only makes sense once a record is already resolved, which
     * is what this page, and not the schema, has.
     *
     * Tenant-scoped, and deliberately not the delete warning's wide read: this
     * section informs rather than decides a delete, and §6.24's rule for an
     * informing read is to keep the scope — reading wide here would name an
     * assignment this screen's own delete button, and `retract()`, cannot act
     * on (§6.21).
     */
    public function infolist(Schema $schema): Schema
    {
        $schema = parent::infolist($schema);

        return $schema->components([
            ...$schema->getComponents(),
            Section::make(__('filament-warden::ui.resources.roles.sections.holders'))
                ->icon(Heroicon::OutlinedUsers)
                ->description(__('filament-warden::ui.resources.roles.holders.description'))
                ->schema([
                    TextEntry::make('holders')
                        ->hiddenLabel()
                        ->state(static fn (Model $record): string => self::holders($record)),
                ]),
        ]);
    }

    /**
     * The edit action needs nothing written by hand — the resource leaves
     * `canEdit()` alone, so the policy closes it on its own, and a protected role
     * opens with its form disabled rather than not opening at all.
     *
     * The delete action does, for the same reason as on the edit screen: its
     * authorization response never passes through `RoleResource::canDelete()`.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make()
                ->modalDescription(static fn (Model $record): string => RolesTable::warning($record))
                ->visible(fn (Model $record): bool => RoleResource::canDelete($record)),
        ];
    }

    private static function holders(Model $record): string
    {
        $rows = self::assignments($record);

        if ($rows->isEmpty()) {
            return (string) __('filament-warden::ui.resources.roles.holders.nobody');
        }

        return (string) __('filament-warden::ui.resources.roles.holders.held', [
            'count' => $rows->count(),
            'names' => implode(', ', RolesTable::labels($rows)),
        ]);
    }

    /**
     * Kept to the tenant this request is in, unlike `RolesTable::warning()`'s
     * own wide read — see this class's `infolist()` docblock for why the two
     * disagree on purpose.
     *
     * @return Collection<int, Model>
     */
    private static function assignments(Model $record): Collection
    {
        /** @var Collection<int, Model> $rows */
        $rows = Context::resolve()->assignedRoleClass()::query()
            ->where('role_id', $record->getKey())
            // Who holds it, and a lapsed row is not one of them — unlike
            // `RolesTable::warning()`, which counts them because the cascade
            // takes them whatever the clock says.
            ->tap(Expiry::live(...))
            ->orderBy('id')
            ->get();

        return $rows;
    }
}
