<?php

namespace Jurager\Media\Converters;

use Jurager\Media\Contracts\Converter;
use Jurager\Media\Conversions\Conversion;
use Jurager\Media\Models\Media;
use RuntimeException;

/**
 * Converts a PDF page to an image using the Imagick extension (requires Ghostscript).
 *
 * Rasterizes the configured page at the configured DPI, then delegates to ImageConverter
 * for all image transformations (resize, format, quality).
 *
 * Requirements:
 *   - ext-imagick
 *   - Ghostscript (gs) installed on the server
 *
 * Configuration:
 *   media.pdf_converter.resolution  (int, DPI — default 150)
 *   media.pdf_converter.page        (int, 0-indexed page number — default 0)
 */
class PdfConverter implements Converter
{
    public function convert(string $sourcePath, Conversion $conversion, Media $media): ConversionResult
    {
        if (! extension_loaded('imagick')) {
            throw new RuntimeException('PdfConverter requires the Imagick PHP extension.');
        }

        $dpi  = (int) config('media.pdf_converter.resolution', 150);
        $page = (int) config('media.pdf_converter.page', 0);

        $rasterized = $this->rasterize($sourcePath, $dpi, $page);

        try {
            return (new ImageConverter)->convert($rasterized, $conversion, $media);
        } finally {
            @unlink($rasterized);
        }
    }

    protected function rasterize(string $sourcePath, int $dpi, int $page): string
    {
        $imagick = new \Imagick;
        $imagick->setResolution($dpi, $dpi);
        $imagick->readImage($sourcePath . "[{$page}]");
        $imagick->setImageColorspace(\Imagick::COLORSPACE_SRGB);
        $imagick->setImageBackgroundColor('white');
        $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_FLATTEN);
        $imagick->setImageFormat('png');

        $tmpFile = tempnam(sys_get_temp_dir(), 'jurager_conv_pdf_');
        $imagick->writeImage($tmpFile);
        $imagick->clear();
        $imagick->destroy();

        return $tmpFile;
    }
}
