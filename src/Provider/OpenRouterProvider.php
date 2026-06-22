<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

final class OpenRouterProvider extends OpenAICompatibleProvider
{
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

        $extraHeaders = $this->config->extraHeaders;

        if (isset($extraHeaders['HTTP-Referer'])) {
            $headers['HTTP-Referer'] = $extraHeaders['HTTP-Referer'];
        }

        if (isset($extraHeaders['X-Title'])) {
            $headers['X-Title'] = $extraHeaders['X-Title'];
        }

        return $headers;
    }

    /**
     * OpenRouter accepts model names as-is (provider-prefixed like "openai/gpt-4o").
     * No transformation needed — pass through unchanged.
     */
    protected function mapModel(string $model): string
    {
        return $model;
    }
}
