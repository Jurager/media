---
title: Collections
weight: 40
---

# Collections

A **collection** is a named group of media attached to a model — similar to an album. Common examples: `image` (single hero shot), `gallery` (multiple photos), `documents` (PDFs).

Collections are optional. If you call `toMediaCollection('gallery')` without defining `'gallery'` in `registerMediaCollections()`, the upload succeeds with no constraints applied.

## Defining collections

Override `registerMediaCollections()` on your model. Use `withConversions()` to attach conversions directly to the collection that needs them — no need for a separate `registerMediaConversions()` method or `performOnCollections()` calls:

```php
public function registerMediaCollections(): void
{
    // Single main image — new upload replaces the previous one
    $this->addMediaCollection('image')
        ->singleFile()
        ->withConversions(function (Media $media): void {
            $this->addMediaConversion('thumb')->fit(200, 200)->format('webp')->quality(85)->nonQueued();
            $this->addMediaConversion('medium')->width(800)->quality(80);
        });

    // Unlimited photos with the same conversions
    $this->addMediaCollection('gallery')
        ->withConversions(function (Media $media): void {
            $this->addMediaConversion('thumb')->fit(200, 200)->format('webp')->quality(85)->nonQueued();
            $this->addMediaConversion('medium')->width(800)->quality(80);
        });

    // PDF-only, 20 MB max, with a first-page preview conversion
    $this->addMediaCollection('documents')
        ->acceptsMimeTypes(['application/pdf'])
        ->maxFileSize(20 * 1024 * 1024)
        ->withConversions(function (Media $media): void {
            $this->addMediaConversion('preview')->width(800)->format('jpg')->quality(85);
        });

    // Slider images — no conversions needed here
    $this->addMediaCollection('slider');
}
```

Multiple `withConversions()` calls on the same collection are additive. Share a common closure to avoid duplication:

```php
$baseConversions = function (Media $media): void {
    $this->addMediaConversion('thumb')->fit(200, 200)->format('webp')->quality(85)->nonQueued();
    $this->addMediaConversion('medium')->width(800)->format('webp')->quality(80);
};

$this->addMediaCollection('image')
    ->singleFile()
    ->withConversions($baseConversions)
    ->withConversions(function (Media $media): void {
        $this->addMediaConversion('large')->width(1600)->quality(85);
    });

$this->addMediaCollection('gallery')
    ->withConversions($baseConversions); // thumb + medium only
```

## Constraints

### Single file

```php
$this->addMediaCollection('image')->singleFile();
```

When a new file is uploaded to a single-file collection, the existing file is automatically deleted before the new one is stored.

### Allowed MIME types

```php
$this->addMediaCollection('documents')
    ->acceptsMimeTypes(['application/pdf', 'application/msword']);
```

Uploading a file with a disallowed MIME type throws `InvalidArgumentException`. MIME type is detected server-side, not from the client header.

### Maximum file size

```php
$this->addMediaCollection('gallery')
    ->maxFileSize(5 * 1024 * 1024); // 5 MB
```

Size is checked in bytes against the actual file size. Exceeding it throws `InvalidArgumentException`.

> [!NOTE]
> File size validation via `maxFileSize()` applies to `UploadedFile` sources. For URL and base64 sources the file must be downloaded first, so the constraint is not enforced before upload begins. Validate content-length headers manually if needed for those sources.

### Per-collection disk

Store a specific collection on a different disk than the global default. Useful for keeping private documents on a private S3 bucket while product images remain on a public CDN-backed disk:

```php
public function registerMediaCollections(): void
{
    $this->addMediaCollection('gallery')->disk('s3-public');

    $this->addMediaCollection('documents')
        ->acceptsMimeTypes(['application/pdf'])
        ->disk('s3-private');
}
```

Each disk must be configured in `config/filesystems.php`. Conversions are stored on the same disk as the original.

### Disable conversions for a collection

If a collection has no `withConversions()` callbacks and no matching entries in `registerMediaConversions()`, no conversion job is dispatched — nothing to configure. Call `withoutConversions()` explicitly only when you want to suppress conversions even if global `registerMediaConversions()` would otherwise match the collection:

```php
$this->addMediaCollection('originals')
    ->withoutConversions();
```

---

## Clearing a collection

```php
$product->clearMediaCollection('gallery'); // deletes all files + S3 objects
```

## Retrieving collection names

`getRegisteredMediaCollections()` returns the statically declared ones as `name => MediaCollection`. `getMediaCollectionNames()` returns every valid name — those plus whatever the model's dynamic resolvers report (see below) — as a flat list:

```php
$product->getRegisteredMediaCollections(); // ['gallery' => MediaCollection {...}]
$product->getMediaCollectionNames();       // ['gallery', 'cover_photo', 'brochure', ...]
```

## Dynamic collections

Some collections can't be declared up front in `registerMediaCollections()` — one product's attributes might include a `cover_photo` file field, another's a `brochure`, driven by whatever schema an external system (a CMS, an EAV package) defines for that entity type. For these, `HasMedia` calls a fallback hook instead of failing:

```php
protected function resolveDynamicMediaCollection(string $name): ?MediaCollection
{
    // return a MediaCollection built on the fly, or null if $name isn't yours
}
```

Overriding it directly is fine for a one-off. When the same dynamic logic applies to several models — e.g. every model backed by the same external schema — register a shared `DynamicMediaCollectionResolver` instead, from `registerMediaCollections()`:

```php
use Jurager\Media\Contracts\DynamicMediaCollectionResolver;

class CmsFieldMediaResolver implements DynamicMediaCollectionResolver
{
    public function resolve(object $model, string $name): ?MediaCollection
    {
        $field = Cms::fieldFor($model, $name);

        return $field?->isFileField() ? new MediaCollection($name) : null;
    }

    public function names(object $model): array
    {
        // Every collection name valid for $model's type, independent of any one
        // instance's state — media:clean asks a throwaway, unbound instance.
        return Cms::fileFieldNamesFor($model::class);
    }
}
```

```php
public function registerMediaCollections(): void
{
    $this->addMediaCollection('gallery');
    $this->addMediaCollectionResolver(app(CmsFieldMediaResolver::class));
}
```

`resolveDynamicMediaCollection()` asks every registered resolver, in order, and uses the first non-null result. Overriding the method yourself replaces this entirely — registered resolvers stop being consulted — so pick one approach per model.

`names()` is what makes `getMediaCollectionNames()` — and therefore `media:clean` — work correctly for dynamic collections. Without it, a throwaway `new $modelClass` has no way to reproduce whatever per-instance state `resolve()`/`resolveDynamicMediaCollection()` needs (a bound category, a loaded relation, ...), every dynamic collection looks unregistered, and `media:clean` deletes files that are very much still in use. Answer `names()` from the schema itself, not from resolving each name individually.

### Applying a resolver to every model

A resolver that already tells its own models apart (`CmsFieldMediaResolver::resolve()` above only reacts if `Cms::fieldFor($model, $name)` finds something) doesn't need registering per model — register it once, and every model using `HasMedia` picks it up automatically:

```php
use Jurager\Media\Support\MediaCollectionResolverRegistry;

app(MediaCollectionResolverRegistry::class)->register(app(CmsFieldMediaResolver::class));
```

Do this once, at boot (a service provider's `boot()` is the natural place). `resolveDynamicMediaCollection()` and `getMediaCollectionNames()` both consult global resolvers after any registered with `addMediaCollectionResolver()` on the model itself.
