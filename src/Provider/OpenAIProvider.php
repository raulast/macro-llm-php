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
}
