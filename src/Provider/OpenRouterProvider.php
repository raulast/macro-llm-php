<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Contract\EmbeddingProviderInterface;

final class OpenRouterProvider extends OpenAICompatibleProvider implements EmbeddingProviderInterface
{
    use OpenAICapabilitiesTrait;

    public function name(): string
    {
        return 'openrouter';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://openrouter.ai/api/v1';
    }

    public function headers(): array
    {
        $headers = parent::headers();
        if (isset($this->config->extraHeaders['HTTP-Referer'])) {
            $headers['HTTP-Referer'] = $this->config->extraHeaders['HTTP-Referer'];
        }
        if (isset($this->config->extraHeaders['X-Title'])) {
            $headers['X-Title'] = $this->config->extraHeaders['X-Title'];
        }
        return $headers;
    }

    protected function mapModel(string $model): string
    {
        return $model;
    }
}
