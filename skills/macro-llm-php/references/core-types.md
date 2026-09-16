# Core Types

The value objects, enums, registries, and orchestration primitives that make up the
MacroLLM core domain, with their canonical namespace and purpose.

## Namespace root

`MacroLLM\` is the namespace root, PSR-4 mapped to `src/`. Every class below resolves
to a file under that tree (for example `MacroLLM\Message\InternalRequest` maps to
`src/Message/InternalRequest.php`).

## Type table

| Class | Namespace | Description |
|---|---|---|
| `MacroLLM` | `MacroLLM` | Main entry point |
| `InternalRequest` | `MacroLLM\Message` | Provider-agnostic request |
| `InternalResponse` | `MacroLLM\Message` | Provider-agnostic response |
| `InternalMessage` | `MacroLLM\Message` | Single conversation turn (text or `ContentPart[]` for multimodal) |
| `StreamChunk` | `MacroLLM\Message` | Streaming delta |
| `Usage` | `MacroLLM\Message` | Token usage metadata |
| `Role` (enum) | `MacroLLM\Message` | system\|user\|assistant\|tool |
| `FinishReason` (enum) | `MacroLLM\Message` | Stop\|ToolCalls\|Length\|ContentFilter\|Error |
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

## Additional types

These types live in the same core domain but were missing from the earlier summary table.
They are documented here so the full surface is discoverable from one place.

| Class | Namespace | Description |
|---|---|---|
| `EnvResolver` | `MacroLLM\Config` | Expands `${VAR}` placeholders from the process environment (`$_ENV` then `getenv()`); unresolved placeholders are left untouched |
| `ToolStatus` (enum) | `MacroLLM\Tool` | Execution outcome of a tool call: ok\|error |
| `ImageSize` (enum) | `MacroLLM\Message` | Aspect ratio hint for image generation: square\|portrait\|landscape |
| `EmbeddingRequest` | `MacroLLM\Message` | Inputs plus optional dimensions and model |
| `EmbeddingResponse` | `MacroLLM\Message` | Vector list plus `Usage` |
| `ImageRequest` | `MacroLLM\Message` | Prompt, `ImageSize`, quality, count, and optional model |
| `ImageResponse` | `MacroLLM\Message` | Generated images (base64) with a `first()` helper |
| `AudioRequest` | `MacroLLM\Message` | Text-to-speech input: text, voice, instructions, format, model |
| `AudioResponse` | `MacroLLM\Message` | Raw binary audio plus format, with `store(string $path)` |
| `TranscriptionRequest` | `MacroLLM\Message` | Speech-to-text input: file path, optional language and model |
| `TranscriptionResponse` | `MacroLLM\Message` | Transcribed text plus optional diarization segments |
| `RerankingRequest` | `MacroLLM\Message` | Query plus candidate documents, optional limit and model |
| `RerankingResponse` | `MacroLLM\Message` | Ranked results with a `first()` helper |
| `RankedDocument` | `MacroLLM\Message` | A document with its original index and relevance score |
| `ToolRegistry` | `MacroLLM\Registry` | Registers tool definitions and resolves them by name |
| `SkillRegistry` | `MacroLLM\Registry` | Registers skills after validating their referenced tools exist |
| `ProviderRegistry` | `MacroLLM\Registry` | Registers provider instances and resolves them by name |
| `FiberStrategy` | `MacroLLM\Orchestration\Strategy` | Secondary concurrency strategy using PHP Fibers for cooperative scheduling |
| `GuzzleConcurrentStrategy` | `MacroLLM\Orchestration\Strategy` | Primary concurrency strategy using Guzzle Promises (`Utils::settle`) |

## Enum values

Backing values are part of the wire contract with providers. Use these exact strings;
a case performed against the wrong backing value silently changes behavior.

- `Role` (string, `MacroLLM\Message`): `system`, `user`, `assistant`, `tool`
- `FinishReason` (string, `MacroLLM\Message`): `stop`, `tool_calls`, `length`,
  `content_filter`, `error`
- `ContentPartType` (string, `MacroLLM\Message`): `text`, `image_url`, `image_base64`
- `AgentStepType` (string, `MacroLLM\Agent`): `llm_response`, `tool_call`,
  `tool_result`, `final_response`
- `ToolStatus` (string, `MacroLLM\Tool`): `ok`, `error`
- `ImageSize` (string, `MacroLLM\Message`): `square`, `portrait`, `landscape`
- `RoutingStrategy` (pure enum, `MacroLLM\Orchestration`): no backing value; cases are
  `Sequential`, `Parallel`, `Conditional`
- `ErrorStrategy` (pure enum, `MacroLLM\Orchestration`): no backing value; cases are
  `Stop`, `Continue`

### Correction: `FinishReason::Error` exists

Earlier documentation listed `FinishReason` as only `Stop|ToolCalls|Length|ContentFilter`.
That table was **stale**. The source declares a fifth case, `Error`, backed by `'error'`.
Code that switches over `FinishReason` must handle `Error` as well, or it will fall
through to an unhandled branch.

## Immutability and dependency boundaries

Request and response value objects are `readonly`: properties are set once in the
constructor and never mutated afterwards, so a request or response instance can be
safely shared, cached, or passed between layers without defensive copying. The core
domain — `Message`, `Contract`, and `Registry` — has zero framework dependencies. Nothing
in those namespaces imports Laravel, Slim, or any other integration package, which keeps
the domain layer portable and testable in isolation. Framework glue lives only in
`MacroLLM\Integration`.
