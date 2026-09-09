<?php

namespace Jurager\Media\Contracts;

use Jurager\Media\Processors\ProcessResult;

interface FileProcessor
{
    /** Normalize the file and extract metadata (e.g. width/height) — caller unlinks the temp file when result->path differs from $filePath. */
    public function process(string $filePath, string $mimeType): ProcessResult;
}
