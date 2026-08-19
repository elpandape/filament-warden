<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources;

use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Tag;
use Filament\Resources\Resource;

/**
 * The resource a relation manager points at, which is the whole reason its model
 * can be reached without instantiating anything.
 */
final class TagResource extends Resource
{
    protected static ?string $model = Tag::class;
}
