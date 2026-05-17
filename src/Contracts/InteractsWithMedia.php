<?php

namespace Jurager\Media\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Jurager\Media\Conversions\Conversion;
use Jurager\Media\MediaCollection;
use Jurager\Media\Models\Media;
use Jurager\Media\Support\FileAdder;

interface InteractsWithMedia
{
    // ─── Uploading ───────────────────────────────────────────────────────────

    public function addMedia(mixed $file): FileAdder;

    public function addMediaFromUrl(string $url, array $headers = []): FileAdder;

    public function addMediaFromBase64(string $base64, string $mimeType = ''): FileAdder;

    public function addMediaFromDisk(string $path, string $disk): FileAdder;

    // ─── Copying ─────────────────────────────────────────────────────────────

    public function copyMediaFrom(object $source, string|array|null $collections = null): void;

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeWithMedia(Builder $query, string|array|null $collections = null): Builder;

    // ─── Retrieval ───────────────────────────────────────────────────────────

    public function getMedia(string $collection = 'default'): Collection;

    public function getFirstMedia(string $collection = 'default'): ?Media;

    public function getLastMedia(string $collection = 'default'): ?Media;

    public function getFirstMediaUrl(string $collection = 'default', string $conversion = ''): string;

    public function getLastMediaUrl(string $collection = 'default', string $conversion = ''): string;

    public function hasMedia(string $collection = 'default'): bool;

    // ─── Ordering ────────────────────────────────────────────────────────────

    public function reorderMedia(string $collection, array $orderedIds): void;

    // ─── Cleanup ─────────────────────────────────────────────────────────────

    public function clearMediaCollection(string $collection = 'default'): static;

    public function clearMediaCollectionExcept(string $collection = 'default', Media|iterable $except = []): static;

    // ─── Conversions & Collections ───────────────────────────────────────────

    public function registerMediaConversions(Media $media): void;

    public function registerMediaCollections(): void;

    public function addMediaConversion(string $name): Conversion;

    public function addMediaCollection(string $name): MediaCollection;

    public function getRegisteredMediaConversions(): array;

    public function getMediaCollection(string $name): ?MediaCollection;
}
