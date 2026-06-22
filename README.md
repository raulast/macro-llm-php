# macro-llm-php

Provider-agnostic AI client for Laravel, Slim 4, and standalone PHP.

[![PHP Version](https://img.shields.io/packagist/php-v/raulast/macro-llm-php)](https://packagist.org/packages/raulast/macro-llm-php)
[![Laravel](https://img.shields.io/badge/Laravel-10.x%20%7C%2011.x-red)](https://laravel.com)
[![License](https://img.shields.io/packagist/l/raulast/macro-llm-php)](LICENSE)
[![Packagist](https://img.shields.io/packagist/v/raulast/macro-llm-php)](https://packagist.org/packages/raulast/macro-llm-php)

## Overview

MacroLLM extends Laravel's HTTP client (`PendingRequest`) through PHP macros, giving you a unified interface to interact with any AI provider. Call `Http::openai(...)`, `Http::anthropic(...)`, or `Http::gemini(...)` with the same request format — swap providers by changing a single string.

Internally, every request and response flows through a normalized format (`InternalRequest` / `InternalResponse`). Providers implement bidirectional normalization: your application code stays the same regardless of which provider is behind the call. This decouples business logic from vendor-specific APIs and makes provider migration a configuration change, not a rewrite.

On top of the provider layer, MacroLLM provides a full agentic stack: **Skills** (reusable system-prompt + tool bundles), **Agents** (automatic tool-call loops with configurable memory), and **Orchestration** (sequential or parallel multi-agent workflows). Combined with built-in MCP client/server support, you can build complex AI-powered systems while keeping each piece testable and swappable.

## Features

- 7 built-in providers: OpenAI, Anthropic, Gemini, Groq, OpenRouter, Ollama, llama.cpp
- Unified `InternalRequest` / `InternalResponse` format with bidirectional normalization
- Automatic tool-call loop via `Agent`
- Reusable Skills (system prompt + tools + config — composable, subclassable, DB-hydratable via `fromArray`)
- Parametrizable conversation memory (`NullMemory` stateless default, `InMemoryMemory`, extensible)
- Multi-agent Orchestration (sequential and parallel via Guzzle concurrent requests)
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
| `retries` | Automatic retries on failure | `0` |
| `max_tool_iterations` | Max agent tool-call loop iterations | `10` |
| `providers` | Array of provider configurations | — |
| `mcp_servers` | External MCP server connections | — |

Each provider entry supports: `api_key`, `default_model`, `base_url`, `timeout`, `retries`, `extra_headers`.

API keys support environment variable patterns (`'${ENV_VAR}'`) resolved lazily at access time.

Example `.env` entries:

```env
MACRO_LLM_DEFAULT_PROVIDER=openai
OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
GEMINI_API_KEY=AIza...
GROQ_API_KEY=gsk_...
OPENROUTER_API_KEY=sk-or-...
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

// Inline skill
$translatorSkill = Skill::create(
    name: 'translator',
    systemPrompt: 'You are a professional translator. Always respond in the target language.',
    tools: ['detect_language', 'translate_text'],
);

$llm->skills()->register($translatorSkill);

// From DB record
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

### Conversation Memory

```php
use MacroLLM\Agent\AgentConfig;
use MacroLLM\Agent\Memory\InMemoryMemory;

$agent = $llm->agent(new AgentConfig(
    provider: 'anthropic',
    memory: new InMemoryMemory(), // stateful — remembers across run() calls
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

$extension = new MacroLLMSlimExtension($container, require 'config/macro-llm.php');
$extension->register();

$macroLLM = $container->get(\MacroLLM\MacroLLM::class);
```

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
