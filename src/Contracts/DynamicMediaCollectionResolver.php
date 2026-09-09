<?php

namespace Jurager\Media\Contracts;

use Jurager\Media\MediaCollection;

/** Resolves collections a model can't declare statically in registerMediaCollections() — register via addMediaCollectionResolver() or MediaCollectionResolverRegistry. */
interface DynamicMediaCollectionResolver
{
    /** Resolve one dynamic collection for $model, or null if $name isn't this resolver's concern. */
    public function resolve(object $model, string $name): ?MediaCollection;

    /** Every collection name this resolver could produce for $model's type, independent of any one instance's state. */
    public function names(object $model): array;
}
