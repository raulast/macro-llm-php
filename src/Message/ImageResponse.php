<?php

declare(strict_types=1);

namespace MacroLLM\Message;

final class ImageResponse
{
    /**
     * @param string[] $images  Base64-encoded image data strings
     */
    public function __construct(
        public readonly array $images,
    ) {}

    /** Returns the first generated image (base64). */
    public function first(): ?string
    {
        return $this->images[0] ?? null;
    }

    /** Writes the first image to disk. Detects PNG/JPEG from base64 header. */
    public function store(string $path): void
    {
        file_put_contents($path, base64_decode($this->first() ?? ''));
    }
}
