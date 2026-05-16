<?php

namespace Jurager\Media\Conversions;

class Conversion
{
    protected int $width = 0;
    protected int $height = 0;
    protected string $fitMethod = 'scale'; // scale | cover | contain
    protected int $quality = 80;
    protected string $format = ''; // '', 'jpg', 'webp', 'png', 'gif', 'avif'
    protected bool $queued = true;
    protected string $queueName = '';
    protected array $collections = [];

    public function __construct(public readonly string $name) {}

    public function width(int $width): static
    {
        $this->width = $width;

        return $this;
    }

    public function height(int $height): static
    {
        $this->height = $height;

        return $this;
    }

    public function fit(int $width, int $height): static
    {
        $this->width = $width;
        $this->height = $height;
        $this->fitMethod = 'cover';

        return $this;
    }

    public function contain(int $width, int $height): static
    {
        $this->width = $width;
        $this->height = $height;
        $this->fitMethod = 'contain';

        return $this;
    }

    public function quality(int $quality): static
    {
        $this->quality = $quality;

        return $this;
    }

    public function format(string $format): static
    {
        $this->format = strtolower($format);

        return $this;
    }

    public function nonQueued(): static
    {
        $this->queued = false;

        return $this;
    }

    /**
     * Dispatch this conversion to a specific queue instead of the default one.
     * Has no effect when combined with nonQueued().
     */
    public function onQueue(string $queue): static
    {
        $this->queueName = $queue;

        return $this;
    }

    public function performOnCollections(string ...$collections): static
    {
        $this->collections = $collections;

        return $this;
    }

    public function shouldBePerformedOn(string $collection): bool
    {
        return empty($this->collections) || in_array($collection, $this->collections, true);
    }

    public function isQueued(): bool { return $this->queued; }

    public function getQueue(): string { return $this->queueName ?: config('media.queue', 'default'); }

    public function getWidth(): int { return $this->width; }

    public function getHeight(): int { return $this->height; }

    public function getFitMethod(): string { return $this->fitMethod; }

    public function getQuality(): int { return $this->quality; }

    public function getFormat(): string { return $this->format; }
}
