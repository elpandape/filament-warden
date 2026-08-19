<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources;

use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Comment;
use Filament\Resources\Resource;

/**
 * A resource whose model has no policy, which is what Filament opens wide.
 */
final class CommentResource extends Resource
{
    protected static ?string $model = Comment::class;
}
