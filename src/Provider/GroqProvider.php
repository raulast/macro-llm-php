<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

final class GroqProvider extends OpenAICompatibleProvider
{
    public function name(): string
    {
        return 'groq';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.groq.com/openai/v1';
    }

    public function getModels(): array
    {
        return [
            'llama-3.3-70b-versatile',
            'llama-3.1-70b-versatile',
            'llama-3.1-8b-instant',
            'llama-3.2-90b-vision-preview',
            'llama-3.2-11b-vision-preview',
            'llama-3.2-3b-preview',
            'llama-3.2-1b-preview',
            'mixtral-8x7b-32768',
            'gemma2-9b-it',
            'gemma-7b-it',
        ];
    }
}
