# Providers

Provider reference for macro-llm-php: capability matrix, wire configuration, runtime capability detection, and the class that implements each provider name.

## Capability matrix

| Provider | chat | embed | image | TTS | STT | rerank |
|---|---|---|---|---|---|---|
| openai | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| anthropic | ✅ | — | — | — | — | — |
| gemini | ✅ | ✅ | ✅ | — | — | — |
| groq | ✅ | — | — | — | ✅ | — |
| openrouter | ✅ | ✅ | — | — | — | — |
| ollama | ✅ | ✅ | — | — | — | — |
| llamacpp | ✅ | ✅ | — | — | — | — |
| mistral | ✅ | ✅ | — | — | ✅ | — |
| deepseek | ✅ | — | — | — | — | — |
| xai | ✅ | ✅ | ✅ | — | — | — |
| azure | ✅ | ✅ | ✅ | — | — | — |
| cohere | ✅ | ✅ | — | — | — | ✅ |
| elevenlabs | — | — | — | ✅ | — | — |
| opencode-zen-go | ✅ | — | — | — | — | — |
| opencode-zen-go-anthropic | ✅ | — | — | — | — | — |

## Runtime capability detection

Capabilities are declared per provider class, not per provider name. Check them at runtime with `instanceof`:

```php
$provider = $llm->providers()->get('openai');

if ($provider instanceof \MacroLLM\Contract\EmbeddingProviderInterface) {
    $llm->embed(new EmbeddingRequest(['hello world']));
}
```

The same recipe applies to the other capability contracts (`ImageProviderInterface`, `AudioProviderInterface`, `RerankingProviderInterface`).

## Provider table

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
| `elevenlabs` | static | xi-api-key | api.elevenlabs.io | TTS only (AudioProviderInterface); `base_url` overridable |

## Provider class map

`ProviderFactory` resolves a provider name to the class below.

| Provider name | Implementing class |
|---|---|
| `openai` | `OpenAIProvider` |
| `anthropic` | `AnthropicProvider` |
| `gemini` | `GeminiProvider` |
| `groq` | `GroqProvider` |
| `openrouter` | `OpenRouterProvider` |
| `ollama` | `OllamaProvider` |
| `llamacpp` | `LlamaCppProvider` |
| `mistral` | `MistralProvider` |
| `deepseek` | `DeepSeekProvider` |
| `xai` | `XAIProvider` |
| `azure` | `AzureOpenAIProvider` |
| `cohere` | `CohereProvider` |
| `elevenlabs` | `ElevenLabsProvider` |
| `opencode-zen-go` | `OpenCodeZenGoProvider` |
| `opencode-zen-go-anthropic` | `OpenCodeZenGoAnthropicProvider` |

`OpenAICompatibleProvider` is the shared base class for the OpenAI-compatible providers. It implements the `/v1/chat/completions` request/response contract once: `toPayload()`, `toResponse()`, `parseStreamEvent()`, and the default `getModels()` that reads `data[].id` from the `/models` response. Subclasses (`GroqProvider`, `OpenRouterProvider`, `MistralProvider`, `DeepSeekProvider`, `XAIProvider`, `AzureOpenAIProvider`, `LlamaCppProvider`, `OllamaProvider`, `OpenCodeZenGoProvider`, `OpenAIProvider`) only override what differs, such as the base URL, endpoint path, or model-name mapping.

## Capability sub-providers

Non-chat capabilities are implemented by dedicated classes, because chat providers do not always expose the same operations:

| Class | Capability |
|---|---|
| `OpenAIEmbeddingProvider` | OpenAI embeddings |
| `OpenAIImageProvider` | OpenAI image generation |
| `OpenAIAudioProvider` | OpenAI TTS and STT (audio) |
| `CohereEmbeddingProvider` | Cohere embeddings |
| `CohereRerankingProvider` | Cohere reranking |
| `GeminiEmbeddingProvider` | Gemini embeddings |
| `OllamaEmbeddingProvider` | Local Ollama embeddings |

## Failover

Configure a chain and a failed request hands over to the next provider instead of failing:

```php
'providers' => [
    'openai' => [
        'api_key' => '${OPENAI_API_KEY}',
        'default_model' => 'gpt-4o',
        'fallback' => ['anthropic', 'gemini'],   // ordered, and empty by default
    ],
    // …the providers named above must each be configured too
],
```

**Which failures hand over — and which do not.** This is the part that protects your wallet:

| Failure | Hands over? | Why |
| --- | --- | --- |
| `429`, `500`, `502`, `503`, `504` | **yes** | A rate limit or a broken provider is what a second provider is for. |
| Connection failure / timeout | **yes** | Nothing was reached at all. |
| Any other `4xx` (`400`, `401`, `403`, `404`, `422`, …) | **no** | It is a verdict on the *request*, and the next provider renders the same verdict. |
| A schema, capability or configuration error | **no** | Not a transport problem, so another provider cannot fix it. |

**A `401` never hands over, deliberately.** A hop spends the next provider's key, and a misconfigured key would
spend a second account to learn nothing — turning one clear error into a confusing chain of them.

**Knowing who answered.** `InternalResponse::$providerName` names the provider that actually produced the response,
which with a chain is not necessarily the one you asked for:

```php
$response = $llm->chat($request, 'openai');
if ($response->providerName !== 'openai') {
    // the chain moved: log it, alert, or adapt
}
```

**When every provider fails**, `ProviderFailoverException` is raised with `$e->causes` — every failure keyed by the
provider that produced it, in the order they were tried — so "the first was rate limited and the second was down"
reaches you intact instead of collapsing into the last error.

**Streaming hops only before the stream starts.** Once bytes are flowing, switching providers would splice two
different responses into one, so the chain covers the request that opens the stream and nothing after it. Providers
in the chain that cannot stream are skipped for streaming requests.

## DeepSeek compatibility note

DeepSeek is dual-compatible. It serves an OpenAI-compatible surface at `https://api.deepseek.com` (`/v1`) and an Anthropic-compatible surface at `https://api.deepseek.com/anthropic`, which serves `POST /v1/messages`.

This package ships only the OpenAI-compatible `DeepSeekProvider`, registered under the name `deepseek` and derived from `OpenAICompatibleProvider` with a static model list. The Anthropic-compatible DeepSeek surface is not implemented; no provider name maps to it.
