<?php

namespace Jurager\Media\Events;

use Jurager\Media\Models\Media;

class MediaAdded
{
    public function __construct(public readonly Media $media) {}
}
