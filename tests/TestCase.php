<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests;

use ElPandaPe\FilamentWarden\FilamentWardenServiceProvider;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;
use ElPandaPe\Warden\WardenServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as ApplicationTestCase;

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
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            WardenServiceProvider::class,
            FilamentWardenServiceProvider::class,
        ];
    }

    /** @param  Application  $app */
    protected function getEnvironmentSetUp($app): void
    {
        /** @var Repository $config */
        $config = $app['config'];

        $config->set('app.key', 'base64:AckfSECXIvnK5r28GVIWUAxmbBSjTsmF/0kOO7HH+Z8=');

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
     * created there. The symptom is a `no such table` halfway through the suite.
     */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        /** @var Migration $migration */
        $migration = require dirname(__DIR__).'/vendor/elpandape/warden/database/migrations/create_warden_tables.php.stub';

        $migration->up(); // @phpstan-ignore method.notFound

        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }
}
