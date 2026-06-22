<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

final class OpenAIProvider extends OpenAICompatibleProvider
{
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
            'gpt-4o',
            'gpt-4o-2024-11-20',
            'gpt-4o-2024-08-06',
            'gpt-4o-mini',
            'gpt-4o-mini-2024-07-18',
            'gpt-4-turbo',
            'gpt-4-turbo-2024-04-09',
            'gpt-4-turbo-preview',
            'gpt-4',
            'gpt-3.5-turbo',
            'gpt-3.5-turbo-0125',
            'o1',
            'o1-2024-12-17',
            'o1-mini',
            'o1-mini-2024-09-12',
            'o1-preview',
            'o1-preview-2024-09-12',
            'o3',
            'o3-mini',
            'o3-mini-2025-01-31',
        ];
    }
}
