<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Exception\MissingApiKeyException;
use MacroLLM\Message\EmbeddingRequest;
use MacroLLM\Message\EmbeddingResponse;
use MacroLLM\Message\Usage;

final class CohereEmbeddingProvider implements EmbeddingProviderInterface
{
    use ProviderHttpClientTrait;

    public function __construct(
        private readonly ProviderConfig $config,
        // Test seam: see ProviderHttpClientTrait. `@internal` — never set by production code.
        private readonly ?\Closure $httpHandlerFactory = null,
    ) {}

    public function name(): string
    {
        return 'cohere';
    }

    public function baseUrl(): string
    {
        return $this->config->baseUrl ?? 'https://api.cohere.com/v2';
    }

    public function headers(): array
    {
        $apiKey = $this->config->apiKey;
        if (!$apiKey) {
            throw new MissingApiKeyException('cohere');
        }

        return ['Authorization' => 'Bearer ' . $apiKey, 'Content-Type' => 'application/json'];
    }

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $apiKey = $this->config->apiKey;
        if (!$apiKey) {
            throw new MissingApiKeyException('cohere');
        }

        $response = $this->httpClient($this->config->timeout ?? 30)->post('/embed', [
            'model'            => $request->model ?? $this->config->defaultModel,
            'texts'            => $request->inputs,
            'input_type'       => 'search_document',
            'embedding_types'  => ['float'],
        ]);

        $embeddings = $response['embeddings']['float'] ?? [];

        return new EmbeddingResponse($embeddings, new Usage());
    }
}
