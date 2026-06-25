<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

final class MistralProvider extends OpenAICompatibleProvider
{
    public function name(): string
    {
        return 'mistral';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.mistral.ai/v1';
    }
}
