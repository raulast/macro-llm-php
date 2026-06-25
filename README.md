# macro-llm-php

Provider-agnostic AI client for Laravel, Slim 4, and standalone PHP.

[![PHP Version](https://img.shields.io/packagist/php-v/raulast/macro-llm-php)](https://packagist.org/packages/raulast/macro-llm-php)
[![Laravel](https://img.shields.io/badge/Laravel-10.x%20%7C%2011.x%20%7C%2012.x%20%7C%2013.x-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Packagist](https://img.shields.io/packagist/v/raulast/macro-llm-php)](https://packagist.org/packages/raulast/macro-llm-php)

## Overview

MacroLLM provides a unified interface to interact with any AI provider from any PHP 8.1+ application. Call `$llm->chat(...)` with the same request format regardless of which provider is behind the call — swap providers by changing a single string.

Internally, every request and response flows through a normalized format (`InternalRequest` / `InternalResponse`). Providers implement bidirectional normalization: your application code stays the same. This decouples business logic from vendor-specific APIs and makes provider migration a configuration change, not a rewrite.

The HTTP layer is a thin Guzzle wrapper (`HttpClient`) — `illuminate/http` is only pulled in for Laravel macro registration, not for core HTTP operations. Standalone and Slim users get zero Laravel overhead.

On top of the provider layer, MacroLLM provides a full agentic stack: **Skills** (reusable system-prompt + tool bundles), **Agents** (automatic tool-call loops with configurable memory), and **Orchestration** (sequential or parallel multi-agent workflows). Combined with built-in MCP client/server support, you can build complex AI-powered systems while keeping each piece testable and swappable.

## Features

- 14 built-in providers: OpenAI, Anthropic, Gemini, Groq, OpenRouter, Ollama, llama.cpp, OpenCode Zen Go, OpenCode Zen Go (Anthropic), Azure OpenAI, Mistral, DeepSeek, xAI, Cohere
- Unified `InternalRequest` / `InternalResponse` format with bidirectional normalization
- Thin Guzzle HTTP layer — no `illuminate/http` required outside Laravel
- Retry/backoff exponential configurable per provider (`retries`, `retry_delay_ms`)
- Vision/multimodal messages — `InternalMessage::userWithImage()` (base64 by default) and `ContentPart[]`
- Structured output via `ResponseFormat::jsonSchema()` (OpenAI-compatible providers)
- Automatic tool-call loop via `Agent`
- Reusable Skills (system prompt + tools + config — composable, subclassable, DB-hydratable via `Skill::fromArray()`)
- `GenericSkill` concrete class for inline and DB-hydrated skills (no subclassing needed)
- Parametrizable conversation memory (`NullMemory`, `InMemoryMemory`, `SqliteMemory`, `RedisMemory`, `FileMemory`)
- Multi-agent Orchestration (sequential, parallel, and conditional routing)
- MCP Client — discover and use tools from any MCP server
- MCP Server — expose your tools as a PSR-15 middleware endpoint
- Laravel integration (ServiceProvider, Facade, auto-discovery, `vendor:publish`)
- Slim 4 integration (`MacroLLMSlimExtension`)
- Standalone PHP — no framework required
- PHP 8.1+ (enums, readonly classes, fibers)

## Supported Providers

| Provider | Type | Auth | Default Base URL | Notes |
|---|---|---|---|---|
| openai | OpenAI-compatible | Bearer API key | `api.openai.com/v1` | Also supports Azure OpenAI via `base_url` override |
| anthropic | Native | x-api-key header | `api.anthropic.com/v1` | Messages API |
| gemini | Native | x-goog-api-key header | `generativelanguage.googleapis.com/v1beta` | generateContent API |
| groq | OpenAI-compatible | Bearer API key | `api.groq.com/openai/v1` | |
| openrouter | OpenAI-compatible | Bearer API key | `openrouter.ai/api/v1` | Model names prefixed: `openai/gpt-4o` |
| ollama | OpenAI-compatible | Optional | `localhost:11434/v1` | Local inference |
| llamacpp | OpenAI-compatible | None | `localhost:8080/v1` | Local inference |
| opencode-zen-go | OpenAI-compatible | Bearer API key | `opencode.ai` | GLM, Kimi, DeepSeek, MiMo; API key from [opencode.ai](https://opencode.ai) Zen console |
| opencode-zen-go-anthropic | Anthropic-compatible | x-api-key header | `opencode.ai` | MiniMax, Qwen; same API key as opencode-zen-go |
| azure | OpenAI-compatible | api-key header | `{resource}.openai.azure.com/openai/deployments/{deployment}` | Resource/deployment/version via `extra_headers` |
| mistral | OpenAI-compatible | Bearer API key | `api.mistral.ai/v1` | |
| deepseek | OpenAI-compatible | Bearer API key | `api.deepseek.com/v1` | Static model list |
| xai | OpenAI-compatible | Bearer API key | `api.x.ai/v1` | |
| cohere | Native | Bearer API key | `api.cohere.com/v2` | Native /v2/chat; streaming SSE |

## Requirements

- PHP ^8.1
- Laravel ^10 | ^11 (optional, for Laravel integration)

## Installation

```bash
composer require raulast/macro-llm-php
```

Laravel auto-discovery registers the ServiceProvider automatically. Optionally publish the config:

```bash
php artisan vendor:publish --tag=macro-llm-config
```

## Configuration

The config file (`config/macro-llm.php`) defines:

| Key | Description | Default |
|---|---|---|
| `default_provider` | Provider used when none is specified | `ollama` |
| `timeout` | Global request timeout in seconds | `30` |
| `retries` | Automatic retries on failure (exponential backoff) | `0` |
| `retry_delay_ms` | Base delay in ms for retry backoff (doubles each attempt) | `500` |
| `max_tool_iterations` | Max agent tool-call loop iterations | `10` |
| `providers` | Array of provider configurations | — |
| `mcp_servers` | External MCP server connections | — |

Each provider entry supports: `api_key`, `default_model`, `base_url`, `timeout`, `retries`, `retry_delay_ms`, `extra_headers`. All numeric fields are `?int` — `null` means "use global value".

API keys support environment variable patterns (`'${ENV_VAR}'`) resolved lazily at access time.

Example `.env` entries:

```env
MACRO_LLM_DEFAULT_PROVIDER=openai
OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
GEMINI_API_KEY=AIza...
GROQ_API_KEY=gsk_...
OPENROUTER_API_KEY=sk-or-...
OPENCODE_ZEN_API_KEY=ocz-...
```

## Usage

### Standalone (no framework)

```php
use MacroLLM\MacroLLM;
use MacroLLM\Config\Config;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalMessage;

$llm = MacroLLM::standalone(Config::fromArray([
    'default_provider' => 'openai',
    'providers' => [
        'openai' => ['api_key' => '${OPENAI_API_KEY}', 'default_model' => 'gpt-4o'],
    ],
]));

$response = $llm->chat(new InternalRequest([
    InternalMessage::user('Hello, world!'),
]));

echo $response->content;
```

### Laravel (via Facade)

```php
use MacroLLM\Integration\Laravel\MacroLLMFacade as LLM;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalMessage;

$response = LLM::chat(new InternalRequest([
    InternalMessage::system('You are a helpful assistant.'),
    InternalMessage::user('What is Laravel?'),
]));
```

### Laravel (via HTTP macro)

```php
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalMessage;

$response = Http::openai(new InternalRequest([
    InternalMessage::user('Explain macros in PHP.'),
]));
```

### Streaming

```php
foreach ($llm->stream(new InternalRequest([InternalMessage::user('Tell me a story.')])) as $chunk) {
    if ($chunk->finished) {
        echo "\n\n[done — {$chunk->response->usage->totalTokens} tokens]\n";
        break;
    }
    echo $chunk->delta;
    flush();
}
```

### Listing Available Models

```php
// Fetches live model list from the provider's own API (may make HTTP request)
$models = $llm->models('openai');
// → ['gpt-4o', 'gpt-4o-mini', 'o1', 'o3', ...]

$models = $llm->models('anthropic');
// → ['claude-opus-4-5', 'claude-sonnet-4-5', ...]

// OpenCode Zen fetches from GET /zen/go/v1/models
$models = $llm->models('opencode-zen-go');
// → ['deepseek-v3-0324', 'glm-z1-flash', 'kimi-k2', ...]

// Ollama and llama.cpp reflect locally installed / loaded models
$models = $llm->models('ollama');
// → ['llama3.2:latest', 'codellama:7b', ...]

// Via the Facade (Laravel)
$models = \MacroLLM\Integration\Laravel\MacroLLMFacade::models('gemini');

// Via provider directly (no HTTP needed for provider object access)
$provider = $llm->providers()->get('groq');
$models = $provider->getModels();
```

### Tool Calling

```php
use MacroLLM\Tool\ToolDefinition;

$llm->tools()->register(new ToolDefinition(
    name: 'get_weather',
    description: 'Get the current weather for a city.',
    parameters: [
        'type' => 'object',
        'properties' => [
            'city' => ['type' => 'string', 'description' => 'City name'],
        ],
        'required' => ['city'],
    ],
    callable: fn(array $args): string => "Sunny, 22°C in {$args['city']}",
));

$agent = $llm->agent(new \MacroLLM\Agent\AgentConfig(
    provider: 'openai',
    maxIterations: 5,
));

$response = $agent->run('What is the weather in Buenos Aires?');
echo $response->content;
```

### Skills

```php
use MacroLLM\Skill\Skill;

// Inline skill — returns a GenericSkill (no subclassing needed)
$translatorSkill = Skill::create(
    name: 'translator',
    systemPrompt: 'You are a professional translator. Always respond in the target language.',
    tools: ['detect_language', 'translate_text'],
);

$llm->skills()->register($translatorSkill);

// From DB record — also returns a GenericSkill
$skillData = $db->find('skills', 1);
$skill = Skill::fromArray($skillData);
$llm->skills()->register($skill);

// Dynamic skill via subclass
class SupportSkill extends Skill
{
    public function __construct(private string $product) {}
    public function getName(): string { return 'support'; }
    public function getSystemPrompt(): string {
        return "You are a support agent for {$this->product}. Be helpful and concise.";
    }
}
```

### Vision/Multimodal

```php
use MacroLLM\Message\InternalMessage;

// Simplest — base64 by default (works with all vision-capable providers)
$response = $llm->chat(new InternalRequest([
    InternalMessage::userWithImage('What is in this image?', '/path/to/photo.jpg'),
]));

// From URL — auto-fetched and encoded to base64
$response = $llm->chat(new InternalRequest([
    InternalMessage::userWithImage('Describe this', 'https://example.com/img.jpg'),
]));
```

### Structured Output

```php
use MacroLLM\Message\ResponseFormat;

$response = $llm->chat(new InternalRequest(
    messages: [InternalMessage::user('Extract: name and age from "Ana is 28 years old"')],
    responseFormat: ResponseFormat::jsonSchema('person', [
        'type'       => 'object',
        'properties' => ['name' => ['type' => 'string'], 'age' => ['type' => 'integer']],
        'required'   => ['name', 'age'],
    ]),
), 'openai');

$data = json_decode($response->content, true);
```

Observe each event in the agent's tool-call loop without modifying or extending `Agent`
(which is `final`). Pass an `onStep` closure to `AgentConfig` — it receives an
`AgentStep` value object at every meaningful point in the loop.

```php
use MacroLLM\Agent\AgentConfig;
use MacroLLM\Agent\AgentStep;
use MacroLLM\Agent\AgentStepType;

$agent = $llm->agent(new AgentConfig(
    provider: 'openai',
    maxIterations: 10,
    onStep: function (AgentStep $step): void {
        echo match ($step->type) {
            AgentStepType::LlmResponse    => "[{$step->iteration}] LLM responded with tool calls\n",
            AgentStepType::ToolCall       => "[{$step->iteration}] → calling {$step->toolCall->name}\n",
            AgentStepType::ToolResult     => "[{$step->iteration}] ← result: " . json_encode($step->toolResult->content) . "\n",
            AgentStepType::FinalResponse  => "[{$step->iteration}] Final: {$step->response->content}\n",
        };
    },
));

$response = $agent->run('What is the weather in Buenos Aires?');
```

**Step types:**

| Type | Fires when | Populated fields |
|------|-----------|-----------------|
| `LlmResponse` | LLM responds with tool calls (loop continues) | `response` |
| `ToolCall` | Before a tool is executed | `toolCall` |
| `ToolResult` | After a tool finishes (success or error) | `toolCall`, `toolResult` |
| `FinalResponse` | LLM responds with no tool calls (loop exits) | `response` |

The callback is **fire-and-forget**: exceptions propagate to the `run()` caller.
Passing `null` (the default) has zero overhead on the hot path.

### Conversation Memory

```php
use MacroLLM\Agent\AgentConfig;
use MacroLLM\Agent\Memory\InMemoryMemory;
use MacroLLM\Agent\Memory\SqliteMemory;
use MacroLLM\Agent\Memory\RedisMemory;
use MacroLLM\Agent\Memory\FileMemory;

$agent = $llm->agent(new AgentConfig(
    provider: 'anthropic',
    memory: new InMemoryMemory(),       // in-process
    // memory: new SqliteMemory('/var/data/conv.db', 'user-123'),  // persistent, no deps
    // memory: new RedisMemory($redis, 'user-123', ttl: 3600),     // redis
    // memory: new FileMemory('/tmp/conv-user-123.json'),          // file
));

$agent->run('My name is Ana.');
$response = $agent->run('What is my name?');
echo $response->content; // "Your name is Ana."
```

### Multi-Agent Orchestration

```php
use MacroLLM\Orchestration\Orchestrator;
use MacroLLM\Orchestration\RoutingStrategy;
use MacroLLM\Orchestration\ErrorStrategy;

$orchestrator = new Orchestrator(
    routing: RoutingStrategy::Parallel,
    errorStrategy: ErrorStrategy::Continue,
);

$orchestrator->addAgent('researcher', $llm->agent(new AgentConfig(
    provider: 'openai',
    systemPrompt: 'Research and summarize information.',
)));

$orchestrator->addAgent('writer', $llm->agent(new AgentConfig(
    provider: 'anthropic',
    systemPrompt: 'Write engaging content based on research.',
)));

$result = $orchestrator->dispatch('Write an article about PHP 8.1 fibers.');

foreach ($result->outcomes as $outcome) {
    echo "Agent {$outcome->agentName} ({$outcome->durationMs}ms):\n";
    echo $outcome->response?->content . "\n\n";
}
```

### MCP Client

```php
use MacroLLM\Mcp\MCPClient;

$mcp = new MCPClient($llm->tools());
$mcp->connect('filesystem', 'http://localhost:3001', auth: 'my-token');
// Tools now available as "filesystem/read_file", "filesystem/write_file", etc.

$agent = $llm->agent(new AgentConfig(provider: 'openai'));
$response = $agent->run('Read the contents of /tmp/notes.txt');
```

### MCP Server (Laravel)

```php
// In a Laravel route or controller:
use MacroLLM\Mcp\MCPServer;
use MacroLLM\Mcp\MCPServerMiddleware;

// Mount as PSR-15 middleware on /mcp
$app->middleware(MCPServerMiddleware::class);
// External MCP clients can now call tools/list and tools/call on /mcp
```

### Slim 4

```php
use MacroLLM\Integration\Slim\MacroLLMSlimExtension;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalMessage;
use Slim\Factory\AppFactory;
use DI\Container;

// PHP-DI container (composer require php-di/php-di)
$container = new Container();
AppFactory::setContainer($container);
$app = AppFactory::create();

// Register MacroLLM into the container
$extension = new MacroLLMSlimExtension($container, require __DIR__ . '/config/macro-llm.php');
$extension->register();

// Use MacroLLM inside a route
$app->get('/chat', function ($request, $response) use ($container) {
    $llm    = $container->get(\MacroLLM\MacroLLM::class);
    $result = $llm->chat(new InternalRequest([
        InternalMessage::user('Hello!'),
    ]));
    $response->getBody()->write($result->content ?? '');
    return $response;
});

$app->run();
```

> `MacroLLMSlimExtension` requires the container to support `set()`. PHP-DI's `Container` does.
> Slim's built-in container does not — use PHP-DI or another writable PSR-11 container.

The `config/macro-llm.php` file lives in your project (not in the package) and returns a plain array:

```php
// your-project/config/macro-llm.php
return [
    'default_provider' => 'ollama',
    'providers' => [
        'ollama' => [
            'api_key'       => getenv('OLLAMA_API_KEY') ?: 'local',
            'default_model' => 'llama3.2',
            'base_url'      => 'http://localhost:11434/v1',
        ],
    ],
];
```

Same format as `Config::fromArray()` — see the [Configuration](#configuration) section.

## Directory Structure

```
src/
├── Agent/          # Agent loop, AgentConfig, Memory strategies
├── Config/         # Config and ProviderConfig value objects
├── Contract/       # Interfaces (Provider, Skill, Memory, Concurrency)
├── Exception/      # Domain-specific exceptions
├── Integration/    # Framework bindings (Laravel, Slim)
├── Mcp/            # MCP Client, Server, and PSR-15 middleware
├── Message/        # InternalRequest, InternalResponse, StreamChunk, Usage
├── Orchestration/  # Multi-agent orchestrator, strategies, results
├── Provider/       # Provider implementations and factory
├── Registry/       # Tool, Skill, and Provider registries
├── Skill/          # Base Skill class (composable, subclassable)
├── Tool/           # ToolDefinition, ToolCall, ToolResult
├── skill-macro-llm-php/  # AI agent skill document (SKILL.md for coding assistants)
└── MacroLLM.php    # Main entry point and macro registration
```

## Architecture

MacroLLM follows a hexagonal architecture. The core domain (`Message`, `Contract`, `Registry`) has no framework dependencies. The provider layer implements bidirectional normalization behind `ProviderInterface`, so adding a new provider means implementing a single class without touching application code. The agentic layer (`Agent`, `Skill`, `Orchestration`) composes on top of the provider layer, using the same normalized types. Framework integrations (`Laravel`, `Slim`) are thin adapters that wire the core into their respective DI containers and lifecycle hooks.

## Exception Handling

| Exception | Thrown when |
|---|---|
| `UnregisteredProviderException` | Macro called for unregistered provider |
| `ProviderRequestException` | HTTP 4xx/5xx from provider API |
| `MissingApiKeyException` | API key missing before request |
| `ToolNotFoundException` | Model requested an unregistered tool |
| `MaxToolIterationsException` | Agent loop exceeded max iterations |
| `SkillToolConflictException` | Two composed skills define same tool |
| `SkillToolNotFoundException` | Skill references a tool not in the registry |
| `MCPConnectionException` | MCP server unreachable |
| `MCPToolCallException` | MCP server returned error |
| `StreamInterruptedException` | SSE stream ended unexpectedly |

## License

MIT — see [LICENSE](LICENSE) file.

## Author

Raul Antonio Salazar Torres — raulast.dev@gmail.com
