<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Message\EmbeddingRequest;
use MacroLLM\Message\EmbeddingResponse;
use MacroLLM\Message\Usage;

/**
 * Ollama embedding provider.
 * Uses the OpenAI-compatible /embeddings endpoint exposed by Ollama.
 * Requires a model with embedding support (e.g. nomic-embed-text, mxbai-embed-large).
 */
final class OllamaEmbeddingProvider implements EmbeddingProviderInterface
{
    use ProviderHttpClientTrait;

    public function __construct(
        private readonly ProviderConfig $config,
        // Test seam: see ProviderHttpClientTrait. `@internal` — never set by production code.
        private readonly ?\Closure $httpHandlerFactory = null,
    ) {}

    public function name(): string
    {
        return 'ollama';
    }

    public function baseUrl(): string
    {
        return rtrim($this->config->baseUrl ?? 'http://localhost:11434/v1', '/');
    }

    public function headers(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $response = $this->httpClient($this->config->timeout ?? 30)->post('/embeddings', [
            'model' => $request->model ?? $this->config->defaultModel,
            'input' => $request->inputs,
        ]);

        $embeddings = array_map(
            fn(array $d) => $d['embedding'],
            $response['data'] ?? [],
        );

        return new EmbeddingResponse($embeddings, new Usage());
    }
}
