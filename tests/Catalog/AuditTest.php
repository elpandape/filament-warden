<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Audit;
use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\PostResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Facades\Filament;
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
    return ['open', 'unpoliced', 'forgotten', 'strays', 'drifted', 'unkeyable', 'unownable', 'unmigrated', 'misconfigured', 'unsatisfiable'];
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
        unownable: $bucket === 'unownable' ? $finding : [],
        stranded: $bucket === 'stranded' ? $finding : [],
        unmigrated: $bucket === 'unmigrated' ? $finding : [],
        misconfigured: $bucket === 'misconfigured' ? $finding : [],
        unsatisfiable: $bucket === 'unsatisfiable' ? $finding : [],
        dormant: $bucket === 'dormant' ? $finding : [],
        expired: $bucket === 'expired' ? $finding : [],
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

test('the dormant bucket is reported and never reddens the build', function (): void {
    expect(auditWith('dormant')->isClean())->toBeTrue()
        ->and(auditWith('dormant')->isSilent())->toBeFalse();
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
        ->and(declaredBuckets())->toHaveCount(count(gateBuckets()) + 5);
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

test('an ownership row on a model that resolves no ownership is a finding', function (): void {
    $role = makeRole();

    Warden::allow($role)->toOwn(Post::class, 'update');
    Warden::allow($role)->to('viewAny', Post::class);

    // Turned off AFTER the write, which is the honest order: warden's `toOwn()`
    // asks nothing on the way in, so the row that exists now is exactly the one
    // a seeder leaves behind on an installation that never registered ownership.
    // `Context` is a singleton built from config on first resolve, and booting
    // the panel already resolved it — without this the config change is inert
    // and the test passes green having changed nothing.
    config()->set('warden.ownership.default_attribute');
    app()->forgetInstance(Context::class);

    $audit = Audit::of([Panel::make()->id('owning')->resources([PostResource::class])]);

    expect($audit->unownable)->toBe(['update on '.new Post()->getMorphClass()])
        // Red, not informational: it is fixable, and both fixes are the
        // operator's — register the ownership, or take the row out.
        ->and($audit->isClean())->toBeFalse();
});

test('an ownership row a model does resolve is not a finding', function (): void {
    $role = makeRole();

    // `title` is a real column on `posts`, which is what makes this resolve:
    // `Ownership::of()` confirms a string resolver against the table before it
    // says yes. Registered before the write only for readability — `toOwn()`
    // asks nothing either way.
    Warden::ownedVia(Post::class, 'title');

    Warden::allow($role)->toOwn(Post::class, 'update');

    $audit = Audit::of([Panel::make()->id('owning')->resources([PostResource::class])]);

    // The control. Without it a bare `return [...]` would paint the test above
    // green while naming every ownership row in the catalogue.
    expect($audit->unownable)->toBeEmpty();
});

test('an ownership row whose entity type resolves nothing is left to the drifted bucket', function (): void {
    $role = makeRole();

    Warden::allow($role)->toOwn(Post::class, 'update');

    // The morph map moved out from under the row. Said once, by `drifted`, whose
    // fix is the map; naming it here too would point the reader at ownership,
    // which is not what is wrong with it.
    permissionClass()::query()->withoutGlobalScopes()
        ->where('name', 'update')
        ->update(['entity_type' => 'gone.away']);

    $audit = Audit::of([Panel::make()->id('owning')->resources([PostResource::class])]);

    expect($audit->unownable)->toBeEmpty();
});

test('a pivot row past its date is counted, and does not redden the build', function (): void {
    $role = makeRole('editor');
    $account = makeUser();

    Warden::allow($account)->until(now()->addWeek())->to('viewAny', roleClass());
    Warden::assign($role)->until(now()->addWeek())->to($account);

    // Backdated by hand: warden refuses a past date on the way in, so this is
    // the only way to build the rows an installation gets by waiting.
    Context::resolve()->grantClass()::query()->withoutGlobalScopes()->update(['expires_at' => now()->subDay()]);
    Context::resolve()->assignedRoleClass()::query()->update(['expires_at' => now()->subDay()]);

    $audit = Audit::of([Filament::getPanel('test')]);

    // Both pivots, counted apart: `warden:clean --expired` sweeps both, and a
    // number that folded them would not say which side to look at.
    expect($audit->expired)->toHaveCount(2)
        ->and($audit->expired[0])->toStartWith('grants: ')
        ->and($audit->expired[1])->toStartWith('assignments: ');
});

test('a row still ahead of its date is not counted as expired', function (): void {
    $account = makeUser();

    Warden::allow($account)->until(now()->addWeek())->to('viewAny', roleClass());

    // The boundary is warden's, exclusive: a row counts until the instant it
    // names. Without this the bucket would report every dated row in the
    // installation and mean nothing.
    expect(Audit::of([Filament::getPanel('test')])->expired)->toBeEmpty();
});

test('the README names every bucket this class carries, and none it does not', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/README.md');

    $section = mb_substr(
        $readme,
        (int) mb_strpos($readme, '### Audit'),
        (int) mb_strpos($readme, '### Catalog Command') - (int) mb_strpos($readme, '### Audit'),
    );

    $bullets = preg_match_all('/^- \*\*/m', $section);

    // Counted rather than matched by wording: the sentences are prose and are
    // meant to read differently from the translated ones the command prints.
    // What the count catches is a bucket added to the class and never written
    // down — three of them had gone unlisted before this test existed, and the
    // section reads perfectly well without them, which is exactly why nobody
    // noticed.
    expect($bullets)->toBe(count(declaredBuckets()));
});
