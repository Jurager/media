<?php

namespace Jurager\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaConversion extends Model
{
    protected $fillable = [
        'media_id',
        'name',
        'status',
        'disk',
        'extension',
        'size',
        'properties',
        'error_message',
        'completed_at',
    ];

    protected $casts = [
        'properties'   => 'array',
        'size'         => 'integer',
        'completed_at' => 'datetime',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(config('media.models.media', Media::class));
    }

    public function isPending(): bool { return $this->status === 'pending'; }

    public function isProcessing(): bool { return $this->status === 'processing'; }

    public function isDone(): bool { return $this->status === 'done'; }

    public function isFailed(): bool { return $this->status === 'failed'; }

    public function getProperty(string $key, mixed $default = null): mixed
    {
        return ($this->properties ?? [])[$key] ?? $default;
    }

    /**
     * Image/video width in pixels. Null for non-visual conversions.
     */
    public function getWidth(): ?int
    {
        return $this->getProperty('width');
    }

    /**
     * Image/video height in pixels.
     */
    public function getHeight(): ?int
    {
        return $this->getProperty('height');
    }
}
