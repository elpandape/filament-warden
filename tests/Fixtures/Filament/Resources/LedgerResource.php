<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources;

use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\RelationManagers\LedgerRelationManager;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\RelationManagers\TagsRelationManager;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Ledger;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;
use Filament\Resources\Resource;

/**
 * A resource with both kinds of relation manager, and one of them inside a group,
 * which is the third shape `getRelations()` can hold.
 */
final class LedgerResource extends Resource
{
    protected static ?string $model = Ledger::class;

    /**
     * @return array<int, mixed>
     */
    public static function getRelations(): array
    {
        return [
            LedgerRelationManager::class,
            // The third shape: a configuration, whose `$relationManager` is
            // public and readonly — the same unwrapping a widget gets.
            new RelationManagerConfiguration(TagsRelationManager::class),
            RelationGroup::make('Tags', [TagsRelationManager::class]),
        ];
    }
}
