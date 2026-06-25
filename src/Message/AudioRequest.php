<?php

declare(strict_types=1);

namespace MacroLLM\Message;

final class AudioRequest
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $voice = null,        // 'alloy'|'echo'|'fable'|'onyx'|'nova'|'shimmer'
        public readonly ?string $instructions = null, // coaching the voice style
        public readonly ?string $format = null,       // 'mp3'|'opus'|'aac'|'flac' (default: mp3)
        public readonly ?string $model = null,
    ) {}
}
