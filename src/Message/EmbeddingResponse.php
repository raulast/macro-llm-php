<?php

declare(strict_types=1);

namespace MacroLLM\Message;

final class EmbeddingResponse
{
    /**
     * @param float[][] $embeddings  One float[] per input
     */
    public function __construct(
        public readonly array $embeddings,
        public readonly Usage $usage = new Usage(),
    ) {}
}
