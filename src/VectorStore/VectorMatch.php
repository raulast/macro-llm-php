<?php

declare(strict_types=1);

namespace MacroLLM\VectorStore;

/**
 * One search hit: which entry, how close, and whatever was stored beside it.
 *
 * The metadata travels with the match because a retriever is almost never useful without it — the id locates the
 * document, but the metadata is usually the text, the source or the permission tag the caller needs next.
 */
final class VectorMatch
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $id,
        public readonly float $score,
        public readonly array $metadata = [],
    ) {}

    /** @return array{id: string, score: float, metadata: array<string, mixed>} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'score' => $this->score, 'metadata' => $this->metadata];
    }
}
