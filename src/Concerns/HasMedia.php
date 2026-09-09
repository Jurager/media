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
use Jurager\Media\Contracts\DynamicMediaCollectionResolver;
use Jurager\Media\Conversions\Conversion;
use Jurager\Media\Enums\ConversionStatus;
use Jurager\Media\MediaCollection;
use Jurager\Media\Models\Media;
use Jurager\Media\Models\MediaConversion;
use Jurager\Media\Support\FileAdder;
use Jurager\Media\Support\MediaCollectionResolverRegistry;
use Jurager\Media\Support\PathGenerator;

trait HasMedia
{
    /** @var Conversion[] */
    protected array $mediaConversions = [];

    /** @var array<string, MediaCollection> */
    protected array $mediaCollections = [];

    /** @var DynamicMediaCollectionResolver[] */
    protected array $mediaCollectionResolvers = [];

    protected bool $mediaCollectionsRegistered = false;

    /** Cached result of registerMediaConversions() — null means not yet built. */
    protected ?array $registeredConversionsCache = null;

    /** When true, all media is deleted automatically when the model is deleted. */
    protected bool $deleteMediaOnDelete = true;

    public static function bootHasMedia(): void
    {
        static::deleting(static function (self $model): void {

            if (! $model->deleteMediaOnDelete) {
                return;
            }

            if (in_array(SoftDeletes::class, class_uses_recursive($model), true) && ! $model->isForceDeleting()) {
                return;
            }

            $model->media()->chunkById(100, fn (Collection $chunk) => $chunk->each->delete());
        });
    }

    public function media(): MorphMany
    {
        return $this->morphMany(config('media.models.media', Media::class), 'mediable')->orderBy('order_column');
    }

    public function addMedia(UploadedFile|string $file): FileAdder
    {
        return $this->makeFileAdder()->setFile($file);
    }

    public function addMediaFromUrl(string $url, array $headers = []): FileAdder
    {
        return $this->makeFileAdder()->setFileFromUrl($url, $headers);
    }

    public function addMediaFromBase64(string $base64, string $mimeType = ''): FileAdder
    {
        return $this->makeFileAdder()->setFileFromBase64($base64, $mimeType);
    }

    public function addMediaFromDisk(string $path, string $disk): FileAdder
    {
        return $this->makeFileAdder()->setFileFromDisk($path, $disk);
    }

    private function makeFileAdder(): FileAdder
    {
        return app(FileAdder::class)->for($this);
    }

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
        $generator = app(PathGenerator::class);

        $copy = $original->replicate(['conversions']);
        $copy->uuid = (string) Str::uuid();
        $copy->mediable_type = $this->getMorphClass();
        $copy->mediable_id = $this->getKey();

        DB::transaction(static function () use ($copy): void {
            $copy->assignNextOrderColumn();
            $copy->save();
        });

        Storage::disk($original->disk)->copy(
            $generator->getPath($original).$original->file_name,
            $generator->getPath($copy).$copy->file_name,
        );

        $mediaConversionClass = config('media.models.media_conversion', MediaConversion::class);

        foreach ($original->conversions()->where('status', ConversionStatus::Done)->get() as $conversion) {
            $conversionFileName = $original->getConversionFileName($conversion->name);

            Storage::disk($conversion->disk)->copy(
                $generator->getPathForConversions($original).$conversionFileName,
                $generator->getPathForConversions($copy).$conversionFileName,
            );

            $mediaConversionClass::create([
                'media_id' => $copy->id,
                'name' => $conversion->name,
                'status' => ConversionStatus::Done,
                'disk' => $conversion->disk,
                'extension' => $conversion->extension,
                'size' => $conversion->size,
                'properties' => $conversion->properties,
                'completed_at' => $conversion->completed_at,
            ]);
        }

        $this->unsetRelation('media');

        return $copy;
    }

    public function scopeWithMedia(Builder $query, string|array|null $collections = null): Builder
    {
        if ($collections === null) {
            return $query->with('media');
        }

        return $query->with(['media' => fn ($q) => $q->whereIn('collection_name', (array) $collections)]);
    }

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

    public function getLastMedia(string $collection = 'default'): ?Media
    {
        return $this->getMedia($collection)->last();
    }

    public function getFirstMediaUrl(string $collection = 'default', string $conversion = ''): string
    {
        $media = $this->getFirstMedia($collection);

        if ($media !== null) {
            return $media->getUrl($conversion);
        }

        return $this->getMediaCollection($collection)?->getFallbackUrl($conversion) ?? '';
    }

    public function getLastMediaUrl(string $collection = 'default', string $conversion = ''): string
    {
        return $this->getLastMedia($collection)?->getUrl($conversion) ?? '';
    }

    public function hasMedia(string $collection = 'default'): bool
    {
        return $this->getMedia($collection)->isNotEmpty();
    }

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

    public function clearMediaCollection(string $collection = 'default'): static
    {
        $this->getMedia($collection)->each->delete();
        $this->unsetRelation('media');

        return $this;
    }

    public function clearMediaCollectionExcept(string $collection = 'default', Media|iterable $except = []): static
    {
        if ($except instanceof Media) {
            $except = [$except];
        }

        $exceptIds = collect($except)->map(fn (Media $m) => $m->getKey())->all();

        $this->getMedia($collection)
            ->reject(fn (Media $m) => in_array($m->getKey(), $exceptIds, true))
            ->each->delete();

        $this->unsetRelation('media');

        return $this;
    }

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

    /** Register a resolver for collections that can't be declared statically — call from registerMediaCollections(), alongside addMediaCollection(). */
    public function addMediaCollectionResolver(DynamicMediaCollectionResolver $resolver): static
    {
        $this->mediaCollectionResolvers[] = $resolver;

        return $this;
    }

    /** @return Conversion[] */
    public function getRegisteredMediaConversions(): array
    {
        if ($this->registeredConversionsCache === null) {
            $this->mediaConversions = [];
            $this->registerMediaConversions(new Media);
            $this->registeredConversionsCache = $this->mediaConversions;
        }

        return $this->registeredConversionsCache;
    }

    /** Statically registered collections only — dynamic ones are not included. */
    public function getRegisteredMediaCollections(): array
    {
        $this->ensureMediaCollectionsRegistered();

        return $this->mediaCollections;
    }

    protected function ensureMediaCollectionsRegistered(): void
    {
        if ($this->mediaCollectionsRegistered) {
            return;
        }

        $this->mediaCollections = [];
        $this->mediaCollectionResolvers = [];
        $this->registerMediaCollections();
        $this->mediaCollectionsRegistered = true;
    }

    /** Every collection name valid for this model, independent of any one instance's state — what a throwaway `new $modelClass` (media:clean) should ask instead of getMediaCollection(). */
    public function getMediaCollectionNames(): array
    {
        $this->ensureMediaCollectionsRegistered();

        $names = array_keys($this->mediaCollections);

        foreach ($this->allMediaCollectionResolvers() as $resolver) {
            $names = [...$names, ...$resolver->names($this)];
        }

        return array_values(array_unique($names));
    }

    /** Resolvers added on this model plus every one registered globally via MediaCollectionResolverRegistry. */
    protected function allMediaCollectionResolvers(): array
    {
        return [
            ...$this->mediaCollectionResolvers,
            ...app(MediaCollectionResolverRegistry::class)->all(),
        ];
    }

    public function getConversionsForMedia(Media $media): array
    {
        return array_values(array_filter(
            $this->getConversionsForCollection($media->collection_name),
            static fn (Conversion $c) => $c->shouldBePerformedOnMimeType($media->mime_type),
        ));
    }

    public function getConversionsForCollection(string $collectionName): array
    {
        $collection = $this->getMediaCollection($collectionName);
        $callbacks = $collection?->getConversionCallbacks() ?? [];

        if (! empty($callbacks)) {
            $this->mediaConversions = [];

            foreach ($callbacks as $callback) {
                $callback(new Media);
            }

            return $this->mediaConversions;
        }

        return array_values(array_filter(
            $this->getRegisteredMediaConversions(),
            static fn (Conversion $c) => $c->shouldBePerformedOn($collectionName),
        ));
    }

    public function getMediaCollection(string $name): ?MediaCollection
    {
        $this->ensureMediaCollectionsRegistered();

        if (isset($this->mediaCollections[$name])) {
            return $this->mediaCollections[$name];
        }

        $dynamic = $this->resolveDynamicMediaCollection($name);

        if ($dynamic !== null) {
            $this->mediaCollections[$name] = $dynamic;
        }

        return $dynamic;
    }

    /** Fallback for a collection not statically registered — default asks every resolver in order; overriding replaces this entirely. */
    protected function resolveDynamicMediaCollection(string $name): ?MediaCollection
    {
        foreach ($this->allMediaCollectionResolvers() as $resolver) {
            $collection = $resolver->resolve($this, $name);

            if ($collection !== null) {
                return $collection;
            }
        }

        return null;
    }
}
