<?php

namespace Jurager\Media\Models;

use DateTimeInterface;
use Illuminate\Contracts\Mail\Attachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Facades\Storage;
use Jurager\Media\Events\MediaAdded;
use Jurager\Media\Events\MediaDeleted;
use Jurager\Media\Support\PathGenerator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Media extends Model implements Attachable
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
        'size',
        'hash',
        'order_column',
        'properties',
    ];

    protected $casts = [
        'properties' => 'array',
        'size' => 'integer',
        'order_column' => 'integer',
    ];

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(
            config('media.models.media_conversion', MediaConversion::class),
            'media_id',
        );
    }

    /**
     * Disk where this media's conversions are stored, absent a per-conversion override.
     * Mirrors the fallback used when conversion records are created.
     */
    public function conversionsDisk(): string
    {
        return config('media.conversions_disk') ?? $this->disk;
    }

    /**
     * Set order_column to the next position within this media's collection.
     *
     * Must run inside a database transaction: the row lock is only meaningful
     * while the surrounding transaction (which also inserts this record) is open,
     * which is what makes concurrent uploads race-free.
     */
    public function assignNextOrderColumn(): void
    {
        $max = static::query()
            ->where('mediable_type', $this->mediable_type)
            ->where('mediable_id', $this->mediable_id)
            ->where('collection_name', $this->collection_name)
            ->lockForUpdate()
            ->max('order_column');

        $this->order_column = (int) $max + 1;
    }

    /**
     * Public URL for the original or a named conversion.
     * Falls back to the original when the conversion has not been generated yet.
     */
    public function getUrl(string $conversion = ''): string
    {
        if (! $conversion) {
            return $this->buildUrl($this->disk, $this->getPath());
        }

        $conv = $this->getConversionRecord($conversion);

        if (! $conv || ! $conv->isDone()) {
            return $this->buildUrl($this->disk, $this->getPath());
        }

        return $this->buildUrl($conv->disk, $this->getPath($conversion));
    }

    /**
     * Presigned S3 URL for private files.
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
        $conv = $this->getConversionRecord($conversion);
        $disk = $conv?->disk ?? $this->conversionsDisk();

        return Storage::disk($disk)->temporaryUrl(
            $this->getPath($conversion),
            $expiration,
            $options,
        );
    }

    public function toMailAttachment(): Attachment
    {
        return Attachment::fromStorageDisk($this->disk, $this->getPath())
            ->as($this->file_name)
            ->withMime($this->mime_type ?? 'application/octet-stream');
    }

    public function stream(): StreamedResponse
    {
        return Storage::disk($this->disk)->response($this->getPath());
    }

    public function download(?string $downloadName = null): StreamedResponse
    {
        return Storage::disk($this->disk)->download(
            $this->getPath(),
            $downloadName ?? $this->file_name,
        );
    }

    public function getPath(string $conversion = ''): string
    {
        /** @var PathGenerator $generator */
        $generator = app(PathGenerator::class);

        if ($conversion) {
            return $generator->getPathForConversions($this).$this->getConversionFileName($conversion);
        }

        return $generator->getPath($this).$this->file_name;
    }

    public function getConversionFileName(string $conversion): string
    {
        $basename = pathinfo($this->file_name, PATHINFO_FILENAME);
        $ext = $this->getConversionRecord($conversion)?->extension
            ?? pathinfo($this->file_name, PATHINFO_EXTENSION);

        return "{$basename}-{$conversion}.{$ext}";
    }

    public function hasGeneratedConversion(string $name): bool
    {
        return (bool) $this->getConversionRecord($name)?->isDone();
    }

    public function markConversionAsGenerated(string $name, string $ext, array $properties = [], int $size = 0): void
    {
        $data = [
            'status' => 'done',
            'extension' => $ext,
            'completed_at' => now(),
        ];

        if (! empty($properties)) {
            $data['properties'] = $properties;
        }

        if ($size > 0) {
            $data['size'] = $size;
        }

        $this->conversions()->where('name', $name)->update($data);

        $this->unsetRelation('conversions');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }

    public function getProperty(string $key, mixed $default = null): mixed
    {
        return ($this->properties ?? [])[$key] ?? $default;
    }

    /**
     * Original image width in pixels. Null for non-image files.
     */
    public function getWidth(): ?int
    {
        return $this->getProperty('width');
    }

    /**
     * Original image height in pixels.
     */
    public function getHeight(): ?int
    {
        return $this->getProperty('height');
    }

    /**
     * Names of conversions with status = pending.
     *
     * @return string[]
     */
    public function pendingConversions(): array
    {
        return $this->conversionNamesWithStatus('pending');
    }

    /**
     * Names of conversions with status = failed.
     *
     * @return string[]
     */
    public function failedConversions(): array
    {
        return $this->conversionNamesWithStatus('failed');
    }

    /**
     * Conversion names filtered by status, reusing the loaded relation when present.
     *
     * @return string[]
     */
    protected function conversionNamesWithStatus(string $status): array
    {
        if ($this->relationLoaded('conversions')) {
            return $this->conversions->where('status', $status)->pluck('name')->all();
        }

        return $this->conversions()->where('status', $status)->pluck('name')->all();
    }

    public function isConversionPending(string $name): bool
    {
        return in_array($name, $this->pendingConversions(), true);
    }

    public function humanReadableSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = $this->size;
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * Fake the media disk(s) for testing.
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

    protected function getConversionRecord(string $name): ?MediaConversion
    {
        if ($this->relationLoaded('conversions')) {
            return $this->conversions->firstWhere('name', $name);
        }

        return $this->conversions()->where('name', $name)->first();
    }

    protected function buildUrl(string $disk, string $path): string
    {
        $cdnUrl = config('media.cdn_url');

        if ($cdnUrl) {
            return rtrim($cdnUrl, '/').'/'.ltrim($path, '/');
        }

        return Storage::disk($disk)->url($path);
    }

    protected static function booted(): void
    {
        static::created(static fn (self $media) => event(new MediaAdded($media)));

        static::deleted(static fn (self $media) => event(new MediaDeleted($media)));

        static::deleting(static function (self $media): void {
            /** @var PathGenerator $generator */
            $generator = app(PathGenerator::class);

            $media->load('conversions');

            // Each media owns an isolated directory, so removing it deletes the original
            // together with every conversion stored on the same disk in a single call.
            Storage::disk($media->disk)->deleteDirectory(rtrim($generator->getPath($media), '/'));

            // Conversions kept on a different disk live outside that directory — clear them too.
            $conversionsPath = $generator->getPathForConversions($media);

            $otherDisks = $media->conversions
                ->pluck('disk')
                ->filter()
                ->unique()
                ->reject(static fn (string $disk) => $disk === $media->disk);

            foreach ($otherDisks as $disk) {
                Storage::disk($disk)->deleteDirectory($conversionsPath);
            }
            // media_conversions rows are deleted by FK cascade
        });
    }
}
