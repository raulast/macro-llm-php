<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Contract\ImageProviderInterface;

final class XAIProvider extends OpenAICompatibleProvider implements
    EmbeddingProviderInterface,
    ImageProviderInterface
{
    use OpenAICapabilitiesTrait;

    public function name(): string
    {
        return 'xai';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.x.ai/v1';
    }
}
