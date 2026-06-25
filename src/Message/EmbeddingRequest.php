<?php

declare(strict_types=1);

namespace MacroLLM\Message;

final class EmbeddingRequest
{
    /** @param string[] $inputs */
    public function __construct(
        public readonly array $inputs,
        public readonly ?int $dimensions = null,
        public readonly ?string $model = null,
    ) {}
}
