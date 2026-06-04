<?php

namespace Jurager\Media\Concerns;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;

/**
 * Test-only assertions for models using the HasMedia trait.
 *
 * Kept separate from HasMedia so production code never depends on phpunit/phpunit
 * (a dev-only requirement). Add this trait to a model — or a test-only subclass —
 * when you want fluent media assertions in your test suite.
 *
 * @method Collection getMedia(string $collection = 'default')
 */
trait InteractsWithMediaAssertions
{
    public function assertHasMedia(string $collection = 'default', ?int $count = null): void
    {
        $media = $this->getMedia($collection);

        Assert::assertTrue(
            $media->isNotEmpty(),
            "Expected [{$collection}] collection to have media, but it is empty.",
        );

        if ($count !== null) {
            Assert::assertCount(
                $count,
                $media,
                "Expected [{$collection}] to have {$count} item(s), got {$media->count()}.",
            );
        }
    }

    public function assertHasNoMedia(string $collection = 'default'): void
    {
        $media = $this->getMedia($collection);

        Assert::assertTrue(
            $media->isEmpty(),
            "Expected [{$collection}] to be empty, but it has {$media->count()} item(s).",
        );
    }

    public function assertMediaCount(string $collection, int $count): void
    {
        $media = $this->getMedia($collection);

        Assert::assertCount(
            $count,
            $media,
            "Expected [{$collection}] to have {$count} item(s), got {$media->count()}.",
        );
    }
}
