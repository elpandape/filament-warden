<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources;

use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Document;
use Filament\Resources\Resource;

/**
 * A resource of the consuming application, which does belong to a tenant. It is
 * here to prove the package never un-scopes what is not its own.
 */
final class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;
}
