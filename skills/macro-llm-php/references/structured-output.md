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
| `inlinesReferences(): bool` | Whether `$ref` is inlined by the normalizer rather than passed through |
| `allowsRecursion(): bool` | Whether the dialect can express a recursive schema at all |

**OpenAI accepts references and recursion**, so `$ref` and `$defs` are passed through untouched: inlining
there would be a defect, not a service — it would break every recursive schema the provider supports. Its
documented unsupported composition is `allOf`, `not`, `dependentRequired`, `dependentSchemas`, `if`,
`then`, `else`. `oneOf` is currently **refused** for OpenAI as the conservative choice: the provider's
documentation never demonstrates it, so the safe direction is to refuse with a message that points at
`anyOf`, which is supported.

**Gemini accepts a JSON-Schema channel whose supported keyword list is enumerated by the API reference**:
`$id`, `$defs`, `$ref`, `$anchor`, `type`, `format`, `title`, `description`, `enum`, `items`, `prefixItems`,
`minItems`, `maxItems`, `minimum`, `maximum`, `anyOf`, `oneOf` (interpreted as `anyOf`), `properties`,
`additionalProperties`, `required`, plus the non-standard `propertyOrdering`. Anything outside that list —
`allOf`, `not`, `const`, `uniqueItems`, `minLength`, `maxLength`, `pattern`, `minProperties` — is refused by
name.

Even though `$ref` is supported there, this engine **inlines** references for Gemini. The reference states that
cyclic references are "unrolled to a limited degree, and only within non-required properties"; inlining sidesteps
that limitation for every schema that is not actually recursive, and refuses real recursion by name. That is the
better worst case.

**Siblings beside a `$ref` are kept, because in JSON Schema they all apply.** `{$ref: X, "format": "date"}`
means "matches X **and** the format is a date", so a constraint you wrote next to a reference is merged in rather
than dropped. When a sibling and the definition both set the same keyword, the request is **refused by name**:
merging would have to choose a winner, and choosing in silence is the thing this engine exists to refuse.
`required` is unioned and `properties` are merged key by key, because neither of those involves a choice.

Two things worth knowing about that channel: the reference marks it deprecated in favour of `responseFormat`,
and the older OpenAPI-subset `Schema` proto is a **different** field list — it carries `nullable` and
`minProperties`, and it names its references `ref`/`defs` without the `$`. This engine models the JSON-Schema
channel, because that is what it emits. A model pinned to the OpenAPI subset would need its own dialect case.

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

## What each provider family emits

| Family | Emission | Schema handling |
| --- | --- | --- |
| OpenAI-compatible (10 providers) | `response_format.json_schema` with `name`, `schema`, `strict` | normalized for `SchemaDialect::OpenAi`; when `strict: true`, completed for strict mode |
| Gemini | `generationConfig.responseMimeType` + `responseJsonSchema` | normalized for `SchemaDialect::Gemini` |
| Cohere | `response_format.json_schema` beside `type: json_object` | normalized for `SchemaDialect::Cohere` |
| Anthropic | a forced tool: `structured_output` + `tool_choice` | passed through — Anthropic takes plain JSON Schema as a tool `input_schema`, and this package makes no claim about its subset |

`ResponseFormat::json()` (the schema-less JSON mode) emits the family's JSON mode without a schema, on every
family except Anthropic, which has no such mode and says so.

**They disagree about how to express the same request, and the package absorbs that rather than passing it on.**
Anthropic has no `response_format` at all, so a schema is enforced by forcing a single tool call: the schema
becomes the tool's `input_schema`, `tool_choice` pins it, and the answer arrives as that tool's input — which the
provider unwraps back into `content`, with `finishReason` reported as `Stop` because nothing was actually called.
**`structured_output` is therefore a reserved tool name**: a caller tool using it is refused rather than silently
shadowed.

**A provider that cannot honour the request raises instead of pretending.** `StructuredOutputUnsupportedException`
means the provider has no way to enforce this at all; `SchemaException` means the schema is wrong for the dialect.
Both surface at the call site, rather than as prose from an endpoint you believed was constrained.

**Annotating `strict`.** Only OpenAI-compatible providers have the flag. `strict: true` also completes the
schema, because strict mode requires `additionalProperties: false` on every object and every property listed in
`required` — strict mode has no notion of an optional field, so optionality is expressed as a nullable type. If
you need your schema to travel byte-identical, pass `strict: false`.

## Status: partial, and stated as such

Emission works for **all four families**: OpenAI-compatible, Gemini, Cohere and Anthropic. One thing is still
missing and this section is what changes when it lands:

- **The streaming and agent paths do not carry `responseFormat` yet**, so structured output currently applies to a
direct `chat()` call. (`2.4` of the same work.)

**One combination is refused outright:** Cohere's reference documents `response_format` as unsupported alongside
`documents` or `tools`, and the package raises `StructuredOutputUnsupportedException` rather than sending a
request whose constraint would be dropped. Same exception type, same reason, for every provider that has no way
to honour a format at all — it is deliberately distinct from `SchemaException`, which means the schema itself is
wrong for the target.

You can always normalize by hand, which is also the way to target a dialect before it is wired up:

```php
use MacroLLM\Schema\SchemaDialect;
use MacroLLM\Schema\SchemaNormalizer;

$schema = (new SchemaNormalizer())->normalize($mySchema, SchemaDialect::Gemini);
```

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
