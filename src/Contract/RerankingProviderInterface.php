<?php

declare(strict_types=1);

namespace MacroLLM\Contract;

use MacroLLM\Message\RerankingRequest;
use MacroLLM\Message\RerankingResponse;

interface RerankingProviderInterface
{
    public function name(): string;
    public function rerank(RerankingRequest $request): RerankingResponse;
}
