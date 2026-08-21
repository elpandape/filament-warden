<h1 align="center">Filament Warden</h1>

<p align="center">
  <strong>Advanced roles and permissions for Filament</strong><br>
  Built on <code>elpandape/warden</code> — a permission grid derived from your policies, explicit denials, and conditional grants.
</p>

<p align="center">
  <a href="https://packagist.org/packages/elpandape/filament-warden"><img src="https://img.shields.io/packagist/v/elpandape/filament-warden?style=flat-square&color=blue" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/elpandape/filament-warden"><img src="https://img.shields.io/packagist/dt/elpandape/filament-warden?style=flat-square&color=green" alt="Total Downloads"></a>
  <a href="LICENSE.md"><img src="https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square" alt="License"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.5-777BB4?style=flat-square&logo=php" alt="PHP 8.5"></a>
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
| 📊 **Automatic audit** | Detects unguarded screens, missing policies, and orphaned permissions. |
| 🔄 **Smart cache** | Automatic invalidation when assigning roles. No ghost permissions. |
| 🏢 **Multi-tenancy** | Native support for tenant scopes across all warden tables. |

---

## 📋 Requirements

| Requirement | Version |
|---|---|
| PHP | `^8.5` |
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

```bash
# Assign the role to your user
php artisan filament-warden:assign super-admin "App\Models\User:1"
```

🎉 **Done!** Open `/admin/roles` and you'll see the permission grid. Click a cell to grant, click again to deny, save, and you're set.

---

## 🔌 Setup

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

Add the field to your user resource:

```php
use ElPandaPe\FilamentWarden\Filament\Forms\RoleAssignment;

RoleAssignment::make('roles')->columnSpanFull(),
```

> 🚫 **Don't use `CheckboxList::make('roles')->relationship(...)`**. That saves through `sync()`, and `sync()`, `attach()` and `detach()` all **skip** warden's cache bump — only warden's own actions make it. A role handed out that way goes on answering the old way, silently and with no expiry. `RoleAssignment` writes through warden's fluent API instead.

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

> ⚠️ **A grant pinned to a single record is not a cell.** Warden filters a check made against a class down to `entity_id is null`, so a rule with a record key on it answers nothing the grid asks — and it is not a wider rule either. The grid lists those rules above the tabs, read-only: this screen shows them, and cannot remove them.

### Permissions Screen

Lists the `permissions` **table** — the rows warden has actually created — and says where each one came from:

- **Provenance**: derived from a policy, loose, the wildcard, or an entity nothing declares any more
- **Reach**: every row, only what the account owns, with conditions — or **one record only**, when the row is pinned to a single record
- **Holders**: how many roles hold it, with denials counted apart
- **Test bench**: ask warden about a real account, from the screen

> ℹ️ **On a fresh install this screen is empty, and that is correct.** Warden creates a permission row the first time something is granted, so nothing exists until you hand something out. The roles screen is the one that shows the whole catalogue derived from your policies, row or no row.

---

## 🛡️ Security

### The Guard

From v0.8.0, the panel **refuses to boot** if it finds an unguarded page or widget. That is what stops a custom screen from being left open to everyone by accident.

It is on by default, and **the plugin takes no options**: `FilamentWardenPlugin` accepts `make()`, `getId()`, `register()` and `boot()`, and nothing else. The switches are config keys, one per kind:

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

# CI mode (fails with exit code 1 if issues found)
php artisan filament-warden:audit --check
```

It writes nothing, and reports six things:

- **screens nobody guards** — the same finding the guard throws on, which is how it reaches CI at all: no artisan command ever starts a panel;
- **resources whose model has no policy** — the case Filament fails open on, told apart from a policy that declares nothing and from a resource pointing at a class that does not exist;
- **permissions no grant points at** — `php artisan warden:clean` is what removes them, and `--dry-run` shows the list first;
- **grants for actions nothing declares any more** — a renamed policy method, a typo in a seeder, a screen that was deleted: the silent mistake warden has no way to detect;
- **whole entity types nothing declares** — a morph alias that moved, reported apart because the fix is the opposite one;
- **models only a relation manager reaches**, with the `catalog.models` line that settles it.

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
    'probe'       => true,         // the test bench, built on explain()
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

**From `v1.0.2` a role cannot arrive at a protected name either.** Creating a role called `super-admin`, or renaming an ordinary one onto it, is refused by the form — before `1.0.2` both succeeded and the role was born protected, which is a way of minting an unremovable role by typing. The role that already carries the name keeps it: only the *arrival* is closed. The refusal currently uses the framework's own validation wording; a sentence saying which list the name is on is coming in `v1.1.0`.

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
| Plugin | `FilamentWardenPlugin`, its ID `filament-warden`, and its four methods: `make()`, `getId()`, `register()`, `boot()` |
| Fields | `PermissionGrid`, `PermissionGridEntry`, `ConditionBuilder`, `RoleAssignment`, and the `{stances, narrowing}` state envelope a form receives |
| Traits | `AuthorizesPageAccess`, `AuthorizesWidgetView`, `AccessesPanels` |
| Authorization | `WardenPolicy`, `Access` |
| Catalog | `Catalog::for()`, `Entry` and its `key()`, `Origin`, `Scope` |
| Guard | `PanelIsOpen` |
| Config | Every key of `config/filament-warden.php` — the test pins the six top-level blocks, and the promise covers the keys inside them too |
| Translations | Every key path of `lang/*/ui.php`, in both locales |
| Commands | `filament-warden:assign` and `filament-warden:audit`, with their arguments |

**Adding a translation key or a config key is a minor, not a major**: nothing you wrote stops working. Only removing or renaming one is a break.

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
