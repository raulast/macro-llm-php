---
name: macro-llm-php
description: "Provider-agnostic AI client for PHP: chat, streaming, tool calling, agents, skills, multi-agent orchestration, MCP client, MCP server, embeddings, vision and multimodal. Works standalone, in Laravel or Slim 4. Supports OpenAI, Anthropic, Gemini, Azure, Groq, OpenRouter, Mistral, DeepSeek, xAI, Cohere, ElevenLabs, Ollama and llama.cpp."
license: MIT
---

# macro-llm-php — AI Agent Skill

## Purpose

`macro-llm-php` is a provider-agnostic AI client Composer package for PHP 8.1+.
It uses a thin Guzzle HTTP layer (no `illuminate/http` required in core) for all provider communication,
exposing a unified interface (`InternalRequest` / `InternalResponse`) across **15 AI providers**.
It ships a full agentic stack: Skills, Agents, multi-agent Orchestration, MCP client, and MCP server.
Supports vision/multimodal messages, structured output (JSON Schema), and persistent conversation memory.

**Namespace root**: `MacroLLM\`
**Package name**: `raulast/macro-llm-php`
**Entry point**: `MacroLLM\MacroLLM`
**HTTP layer**: `MacroLLM\Http\HttpClient` (Guzzle wrapper — `illuminate/http` only needed for Laravel macros)

### What triggers this skill

Load this skill when a task is about building or debugging AI features in PHP: constructing a
provider-agnostic AI client, sending a chat or streaming completion, registering tools and running a
tool-calling loop, defining agents or agent skills, orchestrating multiple agents, embedding a
standalone client in any PHP 8.1+ app, wiring the Laravel service provider or facade, integrating a
Slim 4 route, using the MCP client or exposing an MCP server, generating embeddings, or sending
vision/multimodal image input.

## Installation

```bash
composer require raulast/macro-llm-php
```

Laravel: auto-discovery registers `MacroLLMServiceProvider`. Publish config:

```bash
php artisan vendor:publish --tag=macro-llm-config
```

Standalone, no framework: build the client yourself with `MacroLLM::standalone(...)`. Slim 4 or any
other container: bind the client in your container and inject it — no Laravel package required.

## Type lookup table

| Class | Namespace | One-line description |
| --- | --- | --- |
| `MacroLLM` | `MacroLLM\MacroLLM` | Entry point: `chat()`, `stream()`, `models()`, `agent()`, `tools()` |
| `InternalRequest` | `MacroLLM\Message\InternalRequest` | Provider-agnostic request value object carrying an ordered message list |
| `InternalResponse` | `MacroLLM\Message\InternalResponse` | Normalized reply: `content`, `finishReason`, `usage`, `extra` |
| `InternalMessage` | `MacroLLM\Message\InternalMessage` | One ordered message; factories `system()`, `user()`, `assistant()`, `tool()` |
| `Agent` | `MacroLLM\Agent\Agent` | Runs a request through the automatic tool-call loop and returns a response |
| `AgentConfig` | `MacroLLM\Agent\AgentConfig` | Agent settings: provider, model, `maxIterations`, system prompt, tools |
| `Orchestrator` | `MacroLLM\Orchestration\Orchestrator` | Coordinates multiple agents and merges their results |
| `MCPClient` | `MacroLLM\Mcp\MCPClient` | Connects to external MCP servers and exposes their tools |
| `MCPServer` | `MacroLLM\Mcp\MCPServer` | Exposes this package's tools to MCP-compatible clients |
| `Config` | `MacroLLM\Config\Config` | Config object built with `Config::fromArray()`; resolves `${ENV}` secrets lazily |
| `ToolDefinition` | `MacroLLM\Tool\ToolDefinition` | Tool contract: name, description, JSON Schema parameters, callable |
| `ToolRegistry` | `MacroLLM\Registry\ToolRegistry` | Registry returned by `$llm->tools()`; holds the callables tools call into |
| `StreamChunk` | `MacroLLM\Message\StreamChunk` | One stream delta plus the `finished` flag and terminal `response` |
| `SchemaDialect` | `MacroLLM\Schema\SchemaDialect` | What a provider accepts: `OpenAi`, `Gemini` and `Cohere` |
| `SchemaNormalizer` | `MacroLLM\Schema\SchemaNormalizer` | Rewrites a JSON Schema for a provider dialect, or refuses it by name |
| `SchemaValidator` | `MacroLLM\Schema\SchemaValidator` | Checks a decoded value against a JSON Schema and reports the failing path |
| `FailoverPolicy` | `MacroLLM\Provider\FailoverPolicy` | Classifies which provider failures justify trying another provider |
| `FakeGateway` | `MacroLLM\Testing\FakeGateway` | Test double: a whole provider answered from a queue, with no network |
| `InMemoryVectorStore` | `MacroLLM\VectorStore\InMemoryVectorStore` | Reference vector store: cosine similarity, no dependencies, in memory |
| `VectorMatch` | `MacroLLM\VectorStore\VectorMatch` | One retrieval hit: `id`, `score` and the stored metadata |
| `SimilaritySearchTool` | `MacroLLM\Tool\SimilaritySearchTool` | Builds the tool an Agent calls to search an indexed store |
| `PendingApproval` | `MacroLLM\Approval\PendingApproval` | A tool call waiting for a human answer, with its arguments |
| `Decision` | `MacroLLM\Approval\Decision` | `Approve` or `Reject` — what the human answered |
| `OpenAiCompatibleWireTemplate` | `MacroLLM\Testing\OpenAiCompatibleWireTemplate` | The wire shape the ten OpenAI-compatible providers share, used by the fake |
| `AnthropicWireTemplate` | `MacroLLM\Testing\AnthropicWireTemplate` | The Messages API shape: content blocks, a `tool_use` block, arguments as a real array |
| `GeminiWireTemplate` | `MacroLLM\Testing\GeminiWireTemplate` | The `generateContent` shape: `parts`, a `functionCall` part, `STOP` even for a call |
| `CohereWireTemplate` | `MacroLLM\Testing\CohereWireTemplate` | The Chat v2 shape: content blocks, `message.tool_calls`, usage under `billed_units` |

## When to use which reference

| File | Read it when you need |
| --- | --- |
| `core-types.md` | The full value-object surface: every `InternalMessage` factory, `InternalResponse` fields, `Usage`, enums, and how the internal graph maps to provider wire formats. |
| `providers.md` | Provider specifics: keys, auth headers, base URLs, adapter quirks, model discovery, and the supported options per adapter. |
| `config.md` | The `Config::fromArray()` key table, defaults, environment-variable resolution, and per-provider config blocks. |
| `recipes-basic.md` | Copy-paste starting points: standalone and Laravel setup, provider swapping, streaming, model listing, tool registration. |
| `recipes-agents.md` | Agent and skill recipes: system prompts, tool loops, multi-step agents, skills, and how agents compose. |
| `recipes-advanced.md` | Advanced patterns: multi-agent orchestration, MCP client/server wiring, structured output, embeddings, vision, memory. |
| `exceptions.md` | The exception hierarchy and which failure path to catch for HTTP, provider, configuration, and tool errors. |
| `structured-output.md` | JSON Schema normalization: which keywords each provider dialect accepts, and why an unsupported one is refused instead of dropped. |
| `testing.md` | Faking a provider so your own tests need no network: `FakeGateway`, what gets recorded, and what is deliberately not faked. |
| `vector-store.md` | Retrieval: the driver the package ships and what it costs, the search API, and the four guards that prevent a wrong answer instead of an error. |
| `tool-approval.md` | Making a dangerous tool wait for a human: marking it, answering it, what each answer does, and how a raised decision resumes. |
| `invariants.md` | Non-negotiable package behavior and contracts you must not break when extending or debugging. |

## Inline recipes

### 1. Standalone usage

The standalone factory wires configuration, providers, and the tool registry without any framework.
Secrets such as `${OPENAI_API_KEY}` are resolved from the environment when the value is accessed.

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

### 2. Basic Laravel chat

When the service provider is registered, the facade gives you the same object graph with zero setup.
Messages are ordered, so a system instruction placed first applies to the user turn that follows it.

```php
use MacroLLM\Integration\Laravel\MacroLLMFacade as LLM;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalMessage;

$response = LLM::chat(new InternalRequest([
    InternalMessage::system('You are a helpful assistant.'),
    InternalMessage::user('What is Laravel?'),
]));

echo $response->content;
```

### 3. Agent loop

Register the tools on the registry, then hand the work to an agent. The agent runs the tool-call loop
for you: it sends the request, detects tool calls, executes the matching callable, appends the result,
and repeats until the model produces a final answer or `maxIterations` is reached.

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

## Beyond these recipes

These three snippets are only the entry point. For anything else — provider behavior and supported
options, the full `Config::fromArray()` key table, every value-object factory, agent and skill
patterns, orchestration, MCP client/server, embeddings, vision, streaming details, the exception
hierarchy, and the package invariants — open the matching file in the
`skills/macro-llm-php/references/` directory using the navigation table above. Do not guess at
provider or config keys: read the reference that owns them.

These docs describe the `raulast/macro-llm-php` **0.3.x** line (latest tag: `v0.3.1`), which requires
**PHP 8.1+** at runtime and supports **15 providers**.
