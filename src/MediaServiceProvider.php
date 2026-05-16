<?php

namespace Jurager\Media;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Jurager\Media\Console\Commands\MediaCleanCommand;
use Jurager\Media\Console\Commands\MediaRegenerateCommand;
use Jurager\Media\Models\Media;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/media.php', 'media');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Route::model('media', config('media.models.media', Media::class));

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
