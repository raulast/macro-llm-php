<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

/**
 * OpenCode Zen Go — Anthropic-compatible endpoint.
 *
 * Endpoint: https://opencode.ai/zen/go/v1/messages
 * Models:   MiniMax M3, MiniMax M2.7, MiniMax M2.5,
 *           Qwen3.7 Max, Qwen3.7 Plus, Qwen3.6 Plus
 *
 * API key:  Same Zen console key as OpenCodeZenGoProvider.
 * Auth:     x-api-key header (Anthropic-compatible protocol).
 *
 * For GLM, Kimi, DeepSeek, MiMo models, use OpenCodeZenGoProvider instead.
 */
final class OpenCodeZenGoAnthropicProvider extends AnthropicProvider
{
    public function name(): string
    {
        return 'opencode-zen-go-anthropic';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://opencode.ai';
    }

    public function endpointPath(): string
    {
        return '/zen/go/v1/messages';
    }

    /**
     * Fetches models from the Zen Go endpoint.
     * Falls back to a static list of known Anthropic-compatible models.
     *
     * @return string[]
     */
    public function getModels(): array
    {
        $response = $this->fetchRawModels('/zen/go/v1/models');
        $models = array_values(array_filter(array_column($response['data'] ?? [], 'id')));

        if (!empty($models)) {
            return $models;
        }

        return [
            'MiniMax-M3',
            'MiniMax-M2.7',
            'MiniMax-M2.5',
            'Qwen3.7-Max',
            'Qwen3.7-Plus',
            'Qwen3.6-Plus',
        ];
    }
}
