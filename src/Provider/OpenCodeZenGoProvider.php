<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

/**
 * OpenCode Zen Go — OpenAI-compatible endpoint.
 *
 * Endpoint: https://opencode.ai/zen/go/v1/chat/completions
 * Models:   GLM-5.2, GLM-5.1, Kimi K2.7, Kimi K2.6,
 *           DeepSeek V4 Pro, DeepSeek V4 Flash, MiMo-V2.5, MiMo-V2.5-Pro
 *
 * API key:  Obtain from https://opencode.ai (Zen console)
 * Auth:     Bearer token (same key for both OpenCode Zen Go providers)
 *
 * For MiniMax and Qwen models, use OpenCodeZenGoAnthropicProvider instead.
 */
final class OpenCodeZenGoProvider extends OpenAICompatibleProvider
{
    public function name(): string
    {
        return 'opencode-zen-go';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://opencode.ai';
    }

    public function endpointPath(): string
    {
        return '/zen/go/v1/chat/completions';
    }

    /**
     * Fetches all available Go models from the Zen Go models endpoint.
     * Includes both OpenAI-compatible and Anthropic-compatible model IDs.
     *
     * @return string[]
     */
    public function getModels(): array
    {
        $response = $this->fetchRawModels('/zen/go/v1/models');
        return array_values(array_filter(array_column($response['data'] ?? [], 'id')));
    }
}
