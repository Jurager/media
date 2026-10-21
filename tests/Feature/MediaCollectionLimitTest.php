<?php

namespace Jurager\Media\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Jurager\Media\Models\Media;
use Jurager\Media\Tests\Fixtures\Product;
use Jurager\Media\Tests\TestCase;

class MediaCollectionLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_single_file_keeps_the_new_upload_and_deletes_the_previous_file(): void
    {
        $product = Product::create();
        $product->getMediaCollection('gallery')->singleFile();
        $first = $this->upload($product, 'first.txt');

        $this->assertSame([$first->id], $product->getMedia('gallery')->modelKeys());
        Storage::disk('local')->assertExists($first->getPath());

        $second = $this->upload($product, 'second.txt');

        $this->assertModelMissing($first);
        $this->assertModelExists($second);
        Storage::disk('local')->assertMissing($first->getPath());
        Storage::disk('local')->assertExists($second->getPath());
        $this->assertSame([$second->id], $product->getMedia('gallery')->modelKeys());
    }

    public function test_only_keep_latest_prunes_only_the_owners_target_collection(): void
    {
        $product = Product::create();
        $product->getMediaCollection('gallery')->onlyKeepLatest(2);
        $otherCollection = $this->upload($product, 'attachment.txt', 'attachments');
        $otherOwner = $this->upload(Product::create(), 'other-owner.txt');
        $uploads = [];

        foreach (['first.txt', 'second.txt', 'third.txt', 'fourth.txt'] as $name) {
            $uploads[] = $this->upload($product, $name);
            $expected = array_slice($uploads, -2);

            $this->assertSame(
                array_map(fn (Media $media) => $media->id, $expected),
                $product->getMedia('gallery')->modelKeys(),
            );

            foreach ($expected as $media) {
                $this->assertModelExists($media);
                Storage::disk('local')->assertExists($media->getPath());
            }
        }

        foreach (array_slice($uploads, 0, -2) as $media) {
            $this->assertModelMissing($media);
            Storage::disk('local')->assertMissing($media->getPath());
        }

        foreach ([$otherCollection, $otherOwner] as $media) {
            $this->assertModelExists($media);
            Storage::disk('local')->assertExists($media->getPath());
        }
    }

    private function upload(Product $product, string $name, string $collection = 'gallery'): Media
    {
        return $product->addMedia(UploadedFile::fake()->createWithContent($name, $name))
            ->toMediaCollection($collection);
    }
}
