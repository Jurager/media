<?php

namespace Jurager\Media\Contracts;

use Jurager\Media\Conversions\Conversion;
use Jurager\Media\Converters\ConversionResult;
use Jurager\Media\Models\Media;

interface Converter
{
    /** Convert the source file per the conversion definition — returns the output temp file's path; caller is responsible for cleanup. */
    public function convert(string $sourcePath, Conversion $conversion, Media $media): ConversionResult;
}
