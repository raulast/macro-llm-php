<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Contract\EmbeddingProviderInterface;

final class LlamaCppProvider extends OpenAICompatibleProvider implements EmbeddingProviderInterface
{
    use OpenAICapabilitiesTrait;

    public function name(): string
    {
        return 'llamacpp';
    }

    protected function defaultBaseUrl(): string
    {
        return 'http://localhost:8080/v1';
    }

    public function headers(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    protected function requireApiKey(): string
    {
        return '';
    }
}
