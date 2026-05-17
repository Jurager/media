<?php

namespace Jurager\Media\Converters;

class ConversionResult
{
    public function __construct(
        /** Absolute path to the output temp file. */
        public readonly string $path,
        /** File extension for the output (e.g. 'webp', 'jpg', 'png'). */
        public readonly string $extension,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
    ) {}
}
