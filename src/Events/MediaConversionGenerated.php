<?php

namespace Jurager\Media\Events;

use Jurager\Media\Conversions\Conversion;
use Jurager\Media\Models\Media;

class MediaConversionGenerated
{
    public function __construct(
        public readonly Media $media,
        public readonly Conversion $conversion,
    ) {}
}
