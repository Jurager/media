<?php

namespace Jurager\Media\Converters;

use Imagick;
use ImagickException;
use Jurager\Media\Contracts\Converter;
use Jurager\Media\Conversions\Conversion;
use Jurager\Media\Models\Media;
use Jurager\Media\Support\ConverterRegistry;
use RuntimeException;

/**
 * Converts a PDF page to an image using the Imagick extension (requires Ghostscript).
 *
 * Rasterize the configured page at the configured DPI, then delegates to the image
 * converter registered for image/* in media.converters for all image transformations
 * (resize, format, quality) — so a custom image converter is honoured here too.
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
    public function __construct(private readonly ConverterRegistry $converters) {}

    /**
     * @throws ImagickException
     */
    public function convert(string $sourcePath, Conversion $conversion, Media $media): ConversionResult
    {
        if (! extension_loaded('imagick')) {
            throw new RuntimeException('PdfConverter requires the Imagick PHP extension.');
        }

        // Rasterize the PDF page to a PNG, then run it through the configured image converter.
        $rasterized = $this->rasterize(
            $sourcePath,
            (int) config('media.pdf_converter.resolution', 150),
            (int) config('media.pdf_converter.page', 0),
        );

        try {
            return $this->imageConverter()->convert($rasterized, $conversion, $media);
        } finally {
            @unlink($rasterized);
        }
    }

    /**
     * The converter that handles the rasterized PNG — honours a custom image/* converter
     * registered in media.converters.
     */
    private function imageConverter(): Converter
    {
        return $this->converters->resolve('image/png')
            ?? throw new RuntimeException('PdfConverter needs an image converter registered for [image/png].');
    }

    /**
     * @throws ImagickException
     */
    protected function rasterize(string $sourcePath, int $dpi, int $page): string
    {
        $imagick = new Imagick;
        $imagick->setResolution($dpi, $dpi);
        $imagick->readImage($sourcePath."[{$page}]");
        $imagick->setImageColorspace(Imagick::COLORSPACE_SRGB);
        $imagick->setImageBackgroundColor('white');
        $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        $imagick->setImageFormat('png');

        $tmpFile = tempnam(sys_get_temp_dir(), 'converted_pdf_');

        $imagick->writeImage($tmpFile);
        $imagick->clear();

        return $tmpFile;
    }
}
