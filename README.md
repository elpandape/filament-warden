# Filament Warden

[![Tests](https://github.com/elpandape/filament-warden/actions/workflows/run-tests.yml/badge.svg)](https://github.com/elpandape/filament-warden/actions/workflows/run-tests.yml)
[![Quality](https://github.com/elpandape/filament-warden/actions/workflows/quality.yml/badge.svg)](https://github.com/elpandape/filament-warden/actions/workflows/quality.yml)
[![Latest version](https://img.shields.io/packagist/v/elpandape/filament-warden.svg)](https://packagist.org/packages/elpandape/filament-warden)
[![Downloads](https://img.shields.io/packagist/dt/elpandape/filament-warden.svg)](https://packagist.org/packages/elpandape/filament-warden)
[![PHP](https://img.shields.io/packagist/dependency-v/elpandape/filament-warden/php.svg)](composer.json)
[![Filament](https://img.shields.io/packagist/dependency-v/elpandape/filament-warden/filament%2Ffilament.svg?label=filament)](composer.json)
[![License](https://img.shields.io/packagist/l/elpandape/filament-warden.svg)](LICENSE.md)

> Roles and permissions for [Filament](https://filamentphp.com), built on
> [elpandape/warden](https://github.com/elpandape/warden) — a permission grid derived from
> your policies, explicit denials, and conditional grants.

**Status: `1.0.1` — the freeze.** A roles screen with the permission grid inside it, derived
from your policies; an inspector that says why each cell is the way it is; a builder that
narrows a grant to the rows it should reach; a permissions screen that says where every row
of the catalogue came from; a field that hands roles to an account; and a guard that stops a
panel starting with a screen nobody decides.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start) — zero to a working panel, end to end
- **Wiring it up**
  - [Write your policies on top of the base class](#write-your-policies-on-top-of-the-base-class)
  - [Close the door of the panel](#close-the-door-of-the-panel)
  - [Close your pages and widgets](#close-your-pages-and-widgets)
  - [Ask warden yourself](#ask-warden-yourself)
  - [Hand roles to an account](#hand-roles-to-an-account)
  - [The way back](#the-way-back)
- **The screens**
  - [The grid](#the-grid)
  - [How far a rule reaches](#how-far-a-rule-reaches)
  - [The permissions screen](#the-permissions-screen)
- **Keeping it honest**
  - [The guard](#the-guard)
  - [The audit](#the-audit)
- **Going further**
  - [How far a permission reaches](#how-far-a-permission-reaches)
  - [Multi-tenancy](#multi-tenancy)
  - [Read the catalogue](#read-the-catalogue)
  - [Replacing what this package registers](#replacing-what-this-package-registers)
- [Configuration](#configuration)
- [Why this package](#why-this-package)
- [Stability](#stability)
- [Development](#development)

## Requirements

| | |
|---|---|
| PHP | 8.5 |
| Laravel | 13 |
| Filament | 5.7 |
| elpandape/warden | 1.0 |

## Installation

```bash
composer require elpandape/filament-warden
```

Register the plugin on the panel that should carry the roles and permissions screens:

```php
use ElPandaPe\FilamentWarden\FilamentWardenPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(FilamentWardenPlugin::make());
}
```

Create warden's tables. Warden **publishes** its schema rather than loading it from vendor,
so nothing exists until this runs — and the first thing you click otherwise is a missing
table:

```bash
php artisan warden:install --migrate
```

Publish the package's assets so the grid arrives with its styles:

```bash
php artisan filament:assets
```

This is a plain file copy, not a build step — there is no Tailwind and no bundler in this
package — and it has to run again on every deploy, so applications usually hang it off
composer's `post-autoload-dump`. Until it runs, the panel asks for a stylesheet that answers
404 and the grid renders unstyled.

Three optional publishes:

```bash
php artisan vendor:publish --tag=filament-warden-config          # what the screens let people do
php artisan vendor:publish --tag=filament-warden-translations    # reword the interface
php artisan vendor:publish --tag=filament-warden-views           # rewrite the grid's markup
```

Read the note under [Stability](#stability) before the third one: a published view is welded
to this package's insides.

## Quick start

The whole path, for an application with an `Order` model and an admin panel. Five steps and
one command.

**1. Give the model a policy on top of the base class.** The actions it declares are the
columns the grid will draw — no more, no less.

```php
// app/Policies/OrderPolicy.php
use App\Models\Order;
use App\Models\User;
use ElPandaPe\FilamentWarden\Policies\WardenPolicy;

final class OrderPolicy extends WardenPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'viewAny', Order::class);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->allows($user, 'view', $order);
    }

    public function update(User $user, Order $order): bool
    {
        return $this->allows($user, 'update', $order);
    }
}
```

**2. Let the panel be locked.** Filament lets every authenticated user in unless the account
model says otherwise:

```php
// app/Models/User.php
use ElPandaPe\FilamentWarden\Concerns\AccessesPanels;
use Filament\Models\Contracts\FilamentUser;

final class User extends Authenticatable implements FilamentUser
{
    use AccessesPanels;
}
```

**3. Turn on strict authorization**, so a resource with no policy fails closed instead of
open:

```php
// app/Providers/Filament/AdminPanelProvider.php
return $panel
    ->strictAuthorization()
    ->plugin(FilamentWardenPlugin::make());
```

**4. Give every page and widget of your own a decision.** From `0.8.0` the panel refuses to
start without this — see [The guard](#the-guard):

```php
use ElPandaPe\FilamentWarden\Filament\Concerns\AuthorizesPageAccess;
use ElPandaPe\FilamentWarden\Filament\Concerns\AuthorizesWidgetView;

final class Reports extends Page
{
    use AuthorizesPageAccess;      // canAccess() → page:App\Filament\Pages\Reports
}

final class RevenueChart extends ChartWidget
{
    use AuthorizesWidgetView;      // canView()   → widget:App\Filament\Widgets\RevenueChart
}
```

**5. Let yourself in.** You have just locked the door you are standing behind, and the roles
screen is behind it too — so the first role cannot come from the panel. It comes from a
seeder:

```php
// database/seeders/WardenSeeder.php
use ElPandaPe\Warden\Facades\Warden;

$role = Warden::role(['name' => 'super-admin']);
$role->save();

Warden::allow($role)->everything();
```

`everything()` is warden's wildcard, and the grid cannot mint it: a permission over `*` is
not a cell, so there is no switch to click. `super-admin` is also protected out of the box —
it keeps its name and its permissions, neither can be edited, and it cannot be deleted.

Then hand it to yourself from the console:

```bash
php artisan filament-warden:assign super-admin "App\Models\User:1"
```

**Now open `/admin/roles`.** The grid draws one row per entity and one column per action your
policies declare. Click a cell to grant, click again to forbid, save, and the store answers
for it on the next request.

### What just happened

- `OrderPolicy` declared three actions, so `Order` gets three switches — plus the wildcard
  cell every entity row carries. Delete `update` from the policy and its cell turns into a
  dot: the column stays, because some other model still declares that action, but nobody can
  grant it here.
- Nothing invented a permission name. `viewAny` over `App\Models\Order` is a row in warden's
  catalogue, stored **against the model**, so renaming the resource orphans nothing.
- The panel, the page and the widget got permissions of their own — `panel:admin`, `page:…`,
  `widget:…` — because no policy declares those and somebody has to.

### Check it from a test

```php
use function Filament\get_authorization_response;

it('lets a granted account list orders', function (): void {
    $user = User::factory()->create();

    $role = Warden::role(['name' => 'order-clerk']);
    $role->save();

    Warden::allow($role)->to('viewAny', Order::class);
    Warden::assign($role)->to($user);

    Auth::login($user);

    expect(get_authorization_response('viewAny', Order::class)->allowed())->toBeTrue();
});
```

Use `get_authorization_response()` rather than `Gate::allows()`. Warden's gate hook answers
the same thing your policy does, so `Gate::allows()` returns the same value whether or not
the policy is registered at all — and the assertion that carries the guarantee is the
negative one: *without a grant, denied*.

## Wiring it up

### Write your policies on top of the base class

A policy declares the actions that exist for its model, and nothing else. The base class
brings none of its own, and resolves through warden's store rather than through the gate —
going through the gate would resolve this very policy and never finish the question.

```php
use ElPandaPe\FilamentWarden\Policies\WardenPolicy;

final class OrderPolicy extends WardenPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'viewAny', Order::class);
    }
}
```

`allows()` is `protected` and takes the authority first — `$this->allows($user, $action,
$entity)`. Pass the class for a listing and the record for a single row.

Two methods, so two permissions. Nothing offers `restore` for a model that cannot be
restored.

### Close the door of the panel

The permission is derived from the panel id — a panel called `admin` is opened by
`panel:admin` — so two panels are two doors.

```php
use ElPandaPe\FilamentWarden\Concerns\AccessesPanels;
use Filament\Models\Contracts\FilamentUser;

final class User extends Authenticatable implements FilamentUser
{
    use AccessesPanels;
}
```

An installation that already stores another name maps it in the config instead of renaming
rows:

```php
'guard' => [
    'panel' => ['admin' => 'viewAdminPanel'],
],
```

To add a condition of your own, alias the trait's method rather than replacing it:

```php
use AccessesPanels {
    canAccessPanel as wardenCanAccessPanel;
}

public function canAccessPanel(Panel $panel): bool
{
    return $this->isActive() && $this->wardenCanAccessPanel($panel);
}
```

**Be careful what you fold in here.** Filament calls `canAccessPanel()` from four places and
only one is the middleware that answers with a 403:

- `Login` throws the *same* validation exception as a wrong password, so the account is told
  its credentials do not match;
- both password-reset pages fail **silently** — no link is sent, and the screen still says
  one was.

For a condition the account is supposed to *resolve* rather than simply fail, that is a dead
end with no way to read it. Email verification, for instance, belongs in Filament's own
`->emailVerification()`, which lets them in and then routes them to the prompt.

### Close your pages and widgets

`Page::canAccess()` and `Widget::canView()` return `true` literally in Filament, and
`->strictAuthorization()` reaches neither — it covers resources, relation managers and
Filament's own tenancy pages, and nothing else.

```php
use ElPandaPe\FilamentWarden\Filament\Concerns\AuthorizesPageAccess;
use ElPandaPe\FilamentWarden\Filament\Concerns\AuthorizesWidgetView;

final class Reports extends Page
{
    use AuthorizesPageAccess;
}

final class RevenueChart extends ChartWidget
{
    use AuthorizesWidgetView;
}
```

Two traits and not one, because Filament asks two different questions: a page and a panel
answer `canAccess()`, a widget answers `canView()`. A widget is *seen*; a page is *entered*.
The generated titles say the same.

Pages of a resource need nothing: they are already covered by the resource's own
`canAccess()`, which asks the model's policy.

### Ask warden yourself

For a screen that needs its own rule rather than the door permission:

```php
use ElPandaPe\FilamentWarden\Support\Access;

public static function canAccess(): bool
{
    return Access::grantedToCurrentUser('viewAny', Invoice::class)
        && Access::grantedToCurrentUser('export-reports');
}
```

Three shapes:

```php
Access::grantedToCurrentUser('export-reports');            // loose, current account
Access::grantedToCurrentUser('viewAny', Invoice::class);   // over a class
Access::granted($otherUser, 'view', $invoice);             // another authority, one row
```

**Use this rather than `$user->can()`.** The two agree until they do not:

| | `$user->can('export-reports')` | `Access::grantedToCurrentUser(…)` |
|---|---|---|
| not granted | `false` | `false` |
| granted | `true` | `true` |
| granted, with `warden.gate.register` off | **`false`** | `true` |

That last row is why `Access` exists. Warden ships that switch so an application can register
its own gate callback, and the day one does, every `$user->can('export-reports')` starts
answering `false` with no error to read — a loose permission has no policy to answer for it,
so if warden's hook is gone there is nobody left. `Access` goes straight to the resolver, and
picks the account up through `Filament::auth()`, which is not necessarily the default guard.

Never write a door permission by hand. `PermissionName` is the only place they are minted
**and** the only place they are read back:

```php
use ElPandaPe\FilamentWarden\Catalog\PermissionName;

PermissionName::page(Reports::class);         // page:App\Filament\Pages\Reports
PermissionName::widget(RevenueChart::class);  // widget:App\Filament\Widgets\RevenueChart
PermissionName::panel($panel);                // panel:admin, or your configured override
```

### Hand roles to an account

Add one line to your own account resource's form:

```php
use ElPandaPe\FilamentWarden\Filament\Forms\RoleAssignment;

RoleAssignment::make('roles')->columnSpanFull(),
```

It has to be your line because Filament reads a resource's relation managers and fields from
the resource class itself, and a package cannot write to `getRelations()` from outside —
Filament's own generator says the same thing when it scaffolds one.

**Do not reach for `CheckboxList::make('roles')->relationship(...)` instead.** It looks
equivalent and it is not: `->relationship()` saves through `sync()`, and `sync()`, `attach()`
and `detach()` all skip warden's cache invalidation. A role handed out that way goes on
answering the old way, silently and with no expiry. This field writes through warden's own
API, so the store answers for a role the moment it is given.

You may hand out a role if you may edit it — `update` over that role. A role you cannot edit
is shown, locked, and says why; so is a role this account holds *in a context*, because
taking that back would take every context with it.

### The way back

```bash
php artisan filament-warden:assign super-admin "App\Models\User:1"
```

For whoever locks themselves out. It refuses a role that does not exist rather than creating
one — assigning by name would have minted it, silently and with a generated title — and it
only hands roles out: you lock yourself out by not having a role, never by having one.

## The screens

### The grid

Registering the plugin puts a roles screen on the panel. Inside its form, the grid draws one
row per entity and one column per action the policy declares — grouped by scope, so listing
records does not look like deleting them for good — plus a wildcard column.

A cell cycles: click to go from abstaining to granted to forbidden, and hold shift to walk
the cycle backwards and reach a denial in one step. An action no policy declares is a dot and
not a control, because nobody could grant it.

Nothing is written until you save, and the save is a diff — only the cells that changed reach
the store.

Click a cell and the inspector beside the grid says why it is the way it is: the cause, the
permission that decided it, and the role it came through. It tells an explicit denial apart
from warden abstaining — two different answers that most stores cannot distinguish. It is
asked and never volunteered, because one explanation is three to seven queries and a grid
full of them would be hundreds.

The answer is about what is stored, so if you have cycled a cell and not saved it, the panel
says that too rather than appearing to contradict the screen.

A role can also be read without being changed: `view` and `update` are separate permissions,
and the read-only screen draws the same grid and answers the same questions.

### How far a rule reaches

Under the explanation, a cell that grants or forbids something says how far it reaches, in
three alternatives: **every row**, **only what it owns**, or **with these conditions**.
Ownership is offered only when it can actually resolve — warden's default attribute is the
literal `user_id` and cannot be turned off, so a model whose table has no such column is told
why the alternative is closed to it.

The conditions are a flat list. Each line compares a column of the row against a value or
against a column of the account being checked, with one of warden's six operators, and each
line carries the joiner that ties it to the one above. `and` binds tighter than `or`, the way
it does in SQL, so the builder boxes the lines a group contains and prints the rule as it
will be read:

```text
name = editor or (scope >= 2 and title = account.name)
```

A cell marked amber is narrowed: with conditions, the grant only answers with a record in
front of it, and a class check — the one a listing makes when it asks `viewAny` — fails
closed. A cell marked red is one this screen can read and cannot draw: conditions that do not
deserialize, or two rules stored for the same action. Those are shown, explained, and never
written over.

Every write goes through warden's fluent API, in one transaction, and clears the cell in both
of its shapes first: `to()` and `toOwn()` are disjoint revokes, and editing a condition any
other way leaves the previous twin's grant standing and the old rule still authorizing.

### The permissions screen

Registering the plugin also puts a permissions screen on the panel, and with the shipped
defaults it is a reading surface: nothing can be created, only orphans can be deleted, and of
a derived permission only the label can be rewritten — its name, its entity and its rule are
all closed, because those are what connect it to the policy that asks for it. A loose
permission, which no policy declares, can be rewritten whole. Opening any of that up is one
line of `permissions.*` config; closing it again afterwards means cleaning up whatever was
created meanwhile.

What it shows that warden cannot say for itself:

- **Where each row came from** — derived from a policy, loose, the wildcard, or *nothing
  declares it*. The last one is a permission no code asks for any more: a renamed policy
  method, a typo in a seeder, a screen that was deleted.
- **How far it reaches** — every row, only what it owns, or with conditions. A permission
  carrying conditions is a twin of the plain row, and without this column the two are
  indistinguishable.
- **Who holds it**, as counts, with explicit denials counted apart.
- **A test bench**: pick an account and, if the permission has a model, the key of a row, and
  warden answers with its verdict and its cause. It is the only place in the panel where the
  question is asked the way your application asks it.

Two things worth knowing before you edit anything here. Editing the conditions of a
permission changes the rule for **everyone holding that row** — a constrained permission is a
shared twin, which is correct for a catalogue and is said on screen. And deleting a
permission takes its grants with it through a foreign key, below Eloquent and with no event
of its own, so the confirmation names who loses it: afterwards there is no trace.

## Keeping it honest

### The guard

**From `0.8.0`, a panel does not start with a page or a widget that does not decide who gets
in.** Filament's own `Page::canAccess()` and `Widget::canView()` return `true` literally, and
`->strictAuthorization()` covers resources, relation managers and tenancy pages — never a
page or widget of yours. A screen registered without a decision is open to anybody who can
reach the panel, in silence and with nothing to read.

The exception names the screens. Give each one a decision:

```php
use ElPandaPe\FilamentWarden\Filament\Concerns\AuthorizesPageAccess;

class Reports extends Page
{
    use AuthorizesPageAccess;
}
```

Filament's own screens are its own business; this is about the ones you or a package
registered. Turn either half off with `guard.pages` / `guard.widgets`.

### The audit

```bash
php artisan filament-warden:audit
php artisan filament-warden:audit --check   # exits 1 when anything is found
```

It writes nothing, and reports six things:

- **screens nobody guards** — the same finding the guard throws on, which is how it reaches
  CI at all: no artisan command ever starts a panel;
- **resources whose model has no policy** — the case Filament fails open on, told apart from
  a policy that declares nothing and from a resource pointing at a class that does not exist;
- **permissions no grant points at** — `warden:clean` is what removes them;
- **grants for actions nothing declares any more** — a renamed policy method, a typo in a
  seeder, a screen that was deleted: the silent mistake warden has no way to detect;
- **whole entity types nothing declares** — a morph alias that moved, reported apart because
  the fix is the opposite one;
- **models only a relation manager reaches**, with the `catalog.models` line that settles it.

## Going further

### How far a permission reaches

The test bench also answers *over how many rows* a permission falls, for the account being
probed. It is opt-in on your side, one line on the model:

```php
use ElPandaPe\Warden\Concerns\QueriesByPermission;

class Document extends Model
{
    use QueriesByPermission;
}
```

Without it the screen says so and names the model, rather than printing a number: warden's
`whereCan()` does not fail on a model that never composed the trait — Eloquent turns the call
into a dynamic `where "can" = ?` and answers zero rows in silence.

**The number is a lower bound, not a fact**, and the screen says when. A role assigned in a
context is excluded from `whereCan()`'s grant pass, so the panel itself will answer `true`
for rows the query cannot see. `whereCan()` also never consults the Gate or a policy.

### Multi-tenancy

Two different things are called tenancy here and only one of them is Filament's. Warden
scopes its own four tables with a `scope` column; Filament scopes a panel with a tenant
model. **Nothing in warden knows about `Filament::getTenant()`** — bridging them is a
`TenantResolver` in your application.

This package's own resources declare that they belong to no tenant of Filament's, which they
must: Filament scopes a resource by adding a global scope to its **model**, and that scope
demands a relationship named after the tenant class. Without it, every read of warden's
`Role` and `Permission` throws.

With no tenant active, warden's shipped configuration reads every tenant at once. The screens
show exactly that and say so, rather than hiding rows and disagreeing with what `can()`
answers in the same request. A grant that belongs to another tenant is drawn, marked, and
cannot be changed from there: a write targets one tenant, so switching it off would delete
nothing and still report success.

### Read the catalogue

```php
use ElPandaPe\FilamentWarden\Catalog\Catalog;
use Filament\Facades\Filament;

$entries = Catalog::for(Filament::getPanel('admin'))->entries;

foreach ($entries as $entry) {
    $entry->name;         // 'viewAny', or 'page:App\Filament\Pages\Reports'
    $entry->entityType;   // the morph alias, or null for a loose permission
    $entry->model;        // the class behind it, or null
    $entry->scope;        // Scope::Read | Write | Withdraw | Irreversible
    $entry->origin;       // Origin::Resource | Model | Page | Widget | Custom | Panel
    $entry->key();        // 'viewAny|order' — same action, same entity
}
```

The catalogue is **per panel** and computed when it is asked, never at boot: a plugin cannot
trust its position in the registration chain, and `Panel::boot()` never runs in the console.

A model with a policy but no resource is declared in `catalog.models`; a permission that
belongs to no model at all goes in `catalog.custom`, with its scope:

```php
'catalog' => [
    'models' => [\Laravel\Passkeys\Passkey::class],
    'custom' => ['export-reports' => 'read'],
],
```

A custom name cannot contain a dot. Livewire addresses component state by splitting paths on
them, so `reports.export` would address a nested array that does not exist and take the grid
down with it. Rather than render something broken, the grid refuses to draw and names the
permission in the message. Use `export-reports` or `reports:export`.

### Replacing what this package registers

The policies for warden's `Role` and `Permission` are registered by this package, because
Laravel's policy guessing walks up the **model's own** namespace — for `Warden\Models\Role` it
tries `ElPandaPe\Policies\RolePolicy`, `ElPandaPe\Warden\Policies\RolePolicy` and
`ElPandaPe\Warden\Models\Policies\RolePolicy` — and never reaches this package's
`ElPandaPe\FilamentWarden\Policies\`. None of its candidates exist. An application that wants its own registers it in its own service
provider, which boots after every package provider and therefore wins.

## Configuration

Every key below is covered by [Stability](#stability). The defaults are conservative on
purpose: opening one up later is a line of config, closing it later means cleaning up
whatever was created meanwhile.

### permissions — what the permissions screen allows

| Key | Default | Values |
|---|---|---|
| `create` | `false` | `true` \| `false` |
| `update` | `'loose'` | `false` \| `'title'` \| `'loose'` \| `'all'` |
| `delete` | `'orphaned'` | `false` \| `'orphaned'` \| `'all'` |
| `constraints` | `true` | the condition builder |
| `only_owned` | `true` | the ownership checkbox |
| `probe` | `true` | the test bench |

`'all'` on `update` includes the **name** of a derived permission, which is what disconnects
it from its policy.

### roles — what the roles screen allows

| Key | Default | Values |
|---|---|---|
| `create` | `true` | `true` \| `false` |
| `delete` | `'unassigned'` | `false` \| `'unassigned'` \| `'all'` |
| `protected` | `['super-admin']` | role names |

A protected role keeps its name and its permissions: both are shown, neither can be edited,
and it cannot be deleted. Its title is left editable — nothing resolves by it.

### guard — the screens that must decide

| Key | Default | Meaning |
|---|---|---|
| `panel` | `[]` | override the panel permission, keyed by panel id |
| `pages` | `true` | refuse to start on an unguarded page |
| `widgets` | `true` | refuse to start on an unguarded widget |

### grid — the role grid

| Key | Default | Meaning |
|---|---|---|
| `explain` | `true` | the inspector |
| `constraints` | `true` | show how far a cell reaches |

`constraints` appears here as well as under `permissions` on purpose: they are two separate
decisions. Conditions are defined only on a permission's own screen, where they are seen
whole, leaving the grid to hand things out.

### catalog — what the catalogue contains

| Key | Default | Meaning |
|---|---|---|
| `models` | `[]` | models with a policy and no resource |
| `custom` | `[]` | loose permissions, as `name => scope` |
| `scopes` | four groups | which actions fall in `read`, `write`, `withdraw`, `irreversible` |

### navigation

| Key | Default | Meaning |
|---|---|---|
| `group` | `null` | falls back to this package's own translated group |
| `roles.slug`, `permissions.slug` | `'roles'`, `'permissions'` | what the URL says |
| `roles.icon` | `null` | falls back to a shield |
| `permissions.icon` | `null` | falls back to a key |
| `roles.sort`, `permissions.sort` | `null` | navigation order |

> **The merge is shallow, on purpose.** Declaring `roles.protected => []` in your published
> config genuinely unprotects every role. A recursive merge would blend lists by index and
> silently keep `'super-admin'` in there — so it is not used, and a test holds that line.

## Why this package

There is already a well-known permissions plugin for Filament, and for most projects it is
the right answer. This one exists for what Warden does that others do not: explicit denials
that beat any grant, per-instance permissions, and conditions attached to a grant.

What it adds on top of a wall of checkboxes:

- **The catalogue is derived from your policies, and only from them.** A resource offers
  exactly the actions its policy declares. Delete a method and its switch disappears.
- **Permissions are stored against models**, not as strings: renaming a resource orphans
  nothing.
- **A denial is a state, not an absence.**
- **Every switch can say why it is where it is**, naming the row and the role that decided.

## Stability

From `1.0.0` on, everything below is covered by semantic versioning: changing any of it is a
major. `tests/FrozenTest.php` is what says so — it fails when one of them moves.

Two different kinds of thing are in that list and both matter for the same reason.

**The names are rows in your database.** A permission called
`page:App\Filament\Pages\Settings` was granted to a role a year ago. Renaming the prefix does
not fail: the row stays, stays grantable, and opens nothing.

**The keys are lines in your application** — a published config, an overridden translation, a
command in a deploy script. Removing one is silent here and loud there.

### Covered

| | |
|---|---|
| Permission names | the `page:`, `widget:` and `panel:` prefixes, and `PermissionName` which mints and reads them |
| The plugin | `FilamentWardenPlugin`, its id `filament-warden`, and the methods it accepts |
| Fields | `PermissionGrid`, `PermissionGridEntry`, `ConditionBuilder`, `RoleAssignment`, and the `{stances, narrowing}` state envelope a form receives |
| Traits | `AuthorizesPageAccess`, `AuthorizesWidgetView`, `AccessesPanels` |
| Authorization | `WardenPolicy`, `Access` |
| The catalogue | `Catalog::for()`, `Entry` and its `key()`, `Origin`, `Scope` |
| The guard | `PanelIsOpen` |
| Config | every key of `config/filament-warden.php` — `FrozenTest` pins the six top-level blocks, the promise covers the keys inside them too |
| Translations | every key path of `lang/*/ui.php`, both locales |
| Console | `filament-warden:assign` and `filament-warden:audit`, with their arguments |

Adding a translation key or a config key is a minor, not a major: nothing you wrote stops
working. Only removing or renaming one is a break.

### Not covered

`Grants\`, `Conditions\`, `Filament\Guard`, `Filament\Forms\Grid\` and everything in
`Catalog\` other than those named above are this package's insides. They move without warning,
in any release.

Three consequences worth saying out loud, because each one is a place the line is easy to
cross by accident:

- **A published view is pinned to those insides.** `filament-warden-views` is a real escape
  hatch and you are welcome to it, but your copy calls `$getGrid()` and walks a `GridView`,
  its tabs, rows and cells — all internal. Expect to re-merge it on a minor. If you want
  markup that keeps working, wrap the field rather than forking its view.
- **The screens are not an extension point.** `RoleResource`, `PermissionResource` and their
  pages are left non-final so you can experiment, not because subclassing them is supported.
  They change whenever the screens change.
- **`whereCan()` is warden's, not ours.** Its answer can disagree with the panel's, and it
  never consults the Gate or a policy.

### Not frozen: the word account

The condition builder offers the signed-in account's columns as `account.id` and the like.
That word is a placeholder for whatever an application calls its user model, and it may
change. Nothing is stored under it — it is a label on a screen.

## Development

The host needs no PHP or Composer — everything runs through Docker:

```bash
make build      # build the dev image
make install    # composer install
make test       # the test suite
make ci         # every gate CI runs
```

See [CHANGELOG.md](CHANGELOG.md) for what each version adds, and
[CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

## Credits

- [Carlos Mayorga](https://carlosmayorga.me/)

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
