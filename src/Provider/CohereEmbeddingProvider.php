<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Exception\MissingApiKeyException;
use MacroLLM\Http\HttpClient;
use MacroLLM\Message\EmbeddingRequest;
use MacroLLM\Message\EmbeddingResponse;
use MacroLLM\Message\Usage;

final class CohereEmbeddingProvider implements EmbeddingProviderInterface
{
    public function __construct(
        private readonly ProviderConfig $config,
    ) {}

    public function name(): string
    {
        return 'cohere';
    }

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $apiKey = $this->config->apiKey;
        if (!$apiKey) {
            throw new MissingApiKeyException('cohere');
        }

        $response = (new HttpClient(
            $this->config->baseUrl ?? 'https://api.cohere.com/v2',
            ['Authorization' => 'Bearer ' . $apiKey, 'Content-Type' => 'application/json'],
            $this->config->timeout ?? 30,
        ))->post('/embed', [
            'model'            => $request->model ?? $this->config->defaultModel,
            'texts'            => $request->inputs,
            'input_type'       => 'search_document',
            'embedding_types'  => ['float'],
        ]);

        $embeddings = $response['embeddings']['float'] ?? [];

        return new EmbeddingResponse($embeddings, new Usage());
    }
}
