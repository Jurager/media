---
title: Quickstart
weight: 20
---

# Quickstart

## Make a model media-capable

Add the `HasMedia` trait and implement `InteractsWithMedia`:

```php
use Jurager\Media\Concerns\HasMedia;
use Jurager\Media\Contracts\InteractsWithMedia;
use Jurager\Media\Models\Media;

class Product extends Model implements InteractsWithMedia
{
    use HasMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->singleFile()
            ->withConversions(function (Media $media): void {
                $this->addMediaConversion('thumb')->fit(200, 200)->format('webp')->quality(85)->nonQueued();
                $this->addMediaConversion('medium')->width(800)->quality(80);
            });

        $this->addMediaCollection('gallery')
            ->withConversions(function (Media $media): void {
                $this->addMediaConversion('thumb')->fit(200, 200)->format('webp')->quality(85)->nonQueued();
                $this->addMediaConversion('medium')->width(800)->quality(80);
            });

        $this->addMediaCollection('documents')
            ->acceptsMimeTypes(['application/pdf'])
            ->maxFileSize(20 * 1024 * 1024)
            ->withConversions(function (Media $media): void {
                $this->addMediaConversion('preview')->width(800)->format('jpg')->quality(85);
            });
    }
}
```

## Upload a file

```php
// From an HTTP request
$product->addMedia($request->file('photo'))->toMediaCollection('image');

// From a remote URL (useful for imports)
$product->addMediaFromUrl('https://example.com/product.jpg')->toMediaCollection('gallery');

// From a base64 string (or data URI)
$product->addMediaFromBase64($request->input('image'), 'image/jpeg')
    ->toMediaCollection('image');
```

## Retrieve files

```php
$product->getFirstMediaUrl('image');           // original
$product->getFirstMediaUrl('image', 'thumb');  // thumbnail (falls back to original if pending)

$product->getMedia('gallery');                 // Collection of Media models
```

## Clean up on model delete

Register the cleanup in the model's `boot` or via an observer:

```php
protected static function booted(): void
{
    static::deleting(function (self $product): void {
        $product->clearMediaCollection('image');
        $product->clearMediaCollection('gallery');
        $product->clearMediaCollection('documents');
    });
}
```
