---
title: Collections
weight: 40
---

# Collections

A **collection** is a named group of media attached to a model — similar to an album. Common examples: `image` (single hero shot), `gallery` (multiple photos), `documents` (PDFs).

Collections are optional. If you call `toMediaCollection('gallery')` without defining `'gallery'` in `registerMediaCollections()`, the upload succeeds with no constraints applied.

## Defining collections

Override `registerMediaCollections()` on your model:

```php
public function registerMediaCollections(): void
{
    // Single main image — new upload replaces the previous one
    $this->addMediaCollection('image')->singleFile();

    // Unlimited photos
    $this->addMediaCollection('gallery');

    // PDF-only, 20 MB max
    $this->addMediaCollection('documents')
        ->acceptsMimeTypes(['application/pdf'])
        ->maxFileSize(20 * 1024 * 1024);

    // Slider images
    $this->addMediaCollection('slider');
}
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

Non-image collections (PDFs, documents) don't need image conversion jobs. Mark them explicitly to avoid dispatching a job that would immediately return:

```php
$this->addMediaCollection('documents')
    ->acceptsMimeTypes(['application/pdf'])
    ->withoutConversions();
```

Without this flag, uploading a PDF still dispatches `PerformConversionsJob` which checks `$media->isImage()` and exits — harmless, but wasteful.

---

## Clearing a collection

```php
$product->clearMediaCollection('gallery'); // deletes all files + S3 objects
```

## Retrieving collection names

There is no built-in method to list all defined collection names — iterate `registerMediaCollections()` or define them as class constants if you need to reference them programmatically.
