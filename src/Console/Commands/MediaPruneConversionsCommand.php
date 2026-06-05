<?php

namespace Jurager\Media\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Jurager\Media\Console\Commands\Concerns\ResolvesMorphTypes;
use Jurager\Media\Contracts\InteractsWithMedia;
use Jurager\Media\Models\Media;
use Jurager\Media\Models\MediaConversion;
use Jurager\Media\Support\PathGenerator;

class MediaPruneConversionsCommand extends Command
{
    use ResolvesMorphTypes;

    protected $signature = 'media:prune-conversions
                            {--dry-run : List stale conversions without deleting them}
                            {--chunk=100 : Number of media records to process at a time}';

    protected $description = 'Delete conversion files and records that are no longer defined on the model';

    public function handle(PathGenerator $generator): int
    {
        $mediaClass = config('media.models.media', Media::class);
        $mediaConversionClass = config('media.models.media_conversion', MediaConversion::class);
        $dryRun = (bool) $this->option('dry-run');
        $chunk = (int) $this->option('chunk');
        $pruned = 0;

        $mediaClass::query()
            ->with('conversions')
            ->chunkById($chunk, function ($records) use ($mediaConversionClass, $generator, $dryRun, &$pruned): void {
                foreach ($records as $media) {
                    $modelClass = $this->resolveModelClass($media->mediable_type);

                    if (! class_exists($modelClass) || ! is_a($modelClass, InteractsWithMedia::class, true)) {
                        continue;
                    }

                    $instance = new $modelClass;
                    $defined = array_map(
                        static fn ($conversion) => $conversion->name,
                        $instance->getConversionsForCollection($media->collection_name),
                    );

                    $stale = $media->conversions->filter(
                        fn ($conversion) => ! in_array($conversion->name, $defined, true)
                    );

                    foreach ($stale as $conversion) {
                        $basename = pathinfo($media->file_name, PATHINFO_FILENAME);
                        $conversionFile = "{$basename}-{$conversion->name}.{$conversion->extension}";
                        $conversionPath = $generator->getPathForConversions($media).$conversionFile;

                        $this->line(
                            $dryRun
                            ? "  [dry-run] stale: {$media->mediable_type}#{$media->mediable_id} — {$conversion->name}"
                            : "  Pruning: {$media->mediable_type}#{$media->mediable_id} — {$conversion->name}"
                        );

                        if (! $dryRun) {
                            $disk = $conversion->disk ?? $media->conversionsDisk();
                            Storage::disk($disk)->delete($conversionPath);
                            $mediaConversionClass::where('id', $conversion->id)->delete();
                        }

                        $pruned++;
                    }
                }
            });

        $this->info(
            $dryRun
            ? "Dry run: {$pruned} stale conversion(s) would be pruned."
            : "Pruned {$pruned} stale conversion(s)."
        );

        return self::SUCCESS;
    }
}
