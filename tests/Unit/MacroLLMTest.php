<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MacroLLM\Config\Config;
use MacroLLM\Config\ProviderConfig;
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
