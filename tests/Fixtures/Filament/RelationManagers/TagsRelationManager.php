<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\RelationManagers;

use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\TagResource;
use Filament\Resources\RelationManagers\RelationManager;

/**
 * The one that says where it points. Its model is two public statics away, with
 * nothing instantiated and no relationship executed.
 */
final class TagsRelationManager extends RelationManager
{
    protected static string $relationship = 'tags';

    protected static ?string $relatedResource = TagResource::class;
}
