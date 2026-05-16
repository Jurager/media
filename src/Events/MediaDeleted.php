<?php

namespace Jurager\Media\Events;

use Jurager\Media\Models\Media;

class MediaDeleted
{
    public function __construct(public readonly Media $media) {}
}
