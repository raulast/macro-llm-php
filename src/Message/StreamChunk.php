<?php

declare(strict_types=1);

namespace MacroLLM\Message;

final class StreamChunk
{
    public function __construct(
        public readonly string $delta,
        public readonly int $index,
        public readonly bool $finished = false,
        public readonly ?InternalResponse $response = null,
    ) {}
}
