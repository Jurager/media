<?php

namespace Jurager\Media\Support;

use Jurager\Media\Models\Media;

class PathGenerator
{
    /**
     * Base path for the original file: "{model}/{mediable_id}/{collection}/{media_id}/"
     *
     * The media id segment isolates every record into its own directory, so two
     * files with the same name never collide and deleting one media never touches
     * the files of its siblings in the same collection.
     */
    public function getPath(Media $media): string
    {
        return $this->getBasePath($media).'/';
    }

    /**
     * Path for generated conversions: "{model}/{mediable_id}/{collection}/{media_id}/conversions/"
     */
    public function getPathForConversions(Media $media): string
    {
        return $this->getBasePath($media).'/conversions/';
    }

    protected function getBasePath(Media $media): string
    {
        $type = strtolower(class_basename($media->mediable_type));

        return "{$type}/{$media->mediable_id}/{$media->collection_name}/{$media->getKey()}";
    }
}
