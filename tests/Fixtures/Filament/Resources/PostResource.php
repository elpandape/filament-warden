<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources;

use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Widgets\Summary;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use Filament\Resources\Resource;

final class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    /**
     * A widget a resource brings with it, which a panel never lists on its own.
     *
     * @return array<class-string<\Filament\Widgets\Widget>>
     */
    public static function getWidgets(): array
    {
        return [Summary::class];
    }
}
