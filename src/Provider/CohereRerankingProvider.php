<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\RerankingProviderInterface;
use MacroLLM\Exception\MissingApiKeyException;
use MacroLLM\Http\HttpClient;
use MacroLLM\Message\RankedDocument;
use MacroLLM\Message\RerankingRequest;
use MacroLLM\Message\RerankingResponse;

final class CohereRerankingProvider implements RerankingProviderInterface
{
    public function __construct(
        private readonly ProviderConfig $config,
    ) {}

    public function name(): string
    {
        return 'cohere';
    }

    public function rerank(RerankingRequest $request): RerankingResponse
    {
        $apiKey = $this->config->apiKey;
        if (!$apiKey) {
            throw new MissingApiKeyException('cohere');
        }

        $payload = [
            'model'     => $request->model ?? $this->config->defaultModel,
            'query'     => $request->query,
            'documents' => $request->documents,
        ];
        if ($request->limit !== null) {
            $payload['top_n'] = $request->limit;
        }

        $response = (new HttpClient(
            $this->config->baseUrl ?? 'https://api.cohere.com/v2',
            ['Authorization' => 'Bearer ' . $apiKey, 'Content-Type' => 'application/json'],
            $this->config->timeout ?? 30,
        ))->post('/rerank', $payload);

        $results = array_map(
            fn(array $r) => new RankedDocument(
                index:    $r['index'],
                document: $request->documents[$r['index']] ?? '',
                score:    (float) $r['relevance_score'],
            ),
            $response['results'] ?? [],
        );

        return new RerankingResponse($results);
    }
}
