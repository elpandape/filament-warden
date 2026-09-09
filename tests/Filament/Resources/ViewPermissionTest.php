<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\ViewPermission;
use ElPandaPe\FilamentWarden\Policies\RolePolicy;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages\CombinedTabsViewPermission;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Document;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Vault;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Auth;

use function Pest\Livewire\livewire;

pest()->extend(TestCase::class);

function readablePermission(): string
{
    $user = signIn();

    // Two gates, not one: the resource opens on `viewAny` and the record on `view`.
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());
    // A row the test panel's own catalogue declares: the plugin registers the
    // role resource, so `viewAny` over a role is derived from its policy.
    Warden::allow(makeRole('editor'))->to('viewAny', roleClass());

    $row = permissionClass()::query()
        ->withoutGlobalScopes()
        ->where('name', 'viewAny')
        ->orderByDesc('id')
        ->firstOrFail();

    return recordKey($row);
}

test('an authority the store never trusted with a permission does not read it', function (): void {
    signIn();

    livewire(ViewPermission::class, ['record' => makePermission('viewAny')->getKey()])->assertForbidden();
});

test('the screen names the permission, where it came from and how far it reaches', function (): void {
    $key = readablePermission();

    livewire(ViewPermission::class, ['record' => $key])
        ->assertSee('viewAny')
        ->assertSee('From a policy')
        ->assertSee('Every row')
        ->assertOk();
});

test('the provenance card names the policy method that put the row there', function (): void {
    $key = readablePermission();

    // The badge said WHAT kind of provenance since 0.6; the sentence under it
    // says WHICH method, which is the half somebody can act on. A policy method
    // renamed leaves the permission behind — matching nothing, in silence — and
    // this is the only screen that can hand over the class and method to go and
    // look at.
    livewire(ViewPermission::class, ['record' => $key])
        ->assertSee('From a policy')
        ->assertSee(RolePolicy::class.'::viewAny()')
        ->assertOk();
});

test('a permission no policy declares says that instead, and names nothing', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    // A loose permission: nothing derives it, so there is no method to name and
    // none is guessed. Naming one would be the wrong reason for a true badge.
    $loose = Warden::permission(['name' => 'export']);
    $loose->save();

    livewire(ViewPermission::class, ['record' => recordKey($loose)])
        ->assertSee('No policy in this installation declares it')
        ->assertDontSee('::export()')
        ->assertOk();
});

test('the heading carries the code name and the subheading the title', function (): void {
    $key = readablePermission();

    // `recordTitleAttribute` is `name`, because that is what a grant points at
    // and what a breadcrumb has to say. That leaves the title with nowhere to go
    // once the identity card is gone, and this is where Filament puts it.
    /** @var ViewPermission $page */
    $page = livewire(ViewPermission::class, ['record' => $key])->instance();

    expect($page->getSubheading())->toBe($page->getRecord()->getAttribute('title'));
});

test('a permission pinned to one record says so where the reach goes', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $post = Post::query()->create(['title' => 'A post']);
    Warden::allow(makeRole())->to('view', $post);

    $pinned = permissionClass()::query()
        ->withoutGlobalScopes()
        ->whereNotNull('entity_id')
        ->firstOrFail();

    livewire(ViewPermission::class, ['record' => $pinned->getKey()])
        ->assertSee('One record only')
        ->assertDontSee('Every row')
        ->assertOk();
});

test('who holds it arrives as counts, never as a list of names', function (): void {
    $key = readablePermission();

    livewire(ViewPermission::class, ['record' => $key])
        ->assertSee('Who holds it')
        ->assertDontSee('Editor')
        ->assertOk();
});

test('the test bench answers for the account it is asked about', function (): void {
    $key = readablePermission();

    $holder = makeUser('Holder');
    // The row the page is showing, and not a lookalike: `readablePermission()`
    // returns `viewAny` over a ROLE, so granting `viewAny` over a post would
    // have asked about a different permission entirely — and abstained, which
    // is exactly what the old assertion could not tell apart.
    Warden::allow($holder)->to('viewAny', roleClass());

    livewire(ViewPermission::class, ['record' => $key])
        ->set('ask.account', $holder->getKey())
        ->callAction(TestAction::make('ask')->schemaComponent('bench', 'probeForm'))
        // The card is asserted through the property and not only through the
        // page, because the wording is what travels: the answer is composed once
        // where the store is and the page prints what came back. A render
        // assertion alone would go green on a card that says the right word for
        // the wrong reason.
        ->assertSet('answered.status', 'granted')
        ->assertSee('granted');
});

test('the test bench is closed when the installation closed it', function (): void {
    config()->set('filament-warden.permissions.probe', false);

    $key = readablePermission();

    livewire(ViewPermission::class, ['record' => $key])
        // A positive assertion beside the negative one, because
        // `assertActionDoesNotExist()` swallows «could not be resolved» and
        // calls it a pass: the page still has to render, and still has to show
        // the rest of what it says.
        ->assertDontSee('Ask the store')
        ->assertSee('Who holds it');
});

test('a stored rule is read out as it will be evaluated', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    Warden::allow(makeRole())->to('update', Post::class)->where('title', 'alpha')->orWhere('id', '>=', 2);

    $twin = permissionClass()::query()->withoutGlobalScopes()->whereNotNull('options')->firstOrFail();

    livewire(ViewPermission::class, ['record' => $twin->getKey()])
        ->assertSee('title = alpha or id &gt;= 2', escape: false)
        ->assertSee('With conditions');
});

test('a row that reaches both ways still reads its rule out', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    Warden::allow(makeRole())->toOwn(Post::class, 'update')->where('title', '=', 'alpha');

    $twin = permissionClass()::query()->withoutGlobalScopes()->whereNotNull('options')->firstOrFail();

    livewire(ViewPermission::class, ['record' => $twin->getKey()])
        ->assertSee('title = alpha')
        ->assertSee('Cannot be read');
});

test('the account is searched by whatever column it can be recognised by', function (): void {
    signIn(makeUser('Signed In'));

    $amaru = makeUser('Amaru Quispe');
    makeUser('Someone Else');

    $found = ViewPermission::accounts('Amaru');

    expect($found)->toBe([recordKey($amaru) => 'Amaru Quispe'])
        ->and(ViewPermission::accountLabel($amaru->getKey()))->toBe('Amaru Quispe')
        ->and(ViewPermission::accountLabel('nope'))->toBeNull();
});

test('a permission with no model is asked without a record to put in front of it', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $loose = makePermission('export-reports');

    livewire(ViewPermission::class, ['record' => $loose->getKey()])
        ->assertSee('None: a loose permission')
        ->assertSee('Ask the store')
        // No entity, so there is no row to put in front of it and the field
        // that would ask for one is not offered.
        ->assertDontSee('The key of the row to put in front of it');
});

test('the wildcard reads as the wildcard, not as a class', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    Warden::allow(makeRole())->everything();

    $wildcard = permissionClass()::query()->withoutGlobalScopes()->where('entity_type', '*')->firstOrFail();

    livewire(ViewPermission::class, ['record' => $wildcard->getKey()])
        ->assertSee('Any entity')
        ->assertSee('Wildcard');
});

test('the test bench puts the row it is given in front of the rule', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $holder = makeUser('Holder');
    $alpha = Post::query()->create(['title' => 'alpha']);

    Warden::allow($holder)->to('update', Post::class)->where('title', 'alpha');

    $twin = permissionClass()::query()->withoutGlobalScopes()->whereNotNull('options')->firstOrFail();

    livewire(ViewPermission::class, ['record' => $twin->getKey()])
        ->set('ask.account', $holder->getKey())
        ->set('ask.record', recordKey($alpha))
        ->callAction(TestAction::make('ask')->schemaComponent('bench', 'probeForm'))
        ->assertSet('answered.status', 'granted')
        // The row is the whole point: asked about the class the same rule
        // abstains, and the note beside it says why.
        ->assertSet('answered.note', null);
});

test('an account key that names nobody is refused by the field, not by the store', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $permission = makePermission('export-reports');

    // The refusal is the field's, not the page's, and knowing which matters:
    // a searchable select builds its `in:` rule by asking
    // `getOptionLabel()` for the value it holds, which is this class's own
    // `accountLabel()`. A key that names nobody comes back blank, the rule
    // gets an empty list, and `getState()` throws before the action's body
    // runs at all. Asserting only that no card appeared would have been true
    // for a reason nobody could see.
    // The chain stops at `assertHasErrors()`: livewire declares it returning
    // `mixed`, so anything after it is a call on mixed to the analyser — the
    // same rule §6.12 measured for `assertOk()`, read off the declared type
    // rather than off what it actually hands back.
    $page = livewire(ViewPermission::class, ['record' => $permission->getKey()])
        ->set('ask.account', 9999)
        ->callAction(TestAction::make('ask')->schemaComponent('bench', 'probeForm'));

    $page->assertSet('answered', null);
    $page->assertHasErrors('ask.account');
});

test('an explicit denial comes back as a denial', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $holder = makeUser('Holder');
    Warden::forbid($holder)->to('viewAny', roleClass());

    $row = permissionClass()::query()->withoutGlobalScopes()->where('name', 'viewAny')->orderByDesc('id')->firstOrFail();

    livewire(ViewPermission::class, ['record' => $row->getKey()])
        ->set('ask.account', $holder->getKey())
        ->callAction(TestAction::make('ask')->schemaComponent('bench', 'probeForm'))
        ->assertSet('answered.status', 'forbidden');
});

test('a grant comes back as a grant, which is the word a denial has to differ from', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $holder = makeUser('Holder');
    Warden::allow($holder)->to('viewAny', roleClass());

    livewire(ViewPermission::class, ['record' => latestPermission('viewAny')->getKey()])
        ->set('ask.account', $holder->getKey())
        ->callAction(TestAction::make('ask')->schemaComponent('bench', 'probeForm'))
        ->assertSet('answered.status', 'granted');
});

test('a row nobody holds comes back as abstaining, which is neither of the two', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $holder = makeUser('Holder');

    livewire(ViewPermission::class, ['record' => makePermission('export-reports')->getKey()])
        ->set('ask.account', $holder->getKey())
        ->callAction(TestAction::make('ask')->schemaComponent('bench', 'probeForm'))
        ->assertSet('answered.status', 'abstain');
});

test('an account nobody could name is nobody at all', function (): void {
    signIn();

    expect(ViewPermission::accountLabel(null))->toBeNull();
});

test('a guard that resolves no model offers no accounts to probe with', function (): void {
    signIn();

    config()->set('auth.providers.users', ['driver' => 'database', 'table' => 'users']);
    Auth::forgetGuards();

    expect(ViewPermission::accounts('anything'))->toBeEmpty();
});

test('the test bench says how far the permission reaches, when it can be counted', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $holder = makeUser('Holder');

    Document::query()->create(['title' => 'One']);
    Document::query()->create(['title' => 'Two']);

    Warden::allow($holder)->to('view', Document::class)->where('title', 'One');

    $twin = permissionClass()::query()->withoutGlobalScopes()->whereNotNull('options')->firstOrFail();

    livewire(ViewPermission::class, ['record' => $twin->getKey()])
        ->set('ask.account', $holder->getKey())
        ->callAction(TestAction::make('ask')->schemaComponent('bench', 'probeForm'))
        // Read off the card and not recomputed beside it: the count is worked
        // out when somebody asks and never on a render, so the only place it
        // can be checked is the answer the asking produced.
        ->assertSet('answered.reach', "It falls on 1 of 2 rows. That is a query, not the panel's own answer: a policy that denies never shows up in it.");
});

test('a wildcard in the search is looked for, not obeyed', function (): void {
    signIn();

    makeUser('Ada');
    makeUser('Bob');
    $literal = makeUser('a%b');

    // Before this, `%` was a LIKE wildcard and the box paged the account table.
    $key = $literal->getKey();

    expect(ViewPermission::accounts('%'))->toBe([is_int($key) || is_string($key) ? $key : '' => 'a%b'])
        ->and(ViewPermission::accounts('_'))->toBeEmpty()
        ->and(array_values(ViewPermission::accounts('Ada')))->toBe(['Ada']);
});

test('the escape character is looked for too, not treated as an escape', function (): void {
    signIn();

    makeUser('Ada');
    $bang = makeUser('a!b');

    // `!` is the ESCAPE character in the clause, so it has to be escaped in the
    // term as well or somebody called `a!b` could never be found.
    expect(array_values(ViewPermission::accounts('!')))->toBe(['a!b'])
        ->and(array_values(ViewPermission::accounts('a!b')))->toBe(['a!b'])
        ->and($bang->getKey())->not->toBeNull();
});

test('an account model with nothing to search by finds nothing, rather than the first twenty', function (): void {
    signIn();

    makeUser('Ada');

    // A model whose table has none of name, email or title: the query had no
    // condition at all and answered with whatever came first.
    config()->set('auth.providers.users.model', Vault::class);
    Auth::forgetGuards();

    expect(ViewPermission::accounts('Ada'))->toBeEmpty();
});

test('the card is not there until somebody has asked', function (): void {
    $key = readablePermission();

    livewire(ViewPermission::class, ['record' => $key])
        ->assertSee('Ask the store')
        // Absent, not blank. The question has not been put, so there is nothing
        // to answer and nothing to leave stale under the next one.
        ->assertDontSee('What the store answered')
        ->assertSet('answered', null);
});

test('the question survives the answer, so the next one costs one field', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $holder = makeUser('Holder');
    $alpha = Post::query()->create(['title' => 'alpha']);
    $beta = Post::query()->create(['title' => 'beta']);

    Warden::allow($holder)->to('update', Post::class)->where('title', '=', 'alpha');

    $twin = permissionClass()::query()->withoutGlobalScopes()->whereNotNull('options')->firstOrFail();

    // This is the whole reason the bench left the modal: a modal threw the
    // account away on every submit, so comparing two rows meant finding the
    // same person twice. Changing only the record has to be enough.
    livewire(ViewPermission::class, ['record' => $twin->getKey()])
        ->set('ask.account', $holder->getKey())
        ->set('ask.record', recordKey($alpha))
        ->callAction(TestAction::make('ask')->schemaComponent('bench', 'probeForm'))
        ->assertSet('answered.status', 'granted')
        ->assertSet('ask.account', $holder->getKey())
        ->set('ask.record', recordKey($beta))
        ->callAction(TestAction::make('ask')->schemaComponent('bench', 'probeForm'))
        ->assertSet('answered.status', 'abstain');
});

test('a card whose grant ends says when, under the verdict', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $holder = makeUser('Holder');
    Warden::allow($holder)->until(now()->addWeeks(2))->to('viewAny', roleClass());

    livewire(ViewPermission::class, ['record' => latestPermission('viewAny')->getKey()])
        ->set('ask.account', $holder->getKey())
        ->callAction(TestAction::make('ask')->schemaComponent('bench', 'probeForm'))
        ->assertSet('answered.status', 'granted')
        ->assertSee('Until')
        ->assertSee('The grant that answered ends on');
});

test('a row with nothing to say about time says nothing about time', function (): void {
    $key = readablePermission();

    $holder = makeUser('Holder');
    Warden::allow($holder)->to('viewAny', roleClass());

    livewire(ViewPermission::class, ['record' => $key])
        ->set('ask.account', $holder->getKey())
        ->callAction(TestAction::make('ask')->schemaComponent('bench', 'probeForm'))
        ->assertSet('answered.until', null)
        // The LABEL and not the sentence. Asserting the sentence away was true
        // whether the row was absent or present-and-empty — measured, by
        // dropping the visibility guard and watching this stay green. What the
        // guard decides is whether the word «Until» is on the page at all, so
        // that is what is asked about.
        ->assertDontSee('Until');
});

test('a class check against a narrowed rule prints the note it needs', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $holder = makeUser('Holder');
    Warden::allow($holder)->to('update', Post::class)->where('title', '=', 'alpha');

    $twin = permissionClass()::query()->withoutGlobalScopes()->whereNotNull('options')->firstOrFail();

    livewire(ViewPermission::class, ['record' => $twin->getKey()])
        ->set('ask.account', $holder->getKey())
        ->callAction(TestAction::make('ask')->schemaComponent('bench', 'probeForm'))
        ->assertSet('answered.status', 'abstain')
        ->assertSee('Why the class could not answer')
        ->assertSee('needs a record in front of it');
});

test('the bench survives relation managers folded into the content tab', function (): void {
    $key = readablePermission();

    // The arrangement the parent's own `content()` branches on, and the one an
    // installation turns on with one method. Written out rather than trusted:
    // the bench rides in the content tab there, and a page that quietly lost it
    // would look identical to one that never had it.
    livewire(CombinedTabsViewPermission::class, ['record' => $key])
        ->assertOk()
        ->assertSee('Ask the store');
});

test('a permission can be handed straight to an account, with an end date', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $holder = makeUser('Holder');
    $row = makePermission('export-reports');

    livewire(ViewPermission::class, ['record' => $row->getKey()])
        ->callAction('give', [
            'account' => $holder->getKey(),
            'forbidden' => 0,
            'until' => now()->addWeeks(2)->toDateString(),
        ])
        ->assertNotified();

    $grant = Context::resolve()->grantClass()::query()
        ->withoutGlobalScopes()
        ->where('permission_id', $row->getKey())
        ->where('entity_id', $holder->getKey())
        ->firstOrFail();

    expect($grant->getAttribute('forbidden'))->toBeFalsy()
        ->and($grant->getAttribute('expires_at'))->not->toBeNull();
});

test('a prohibition is written without a date, because warden refuses one', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $holder = makeUser('Holder');
    $row = makePermission('export-reports');

    // The date is not merely hidden on this branch: `ForbidsPermissions::until()`
    // throws unconditionally, `null` included, so a screen that passed one
    // along — even an empty one — would 500 instead of writing.
    livewire(ViewPermission::class, ['record' => $row->getKey()])
        ->callAction('give', ['account' => $holder->getKey(), 'forbidden' => 1])
        ->assertNotified();

    $grant = Context::resolve()->grantClass()::query()
        ->withoutGlobalScopes()
        ->where('permission_id', $row->getKey())
        ->where('entity_id', $holder->getKey())
        ->firstOrFail();

    expect($grant->getAttribute('forbidden'))->toBeTruthy()
        ->and($grant->getAttribute('expires_at'))->toBeNull();
});

test('an authority that may not edit the row may not hand it out either', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $row = makePermission('export-reports');
    $holder = makeUser('Holder');

    // Reading is not handing out. Without `update` the button is not offered —
    // and Filament refuses to mount an action it will not show, so the bare
    // livewire pair writes nothing either.
    livewire(ViewPermission::class, ['record' => $row->getKey()])
        ->assertActionHidden('give')
        ->call('mountAction', 'give', [])
        ->call('callMountedAction', []);

    expect(Context::resolve()->grantClass()::query()->withoutGlobalScopes()->where('entity_id', $holder->getKey())->count())->toBe(0);
});

test('the counts beside the button are re-read after a hand-out', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $holder = makeUser('Holder');
    $row = makePermission('export-reports');

    // `Holders` memoises by instance, and the page keeps the same record across
    // the write and the re-render — so without the forget the accounts figure
    // would still be the one from before the button was pressed.
    livewire(ViewPermission::class, ['record' => $row->getKey()])
        ->assertSee('Accounts')
        ->callAction('give', ['account' => $holder->getKey(), 'forbidden' => 0]);

    expect(ElPandaPe\FilamentWarden\Grants\Holders::of($row->fresh() ?? $row)->accountCount)->toBe(1);
});

test('a grant that has already lapsed is not counted as ending', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $row = makePermission('export-reports');

    $live = makeUser('Live');
    $dead = makeUser('Dead');

    Warden::allow($live)->until(now()->addWeek())->to($row);
    Warden::allow($dead)->until(now()->addWeek())->to($row);

    // Backdated by hand rather than by a fluent call, because warden refuses a
    // date in the past on the way in — which is right, and leaves this the only
    // way to build the row an installation gets by waiting.
    Context::resolve()->grantClass()::query()
        ->withoutGlobalScopes()
        ->where('entity_id', $dead->getKey())
        ->update(['expires_at' => now()->subDay()]);

    // `Holders` counts both, and that is not a disagreement: it answers what a
    // DELETE destroys, and the cascade takes a lapsed grant like any other. This
    // figure answers what is about to stop, and a row that already stopped is
    // not about to do anything.
    livewire(ViewPermission::class, ['record' => $row->getKey()])
        ->assertSee('Ending')
        ->assertOk();

    expect(ElPandaPe\FilamentWarden\Grants\Holders::of($row)->accountCount)->toBe(2);
});

test('ownership is stated on the screen, not left to be inferred from the rule', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    Warden::allow(makeRole('editor'))->toOwn(Post::class, 'update');

    $owned = permissionClass()::query()
        ->withoutGlobalScopes()
        ->where('only_owned', true)
        ->firstOrFail();

    // «Only what it owns» is a shape the reach badge already says, and saying it
    // twice is not the point: the badge collapses to one word for a row that is
    // BOTH owned and conditioned, and this field is the half that survives that.
    livewire(ViewPermission::class, ['record' => $owned->getKey()])
        ->assertSee('Only what it owns')
        ->assertSee('yes');
});
