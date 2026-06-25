<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\EmbeddingProviderInterface;

final class GroqProvider extends OpenAICompatibleProvider implements
    EmbeddingProviderInterface,
    AudioProviderInterface
{
    use OpenAICapabilitiesTrait;

    public function name(): string
    {
        return 'groq';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.groq.com/openai/v1';
    }
}
