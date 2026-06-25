<?php

declare(strict_types=1);

namespace MacroLLM\Message;

final class RankedDocument
{
    public function __construct(
        public readonly int $index,
        public readonly string $document,
        public readonly float $score,
    ) {}
}
