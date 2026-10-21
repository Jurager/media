<?php

namespace Jurager\Media\Tests\Feature;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jurager\Media\Models\Media;
use Jurager\Media\Tests\Fixtures\Product;
use Jurager\Media\Tests\TestCase;

class MediaLoadingTest extends TestCase
{
    public function test_get_media_preserves_eloquent_batch_autoloading(): void
    {
        foreach (range(1, 15) as $i) {
            Product::create();
        }

        $products = Product::query()->get()->withRelationshipAutoloading();
        DB::enableQueryLog();

        foreach ($products as $product) {
            $product->getMedia();
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
    }

    public function test_conversion_urls_batch_load_conversions_for_the_media_collection(): void
    {
        config(['media.cdn_url' => 'https://media.example.test']);
        $product = Product::create();

        foreach (range(1, 15) as $id) {
            $media = Media::query()->forceCreate([
                'uuid' => (string) Str::uuid(),
                'mediable_type' => $product->getMorphClass(), 'mediable_id' => $product->id,
                'collection_name' => 'gallery', 'name' => 'Image '.$id,
                'file_name' => 'image-'.$id.'.jpg', 'mime_type' => 'image/jpeg',
                'disk' => 'local', 'size' => 100, 'order_column' => $id,
            ]);
            foreach (['thumb', 'webp'] as $name) {
                $media->conversions()->create([
                    'name' => $name, 'status' => 'done', 'disk' => 'local',
                    'extension' => 'webp', 'size' => 50,
                ]);
            }
        }

        $media = Media::query()->get()->withRelationshipAutoloading();
        DB::enableQueryLog();
        DB::flushQueryLog();

        foreach ($media as $item) {
            $this->assertStringEndsWith('image-'.$item->id.'-thumb.webp', $item->getUrl('thumb'));
            $this->assertStringEndsWith('image-'.$item->id.'-webp.webp', $item->getUrl('webp'));
            $this->assertStringEndsWith('image-'.$item->id.'.jpg', $item->getUrl('missing'));
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, $queries, 'Conversion lookup must preserve collection autoloading.');
    }

    public function test_conversion_status_lists_batch_load_and_refresh_after_generation(): void
    {
        config(['media.cdn_url' => 'https://media.example.test']);
        $product = Product::create();
        foreach (range(1, 15) as $id) {
            $media = Media::query()->forceCreate([
                'uuid' => (string) Str::uuid(),
                'mediable_type' => $product->getMorphClass(), 'mediable_id' => $product->id,
                'collection_name' => 'gallery', 'name' => 'Image '.$id,
                'file_name' => 'image-'.$id.'.jpg', 'mime_type' => 'image/jpeg',
                'disk' => 'local', 'size' => 100, 'order_column' => $id,
            ]);
            foreach (['pending', 'failed'] as $status) {
                $media->conversions()->create([
                    'name' => $status, 'status' => $status, 'disk' => 'local',
                ]);
            }
        }
        $media = Media::query()->get()->withRelationshipAutoloading();
        DB::enableQueryLog();
        DB::flushQueryLog();

        foreach ($media as $item) {
            $this->assertSame(['pending'], $item->pendingConversions());
            $this->assertSame(['failed'], $item->failedConversions());
        }

        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, $queries);

        $first = $media->first();
        $this->assertStringEndsWith('image-1.jpg', $first->getUrl('pending'));
        $first->markConversionAsGenerated('pending', 'avif');
        $this->assertSame([], $first->pendingConversions());
        $this->assertTrue($first->hasGeneratedConversion('pending'));
        $this->assertStringEndsWith('image-1-pending.avif', $first->getUrl('pending'));
    }

    public function test_get_media_uses_the_current_relation_and_preserves_order(): void
    {
        $first = new Media(['collection_name' => 'gallery', 'name' => 'First']);
        $last = new Media(['collection_name' => 'gallery', 'name' => 'Last']);
        $other = new Media(['collection_name' => 'attachments']);
        $product = (new Product)->setRelation('media', new Collection([
            $first, $other, $last,
        ]));

        $this->assertSame([$first, $last], $product->getMedia('gallery')->all());
        $this->assertSame([], $product->getMedia('missing')->all());
        $product->setRelation('media', new Collection([$last]));
        $this->assertSame([$last], $product->getMedia('gallery')->all());
    }
}
