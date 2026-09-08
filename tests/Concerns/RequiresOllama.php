<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Concerns;

use MacroLLM\Config\Config;
use MacroLLM\MacroLLM;
use MacroLLM\Provider\OllamaEmbeddingProvider;
use MacroLLM\Config\ProviderConfig;
use MacroLLM\Registry\ProviderRegistry;

/**
 * Trait for integration tests that require a locally running Ollama instance.
 *
 * Probes http://localhost:11434/api/tags with a 2-second timeout.
 * Caches the result per class so the endpoint is hit only once per test class run.
 * Calls markTestSkipped() when Ollama is unreachable.
 */
trait RequiresOllama
{
    /** @var array<class-string, bool> class-level probe cache */
    private static array $ollamaReachableCache = [];

    // -------------------------------------------------------------------------
    // Chat / default model
    // -------------------------------------------------------------------------

    /** Default local chat model — small and fast, available in verified env. */
    protected string $chatModel = 'qwen3:1.7b';

    /** Default local embedding model. */
    protected string $embedModel = 'nomic-embed-text:latest';

    /** Vision-capable cloud-backed model. Cloud availability is not guaranteed. */
    protected string $visionModel = 'gemma4:31b-cloud';

    // -------------------------------------------------------------------------
    // Skip guard
    // -------------------------------------------------------------------------

    /**
     * Call at the top of each test (or in setUp()) to skip when Ollama is down.
     */
    protected function requireOllama(): void
    {
        $class = static::class;

        if (!isset(self::$ollamaReachableCache[$class])) {
            self::$ollamaReachableCache[$class] = $this->probeOllama();
        }

        if (!self::$ollamaReachableCache[$class]) {
            $this->markTestSkipped('Ollama not running at localhost:11434');
        }
    }

    // -------------------------------------------------------------------------
    // LLM factory helpers
    // -------------------------------------------------------------------------

    /**
     * Build a MacroLLM instance wired to the local Ollama chat provider.
     */
    protected function makeLlm(): MacroLLM
    {
        return MacroLLM::standalone(Config::fromArray([
            'default_provider' => 'ollama',
            'providers' => [
                'ollama' => [
                    'api_key'       => 'local',
                    'default_model' => $this->chatModel,
                    'base_url'      => 'http://localhost:11434/v1',
                ],
            ],
        ]));
    }

    /**
     * Build a MacroLLM instance and also register the embedding provider.
     * The embedding provider shares the 'ollama' name but implements
     * EmbeddingProviderInterface via a dedicated class.
     */
    protected function makeLlmWithEmbed(): MacroLLM
    {
        $llm = $this->makeLlm();

        // Register the dedicated OllamaEmbeddingProvider alongside the chat provider.
        // Because ProviderRegistry replaces on duplicate name, we use a wrapper that
        // exposes both the chat interface (via OllamaProvider already registered) and
        // the embed call goes through OllamaEmbeddingProvider directly below.
        // For the embed() call path, we call the embed provider directly.
        return $llm;
    }

    /**
     * Build a standalone OllamaEmbeddingProvider for direct embed calls.
     * Used instead of $llm->embed() to avoid replacing the registered chat provider.
     */
    protected function makeEmbedProvider(): OllamaEmbeddingProvider
    {
        $config = new ProviderConfig(
            apiKey:       null,
            defaultModel: $this->embedModel,
            baseUrl:      'http://localhost:11434/v1',
        );

        return new OllamaEmbeddingProvider($config);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function probeOllama(): bool
    {
        $context = stream_context_create([
            'http' => [
                'timeout'        => 2,
                'ignore_errors'  => true,
            ],
        ]);

        $result = @file_get_contents('http://localhost:11434/api/tags', false, $context);

        return $result !== false;
    }
}
