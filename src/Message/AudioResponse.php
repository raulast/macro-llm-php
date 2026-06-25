<?php

declare(strict_types=1);

namespace MacroLLM\Message;

final class AudioResponse
{
    public function __construct(
        public readonly string $content,  // raw binary audio
        public readonly string $format,   // 'mp3'|'opus'|'aac'|'flac'
    ) {}

    public function store(string $path): void
    {
        file_put_contents($path, $this->content);
    }
}
