# macro-llm-php — AI Agent Skill

## Purpose

`macro-llm-php` is a provider-agnostic AI client Composer package for PHP 8.1+.
It uses a thin Guzzle HTTP layer (no `illuminate/http` required in core) for all provider communication,
exposing a unified interface (`InternalRequest` / `InternalResponse`) across **14 AI providers**.
It ships a full agentic stack: Skills, Agents, multi-agent Orchestration, MCP client, and MCP server.
Supports vision/multimodal messages, structured output (JSON Schema), and persistent conversation memory.

**Namespace root**: `MacroLLM\`
**Package name**: `raulast/macro-llm-php`
**Entry point**: `MacroLLM\MacroLLM`
**HTTP layer**: `MacroLLM\Http\HttpClient` (Guzzle wrapper — `illuminate/http` only needed for Laravel macros)

## Installation

```bash
composer require raulast/macro-llm-php
```

Laravel: auto-discovery registers `MacroLLMServiceProvider`. Publish config:
```bash
php artisan vendor:publish --tag=macro-llm-config
```

## Core Types

| Class | Namespace | Description |
|---|---|---|
| `MacroLLM` | `MacroLLM` | Main entry point |
| `InternalRequest` | `MacroLLM\Message` | Provider-agnostic request |
| `InternalResponse` | `MacroLLM\Message` | Provider-agnostic response |
| `InternalMessage` | `MacroLLM\Message` | Single conversation turn (text or `ContentPart[]` for multimodal) |
| `StreamChunk` | `MacroLLM\Message` | Streaming delta |
| `Usage` | `MacroLLM\Message` | Token usage metadata |
| `Role` (enum) | `MacroLLM\Message` | system\|user\|assistant\|tool |
| `FinishReason` (enum) | `MacroLLM\Message` | Stop\|ToolCalls\|Length\|ContentFilter |
| `ContentPart` | `MacroLLM\Message` | Single part within a multimodal message |
| `ContentPartType` (enum) | `MacroLLM\Message` | Text\|ImageUrl\|ImageBase64 |
| `ResponseFormat` | `MacroLLM\Message` | Structured output — JSON or JSON Schema enforcement |
| `ToolDefinition` | `MacroLLM\Tool` | Tool name+schema+callable |
| `ToolCall` | `MacroLLM\Tool` | Model-issued tool invocation |
| `ToolResult` | `MacroLLM\Tool` | Tool execution output |
| `Config` | `MacroLLM\Config` | Package configuration |
| `ProviderConfig` | `MacroLLM\Config` | Per-provider settings (`timeout`/`retries` are `?int`) |
| `Skill` (abstract) | `MacroLLM\Skill` | Reusable prompt+tools bundle |
| `GenericSkill` | `MacroLLM\Skill` | Concrete Skill for inline/DB hydration |
| `AgentConfig` | `MacroLLM\Agent` | Agent configuration |
| `AgentStep` | `MacroLLM\Agent` | Value object for a single loop event |
| `AgentStepType` (enum) | `MacroLLM\Agent` | LlmResponse\|ToolCall\|ToolResult\|FinalResponse |
| `Agent` | `MacroLLM\Agent` | Autonomous tool-call loop |
| `NullMemory` | `MacroLLM\Agent\Memory` | Stateless memory (default) |
| `InMemoryMemory` | `MacroLLM\Agent\Memory` | Stateful in-process memory |
| `SqliteMemory` | `MacroLLM\Agent\Memory` | SQLite-backed persistent memory (PDO, no extra deps) |
| `RedisMemory` | `MacroLLM\Agent\Memory` | Redis-backed memory (phpredis or Predis) |
| `FileMemory` | `MacroLLM\Agent\Memory` | File-backed memory (JSON on disk) |
| `Orchestrator` | `MacroLLM\Orchestration` | Multi-agent coordinator |
| `OrchestratorResult` | `MacroLLM\Orchestration` | Aggregated agent outcomes |
| `AgentOutcome` | `MacroLLM\Orchestration` | Single agent result |
| `ConditionalRoute` | `MacroLLM\Orchestration` | Agent + condition pair for conditional routing |
| `RoutingStrategy` (enum) | `MacroLLM\Orchestration` | Sequential\|Parallel\|Conditional |
| `ErrorStrategy` (enum) | `MacroLLM\Orchestration` | Stop\|Continue |
| `MCPClient` | `MacroLLM\Mcp` | MCP server consumer |
| `MCPServer` | `MacroLLM\Mcp` | MCP server implementation |
| `MCPServerMiddleware` | `MacroLLM\Mcp` | PSR-15 MCP middleware |
| `HttpClient` | `MacroLLM\Http` | Thin Guzzle wrapper (retry/backoff built-in) |

## Providers

| Name | getModels() | Auth | Base URL | Notes |
|---|---|---|---|---|
| `openai` | GET /v1/models | Bearer | api.openai.com/v1 | OpenAI-compat base |
| `anthropic` | GET /models (fallback: static) | x-api-key | api.anthropic.com/v1 | Native Anthropic |
| `gemini` | GET /models (fallback: static) | x-goog-api-key | generativelanguage.googleapis.com/v1beta | |
| `groq` | GET /models | Bearer | api.groq.com/openai/v1 | OpenAI-compat |
| `openrouter` | GET /models | Bearer | openrouter.ai/api/v1 | OpenAI-compat |
| `ollama` | GET /v1/models | optional | localhost:11434/v1 | Local inference |
| `llamacpp` | GET /v1/models | none | localhost:8080/v1 | Local inference |
| `opencode-zen-go` | GET /zen/go/v1/models | Bearer | opencode.ai | GLM, Kimi, DeepSeek, MiMo |
| `opencode-zen-go-anthropic` | GET /zen/go/v1/models (fallback: static) | x-api-key | opencode.ai | MiniMax, Qwen |
| `azure` | — (empty) | api-key header | {resource}.openai.azure.com/openai/deployments/{deployment} | Resource/deployment/version via `extra_headers` |
| `mistral` | GET /models | Bearer | api.mistral.ai/v1 | OpenAI-compat |
| `deepseek` | static | Bearer | api.deepseek.com/v1 | OpenAI-compat |
| `xai` | GET /models | Bearer | api.x.ai/v1 | OpenAI-compat |
| `cohere` | GET /models?endpoint=chat | Bearer | api.cohere.com/v2 | Native /v2/chat |

## Recipes

### Recipe 1: Standalone Usage

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

### Recipe 2: Laravel Facade

```php
use MacroLLM\Integration\Laravel\MacroLLMFacade as LLM;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalMessage;

$response = LLM::chat(new InternalRequest([
    InternalMessage::system('You are a helpful assistant.'),
    InternalMessage::user('What is Laravel?'),
]));
```

### Recipe 3: Laravel HTTP Macro

```php
// After ServiceProvider boots, each registered provider is available as a PendingRequest macro.
$response = Http::withHeaders(['X-Custom' => 'value'])->openai(new InternalRequest([
    InternalMessage::user('Explain PHP 8.1 fibers.'),
]));
```

### Recipe 4: Switching Providers

```php
// Swap provider without changing InternalRequest shape:
$request = new InternalRequest([InternalMessage::user('Summarize this.')]);

$openaiResponse    = $llm->chat($request, 'openai');
$anthropicResponse = $llm->chat($request, 'anthropic');
$localResponse     = $llm->chat($request, 'ollama');
```

### Recipe 5: Streaming

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

### Recipe 6: List Available Models

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

### Recipe 7: Tool Registration and Auto Tool-Call Loop

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

### Recipe 8: Skills — Inline Creation

```php
use MacroLLM\Skill\Skill;

$skill = Skill::create(
    name:         'translator',
    systemPrompt: 'You are a professional translator. Respond only in the target language.',
    tools:        ['detect_language', 'translate_text'], // tool names already in ToolRegistry
);

$llm->skills()->register($skill);

$agent = $llm->agent(new AgentConfig(
    provider:    'anthropic',
    skillNames:  ['translator'],
));
$response = $agent->run('Translate "Hello world" to French.');
```

### Recipe 9: Skills — DB Hydration

```php
// Hydrate from a database record (or any array):
$record = ['name' => 'support', 'system_prompt' => 'You are a support agent.', 'tools' => []];
$skill  = Skill::fromArray($record);
$llm->skills()->register($skill);
```

### Recipe 10: Skills — Dynamic Subclass

```php
use MacroLLM\Skill\Skill;

class SupportSkill extends Skill
{
    public function __construct(private readonly string $product) {}
    public function getName(): string        { return 'support'; }
    public function getSystemPrompt(): string {
        return "You are a support agent for {$this->product}. Be concise and helpful.";
    }
}

$llm->skills()->register(new SupportSkill('Laravel'));
```

### Recipe 10.5: Agent Step Callback (Observability)

`Agent` es `final` — el closure `onStep` en `AgentConfig` es el único mecanismo para observar
los eventos del loop sin modificar ni extender la clase.

```php
use MacroLLM\Agent\AgentConfig;
use MacroLLM\Agent\AgentStep;
use MacroLLM\Agent\AgentStepType;

$agent = $llm->agent(new AgentConfig(
    provider:      'openai',
    maxIterations: 10,
    onStep: function (AgentStep $step): void {
        echo match ($step->type) {
            AgentStepType::LlmResponse   => "[{$step->iteration}] LLM → tool calls incoming\n",
            AgentStepType::ToolCall      => "[{$step->iteration}] → {$step->toolCall->name}(" . json_encode($step->toolCall->arguments) . ")\n",
            AgentStepType::ToolResult    => "[{$step->iteration}] ← " . json_encode($step->toolResult->content) . " [{$step->toolResult->status->value}]\n",
            AgentStepType::FinalResponse => "[{$step->iteration}] DONE: {$step->response->content}\n",
        };
    },
));

$response = $agent->run('What is the weather in Paris?');
```

**AgentStep fields per type:**

| Type | `response` | `toolCall` | `toolResult` |
|---|---|---|---|
| `LlmResponse` | ✓ (has tool calls) | — | — |
| `ToolCall` | — | ✓ | — |
| `ToolResult` | — | ✓ | ✓ |
| `FinalResponse` | ✓ (no tool calls) | — | — |

- `iteration` is 1-based, independent from the iteration guard counter.
- Exceptions thrown inside the callback propagate to the `agent->run()` caller.
- `onStep: null` (default) has zero overhead on the hot path.

### Recipe 11: Conversation Memory

```php
use MacroLLM\Agent\AgentConfig;
use MacroLLM\Agent\Memory\InMemoryMemory;
use MacroLLM\Agent\Memory\SqliteMemory;
use MacroLLM\Agent\Memory\RedisMemory;
use MacroLLM\Agent\Memory\FileMemory;

// In-process (lost on restart)
$agent = $llm->agent(new AgentConfig(
    provider: 'openai',
    memory:   new InMemoryMemory(),
));

// Persistent — SQLite (no extra dependencies, uses PHP PDO)
$agent = $llm->agent(new AgentConfig(
    provider: 'openai',
    memory:   new SqliteMemory('/var/data/conversations.db', 'user-123'),
));

// Persistent — Redis
$agent = $llm->agent(new AgentConfig(
    provider: 'openai',
    memory:   new RedisMemory($redisClient, 'user-123', ttl: 3600),
));

// Persistent — File
$agent = $llm->agent(new AgentConfig(
    provider: 'openai',
    memory:   new FileMemory('/tmp/conv-user-123.json'),
));

$agent->run('My name is Ana and I love PHP.');
$response = $agent->run('What do you know about me?');
echo $response->content; // "Your name is Ana and you love PHP."
```

### Recipe 11.5: Vision / Multimodal

```php
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\ContentPart;

// Convenience factory — base64 by default (works with all providers)
$response = $llm->chat(new InternalRequest([
    InternalMessage::userWithImage('What is in this image?', '/path/to/photo.jpg'),
]));

// From URL — fetches and converts to base64 automatically
$response = $llm->chat(new InternalRequest([
    InternalMessage::userWithImage('Describe this', 'https://example.com/img.jpg'),
]));

// Send as URL (only for providers that support it — pass asUrl: true explicitly)
$response = $llm->chat(new InternalRequest([
    InternalMessage::userWithImage('Describe this', 'https://storage.googleapis.com/...', asUrl: true),
]));

// Full control via ContentPart
$response = $llm->chat(new InternalRequest([
    InternalMessage::userWithParts(
        ContentPart::text('Compare these two images:'),
        ContentPart::imageBase64($base64data, 'image/png'),
        ContentPart::imageBase64($base64data2, 'image/jpeg'),
    ),
]));
```

**Provider support:**
- `openai`, `anthropic`, `gemini`, `groq` — support `imageBase64`
- `imageUrl` — only works with providers that allow URL fetching (generally avoid; use base64)

### Recipe 11.6: Structured Output (JSON Schema)

```php
use MacroLLM\Message\ResponseFormat;

$request = new InternalRequest(
    messages: [InternalMessage::user('Extract: name and age from "Ana is 28 years old"')],
    responseFormat: ResponseFormat::jsonSchema('person', [
        'type'       => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'age'  => ['type' => 'integer'],
        ],
        'required'   => ['name', 'age'],
    ]),
);

$response = $llm->chat($request, 'openai');
$data = json_decode($response->content, true);
// ['name' => 'Ana', 'age' => 28]
```

**Note:** `ResponseFormat` is applied by OpenAI-compatible providers. Anthropic and Gemini ignore it silently — instruct via system prompt instead.

### Recipe 11.7: Conditional Orchestration

```php
use MacroLLM\Orchestration\Orchestrator;
use MacroLLM\Orchestration\AgentOutcome;

$orchestrator = new Orchestrator();

// Always runs (no condition)
$orchestrator->addAgent('classifier', $llm->agent(new AgentConfig(
    provider:     'openai',
    systemPrompt: 'Classify the input as "technical" or "general". Reply with one word only.',
)));

// Only runs if previous agent said "technical"
$orchestrator->addConditionalAgent(
    'technical-responder',
    $llm->agent(new AgentConfig(provider: 'openai', systemPrompt: 'You are a senior PHP engineer.')),
    fn(?AgentOutcome $prev) => $prev !== null && str_contains(strtolower($prev->response?->content ?? ''), 'technical'),
);

// Only runs if previous agent said "general"
$orchestrator->addConditionalAgent(
    'general-responder',
    $llm->agent(new AgentConfig(provider: 'openai', systemPrompt: 'You are a friendly assistant.')),
    fn(?AgentOutcome $prev) => $prev !== null && str_contains(strtolower($prev->response?->content ?? ''), 'general'),
);

$result = $orchestrator->dispatch('How do PHP fibers work?');
```

### Recipe 12: Multi-Agent Orchestration — Sequential

```php
use MacroLLM\Orchestration\Orchestrator;
use MacroLLM\Orchestration\RoutingStrategy;
use MacroLLM\Orchestration\ErrorStrategy;
use MacroLLM\Agent\AgentConfig;

$orchestrator = new Orchestrator(
    routing:       RoutingStrategy::Sequential,
    errorStrategy: ErrorStrategy::Continue,
);

$orchestrator->addAgent('researcher', $llm->agent(new AgentConfig(
    provider:     'openai',
    systemPrompt: 'Research and summarize information about the given topic.',
)));

$orchestrator->addAgent('writer', $llm->agent(new AgentConfig(
    provider:     'anthropic',
    systemPrompt: 'Write a blog post based on the research provided.',
)));

$result = $orchestrator->dispatch('PHP 8.1 new features');

foreach ($result->outcomes as $outcome) {
    echo "=== {$outcome->agentName} ({$outcome->durationMs}ms) ===\n";
    echo $outcome->response?->content . "\n\n";
}
```

### Recipe 13: Multi-Agent Orchestration — Parallel

```php
$orchestrator = new Orchestrator(
    routing:       RoutingStrategy::Parallel,
    errorStrategy: ErrorStrategy::Continue,
);

$orchestrator->addAgent('agent-a', $llm->agent(new AgentConfig(provider: 'openai')));
$orchestrator->addAgent('agent-b', $llm->agent(new AgentConfig(provider: 'groq')));

$result = $orchestrator->dispatch('Describe microservices architecture.');
// Both agents run concurrently via Guzzle Promises (curl_multi).
```

### Recipe 14: MCP Client — Connect and Use External Tools

```php
use MacroLLM\Mcp\MCPClient;
use MacroLLM\Agent\AgentConfig;

$mcp = new MCPClient($llm->tools());

// Connect to an MCP server — discovers and registers tools as "server/tool"
$mcp->connect('filesystem', 'http://localhost:3001', auth: 'my-token');
// Tools now available: "filesystem/read_file", "filesystem/list_directory", etc.

$agent    = $llm->agent(new AgentConfig(provider: 'openai'));
$response = $agent->run('Read /tmp/notes.txt and summarize it.');
echo $response->content;
```

### Recipe 15: MCP Server — Expose Local Tools

```php
use MacroLLM\Mcp\MCPServer;
use MacroLLM\Mcp\MCPServerMiddleware;

$mcpServer     = new MCPServer($llm->tools());
$mcpMiddleware = new MCPServerMiddleware($mcpServer, path: '/mcp');

// Laravel: add to middleware stack
$app->middleware(MCPServerMiddleware::class);

// Slim 4: add to route or global middleware
$slimApp->add($mcpMiddleware);

// Any PSR-15 app: MCPServerMiddleware implements MiddlewareInterface
// Clients can POST to /mcp with JSON-RPC 2.0:
// {"jsonrpc":"2.0","id":1,"method":"tools/list"}
// {"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"get_weather","arguments":{"city":"BA"}}}
```

### Recipe 16: Slim 4 Integration

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

// Register MacroLLM — reads config/macro-llm.php from your project root
$extension = new MacroLLMSlimExtension($container, require __DIR__ . '/config/macro-llm.php');
$extension->register();

// Use in a route
$app->get('/chat', function ($request, $response) use ($container) {
    $llm = $container->get(\MacroLLM\MacroLLM::class);
    $result = $llm->chat(new InternalRequest([InternalMessage::user('Hello!')]));
    $response->getBody()->write($result->content ?? '');
    return $response;
});

$app->run();
```

The `config/macro-llm.php` is created by you in your project (not auto-published):
```php
// your-project/config/macro-llm.php
return [
    'default_provider' => 'ollama',
    'providers' => [
        'ollama' => ['api_key' => 'local', 'default_model' => 'llama3.2'],
    ],
];
```

After `register()`, the container holds:
- `MacroLLM::class`
- `MCPServer::class`
- `MCPServerMiddleware::class`

### Recipe 17: Custom Provider

```php
use MacroLLM\Contract\ProviderInterface;
use MacroLLM\Message\{InternalRequest, InternalResponse, StreamChunk, FinishReason, Usage};

final class MyProvider implements ProviderInterface
{
    public function name(): string          { return 'myprovider'; }
    public function baseUrl(): string       { return 'https://api.myprovider.com/v1'; }
    public function endpointPath(): string  { return '/chat'; }
    public function headers(): array        { return ['Authorization' => 'Bearer '.$this->apiKey]; }
    public function supportsStreaming(): bool { return false; }
    public function getModels(): array      { return ['my-model-v1', 'my-model-v2']; }

    public function toPayload(InternalRequest $request): array { /* ... */ }
    public function toResponse(array $providerResponse): InternalResponse { /* ... */ }
    public function parseStreamEvent(string $rawEvent, int $index): ?StreamChunk { return null; }
}

$llm->providers()->register(new MyProvider());
$llm->models('myprovider'); // → ['my-model-v1', 'my-model-v2']
```

### Recipe 17.5: OpenCode Zen Go Providers

```php
use MacroLLM\MacroLLM;
use MacroLLM\Config\Config;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalMessage;

// OpenCode Zen Go — OpenAI-compatible (GLM, Kimi, DeepSeek, MiMo)
$llm = MacroLLM::standalone(Config::fromArray([
    'default_provider' => 'opencode-zen-go',
    'providers' => [
        'opencode-zen-go' => [
            'api_key'       => '${OPENCODE_ZEN_API_KEY}',
            'default_model' => 'deepseek-v3-0324',
        ],
    ],
]));

$response = $llm->chat(new InternalRequest([
    InternalMessage::user('Explain PHP fibers.'),
]), 'opencode-zen-go');

// List available models (fetched from API):
$models = $llm->models('opencode-zen-go');
// → ['GLM-5.2', 'Kimi-K2.7', 'deepseek-v3-0324', ...]

// OpenCode Zen Go — Anthropic-compatible (MiniMax, Qwen)
$llm = MacroLLM::standalone(Config::fromArray([
    'default_provider' => 'opencode-zen-go-anthropic',
    'providers' => [
        'opencode-zen-go-anthropic' => [
            'api_key'       => '${OPENCODE_ZEN_API_KEY}',
            'default_model' => 'MiniMax-M3',
        ],
    ],
]));

$response = $llm->chat(new InternalRequest([
    InternalMessage::user('What is dependency injection?'),
]), 'opencode-zen-go-anthropic');

// Same API key works for both providers.
// The Anthropic-compat provider falls back to a static model list on failure:
$models = $llm->models('opencode-zen-go-anthropic');
// → ['MiniMax-M3', 'MiniMax-M2.7', 'Qwen3.7-Max', ...] or live list
```

## Config Structure

```php
Config::fromArray([
    'default_provider'    => 'openai',  // used when no provider specified
    'timeout'             => 30,         // global timeout seconds (1–300)
    'retries'             => 0,          // retry count (0–10); per-provider override via ProviderConfig
    'retry_delay_ms'      => 500,        // base delay ms for exponential backoff (500→1000→2000→...)
    'max_tool_iterations' => 10,         // agent loop max
    'providers' => [
        'openai' => [
            'api_key'        => '${OPENAI_API_KEY}', // ${VAR} resolved lazily from env
            'default_model'  => 'gpt-4o',
            'base_url'       => null,     // override for Azure OpenAI
            'timeout'        => null,     // ?int — null = use global timeout
            'retries'        => null,     // ?int — null = use global retries
            'retry_delay_ms' => null,     // ?int — null = use global retry_delay_ms
            'extra_headers'  => [],
        ],
        'ollama' => [
            'api_key'       => null,     // optional for local providers
            'default_model' => 'llama3.2',
            'base_url'      => 'http://localhost:11434/v1', // developer-specific URL
        ],
        'opencode-zen-go' => [
            'api_key'       => '${OPENCODE_ZEN_API_KEY}',
            'default_model' => 'deepseek-v3-0324',
        ],
        'opencode-zen-go-anthropic' => [
            'api_key'       => '${OPENCODE_ZEN_API_KEY}',
            'default_model' => 'MiniMax-M3',
        ],
    ],
]);
```

## AgentConfig Options

```php
new AgentConfig(
    provider:       'openai',          // overrides global default; null = use global
    systemPrompt:   'You are...',      // prepended before skill prompts
    tools:          [$toolDefinition], // direct ToolDefinition[] — lower priority than skills
    skillNames:     ['translator'],    // resolved from SkillRegistry
    skillSeparator: "\n\n",            // separator between composed skill prompts
    maxIterations:  10,                // max tool-call loop iterations
    memory:         new InMemoryMemory(), // NullMemory (default) or InMemoryMemory
    onStep:         fn(AgentStep $s) => log($s), // optional; null = disabled (default)
);
```

**Provider resolution order** (low → high priority):
1. Global `Config::defaultProvider()`
2. `AgentConfig::provider`
3. `Skill::getConfigOverride()::defaultProvider()`

**System prompt order** (first → last in request):
1. `AgentConfig::systemPrompt`
2. Skill prompts in composition order, joined by `skillSeparator`

**Tool resolution** (priority order):
1. Skill tools (in composition order) — `SkillToolConflictException` if two skills share a name
2. `AgentConfig::tools` — silently ignored if name conflicts with a skill tool

## Exceptions

| Exception | Namespace | Constructor args | When thrown |
|---|---|---|---|
| `MacroLLMException` | `MacroLLM\Exception` | — | Abstract base |
| `UnregisteredProviderException` | `MacroLLM\Exception` | `string $providerName` | Macro called for unknown provider |
| `ProviderRequestException` | `MacroLLM\Exception` | `string $provider, int $status, string $body` | HTTP 4xx/5xx from provider API |
| `MissingApiKeyException` | `MacroLLM\Exception` | `string $provider` | API key missing before request |
| `StreamInterruptedException` | `MacroLLM\Exception` | `array $chunks` | SSE stream dropped before finish |
| `ToolNotFoundException` | `MacroLLM\Exception` | `string $toolName` | Model called unregistered tool |
| `MaxToolIterationsException` | `MacroLLM\Exception` | `int $iterations, InternalResponse $last` | Agent loop hit iteration cap |
| `SkillToolNotFoundException` | `MacroLLM\Exception` | `string $skillName, string $toolName` | Skill references unregistered tool (at registration) |
| `SkillToolConflictException` | `MacroLLM\Exception` | `string $toolName, string $skill1, string $skill2` | Two skills define same tool |
| `MCPConnectionException` | `MacroLLM\Exception` | `string $url, string $detail` | MCP server unreachable |
| `MCPToolCallException` | `MacroLLM\Exception` | `string $toolName, int $code, string $message` | MCP server returned error |
| `ContainerBindingException` | `MacroLLM\Exception` | `string $containerClass` | PSR-11 container is read-only |

### Recommended Catch Patterns

```php
use MacroLLM\Exception\MacroLLMException;
use MacroLLM\Exception\ProviderRequestException;
use MacroLLM\Exception\MaxToolIterationsException;

try {
    $response = $agent->run($input);
} catch (MaxToolIterationsException $e) {
    // $e->iterations — how many iterations ran
    // $e->lastResponse — last InternalResponse before exception
    $partial = $e->lastResponse->content;
} catch (ProviderRequestException $e) {
    // $e->statusCode — HTTP status
    // $e->providerBody — raw error body from provider
    Log::error("Provider error {$e->statusCode}: {$e->providerBody}");
} catch (MacroLLMException $e) {
    // Catch-all for any package exception
    Log::error($e->getMessage());
}
```

## Key Invariants

- `getModels()` may make HTTP requests to discover available models. Returns `[]` on any failure.
- Providers with discovery endpoints (OpenAI-compat, Anthropic, Gemini) attempt a live API call first.
- Providers without guaranteed endpoints (Anthropic, Gemini, opencode-zen-go-anthropic) fall back to a curated static list.
- `InternalRequest` and `InternalResponse` are immutable `readonly` classes.
- `Config` values with `${VAR}` pattern are resolved lazily at `get()` time (not at construction).
- `SkillRegistry::register()` validates tool existence at registration time (fail-fast).
- `ProviderRegistry::register()` replaces on duplicate provider name (no exception).
- `NullMemory` is the default memory — agents are stateless unless `InMemoryMemory` is explicitly set.
- All package exceptions extend `MacroLLMException` — catch-all with a single `catch (MacroLLMException)`.
- **`Usage` token fields**: `promptTokens`, `completionTokens`, `totalTokens` — there is NO `inputTokens` or `outputTokens`.
- **`Skill` is abstract** — `Skill::fromArray()` and `Skill::create()` return a `GenericSkill` instance when called directly on `Skill`. Subclasses continue to return `new static()`. No need to create a concrete subclass just for hydration.
- **`illuminate/http` is NOT required** for standalone or Slim usage. It is listed under `suggest` in `composer.json`. Laravel users already have it installed; the `MacroLLMServiceProvider` registers macros in `boot()`.
- **HTTP retry/backoff**: `Config` accepts `retries` (int, default 0) and `retry_delay_ms` (int, default 500ms). `HttpClient` retries on connect failure and HTTP 429/500/502/503 with exponential backoff (`delay * 2^attempt`). Per-provider override via `ProviderConfig::$retries` and `$retryDelayMs` (both `?int` — null = use global).
- **`ProviderConfig::$timeout` and `$retries` are `?int`** — `null` means "not overridden, use global Config value". This fixes the previous sentinel anti-pattern.
- The `config/macro-llm.php` in Slim integration is created by the user in their project — it is NOT auto-published (unlike Laravel's `vendor:publish`).
