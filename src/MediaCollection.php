<?php /** @noinspection PhpUnnecessaryCurlyVarSyntaxInspection */

namespace Jurager\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;

class MediaCollection
{
    use Macroable;

    protected bool $singleFile = false;
    protected int $collectionSizeLimit = 0;
    protected array $allowedMimeTypes = [];
    protected int $maxFileSizeInBytes = 0;
    protected ?string $disk = null;
    protected ?string $conversionsDisk = null;
    protected bool $performConversions = true;
    protected array $fallbackUrls = [];
    protected array $fallbackPaths = [];
    /** @var callable|null */
    protected $fileAcceptor = null;

    /** @var callable[] */
    protected array $conversionCallbacks = [];

    public function __construct(public readonly string $name) {}

    /**
     * Allow only one file; any previous file is deleted before the new one is stored.
     */
    public function singleFile(): static
    {
        return $this->onlyKeepLatest(1);
    }

    /**
     * Keep only the N most recently uploaded files in this collection.
     * Older files are deleted after each upload. singleFile() is onlyKeepLatest(1).
     */
    public function onlyKeepLatest(int $n): static
    {
        if ($n < 1) {
            throw new InvalidArgumentException(
                "onlyKeepLatest() requires a value of at least 1, [{$n}] given."
            );
        }

        $this->singleFile = ($n === 1);
        $this->collectionSizeLimit = $n;

        return $this;
    }

    /**
     * Restrict uploads to the given MIME types.
     */
    public function acceptsMimeTypes(array $mimeTypes): static
    {
        $this->allowedMimeTypes = $mimeTypes;

        return $this;
    }

    /**
     * Custom callable for arbitrary file acceptance logic.
     * Receives (UploadedFile $file, MediaCollection $collection): bool.
     * Called in addition to acceptsMimeTypes() and maxFileSize() checks.
     */
    public function acceptsFile(callable $fn): static
    {
        $this->fileAcceptor = $fn;

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
     * Store originals in this collection on a specific disk.
     */
    public function disk(string $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * Store generated conversions on a separate disk.
     * Falls back to the global config('media.conversions_disk') when not set.
     */
    public function storeConversionsOnDisk(string $disk): static
    {
        $this->conversionsDisk = $disk;

        return $this;
    }

    /**
     * Register conversions that apply only to this collection.
     * The callback receives a Media instance and calls $this->addMediaConversion() normally.
     * Multiple calls are additive — all callbacks are executed in order.
     *
     * When a collection has at least one withConversions() callback, those take priority
     * and registerMediaConversions() + performOnCollections() are not consulted for it.
     *
     * Example:
     *   ->withConversions(function (Media $media): void {
     *       $this->addMediaConversion('thumb')->fit(200, 200)->format('webp');
     *   })
     */
    public function withConversions(callable $callback): static
    {
        $this->conversionCallbacks[] = $callback;

        return $this;
    }

    /**
     * Disable automatic conversion dispatch for this collection.
     */
    public function withoutConversions(): static
    {
        $this->performConversions = false;

        return $this;
    }

    /**
     * URL to return from getFirstMediaUrl() when the collection is empty.
     * Pass a conversion name for conversion-specific fallbacks.
     *
     * Example:
     *   ->useFallbackUrl('/img/placeholder.png')
     *   ->useFallbackUrl('/img/placeholder-thumb.png', 'thumb')
     */
    public function useFallbackUrl(string $url, string $conversion = ''): static
    {
        $this->fallbackUrls[$conversion ?: 'default'] = $url;

        return $this;
    }

    /**
     * Filesystem path to return when the collection is empty.
     */
    public function useFallbackPath(string $path, string $conversion = ''): static
    {
        $this->fallbackPaths[$conversion ?: 'default'] = $path;

        return $this;
    }

    // â”€â”€â”€ Getters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function isSingleFile(): bool { return $this->singleFile; }

    public function getCollectionSizeLimit(): int { return $this->collectionSizeLimit; }

    public function getAllowedMimeTypes(): array { return $this->allowedMimeTypes; }

    public function getMaxFileSize(): int { return $this->maxFileSizeInBytes; }

    public function getDisk(): ?string { return $this->disk; }

    public function getConversionsDisk(): ?string { return $this->conversionsDisk; }

    public function shouldPerformConversions(): bool { return $this->performConversions; }

    public function getFileAcceptor(): ?callable { return $this->fileAcceptor; }

    public function getFallbackUrl(string $conversion = ''): ?string
    {
        return $this->fallbackUrls[$conversion] ?? $this->fallbackUrls['default'] ?? null;
    }

    public function getFallbackPath(string $conversion = ''): ?string
    {
        return $this->fallbackPaths[$conversion] ?? $this->fallbackPaths['default'] ?? null;
    }

    /** @return callable[] */
    public function getConversionCallbacks(): array
    {
        return $this->conversionCallbacks;
    }
}
