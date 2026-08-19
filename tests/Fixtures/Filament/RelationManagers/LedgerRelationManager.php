<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;

/**
 * The one that says nothing but a relationship name.
 *
 * Reaching its model would mean building the owner and running `explosive()`,
 * which throws on purpose: if the catalogue ever resolved this, the suite would
 * die where it stands.
 */
final class LedgerRelationManager extends RelationManager
{
    protected static string $relationship = 'explosive';
}
