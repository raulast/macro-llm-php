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

/**
 * Gemini embedding provider.
 * Uses batchEmbedContents API:
 * POST /models/{model}:batchEmbedContents
 */
final class GeminiEmbeddingProvider implements EmbeddingProviderInterface
{
    public function __construct(
        private readonly ProviderConfig $config,
    ) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $apiKey = $this->config->apiKey;
        if (!$apiKey) {
            throw new MissingApiKeyException('gemini');
        }

        $model = $request->model ?? $this->config->defaultModel;
        $baseUrl = rtrim($this->config->baseUrl ?? 'https://generativelanguage.googleapis.com/v1beta', '/');

        $requests = array_map(
            fn(string $input) => [
                'model'   => "models/{$model}",
                'content' => ['parts' => [['text' => $input]]],
            ],
            $request->inputs,
        );

        $payload = ['requests' => $requests];
        if ($request->dimensions !== null) {
            // Apply outputDimensionality to each request
            foreach ($payload['requests'] as &$req) {
                $req['outputDimensionality'] = $request->dimensions;
            }
        }

        $response = (new HttpClient(
            $baseUrl,
            ['Content-Type' => 'application/json', 'x-goog-api-key' => $apiKey],
            $this->config->timeout ?? 30,
        ))->post("/models/{$model}:batchEmbedContents", $payload);

        $embeddings = array_map(
            fn(array $e) => $e['values'],
            $response['embeddings'] ?? [],
        );

        return new EmbeddingResponse($embeddings, new Usage());
    }
}
