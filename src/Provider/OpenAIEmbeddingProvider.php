<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Exception\MissingApiKeyException;
use MacroLLM\Exception\ProviderRequestException;
use MacroLLM\Http\HttpClient;
use MacroLLM\Message\EmbeddingRequest;
use MacroLLM\Message\EmbeddingResponse;
use MacroLLM\Message\Usage;

final class OpenAIEmbeddingProvider implements EmbeddingProviderInterface
{
    public function __construct(
        private readonly ProviderConfig $config,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $apiKey = $this->config->apiKey;
        if (!$apiKey) {
            throw new MissingApiKeyException('openai');
        }

        $payload = [
            'model' => $request->model ?? $this->config->defaultModel,
            'input' => $request->inputs,
        ];
        if ($request->dimensions !== null) {
            $payload['dimensions'] = $request->dimensions;
        }

        $response = (new HttpClient(
            $this->config->baseUrl ?? 'https://api.openai.com/v1',
            ['Authorization' => 'Bearer ' . $apiKey, 'Content-Type' => 'application/json'],
            $this->config->timeout ?? 30,
        ))->post('/embeddings', $payload);

        $embeddings = array_map(
            fn(array $d) => $d['embedding'],
            $response['data'] ?? [],
        );

        $usage = new Usage(
            promptTokens:     $response['usage']['prompt_tokens'] ?? 0,
            completionTokens: 0,
            totalTokens:      $response['usage']['total_tokens'] ?? 0,
        );

        return new EmbeddingResponse($embeddings, $usage);
    }
}
