<?php

namespace Jurager\Media\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Jurager\Media\Concerns\HasMedia;
use Jurager\Media\Contracts\InteractsWithMedia;

/** Plain model — no EAV, no Attributable — proves HasMedia works without jurager/eav in the picture. */
class Product extends Model implements InteractsWithMedia
{
    use HasMedia;

    protected $table = 'products';

    protected $guarded = [];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('gallery');
    }
}
