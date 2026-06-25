<?php

declare(strict_types=1);

namespace MacroLLM\Message;

final class RerankingResponse
{
    /** @param RankedDocument[] $results */
    public function __construct(
        public readonly array $results,
    ) {}

    public function first(): ?RankedDocument
    {
        return $this->results[0] ?? null;
    }
}
