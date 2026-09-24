<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MacroLLM\Config\Config;
use MacroLLM\Config\ProviderConfig;
use MacroLLM\Exception\ProviderFailoverException;
use MacroLLM\Exception\ProviderRequestException;
use MacroLLM\MacroLLM;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Provider\AnthropicProvider;
use MacroLLM\Provider\OpenAIProvider;
use MacroLLM\Registry\ProviderRegistry;
use MacroLLM\Registry\SkillRegistry;
use MacroLLM\Registry\ToolRegistry;
use MacroLLM\Tests\TestCase;

/**
 * Failover, end to end: a provider that fails in a way another provider might survive hands over — and one that
 * fails on the request's own terms does not.
 *
 * The exclusions carry the risk, because a hop spends the next provider's key.
 */
final class FailoverTest extends TestCase
{
    private const ANTHROPIC_REPLY = '{"id":"msg_1","type":"message","role":"assistant",'
        . '"content":[{"type":"text","text":"from the secondary"}],"stop_reason":"end_turn",'
        . '"usage":{"input_tokens":1,"output_tokens":2}}';

    /**
     * @param  list<Response|callable>  $queue
     * @param  list<string>             $fallback
     * @param  array<int, mixed>        $history
     */
    private function makeLLM(array $queue, array $fallback = [], array &$history = []): MacroLLM
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        $providers = new ProviderRegistry();
        $providers->register(new OpenAIProvider(new ProviderConfig(
            apiKey: 'sk-primary',
            defaultModel: 'gpt-4o',
            baseUrl: 'http://primary.test',
            fallback: $fallback,
        )));
        $providers->register(new AnthropicProvider(new ProviderConfig(
            apiKey: 'sk-secondary',
            defaultModel: 'claude-3-5-sonnet-latest',
            baseUrl: 'http://secondary.test',
        )));

        $tools = new ToolRegistry();

        // The CONFIG is the source of truth for the chain, exactly as in production: `MacroLLM::standalone()` builds
        // the registry FROM the config, so the two agree there. Building them separately here meant the registry's
        // provider carried a `fallback` the config had never heard of — and the test failed for that reason rather
        // than for the behaviour it was checking.
        $config = Config::fromArray([
            'default_provider' => 'openai',
            'providers' => [
                'openai' => [
                    'api_key' => 'sk-primary',
                    'default_model' => 'gpt-4o',
                    'base_url' => 'http://primary.test',
                    'fallback' => $fallback,
                ],
                'anthropic' => [
                    'api_key' => 'sk-secondary',
                    'default_model' => 'claude-3-5-sonnet-latest',
                    'base_url' => 'http://secondary.test',
                ],
            ],
        ]);

        return new MacroLLM(
            $config,
            $providers,
            $tools,
            new SkillRegistry($tools),
            httpHandlerFactory: fn () => $stack,
        );
    }

    private function request(): InternalRequest
    {
        return new InternalRequest(messages: [InternalMessage::user('hi')]);
    }

    public function test_a_server_error_hands_over_and_reports_who_answered(): void
    {
        $history = [];
        $llm = $this->makeLLM(
            [new Response(503, [], ''), new Response(200, [], self::ANTHROPIC_REPLY)],
            fallback: ['anthropic'],
            history: $history,
        );

        $response = $llm->chat($this->request());

        $this->assertSame('from the secondary', $response->content);
        $this->assertSame('anthropic', $response->providerName, 'the caller must be able to see who answered');
        $this->assertCount(2, $history);
        $this->assertSame('http://primary.test/chat/completions', (string) $history[0]['request']->getUri());
        $this->assertSame(
            'http://secondary.test/messages',
            (string) $history[1]['request']->getUri(),
            'the second attempt must go to the second provider, not repeat the first',
        );
    }

    /**
     * The exclusion that protects a wallet. A 401 is a misconfiguration the next provider rejects in exactly the
     * same way, so hopping would spend a second key to learn nothing.
     */
    public function test_an_authentication_failure_does_not_hand_over(): void
    {
        $history = [];
        $llm = $this->makeLLM([new Response(401, [], 'nope')], fallback: ['anthropic'], history: $history);

        try {
            $llm->chat($this->request());
            $this->fail('Expected a ProviderRequestException.');
        } catch (ProviderRequestException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertSame('openai', $e->providerName);
        }

        $this->assertCount(1, $history, 'a 401 must not spend the second provider key');
    }

    public function test_an_exhausted_chain_reports_every_cause(): void
    {
        $history = [];
        $llm = $this->makeLLM(
            [new Response(503, [], ''), new Response(500, [], '')],
            fallback: ['anthropic'],
            history: $history,
        );

        try {
            $llm->chat($this->request());
            $this->fail('Expected a ProviderFailoverException.');
        } catch (ProviderFailoverException $e) {
            $this->assertCount(2, $e->causes);
            $this->assertSame(['openai', 'anthropic'], array_keys($e->causes));
            $this->assertStringContainsString('openai', $e->getMessage());
            $this->assertStringContainsString('anthropic', $e->getMessage());
            $this->assertStringContainsString('server_error', $e->getMessage());
        }

        $this->assertCount(2, $history);
    }

    /** The backward-compatibility guard: with no chain configured, the original failure is what a caller catches. */
    public function test_without_a_configured_chain_the_failure_is_unchanged(): void
    {
        $history = [];
        $llm = $this->makeLLM([new Response(503, [], '')], history: $history);

        $this->expectException(ProviderRequestException::class);

        try {
            $llm->chat($this->request());
        } finally {
            $this->assertCount(1, $history);
        }
    }

    public function test_stream_hands_over_too(): void
    {
        $history = [];
        $llm = $this->makeLLM(
            [
                new Response(503, [], ''),
                new Response(200, [], "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"hi\"}}\n\ndata: [DONE]\n\n"),
            ],
            fallback: ['anthropic'],
            history: $history,
        );

        $chunks = iterator_to_array($llm->stream($this->request()));
        $terminal = end($chunks);

        $this->assertCount(2, $history);
        $this->assertNotFalse($terminal);
        $this->assertSame(
            'anthropic',
            $terminal->response?->providerName,
            'the terminal chunk carries the identity of whoever eventually answered',
        );
    }

    /** A secondary that fails on the request's own terms stops the chain right there. */
    public function test_a_non_failoverable_failure_on_the_secondary_surfaces_as_the_secondary_failure(): void
    {
        $history = [];
        $llm = $this->makeLLM(
            [new Response(503, [], ''), new Response(401, [], 'bad key')],
            fallback: ['anthropic'],
            history: $history,
        );

        try {
            $llm->chat($this->request());
            $this->fail('Expected a ProviderRequestException.');
        } catch (ProviderRequestException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertSame('anthropic', $e->providerName);
        }

        $this->assertCount(2, $history);
    }
}
