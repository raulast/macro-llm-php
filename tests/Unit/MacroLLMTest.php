<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MacroLLM\Config\Config;
use MacroLLM\Config\ProviderConfig;
use MacroLLM\Agent\AgentConfig;
use MacroLLM\Exception\ProviderRequestException;
use MacroLLM\MacroLLM;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\ResponseFormat;
use MacroLLM\Provider\AnthropicProvider;
use MacroLLM\Provider\OpenAIProvider;
use MacroLLM\Registry\ProviderRegistry;
use MacroLLM\Registry\SkillRegistry;
use MacroLLM\Registry\ToolRegistry;
use MacroLLM\Tests\TestCase;

/**
 * Failure-attribution tests at the MacroLLM boundary.
 *
 * HttpClient is a transport and knows only the base URL it was handed. The provider identity
 * exists in exactly one place — the layer that resolved the provider — so that is where the
 * failure is attributed. Without this, a caller (or a future failover policy) learns the
 * endpoint but cannot tell which provider failed.
 */
final class MacroLLMTest extends TestCase
{
    /** @param array<int, Response> $guzzleQueue */
    private function makeLLM(array $guzzleQueue): MacroLLM
    {
        $tools = new ToolRegistry();
        $skills = new SkillRegistry($tools);
        $stack = HandlerStack::create(new MockHandler($guzzleQueue));

        $providers = new ProviderRegistry();
        $providers->register(new OpenAIProvider(new ProviderConfig(
            apiKey: 'test-key',
            defaultModel: 'gpt-4o',
            baseUrl: 'http://fake-openai.test',
        )));
        $providers->register(new AnthropicProvider(new ProviderConfig(
            apiKey: 'test-key',
            defaultModel: 'claude-3-5-sonnet-latest',
            baseUrl: 'http://fake-anthropic.test',
        )));

        return new MacroLLM(
            new Config(defaultProvider: 'openai'),
            $providers,
            $tools,
            $skills,
            httpHandlerFactory: fn () => $stack,
        );
    }

    private function request(): InternalRequest
    {
        return new InternalRequest(messages: [InternalMessage::user('hi')]);
    }

    /**
     * Records what an attempt put on the wire, from inside the handler, draining without seeking.
     *
     * @param  list<string>  $bodies
     * @return callable(\Psr\Http\Message\RequestInterface): Response
     */
    private function captureThenRespond(array &$bodies, int $status, string $body): callable
    {
        return function ($request) use (&$bodies, $status, $body): Response {
            $stream = $request->getBody();
            $captured = '';

            while (! $stream->eof()) {
                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    break;
                }
                $captured .= $chunk;
            }

            $bodies[] = $captured;

            return new Response($status, [], $body);
        };
    }

    private function openAiTextResponse(string $content = '{}'): string
    {
        return json_encode([
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'model' => 'gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    // ── responseFormat plumbing ────────────────────────────────────────────

    public function test_stream_carries_the_response_format_to_the_provider(): void
    {
        $bodies = [];
        $llm = $this->makeLLM([
            $this->captureThenRespond($bodies, 200, "data: {\"candidates\":[]}\n\ndata: [DONE]\n\n"),
        ]);

        iterator_to_array($llm->stream(new InternalRequest(
            messages: [InternalMessage::user('Give me JSON')],
            responseFormat: ResponseFormat::jsonSchema('answer', ['type' => 'object']),
        )));

        $this->assertStringContainsString('response_format', $bodies[0]);
        $this->assertStringContainsString('json_schema', $bodies[0]);
        $this->assertStringContainsString('"answer"', $bodies[0]);
    }

    public function test_agent_config_carries_the_response_format_into_the_request(): void
    {
        $bodies = [];
        $llm = $this->makeLLM([$this->captureThenRespond($bodies, 200, $this->openAiTextResponse())]);

        $llm->agent(new AgentConfig(
            provider: 'openai',
            responseFormat: ResponseFormat::jsonSchema('answer', ['type' => 'object']),
        ))->run('hi');

        $this->assertStringContainsString('response_format', $bodies[0]);
        $this->assertStringContainsString('"answer"', $bodies[0]);
    }

    /** A format on the request wins over the agent config: it is the more specific of the two. */
    public function test_the_requests_own_format_wins_over_the_agent_config(): void
    {
        $bodies = [];
        $llm = $this->makeLLM([$this->captureThenRespond($bodies, 200, $this->openAiTextResponse())]);

        $llm->agent(new AgentConfig(
            provider: 'openai',
            responseFormat: ResponseFormat::jsonSchema('from-config', ['type' => 'object']),
        ))->run(new InternalRequest(
            messages: [InternalMessage::user('hi')],
            responseFormat: ResponseFormat::jsonSchema('from-request', ['type' => 'object']),
        ));

        $this->assertStringContainsString('"from-request"', $bodies[0]);
        $this->assertStringNotContainsString('"from-config"', $bodies[0]);
    }

    public function testFailedChatAttributesTheFailureToTheResolvedProvider(): void
    {
        $llm = $this->makeLLM([new Response(400, [], '{"error":{"message":"bad model"}}')]);

        try {
            $llm->chat($this->request());
            $this->fail('Expected a ProviderRequestException.');
        } catch (ProviderRequestException $e) {
            $this->assertSame('openai', $e->providerName);
            $this->assertSame('http://fake-openai.test', $e->endpoint);
            $this->assertSame(400, $e->statusCode);
            $this->assertStringStartsWith('Provider "openai" returned HTTP 400', $e->getMessage());
        }
    }

    /**
     * The attribution must follow the RESOLVED provider, not the configured default: a request
     * sent explicitly to another provider has to name that provider.
     */
    public function testExplicitlySelectedProviderIsTheOneNamedOnFailure(): void
    {
        $llm = $this->makeLLM([new Response(400, [], '{"error":{"message":"bad model"}}')]);

        try {
            $llm->chat($this->request(), 'anthropic');
            $this->fail('Expected a ProviderRequestException.');
        } catch (ProviderRequestException $e) {
            $this->assertSame('anthropic', $e->providerName);
            $this->assertSame('http://fake-anthropic.test', $e->endpoint);
            $this->assertStringStartsWith('Provider "anthropic" returned HTTP 400', $e->getMessage());
        }
    }
}
