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
}
