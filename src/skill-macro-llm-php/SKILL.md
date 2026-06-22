# macro-llm-php — AI Agent Skill

## Purpose

`macro-llm-php` is a provider-agnostic AI client Composer package for PHP 8.1+.
It extends Laravel's HTTP client (`Illuminate\Http\Client\PendingRequest`) via PHP macros,
exposing a unified interface (`InternalRequest` / `InternalResponse`) across 9 AI providers.
It ships a full agentic stack: Skills, Agents, multi-agent Orchestration, MCP client, and MCP server.

**Namespace root**: `MacroLLM\`
**Package name**: `raulast/macro-llm-php`
**Entry point**: `MacroLLM\MacroLLM`

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
| `InternalMessage` | `MacroLLM\Message` | Single conversation turn |
| `StreamChunk` | `MacroLLM\Message` | Streaming delta |
| `Usage` | `MacroLLM\Message` | Token usage metadata |
| `Role` (enum) | `MacroLLM\Message` | system\|user\|assistant\|tool |
| `FinishReason` (enum) | `MacroLLM\Message` | Stop\|ToolCalls\|Length\|ContentFilter |
| `ToolDefinition` | `MacroLLM\Tool` | Tool name+schema+callable |
| `ToolCall` | `MacroLLM\Tool` | Model-issued tool invocation |
| `ToolResult` | `MacroLLM\Tool` | Tool execution output |
| `Config` | `MacroLLM\Config` | Package configuration |
| `ProviderConfig` | `MacroLLM\Config` | Per-provider settings |
| `Skill` (abstract) | `MacroLLM\Skill` | Reusable prompt+tools bundle |
| `AgentConfig` | `MacroLLM\Agent` | Agent configuration |
| `Agent` | `MacroLLM\Agent` | Autonomous tool-call loop |
| `NullMemory` | `MacroLLM\Agent\Memory` | Stateless memory (default) |
| `InMemoryMemory` | `MacroLLM\Agent\Memory` | Stateful in-process memory |
| `Orchestrator` | `MacroLLM\Orchestration` | Multi-agent coordinator |
| `OrchestratorResult` | `MacroLLM\Orchestration` | Aggregated agent outcomes |
| `AgentOutcome` | `MacroLLM\Orchestration` | Single agent result |
| `RoutingStrategy` (enum) | `MacroLLM\Orchestration` | Sequential\|Parallel\|Conditional |
| `ErrorStrategy` (enum) | `MacroLLM\Orchestration` | Stop\|Continue |
| `MCPClient` | `MacroLLM\Mcp` | MCP server consumer |
| `MCPServer` | `MacroLLM\Mcp` | MCP server implementation |
| `MCPServerMiddleware` | `MacroLLM\Mcp` | PSR-15 MCP middleware |

## Providers

| Name | getModels() | Auth | Base URL | Notes |
|---|---|---|---|---|
| `openai` | GET /v1/models | `Authorization: Bearer {key}` | api.openai.com/v1 | OpenAI-compat base |
| `anthropic` | GET /models (fallback: static) | `x-api-key: {key}` + `anthropic-version: 2023-06-01` | api.anthropic.com/v1 | Native Anthropic protocol |
| `gemini` | GET /models (fallback: static) | `x-goog-api-key: {key}` | generativelanguage.googleapis.com/v1beta | Strips "models/" prefix |
| `groq` | GET /models (inherited) | `Authorization: Bearer {key}` | api.groq.com/openai/v1 | Inherits from OpenAI-compat |
| `openrouter` | GET /models (inherited) | `Authorization: Bearer {key}` | openrouter.ai/api/v1 | Dynamic catalog; OpenAI-compat |
| `ollama` | GET /v1/models (inherited) | optional | localhost:11434/v1 (configurable) | Locally installed models |
| `llamacpp` | GET /v1/models (inherited) | none | localhost:8080/v1 (configurable) | Single loaded model |
| `opencode-zen-go` | GET /zen/go/v1/models | `Authorization: Bearer {key}` | opencode.ai | OpenAI-compat; GLM, Kimi, DeepSeek, MiMo |
| `opencode-zen-go-anthropic` | GET /zen/go/v1/models (fallback: static) | `x-api-key: {key}` + anthropic-version | opencode.ai | Anthropic-compat; MiniMax, Qwen |

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

### Recipe 11: Conversation Memory

```php
use MacroLLM\Agent\AgentConfig;
use MacroLLM\Agent\Memory\InMemoryMemory;

$agent = $llm->agent(new AgentConfig(
    provider: 'openai',
    memory:   new InMemoryMemory(), // persists history across run() calls
));

$agent->run('My name is Ana and I love PHP.');
$response = $agent->run('What do you know about me?');
echo $response->content; // "Your name is Ana and you love PHP."
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

$extension = new MacroLLMSlimExtension($container, require __DIR__ . '/config/macro-llm.php');
$extension->register();

// Now available in container:
$llm           = $container->get(\MacroLLM\MacroLLM::class);
$mcpServer     = $container->get(\MacroLLM\Mcp\MCPServer::class);
$mcpMiddleware = $container->get(\MacroLLM\Mcp\MCPServerMiddleware::class);
```

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
    'retries'             => 0,          // retry count (0–10)
    'max_tool_iterations' => 10,         // agent loop max
    'providers' => [
        'openai' => [
            'api_key'       => '${OPENAI_API_KEY}', // ${VAR} resolved lazily from env
            'default_model' => 'gpt-4o',
            'base_url'      => null,     // override for Azure OpenAI
            'timeout'       => 30,
            'retries'       => 0,
            'extra_headers' => [],
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
