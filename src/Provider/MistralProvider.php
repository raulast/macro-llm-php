<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\EmbeddingProviderInterface;

final class MistralProvider extends OpenAICompatibleProvider implements
    EmbeddingProviderInterface,
    AudioProviderInterface
{
    use OpenAICapabilitiesTrait;

    public function name(): string
    {
        return 'mistral';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.mistral.ai/v1';
    }
}
