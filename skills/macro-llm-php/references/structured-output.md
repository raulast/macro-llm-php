# Structured output — the schema engine

Structured output asks a provider to return JSON that matches a JSON Schema you supply. The package
cannot send your schema verbatim to every provider, because each one accepts a different subset of
JSON Schema — so the schema is passed through an engine first.

## The doctrine: refuse, do not drop

The engine performs exactly two transformations, and both preserve your meaning:

1. **Annotation-only keywords the target rejects are dropped** — `$id`, `$schema`, `$comment`, `examples`,
   `readOnly`, `writeOnly`, `deprecated`. Dropping an annotation changes no validation outcome.
2. **Local `$ref` pointers are inlined** for dialects that cannot receive a reference at all.

**Everything else is either kept or refused.** A *validation* keyword the target does not support raises
`SchemaException` instead of disappearing. The reason is worth stating plainly: a schema that arrives
without its constraint still produces a successful-looking request, so a silent drop turns "the model is
verified against this shape" into a comfortable lie. A named exception costs you five minutes; a silently
weakened contract costs you a production bug.

## `SchemaDialect`

```php
use MacroLLM\Schema\SchemaDialect;

SchemaDialect::OpenAi;   // OpenAI and every OpenAI-compatible provider
SchemaDialect::Gemini;   // Google Gemini
```

| Method | Meaning |
| --- | --- |
| `supportedKeywords(): array` | Keywords this dialect accepts, and that are therefore kept |
| `droppedKeywords(): array` | Annotation-only keywords dropped silently, because no validation outcome changes |
| `inlinesReferences(): bool` | Whether `$ref` must be inlined because the dialect cannot receive one |
| `allowsRecursion(): bool` | Whether the dialect can express a recursive schema at all |

**OpenAI accepts references and recursion**, so `$ref` and `$defs` are passed through untouched: inlining
there would be a defect, not a service — it would break every recursive schema the provider supports. Its
documented unsupported composition is `allOf`, `not`, `dependentRequired`, `dependentSchemas`, `if`,
`then`, `else`. `oneOf` is currently **refused** for OpenAI as the conservative choice: the provider's
documentation never demonstrates it, so the safe direction is to refuse with a message that points at
`anyOf`, which is supported.

**Gemini cannot receive `$ref`**, so references are inlined and `$defs` is removed. Because inlining a cycle
can never terminate, a recursive schema is refused by name rather than looping.

## `SchemaNormalizer`

```php
use MacroLLM\Schema\SchemaDialect;
use MacroLLM\Schema\SchemaNormalizer;
use MacroLLM\Exception\SchemaException;

$normalizer = new SchemaNormalizer();

try {
    $schema = $normalizer->normalize($mySchema, SchemaDialect::Gemini);
} catch (SchemaException $e) {
    // $e->reason     — 'unsupported_keyword' | 'recursive_reference'
    //                | 'unresolvable_reference' | 'root_not_object'
    // $e->dialect    — 'openai' | 'gemini'
    // $e->path       — where it failed, e.g. '$.properties.address.additionalProperties'
    // $e->keyword    — the offending keyword, when a keyword is the cause
    // $e->reference  — the offending `$ref`, when a reference is the cause
    // $e->declaredType — the declared type, when the root is not an object
    report($e->getMessage());
}
```

The root schema must describe an object. Only local pointers (`#/$defs/...`, `#/definitions/...`) are
resolved; external and remote references are refused, not fetched.

**Status: the providers do not call the normalizer for you yet.** As of this version you normalize the
schema yourself before handing it to `ResponseFormat::jsonSchema()`. Provider-side wiring is the next step
of the same work, and this paragraph is what will change when it lands — not a claim that it already works.

## Example: a schema that survives both dialects

```php
$schema = [
    'type' => 'object',
    'properties' => [
        'city' => ['type' => 'string', 'description' => 'City name'],
        'population' => ['type' => 'integer'],
    ],
    'required' => ['city'],
];

// Both dialects accept this, and both keep 'description' and 'required'.
$forOpenAi = (new SchemaNormalizer())->normalize($schema, SchemaDialect::OpenAi);
$forGemini = (new SchemaNormalizer())->normalize($schema, SchemaDialect::Gemini);
```

A schema that does **not** survive both, and what to do about it:

```php
$schema = [
    'type' => 'object',
    'additionalProperties' => false,   // ← OpenAI requires this under strict mode
    'properties' => ['name' => ['type' => 'string']],
];

(new SchemaNormalizer())->normalize($schema, SchemaDialect::Gemini);
// SchemaException: Schema keyword "additionalProperties" at $.additionalProperties is not supported
// by the "gemini" dialect, and it will not be dropped silently: dropping it would weaken the
// contract while still looking like success.
```

The fix is to keep one schema per dialect when the two genuinely disagree — which is honest, because the
two providers genuinely disagree.
