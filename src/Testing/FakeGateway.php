<?php

declare(strict_types=1);

namespace MacroLLM\Testing;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MacroLLM\Config\Config;
use MacroLLM\Config\ProviderConfig;
use MacroLLM\Exception\FakeGatewayException;
use MacroLLM\MacroLLM;
use MacroLLM\Provider\ProviderFactory;
use MacroLLM\Registry\ProviderRegistry;
use MacroLLM\Registry\SkillRegistry;
use MacroLLM\Registry\ToolRegistry;

/**
 * The entry point for testing code built on this package without a network.
 *
 * ```php
 * $fake = FakeGateway::for('openai')
 *     ->respondingWith('Hello!')
 *     ->respondingWithToolCall('get_weather', ['location' => 'Rosario']);
 *
 * $llm = $fake->client();
 *
 * $response = $llm->chat(new InternalRequest([InternalMessage::user('Hi')]));
 *
 * $this->assertSame('Hello!', $response->content);
 * $this->assertSame('Hi', $fake->lastRequest()['messages'][1]['content']);
 * ```
 *
 * **Where it sits, and why.** The package performs provider HTTP calls from `MacroLLM`, not from the provider — the
 * provider builds the payload and maps the answer, and `MacroLLM` makes the request. `MacroLLM` is also `final`. So
 * the only honest place for a double is the transport itself: the fake installs a Guzzle `MockHandler` through the
 * same `@internal` seam the package's own tests use, and a real provider adapter runs on top of it. **The adapter is
 * genuinely exercised; only the network is not.**
 *
 * The consequence to know about: what gets recorded is the WIRE payload — the array the adapter sent — not the
 * `InternalRequest` your code built, because that object is not visible at this layer. Assertions therefore read the
 * provider's request body, which for most tests is what they want anyway (that the right model, messages and tools
 * went out).
 *
 * Deliberately free of any test-framework dependency: the package runs in any PHP 8.1+ application, so a fake that
 * called PHPUnit assertions would drag a dev dependency into production. It exposes what happened and lets your test
 * framework assert on it.
 */
final class FakeGateway
{
    /** @var list<array{status: int, body: string}> */
    private array $queued = [];

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function __construct(
        private readonly WireTemplate $template,
        private readonly string $provider,
        private readonly string $model,
    ) {}

    /**
     * @param  string  $provider  A name from `ProviderFactory`.
     * @param  string  $model     The model the fake reports back, and the one its client is configured with.
     */
    public static function for(string $provider, string $model = 'fake-model'): self
    {
        if (!ProviderFactory::supports($provider)) {
            throw FakeGatewayException::unknownProvider($provider);
        }

        return new self(self::templateFor($provider), $provider, $model);
    }

    /** Queue a plain text answer. Responses come back in the order they were queued. */
    public function respondingWith(string $content): self
    {
        $this->queued[] = ['status' => 200, 'body' => $this->template->text($content, $this->model)];

        return $this;
    }

    /** Queue an answer that asks for a tool, with the arguments in the place this family hides them. */
    public function respondingWithToolCall(
        string $toolName,
        array $arguments = [],
        string $toolCallId = 'call_1',
    ): self {
        $this->queued[] = [
            'status' => 200,
            'body' => $this->template->toolCall($toolName, $arguments, $toolCallId, $this->model),
        ];

        return $this;
    }

    /** Queue an HTTP failure, so a caller's error handling runs without an unreachable host or a spent key. */
    public function failingWith(int $status, string $body = ''): self
    {
        $this->queued[] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /** A client wired to this fake and to nothing else: no request can leave the process. */
    public function client(): MacroLLM
    {
        $config = new Config(
            providers: [$this->provider => new ProviderConfig(apiKey: 'fake', defaultModel: $this->model)],
            defaultProvider: $this->provider,
        );

        $providers = new ProviderRegistry();
        $providers->register(ProviderFactory::make($this->provider, $config->provider($this->provider)));

        $tools = new ToolRegistry();

        // Built ONCE and reused. `httpHandlerFactory` is invoked per request, and a fresh stack per request would
        // rebuild the queue each time — so every call would return the FIRST queued answer and the queue would never
        // drain. The MockHandler is stateful on purpose: that is what makes "responses in order" mean anything.
        $stack = $this->handlerStack();

        return new MacroLLM(
            $config,
            $providers,
            $tools,
            new SkillRegistry($tools),
            httpHandlerFactory: fn () => $stack,
        );
    }

    /**
     * The decoded request bodies this fake was asked to answer, in order.
     *
     * Reading them with a string cast is safe HERE, unlike the retry case the package's own tests guard against: this
     * is a post-hoc read of a JSON body, not an observation taken while a stream still needs to be re-read.
     *
     * @return list<array<string, mixed>>
     */
    public function requests(): array
    {
        return array_map(
            fn (array $entry): array => json_decode((string) $entry['request']->getBody(), true) ?: [],
            $this->history,
        );
    }

    /** @return array<string, mixed>|null */
    public function lastRequest(): ?array
    {
        $requests = $this->requests();

        return $requests === [] ? null : $requests[array_key_last($requests)];
    }

    public function callCount(): int
    {
        return count($this->history);
    }

    private function handlerStack(): HandlerStack
    {
        $queue = [];

        foreach ($this->queued as $response) {
            $queue[] = new Response(
                $response['status'],
                ['Content-Type' => 'application/json'],
                $response['body'],
            );
        }

        // A call beyond the queue fails loudly. Without this the MockHandler would throw its own underflow error,
        // which says nothing about what the test forgot to queue.
        $queue[] = fn (): Response => throw FakeGatewayException::noResponsesQueued(
            $this->provider,
            count($this->history) + 1,
        );

        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return $stack;
    }

    /** @return array<string, string> */
    private static function templates(): array
    {
        return [
            // The ten OpenAI-compatible providers share one wire format.
            'openai' => OpenAiCompatibleWireTemplate::class,
            'groq' => OpenAiCompatibleWireTemplate::class,
            'openrouter' => OpenAiCompatibleWireTemplate::class,
            'ollama' => OpenAiCompatibleWireTemplate::class,
            'llamacpp' => OpenAiCompatibleWireTemplate::class,
            'opencode-zen-go' => OpenAiCompatibleWireTemplate::class,
            'azure' => OpenAiCompatibleWireTemplate::class,
            'mistral' => OpenAiCompatibleWireTemplate::class,
            'deepseek' => OpenAiCompatibleWireTemplate::class,
            'xai' => OpenAiCompatibleWireTemplate::class,
            // Two providers speak the Messages API, not just `anthropic`.
            'anthropic' => AnthropicWireTemplate::class,
            'opencode-zen-go-anthropic' => AnthropicWireTemplate::class,
            'gemini' => GeminiWireTemplate::class,
            'cohere' => CohereWireTemplate::class,
            // `elevenlabs` is audio-only: it has no chat surface, so it has no chat template and needs none.
        ];
    }

    private static function templateFor(string $provider): WireTemplate
    {
        $class = self::templates()[$provider] ?? null;

        if ($class === null) {
            throw FakeGatewayException::noTemplateFor($provider);
        }

        return new $class();
    }
}
