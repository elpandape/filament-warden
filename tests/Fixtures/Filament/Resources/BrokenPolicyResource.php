<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources;

use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Ledger;
use Filament\Resources\Resource;

/**
 * A resource whose model's policy is registered against a class that is not
 * there. `Gate::getPolicyFor()` throws for it — it is not total, and an audit
 * that assumed it was would die where it was meant to report.
 */
final class BrokenPolicyResource extends Resource
{
    protected static ?string $model = Ledger::class;
}
