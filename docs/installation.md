---
title: Installation
weight: 10
---

# Installation

## Composer

```bash
composer require jurager/media
```

The package requires one of the following PHP extensions for image processing:

| Extension | Notes |
|-----------|-------|
| `ext-gd` | Default driver, available in most environments |
| `ext-imagick` | Better quality and wider format support; set `MEDIA_IMAGE_DRIVER=imagick` |

## Configuration

```bash
php artisan vendor:publish --tag=media-config
```

Creates `config/media.php`. Key options:

| Key | Default | Description |
|-----|---------|-------------|
| `disk` | `s3` | Laravel disk for storing original files |
| `conversions_disk` | `null` | Default disk for conversions; `null` uses same as `disk` |
| `cdn_url` | `null` | CDN base URL (e.g. CloudFront); replaces S3 URLs globally |
| `queue` | `default` | Queue name for async conversion jobs |
| `image_driver` | `gd` | `gd` or `imagick` |
| `strip_exif` | `true` | Strip EXIF metadata (GPS, camera) from uploaded images |
| `deduplication` | `true` | Skip re-uploading identical files (same MD5) per model+collection |
| `download_timeout` | `60` | HTTP timeout in seconds for `addMediaFromUrl()` |
| `models.media` | `Media::class` | Override the Media model |
| `models.media_conversion` | `MediaConversion::class` | Override the MediaConversion model |
| `path_generator` | `PathGenerator::class` | Override the S3 path strategy |

## Environment variables

```dotenv
MEDIA_DISK=s3
MEDIA_CDN_URL=https://d1234example.cloudfront.net
MEDIA_IMAGE_DRIVER=gd
MEDIA_STRIP_EXIF=true
MEDIA_DEDUPLICATION=true
MEDIA_QUEUE=conversions
```

## Database

```bash
php artisan vendor:publish --tag=media-migrations
php artisan migrate
```

This creates two tables:

### `media`

| Column | Type | Description |
|--------|------|-------------|
| `mediable_type`, `mediable_id` | morph | The owning model |
| `uuid` | string | Unique identifier |
| `collection_name` | string | Logical group (`image`, `gallery`, `documents`, …) |
| `name` | string | Human-readable label |
| `file_name` | string | Sanitized filename stored on disk |
| `mime_type` | string | MIME type detected at upload |
| `disk` | string | Laravel disk name |
| `size` | bigint | File size in bytes |
| `hash` | string | MD5 of the uploaded file; indexed for deduplication |
| `order_column` | int | Position within the collection |
| `properties` | json | System metadata: `width`/`height` for images (GIN-indexed on PostgreSQL) |

### `media_conversions`

| Column | Type | Description |
|--------|------|-------------|
| `media_id` | bigint | FK → `media.id` (cascade delete) |
| `name` | string | Conversion name (`thumb`, `medium`, …) |
| `status` | string | `pending` · `processing` · `done` · `failed` |
| `disk` | string | Disk where this conversion is stored |
| `extension` | string | File extension for this conversion |
| `size` | bigint | Conversion file size in bytes |
| `properties` | json | System metadata: `width`/`height` for image conversions (GIN-indexed on PostgreSQL) |
| `error_message` | text | Populated when status = `failed` |
| `completed_at` | timestamp | Set when status = `done` |

## Overriding the Media model

Create a subclass and point the config at it:

```php
// app/Models/Media.php
class Media extends \Jurager\Media\Models\Media
{
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'mediable_id')
            ->where('mediable_type', 'product');
    }
}
```

```php
// config/media.php
'models' => [
    'media' => \App\Models\Media::class,
],
```
