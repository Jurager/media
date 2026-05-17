<?php

namespace Jurager\Media\Support;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;

class ImageProcessor
{
    /**
     * Process an image file: extract dimensions and optionally strip EXIF.
     *
     * Returns [string $uploadPath, array $properties] where $properties contains
     * system metadata (width, height) to be stored in the media.properties column.
     * $uploadPath may be a new temp file when EXIF was stripped — callers are
     * responsible for unlinking it if it differs from $filePath.
     *
     * For non-image files returns [$filePath, []] unchanged.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function process(string $filePath): array
    {
        if (! $this->isImagePath($filePath)) {
            return [$filePath, []];
        }

        $properties = $this->extractProperties($filePath);

        if (config('media.strip_exif', true)) {
            $stripped = $this->stripExif($filePath);

            return [$stripped, $properties];
        }

        return [$filePath, $properties];
    }

    protected function extractProperties(string $filePath): array
    {
        $size = @getimagesize($filePath);

        if ($size === false) {
            return [];
        }

        return [
            'width'  => $size[0],
            'height' => $size[1],
        ];
    }

    protected function stripExif(string $filePath): string
    {
        $manager = $this->buildImageManager();
        $image = $manager->read($filePath);

        $mime = mime_content_type($filePath) ?: 'image/jpeg';

        $encoded = match (true) {
            str_contains($mime, 'png')  => $image->toPng(),
            str_contains($mime, 'gif')  => $image->toGif(),
            str_contains($mime, 'webp') => $image->toWebp(90),
            str_contains($mime, 'avif') => $image->toAvif(90),
            default                     => $image->toJpeg(95),
        };

        $tmpFile = tempnam(sys_get_temp_dir(), 'jurager_media_exif_');
        file_put_contents($tmpFile, (string) $encoded);

        return $tmpFile;
    }

    protected function isImagePath(string $filePath): bool
    {
        return str_starts_with(mime_content_type($filePath) ?: '', 'image/');
    }

    protected function buildImageManager(): ImageManager
    {
        $driver = config('media.image_driver', 'gd') === 'imagick'
            ? new ImagickDriver
            : new GdDriver;

        return new ImageManager($driver);
    }
}
