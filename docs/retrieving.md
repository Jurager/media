---
title: Retrieving
weight: 60
---

# Retrieving

## Getting media records

```php
// All media in a collection (ordered by order_column)
$product->getMedia('gallery');              // Illuminate\Support\Collection<Media>

// First item in a collection
$product->getFirstMedia('gallery');         // ?Media

// Check whether any media exists
$product->hasMedia('gallery');              // bool
```

## Public URLs

```php
// Original file
$product->getFirstMediaUrl('image');

// Named conversion — falls back to original if conversion is still pending
$product->getFirstMediaUrl('image', 'thumb');

// Directly on a Media instance
$media->getUrl();            // original
$media->getUrl('thumb');     // conversion (fallback to original when not yet generated)
$media->getUrl('medium');
```

### CDN

Set `MEDIA_CDN_URL=https://d1234.cloudfront.net` and all URLs — originals and conversions — are rewritten automatically. No code changes required.

## Presigned (temporary) URLs

For files stored on a private S3 disk:

```php
// Original file, expires in 30 minutes
$media->getTemporaryUrl(now()->addMinutes(30));

// A specific conversion
$media->getTemporaryConversionUrl('thumb', now()->addMinutes(30));

// With extra S3 options
$media->getTemporaryUrl(now()->addHour(), ['ResponseContentDisposition' => 'attachment']);
```

> [!NOTE]
> Presigned URLs are only supported by S3-compatible disks. Using `getTemporaryUrl()` on a local disk will throw an exception.

## Image dimensions

Width and height are extracted automatically at upload and stored in `custom_properties`:

```php
$media->getCustomProperty('width');   // e.g. 1920
$media->getCustomProperty('height');  // e.g. 1080
```

Use these to set `width` and `height` attributes on `<img>` tags, preventing layout shift.

## File metadata

```php
$media->name;                 // human-readable name
$media->file_name;            // stored filename (sanitized slug)
$media->mime_type;            // e.g. 'image/jpeg', 'application/pdf'
$media->size;                 // bytes
$media->humanReadableSize();  // '2.4 MB'
$media->isImage();            // true for image/* MIME types
$media->collection_name;      // 'gallery'
$media->order_column;         // position within collection
$media->uuid;                 // unique identifier
```

## Custom properties

```php
// Set at upload time
$product->addMedia($file)
    ->withCustomProperties(['alt' => 'Red chair', 'photographer' => 'Studio X'])
    ->toMediaCollection('gallery');

// Read at any time
$media->getCustomProperty('alt');
$media->getCustomProperty('missing_key', 'default value');

// Modify and save
$media->setCustomProperty('alt', 'Updated description')->save();
```

## Reordering

Update the `order_column` for a collection by passing an array of Media IDs in the desired order:

```php
$product->reorderMedia('gallery', [3, 1, 2]);
```

After reordering, `getMedia()` will return items in the new order. The relationship is automatically refreshed.

## Eager loading

To avoid N+1 queries when listing multiple models use the `withMedia()` scope:

```php
// All collections
$products = Product::withMedia()->get();

// Only specific collections — other collections will query on demand
$products = Product::withMedia(['image', 'gallery'])->get();

foreach ($products as $product) {
    $url = $product->getFirstMediaUrl('image', 'thumb');
}
```

`getMedia()` checks `$this->relationLoaded('media')` and skips the query when the relation is already loaded.

## Reordering

```php
$product->reorderMedia('gallery', [3, 1, 2]); // Media IDs in desired order
```

## Serving private files

For files stored on a private disk, stream them through your controller rather than generating presigned URLs. This keeps access control in your application:

```php
public function show(Media $media): StreamedResponse
{
    abort_unless(auth()->user()->can('view', $media), 403);

    return $media->stream();    // inline (PDF preview in browser)
    // or
    return $media->download();              // force download
    return $media->download('manual.pdf'); // with custom filename
}
```

## Copying media between models

```php
// Copy a specific collection (S3 server-side copy — no bandwidth cost)
$duplicate->copyMediaFrom($product, 'gallery');

// Copy multiple collections
$duplicate->copyMediaFrom($product, ['gallery', 'documents']);

// Copy everything
$duplicate->copyMediaFrom($product);
```

Already-generated conversions are copied alongside originals.

## Checking conversion status

```php
$media->hasGeneratedConversion('thumb');  // false while job is pending
$media->getUrl('thumb');                  // returns original URL when pending
```
