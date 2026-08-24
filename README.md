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
  <a href="https://filamentphp.com"><img src="https://img.shields.io/badge/Filament-5.7-4ade80?style=flat-square" alt="Filament 5.7"></a>
</p>

---

## 📖 Table of Contents

- [✨ Features](#-features)
- [📋 Requirements](#-requirements)
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
| elpandape/warden | `^1.0` |

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

For a small installation. Frozen since `v0.7.0`, and still the right answer there:

```php
use ElPandaPe\FilamentWarden\Filament\Forms\RoleAssignment;

RoleAssignment::make('roles')->columnSpanFull(),
```

> 🚫 **Don't use `CheckboxList::make('roles')->relationship(...)`**. That saves through `sync()`, and `sync()`, `attach()` and `detach()` all **skip** warden's cache bump — only warden's own actions make it. A role handed out that way goes on answering the old way, silently and with no expiry. `RoleAssignment` writes through warden's fluent API instead.

> 🔒 **A role assigned outside the tenant you are viewing from cannot be handed back here, from
> `v1.3.0`.** Warden's own tenant scope reads a role as held from *global or this tenant*, so a
> globally assigned role shows as ticked from inside any tenant — but a retract targets one exact
> scope. Unticking that box now locks instead of quietly deleting nothing (and reporting success)
> or, worse, deleting a real tenant-scoped row while the global one keeps it looking held. Switch
> tenant to change it.

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

> 🚫 **A grid that cannot be operated says so.** From `v1.1.0`, a protected role's grid, a field
> your application called `->disabled()` on, and the read-only screen (`ViewRole`) all print one
> sentence above the table — "This grid cannot be changed from here: its cells select, they do not
> cycle." — instead of silently accepting clicks that never save or, on the read-only screen,
> saying nothing at all. A protected role keeps its own stronger notice naming `roles.protected`;
> the other two share this one, because neither route lets the package know *why* it cannot write.

> 🤝 **From `v1.6.0` two people editing the same role no longer undo each other.** A save used to
> compare the store against what your browser held and write every difference — so a cell somebody
> else had changed while your page sat open was quietly changed back. It now compares three things,
> including what your screen was showing when it opened. A cell you did not touch is left as they
> set it. A cell you both moved to different values is refused and named, rather than resolved in
> silence in favour of whoever saved last. The grid re-reads the store afterwards, so your next save
> starts from what is actually there.
>
> **From `v1.7.0` the same holds when you hand roles out from an account.** `RoleAssignment` keeps
> its own copy of what the store said, in a namespaced key beside its list, and leaves alone any role
> you did not tick or untick. Nothing is ever *refused* there — a role is held or it is not, so two
> people can only ever have moved one the same way — and the field sends its own notice, because that
> form is yours and there is no notification of ours to replace.
>
> Two things this still does not cover. If you embed `PermissionGrid` on a page of your own rather
> than using the roles screen, the *protection* is in the field and works, but the *report* is not —
> `EditRole` is what turns it into a notification, so on your page a refused cell is simply not
> written and the save says nothing about it. And `RoleAssignment` keeps its copy beside its own
> state, so a schema with **no state path at all** gives it nowhere to keep one: that page saves the
> way every page did before `v1.7.0`, with no notice either way.

> ⚡ **From `v1.5.0` a save writes in groups.** Cells that share an entity and a stance and have
> nothing left to narrow go out in one warden call instead of one per cell. If you listen for
> `GrantingPermission` or `ForbiddingPermission`, that is **one event per group carrying every name
> in it**, and a listener that vetoes one now vetoes the whole group. Cells narrowed to "only what
> it owns" or to conditions still go one at a time — warden's `where()` re-points every permission
> in a chain at the same twin, so two cells asking for two different conditions can never share a
> call.

> 📱 **From `v1.10.0` the grid folds when the columns do not fit.** Below `55.9375rem` the table is
> replaced by one card per entity, holding one disclosure per scope — read, write, withdraw,
> irreversible — and one row per action inside it. It is not a second grid: every cell is the same
> button, bound to the same state, so whatever one reading says the other says too. One thing does
> not survive the fold: the per-row `read` / `all` / `none` shortcuts, which are revealed by hovering
> a table row and have no row to hover.
>
> The inspector moved below the grid in the same release, and the rule-scope picker became a
> segmented radiogroup — one tab stop, arrow keys that step over what cannot be picked, and only the
> chosen mode's hint. If you publish this package's views, that is a re-merge.

### Permission Inspector

Click any cell to see:
- **Cause**: Why does this cell have this value?
- **Permission**: Which specific rule decided it
- **Role**: Which role it came from

> 🔍 The inspector is queried on demand (not automatically) to avoid hundreds of queries.

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

> 🔒 **A locked cell lights none of the three.** From `v1.1.0`, a cell the grid cannot let you set
> — more than one rule for the same action, a condition it cannot parse, or a grant that belongs to
> another tenant — draws its actual reach and highlights none of "Every row", "Only owned" or "With
> conditions", instead of defaulting to "Every row" as it did before. The inspector names which of
> the three it is and, when there is a stored rule, shows it read-only underneath the note. A row
> that is both "only what it owns" **and** carries conditions is drawn the same way: read-only, with
> its stored rule shown, never silently narrowed to plain ownership.

> ⚠️ **A grant pinned to a single record is not a cell.** Warden filters a check made against a class down to `entity_id is null`, so a rule with a record key on it answers nothing the grid asks — and it is not a wider rule either. The grid lists those rules above the tabs, read-only: this screen shows them, and cannot remove them.

> 🔒 **A rule the permission form cannot write back exactly locks there, from `v1.3.0`.** A value
> stored as the string `'2'`, `'2.5'`, `'true'` or `'false'` would read back as another type the
> moment that screen's condition builder parses it, and a rule whose first line is `or` would read
> back as `and` — both would change what the row means for everybody holding it. Instead, the field
> draws the rule, explains why, and leaves it alone rather than silently rewriting it on the next
> save. It can still be edited from warden's own fluent API. And a `true`/`false` value compared
> against a column the model has not cast to `boolean` gets its own warning — on the permission form
> and in this grid's inspector alike — because that comparison is stored and then never matches a
> single row.

> ℹ️ **The grid never locks a cell for this, and from `v1.3.2` it stops making the type mismatch
> worse.** A stance flip — grant to forbid, or back — used to re-type a mis-typed condition through
> the browser's own casting rules as a side effect, on a click that only meant to change the stance:
> the string `'true'` could come back as the boolean `true`, which matches every row the string
> never did. Now the value survives untouched whenever the stance is all that moved. What the grid
> still does not fix, because it is a different and lighter hazard: a rule whose first line reads
> `or` still comes back as `and` on any grid save, stance-only or not — it changes no cell's answer,
> only which permission row backs it, which shows up as an orphaned row in `filament-warden:audit`,
> not as a wrong answer on screen.

### Permissions Screen

Lists the `permissions` **table** — the rows warden has actually created — and says where each one came from:

- **Provenance**: derived from a policy, loose, the wildcard, or an entity nothing declares any more
- **Reach**: every row, only what the account owns, with conditions — or **one record only**, when the row is pinned to a single record
- **Holders**: how many roles hold it, with denials counted apart
- **Test bench**: ask warden about a real account, from the screen

> ℹ️ **On a fresh install this screen is empty, and that is correct.** Warden creates a permission row the first time something is granted, so nothing exists until you hand something out. The roles screen is the one that shows the whole catalogue derived from your policies, row or no row. To see the catalogue itself — without opening a screen, and whether or not it has a row yet — run [`filament-warden:catalog`](#catalog-command).

---

## 🛡️ Security

### The Guard

From v0.8.0, the panel **refuses to boot** if it finds an unguarded page or widget. That is what stops a custom screen from being left open to everyone by accident.

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

It writes nothing, and reports nine things:

- **screens nobody guards** — the same finding the guard throws on, which is how it reaches CI at all: no artisan command ever starts a panel;
- **resources whose model has no policy** — the case Filament fails open on, told apart from a policy that declares nothing and from a resource pointing at a class that does not exist;
- **permissions the catalogue declares that no grant points at** — *informational: this one never turns `--check` red*. Turning a grid cell off revokes the grant and leaves the row, because warden's `revoke()` only touches `grants`, so a build that failed on this would fail on every save and stay failing. `php artisan warden:clean` is what removes them, and `--dry-run` shows the list first;
- **permissions nothing declares that no grant points at** — a rename left them behind: they can never match again, and nothing will ever create them;
- **grants for actions nothing declares any more** — a renamed policy method, a typo in a seeder, a screen that was deleted: the silent mistake warden has no way to detect;
- **whole entity types nothing declares** — a morph alias that moved, reported apart because the fix is the opposite one;
- **models only a relation manager reaches**, with the `catalog.models` line that settles it;
- **catalogue names carrying a dot** — Livewire splits a state path on dots, so such a name cannot be a cell and a role screen throws the moment it draws one. Rename the permission. New in `v1.8.0`: before it, the only way to find out was somebody opening the screen;
- **grants whose authority no longer exists** — *informational, like the permissions-the-catalogue-declares-that-no-grant-points-at bucket above (third bullet): this one never turns `--check` red either, and is new in `v1.9.0`*. Warden's schema puts a foreign key on `assigned_roles.role_id` and on `grants.permission_id`, never on the two columns that name a grant's authority, so deleting a role takes its assignments and leaves its own grants behind — no listener picks them up, and `warden:clean` cannot see them either, because it prunes *permissions* nothing points at, not grants pointing at nobody. Reported once per stranded authority — the deduplicated `type:key` a whole cluster of grants can share — and once per authority type this installation cannot even resolve.

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

**Deleting a role looks across every tenant.** From `v1.0.2`, `roles.delete => 'unassigned'` counts assignments with warden's tenant scope lifted. A role held only under a tenant you are not currently in would otherwise read as unassigned, and deleting it takes that tenant's `assigned_roles` and `grants` rows with it through the foreign key — which never looked at `scope`, and neither does `$record->delete()`.

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

`Catalog::for()` reflects every Policy the panel declares and walks its resources, pages and widgets besides, so from `v1.5.0` it is built once per panel and kept for the life of the process. Nothing it derives from moves while that process runs: panels, Policies and `catalog.*` all come from code and config loaded at boot. The one thing that would go stale is an application rewriting `catalog.*` config at runtime — invisible until you drop the memo:

```php
Catalog::forget();
```

**`Catalog::union(array $panels): self`** merges more than one panel's catalogue into one, from `v1.9.0`. A multi-panel installation needs this for provenance, not `for()` alone: the permissions screen, its infolist and its form all ask every panel now, because asking only the current one used to draw a row derived in another panel as "Nothing declares it" while `filament-warden:audit` — which already read every panel — said the opposite.

#### Custom Permissions

```php
// config/filament-warden.php
'catalog' => [
    'models' => [\Laravel\Passkeys\Passkey::class],
    'custom' => ['export-reports' => 'read'],
],
```

> 🚫 Custom names **cannot contain dots** (`.`) — they break Livewire state.

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
],
```

**A row somebody holds cannot be re-pointed.** From `v1.0.2`, the name and the entity of a permission that at least one role already holds are locked on its edit screen, and put back on the server if the payload says otherwise — at every setting of `update` except `'all'`. Moving them moves what those holders hold without revoking anything and without writing a single row to `grants`: the check they used to pass simply starts answering something else. `'loose'` still mints and edits the rows nobody holds yet, and the conditions and the ownership checkbox stay editable wherever they were before — those narrow what a row means, they do not re-point it.

### Roles (roles screen)

```php
'roles' => [
    'create'    => true,             // true | false
    'delete'    => 'unassigned',     // false | 'unassigned' | 'all'
    'protected' => ['super-admin'],  // names that cannot be taken, renamed onto, or deleted
],
```

A protected role keeps its name and its grid: both are shown, neither can be edited, and it cannot be deleted. Its title is left editable — nothing resolves by it.

**From `v1.0.2` a role cannot arrive at a protected name either.** Creating a role called `super-admin`, or renaming an ordinary one onto it, is refused by the form — before `1.0.2` both succeeded and the role was born protected, which is a way of minting an unremovable role by typing. The role that already carries the name keeps it: only the *arrival* is closed. From `v1.1.0` the refusal names which list the name is on, instead of the framework's generic validation wording.

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
],
```

> 🔎 **`probe` lets anyone who can view a permission search your accounts.** The bench needs an
> account to test the permission against, so its picker searches whatever of `name`, `email` and
> `title` that model has and is text-typed, and shows up to twenty matches. That is a list of your
> people's names and addresses, offered to everybody with `view` on a permission. It is off with one
> line if that is not a trade you want. From `v1.8.0` a `%` in the box is looked for rather than
> obeyed, and a model with none of those three columns returns nothing instead of the first twenty
> rows. From `v1.9.0` a column among the three that is not text-typed is left out the same way — a
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

From `v1.0.0`, everything below is covered by **SemVer**: changing any of it is a **major release**. `tests/FrozenTest.php` is what says so — it fails when one of them moves.

Two different kinds of thing are in that list, and both matter for the same reason.

**The names are rows in your database.** A permission called `page:App\Filament\Pages\Settings` was granted to a role a year ago. Renaming the prefix does not fail: the row stays, stays grantable, and opens nothing.

**The keys are lines in your application** — a published config, an overridden translation, a command in a deploy script. Removing one is silent here and loud there.

### ✅ Frozen (SemVer)

| Category | Items |
|---|---|
| Permission prefixes | `page:`, `widget:`, `panel:` and `PermissionName`, which mints them and reads them back |
| Plugin | `FilamentWardenPlugin`, its ID `filament-warden`, and its six methods: `make()`, `getId()`, `register()`, `boot()`, `roles()`, `permissions()` |
| Fields | `PermissionGrid`, `PermissionGridEntry`, `ConditionBuilder`, `RoleAssignment`, the `{stances, narrowing, baseline}` state envelope a form receives — `baseline` joined it in `v1.6.0`, and an addition is a minor — and the key `RoleAssignment` keeps beside its own list, `__filament_warden_roles_baseline`, which from `v1.7.0` sits in your page's state array |
| Relation managers | `RolesRelationManager`'s class name — a consuming application's own `UserResource::getRelations()` stores it by name, so renaming the class breaks every installation that attached it |
| Traits | `AuthorizesPageAccess`, `AuthorizesWidgetView`, `AccessesPanels` |
| Authorization | `WardenPolicy`, `Access` |
| Catalog | `Catalog` and its seven public methods — `for()`, `relationManagers()`, `resourceClasses()`, `pageClasses()`, `widgetClasses()`, `union()`, `forget()` — plus `Entry` and its `key()`, `Origin`, `Scope` |
| Guard | `PanelIsOpen` |
| Config | Every key path of `config/filament-warden.php` — all 27 of them, each pinned with the shape it holds. The pin stops at a key whose value is a list or an empty array: what goes inside those is your data, not our schema |
| Translations | Every key path of `lang/*/ui.php`, in both locales |
| Commands | `filament-warden:assign`, `filament-warden:audit` and `filament-warden:catalog`, with their arguments |

**Adding to one of these — a translation key, a config key, a key in the grid's state envelope — is a minor, not a major**: nothing you wrote stops working. Only removing or renaming one is a break. Both pins list every path and compare in order, so on our side an addition also turns the build red — deliberately, so that a new key is a line somebody typed on purpose rather than a diff nobody read.

### ⚠️ Not frozen (may change in any release)

`Grants\`, `Conditions\`, `Filament\Guard`, `Filament\Forms\Grid\` and everything in `Catalog\` other than the classes named above are this package's insides. They move without warning.

Five consequences worth saying out loud, because each one is a place the line is easy to cross by accident:

- **A published view is welded to those insides.** `filament-warden-views` is a real escape hatch and you are welcome to it, but your copy calls `$getGrid()` and walks a `GridView`, its tabs, rows and cells — all internal. Expect to re-merge it on a minor. If you want markup that keeps working, wrap the field rather than forking its view.
- **The screens are not an extension point.** `RoleResource`, `PermissionResource` and their pages are left non-final so you can experiment, not because subclassing them is supported. They change whenever the screens change.
- **`whereCan()` is warden's, not ours.** Its answer can disagree with the panel's, and it never consults the Gate or a policy.
- **`DrawsThePermissionGrid` is not one of the frozen traits.** The three named in the table above are; this one is the shared insides of `PermissionGrid` and `PermissionGridEntry`, and it grows a method whenever those two learn a new fact about their own render. **v1.1.0 adds `gridInteracts(): bool` to it.** If you composed it into a class of your own, implement it — `return false;` if your screen does not write — or the upgrade is a fatal.
- **The inspector's bridge is not an API.** `explainCell()` and `narrowingFor()` are exposed to the browser so the field's own script can ask them one cell at a time. What they answer is internal and it moves: from `1.1.0`, an empty array back from `explainCell()` means only *this grid cannot be asked that* — the inspector is switched off, or the cell is not in the catalogue. A record that has not been saved yet now gets a real explanation instead. If you call either method from your own component, read the answer, do not assume its shape.

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
