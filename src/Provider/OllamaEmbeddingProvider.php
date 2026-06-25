<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Http\HttpClient;
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
    public function __construct(
        private readonly ProviderConfig $config,
    ) {}

    public function name(): string
    {
        return 'ollama';
    }

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $baseUrl = rtrim($this->config->baseUrl ?? 'http://localhost:11434/v1', '/');

        $response = (new HttpClient(
            $baseUrl,
            ['Content-Type' => 'application/json'],
            $this->config->timeout ?? 30,
        ))->post('/embeddings', [
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
