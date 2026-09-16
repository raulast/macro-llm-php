# Basic Recipes

Copy-paste starting points for the most common `MacroLLM` tasks: standalone setup, Laravel integration, provider swapping, streaming, model discovery, and tool calling.

`MacroLLM` is the single entry point for every call: you build it once via `MacroLLM::standalone(...)` or through the Laravel facade, then reuse it. Requests are expressed with the `InternalRequest` value object, which carries an ordered list of `InternalMessage` value objects (created with `InternalMessage::system(...)`, `InternalMessage::user(...)`, and the other role factories). Providers translate those internal value objects into their own wire formats, so the same request object can be sent to any configured provider.

### Recipe 1: Standalone Usage

The standalone factory wires configuration, providers, and the tool registry without any framework. `Config::fromArray()` takes the same key table documented in `config.md`, and secrets such as `${OPENAI_API_KEY}` are resolved from the environment when the value is accessed, not when the config array is built.

```php
use MacroLLM\MacroLLM;
use MacroLLM\Config\Config;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalMessage;

$llm = MacroLLM::standalone(Config::fromArray([
    'default_provider' => 'openai',
    'providers' => [
        'openai' => [
            'api_key'       => '${OPENAI_API_KEY}', // resolved from env at access time
            'default_model' => 'gpt-4o',
        ],
    ],
]));

$response = $llm->chat(new InternalRequest([
    InternalMessage::user('Hello!'),
]));

echo $response->content;
// $response->finishReason   — FinishReason enum
// $response->usage->totalTokens
// $response->extra          — unmapped provider fields
```

The returned `InternalResponse` exposes `content` for the assistant text, `finishReason` as a `FinishReason` enum, `usage->totalTokens` for token accounting, and `extra` for provider fields that have no internal mapping.

### Recipe 2: Laravel Facade

When the service provider is registered, the facade gives you the same object graph with zero setup. Use the facade alias to keep call sites short.

```php
use MacroLLM\Integration\Laravel\MacroLLMFacade as LLM;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalMessage;

$response = LLM::chat(new InternalRequest([
    InternalMessage::system('You are a helpful assistant.'),
    InternalMessage::user('What is Laravel?'),
]));
```

Messages are ordered, so a system instruction placed first applies to the user turn that follows it.

### Recipe 3: Laravel HTTP Macro

The service provider also registers one `PendingRequest` macro per configured provider. This lets you keep Laravel's fluent HTTP builder — headers, timeouts, retries, middleware — while still sending an `InternalRequest` through the provider adapter.

```php
// After ServiceProvider boots, each registered provider is available as a PendingRequest macro.
$response = Http::withHeaders(['X-Custom' => 'value'])->openai(new InternalRequest([
    InternalMessage::user('Explain PHP 8.1 fibers.'),
]));
```

The macro name matches the provider key from your config (`openai`, `anthropic`, `ollama`, and so on).

### Recipe 4: Switching Providers

Because `InternalRequest` is provider-agnostic, you can reuse one request object across providers. Pass the provider name as the second argument to `chat()`, or omit it to use the configured default provider.

```php
// Swap provider without changing InternalRequest shape:
$request = new InternalRequest([InternalMessage::user('Summarize this.')]);

$openaiResponse    = $llm->chat($request, 'openai');
$anthropicResponse = $llm->chat($request, 'anthropic');
$localResponse     = $llm->chat($request, 'ollama');
```

This is the recommended way to compare outputs or fail over between providers without duplicating request construction.

### Recipe 5: Streaming

`stream()` yields chunks as the provider produces them. Each chunk is a delta plus a terminal signal: once a chunk reports `finished`, its `response` property holds the complete `InternalResponse`, which is where you read final usage and metadata.

```php
foreach ($llm->stream(new InternalRequest([InternalMessage::user('Tell me a story.')])) as $chunk) {
    if ($chunk->finished) {
        // $chunk->response is the full InternalResponse
        echo "\n[{$chunk->response->usage->totalTokens} tokens]\n";
        break;
    }
    echo $chunk->delta;
    flush();
}
```

Call `flush()` after each delta when streaming to a browser so the text is pushed out instead of buffered.

### Recipe 6: List Available Models

`models()` reports the models a provider currently offers. It fetches from the provider's own API, which means it may perform an HTTP request — handle the empty-array case rather than assuming a live result. `ollama` and `llamacpp` report what is actually installed or loaded locally, while `anthropic` and `gemini` try HTTP first and fall back to a curated static list.

```php
// getModels() now fetches from the provider's own API — may make HTTP requests
$models = $llm->models('openai');
// → live list from GET /v1/models (or [] on failure)

$models = $llm->models('opencode-zen-go');
// → live list from GET /zen/go/v1/models

// Providers without a standard endpoint return static fallback:
$llm->models('ollama');   // → live list of installed models (GET /v1/models)
$llm->models('llamacpp'); // → currently loaded model (GET /v1/models)

// Anthropic and Gemini attempt HTTP first, fall back to a curated static list:
$llm->models('anthropic'); // → GET /models or fallback ['claude-opus-4-5', ...]
$llm->models('gemini');    // → GET /models or fallback ['gemini-2.0-flash', ...]
```

The single-provider signature is `models(string $provider): array`; call it per provider when you are enumerating what is available.

### Recipe 7: Tool Registration and Auto Tool-Call Loop

Register tools on the registry, then hand the work to an agent. The agent runs the tool-call loop for you: it sends the request, detects tool calls, executes the matching registered callable, appends the result, and repeats until the model produces a final answer or `maxIterations` is reached.

```php
use MacroLLM\Tool\ToolDefinition;
use MacroLLM\Agent\AgentConfig;

// 1. Register a tool
$llm->tools()->register(new ToolDefinition(
    name: 'get_weather',
    description: 'Returns current weather for a city.',
    parameters: [
        'type'       => 'object',
        'properties' => [
            'city' => ['type' => 'string', 'description' => 'City name'],
        ],
        'required'   => ['city'],
    ],
    callable: fn(array $args): string => "Sunny, 22°C in {$args['city']}",
));

// 2. Run an agent — tool calls handled automatically
$agent    = $llm->agent(new AgentConfig(provider: 'openai', maxIterations: 5));
$response = $agent->run('What is the weather in Buenos Aires and in Tokyo?');
echo $response->content;
```

The `parameters` array is a JSON Schema object, so the model sees the exact argument contract. `maxIterations` caps how many round trips the loop may perform before it stops with the best answer it has.

## See also

- `providers.md` — provider details, supported options, and adapter-specific behavior.
- `config.md` — the `Config::fromArray()` key table.
- `recipes-agents.md` — agent and skill recipes built on top of the primitives shown here.
