---
title: Conversions
weight: 50
---

# Conversions

A **conversion** is a derived version of an uploaded file — a thumbnail, a medium-sized preview, or a WebP reformat. Conversions are defined per model and generated automatically after upload.

Which file types can be converted depends on the registered converters (see [Pluggable converters](#pluggable-converters) below). Out of the box: all `image/*` types and `application/pdf`.

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

When a format is specified, the extension is stored in the `MediaConversion` record — `getUrl('thumb')` returns the correct URL regardless of the original format.

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
    $this->addMediaConversion('thumb')
        ->fit(200, 200)
        ->format('webp')
        ->onQueue('high');

    $this->addMediaConversion('medium')->width(800);
    $this->addMediaConversion('large')->width(1600);
}
```

Conversions are grouped by queue and dispatched as separate jobs, so `onQueue('high')` does not delay other conversions.

## Checking conversion status

```php
$media->hasGeneratedConversion('thumb');  // bool — is it ready?
$media->isConversionPending('medium');    // bool — status=pending?
$media->pendingConversions();             // ['medium', 'large'] — all pending names
$media->failedConversions();             // names with status=failed
```

These methods read from the `media_conversions` table. Eager-load `media.conversions` when calling them in a loop to avoid N+1 queries.

## Pluggable converters

Conversions are not limited to images. The package routes each file through a **converter** selected by MIME type. You can register converters for any file type.

### Built-in converters

| Converter | MIME pattern | Requirements |
|-----------|-------------|--------------|
| `ImageConverter` | `image/*` | `ext-gd` or `ext-imagick` |
| `PdfConverter` | `application/pdf` | `ext-imagick` + Ghostscript |

### PDF preview

`PdfConverter` rasterizes a single page of a PDF to an image, then applies all standard image transformations (resize, format, quality):

```php
public function registerMediaCollections(): void
{
    $this->addMediaCollection('documents')
        ->acceptsMimeTypes(['application/pdf']);
}

public function registerMediaConversions(Media $media): void
{
    $this->addMediaConversion('preview')
        ->width(800)
        ->format('jpg')
        ->quality(85)
        ->performOnCollections('documents');
}
```

Configure in `config/media.php`:

```php
'pdf_converter' => [
    'resolution' => env('MEDIA_PDF_RESOLUTION', 150), // DPI — higher = sharper but slower
    'page'       => 0,                                 // 0-indexed page to render
],
```

Files with no registered converter (e.g. DOCX uploaded to a collection that has a conversion defined) will have their `MediaConversion` record marked `status=failed` with a descriptive `error_message`. This is visible via `$media->failedConversions()`.

### Custom converter

Implement the `Converter` interface and register it in `config/media.php`:

```php
use Jurager\Media\Contracts\Converter;
use Jurager\Media\Converters\ConversionResult;
use Jurager\Media\Conversions\Conversion;
use Jurager\Media\Models\Media;

class VideoThumbnailConverter implements Converter
{
    public function convert(string $sourcePath, Conversion $conversion, Media $media): ConversionResult
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'media_video_');

        // Extract frame at 00:00:01 using FFMpeg
        exec("ffmpeg -i {$sourcePath} -ss 00:00:01 -vframes 1 {$tmpFile}.jpg");

        return new ConversionResult(
            path:      $tmpFile . '.jpg',
            extension: 'jpg',
            width:     1920,
            height:    1080,
        );
    }
}
```

```php
// config/media.php
'converters' => [
    'image/*'         => \Jurager\Media\Converters\ImageConverter::class,
    'application/pdf' => \Jurager\Media\Converters\PdfConverter::class,
    'video/mp4'       => \App\Media\VideoThumbnailConverter::class,
],
```

## Regenerating conversions

When you add a new conversion size or change existing parameters, regenerate for existing records:

```bash
# Regenerate for a specific model (dispatches to queue)
php artisan media:regenerate "App\Models\Product"

# Limit to one collection
php artisan media:regenerate "App\Models\Product" --collection=documents

# All models in the media table
php artisan media:regenerate --all

# Run synchronously (no queue)
php artisan media:regenerate "App\Models\Product" --sync
```

The command resets existing `MediaConversion` records to `status=pending` before dispatching, so that `getUrl()` falls back to the original while conversions are regenerating.

## Storage layout

Given `Product` with `id=42`, collection `gallery`, file `photo.jpg`:

```
product/42/gallery/photo.jpg                        ← original
product/42/gallery/conversions/photo-thumb.webp     ← thumb conversion
product/42/gallery/conversions/photo-medium.jpg     ← medium conversion
```

PDF preview:

```
product/42/documents/manual.pdf                         ← original
product/42/documents/conversions/manual-preview.jpg     ← first page preview
```
