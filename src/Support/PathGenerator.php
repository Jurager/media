<?php

namespace Jurager\Media\Support;

use Jurager\Media\Models\Media;

class PathGenerator
{
    /**
     * Base path for the original file: "{model}/{id}/{collection}/"
     */
    public function getPath(Media $media): string
    {
        return $this->getBasePath($media) . '/';
    }

    /**
     * Path for generated conversions: "{model}/{id}/{collection}/conversions/"
     */
    public function getPathForConversions(Media $media): string
    {
        return $this->getBasePath($media) . '/conversions/';
    }

    protected function getBasePath(Media $media): string
    {
        $type = strtolower(class_basename($media->mediable_type));

        return "{$type}/{$media->mediable_id}/{$media->collection_name}";
    }
}
