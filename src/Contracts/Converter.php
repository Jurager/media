<?php

namespace Jurager\Media\Contracts;

use Jurager\Media\Converters\ConversionResult;
use Jurager\Media\Conversions\Conversion;
use Jurager\Media\Models\Media;

interface Converter
{
    /**
     * Convert the source file according to the conversion definition.
     *
     * @param  string  $sourcePath  Local path to the original file
     * @return ConversionResult     Path to the output temp file (caller is responsible for cleanup)
     */
    public function convert(string $sourcePath, Conversion $conversion, Media $media): ConversionResult;
}
