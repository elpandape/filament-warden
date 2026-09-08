<p align="center">
  <img src="https://repository-images.githubusercontent.com/1338877806/555f5626-51c1-4808-a8e6-ba7e98fe60bd" alt="Filament Warden" width="800">
</p>

<h1 align="center">Filament Warden</h1>

<p align="center">
  <strong>Advanced roles and permissions for Filament</strong><br>
  Built on <code>elpandape/warden</code> — a permission grid derived from your policies, explicit denials, and conditional grants.
</p>

<p align="center">
  <a href="https://packagist.org/packages/elpandape/filament-warden"><img src="https://img.shields.io/packagist/v/elpandape/filament-warden?style=flat-square&color=blue" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/elpandape/filament-warden"><img src="https://img.shields.io/packagist/dt/elpandape/filament-warden?style=flat-square&color=green" alt="Total Downloads"></a>
  <a href="LICENSE.md"><img src="https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square" alt="License"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php" alt="PHP 8.4"></a>
  <a href="https://laravel.com"><img src="https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel" alt="Laravel 13"></a>
  <a href="https://filamentphp.com"><img src="https://img.shields.io/badge/Filament-5.7-orange?style=flat-square" alt="Filament 5.7"></a>
</p>

---

## 📖 Table of Contents

- [✨ Features](#-features)
- [📋 Requirements](#-requirements)
- [⬆️ Upgrading to 2.0](#upgrading-to-20-from-1x)
- [🚀 Installation](#-installation)
- [⚡ Quick Start](#-quick-start)
- [🔌 Setup](#-setup)
    - [Plugin Options](#plugin-options)
    - [Policies](#policies)
    - [Lock the Panel](#lock-the-panel)
    - [Lock Pages & Widgets](#lock-pages--widgets)
    - [Assign Roles to Users](#assign-roles-to-users)
    - [Query Permissions Manually](#query-permissions-manually)
    - [Permission Names](#permission-names)
- [🧪 Testing Your Authorization](#testing-your-authorization)
- [🖥️ The Screens](#-the-screens)
    - [The Permission Grid](#the-permission-grid)
    - [Permission Inspector](#permission-inspector)
    - [Cell Reach](#cell-reach)
    - [Permissions Screen](#permissions-screen)
- [🛡️ Security](#️-security)
    - [The Guard](#the-guard)
    - [Audit](#audit)
    - [Catalog Command](#catalog-command)
- [🔧 Advanced](#-advanced)
    - [How Far a Permission Reaches](#how-far-a-permission-reaches)
    - [Multi-tenancy](#multi-tenancy)
    - [Catalog](#catalog)
- [⚙️ Configuration Reference](#️-configuration-reference)
- [🤔 Why this package?](#-why-this-package)
- [📦 Stability](#-stability)
- [🧪 Development](#-development)
- [👤 Credits](#-credits)
- [📄 License](#-license)

---

## ✨ Features

| Feature | Description |
|---|---|
| 🎯 **Policies as the source of truth** | The permission grid is automatically derived from your policies. Zero manual configuration. |
| 🔒 **Explicit denials** | A hard "no" beats any grant. Distinguishes between abstention and denial. |
| 🔍 **Built-in inspector** | Every cell explains *why* it has that value: which role, which rule, and which permission decided it. |
| 🏗️ **Advanced conditions** | Restrict permissions with SQL-like conditions (`name = editor AND scope >= 2`). |
| 🧪 **Test bench** | Verify permissions in real time from the panel without writing code. |
| 🛡️ **Security guard** | The panel refuses to boot if there are unprotected pages or widgets. |
| 📊 **Automatic audit** | Detects unguarded screens, missing policies, and permissions nothing declares. |
| 🔄 **Smart cache** | Automatic invalidation when assigning roles. No ghost permissions. |
| 🏢 **Multi-tenancy** | Native support for tenant scopes across all warden tables. |

---

## 📋 Requirements

| Requirement | Version |
|---|---|
| PHP | `^8.4` |
| Laravel | `^13.0` |
| Filament | `^5.7` |
| elpandape/warden | `^2.2.2` |

---

## 🚀 Installation

```bash
# 1. Install the package
composer require elpandape/filament-warden

# 2. Register the plugin in your Panel
use ElPandaPe\FilamentWarden\FilamentWardenPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(FilamentWardenPlugin::make());
}

# 3. Install warden (creates tables)
php artisan warden:install --migrate

# 4. Publish assets
php artisan filament:assets
```

> 💡 **Tip:** Add `php artisan filament:assets` to Composer's `post-autoload-dump` so it runs on every deploy.

### Upgrading to 3.0 from 2.x

`filament-warden 3.x` requires `elpandape/warden ^3.0`. **Run warden's migration before anybody
uses the panel**, then re-copy the assets: this release changed both `grid.blade.php` and the
stylesheet, and it ships a screen the panel did not have.

```bash
# 1. Both packages together — the floor is a major on warden's side
composer require elpandape/warden:^3.0 elpandape/filament-warden:^3.0

# 2. Publish and run warden's UPGRADE migration for THIS major.
#    Not `warden-migrations`, which is the CREATE migration and stops on
#    tables you already have; not `-v2`, which is the previous one.
php artisan vendor:publish --tag=warden-migrations-v3
php artisan migrate

# 3. Re-copy the assets. Not optional: the stylesheet and the script both moved
php artisan filament:assets

# 4. Check the store, and ask warden's own doctor
php artisan filament-warden:audit --check
php artisan warden:doctor
```

`upgrade_warden_to_v3` adds one nullable `expires_at` column to **both** pivots — `grants` and
`assigned_roles` — with an index, and asks before each step, so a run interrupted halfway finishes
on the next one. Nothing is backfilled and nothing changes meaning: every existing row has no end
date, which is what every existing row already meant.

**Four things to look at afterwards, none of them urgent:**

- **Published views have to be reintegrated.** `grid.blade.php` grew two marks, a date control and
  a close button; a published copy from 2.x renders the 3.0 payload without any of them, and the
  clock and link marks simply will not appear.
- **Published translations arrive in English until you copy the new keys across.**
  `FileLoader::loadNamespaceOverrides()` merges recursively, so a stale copy does not swallow the
  new keys — it just does not translate them. Nothing was renamed in this release, so no key has to
  be moved.
- **A published config does not have the new keys, and that is handled.** `permissions.direct` and
  `grid.expiry` fall back to the packaged defaults through this package's own accessor, which is why
  it never reads `config()` bare. `permissions.direct` is `false` there, so the new direct-grants
  tab does not appear until you decide it should.
- **`filament-warden:audit` grew two buckets.** *Rows whose condition can never be true* turns
  `--check` red — warden refuses to write new ones, so anything listed predates that and has been
  authorising nothing. *Rows past their end date* is informational and will be empty on the day you
  upgrade, since nothing could have set a date before there was a column.

### Upgrading to 2.0 from 1.x

`filament-warden 2.x` requires `elpandape/warden ^2.2.2`, and the jump to warden 2.x is the whole reason `2.0.0` was a major. **Run warden's migration before anybody uses the panel.**

Warden 2.0 adds an `identity_key` column to `permissions` and a unique index over `(name, identity_key)`, and it stamps that key on every save. A database still in the 1.x shape gets `no column named identity_key` the first time anything writes a permission — the grid, the permission screen, a seeder. Composer resolves without complaint and the application breaks on first use.

```bash
# 1. Publish and run warden's UPGRADE migration — not `warden-migrations`,
#    which is the CREATE migration and fails on tables you already have
php artisan vendor:publish --tag=warden-migrations-v2
php artisan migrate

# 2. If the migration stops on duplicates, clear them and run it again
php artisan warden:clean --duplicates
php artisan migrate

# 3. Re-copy the assets, as with any upgrade
php artisan filament:assets
```

The tag matters. `warden-migrations` publishes `create_warden_tables`, whose `Schema::create()` calls carry no `hasTable` guard, so on a database that already has warden's tables `php artisan migrate` stops on the first one and the `identity_key` column never arrives. `warden-migrations-v2` publishes `upgrade_warden_to_v2`, which adds the column, backfills every row and then puts the unique index on. It is safe to run again: it stops before the index while rows still collide, so the run after a `warden:clean --duplicates` finishes the job.

`php artisan filament-warden:audit --check` reports an unmigrated catalogue as its own finding and exits 1, so a deploy pipeline goes red before the deploy rather than after it. That bucket stays permanently empty afterwards, which is what it is supposed to do.

**Existing titles are not rewritten by the upgrade itself.** Warden 2.0 changed how it generates a title — `viewAny` on `Post` is `View any posts` now, where 1.x wrote `ViewAny posts` — and neither warden nor this package retitles rows in place when you upgrade, so an upgraded catalogue shows mixed wording until somebody converges it. `php artisan warden:retitle` is what does that, since warden 2.1: it rewrites a title an older warden generated, leaves a title a person typed alone, and leaves a `null` null. `--dry-run` reports the count first. Nothing here is urgent — what this package guarantees meanwhile is that it still RECOGNISES the old wording, asking warden which titles warden has ever written, so renaming a permission still regenerates it whichever generation the row carries.

### Upgrading to 3.3 from 3.2

`composer update`, then **run `php artisan filament:assets`** — the stylesheet and `grid.blade.php`
both changed.

One default moved. `grid.class_names` shipped **on** in `3.2.0` and is **off** from here: the class
under each entity is drawn only where you ask for it. If you published the config in `3.2.0` your
copy already says `true` and nothing changes; if you did not, the class comes off the rows and stays
on their `title`, where hovering shows it. Put it back with one line:

```php
'grid' => ['class_names' => true],
```

Nothing else moves: no database, no config keys, no change to the
`{stances, narrowing, until, inherited, baseline}` envelope.

### Upgrading to 2.11 from 2.10

`composer update`, then **run `php artisan filament:assets`** — this release grew the stylesheet
from 1107 lines to 1400 and changed `grid.blade.php`, and skipping the republish is not a quiet
downgrade:
it serves the OLD assets against the NEW markup, and three things actually break, not just look a
version behind.

- **The folded key renders unstyled.** `<details class="fw-legend-fold">` — "What the marks
  mean," now sitting above the tabs instead of under the grid — is new markup with no counterpart
  in `2.10`'s sheet: no chevron, no two-column layout for the marks, just a bare native disclosure
  triangle.
- **The inspector's two voices render unstyled, and worse, non-functional.** "In the store" and
  "On screen, not saved" call `storedStance()`, `moved()`, `matchedName()` and `storedRule()` —
  confirmed absent from `2.10.2`'s script — so Alpine throws evaluating them instead of drawing
  anything, the same way a missing method broke the grid entirely when `1.1.0`'s assets went out
  unrepublished.
- **The condition editor and the tabs stay exactly at `2.10`'s look.** `2.10`'s sheet has no
  two-column `.fw-conditions` split and no `.fw-write` rule at all, so neither this release's
  pairing nor the narrow-screen collapse it needed apply — what renders is last release's single
  column, not a broken copy of the new one.

Nothing here touches the database, the config, or the `{stances, narrowing, baseline}` envelope:
this release is assets only.

### Upgrading to 2.10 from 2.9

`composer update` and nothing else. The floor moves from `elpandape/warden ^2.1` to `^2.2.1`, which is where the invalidation this package used to do by hand now lives.

Warden `2.2.0` taught its own invalidation hook to recognise the permission catalogue: editing a row through Eloquent — renaming a permission, rewriting its `options` — now bumps the cache version on its own. This package had two `Warden::refresh()` calls compensating for that, and they are gone. Nothing you can see changes; the cache is cleared by the same event it always should have been.

If you pin warden below `2.2.1`, do not take this version: with the older hook, an edit made on the permission screen would leave every cached check answering the old rule until the payload expired a day later. Composer will not let you, which is the point of moving the floor rather than leaving it optimistic.

Warden `2.2.1` also fixes a catalogue lookup this package reported: under a tenant, granting a permission attached the concession to the **global** row even when the tenant had minted its own, leaving that row orphaned and the rule governed by conditions somebody else chose. Nothing here had to change for it — but if you run tenancy, that fix is the reason to take warden `2.2.1` rather than `2.2.0`.

### Upgrading to 2.8 from 2.7

Nothing to run. One behaviour changes, and it can be visible on an installation that has rows nobody could read anyway.

`permissions.options` is now read from the **column** instead of Eloquent's `array` cast. Three stored values cast to `null` and so used to read as "no conditions": text that is not JSON, the empty string, and the JSON literal `null`. Warden's three engines moved off the cast in its `1.0.2` and fail closed on all three; this package did not, so such a row was drawn as **every row**, left editable, and overwritten with SQL `NULL` on the next save — turning a rule nobody could decode into an unconditional grant.

After upgrading, a row like that is drawn as unreadable and locked, with the reason on screen, and no save touches its column. **If your catalogue has any, cells and permission screens that used to accept an edit will stop accepting one.** That is the point: they were offering to overwrite something they could not show you.

Only a write from outside this package can produce such a row — a seeder, a console command, a restore, a hand edit. Nothing in warden's fluent API or in these screens can mint one. To find them:

```sql
select id, name, entity_type from permissions
where options is not null and json_valid(options) = 0;
```

`php artisan warden:clean --duplicates` is **not** the tool for these, and is worth avoiding until they are fixed: it groups by the cast too, so it can collapse an undecodable twin onto its plain sibling and leave the grant unconditional. Repair the column, or delete the row and write the rule again.

### Optional publishes

```bash
# Configuration
php artisan vendor:publish --tag=filament-warden-config

# Translations
php artisan vendor:publish --tag=filament-warden-translations

# Views (⚠️ see Stability section)
php artisan vendor:publish --tag=filament-warden-views
```

---

## ⚡ Quick Start

Follow these 5 steps to get a working permissions panel in minutes. We'll use an `Order` model as an example.

### 1. Create the Policy

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

> 📌 **Important:** Only actions declared in the policy will appear in the grid. Remove `update` and its cell disappears.

### 2. Lock Panel Access

```php
// app/Models/User.php
use ElPandaPe\FilamentWarden\Concerns\AccessesPanels;
use Filament\Models\Contracts\FilamentUser;

final class User extends Authenticatable implements FilamentUser
{
    use AccessesPanels;
}
```

### 3. Enable Strict Authorization

```php
// app/Providers/Filament/AdminPanelProvider.php
return $panel
    ->strictAuthorization()
    ->plugin(FilamentWardenPlugin::make());
```

### 4. Lock Custom Pages & Widgets

```php
use ElPandaPe\FilamentWarden\Filament\Concerns\AuthorizesPageAccess;
use ElPandaPe\FilamentWarden\Filament\Concerns\AuthorizesWidgetView;

final class Reports extends Page
{
    use AuthorizesPageAccess;  // Generates: page:App\Filament\Pages\Reports
}

final class RevenueChart extends ChartWidget
{
    use AuthorizesWidgetView;  // Generates: widget:App\Filament\Widgets\RevenueChart
}
```

### 5. Create Your First Role (from console)

```php
// database/seeders/WardenSeeder.php
use ElPandaPe\Warden\Facades\Warden;

$role = Warden::role(['name' => 'super-admin']);
$role->save();

Warden::allow($role)->everything();
```

> ⚠️ **This is also your only way back.** The grid can hand out every action it finds on every
> entity it knows about, one row at a time, but never the wildcard *over* the wildcard —
> `entity_type = '*'`, the one permission `everything()` writes — because that row answers no check
> the grid asks and draws no cell (nothing in this package calls `everything()`). Keep this seeder:
> it is what you run again if a role ever locks you out of the panel itself.

```bash
# Assign the role to your user
php artisan filament-warden:assign super-admin "App\Models\User:1"
```

🎉 **Done!** Open `/admin/roles` and you'll see the permission grid. Click a cell to grant, click again to deny, save, and you're set.

---

## 🔌 Setup

### Plugin Options

Both resources are registered by default. Drop one an installation does not want:

```php
FilamentWardenPlugin::make()
    ->roles(false)        // no role resource, no grid
    ->permissions(false); // no permission resource
```

Each takes a `bool`, defaulting to `true`, so `->roles()` alone is the same as `->roles(true)`. The guard, the audit and the two Filament assets stay registered either way — turning a resource off does not turn off what protects the rest of the panel (see [The Guard](#the-guard)).

### Policies

All your policies must extend `WardenPolicy`. The `allows()` method resolves directly from warden's store, avoiding infinite loops with the Gate.

```php
use ElPandaPe\FilamentWarden\Policies\WardenPolicy;

final class OrderPolicy extends WardenPolicy
{
    public function viewAny(User $user): bool
    {
        // For listings: pass the class
        return $this->allows($user, 'viewAny', Order::class);
    }

    public function view(User $user, Order $order): bool
    {
        // For individual records: pass the instance
        return $this->allows($user, 'view', $order);
    }
}
```

### Lock the Panel

The panel permission is automatically derived from its ID. A panel named `admin` generates the permission `panel:admin`.

```php
use ElPandaPe\FilamentWarden\Concerns\AccessesPanels;

final class User extends Authenticatable implements FilamentUser
{
    use AccessesPanels;
}
```

An installation that already stores another name maps it in the config instead of renaming rows:

```php
'guard' => [
    'panel' => ['admin' => 'viewAdminPanel'],
],
```

To add a condition of your own, **alias the trait's method rather than replacing it**. `AccessesPanels` is a trait: declaring `canAccessPanel()` on the class silently overrides the trait's copy, and there is no `parent::canAccessPanel()` to fall back to — `Authenticatable` has no such method, so that call is a fatal error at login.

```php
use AccessesPanels {
    canAccessPanel as wardenCanAccessPanel;
}

public function canAccessPanel(Panel $panel): bool
{
    return $this->isActive() && $this->wardenCanAccessPanel($panel);
}
```

**Be careful what you fold in here.** Filament calls `canAccessPanel()` from four places and only one of them is the middleware that answers with a 403:

- `Login` throws the *same* validation exception as a wrong password, so the account is told its credentials do not match;
- both password-reset pages fail **silently** — no link is sent, and the screen still says one was.

For a condition the account is supposed to *resolve* rather than simply fail, that is a dead end with no way to read it. Email verification, for instance, belongs in Filament's own `->emailVerification()`, which lets them in and then routes them to the prompt.

### Lock Pages & Widgets

Filament returns `true` by default for `Page::canAccess()` and `Widget::canView()`. `strictAuthorization()` does not cover them.

| Type | Trait | Generated Permission |
|---|---|---|
| Page | `AuthorizesPageAccess` | `page:App\Filament\Pages\Name` |
| Widget | `AuthorizesWidgetView` | `widget:App\Filament\Widgets\Name` |

### Assign Roles to Users

Two ways to hand a role to an account. Use whichever fits the size of the installation.

#### From an Account Screen

For a real catalogue of roles — one a `CheckboxList` cannot page, sort, or explain. This is the
one thing the package cannot do for you: `Resource::getRelations()` is a concrete static, and
nothing outside your own `UserResource` can write to it. Add one line:

```php
use ElPandaPe\FilamentWarden\Filament\RelationManagers\RolesRelationManager;

public static function getRelations(): array
{
    return [RolesRelationManager::class];
}
```

It will show up in `filament-warden:audit` under *models only a relation manager reaches*, and that is
expected: it declares no `$relatedResource` on purpose, because pointing it at `RoleResource` leaks that
resource's own edit and delete actions into the tab. The finding is informational and never turns
`--check` red.

That is the whole of it. Everything else — who may assign or retract which role — is decided by
the package, the same way `RoleAssignment` below decides it for the field.

It lists the roles the account holds, deduplicated (a role assigned both with and without a
context is two rows of warden's own pivot table sharing one key), with a badge saying **how**:
here, elsewhere, or restricted to a context. Assigning opens a searchable list; retracting is one
click on the row. Both are hand-written actions, never `AttachAction`, `DetachAction` or
`DetachBulkAction` — in Filament 5.7 those three check **no policy at all**
(`RelationManager::getDefaultActionAuthorizationResponse()` closes them only with `isReadOnly()`,
which is `false` on any edit page) — and both write through warden's fluent API, one role at a
time, never `attach()`/`detach()`/`sync()`, for the same cache-bump reason `RoleAssignment` warns
about below.

> 🔒 **A role held restricted to a context, or outside the tenant you are viewing from, shows its
> badge and carries no retract action.** Same two reasons `RoleAssignment` locks them below —
> retracting either from here would either take every context with it or delete the wrong row.

#### From a Field

For a small installation, and still the right answer there:

```php
use ElPandaPe\FilamentWarden\Filament\Forms\RoleAssignment;

RoleAssignment::make('roles')->columnSpanFull(),
```

> 🚫 **Don't use `CheckboxList::make('roles')->relationship(...)`**. That saves through `sync()`, and `sync()`, `attach()` and `detach()` all **skip** warden's cache bump — only warden's own actions make it. A role handed out that way goes on answering the old way, silently and with no expiry. `RoleAssignment` writes through warden's fluent API instead.

> 🔒 **A role assigned outside the tenant you are viewing from cannot be handed back here.**
> Warden's own tenant scope reads a role as held from *global or this tenant*, so a
> globally assigned role shows as ticked from inside any tenant — but a retract targets one exact
> scope. Unticking that box now locks instead of quietly deleting nothing (and reporting success)
> or, worse, deleting a real tenant-scoped row while the global one keeps it looking held. Switch
> tenant to change it.

### Grant a Permission Straight to an Account

A permission handed to somebody without a role in between is the hardest access in an installation
to find again: it belongs to no role, so no role's grid draws it. Warden has allowed the write since
it existed — `Warden::allow($account)->to('export-reports')` — and 3.0 is the first version with
anywhere to look at the result.

Two screens, and they are the two directions of one write. From a permission's own page, **Grant to
an account** hands that row to somebody, with an end date if it should have one. From an account's
page, a relation manager lists everything that account holds directly:

```php
use ElPandaPe\FilamentWarden\Filament\RelationManagers\PermissionsRelationManager;

public static function getRelations(): array
{
    return [RolesRelationManager::class, PermissionsRelationManager::class];
}
```

> ⚠️ **It is off out of the box.** `permissions.direct` is `false`, and that is not caution about a
> listing: turning it on does not only show direct grants, it hands them out. An installation that
> keeps every permission behind a role should leave it off, and the tab will not appear.

Each row says its polarity (a direct prohibition beats every grant the account's roles carry), its
reach, and when it ends. A grant written outside the tenant you are viewing from is shown, marked
and left alone — a revoke from there would delete nothing and still report success. Flipping a row
from granted to forbidden is a write **and** a delete, because `forbidden` is part of warden's
unique index and the two coexist as separate rows.

The wildcard is never offered. `entity_type = '*'` is the one row that covers literally everything,
and this package never writes it (property 6 below) — it can only be seeded from a console.

### Query Permissions Manually

```php
use ElPandaPe\FilamentWarden\Support\Access;

// Loose permission
Access::grantedToCurrentUser('export-reports');

// Over a class
Access::grantedToCurrentUser('viewAny', Invoice::class);

// Over a specific record
Access::granted($otherUser, 'view', $invoice);
```

**Use this rather than `$user->can()`.** The two agree until they do not:

| | `$user->can('export-reports')` | `Access::grantedToCurrentUser(…)` |
|---|---|---|
| not granted | `false` | `false` |
| granted | `true` | `true` |
| granted, with `warden.gate.register` off | **`false`** | `true` |

That last row is why `Access` exists. Warden ships that switch so an application can register its own gate callback, and the day one does, every `$user->can('export-reports')` starts answering `false` with no error to read — a loose permission has no policy to answer for it, so if warden's hook is gone there is nobody left. `Access` goes straight to the resolver, and picks the account up through `Filament::auth()`, which is not necessarily the default guard.

### Testing Your Authorization

`Warden::fake()` replaces the `Contracts\Resolver` binding, and that is the one thing everything in this package asks: `Access` resolves it out of the container, and every policy it ships takes it by constructor. So scripting the fake scripts this package's answers too, with no tables and no cache.

```php
use ElPandaPe\Warden\Facades\Warden;

it('lets an editor update their own posts and nobody else touch them', function () {
    $fake = Warden::fake();

    $fake->allow('update', Post::class)->for($editor);

    expect(Filament\get_authorization_response('update', $post)->allowed())->toBeTrue();

    $fake->assertChecked('update');
});
```

Four things to know before you write one:

- **The fake abstains, and abstention is a denial here.** `WardenPolicy::allows()` folds it into `false`, so a check you did not script is a hard deny, not a fall-through. Script the negative case as an assertion on `false`, not as an absence.
- **Assert with `Filament\get_authorization_response()`, never `Gate::allows()`.** They agree in every case but one, and that one is the case a security test exists for: with no policy registered and strict authorization off, `get_authorization_response()` returns `allow()` without looking at anything. The assertion that carries the guarantee is the negative one.
- **The wildcard is expressible.** `$fake->allow('*', '*')` answers a class check, a loose name and an instance alike — which is how you script the account the roles screen hands everything to.
- **It decides authorization; it does not populate the grid.** The permission grid reads the store, so a screen test still needs rows. Use the fluent API for those and keep the fake for policy behaviour.

Scriptable narrowings: `->for($authority)`, `->owned()`, `->inScope($tenant)`, `->where($column, $operator, $value)` and `->whereColumn(...)`. Assertions: `assertChecked`, `assertNotChecked`, `assertNothingChecked`, `assertGranted`, `assertForbidden`.

### Permission Names

```php
use ElPandaPe\FilamentWarden\Catalog\PermissionName;

PermissionName::page(Reports::class);        // page:App\Filament\Pages\Reports
PermissionName::widget(RevenueChart::class);   // widget:App\Filament\Widgets\RevenueChart
PermissionName::panel($panel);                 // panel:admin
```

---

## 🖥️ The Screens

### The Permission Grid

The roles screen shows a grid where:
- **Rows** = Entities (models, pages, widgets, panel)
- **Columns** = Actions declared in policies
- **Cells** = Cycle through: abstain → grant → deny

**Shortcuts:**
- 🖱️ **Normal click** → Cycle forward
- ⇧ **Shift + click** → Cycle backward (useful for quick denials)
- ⌨️ **Arrow keys** move between tabs; every cell and tab carries a name a screen reader can
  announce on its own, not one shared word for all seven states.
- 🔊 **A cell says what it just became.** Its name already changed on its own — but a name that
  changes under a focus that never moved is not one a screen reader re-reads, so the grid carries a
  live region that says it, in the cell's own words. It fires on the write, so a preset, a keyboard
  cycle and a shift-click all announce, and it keeps working with the inspector and the condition
  builder both switched off — a configuration where a click used to say nothing at all.

> 🚫 **A grid that cannot be operated says so.** A protected role's grid, a field
> your application called `->disabled()` on, and the read-only screen (`ViewRole`) all print one
> sentence above the table — "This grid cannot be changed from here: its cells select, they do not
> cycle." — instead of silently accepting clicks that never save or, on the read-only screen,
> saying nothing at all. A protected role keeps its own stronger notice naming `roles.protected`;
> the other two share this one, because neither route lets the package know *why* it cannot write.

> 🤝 **Two people editing the same role do not undo each other.** A save compares three things —
> the store, what your browser holds, and what your screen was showing when it opened — rather than
> two. A cell you did not touch is left as they set it. A cell you both moved to different values is
> refused and named, rather than resolved in silence in favour of whoever saved last. The grid
> re-reads the store afterwards, so your next save starts from what is actually there.
>
> **The same holds when you hand roles out from an account.** `RoleAssignment` keeps
> its own copy of what the store said, in a namespaced key beside its list, and leaves alone any role
> you did not tick or untick. Nothing is ever *refused* there — a role is held or it is not, so two
> people can only ever have moved one the same way — and the field sends its own notice, because that
> form is yours and there is no notification of ours to replace.
>
> An embedded `PermissionGrid` is covered too: the field sends its own notification through
> `afterCommit()` and re-fills its own state from the store, so a page this package does not own says
> the same thing the roles screen does. What is **not** covered is a schema with no state path at
> all — `RoleAssignment` keeps its baseline beside its own state and has nowhere to put one there, so
> such a page saves with no notice either way.

> ⚡ **A save writes in groups.** Cells that share an entity and a stance and have
> nothing left to narrow go out in one warden call instead of one per cell. If you listen for
> `GrantingPermission` or `ForbiddingPermission`, that is **one event per group carrying every name
> in it**, and a listener that vetoes one now vetoes the whole group. Cells narrowed to "only what
> it owns" or to conditions still go one at a time — warden's `where()` re-points every permission
> in a chain at the same twin, so two cells asking for two different conditions can never share a
> call.

> 📱 **The grid folds when the columns do not fit.** Below `55.9375rem` the table is
> replaced by one card per entity, holding one disclosure per scope — read, write, withdraw,
> irreversible — and one row per action inside it. It is not a second grid: every cell is the same
> button, bound to the same state, so whatever one reading says the other says too. ~~One thing does
> not survive the fold: the per-row `read` / `all` / `none` shortcuts, which are revealed by hovering
> a table row and have no row to hover.~~ Fixed in `2.5.0`: the fold carries its own copy of those
> shortcuts, in the body of the disclosure rather than its summary, plus a count of what each folded
> entity answers.
>
> The inspector moved below the grid in the same release, and the rule-scope picker became a
> segmented radiogroup — one tab stop, arrow keys that step over what cannot be picked, and only the
> chosen mode's hint. If you publish this package's views, that is a re-merge.

### Finding a Row

A catalogue with thirty entities is a long scroll. The box above the grid filters the rows by their
title and their class name, in both readings at once, and says how many matched.

> 🔒 It only decides what is **drawn**. Every row stays in the field's state, filtered out or not —
> and that is a guarantee, not a detail: a save compares the payload against the catalogue, so an
> entity missing from the payload is written as a deliberate revoke. `verify/verify-filter-keeps-state.mjs`
> is the gate on it.

> 📊 The tab counters keep counting the whole tab while a filter is on, and the line beside the box
> says so. They answer "what does this role grant", which a filter does not change.

### Permission Inspector

Click any cell and a bar appears below the matrix with the answer in one line: the glyph, the cell,
and what the store says plus its end date. **Customise** expands it to the full width of the card.
The matrix never gives up a pixel — it did in 3.0.0, and half of it was too much to pay for a panel
that usually says one sentence. Escape or the close button shuts it and hands the focus back to the
cell that opened it.

It says:
- **Cause**: why this cell answers what it answers
- **Permission**: which rule decided it
- **Role**: which role it came from, and — when the answer arrives through a role this one inherits
  — which of them
- **Ends on**: when the grant that answered runs out, and what happens after
- **The rule**, when there is one, read out as it will be evaluated

> 🔍 It is queried on demand, never on render. One `explain()` is three to five queries with no
> cache, so a grid of thirty-five cells explaining itself on sight would be a hundred and fifty.

> ⏳ **The date is set here too, on a granted cell.** Not on a prohibition — warden refuses one
> outright, `until(null)` included — and not on a cell with no rule of its own to date. Each of the
> three noes is a different sentence rather than one greyed control, and an installation can close
> the whole thing with `grid.expiry`, which says so as a fourth.

### Cell Reach

Each cell can reach:

| Reach | Description |
|---|---|
| **Every row** | Permission applies globally |
| **Only owned** | Restricted to records where `user_id` matches |
| **With conditions** | Custom SQL-like rules |

Example conditions:
```
name = editor OR (scope >= 2 AND title = account.name)
```

> 🔒 **A locked cell lights none of the three.** A cell the grid cannot let you set — more than one
> rule for the same action, a condition it cannot parse, or a grant that belongs to another tenant —
> draws its actual reach and highlights none of "Every row", "Only owned" or "With conditions". The
> inspector names which of the three it is and, when there is a stored rule, shows it read-only
> underneath the note. A row
> that is both "only what it owns" **and** carries conditions is drawn the same way: read-only, with
> its stored rule shown, never silently narrowed to plain ownership.

> ⚠️ **A grant pinned to a single record is not a cell.** Warden filters a check made against a class down to `entity_id is null`, so a rule with a record key on it answers nothing the grid asks — and it is not a wider rule either. The grid lists those rules above the tabs, read-only: this screen shows them, and cannot remove them.

> 🔒 **A rule the permission form cannot write back exactly locks there.** A value
> stored as the string `'2'`, `'2.5'`, `'true'` or `'false'` would read back as another type the
> moment that screen's condition builder parses it, and a rule whose first line is `or` would read
> back as `and` — both would change what the row means for everybody holding it. Instead, the field
> draws the rule, explains why, and leaves it alone rather than silently rewriting it on the next
> save. It can still be edited from warden's own fluent API. And a `true`/`false` value compared
> against a column the model has not cast to `boolean` gets its own warning — on the permission form
> and in this grid's inspector alike — because that comparison is stored and then never matches a
> single row.

> ℹ️ **The grid never locks a cell for this, and it does not make the mismatch worse either.** A
> stance flip — grant to forbid, or back — leaves the stored value untouched: re-typing it through
> the browser's own casting rules would turn the string `'true'` into the boolean `true`, which
> matches every row the string never did, on a click that only meant to change the stance. What the
> grid does **not** fix, because it is a different and lighter hazard: a rule whose first line reads
> `or` still comes back as `and` on any grid save, stance-only or not — it changes no cell's answer,
> only which permission row backs it, which shows up as an orphaned row in `filament-warden:audit`,
> not as a wrong answer on screen.

### Permissions Screen

Lists the `permissions` **table** — the rows warden has actually created — and says where each one came from:

- **Provenance**: derived from a policy, loose, the wildcard, or an entity nothing declares any more
- **Reach**: every row, only what the account owns, with conditions — or **one record only**, when the row is pinned to a single record
- **Held by**: roles, accounts and denials, counted apart, from one grouped query per page
- **Health**: whether the row's condition can ever be true. Warden refuses to write a new one that
  cannot, so anything listed here predates that and has been authorising nothing
- **Test bench**: ask warden about a real account, on the page rather than in a modal — the account
  stays put between questions, so comparing two records costs one field

The view screen adds what the listing cannot afford per row: the rule as it will be evaluated, how
many of its grants end and how many already have, and **Grant to an account** — the direct write,
with a date, whose way back is the [direct-permissions tab](#grant-a-permission-straight-to-an-account).

> ℹ️ **On a fresh install this screen is empty, and that is correct.** Warden creates a permission row the first time something is granted, so nothing exists until you hand something out. The roles screen is the one that shows the whole catalogue derived from your policies, row or no row. To see the catalogue itself — without opening a screen, and whether or not it has a row yet — run [`filament-warden:catalog`](#catalog-command).

---

## 🛡️ Security

### The Guard

The panel **refuses to boot** if it finds an unguarded page or widget. That is what stops a custom screen from being left open to everyone by accident.

It is on by default, and neither of the plugin's `roles()`/`permissions()` toggles (see [Plugin Options](#plugin-options)) touches it — those turn a resource on or off, never the guard. The guard's own switches are config keys, one per kind:

```php
// config/filament-warden.php
'guard' => [
    'pages'   => true,   // refuse to start on an unguarded page
    'widgets' => true,   // refuse to start on an unguarded widget
],
```

Turn one off only to get the panel up while you close the screens — `php artisan filament-warden:audit` lists what is still open without stopping anything.

### Audit

```bash
# View report
php artisan filament-warden:audit

# CI mode (fails with exit code 1 on an actionable finding)
php artisan filament-warden:audit --check
```

**It is not warden's `warden:doctor`, and they do not overlap much.** `warden:doctor` reads the catalogue alone and answers one question — which stored conditions can never be true — in more detail than this can, naming the column and the cast it wants. `filament-warden:audit` answers what only a *panel* can: which screens decide nothing, which resources have no policy, which grants name actions no policy declares any more. The one thing both report is the unsatisfiable row, and deliberately: a build already running this command should not have to know to run a second one to hear about it.


It writes nothing, and reports eleven things:

- **screens nobody guards** — the same finding the guard throws on, which is how it reaches CI at all: no artisan command ever starts a panel;
- **resources whose model has no policy** — the case Filament fails open on, told apart from a policy that declares nothing and from a resource pointing at a class that does not exist;
- **permissions the catalogue declares that no grant points at** — *informational: this one never turns `--check` red*. Turning a grid cell off revokes the grant and leaves the row, because warden's `revoke()` only touches `grants`, so a build that failed on this would fail on every save and stay failing. `php artisan warden:clean` is what removes them, and `--dry-run` shows the list first;
- **permissions nothing declares that no grant points at** — a rename left them behind: they can never match again, and nothing will ever create them;
- **grants for actions nothing declares any more** — a renamed policy method, a typo in a seeder, a screen that was deleted: the silent mistake warden has no way to detect;
- **whole entity types nothing declares** — a morph alias that moved, reported apart because the fix is the opposite one;
- **models only a relation manager reaches** — *informational: this one never turns `--check` red*. Reaching one means running the relationship, which is not safe to do from a command, so they are named instead. A relation manager that declares `$relatedResource` is walked for free; one that cannot stays listed for good, and `RolesRelationManager` and `PermissionsRelationManager` below are deliberately two of them. `catalog.models` is what puts the model in the catalogue — it does not clear the line;
- **catalogue names carrying a dot** — Livewire splits a state path on dots, so such a name cannot be a cell and a role screen throws the moment it draws one. Rename the permission — otherwise the only way to find out is somebody opening the screen;
- **grants and role assignments past their end date** — *informational: this one never turns `--check` red*. Not a defect and not something anybody did: a date arrived, and warden stopped reading the row without touching it. They are not inert, though — a dead row still follows its permission or its role down a foreign key, so it still blocks a delete under `roles.delete => 'unassigned'` and still locks a name under `permissions.update => 'loose'`, both deliberately. `warden:clean --expired` removes them;
- **roles assigned to other roles while `warden.roles.nested` is off** — *informational, and the only bucket here that reports something which is not a defect: it is what a SWITCH would do.* Such an edge has always been writable and has always granted nothing, so an installation can have collected them without knowing — and turning the flag on is what makes them live, so a grant somebody wrote years ago as a no-op becomes access on the next check. Warden's own upgrade note asks for this count before you flip it. With nesting on the list is empty by definition;
- **catalogue rows whose condition can never be true** — a boolean value against a column the model does not cast to `bool`, or the reverse. As a grant they authorise nothing; as a prohibition they are inert, and the grant they were written to narrow keeps applying. Warden 3.0 refuses to write new ones, so this bucket only ever shrinks — which is why it is red rather than informational: correcting the condition or adding the cast empties it, and nothing can refill it;
- **permissions restricted to what the holder owns, on a model that resolves no ownership** — they grant nothing and forbid nothing: there is no attribute to compare, and the query side fails closed. `Warden::ownedVia()` is what registers it, or the row comes out;
- **warden's catalogue still in its pre-2.0 shape** — no `identity_key` column, so the first permission anybody saves fails. It turns `--check` red on purpose and stays permanently empty once migrated, which is what it is for: a deploy pipeline should learn this before the deploy, not after;
- **config entries this package reads and cannot use** — each one was dropped in silence, so what is missing from a screen never said why;
- **grants and role assignments whose authority no longer exists** — *informational, like the permissions-the-catalogue-declares-that-no-grant-points-at bucket above (third bullet): this one never turns `--check` red either*. Warden's schema puts a foreign key on `assigned_roles.role_id` and on `grants.permission_id`, never on the two columns that name a grant's authority, so no database cascade reaches them. Warden 2.0 sweeps some: `CacheInvalidations::markCascade()` deletes the grants of a deleted role, but only when the model's class is exactly the configured role class — an account, a role subclass, and anything deleted by query builder or raw SQL are all left behind, because it hangs off `eloquent.deleted`. `warden:clean --stranded` sweeps the rest — both pivots since warden 3.0, because nesting let `assigned_roles` hold an edge whose authority is a role — and it is opt-in. This bucket reports what is left over. Reported once per stranded authority — the deduplicated `type:key` a whole cluster of grants can share — and once per authority type this installation cannot even resolve.

`--check` returns 1 for every finding above except the two informational ones.

The word "orphaned" now carries two meanings in this package. `warden:clean` and the permissions screen's **Orphaned** filter mean the same thing — no grant points at the row, declared or not — which is the whole of the third and fourth bullets above, together. This command's own **orphans** bucket only covers the declared half of that population, because the undeclared half (`forgotten`) is the one worth failing a build over. **Stranded** is a different word for a different thing, not a third meaning of "orphaned": it says nothing about a permission nobody uses — it says a *grant* points at an authority that has been deleted, which `warden:clean` cannot see and cannot fix.

### Catalog Command

```bash
# Every panel
php artisan filament-warden:catalog

# One panel
php artisan filament-warden:catalog --panel=admin
```

The catalogue as data: one row per ability every panel declares — name, entity, model, scope, origin — with a last column saying whether the permissions **table** already has a row for it. Nothing here is wrong or right, unlike `filament-warden:audit`; this is what a fresh install's empty permissions screen cannot show, because warden only mints a permission row the first time something is granted.

---

## 🔧 Advanced

### How Far a Permission Reaches

To see *how many rows* a user can view:

```php
use ElPandaPe\Warden\Concerns\QueriesByPermission;

class Document extends Model
{
    use QueriesByPermission;
}
```

> ⚠️ The number shown is a **lower bound**. Roles assigned in specific contexts are not included in `whereCan()`.

### Multi-tenancy

Warden supports multi-tenancy through a `scope` column on its tables. **It does not know about `Filament::getTenant()`** — you must create a `TenantResolver` in your application.

This package's resources declare `protected static bool $isScopedToTenant = false;`, so Filament's tenancy never reaches warden's own models. Without it, a panel with `->tenant()` puts a global scope on the `Role` and `Permission` **models** — not on the resources — and that scope demands a relationship named after your tenant class: `Role::query()->count()` then throws `LogicException` for the whole request, warden's internals included.

It is written as the property and **never** through `scopeToTenant(false)`, which is static and would un-scope every resource of *your* application — a cross-tenant leak this package would have caused.

**Deleting a role looks across every tenant.** `roles.delete => 'unassigned'` counts assignments with warden's tenant scope lifted. A role held only under a tenant you are not currently in would otherwise read as unassigned, and deleting it takes that tenant's `assigned_roles` and `grants` rows with it through the foreign key — which never looked at `scope`, and neither does `$record->delete()`.

### Catalog

```php
use ElPandaPe\FilamentWarden\Catalog\Catalog;
use Filament\Facades\Filament;

$entries = Catalog::for(Filament::getPanel('admin'))->entries;

foreach ($entries as $entry) {
    $entry->name;         // 'viewAny', 'page:App\...'
    $entry->entityType;   // Morph alias or null
    $entry->model;        // Model class or null
    $entry->scope;        // Read | Write | Withdraw | Irreversible
    $entry->origin;       // Resource | Model | Page | Widget | Custom | Panel
    $entry->key();        // 'viewAny|order'
}
```

`Catalog::for()` reflects every Policy the panel declares and walks its resources, pages and widgets besides, so it is built once per panel and kept for the life of the process. Nothing it derives from moves while that process runs: panels, Policies and `catalog.*` all come from code and config loaded at boot. The one thing that would go stale is an application rewriting `catalog.*` config at runtime — invisible until you drop the memo:

```php
Catalog::forget();
```

**`Catalog::union(array $panels): self`** merges more than one panel's catalogue into one. A multi-panel installation needs this for provenance, not `for()` alone: the permissions screen, its infolist and its form all ask every panel now, because asking only the current one used to draw a row derived in another panel as "Nothing declares it" while `filament-warden:audit` — which already read every panel — said the opposite.

#### Custom Permissions

```php
// config/filament-warden.php
'catalog' => [
    'models' => [\Laravel\Passkeys\Passkey::class],
    'custom' => ['export-reports' => 'read'],
],
```

> 🚫 Custom names **cannot contain dots** (`.`) — they break Livewire state.

### Reacting to a Save

This package fires no event of its own, on purpose: it would be a public class frozen forever for a gap that two mechanisms already cover between them.

**Who did it — warden's own events.** Since warden 2.0 its eight write events carry `?Model $actor`, resolved from the authenticated user, so a grid save already leaves an audit trail with a name on it and no code from this package:

```php
Event::listen(\ElPandaPe\Warden\Events\PermissionGranted::class, function ($event): void {
    // $event->actor, $event->authority, $event->permissions
});
```

They are coarser than a cell: one event names every permission written in the same group, and a cell this screen *refused* to write fires nothing at all, because nothing was written.

**What the save did — the report in the container.** Both screens leave a `Grants\SaveReport` bound for the rest of the request, so a page of your own can say more than the field's own notification:

```php
protected function getSavedNotification(): ?Notification
{
    $report = app()->bound(SaveReport::class) ? app(SaveReport::class) : null;
    // $report->written, ->granted, ->forbidden, ->revoked, ->preserved,
    // ->refused, ->unresolved, ->lapsed, ->impossible
}
```

It is thinner from the account screen: a role is held or it is not, so `refused`, `unresolved`, `lapsed` and `impossible` are always empty there and the three stance counts stay at zero.

> ⚠️ `SaveReport` is **not frozen** (see Stability) and the binding lives exactly as long as the request that made it. Neither mechanism survives a queue — if you need a save delivered asynchronously, listen to warden's events and write your own record.

---

## ⚙️ Configuration Reference

### Permissions (permissions screen)

```php
'permissions' => [
    'create'      => false,        // manual creation of permissions
    'update'      => 'loose',      // false | 'title' | 'loose' | 'all'
    'delete'      => 'orphaned',   // false | 'orphaned' | 'all'
    'constraints' => true,         // the condition builder
    'only_owned'  => true,         // the ownership checkbox
    'probe'       => true,         // the test bench, built on explain() — see below
    'direct'      => false,        // the direct-grants relation manager — see below
],
```

**A row somebody holds cannot be re-pointed.** The name and the entity of a permission that at least one role already holds are locked on its edit screen, and put back on the server if the payload says otherwise — at every setting of `update` except `'all'`. Moving them moves what those holders hold without revoking anything and without writing a single row to `grants`: the check they used to pass simply starts answering something else. `'loose'` still mints and edits the rows nobody holds yet, and the conditions and the ownership checkbox stay editable wherever they were before — those narrow what a row means, they do not re-point it.

### Roles (roles screen)

```php
'roles' => [
    'create'    => true,             // true | false
    'delete'    => 'unassigned',     // false | 'unassigned' | 'all'
    'protected' => ['super-admin'],  // names that cannot be taken, renamed onto, or deleted
],
```

A protected role keeps its name and its grid: both are shown, neither can be edited, and it cannot be deleted. Its title is left editable — nothing resolves by it.

**A role cannot arrive at a protected name either.** Creating a role called `super-admin`, or renaming an ordinary one onto it, is refused by the form: otherwise the role is born protected, which is a way of minting an unremovable role by typing. The role that already carries the name keeps it: only the *arrival* is closed. The refusal names which list the name is on, rather than falling back to the framework's generic validation wording.

> ⏳ **A grant or an assignment that has already lapsed still blocks a delete, and still counts as a
> holder for the name lock.** Both rules read every row rather than the live ones, because the
> foreign key that removes them does — a delete takes a lapsed row exactly like any other, so
> calling the record unheld would promise a smaller loss than the delete causes. On screen the two
> figures are separated instead: *Already lapsed* says how many of the holders are answering
> nothing, so the wide count can stay wide without reading as access nobody has.

### Nested Roles

There is no `roles.nested` key here. It is **warden's** — `warden.roles.nested`, off out of the box,
with `warden.roles.max_depth` beside it — because it is part of warden's own cache key and decides
what every check answers, not merely what a screen draws. This package reads it and shows the
*Inherits from* field only when it is on.

> ⚠️ **Turning it on is not a write, and that is exactly why it is worth a moment.** A role assigned
> to another role has always been storable and has always granted nothing, so an installation can
> have collected such edges without knowing. Flipping the flag makes them live: a row somebody wrote
> years ago as a no-op becomes access on the next check. `filament-warden:audit` counts them for you
> before you flip it, under *roles assigned to other roles while nesting is off*.

> ⚠️ **The merge is shallow, on purpose.** Declaring `roles.protected => []` in your published config genuinely unprotects every role. A recursive merge would blend lists by index and silently keep `'super-admin'` in there — so it is not used, and a test holds that line.

### Guard

```php
'guard' => [
    'panel'   => ['admin' => 'viewAdminPanel'], // Override by panel ID
    'pages'   => true,   // true | false
    'widgets' => true,   // true | false
],
```

### Grid

```php
'grid' => [
    'explain'     => true,  // Inspector
    'constraints' => true,  // Show scope
    'expiry'      => true,  // May the grid set an end date
    'class_names' => false, // Draw the class under each entity
],
```

> 🏷️ **`class_names` draws `App\Models\Post` under «Posts».** It is off because in most
> installations the namespace repeats on every row and tells the rows apart on none of them: it is
> noise in the column people read most. Nothing is lost — the class stays on the row's `title`, so
> hovering still shows it. Turn it on where two models produce the same label: `App\Models\User` and
> `App\Models\Security\User` are both "Users", and since a permission is stored against the CLASS
> and not against the label, without it those two are identical rows.

> ⏳ **`expiry` decides whether the grid may SET a date, never whether one is honoured.** Warden
> stops reading a row past its date whatever this says, so a grid with this off still draws a lapsed
> cell as the abstention it is. Switching it off makes the screen answer "no opinion" rather than
> "no date" — an empty answer would end every timed grant on the grid the first time anybody saved.

> 🔎 **`probe` lets anyone who can view a permission search your accounts.** The bench needs an
> account to test the permission against, so its picker searches whatever of `name`, `email` and
> `title` that model has and is text-typed, and shows up to twenty matches. That is a list of your
> people's names and addresses, offered to everybody with `view` on a permission. It is off with one
> line if that is not a trade you want. A `%` in the box is looked for rather than obeyed, and a
> model with none of those three columns returns nothing instead of the first twenty rows. A column
> among the three that is not text-typed is left out the same way — a
> `name` stored as an integer, say — because `LIKE` raises on Postgres against a column that is not
> one; MySQL and SQLite would have coerced it silently.

### Catalog

```php
'catalog' => [
    'models' => [],   // models with a policy and no resource
    'custom' => [],   // loose permissions, as name => scope
    'scopes' => [
        'read'         => ['viewAny', 'view'],
        'write'        => ['create', 'update'],
        'withdraw'     => ['delete', 'deleteAny', 'restore', 'restoreAny'],
        'irreversible' => ['forceDelete', 'forceDeleteAny'],
    ],
],
```

### Navigation

```php
'navigation' => [
    'group' => null,           // falls back to this package's own translated group
    'roles' => [
        'slug' => 'roles',     // what the URL says
        'icon' => null,        // falls back to a shield
        'sort' => null,        // navigation order
    ],
    'permissions' => [
        'slug' => 'permissions',
        'icon' => null,        // falls back to a key
        'sort' => null,
    ],
],
```

---

## 🤔 Why this package?

There's already a well-known permissions plugin for Filament, and for most projects it's the right answer. **Filament Warden** exists for what Warden does that others don't:

| Feature | Filament Warden | Others |
|---|---|---|
| Catalog derived from policies | ✅ Automatic | ❌ Manual |
| Permissions against models | ✅ By class | ❌ Plain strings |
| Explicit denials | ✅ Real state | ❌ Absence = denial |
| Cause inspector | ✅ Built-in | ❌ Not available |
| SQL-like conditions | ✅ Native | ❌ Not available |
| Renaming resources | ✅ No breakage | ❌ Orphan permissions |

---

## 📦 Stability

Everything below is covered by **SemVer**: changing any of it is a **major release**. `tests/FrozenTest.php` is what says so — it fails when one of them moves.

Two different kinds of thing are in that list, and both matter for the same reason.

**The names are rows in your database.** A permission called `page:App\Filament\Pages\Settings` was granted to a role a year ago. Renaming the prefix does not fail: the row stays, stays grantable, and opens nothing.

**The keys are lines in your application** — a published config, an overridden translation, a command in a deploy script. Removing one is silent here and loud there.

### ✅ Frozen (SemVer)

| Category | Items |
|---|---|
| Permission prefixes | `page:`, `widget:`, `panel:` and `PermissionName`, which mints them and reads them back |
| Plugin | `FilamentWardenPlugin`, its ID `filament-warden`, and its six methods: `make()`, `getId()`, `register()`, `boot()`, `roles()`, `permissions()` |
| Fields | `PermissionGrid`, `PermissionGridEntry`, `ConditionBuilder`, `RoleAssignment`, the `{stances, narrowing, until, inherited, baseline}` state envelope a form receives — adding a key to it is a minor — and the key `RoleAssignment` keeps beside its own list, `__filament_warden_roles_baseline`, which sits in your page's state array |
| Relation managers | `RolesRelationManager` and `PermissionsRelationManager`'s class names — a consuming application's own `UserResource::getRelations()` stores them by name, so renaming either breaks every installation that attached it |
| Traits | `AuthorizesPageAccess`, `AuthorizesWidgetView`, `AccessesPanels` |
| Authorization | `WardenPolicy`, `Access` |
| Catalog | `Catalog` and its seven public methods — `for()`, `relationManagers()`, `resourceClasses()`, `pageClasses()`, `widgetClasses()`, `union()`, `forget()` — plus `Entry` and its `key()`, `Origin`, `Scope` |
| Guard | `PanelIsOpen` |
| Config | Every key path of `config/filament-warden.php` — all 29 of them, counted against the file rather than remembered, each pinned with the shape it holds. The pin stops at a key whose value is a list or an empty array: what goes inside those is your data, not our schema |
| Translations | Every key path of `lang/*/ui.php`, in both locales |
| Commands | `filament-warden:assign`, `filament-warden:audit` and `filament-warden:catalog`, with their arguments and, for `audit`, its `--check` option |

**Adding to one of these — a translation key, a config key, a key in the grid's state envelope — is a minor, not a major**: nothing you wrote stops working. Only removing or renaming one is a break. Both pins list every path and compare in order, so on our side an addition also turns the build red — deliberately, so that a new key is a line somebody typed on purpose rather than a diff nobody read.

### ⚠️ Not frozen (may change in any release)

`Grants\`, `Conditions\`, `Filament\Guard`, `Filament\Forms\Grid\` and everything in `Catalog\` other than the classes named above are this package's insides. They move without warning.

Five consequences worth saying out loud, because each one is a place the line is easy to cross by accident:

- **A published view is welded to those insides.** `filament-warden-views` is a real escape hatch and you are welcome to it, but your copy calls `$getGrid()` and walks a `GridView`, its tabs, rows and cells — all internal. Expect to re-merge it on a minor. If you want markup that keeps working, wrap the field rather than forking its view.
- **The screens are not an extension point.** `RoleResource`, `PermissionResource` and their pages are left non-final so you can experiment, not because subclassing them is supported. They change whenever the screens change.
- **`whereCan()` is warden's, not ours.** Its answer can disagree with the panel's, and it never consults the Gate or a policy.
- **`DrawsThePermissionGrid` is not one of the frozen traits.** The three named in the table above are; this one is the shared insides of `PermissionGrid` and `PermissionGridEntry`, and it grows a method whenever those two learn a new fact about their own render. If you composed it into a class of your own, expect to implement new methods on it — an upgrade that adds one is a fatal otherwise. The CHANGELOG names each.
- **The inspector's bridge is not an API.** `explainCell()` and `narrowingFor()` are exposed to the browser so the field's own script can ask them one cell at a time. What they answer is internal and it moves. An empty array back from `explainCell()` means only *this grid cannot be asked that* — the inspector is switched off, or the cell is not in the catalogue; a record that has not been saved yet gets a real explanation. If you call either method from your own component, read the answer, do not assume its shape.

#### Not frozen: the word *account*

The condition builder offers the signed-in account's columns as `account.id` and the like. That word is a placeholder for whatever an application calls its user model, and it may change. Nothing is stored under it — it is a label on a screen.

---

## 🧪 Development

You don't need PHP or Composer locally — everything runs through Docker:

```bash
make build    # Build dev image
make install  # composer install
make test     # Test suite
make ci       # Everything CI runs
```

See `CHANGELOG.md` for what each version adds, and `CONTRIBUTING.md` before opening a PR.

---

## 👤 Credits

- **Carlos Mayorga** ([@elpandape](https://github.com/elpandape))

---

## 📄 License

Filament Warden is open-source software licensed under the [MIT License](LICENSE.md).

---

<p align="center">
  <sub>Built with ❤️ for the Filament community.</sub>
</p>
