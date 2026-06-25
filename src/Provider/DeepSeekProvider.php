<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

final class DeepSeekProvider extends OpenAICompatibleProvider
{
    public function name(): string
    {
        return 'deepseek';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.deepseek.com/v1';
    }

    public function getModels(): array
    {
        // DeepSeek doesn't have a stable /models endpoint — return known models
        return ['deepseek-chat', 'deepseek-reasoner'];
    }
}
