<?php

namespace Jurager\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Jurager\Media\Conversions\Conversion;
use Jurager\Media\Enums\ConversionStatus;
use Jurager\Media\Events\MediaConversionGenerated;
use Jurager\Media\Models\Media;
use Jurager\Media\Models\MediaConversion;
use Jurager\Media\Support\ConverterRegistry;
use Jurager\Media\Support\PathGenerator;
use RuntimeException;

class PerformConversionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    /**
     * @param  Conversion[]  $conversions
     */
    public function __construct(
        public readonly Media $media,
        public readonly array $conversions,
    ) {}

    /**
     * Dispatch the given conversions for a media item, honouring each conversion's
     * queue preferences: nonQueued() conversions run synchronously, the rest are
     * grouped by their target queue (onQueue()) into separate jobs.
     *
     * @param  Conversion[]  $conversions
     */
    public static function dispatchFor(Media $media, array $conversions): void
    {
        $sync = array_values(array_filter($conversions, static fn (Conversion $c) => ! $c->isQueued()));
        $async = array_values(array_filter($conversions, static fn (Conversion $c) => $c->isQueued()));

        if ($sync !== []) {
            self::dispatchSync($media, $sync);
        }

        collect($async)
            ->groupBy(static fn (Conversion $c) => $c->getQueue())
            ->each(static function ($group, string $queue) use ($media): void {
                self::dispatch($media, $group->values()->all())->onQueue($queue);
            });
    }

    public function uniqueId(): string
    {
        $names = implode('_', array_map(static fn (Conversion $c) => $c->name, $this->conversions));

        return "media_{$this->media->id}_{$names}";
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    /**
     * Dependencies are injected via handle() so they are not serialized with the job
     * and are always resolved fresh from the container on each execution.
     *
     * @throws \Throwable
     */
    public function handle(ConverterRegistry $registry, PathGenerator $generator): void
    {
        $mediaConversionClass = config('media.models.media_conversion', MediaConversion::class);
        $names = array_map(static fn (Conversion $c) => $c->name, $this->conversions);

        $mediaConversionClass::where('media_id', $this->media->id)
            ->whereIn('name', $names)
            ->whereIn('status', [ConversionStatus::Pending, ConversionStatus::Failed])
            ->update(['status' => ConversionStatus::Processing, 'error_message' => null]);

        $conversionRecords = $mediaConversionClass::where('media_id', $this->media->id)
            ->whereIn('name', $names)
            ->get()
            ->keyBy('name');

        $originalPath = $generator->getPath($this->media).$this->media->file_name;
        $stream = Storage::disk($this->media->disk)->readStream($originalPath);

        if ($stream === null) {
            $mediaConversionClass::where('media_id', $this->media->id)
                ->whereIn('name', $names)
                ->where('status', ConversionStatus::Processing)
                ->update(['status' => ConversionStatus::Failed, 'error_message' => 'Original file not found on disk.']);

            return;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'jurager_media_orig_');

        if ($tmpFile === false) {
            fclose($stream);

            throw new RuntimeException('Unable to create a temporary file for media conversion.');
        }

        try {
            $dest = fopen($tmpFile, 'wb');

            if ($dest === false) {
                throw new RuntimeException("Unable to open temporary file [{$tmpFile}] for writing.");
            }

            stream_copy_to_stream($stream, $dest);
            fclose($dest);
            fclose($stream);

            // Collected so one failing conversion neither aborts its siblings nor leaves
            // them stuck in "processing"; the job is retried only after every conversion
            // in the batch has been attempted.
            $failures = [];

            foreach ($this->conversions as $conversion) {
                $record = $conversionRecords->get($conversion->name);

                // Skip conversions already generated — e.g. on a retry after a sibling failed.
                if ($record?->isDone()) {
                    continue;
                }

                $converter = $registry->resolve($this->media->mime_type);

                if ($converter === null) {
                    // Terminal: a missing converter will not appear on retry, so don't retry.
                    $mediaConversionClass::where('media_id', $this->media->id)
                        ->where('name', $conversion->name)
                        ->update([
                            'status' => ConversionStatus::Failed,
                            'error_message' => "No converter registered for [{$this->media->mime_type}].",
                        ]);

                    continue;
                }

                $conversionTmp = null;

                try {
                    $result = $converter->convert($tmpFile, $conversion, $this->media);
                    $conversionTmp = $result->path;

                    $basename = pathinfo($this->media->file_name, PATHINFO_FILENAME);
                    $conversionFileName = "{$basename}-{$conversion->name}.{$result->extension}";
                    $conversionPath = $generator->getPathForConversions($this->media).$conversionFileName;

                    $content = file_get_contents($conversionTmp);
                    $resultSize = strlen($content);

                    $convDisk = $record?->disk ?? $this->media->conversionsDisk();
                    Storage::disk($convDisk)->put($conversionPath, $content);

                    $properties = array_filter([
                        'width' => $result->width,
                        'height' => $result->height,
                    ]);

                    $this->media->markConversionAsGenerated(
                        $conversion->name,
                        $result->extension,
                        $properties,
                        $resultSize,
                    );

                    event(new MediaConversionGenerated($this->media, $conversion));
                } catch (\Throwable $e) {
                    $mediaConversionClass::where('media_id', $this->media->id)
                        ->where('name', $conversion->name)
                        ->update(['status' => ConversionStatus::Failed, 'error_message' => $e->getMessage()]);

                    $failures[$conversion->name] = $e->getMessage();
                } finally {
                    if ($conversionTmp !== null && is_file($conversionTmp)) {
                        @unlink($conversionTmp);
                    }
                }
            }

            // Surface transient failures so the job retries (with backoff). Already-generated
            // conversions are skipped on the next attempt thanks to the isDone() guard above.
            if ($failures !== []) {
                throw new RuntimeException(
                    'Conversions failed: '.implode('; ', array_map(
                        static fn (string $name, string $message) => "{$name} ({$message})",
                        array_keys($failures),
                        $failures,
                    ))
                );
            }
        } finally {
            @unlink($tmpFile);
        }
    }
}
