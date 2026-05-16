---
title: Conversions
weight: 50
---

# Conversions

A **conversion** is a derived version of an uploaded image — a thumbnail, a medium-sized preview, or a WebP reformat. Conversions are defined per model and generated automatically after each image upload.

Non-image files (PDFs, documents, etc.) are stored as-is; conversions are never attempted for them.

## Defining conversions

Override `registerMediaConversions()` on your model. Conversions are registered with `addMediaConversion()`:

```php
public function registerMediaConversions(Media $media): void
{
    // 200×200 crop, converted to WebP — good for listing thumbnails
    $this->addMediaConversion('thumb')
        ->fit(200, 200)
        ->format('webp')
        ->quality(85);

    // Scale to 800 px wide, keep aspect ratio
    $this->addMediaConversion('medium')
        ->width(800)
        ->quality(80);

    // Large version, generated synchronously (not queued)
    $this->addMediaConversion('large')
        ->width(1600)
        ->nonQueued();

    // Only generate this conversion for the gallery collection
    $this->addMediaConversion('square')
        ->fit(400, 400)
        ->performOnCollections('gallery', 'slider');
}
```

## Fit modes

| Method | Behaviour |
|--------|-----------|
| `->width(int)` | Scale to width, preserve aspect ratio |
| `->height(int)` | Scale to height, preserve aspect ratio |
| `->fit(w, h)` | Crop to exact dimensions (cover, no whitespace) |
| `->contain(w, h)` | Fit within box, preserve aspect ratio (no crop) |

Both `width()` and `height()` can be combined — the image is scaled to fit within both bounds while preserving the aspect ratio.

## Output formats

```php
->format('webp')  // WebP (best compression for web)
->format('jpg')   // JPEG (default if no format is set)
->format('png')   // PNG (lossless)
->format('avif')  // AVIF (requires Imagick)
```

When a format is specified, the stored extension in `generated_conversions` reflects it — `getUrl('thumb')` returns the correct URL regardless of the original format.

## Quality

```php
->quality(85) // JPEG/WebP quality 1–100 (default: 80)
```

Has no effect on lossless formats (`png`).

## Queue behaviour

By default all conversions run **asynchronously** via the queue configured in `MEDIA_QUEUE`. This keeps HTTP responses fast.

```php
// Run in the queue (default)
$this->addMediaConversion('medium')->width(800);

// Run synchronously — blocks the upload request
$this->addMediaConversion('thumb')->fit(200, 200)->nonQueued();
```

A common pattern is to generate `thumb` synchronously (so it is available immediately for the response) and queue `medium` and `large`:

```php
$this->addMediaConversion('thumb')->fit(200, 200)->nonQueued();
$this->addMediaConversion('medium')->width(800);
$this->addMediaConversion('large')->width(1600);
```

## Per-conversion queue

Override the default queue for a specific conversion:

```php
public function registerMediaConversions(Media $media): void
{
    // Listed in the product immediately — high priority
    $this->addMediaConversion('thumb')
        ->fit(200, 200)
        ->format('webp')
        ->onQueue('high');

    // Needed less urgently — default queue
    $this->addMediaConversion('medium')->width(800);
    $this->addMediaConversion('large')->width(1600);
}
```

Conversions are grouped by queue and dispatched as separate jobs, so `onQueue('high')` does not delay other conversions.

## Checking conversion status

```php
$media->hasGeneratedConversion('thumb');  // bool — is it ready?
$media->isConversionPending('medium');    // bool — registered but not yet generated?
$media->pendingConversions();             // ['medium', 'large'] — all pending names
```

`pendingConversions()` loads the mediable relation to read the model's registered conversions. Eager-load `media.mediable` when calling this in a loop.

## Regenerating conversions

When you add a new conversion size or change existing parameters, regenerate for existing records:

```bash
# Regenerate for a specific model (dispatches to queue)
php artisan media:regenerate "App\Models\Product"

# Limit to one collection
php artisan media:regenerate "App\Models\Product" --collection=gallery

# All models in the media table
php artisan media:regenerate --all

# Run synchronously (no queue)
php artisan media:regenerate "App\Models\Product" --sync
```

The command resets `generated_conversions` before dispatching so that `getUrl()` falls back to the original while conversions are pending.

## Storage layout

Given `Product` with `id=42`, collection `gallery`, file `photo.jpg`:

```
product/42/gallery/photo.jpg                        ← original
product/42/gallery/conversions/photo-thumb.webp     ← thumb conversion
product/42/gallery/conversions/photo-medium.jpg     ← medium conversion
```
