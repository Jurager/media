<?php

namespace Jurager\Media;

use Illuminate\Support\ServiceProvider;
use Jurager\Media\Console\Commands\MediaCleanCommand;
use Jurager\Media\Console\Commands\MediaRegenerateCommand;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/media.php', 'media');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/media.php' => config_path('media.php'),
            ], 'media-config');

            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations'),
            ], 'media-migrations');

            $this->commands([
                MediaCleanCommand::class,
                MediaRegenerateCommand::class,
            ]);
        }
    }
}
