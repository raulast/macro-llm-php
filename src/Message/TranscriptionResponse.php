<?php

declare(strict_types=1);

namespace MacroLLM\Message;

final class TranscriptionResponse
{
    public function __construct(
        public readonly string $text,
        public readonly ?array $segments = null, // [{speaker, start, end, text}] for diarization
    ) {}

    public function __toString(): string
    {
        return $this->text;
    }
}
