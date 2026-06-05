<?php

namespace Jurager\Media\Converters;

final readonly class ConversionResult
{
    public function __construct(
        /** Absolute path to the output temp file. */
        public string $path,
        /** File extension for the output (e.g. 'webp', 'jpg', 'png'). */
        public string $extension,
        public ?int $width = null,
        public ?int $height = null,
    ) {}
}
