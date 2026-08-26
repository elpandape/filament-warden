<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Audit;
use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\PostResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Panel;

/**
 * The gate, bucket by bucket.
 *
 * `isClean()` is a flat conjunction and the only thing `--check` consults, so a
 * bucket left out of it is a finding every build swallows: a change that looks
 * done, passes every other test, and fixes nothing. And three buckets are out of
 * it on purpose — a permission the catalogue declares that nobody holds is what
 * every grid save that turns a cell off leaves behind, a grant whose authority is
 * gone has no cure this package can write, and a relation manager that declares
 * no `$relatedResource` cannot be walked to by anybody: this package's own
 * `RolesRelationManager` is one deliberately, so the integration the README
 * documents used to redden `--check` the moment it was wired, for good.
 *
 * Every parameter of the constructor has a default, so a further bucket added
 * without touching this file is legal PHP and clean at `level: max`. Nothing
 * goes red on its own: what catches it is `'this file puts a finding in every
 * bucket the audit carries'`, which walks the constructor by reflection.
 */
pest()->extend(TestCase::class);

/**
 * The buckets that reach the gate, in CONSTRUCTOR order — which is what
 * `declaredBuckets()` walks and what the comparison below is against, not the
 * order `isClean()` happens to read them in nor the order the command prints
 * them in. The three agreed until `unmigrated` was appended.
 *
 * @return list<string>
 */
function gateBuckets(): array
{
    return ['open', 'unpoliced', 'forgotten', 'strays', 'drifted', 'unkeyable', 'unmigrated'];
}

/**
 * Every bucket the audit carries, asked of the constructor rather than listed.
 *
 * @return list<string>
 */
function declaredBuckets(): array
{
    return array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        new ReflectionMethod(Audit::class, '__construct')->getParameters(),
    );
}

function auditWith(string $bucket): Audit
{
    $finding = ['something a build must not swallow'];

    return new Audit(
        open: $bucket === 'open' ? $finding : [],
        unpoliced: $bucket === 'unpoliced' ? $finding : [],
        orphans: $bucket === 'orphans' ? $finding : [],
        forgotten: $bucket === 'forgotten' ? $finding : [],
        strays: $bucket === 'strays' ? $finding : [],
        drifted: $bucket === 'drifted' ? $finding : [],
        unwalkable: $bucket === 'unwalkable' ? $finding : [],
        unkeyable: $bucket === 'unkeyable' ? $finding : [],
        stranded: $bucket === 'stranded' ? $finding : [],
        unmigrated: $bucket === 'unmigrated' ? $finding : [],
    );
}

test('an audit carrying nothing at all is clean and says nothing', function (): void {
    expect(auditWith('none of them')->isClean())->toBeTrue()
        ->and(auditWith('none of them')->isSilent())->toBeTrue();
});

test('each bucket the gate reads turns the build red on its own', function (string $bucket): void {
    expect(auditWith($bucket)->isClean())->toBeFalse();
})->with(gateBuckets());

test('the declared and unheld bucket is reported and never reddens the build', function (): void {
    expect(auditWith('orphans')->isClean())->toBeTrue()
        ->and(auditWith('orphans')->isSilent())->toBeFalse();
});

test('the stranded bucket is reported and never reddens the build', function (): void {
    expect(auditWith('stranded')->isClean())->toBeTrue()
        ->and(auditWith('stranded')->isSilent())->toBeFalse();
});

test('the unwalkable bucket is reported and never reddens the build', function (): void {
    expect(auditWith('unwalkable')->isClean())->toBeTrue()
        ->and(auditWith('unwalkable')->isSilent())->toBeFalse();
});

test('the gate reads exactly the buckets this file names, no more and no fewer', function (): void {
    $reaching = array_values(array_filter(
        declaredBuckets(),
        static fn (string $bucket): bool => ! auditWith($bucket)->isClean(),
    ));

    expect($reaching)->toBe(gateBuckets())
        ->and(declaredBuckets())->toHaveCount(count(gateBuckets()) + 3);
});

test('this file puts a finding in every bucket the audit carries', function (string $bucket): void {
    expect(new ReflectionProperty(Audit::class, $bucket)->getValue(auditWith($bucket)))->toHaveCount(1);
})->with(declaredBuckets());

test('a row one panel declares is not forgotten because another panel never heard of it', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);
    Warden::disallow($role)->to('viewAny', Post::class);

    $label = 'viewAny on '.new Post()->getMorphClass();

    $declaring = Panel::make()->id('declaring')->resources([PostResource::class]);
    $ignorant = Panel::make()->id('ignorant');

    $both = Audit::of([$declaring, $ignorant]);

    expect($both->orphans)->toContain($label)
        ->and($both->forgotten)->toBeEmpty()
        ->and(Audit::of([$ignorant])->forgotten)->toContain($label)
        ->and(Audit::of([$ignorant])->isClean())->toBeFalse();
});

test('a catalogue name carrying a dot is found by the build, not by a person opening the screen', function (): void {
    config()->set('filament-warden.catalog.custom', ['reports.export' => 'read']);

    Catalog::forget();

    $audit = Audit::of([Panel::make()->id('dotted')]);

    expect($audit->unkeyable)->toBe(['dotted: reports.export'])
        ->and($audit->isClean())->toBeFalse();
});

test('a name the grid can key is not reported', function (): void {
    config()->set('filament-warden.catalog.custom', ['reports-export' => 'read']);

    Catalog::forget();

    expect(Audit::of([Panel::make()->id('undotted')])->unkeyable)->toBeEmpty();
});
