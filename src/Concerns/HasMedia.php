<?php

namespace Jurager\Media\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Jurager\Media\Conversions\Conversion;
use Jurager\Media\MediaCollection;
use Jurager\Media\Models\Media;
use Jurager\Media\Support\FileAdder;
use Jurager\Media\Support\PathGenerator;

trait HasMedia
{
    /** @var Conversion[] */
    protected array $mediaConversions = [];

    /** @var array<string, MediaCollection> */
    protected array $mediaCollections = [];

    protected bool $mediaCollectionsRegistered = false;

    /**
     * When true, all media is deleted automatically when the model is deleted.
     * Set to false if you need custom cleanup logic.
     */
    protected bool $deleteMediaOnDelete = true;

    /**
     * Automatically clean up media when the model is deleted.
     * Called by Laravel during model boot via the boot{TraitName}() convention.
     */
    public static function bootHasMedia(): void
    {
        static::deleting(function (self $model): void {
            if (! $model->deleteMediaOnDelete) {
                return;
            }

            // For models using SoftDeletes, only clean up on permanent (force) deletion.
            // Soft-deleting a product should not remove its images.
            if (in_array(SoftDeletes::class, class_uses_recursive($model), true) && ! $model->isForceDeleting()) {
                return;
            }

            $model->media()
                ->chunkById(100, fn (Collection $chunk) => $chunk->each->delete());
        });
    }

    public function media(): MorphMany
    {
        $mediaClass = config('media.models.media', Media::class);

        return $this->morphMany($mediaClass, 'mediable')->orderBy('order_column');
    }

    // ─── Uploading ───────────────────────────────────────────────────────────

    public function addMedia(UploadedFile|string $file): FileAdder
    {
        return (new FileAdder($this))->setFile($file);
    }

    /**
     * Download a file from a remote URL and attach it as media.
     * Uses Guzzle streaming (sink) — the file is never fully loaded into memory.
     *
     * @param  array<string, string>  $headers
     */
    public function addMediaFromUrl(string $url, array $headers = []): FileAdder
    {
        return (new FileAdder($this))->setFileFromUrl($url, $headers);
    }

    /**
     * Decode a base64-encoded string and attach it as media.
     * Accepts both raw base64 and data URIs (data:image/jpeg;base64,...).
     */
    public function addMediaFromBase64(string $base64, string $mimeType = ''): FileAdder
    {
        return (new FileAdder($this))->setFileFromBase64($base64, $mimeType);
    }

    // ─── Copying ─────────────────────────────────────────────────────────────

    /**
     * Copy media from another model using S3 server-side copy.
     * No files are downloaded or re-uploaded — AWS copies them within the same region instantly.
     * Each copy is an independent object; deleting one does not affect the other.
     *
     * @param  string|string[]|null  $collections  Collection name(s) to copy; null copies all.
     */
    public function copyMediaFrom(object $source, string|array|null $collections = null): void
    {
        if (! method_exists($source, 'media')) {
            throw new InvalidArgumentException('Source model must use the HasMedia trait.');
        }

        $query = $source->media();

        if ($collections !== null) {
            $query->whereIn('collection_name', (array) $collections);
        }

        $query->get()->each(fn (Media $media) => $this->copyMediaRecord($media));
    }

    protected function copyMediaRecord(Media $original): Media
    {
        /** @var PathGenerator $generator */
        $generator = app(config('media.path_generator', PathGenerator::class));

        $copy = $original->replicate();
        $copy->uuid = (string) Str::uuid();
        $copy->mediable_type = $this->getMorphClass();
        $copy->mediable_id = $this->getKey();
        $copy->order_column = $this->nextOrderColumnFor($original->collection_name);
        $copy->generated_conversions = [];
        $copy->save();

        // S3 server-side copy: no bandwidth, no download, instant within AWS
        Storage::disk($original->disk)->copy(
            $generator->getPath($original) . $original->file_name,
            $generator->getPath($copy) . $copy->file_name,
        );

        // Copy any already-generated conversions the same way
        $generatedConversions = [];
        $convDisk = $original->conversions_disk ?? $original->disk;

        foreach ($original->generated_conversions ?? [] as $name => $ext) {
            $conversionFileName = $original->getConversionFileName($name);

            Storage::disk($convDisk)->copy(
                $generator->getPathForConversions($original) . $conversionFileName,
                $generator->getPathForConversions($copy) . $conversionFileName,
            );

            $generatedConversions[$name] = $ext;
        }

        $copy->generated_conversions = $generatedConversions;
        $copy->saveQuietly();

        $this->unsetRelation('media');

        return $copy;
    }

    protected function nextOrderColumnFor(string $collection): int
    {
        $mediaClass = config('media.models.media', Media::class);

        return DB::transaction(function () use ($mediaClass, $collection): int {
            $max = $mediaClass::query()
                ->where('mediable_type', $this->getMorphClass())
                ->where('mediable_id', $this->getKey())
                ->where('collection_name', $collection)
                ->lockForUpdate()
                ->max('order_column');

            return ($max ?? 0) + 1;
        });
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    /**
     * Eager-load the media relation to avoid N+1 queries when iterating models.
     *
     * When $collections is given, only those collections are loaded.
     * Accessing other collections afterwards will trigger individual queries.
     *
     * @param  string|string[]|null  $collections
     */
    public function scopeWithMedia(Builder $query, string|array|null $collections = null): Builder
    {
        if ($collections === null) {
            return $query->with('media');
        }

        return $query->with(['media' => fn ($q) => $q->whereIn('collection_name', (array) $collections)]);
    }

    // ─── Retrieval ───────────────────────────────────────────────────────────

    public function getMedia(string $collection = 'default'): Collection
    {
        if (! $this->relationLoaded('media')) {
            $this->load('media');
        }

        return $this->media->where('collection_name', $collection)->values();
    }

    public function getFirstMedia(string $collection = 'default'): ?Media
    {
        return $this->getMedia($collection)->first();
    }

    /**
     * Return the URL for the first item in a collection.
     * Falls back to the original when the conversion is still pending.
     */
    public function getFirstMediaUrl(string $collection = 'default', string $conversion = ''): string
    {
        return $this->getFirstMedia($collection)?->getUrl($conversion) ?? '';
    }

    public function hasMedia(string $collection = 'default'): bool
    {
        return $this->getMedia($collection)->isNotEmpty();
    }

    // ─── Ordering ────────────────────────────────────────────────────────────

    /**
     * Reorder media within a collection by providing Media IDs in the desired order.
     *
     * @param  array<int>  $orderedIds
     */
    public function reorderMedia(string $collection, array $orderedIds): void
    {
        $mediaClass = config('media.models.media', Media::class);

        DB::transaction(function () use ($mediaClass, $collection, $orderedIds): void {
            foreach ($orderedIds as $position => $id) {
                $mediaClass::query()
                    ->where('id', $id)
                    ->where('mediable_type', $this->getMorphClass())
                    ->where('mediable_id', $this->getKey())
                    ->where('collection_name', $collection)
                    ->update(['order_column' => $position + 1]);
            }
        });

        $this->unsetRelation('media');
    }

    // ─── Cleanup ─────────────────────────────────────────────────────────────

    public function clearMediaCollection(string $collection = 'default'): static
    {
        $this->getMedia($collection)->each->delete();
        $this->unsetRelation('media');

        return $this;
    }

    // ─── Conversions & Collections ───────────────────────────────────────────

    public function registerMediaConversions(Media $media): void {}

    public function registerMediaCollections(): void {}

    public function addMediaConversion(string $name): Conversion
    {
        $conversion = new Conversion($name);
        $this->mediaConversions[] = $conversion;

        return $conversion;
    }

    public function addMediaCollection(string $name): MediaCollection
    {
        $collection = new MediaCollection($name);
        $this->mediaCollections[$name] = $collection;

        return $collection;
    }

    /** @return Conversion[] */
    public function getRegisteredMediaConversions(): array
    {
        $this->mediaConversions = [];
        $this->registerMediaConversions(new Media);

        return $this->mediaConversions;
    }

    public function getMediaCollection(string $name): ?MediaCollection
    {
        if (! $this->mediaCollectionsRegistered) {
            $this->mediaCollections = [];
            $this->registerMediaCollections();
            $this->mediaCollectionsRegistered = true;
        }

        return $this->mediaCollections[$name] ?? null;
    }

    // ─── Test assertions ─────────────────────────────────────────────────────

    /**
     * Assert the collection contains media (and optionally a specific count).
     * Uses PHPUnit\Framework\Assert — call only from test code.
     */
    public function assertHasMedia(string $collection = 'default', ?int $count = null): void
    {
        $media = $this->getMedia($collection);

        \PHPUnit\Framework\Assert::assertTrue(
            $media->isNotEmpty(),
            "Expected [{$collection}] collection to have media, but it is empty.",
        );

        if ($count !== null) {
            \PHPUnit\Framework\Assert::assertCount(
                $count,
                $media,
                "Expected [{$collection}] to have {$count} item(s), got {$media->count()}.",
            );
        }
    }

    /**
     * Assert the collection is empty.
     */
    public function assertHasNoMedia(string $collection = 'default'): void
    {
        $media = $this->getMedia($collection);

        \PHPUnit\Framework\Assert::assertTrue(
            $media->isEmpty(),
            "Expected [{$collection}] to be empty, but it has {$media->count()} item(s).",
        );
    }

    /**
     * Assert the collection contains exactly the given number of items.
     */
    public function assertMediaCount(string $collection, int $count): void
    {
        $media = $this->getMedia($collection);

        \PHPUnit\Framework\Assert::assertCount(
            $count,
            $media,
            "Expected [{$collection}] to have {$count} item(s), got {$media->count()}.",
        );
    }
}
