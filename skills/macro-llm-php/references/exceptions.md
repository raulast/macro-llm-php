# Exceptions

Every exception the package throws, where it lives, how it is constructed, and when it fires.

## Exception catalog

All package exceptions live in the `MacroLLM\Exception` namespace. `MacroLLMException` is the abstract
base; the fifteen concrete classes below extend it.

| Exception | Namespace | Constructor args (descriptive shorthand) | When thrown |
|---|---|---|---|
| `MacroLLMException` | `MacroLLM\Exception` | — | Abstract base |
| `UnregisteredProviderException` | `MacroLLM\Exception` | `string $providerName` | Macro called for unknown provider |
| `ProviderRequestException` | `MacroLLM\Exception` | `?string $provider, int $status, string $body, ?string $endpoint = null` | HTTP 4xx/5xx from provider API |
| `SchemaException` | `MacroLLM\Exception` | static factories, e.g. `unsupportedKeyword()` | A JSON Schema cannot be expressed in the target provider dialect |
| `StructuredOutputUnsupportedException` | `MacroLLM\Exception` | `conflictsWith()` | The provider cannot honour structured output for this request at all |
| `SchemaValidationException` | `MacroLLM\Exception` | static factories, e.g. `valueMismatch()` | A value does not match a schema, or the schema cannot be enforced |
| `ProviderFailoverException` | `MacroLLM\Exception` | `exhausted()` | Every provider in the failover chain failed |
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
| `ProviderRequestException` | `$e->statusCode` | HTTP status returned by the endpoint |
| `ProviderRequestException` | `$e->responseBody` | Raw error body from the endpoint |
| `ProviderRequestException` | `$e->providerName` | Provider resolved for the request, or `null` when the transport raised the failure before attribution (HC-11) |
| `ProviderRequestException` | `$e->endpoint` | Base URL the request was sent to |
| `SchemaException` | `$e->reason` | `unsupported_keyword`, `recursive_reference`, `unresolvable_reference`, `root_not_object` or `conflicting_reference_sibling` |
| `SchemaException` | `$e->path` | Where the schema failed, e.g. `$.properties.address.additionalProperties` |
| `SchemaException` | `$e->keyword` / `$e->reference` / `$e->declaredType` | The offending keyword, `$ref`, or declared root type — whichever caused it |
| `StructuredOutputUnsupportedException` | `$e->providerName` / `$e->reason` | Which provider refused, and why (`conflicts_with_tools`, …) |
| `SchemaValidationException` | `$e->reason` | `value_mismatch` (the payload is wrong) or `unsupported_keyword` (the schema is) |
| `SchemaValidationException` | `$e->path` / `$e->keyword` / `$e->expected` / `$e->actual` | Exactly where it failed and what was found |

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
    // $e->responseBody — raw error body from provider
    Log::error("Provider error {$e->statusCode}: {$e->responseBody}");
} catch (MacroLLMException $e) {
    // Catch-all for any package exception
    Log::error($e->getMessage());
}
```

## Catch-all behavior

All package exceptions extend `MacroLLMException`, so a single `catch (MacroLLMException $e)` is enough
to catch everything the package throws.

## Source of truth

The property names above (`$e->iterations`, `$e->lastResponse`, `$e->statusCode`, `$e->responseBody`)
are verified against `src/Exception/` and `tests/Unit/Exception/ExceptionTest.php`. If the monolith
SKILL.md disagrees with the source, follow the source.
