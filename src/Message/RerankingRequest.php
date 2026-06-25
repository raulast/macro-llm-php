<?php

declare(strict_types=1);

namespace MacroLLM\Message;

final class RerankingRequest
{
    /**
     * @param string[] $documents
     */
    public function __construct(
        public readonly string $query,
        public readonly array $documents,
        public readonly ?int $limit = null,
        public readonly ?string $model = null,
    ) {}
}
