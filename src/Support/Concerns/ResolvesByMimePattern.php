<?php

namespace Jurager\Media\Support\Concerns;

/**
 * Shared MIME-pattern registry: maps MIME types to class names with
 * exact-match priority over wildcard (e.g. 'image/*') fallback.
 */
trait ResolvesByMimePattern
{
    /** @var array<string, class-string> */
    private array $map = [];

    public function register(string $mimePattern, string $class): void
    {
        $this->map[$mimePattern] = $class;
    }

    /**
     * Return the class registered for the MIME type: exact match first,
     * then the type's wildcard (e.g. 'image/*'), or null when nothing matches.
     *
     * @return class-string|null
     */
    protected function match(string $mimeType): ?string
    {
        if (isset($this->map[$mimeType])) {
            return $this->map[$mimeType];
        }

        $wildcard = explode('/', $mimeType, 2)[0].'/*';

        return $this->map[$wildcard] ?? null;
    }
}
