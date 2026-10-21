<?php

namespace Jurager\Media\Tests\Feature;

use Illuminate\Support\Facades\DB;
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
}
