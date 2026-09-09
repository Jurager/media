<?php

namespace Jurager\Media\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Jurager\Media\MediaServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

/** No jurager/eav anywhere in this package's own vendor tree — proves the package works standalone. */
abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [MediaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('media.disk', 'local');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }
}
