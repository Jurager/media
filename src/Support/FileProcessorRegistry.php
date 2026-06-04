<?php

namespace Jurager\Media\Support;

use Jurager\Media\Contracts\FileProcessor;
use Jurager\Media\Processors\PassthroughProcessor;
use Jurager\Media\Support\Concerns\ResolvesByMimePattern;

class FileProcessorRegistry
{
    use ResolvesByMimePattern;

    /**
     * Resolve a processor for the given MIME type.
     * Exact match takes priority over wildcards (e.g. 'image/*').
     * Falls back to PassthroughProcessor when no match is found.
     */
    public function resolve(string $mimeType): FileProcessor
    {
        return app($this->match($mimeType) ?? PassthroughProcessor::class);
    }
}
