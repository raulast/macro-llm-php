<?php

declare(strict_types=1);

namespace MacroLLM\Contract;

use MacroLLM\Message\EmbeddingRequest;
use MacroLLM\Message\EmbeddingResponse;

interface EmbeddingProviderInterface
{
    public function name(): string;
    public function embed(EmbeddingRequest $request): EmbeddingResponse;
}
