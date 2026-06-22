<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Provider
    |--------------------------------------------------------------------------
    |
    | The provider name to use when no provider is explicitly specified in a
    | request. Must match one of the keys defined in the 'providers' array.
    |
    */

    'default_provider' => env('MACRO_LLM_DEFAULT_PROVIDER', 'ollama'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Global request timeout in seconds. Individual provider configs can
    | override this value.
    |
    */

    'timeout' => 30,

    /*
    |--------------------------------------------------------------------------
    | Retry Count
    |--------------------------------------------------------------------------
    |
    | Number of automatic retries on failed requests. Individual provider
    | configs can override this value. Set to 0 to disable retries.
    |
    */

    'retries' => 0,

    /*
    |--------------------------------------------------------------------------
    | Max Tool Iterations
    |--------------------------------------------------------------------------
    |
    | Maximum number of tool-call loop iterations before the Agent throws a
    | MaxToolIterationsException. Prevents infinite loops when the model
    | continuously requests tool calls without reaching a stop condition.
    |
    */

    'max_tool_iterations' => 10,

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Configuration for each AI provider. Each entry is keyed by the provider
    | name and contains credentials, model defaults, and connection settings.
    |
    | Supported providers: openai, anthropic, gemini, groq, openrouter,
    |                      ollama, llamacpp, opencode-zen-go, opencode-zen-go-anthropic
    |
    | API keys support environment variable patterns: '${ENV_VAR_NAME}'
    | which are resolved lazily at access time via $_ENV or getenv().
    |
    */

    'providers' => [

        'openai' => [
            // API key for OpenAI. Supports ${ENV_VAR} pattern for lazy resolution.
            'api_key' => '${OPENAI_API_KEY}',

            // Default model identifier for this provider.
            'default_model' => 'gpt-4o',

            // Base URL override. Useful for Azure OpenAI or proxy endpoints.
            // 'base_url' => null,

            // Per-provider timeout in seconds (overrides global timeout).
            // 'timeout' => 30,

            // Per-provider retry count (overrides global retries).
            // 'retries' => 0,

            // Additional headers sent with every request to this provider.
            // 'extra_headers' => [],
        ],

        'anthropic' => [
            'api_key' => '${ANTHROPIC_API_KEY}',
            'default_model' => 'claude-sonnet-4-20250514',
            // 'base_url' => null,
            // 'timeout' => 30,
            // 'retries' => 0,
            // 'extra_headers' => [],
        ],

        'gemini' => [
            'api_key' => '${GEMINI_API_KEY}',
            'default_model' => 'gemini-2.0-flash',
            // 'base_url' => null,
            // 'timeout' => 30,
            // 'retries' => 0,
            // 'extra_headers' => [],
        ],

        'groq' => [
            'api_key' => '${GROQ_API_KEY}',
            'default_model' => 'llama-3.3-70b-versatile',
            // 'base_url' => null,
            // 'timeout' => 30,
            // 'retries' => 0,
            // 'extra_headers' => [],
        ],

        'openrouter' => [
            'api_key' => '${OPENROUTER_API_KEY}',
            'default_model' => 'openai/gpt-4o',
            // 'base_url' => null,
            // 'timeout' => 30,
            // 'retries' => 0,
            // 'extra_headers' => [],
        ],

        'ollama' => [
            // API key is optional for Ollama (local inference).
            'api_key' => null,
            'default_model' => 'llama3.2',
            // 'base_url' => 'http://localhost:11434/v1',
            // 'timeout' => 120,
            // 'retries' => 0,
            // 'extra_headers' => [],
        ],

        'llamacpp' => [
            // No authentication required for llama.cpp server.
            'api_key' => null,
            'default_model' => 'default',
            // 'base_url' => 'http://localhost:8080/v1',
            // 'timeout' => 120,
            // 'retries' => 0,
            // 'extra_headers' => [],
        ],

        'opencode-zen-go' => [
            // API key from https://opencode.ai Zen console.
            // The same key works for both opencode-zen-go and opencode-zen-go-anthropic.
            'api_key' => '${OPENCODE_ZEN_API_KEY}',

            // Default model for OpenAI-compatible Go models (GLM, Kimi, DeepSeek, MiMo).
            'default_model' => 'deepseek-v3-0324',

            // Base URL is fixed — do not override.
            // 'base_url' => null,

            // 'timeout' => 30,
            // 'retries' => 0,
            // 'extra_headers' => [],
        ],

        'opencode-zen-go-anthropic' => [
            // Same API key as opencode-zen-go.
            'api_key' => '${OPENCODE_ZEN_API_KEY}',

            // Default model for Anthropic-compatible Go models (MiniMax, Qwen).
            'default_model' => 'MiniMax-M3',

            // 'timeout' => 30,
            // 'retries' => 0,
            // 'extra_headers' => [],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Servers
    |--------------------------------------------------------------------------
    |
    | Configuration for external MCP (Model Context Protocol) server
    | connections. Each entry is keyed by a unique server name used for
    | tool namespacing (e.g., "weather/get_forecast").
    |
    */

    'mcp_servers' => [
        // 'weather' => [
        //     'url' => 'https://mcp.example.com',
        //     'auth' => '${MCP_WEATHER_TOKEN}',
        // ],
    ],

];
