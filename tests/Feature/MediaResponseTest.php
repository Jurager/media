<?php

namespace Jurager\Media\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Jurager\Media\Models\Media;
use Jurager\Media\Tests\Fixtures\Product;
use Jurager\Media\Tests\TestCase;

class MediaResponseTest extends TestCase
{
    public function test_streamed_and_downloaded_files_cannot_be_sniffed_or_executed(): void
    {
        Storage::fake('local');
        $product = Product::create();

        $media = Media::query()->forceCreate([
            'uuid' => (string) Str::uuid(),
            'mediable_type' => $product->getMorphClass(), 'mediable_id' => $product->id,
            'collection_name' => 'gallery', 'name' => 'Page',
            'file_name' => 'page.html', 'mime_type' => 'text/html',
            'disk' => 'local', 'size' => 50, 'order_column' => 1,
        ]);
        Storage::disk('local')->put($media->getPath(), '<html><script>alert(1)</script></html>');

        foreach ([$media->stream(), $media->download()] as $response) {
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertSame("sandbox; default-src 'none'", $response->headers->get('Content-Security-Policy'));
        }
    }
}
