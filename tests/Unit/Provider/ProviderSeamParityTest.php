<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MacroLLM\Config\ProviderConfig;
use MacroLLM\Exception\ProviderRequestException;
use MacroLLM\Message\EmbeddingRequest;
use MacroLLM\Message\ImageRequest;
use MacroLLM\Message\RerankingRequest;
use MacroLLM\Provider\CohereProvider;
use MacroLLM\Provider\CohereRerankingProvider;
use MacroLLM\Provider\GeminiProvider;
use MacroLLM\Provider\OpenAIEmbeddingProvider;
use MacroLLM\Provider\OpenAIImageProvider;
use MacroLLM\Tests\TestCase;

/**
 * Every provider that performs its own HTTP now builds its client through `ProviderHttpClientTrait`.
 *
 * One representative per capability shape is enough: the seam, the retry budget and the failure
 * contract live in the shared trait, so proving OpenAI embeddings, OpenAI images and Cohere rerank
 * exercise the same transport is more honest than copying the assertions per provider.
 */
final class ProviderSeamParityTest extends TestCase
{
    /** @param list<Response> $queue */
    private function makeOpenAIEmbedding(array $queue, array &$history = []): OpenAIEmbeddingProvider
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        return new OpenAIEmbeddingProvider(
            new ProviderConfig(apiKey: 'test-key', defaultModel: 'text-embedding-3-small'),
            httpHandlerFactory: fn () => $stack,
        );
    }

    /** @param list<Response> $queue */
    private function makeOpenAIImage(array $queue, array &$history = []): OpenAIImageProvider
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        return new OpenAIImageProvider(
            new ProviderConfig(apiKey: 'test-key', defaultModel: 'dall-e-3'),
            httpHandlerFactory: fn () => $stack,
        );
    }

    /** @param list<Response> $queue */
    private function makeCohereReranking(array $queue, array &$history = []): CohereRerankingProvider
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        return new CohereRerankingProvider(
            new ProviderConfig(apiKey: 'test-key', defaultModel: 'rerank-v3.5'),
            httpHandlerFactory: fn () => $stack,
        );
    }

    /** @param list<Response> $queue */
    private function makeGemini(array $queue, array &$history = []): GeminiProvider
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        return new GeminiProvider(
            new ProviderConfig(apiKey: 'test-key', defaultModel: 'gemini-2.0-flash'),
            httpHandlerFactory: fn () => $stack,
        );
    }

    /** @param list<Response> $queue */
    private function makeCohere(array $queue, array &$history = []): CohereProvider
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        return new CohereProvider(
            new ProviderConfig(apiKey: 'test-key', defaultModel: 'command-r-plus'),
            httpHandlerFactory: fn () => $stack,
        );
    }

    /**
     * The three paths an independent verification found constructing `HttpClient` by fully-qualified name, which
     * evaded the invariant grep AND bypassed the trait. Bypassing the trait is what made them impossible to stub:
     * before this, every attempt was a real network call, so nothing could test them.
     *
     * These tests assert the seam is honoured — the request reaches the stub — rather than the response parsing,
     * which is pre-existing behaviour and a different concern.
     */
    public function test_gemini_embedding_goes_through_the_shared_client(): void
    {
        $history = [];
        $provider = $this->makeGemini(
            [new Response(200, [], '{"embedding":{"values":[0.1,0.2]}}')],
            $history,
        );

        $response = $provider->embed(new EmbeddingRequest(['hello']));

        $this->assertCount(1, $history, 'the @internal seam must reach this path');
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:embedContent', (string) $history[0]['request']->getUri());
        $this->assertSame([0.1, 0.2], $response->embeddings[0]);
    }

    public function test_cohere_embedding_goes_through_the_shared_client(): void
    {
        $history = [];
        $provider = $this->makeCohere(
            [new Response(200, [], '{"embeddings":{"float":[[0.1,0.2]]}}')],
            $history,
        );

        $response = $provider->embed(new EmbeddingRequest(['hello']));

        $this->assertCount(1, $history);
        $this->assertSame('https://api.cohere.com/v2/embed', (string) $history[0]['request']->getUri());
        $this->assertSame([0.1, 0.2], $response->embeddings[0]);
    }

    public function test_cohere_reranking_goes_through_the_shared_client(): void
    {
        $history = [];
        $provider = $this->makeCohere(
            [new Response(200, [], '{"results":[{"index":0,"relevance_score":0.9}]}')],
            $history,
        );

        $provider->rerank(new RerankingRequest('query', ['doc one', 'doc two']));

        $this->assertCount(1, $history);
        $this->assertSame('https://api.cohere.com/v2/rerank', (string) $history[0]['request']->getUri());
    }

    public function testEmbeddingReachesTheStubbedHandlerAndParsesTheResponse(): void
    {
        $history = [];
        $provider = $this->makeOpenAIEmbedding(
            [new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data'  => [['embedding' => [0.1, 0.2, 0.3]]],
                'usage' => ['prompt_tokens' => 3, 'total_tokens' => 3],
            ]))],
            $history,
        );

        $response = $provider->embed(new EmbeddingRequest(['hola']));

        $this->assertSame([[0.1, 0.2, 0.3]], $response->embeddings);

        $request = $history[0]['request'];
        $this->assertSame('https://api.openai.com/v1/embeddings', (string) $request->getUri());
        $this->assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
    }

    public function testEmbeddingHttpFailureSurfacesAsThePackageException(): void
    {
        $provider = $this->makeOpenAIEmbedding([new Response(500, [], 'boom')]);

        try {
            $provider->embed(new EmbeddingRequest(['hola']));
            $this->fail('Expected a ProviderRequestException.');
        } catch (ProviderRequestException $e) {
            $this->assertSame(500, $e->statusCode);
            $this->assertSame('https://api.openai.com/v1', $e->endpoint);
        }
    }

    public function testImageReachesTheStubbedHandlerAndParsesTheResponse(): void
    {
        $history = [];
        $provider = $this->makeOpenAIImage(
            [new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [['b64_json' => 'aGVsbG8=']],
            ]))],
            $history,
        );

        $response = $provider->generate(new ImageRequest('a cat'));

        $this->assertSame(['aGVsbG8='], $response->images);

        $request = $history[0]['request'];
        $this->assertSame('https://api.openai.com/v1/images/generations', (string) $request->getUri());
        $this->assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
    }

    public function testImageHttpFailureSurfacesAsThePackageException(): void
    {
        $provider = $this->makeOpenAIImage([new Response(500, [], 'boom')]);

        try {
            $provider->generate(new ImageRequest('a cat'));
            $this->fail('Expected a ProviderRequestException.');
        } catch (ProviderRequestException $e) {
            $this->assertSame(500, $e->statusCode);
            $this->assertSame('https://api.openai.com/v1', $e->endpoint);
        }
    }

    public function testRerankingReachesTheStubbedHandlerAndParsesTheResponse(): void
    {
        $history = [];
        $provider = $this->makeCohereReranking(
            [new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'results' => [['index' => 0, 'relevance_score' => 0.9]],
            ]))],
            $history,
        );

        $response = $provider->rerank(new RerankingRequest('query', ['doc-a', 'doc-b']));

        $this->assertCount(1, $response->results);
        $this->assertSame(0, $response->results[0]->index);
        $this->assertSame(0.9, $response->results[0]->score);

        $request = $history[0]['request'];
        $this->assertSame('https://api.cohere.com/v2/rerank', (string) $request->getUri());
        $this->assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
    }

    public function testRerankingHttpFailureSurfacesAsThePackageException(): void
    {
        $provider = $this->makeCohereReranking([new Response(500, [], 'boom')]);

        try {
            $provider->rerank(new RerankingRequest('query', ['doc-a']));
            $this->fail('Expected a ProviderRequestException.');
        } catch (ProviderRequestException $e) {
            $this->assertSame(500, $e->statusCode);
            $this->assertSame('https://api.cohere.com/v2', $e->endpoint);
        }
    }
}
