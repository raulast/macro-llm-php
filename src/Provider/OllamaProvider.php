<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Contract\EmbeddingProviderInterface;

final class OllamaProvider extends OpenAICompatibleProvider implements EmbeddingProviderInterface
{
    use OpenAICapabilitiesTrait;

    public function name(): string
    {
        return 'ollama';
    }

    protected function defaultBaseUrl(): string
    {
        return 'http://localhost:11434/v1';
    }

    /**
     * Auth is optional for Ollama — only include Bearer header if apiKey is set.
     */
    public function headers(): array
    {
        $headers = ['Content-Type' => 'application/json'];
        $key = $this->config->apiKey;
        if ($key !== null && $key !== '') {
            $headers['Authorization'] = 'Bearer ' . $key;
        }
        return $headers;
    }
}
