<?php

namespace Jurager\Media;

class MediaCollection
{
    protected bool $singleFile = false;
    protected array $allowedMimeTypes = [];
    protected int $maxFileSizeInBytes = 0;
    protected ?string $disk = null;

    public function __construct(public readonly string $name) {}

    /**
     * Allow only one file in this collection; the previous file is replaced on upload.
     */
    public function singleFile(): static
    {
        $this->singleFile = true;

        return $this;
    }

    /**
     * Restrict uploads to the given MIME types.
     * Example: ['image/jpeg', 'image/png', 'application/pdf']
     */
    public function acceptsMimeTypes(array $mimeTypes): static
    {
        $this->allowedMimeTypes = $mimeTypes;

        return $this;
    }

    /**
     * Maximum allowed file size in bytes. 0 means no limit.
     */
    public function maxFileSize(int $bytes): static
    {
        $this->maxFileSizeInBytes = $bytes;

        return $this;
    }

    /**
     * Store files in this collection on a specific disk instead of the default one.
     * Useful for keeping private documents on a private disk while images stay public.
     *
     * Example: ->disk('s3-private')
     */
    public function disk(string $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    public function isSingleFile(): bool { return $this->singleFile; }

    public function getAllowedMimeTypes(): array { return $this->allowedMimeTypes; }

    public function getMaxFileSize(): int { return $this->maxFileSizeInBytes; }

    public function getDisk(): ?string { return $this->disk; }
}
