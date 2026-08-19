<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources;

use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\RelationManagers\TagsRelationManager;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\Resource;

/**
 * A relation group built with a closure instead of an array, which makes
 * `getManagers()` fatal. The catalogue has to survive it.
 */
final class ClosureGroupResource extends Resource
{
    protected static ?string $model = Post::class;

    /**
     * @return array<int, mixed>
     */
    public static function getRelations(): array
    {
        return [
            RelationGroup::make('Tags', fn (): array => [TagsRelationManager::class]),
        ];
    }
}
