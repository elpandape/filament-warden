<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Carbon\Laravel\ServiceProvider as CarbonServiceProvider;
use Composer\InstalledVersions;
use ElPandaPe\FilamentWarden\Conditions\Columns;
use ElPandaPe\FilamentWarden\FilamentWardenServiceProvider;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Providers\BarePanelProvider;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Providers\LaxPanelProvider;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Providers\TestPanelProvider;
use ElPandaPe\Warden\WardenServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as ApplicationTestCase;
use RuntimeException;

/**
 * The boot every test file asks for with `pest()->extend(TestCase::class)`, which resolves
 * the file calling it — hence no `->in(...)` anywhere in `tests/Pest.php`.
 */
abstract class TestCase extends ApplicationTestCase
{
    use RefreshDatabase;

    /** @var Application */
    protected $app;

    protected function setUp(): void
    {
        parent::setUp();

        // The applications this package is built for run Eloquent strictly: without this the
        // suite tests a laxer world than the one it ships into.
        Model::shouldBeStrict();

        // Nothing in Laravel caches a column listing, so the package does it itself.
        // A suite raises a different schema for every case and would read the one before.
        Columns::forget();

        // No request has been through the panel's middleware here, so nothing has told
        // Filament which panel it is serving. Without this every resource resolves against
        // no panel at all and its pages refuse to mount.
        Filament::setCurrentPanel('test');
        Filament::bootCurrentPanel();
    }

    /**
     * Filament has to register before Livewire: `SupportServiceProvider` binds `DataStore`
     * with an unshared `bind()` that overwrites Livewire's instance, so every
     * `store($component)->set(...)` is lost and the render dies on a null error bag.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            WardenServiceProvider::class,
            FilamentWardenServiceProvider::class,
            ActionsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            QueryBuilderServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            LivewireServiceProvider::class,
            // Carbon does not follow `App::setLocale()` on its own; a consuming application
            // gets this provider auto-discovered.
            CarbonServiceProvider::class,
            TestPanelProvider::class,
            BarePanelProvider::class,
            LaxPanelProvider::class,
        ];
    }

    /** @param  Application  $app */
    protected function getEnvironmentSetUp($app): void
    {
        /** @var Repository $config */
        $config = $app['config'];

        $config->set('app.key', 'base64:AckfSECXIvnK5r28GVIWUAxmbBSjTsmF/0kOO7HH+Z8=');

        // The fixture views the suite renders, under a namespace of their own so
        // they can never be mistaken for the ones the package ships.
        View::addNamespace('filament-warden-tests', __DIR__.'/Fixtures/resources/views');

        $config->set('app.locale', 'en');
        $config->set('app.fallback_locale', 'en');

        $config->set('cache.default', 'array');
        $config->set('session.driver', 'array');
        $config->set('queue.default', 'sync');

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $config->set('auth.defaults.guard', 'web');
        $config->set('auth.guards.web', ['driver' => 'session', 'provider' => 'users']);
        $config->set('auth.providers.users', ['driver' => 'eloquent', 'model' => User::class]);
    }

    /**
     * Warden ships its schema as a publishable stub and never loads it from vendor, so the
     * four tables are raised here by requiring the file and calling `up()` by hand.
     *
     * The hook is `defineDatabaseMigrationsAfterDatabaseRefreshed()` and not
     * `defineDatabaseMigrations()`: testbench calls the second *before* refreshing the
     * database, and with sqlite in memory the refresh raises an empty one over everything
     * created there. The symptom is a `no such table` halfway through the suite. The install
     * path is resolved through Composer's runtime API rather than hardcoded, so a custom
     * `vendor-dir` or a path repository does not break it.
     */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $installPath = InstalledVersions::getInstallPath('elpandape/warden');

        if ($installPath === null) {
            throw new RuntimeException('Could not resolve the install path of elpandape/warden.');
        }

        /** @var Migration $migration */
        $migration = require $installPath.'/database/migrations/create_warden_tables.php.stub';

        $migration->up(); // @phpstan-ignore method.notFound

        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        // The one fixture model the suite persists: a grant over a record is not
        // a cell of the grid, and proving it needs a record.
        Schema::create('posts', static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        // The one with an ownership column, which is what makes "only what it
        // owns" offerable for it and not for a post.
        Schema::create('comments', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('body');
            $table->timestamps();
        });
    }
}
