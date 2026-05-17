<?php

namespace Jurager\Media\Support;

use Jurager\Media\Contracts\Converter;

class ConverterRegistry
{
    /** @var array<string, class-string<Converter>> */
    private array $converters = [];

    public function register(string $mimePattern, string $converterClass): void
    {
        $this->converters[$mimePattern] = $converterClass;
    }

    /**
     * Resolve a converter for the given MIME type.
     * Exact match takes priority; falls back to wildcard (e.g. 'image/*').
     */
    public function resolve(string $mimeType): ?Converter
    {
        if (isset($this->converters[$mimeType])) {
            return app($this->converters[$mimeType]);
        }

        $prefix   = explode('/', $mimeType, 2)[0];
        $wildcard = $prefix . '/*';

        if (isset($this->converters[$wildcard])) {
            return app($this->converters[$wildcard]);
        }

        return null;
    }
}
