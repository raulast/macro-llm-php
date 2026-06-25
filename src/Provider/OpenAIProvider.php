<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Contract\ImageProviderInterface;

final class OpenAIProvider extends OpenAICompatibleProvider implements
    EmbeddingProviderInterface,
    ImageProviderInterface,
    AudioProviderInterface
{
    use OpenAICapabilitiesTrait;

    public function name(): string
    {
        return 'openai';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.openai.com/v1';
    }

    public function getModels(): array
    {
        return [
            'gpt-4o', 'gpt-4o-2024-11-20', 'gpt-4o-2024-08-06',
            'gpt-4o-mini', 'gpt-4o-mini-2024-07-18',
            'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo',
            'o1', 'o1-mini', 'o3', 'o3-mini',
        ];
    }
}
