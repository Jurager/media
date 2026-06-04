<?php

namespace Jurager\Media\Support;

use Jurager\Media\Contracts\Converter;
use Jurager\Media\Support\Concerns\ResolvesByMimePattern;

class ConverterRegistry
{
    use ResolvesByMimePattern;

    /**
     * Resolve a converter for the given MIME type.
     * Exact match takes priority; falls back to wildcard (e.g. 'image/*').
     */
    public function resolve(string $mimeType): ?Converter
    {
        $class = $this->match($mimeType);

        return $class ? app($class) : null;
    }
}
