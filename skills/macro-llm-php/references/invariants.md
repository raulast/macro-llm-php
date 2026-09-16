# Key Invariants

Non-obvious guarantees the package relies on. Breaking one of these silently breaks consumers, so
treat each entry as a behavior contract, not a suggestion.

## Model discovery

1. **`getModels()` may hit the network** — it may make HTTP requests to discover available models, and
   returns `[]` on any failure.
2. **Discovery endpoints are attempted live first** — providers with discovery endpoints
   (OpenAI-compat, Anthropic, Gemini) attempt a live API call first.
3. **Static fallback lists** — providers without guaranteed endpoints (Anthropic, Gemini,
   opencode-zen-go-anthropic) fall back to a curated static list.

## Immutability and configuration

4. **Wire objects are immutable** — `InternalRequest` and `InternalResponse` are immutable `readonly`
   classes.
5. **`${VAR}` expansion happens at CONSTRUCTION, not lazily** — `ProviderConfig` expands `apiKey`,
   `defaultModel`, `baseUrl` and `extraHeaders` through `EnvResolver` in its constructor, because
   providers read those properties directly and never call `Config::get()`. Load your `.env` BEFORE
   building `Config`. An undefined variable is left verbatim (e.g. the literal `${OPENAI_API_KEY}`
   reaches the provider) so the misconfiguration is visible rather than masked as an empty credential.
6. **`ProviderConfig::$timeout` and `$retries` are `?int`** — `null` means "not overridden, use global
   Config value". This fixes the previous sentinel anti-pattern.

## Registries and skills

7. **Skill registration is fail-fast** — `SkillRegistry::register()` validates tool existence at
   registration time.
8. **Provider registration replaces on duplicate** — `ProviderRegistry::register()` replaces on
   duplicate provider name (no exception).
9. **`NullMemory` is the default** — agents are stateless unless `InMemoryMemory` is explicitly set.
10. **`Skill` is abstract** — `Skill::fromArray()` and `Skill::create()` return a `GenericSkill`
    instance when called directly on `Skill`. Subclasses continue to return `new static()`. No need to
    create a concrete subclass just for hydration.

## Agent execution

11. **Agent tool scope** — `Agent` executes only the tools it advertised: those resolved from its
    skills plus `AgentConfig::tools`. A `ToolDefinition` passed via `AgentConfig::tools` does NOT need
    to be registered in the global `ToolRegistry`; it is executable as-is. When a model names a tool
    outside the offered set, the agent returns a `ToolResult` in error state rather than throwing, and
    the loop continues so the model can self-correct.
12. **Empty tool arguments serialize as `{}`, never `[]`** — `ToolCall::$arguments` is a PHP array, and
    an empty array would serialize to a JSON array. Providers require an object, so the payload
    builders cast with `(object)`. A tool declared with no parameters works on OpenAI-compatible,
    Anthropic and Gemini.

## Types and payloads

13. **`Usage` token fields** — `promptTokens`, `completionTokens`, `totalTokens`; there is NO
    `inputTokens` or `outputTokens`.
14. **All package exceptions extend `MacroLLMException`** — catch-all with a single
    `catch (MacroLLMException)`.
15. **HTTP retry/backoff** — `Config` accepts `retries` (int, default 0) and `retry_delay_ms` (int,
    default 500ms). `HttpClient` retries on connect failure and HTTP 429/500/502/503 with exponential
    backoff (`delay * 2^attempt`). Per-provider override via `ProviderConfig::$retries` and
    `$retryDelayMs` (both `?int` — null = use global).

## Dependencies and integrations

16. **`illuminate/http` is NOT required** for standalone or Slim usage. It is listed under `suggest`
    in `composer.json`. Laravel users already have it installed; the `MacroLLMServiceProvider`
    registers macros in `boot()`.
17. **Slim config is user-created** — the `config/macro-llm.php` in Slim integration is created by the
    user in their project. It is NOT auto-published, unlike Laravel's `vendor:publish`.

## Test suite

18. **`composer test` runs the offline unit suite** — 553 tests / 1189 assertions, no API keys,
    hand-authored Guzzle MockHandler fixtures. `composer test:integration` runs live tests against a
    local Ollama and self-skips when it is unreachable. PHPUnit 11 is in `require-dev`, so consumers on
    PHP 8.1 are unaffected; contributors need PHP 8.2+.
19. **PHPUnit bootstrap and coverage scope** — `bootstrap=vendor/autoload.php`; the Unit suite excludes
    `src/skill-macro-llm-php/` from the `<source>` coverage set.
