---
title: Uploading
weight: 30
---

# Uploading

All upload methods on `HasMedia` return a `FileAdder` instance. The upload is triggered by the terminal call `toMediaCollection()`.

## From an HTTP request

```php
$media = $product->addMedia($request->file('photo'))
    ->toMediaCollection('gallery');
```

## From a local file path

```php
$media = $product->addMedia('/tmp/import/photo.jpg')
    ->toMediaCollection('gallery');
```

## From a remote URL

Downloads the file using Guzzle and streams it directly to disk — the full file is never held in memory.

```php
$media = $product->addMediaFromUrl('https://cdn.supplier.com/product.jpg')
    ->toMediaCollection('gallery');

// With custom HTTP headers
$media = $product->addMediaFromUrl($url, ['Authorization' => 'Bearer ' . $token])
    ->toMediaCollection('gallery');
```

The download timeout is configurable via `MEDIA_DOWNLOAD_TIMEOUT` (default: 60 seconds).

## From base64

Accepts both a raw base64 string and a data URI:

```php
// data URI (common in mobile/JSON API requests)
$product->addMediaFromBase64('data:image/jpeg;base64,/9j/4AAQ...', 'image/jpeg')
    ->toMediaCollection('image');

// raw base64
$product->addMediaFromBase64(base64_encode(file_get_contents($path)), 'image/png')
    ->toMediaCollection('image');
```

## FileAdder options

Chain these before calling `toMediaCollection()`:

```php
$product->addMedia($file)
    ->usingName('Product hero shot')       // human-readable name on the Media record
    ->usingFileName('hero.jpg')            // override the stored filename
    ->withCustomProperties(['alt' => 'A red chair'])
    ->toMediaCollection('image');
```

## What happens on upload

1. File is resolved to a local temp path (downloaded or decoded if needed).
2. `ImageProcessor` extracts `width`/`height` for images and strips EXIF (when `strip_exif` is `true`).
3. If `deduplication` is enabled, the MD5 hash is compared against existing records for the same model + collection. A duplicate returns the existing `Media` record without re-uploading.
4. The `Media` record is created and the file is written to the configured S3 disk.
5. Image conversions are dispatched — synchronously for `.nonQueued()` conversions, to the queue for the rest.
6. The new `Media` instance is returned.

## Batch import pattern

When importing many products with multiple images each (e.g. from an external supplier API), loop `addMediaFromUrl()` per product. Deduplication ensures repeated runs are safe:

```php
foreach ($productData['images'] as $url) {
    $product->addMediaFromUrl($url)->toMediaCollection('gallery');
}
```

If the same URL appears in a subsequent import run, the MD5 hash will match the stored record and the file will not be re-downloaded or re-uploaded.
