# Exceptions

Every exception the package throws, where it lives, how it is constructed, and when it fires.

## Exception catalog

All package exceptions live in the `MacroLLM\Exception` namespace. `MacroLLMException` is the abstract
base; the eleven concrete classes below extend it.

| Exception | Namespace | Constructor args | When thrown |
|---|---|---|---|
| `MacroLLMException` | `MacroLLM\Exception` | — | Abstract base |
| `UnregisteredProviderException` | `MacroLLM\Exception` | `string $providerName` | Macro called for unknown provider |
| `ProviderRequestException` | `MacroLLM\Exception` | `string $provider, int $status, string $body` | HTTP 4xx/5xx from provider API |
| `MissingApiKeyException` | `MacroLLM\Exception` | `string $provider` | API key missing before request |
| `StreamInterruptedException` | `MacroLLM\Exception` | `array $chunks` | SSE stream dropped before finish |
| `ToolNotFoundException` | `MacroLLM\Exception` | `string $toolName` | `ToolRegistry::get()` asked for a name never registered |
| `MaxToolIterationsException` | `MacroLLM\Exception` | `int $iterations, InternalResponse $last` | Agent loop hit iteration cap |
| `SkillToolNotFoundException` | `MacroLLM\Exception` | `string $skillName, string $toolName` | Skill references unregistered tool (at registration) |
| `SkillToolConflictException` | `MacroLLM\Exception` | `string $toolName, string $skill1, string $skill2` | Two skills define same tool |
| `MCPConnectionException` | `MacroLLM\Exception` | `string $url, string $detail` | MCP server unreachable |
| `MCPToolCallException` | `MacroLLM\Exception` | `string $toolName, int $code, string $message` | MCP server returned error |
| `ContainerBindingException` | `MacroLLM\Exception` | `string $containerClass` | PSR-11 container is read-only |

## Exposed properties

Two exceptions carry recoverable state beyond the standard message and code:

| Exception | Property | Meaning |
|---|---|---|
| `MaxToolIterationsException` | `$e->iterations` | How many iterations ran |
| `MaxToolIterationsException` | `$e->lastResponse` | Last `InternalResponse` before the exception |
| `ProviderRequestException` | `$e->statusCode` | HTTP status returned by the provider |
| `ProviderRequestException` | `$e->providerBody` | Raw error body from the provider |

`MaxToolIterationsException::$lastResponse` is the escape hatch for partial work: read
`$e->lastResponse->content` instead of discarding the turn.

## Recommended catch patterns

Order the `catch` blocks from most specific to least specific. `MaxToolIterationsException` and
`ProviderRequestException` both extend `MacroLLMException`, so they must be caught before the base
class, otherwise the catch-all swallows them.

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

## Catch-all behavior

All package exceptions extend `MacroLLMException`, so a single `catch (MacroLLMException $e)` is enough
to catch everything the package throws.

## Source of truth

The property names above (`$e->iterations`, `$e->lastResponse`, `$e->statusCode`, `$e->providerBody`)
are verified against `src/Exception/` and `tests/Unit/Exception/ExceptionTest.php`. If the monolith
SKILL.md disagrees with the source, follow the source.
