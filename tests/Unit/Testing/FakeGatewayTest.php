<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Testing;

use MacroLLM\Exception\FakeGatewayException;
use MacroLLM\Exception\ProviderRequestException;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Testing\FakeGateway;
use MacroLLM\Tests\TestCase;

/**
 * The test doubles a consumer uses. What matters here is that a fake is usable WITHOUT a network, that a real
 * provider adapter still runs underneath it, and that it fails loudly when it has nothing to say.
 */
final class FakeGatewayTest extends TestCase
{
    private function request(string $text = 'Hi'): InternalRequest
    {
        return new InternalRequest(messages: [InternalMessage::user($text)]);
    }

    public function test_a_queued_answer_comes_back_through_chat(): void
    {
        $fake = FakeGateway::for('openai')->respondingWith('Hello!');

        $response = $fake->client()->chat($this->request());

        $this->assertSame('Hello!', $response->content);
        $this->assertSame('openai', $response->providerName);
        $this->assertSame(1, $fake->callCount());
    }

    /**
     * The real adapter ran: the recorded body is the OpenAI request shape, not something the fake invented.
     */
    public function test_the_real_adapter_built_the_request_that_was_recorded(): void
    {
        $fake = FakeGateway::for('openai', 'gpt-4o')->respondingWith('ok');

        $fake->client()->chat($this->request('What is the weather?'));

        $recorded = $fake->lastRequest();

        $this->assertNotNull($recorded);
        $this->assertSame('gpt-4o', $recorded['model']);
        $this->assertSame('user', $recorded['messages'][0]['role']);
        $this->assertSame('What is the weather?', $recorded['messages'][0]['content']);
    }

    public function test_queued_answers_come_back_in_order(): void
    {
        $fake = FakeGateway::for('openai')->respondingWith('first')->respondingWith('second');
        $client = $fake->client();

        $this->assertSame('first', $client->chat($this->request())->content);
        $this->assertSame('second', $client->chat($this->request())->content);
        $this->assertSame(2, $fake->callCount());
    }

    public function test_a_tool_call_arrives_mapped_with_its_arguments(): void
    {
        $fake = FakeGateway::for('openai')
            ->respondingWithToolCall('get_weather', ['location' => 'Rosario']);

        $response = $fake->client()->chat($this->request());

        $this->assertTrue($response->hasToolCalls());
        $this->assertSame('get_weather', $response->toolCalls[0]->name);
        $this->assertSame(['location' => 'Rosario'], $response->toolCalls[0]->arguments);
    }

    /**
     * A fake that runs dry must fail rather than improvise: inventing an answer would let a test pass while proving
     * nothing, and nothing would point at the mistake.
     */
    public function test_running_out_of_queued_answers_fails_loudly(): void
    {
        $fake = FakeGateway::for('openai')->respondingWith('only one');
        $client = $fake->client();

        $client->chat($this->request());

        try {
            $client->chat($this->request());
            $this->fail('Expected a FakeGatewayException.');
        } catch (FakeGatewayException $e) {
            $this->assertStringContainsString('openai', $e->getMessage());
            $this->assertStringContainsString('call #2', $e->getMessage());
        }
    }

    public function test_an_http_failure_can_be_queued(): void
    {
        $fake = FakeGateway::for('openai')->failingWith(503);

        try {
            $fake->client()->chat($this->request());
            $this->fail('Expected a ProviderRequestException.');
        } catch (ProviderRequestException $e) {
            $this->assertSame(503, $e->statusCode);
            $this->assertSame('openai', $e->providerName);
        }

        $this->assertSame(1, $fake->callCount());
    }

    public function test_fakes_do_not_share_state(): void
    {
        $first = FakeGateway::for('openai')->respondingWith('one');
        $second = FakeGateway::for('groq')->respondingWith('two');

        $this->assertSame('one', $first->client()->chat($this->request())->content);
        $this->assertSame('two', $second->client()->chat($this->request())->content);
        $this->assertSame(1, $first->callCount());
        $this->assertSame(1, $second->callCount());
    }

    public function test_every_openai_compatible_provider_is_fakeable(): void
    {
        foreach (['openai', 'groq', 'openrouter', 'ollama', 'llamacpp', 'opencode-zen-go', 'azure', 'mistral', 'deepseek', 'xai'] as $provider) {
            $fake = FakeGateway::for($provider)->respondingWith('ok');

            $this->assertSame('ok', $fake->client()->chat($this->request())->content, $provider);
        }
    }

    /** A family with no template yet is refused, because a guessed payload would fail inside the adapter. */
    public function test_a_provider_without_a_template_is_refused(): void
    {
        try {
            FakeGateway::for('gemini');
            $this->fail('Expected a FakeGatewayException.');
        } catch (FakeGatewayException $e) {
            $this->assertStringContainsString('gemini', $e->getMessage());
            $this->assertStringContainsString('no wire template', $e->getMessage());
        }
    }

    public function test_a_provider_the_package_does_not_know_is_refused(): void
    {
        try {
            FakeGateway::for('not-a-provider');
            $this->fail('Expected a FakeGatewayException.');
        } catch (FakeGatewayException $e) {
            $this->assertStringContainsString('not-a-provider', $e->getMessage());
        }
    }

    /** No HTTP client is constructed beyond the mocked stack, so nothing can leave the process. */
    public function test_no_request_leaves_the_process(): void
    {
        $fake = FakeGateway::for('openai')->respondingWith('ok');

        $fake->client()->chat($this->request());

        $this->assertSame(1, $fake->callCount());
        $this->assertStringContainsString('fake-model', (string) json_encode($fake->lastRequest()));
    }
}
