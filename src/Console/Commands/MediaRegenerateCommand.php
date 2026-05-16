<?php

namespace Jurager\Media\Console\Commands;

use Illuminate\Console\Command;
use Jurager\Media\Jobs\PerformConversionsJob;
use Jurager\Media\Models\Media;

class MediaRegenerateCommand extends Command
{
    protected $signature = 'media:regenerate
                            {model? : Fully qualified model class (e.g. "App\\Models\\Product")}
                            {--collection= : Limit regeneration to a specific collection name}
                            {--all : Regenerate for all mediable types found in the media table}
                            {--sync : Run conversions synchronously instead of dispatching to queue}
                            {--chunk=50 : Number of media records to process at a time}';

    protected $description = 'Regenerate image conversions for existing media records';

    public function handle(): int
    {
        $mediaClass = config('media.models.media', Media::class);
        $modelClass = $this->argument('model');
        $collection = $this->option('collection');
        $all = (bool) $this->option('all');
        $sync = (bool) $this->option('sync');
        $chunk = (int) $this->option('chunk');

        if (! $modelClass && ! $all) {
            $this->error('Provide a model class or pass --all.');

            return self::FAILURE;
        }

        $query = $mediaClass::query()->where(function ($q) {
            $q->whereRaw("mime_type LIKE 'image/%'");
        });

        if ($modelClass) {
            $query->where('mediable_type', (new $modelClass)->getMorphClass());
        }

        if ($collection) {
            $query->where('collection_name', $collection);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No media records found matching the criteria.');

            return self::SUCCESS;
        }

        $this->info("Regenerating conversions for {$total} media record(s)…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;

        $query->chunkById($chunk, function ($records) use ($sync, &$processed, $bar) {
            foreach ($records as $media) {
                $conversions = $this->getConversionsFor($media);

                if (empty($conversions)) {
                    $bar->advance();
                    continue;
                }

                // Reset generated_conversions so getUrl() falls back to original while re-generating
                $media->generated_conversions = [];
                $media->saveQuietly();

                if ($sync) {
                    PerformConversionsJob::dispatchSync($media, $conversions);
                } else {
                    PerformConversionsJob::dispatch($media, $conversions)
                        ->onQueue(config('media.queue', 'default'));
                }

                $processed++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        $verb = $sync ? 'Processed' : 'Dispatched jobs for';
        $this->info("{$verb} {$processed} media record(s).");

        return self::SUCCESS;
    }

    /**
     * @return \Jurager\Media\Conversions\Conversion[]
     */
    protected function getConversionsFor(Media $media): array
    {
        $mediable = $media->mediable;

        if (! $mediable || ! method_exists($mediable, 'getRegisteredMediaConversions')) {
            return [];
        }

        return array_filter(
            $mediable->getRegisteredMediaConversions(),
            fn ($c) => $c->shouldBePerformedOn($media->collection_name),
        );
    }
}
