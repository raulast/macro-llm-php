<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

final class XAIProvider extends OpenAICompatibleProvider
{
    public function name(): string
    {
        return 'xai';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.x.ai/v1';
    }
}
