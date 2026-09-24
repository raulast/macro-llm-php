# Testing your code

`FakeGateway` answers from a queue instead of a network, so your test suite needs no API keys, spends no money, and
cannot be flaky because a provider was slow.

```php
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Testing\FakeGateway;

$fake = FakeGateway::for('openai')
    ->respondingWith('Hello!')
    ->respondingWithToolCall('get_weather', ['location' => 'Rosario']);

$llm = $fake->client();

$response = $llm->chat(new InternalRequest([InternalMessage::user('Hi')]));

$this->assertSame('Hello!', $response->content);
$this->assertSame(1, $fake->callCount());
```

You speak canonical terms — a string, a tool name, its arguments — and the package takes care of the wire format for
the provider you named. Queue as many answers as your code makes calls; they come back in order.

## What gets recorded

```php
$fake->requests();      // list<array> — every request body, in order
$fake->lastRequest();   // array|null — the last one
$fake->callCount();     // int
```

**What is recorded is the request body the adapter SENT**, not the `InternalRequest` your code built. The reason is
structural rather than a shortcut: the package performs provider HTTP calls from `MacroLLM`, not from the provider, so
the `InternalRequest` is not visible at the layer where the fake sits. For most tests that is what you want anyway —
that the right model, messages and tools went out.

```php
$this->assertSame('gpt-4o', $fake->lastRequest()['model']);
$this->assertSame('What is the weather?', $fake->lastRequest()['messages'][0]['content']);
```

## Faking a failure

```php
$fake = FakeGateway::for('openai')->failingWith(503);

// your code's retry / failover / error handling runs, with no unreachable host and no spent key
```

## What is deliberately NOT faked

- **The provider adapters run for real.** A fake installs a Guzzle `MockHandler` under a real adapter, so the payload
  building and response mapping you would otherwise be re-testing are exercised. Only the network is replaced.
- **Streaming is not framed.** `stream()` fetches through the transport rather than through the provider, so a fake
  cannot intercept SSE events. The package's own suite covers the framing; a fake is for testing *your* code.
- **Provider families without a wire template are refused**, not approximated. Today that is OpenAI-compatible (which
  covers ten of the fifteen providers). Faking `gemini`, `anthropic` or `cohere` raises `FakeGatewayException` telling
  you so, because a guessed payload would fail inside the adapter instead of in your test.

## The test-framework question

`FakeGateway` calls no assertion methods of its own. The package runs in any PHP 8.1+ application, so a fake that
depended on PHPUnit would drag a dev dependency into production. It exposes what happened; assert on it with whatever
you already use.

## When the queue runs dry

A call beyond the queue raises `FakeGatewayException` naming the provider and the call number. That is on purpose: a
fake that invents an answer makes a test pass for a reason its author never intended, and nothing would point at the
mistake.
