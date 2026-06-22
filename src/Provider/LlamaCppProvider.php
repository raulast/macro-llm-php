<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

final class LlamaCppProvider extends OpenAICompatibleProvider
{
    public function name(): string
    {
        return 'llamacpp';
    }

    protected function defaultBaseUrl(): string
    {
        return 'http://localhost:8080/v1';
    }

    /**
     * No auth at all for llama.cpp.
     */
    public function headers(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    /**
     * No API key required — returns empty string.
     */
    protected function requireApiKey(): string
    {
        return '';
    }

    /**
     * Returns the currently loaded model from llama.cpp's /v1/models endpoint.
     * Reflects the single model loaded at server startup.
     *
     * @return string[]
     */
    public function getModels(): array
    {
        return parent::getModels();
    }
}
