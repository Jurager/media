<?php

namespace Jurager\Media\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Jurager\Media\Events\MediaAdded;
use Jurager\Media\Events\MediaDeleted;
use Jurager\Media\Support\PathGenerator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Media extends Model
{
    protected $fillable = [
        'mediable_type',
        'mediable_id',
        'uuid',
        'collection_name',
        'name',
        'file_name',
        'mime_type',
        'disk',
        'conversions_disk',
        'size',
        'hash',
        'order_column',
        'custom_properties',
        'generated_conversions',
        'manipulations',
    ];

    protected $casts = [
        'custom_properties' => 'array',
        'generated_conversions' => 'array',
        'manipulations' => 'array',
        'size' => 'integer',
        'order_column' => 'integer',
    ];

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    // ─── URLs ────────────────────────────────────────────────────────────────

    /**
     * Public URL for the original or a named conversion.
     * Falls back to the original when the conversion has not been generated yet.
     */
    public function getUrl(string $conversion = ''): string
    {
        if ($conversion && ! $this->hasGeneratedConversion($conversion)) {
            return $this->buildUrl($this->disk, $this->getPath());
        }

        $disk = $conversion ? ($this->conversions_disk ?? $this->disk) : $this->disk;

        return $this->buildUrl($disk, $this->getPath($conversion));
    }

    /**
     * Presigned S3 URL for private files. Only works with S3-compatible disks.
     */
    public function getTemporaryUrl(DateTimeInterface $expiration, array $options = []): string
    {
        return Storage::disk($this->disk)->temporaryUrl(
            $this->getPath(),
            $expiration,
            $options,
        );
    }

    /**
     * Presigned URL for a specific conversion of a private file.
     */
    public function getTemporaryConversionUrl(
        string $conversion,
        DateTimeInterface $expiration,
        array $options = [],
    ): string {
        $disk = $this->conversions_disk ?? $this->disk;

        return Storage::disk($disk)->temporaryUrl(
            $this->getPath($conversion),
            $expiration,
            $options,
        );
    }

    // ─── Response helpers ────────────────────────────────────────────────────

    /**
     * Stream the file inline — suitable for PDF preview in browser.
     */
    public function stream(): StreamedResponse
    {
        return Storage::disk($this->disk)->response($this->getPath());
    }

    /**
     * Force-download the file with the correct Content-Disposition header.
     */
    public function download(?string $downloadName = null): StreamedResponse
    {
        return Storage::disk($this->disk)->download(
            $this->getPath(),
            $downloadName ?? $this->file_name,
        );
    }

    // ─── Paths ───────────────────────────────────────────────────────────────

    public function getPath(string $conversion = ''): string
    {
        /** @var PathGenerator $generator */
        $generator = app(config('media.path_generator', PathGenerator::class));

        if ($conversion) {
            return $generator->getPathForConversions($this) . $this->getConversionFileName($conversion);
        }

        return $generator->getPath($this) . $this->file_name;
    }

    public function getConversionFileName(string $conversion): string
    {
        $basename = pathinfo($this->file_name, PATHINFO_FILENAME);
        $ext = $this->generated_conversions[$conversion] ?? pathinfo($this->file_name, PATHINFO_EXTENSION);

        return "{$basename}-{$conversion}.{$ext}";
    }

    // ─── Conversions ─────────────────────────────────────────────────────────

    public function hasGeneratedConversion(string $name): bool
    {
        return isset($this->generated_conversions[$name]);
    }

    public function markConversionAsGenerated(string $name, string $ext): void
    {
        $conversions = $this->generated_conversions ?? [];
        $conversions[$name] = $ext;
        $this->generated_conversions = $conversions;
        $this->saveQuietly();
    }

    // ─── Type checks ─────────────────────────────────────────────────────────

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }

    // ─── Dimensions ──────────────────────────────────────────────────────────

    public function getWidth(): ?int
    {
        return $this->getCustomProperty('width');
    }

    public function getHeight(): ?int
    {
        return $this->getCustomProperty('height');
    }

    // ─── Custom properties ───────────────────────────────────────────────────

    public function getCustomProperty(string $key, mixed $default = null): mixed
    {
        return ($this->custom_properties ?? [])[$key] ?? $default;
    }

    public function setCustomProperty(string $key, mixed $value): static
    {
        $properties = $this->custom_properties ?? [];
        $properties[$key] = $value;
        $this->custom_properties = $properties;

        return $this;
    }

    // ─── Conversion status ───────────────────────────────────────────────────

    /**
     * Return the names of conversions that are registered on the mediable model
     * but have not been generated yet.
     *
     * Requires the mediable relation to be loaded (or will trigger a query).
     *
     * @return string[]
     */
    public function pendingConversions(): array
    {
        $mediable = $this->mediable;

        if (! $mediable || ! method_exists($mediable, 'getRegisteredMediaConversions')) {
            return [];
        }

        return collect($mediable->getRegisteredMediaConversions())
            ->filter(fn ($c) =>
                $c->shouldBePerformedOn($this->collection_name) &&
                ! $this->hasGeneratedConversion($c->name)
            )
            ->map(fn ($c) => $c->name)
            ->values()
            ->all();
    }

    /**
     * Determine whether a specific conversion is still waiting to be generated.
     */
    public function isConversionPending(string $name): bool
    {
        return in_array($name, $this->pendingConversions(), true);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function humanReadableSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = $this->size;
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Fake the media disk(s) for testing.
     * Pass additional disk names for per-collection disks defined with ->disk().
     *
     *   Media::fake();
     *   Media::fake(['s3-private']);
     *   $product->addMedia(UploadedFile::fake()->image('test.jpg'))->toMediaCollection('gallery');
     */
    public static function fake(array $additionalDisks = []): void
    {
        $disks = array_values(array_unique(array_filter([
            config('media.disk', 's3'),
            config('media.conversions_disk'),
            ...$additionalDisks,
        ])));

        foreach ($disks as $disk) {
            Storage::fake($disk);
        }
    }

    // ─── Internal ────────────────────────────────────────────────────────────

    protected function buildUrl(string $disk, string $path): string
    {
        $cdnUrl = config('media.cdn_url');

        if ($cdnUrl) {
            return rtrim($cdnUrl, '/') . '/' . ltrim($path, '/');
        }

        return Storage::disk($disk)->url($path);
    }

    protected static function booted(): void
    {
        static::created(fn (self $media) => event(new MediaAdded($media)));

        static::deleted(fn (self $media) => event(new MediaDeleted($media)));

        static::deleting(function (self $media): void {
            /** @var PathGenerator $generator */
            $generator = app(config('media.path_generator', PathGenerator::class));

            Storage::disk($media->disk)->delete(
                $generator->getPath($media) . $media->file_name
            );

            $conversionsDisk = $media->conversions_disk ?? $media->disk;
            Storage::disk($conversionsDisk)->deleteDirectory(
                $generator->getPathForConversions($media)
            );
        });
    }
}
