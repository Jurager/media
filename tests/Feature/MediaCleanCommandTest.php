<?php

namespace Jurager\Media\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Jurager\Media\Models\Media;
use Jurager\Media\Support\MediaCollectionResolverRegistry;
use Jurager\Media\Tests\Fixtures\Product;
use Jurager\Media\Tests\TestCase;

class MediaCleanCommandTest extends TestCase
{
    private function attach(Product $product, string $collection): Media
    {
        $media = new Media([
            'collection_name' => $collection,
            'name' => 'file',
            'file_name' => 'file.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'local',
            'size' => 1,
        ]);
        $media->mediable_type = $product->getMorphClass();
        $media->mediable_id = $product->id;
        $media->uuid = (string) Str::uuid();
        $media->save();

        return $media;
    }

    public function test_static_collections_survive_media_clean_without_eav(): void
    {
        $product = Product::create();
        $kept = $this->attach($product, 'gallery');
        $deleted = $this->attach($product, 'not_a_real_collection');

        Artisan::call('media:clean', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString("#{$product->id} — file.jpg (collection is not registered)", $output);
        $this->assertStringNotContainsString("#{$product->id} — file.jpg)\n", $output);

        Artisan::call('media:clean');

        $this->assertModelExists($kept);
        $this->assertModelMissing($deleted);
    }

    public function test_get_media_collection_names_and_resolver_registry_work_without_any_resolver_registered(): void
    {
        $product = new Product;

        $this->assertSame(['gallery'], $product->getMediaCollectionNames());
        $this->assertSame([], app(MediaCollectionResolverRegistry::class)->all());
    }
}
