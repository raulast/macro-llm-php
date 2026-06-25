<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Config\ProviderConfig;

/**
 * Azure OpenAI — OpenAI-compatible, but auth uses `api-key` header (not Bearer).
 * Endpoint: https://{resource}.openai.azure.com/openai/deployments/{deployment}/chat/completions?api-version={version}
 */
final class AzureOpenAIProvider extends OpenAICompatibleProvider
{
    public function name(): string
    {
        return 'azure';
    }

    protected function defaultBaseUrl(): string
    {
        $resource   = $this->config->extraHeaders['resource'] ?? 'my-resource';
        $deployment = $this->config->extraHeaders['deployment'] ?? 'gpt-4o';
        return "https://{$resource}.openai.azure.com/openai/deployments/{$deployment}";
    }

    public function endpointPath(): string
    {
        $version = $this->config->extraHeaders['api_version'] ?? '2024-02-01';
        return "/chat/completions?api-version={$version}";
    }

    public function headers(): array
    {
        return array_merge([
            'api-key'      => $this->requireApiKey(),
            'Content-Type' => 'application/json',
        ], $this->config->extraHeaders);
    }

    public function getModels(): array
    {
        // Azure doesn't have a standard models endpoint — return empty
        return [];
    }
}
