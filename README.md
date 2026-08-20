<p align="center">
  <img src="https://raw.githubusercontent.com/elpandape/filament-warden/main/art/banner.png" alt="Filament Warden" width="800">
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
    - [Assign Roles](#assign-roles)
- [🖥️ The Screens](#-the-screens)
    - [The Permission Grid](#the-permission-grid)
    - [Permission Inspector](#permission-inspector)
    - [Permissions Screen](#permissions-screen)
- [🛡️ Security](#️-security)
    - [The Guard](#the-guard)
    - [Audit](#audit)
- [🔧 Advanced](#-advanced)
    - [Permission Scope](#permission-scope)
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

    // Optional: add custom conditions
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isActive() && parent::canAccessPanel($panel);
    }
}
```

> ⚠️ **Caution:** `canAccessPanel()` is called from 4 different places. Don't use conditions the user is supposed to "resolve" (like email verification) — use Filament's own `->emailVerification()` for that.

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

> 🚫 **Don't use `CheckboxList::make('roles')->relationship(...)`**. `sync()` silently invalidates warden's cache. `RoleAssignment` uses warden's native API.

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

> 💡 **Use `Access` instead of `$user->can()`**. If you disable `warden.gate.register`, `$user->can()` will silently return `false` for loose permissions.

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

### Permission Scope

Each cell can reach:

| Scope | Description |
|---|---|
| **Every row** | Permission applies globally |
| **Only owned** | Restricted to records where `user_id` matches |
| **With conditions** | Custom SQL-like rules |

Example conditions:
```
name = editor OR (scope >= 2 AND title = account.name)
```

### Permissions Screen

Shows the full catalog with additional info:
- **Origin**: Derived from policy, loose, wildcard, or obsolete?
- **Scope**: Global, owned, or conditional?
- **Holders**: Count of who has it (with denials counted separately)
- **Test bench**: Verify permissions in real time

---

## 🛡️ Security

### The Guard

From v0.8.0, the panel **refuses to boot** if it detects unprotected pages or widgets. This prevents custom screens from being accidentally left open.

```php
// In your PanelProvider — already enabled by default
FilamentWardenPlugin::make()
    ->guardPages(true)    // default: true
    ->guardWidgets(true); // default: true
```

### Audit

```bash
# View report
php artisan filament-warden:audit

# CI mode (fails with exit code 1 if issues found)
php artisan filament-warden:audit --check
```

Detects:
1. 📺 Unguarded screens
2. 📄 Resources without policies
3. 🗑️ Permissions with no grants
4. 👻 Grants for non-existent actions
5. 📦 Undeclared entities
6. 🔗 Models only accessible via relation managers

---

## 🔧 Advanced

### Permission Scope

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

This package's resources declare `->tenant(null)` to avoid conflicts with Filament's global scope.

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
    'create'      => false,        // true | false
    'update'      => 'loose',      // false | 'title' | 'loose' | 'all'
    'delete'      => 'orphaned',   // false | 'orphaned' | 'all'
    'constraints' => true,         // Condition builder
    'only_owned'  => true,         // Ownership checkbox
    'probe'       => true,         // Test bench
],
```

### Roles (roles screen)

```php
'roles' => [
    'create'     => true,              // true | false
    'delete'     => 'unassigned',      // false | 'unassigned' | 'all'
    'protected'  => ['super-admin'],  // Immutable role names
],
```

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

### Navigation

```php
'navigation' => [
    'group'             => null,
    'roles.slug'        => 'roles',
    'permissions.slug'  => 'permissions',
    'roles.icon'        => null,  // Default: shield
    'permissions.icon'  => null,  // Default: key
    'roles.sort'        => null,
    'permissions.sort'  => null,
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

From `v1.0.0`, everything below is covered by **SemVer**. Changing any of these requires a **major release**:

### ✅ Frozen (SemVer)

| Category | Items |
|---|---|
| Permission prefixes | `page:`, `widget:`, `panel:` and `PermissionName` |
| Plugin | `FilamentWardenPlugin`, its ID `filament-warden`, and methods |
| Fields | `PermissionGrid`, `PermissionGridEntry`, `ConditionBuilder`, `RoleAssignment` |
| Traits | `AuthorizesPageAccess`, `AuthorizesWidgetView`, `AccessesPanels` |
| Authorization | `WardenPolicy`, `Access` |
| Catalog | `Catalog::for()`, `Entry`, `key()`, `Origin`, `Scope` |
| Guard | `PanelIsOpen` |
| Config | All keys in `config/filament-warden.php` |
| Translations | All key paths in `lang/*/ui.php` |
| Commands | `filament-warden:assign` and `filament-warden:audit` |

### ⚠️ Not frozen (may change in any release)

- Internal classes: `Grants\`, `Conditions\`, `Filament\Guard`, `Filament\Forms\Grid\`
- Published views (require re-merge on every update)
- Resources: `RoleResource`, `PermissionResource` and their pages

> 📌 **Note on published views:** When publishing views with `--tag=filament-warden-views`, your copy calls internal methods like `$getGrid()`. Expect to re-integrate changes on every minor update.

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
