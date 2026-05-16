<?php

namespace Jurager\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Jurager\Media\Conversions\Conversion;
use Jurager\Media\Events\MediaConversionGenerated;
use Jurager\Media\Models\Media;
use Jurager\Media\Support\PathGenerator;

class PerformConversionsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Retry up to 3 times on transient S3 / Intervention failures. */
    public int $tries = 3;

    /**
     * @param  Media  $media
     * @param  Conversion[]  $conversions
     */
    public function __construct(
        public readonly Media $media,
        public readonly array $conversions,
    ) {}

    /**
     * Unique key includes conversion names so jobs split across different queues
     * (via ->onQueue()) don't block each other, while identical dispatches deduplicate.
     */
    public function uniqueId(): string
    {
        $names = implode('_', array_map(fn (Conversion $c) => $c->name, $this->conversions));

        return "media_{$this->media->id}_{$names}";
    }

    /** Release the unique lock after 10 minutes regardless of job state. */
    public function uniqueFor(): int
    {
        return 600;
    }

    /** Exponential backoff: 30 s → 60 s → 120 s between retries. */
    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function handle(): void
    {
        if (! $this->media->isImage()) {
            return;
        }

        /** @var PathGenerator $generator */
        $generator = app(config('media.path_generator', PathGenerator::class));

        $originalPath = $generator->getPath($this->media) . $this->media->file_name;
        $fileContent = Storage::disk($this->media->disk)->get($originalPath);

        if ($fileContent === null) {
            return;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'jurager_media_conv_');

        try {
            file_put_contents($tmpFile, $fileContent);

            $manager = $this->buildImageManager();

            foreach ($this->conversions as $conversion) {
                $this->processConversion($manager, $generator, $tmpFile, $conversion);
            }
        } finally {
            @unlink($tmpFile);
        }
    }

    protected function processConversion(
        ImageManager $manager,
        PathGenerator $generator,
        string $tmpFile,
        Conversion $conversion,
    ): void {
        $image = $manager->read($tmpFile);

        $hasWidth = $conversion->getWidth() > 0;
        $hasHeight = $conversion->getHeight() > 0;

        if ($hasWidth || $hasHeight) {
            match ($conversion->getFitMethod()) {
                'cover'   => $image->cover(
                    $conversion->getWidth() ?: $conversion->getHeight(),
                    $conversion->getHeight() ?: $conversion->getWidth(),
                ),
                'contain' => $image->contain(
                    $conversion->getWidth() ?: $conversion->getHeight(),
                    $conversion->getHeight() ?: $conversion->getWidth(),
                ),
                default   => $image->scale(
                    $hasWidth ? $conversion->getWidth() : null,
                    $hasHeight ? $conversion->getHeight() : null,
                ),
            };
        }

        $format = $conversion->getFormat();
        $quality = $conversion->getQuality();

        $encoded = match ($format) {
            'webp' => $image->toWebp($quality),
            'png'  => $image->toPng(),
            'gif'  => $image->toGif(),
            'avif' => $image->toAvif($quality),
            default => $image->toJpeg($quality),
        };

        $ext = $format ?: pathinfo($this->media->file_name, PATHINFO_EXTENSION);
        $basename = pathinfo($this->media->file_name, PATHINFO_FILENAME);
        $conversionFileName = "{$basename}-{$conversion->name}.{$ext}";
        $conversionPath = $generator->getPathForConversions($this->media) . $conversionFileName;

        $conversionsDisk = $this->media->conversions_disk ?? $this->media->disk;
        Storage::disk($conversionsDisk)->put($conversionPath, (string) $encoded);

        $this->media->markConversionAsGenerated($conversion->name, $ext);

        event(new MediaConversionGenerated($this->media, $conversion));
    }

    protected function buildImageManager(): ImageManager
    {
        $driver = config('media.image_driver', 'gd') === 'imagick'
            ? new ImagickDriver
            : new GdDriver;

        return new ImageManager($driver);
    }
}
