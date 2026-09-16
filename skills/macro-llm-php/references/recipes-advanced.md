# Advanced Recipes

End-to-end recipes for orchestration, MCP integration, framework wiring, and custom providers.

These recipes revolve around the three PSR-15 / orchestration entry points of the package: `Orchestrator` for multi-agent routing, `MCPClient` for consuming external Model Context Protocol servers, and `MCPServer` for exposing local tools over JSON-RPC 2.0. Each recipe below is self-contained and can be lifted into a project as-is.

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

With `RoutingStrategy::Sequential`, each agent receives the accumulated context of the previous stage, so the writer sees the researcher's output. `ErrorStrategy::Continue` keeps the chain alive when one agent fails; inspect `$result->outcomes` to detect the failed stage.

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

With `RoutingStrategy::Parallel`, every registered agent receives the same input and runs concurrently through Guzzle Promises backed by `curl_multi`. The dispatch returns after all agents settle.

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

`MCPClient::connect()` performs the MCP handshake, lists the remote tools, and registers each one into the local tool registry under the `server/tool` naming scheme. Once registered, any agent built from the same `$llm` instance can call those tools without further wiring.

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

`MCPServer` adapts the local tool registry to the MCP protocol, and `MCPServerMiddleware` exposes it as a PSR-15 middleware mounted at the configured `path`. The JSON-RPC 2.0 payloads above are the two core methods a client will send: `tools/list` for discovery and `tools/call` for invocation.

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

`MacroLLMSlimExtension` requires a PHP-DI compatible container, which is why the recipe bootstraps `DI\Container` before `AppFactory::setContainer()`. The extension consumes the same config array shape used by the standalone factory, so migrating between standalone and Slim does not require rewriting provider settings.

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

`ProviderInterface` is the full contract a custom provider must satisfy: `name()`, `baseUrl()`, `endpointPath()`, `headers()`, `supportsStreaming()`, `getModels()`, `toPayload()`, `toResponse()`, and `parseStreamEvent()`. Register the instance through `$llm->providers()->register()` and it becomes addressable by its `name()` value everywhere a provider string is accepted.

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

The `opencode-zen-go` provider speaks the OpenAI-compatible surface, while `opencode-zen-go-anthropic` speaks the Anthropic-compatible surface; both accept the same API key. The OpenAI-compatible variant fetches its model list from the API, whereas the Anthropic-compatible variant falls back to a static list when the live fetch fails.

See also: `providers.md` for provider configuration details and `invariants.md` for the retry/backoff contract.
