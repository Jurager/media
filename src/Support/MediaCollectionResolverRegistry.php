<?php

namespace Jurager\Media\Support;

use Jurager\Media\Contracts\DynamicMediaCollectionResolver;

/** Dynamic collection resolvers applied to every model using HasMedia, regardless of class. */
class MediaCollectionResolverRegistry
{
    /** @var DynamicMediaCollectionResolver[] */
    protected array $resolvers = [];

    public function register(DynamicMediaCollectionResolver $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    /** @return DynamicMediaCollectionResolver[] */
    public function all(): array
    {
        return $this->resolvers;
    }
}
