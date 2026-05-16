<?php

namespace Jurager\Media\Support;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;

class ImageProcessor
{
    /**
     * Process an image file: extract dimensions, optionally strip EXIF.
     *
     * Returns the path to upload (may be a new temp file when EXIF was stripped).
     * Callers are responsible for unlinking any temp file that was created
     * (check whether the returned path differs from $filePath).
     *
     * @param  array<string, mixed>  $customProperties  Modified in-place to add width/height.
     */
    public function process(string $filePath, array &$customProperties): string
    {
        if (! $this->isImagePath($filePath)) {
            return $filePath;
        }

        $this->extractDimensions($filePath, $customProperties);

        if (config('media.strip_exif', true)) {
            return $this->stripExif($filePath);
        }

        return $filePath;
    }

    protected function extractDimensions(string $filePath, array &$customProperties): void
    {
        $size = @getimagesize($filePath);

        if ($size !== false) {
            $customProperties['width'] = $size[0];
            $customProperties['height'] = $size[1];
        }
    }

    /**
     * Re-encode the image through Intervention so EXIF is discarded.
     * Returns a new temp file path.
     */
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
