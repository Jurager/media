---
title: Advanced
weight: 70
---

# Advanced

## Events

The package dispatches events at key points in the media lifecycle. All events are in the `Jurager\Media\Events\` namespace.

| Event | Property | When |
|-------|----------|------|
| `MediaAdded` | `Media $media` | A Media record is created |
| `MediaDeleted` | `Media $media` | A Media record is deleted (before S3 cleanup) |
| `MediaConversionGenerated` | `Media $media`, `Conversion $conversion` | Each conversion is written to disk |

Laravel auto-discovers listeners by type-hint in `handle()`:

```php
namespace App\Listeners;

use Jurager\Media\Events\MediaAdded;

class InvalidateProductCache
{
    public function handle(MediaAdded $event): void
    {
        $mediable = $event->media->mediable;

        if ($mediable instanceof \App\Models\Product) {
            Cache::forget("product:{$mediable->id}:media");
        }
    }
}
```

```php
use Jurager\Media\Events\MediaConversionGenerated;

class UpdateSearchIndex
{
    public function handle(MediaConversionGenerated $event): void
    {
        // Re-index the product in Typesense once its thumb is ready
        if ($event->conversion->name === 'thumb') {
            $event->media->mediable?->searchable();
        }
    }
}
```

---

## Soft deletes

When a model uses `SoftDeletes`, `$model->delete()` only sets `deleted_at` — it does **not** clean up media. Media is only deleted when the model is permanently removed via `$model->forceDelete()`.

This is the expected behaviour: a soft-deleted product might be restored, and its images must still exist.

```php
$product->delete();        // soft delete — media untouched
$product->restore();       // restored — media still there
$product->forceDelete();   // permanent — media and S3 files are deleted
```

To opt out of automatic cleanup entirely, set `$deleteMediaOnDelete = false` and manage cleanup manually.

---

## Testing

```php
// In setUp() or in the test method
Media::fake();

// If any collection uses a custom disk (->disk('s3-private')), pass it explicitly
Media::fake(['s3-private']);

// Upload
$product->addMedia(UploadedFile::fake()->image('photo.jpg'))->toMediaCollection('gallery');

// Assertions on the model
$product->assertHasMedia('gallery');
$product->assertMediaCount('gallery', 1);
$product->assertHasNoMedia('documents');

// Standard assertions on the Media record
$media = $product->getFirstMedia('gallery');
$this->assertNotNull($media);
$this->assertEquals('image/jpeg', $media->mime_type);
$this->assertNotNull($media->getWidth());
```

`Media::fake()` calls `Storage::fake()` for all configured disks. File operations (upload, copy, delete) work normally against the in-memory filesystem without touching S3.

---

## Deduplication

When `MEDIA_DEDUPLICATION=true` (default), uploading a file computes its MD5 hash and checks the `media.hash` column for a matching record within the same model + collection. If found, the existing `Media` instance is returned without re-uploading.

This is designed for **idempotent imports**: running the same Lemana/supplier import twice will not duplicate images.

```php
// First run — uploads the file
$media = $product->addMediaFromUrl($url)->toMediaCollection('gallery');

// Second run — returns the same $media record, no S3 upload
$same = $product->addMediaFromUrl($url)->toMediaCollection('gallery');

$media->id === $same->id; // true
```

The `hash` column is always written on upload, even when deduplication is disabled, so it can be used for analytics or manual deduplication later.

To disable:

```dotenv
MEDIA_DEDUPLICATION=false
```

---

## EXIF stripping

When `MEDIA_STRIP_EXIF=true` (default), uploaded images are passed through Intervention Image before being written to S3. Re-encoding discards EXIF metadata — GPS coordinates, camera model, author, etc.

This happens transparently. The stored file is the re-encoded version; the original upload is never persisted. A temporary file is used during processing and deleted immediately after.

To disable (e.g. to preserve EXIF for internal archival purposes):

```dotenv
MEDIA_STRIP_EXIF=false
```

> [!NOTE]
> EXIF stripping with the GD driver is automatic (GD never carries EXIF). With the Imagick driver, re-encoding also strips it. Either driver is safe.

---

## URL download security (SSRF protection)

When `addMediaFromUrl()` accepts URLs from user input, restrict which domains are allowed to prevent Server-Side Request Forgery (SSRF) attacks — an attacker could otherwise reach the AWS Instance Metadata endpoint, internal services, or other hosts the server can access.

```dotenv
# Comma-separated list of allowed hostnames
MEDIA_ALLOWED_DOMAINS=cdn.example.com,s3.amazonaws.com,storage.supplier.com

# Allow all domains — only safe for fully trusted internal callers
# MEDIA_ALLOWED_DOMAINS=*
```

When the list is empty (default), **all URL downloads are blocked**. This is the safe default for user-facing endpoints. Set it to `*` only for internal import jobs where the URL source is fully trusted (e.g. the Lemana importer).

```php
// config/media.php
'allowed_domains' => ['cdn.example.com', 'assets.supplier.com'],
```

---

## CDN support

Set the CDN base URL once; all `getUrl()` calls — originals and conversions — will use it:

```dotenv
MEDIA_CDN_URL=https://d1234example.cloudfront.net
```

The path is appended directly: `{cdn_url}/{model}/{id}/{collection}/{file}`. No code changes are required in the application.

---

## Custom path generator

The default strategy stores files at `{model_class}/{id}/{collection}/`. To change the layout, implement a custom generator and register it in `config/media.php`:

```php
namespace App\Media;

use Jurager\Media\Models\Media;
use Jurager\Media\Support\PathGenerator;

class TenantPathGenerator extends PathGenerator
{
    protected function getBasePath(Media $media): string
    {
        $tenantId = app('tenant')->id;

        return "tenant/{$tenantId}/" . parent::getBasePath($media);
    }
}
```

```php
// config/media.php
'path_generator' => \App\Media\TenantPathGenerator::class,
```

---

## Artisan commands

### `media:clean`

Finds and deletes `Media` records whose owning model no longer exists. S3 files are removed through the model's `deleting` observer.

```bash
php artisan media:clean

# Preview without deleting
php artisan media:clean --dry-run

# Control batch size (default: 100)
php artisan media:clean --chunk=500
```

Run this periodically (e.g. nightly cron) to prevent S3 cost accumulation from orphaned files.

### `media:regenerate`

Regenerates conversions for existing media records. Works for any file type — images, PDFs, or custom converters. Useful after adding new conversion sizes, changing parameters, or registering a new converter for an existing collection.

```bash
# One model
php artisan media:regenerate "App\Models\Product"

# One model, one collection
php artisan media:regenerate "App\Models\Product" --collection=gallery

# All models
php artisan media:regenerate --all

# Synchronous (no queue)
php artisan media:regenerate "App\Models\Product" --sync
```

The command resets existing `MediaConversion` records to `status=pending` before dispatching jobs, so `getUrl()` falls back to the original while conversions are regenerating.

---

## EAV integration (`jurager/eav`)

`jurager/media` has no dependency on `jurager/eav`. The two packages work independently.

**Path 1 — EAV stores S3 paths directly**

`ImageField` and `FileField` in `jurager/eav` store strings in `value_text`. Configure S3 as the default disk and the EAV fields work automatically — `HasFileStorage::url()` calls `Storage::disk($disk)->url($path)`.

This is the simplest approach. Use it when EAV attributes hold individual images (e.g. a `cover_image` product attribute).

**Path 2 — `HasMedia` on the same model**

Use `HasMedia` for collections (gallery, slider, documents) alongside EAV attributes. The two systems do not interfere — `jurager/media` uses its own `media` table while EAV uses `entity_attribute`.

```php
class Product extends Model implements Attributable, InteractsWithMedia
{
    use HasAttributes, HasMedia;

    // EAV: single structured attributes (name, price, weight…)
    public function attributeEntityType(): string { return 'product'; }

    // Media: unstructured file collections (gallery, documents)
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('gallery');
        $this->addMediaCollection('documents')->acceptsMimeTypes(['application/pdf']);
    }
}
```
