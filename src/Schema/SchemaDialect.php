<?php

declare(strict_types=1);

namespace MacroLLM\Schema;

/**
 * What a provider's structured-output channel actually accepts.
 *
 * The allow-lists are **data, not guesses**, and each carries the evidence it came from. A wrong list fails
 * in both directions: too permissive earns a provider 400, too strict rejects schemas the provider would
 * have accepted.
 *
 * Evidence classes:
 *  - **OpenAi** — read from the structured-outputs guide: `$defs` + `$ref` are supported *including
 *    recursive schemas*, `anyOf` is supported, and the unsupported composition list is stated explicitly
 *    as `allOf`, `not`, `dependentRequired`, `dependentSchemas`, `if`, `then`, `else`.
 *  - **Gemini** — **verified** against the Gemini API reference, which enumerates the properties its
 *    JSON-Schema channel supports: `$id`, `$defs`, `$ref`, `$anchor`, `type`, `format`, `title`,
 *    `description`, `enum`, `items`, `prefixItems`, `minItems`, `maxItems`, `minimum`, `maximum`, `anyOf`,
 *    `oneOf` (interpreted as `anyOf`), `properties`, `additionalProperties`, `required`, plus the
 *    non-standard `propertyOrdering`. **The verification corrected this list in EIGHT places.** The
 *    provisional list this class first shipped refused `additionalProperties`, `$ref` and `oneOf` by
 *    mistake, and allowed `minLength`, `maxLength`, `pattern`, `nullable` and `default` by mistake — every
 *    one of those eight is now pinned by a test. That is the concrete cost of guessing an allow-list, and
 *    the reason the doctrine here is verify-first.
 *
 *  - **The other Gemini channel.** The reference marks `responseSchema` and `_responseJsonSchema` deprecated
 *    in favour of `responseFormat`, and the OpenAPI-subset `Schema` proto is a *different* field list from
 *    the JSON-Schema one (it carries `nullable`, `minProperties`, `ref`/`defs` without the `$`). This enum
 *    models the **JSON-Schema channel**, because that is what the normalizer emits. A caller pinned to an
 *    older model that only accepts the OpenAPI subset needs its own dialect case; that is recorded as an
 *    open follow-up rather than papered over.
 *
 *  - **Cohere** — verified against Cohere's structured-outputs guide and its Chat v2 reference: strings, integers,
 *    floats, booleans, arrays (including lists of lists), nested objects, enum, const, pattern, format,
 *    `additionalProperties` and `anyOf` are supported, and `$ref`/`$def` are supported too. Composition
 *    (`allOf`, `oneOf`, `not`), numeric ranges (`minimum`, `maximum`), length ranges (`minItems`, `maxItems`,
 *    `minLength`, `maxLength`) and `uniqueItems` are explicitly unsupported.
 *
 *    Cohere names its definitions **`$def`**, singular — while the JSON Schema convention, and therefore what a
 *    caller writes, is `$defs`. Rather than rename the keyword, this dialect inlines references, which makes the
 *    mismatch unreachable. Three constraints from the same reference are recorded but not enforced, because each
 *    fails loudly at the provider: every object must declare at least one `required` field; `format` accepts only
 *    `date-time`, `uuid`, `date` and `time`; and `response_format` is unsupported in combination with `documents`
 *    or `tools` — that last one IS enforced, by `CohereProvider`, because it would otherwise be ignored rather
 *    than rejected.
 *
 *  - **Why `$ref` is not in the kept list.** Gemini accepts `$ref`, but the normalizer inlines references
 *    for it: the provider "unrolls cyclic references to a limited degree, and only within non-required
 *    properties". Inlining removes that trap entirely for non-recursive schemas, and refuses recursion by
 *    name for the rest — a better worst case than sending a cycle the provider may mishandle.
 *
 * `oneOf` is treated as unsupported for OpenAI **pending verification**: the guide's unsupported list does
 * not name it and no example uses it, so the safe direction is to reject it with a message that points at
 * `anyOf`. That single entry is the one deliberate conservatism in the OpenAI list.
 */
enum SchemaDialect: string
{
    case OpenAi = 'openai';
    case Gemini = 'gemini';
    case Cohere = 'cohere';

    /**
     * Keywords this dialect accepts and that the normalizer therefore **keeps** in the emitted schema.
     *
     * A keyword the provider accepts but the normalizer transforms away (such as `$ref` where references are
     * inlined) deliberately does not appear here: this list describes the emitted shape, not the provider's
     * full capability.
     *
     * @return list<string>
     */
    public function supportedKeywords(): array
    {
        return match ($this) {
            self::OpenAi => [
                // Core shape
                'type', 'properties', 'required', 'items', 'additionalProperties',
                // Values
                'enum', 'const',
                // Composition OpenAI documents as supported
                'anyOf',
                // References OpenAI documents as supported, recursive schemas included
                '$ref', '$defs', 'definitions',
                // Annotations OpenAI examples rely on
                'title', 'description',
                // Type-specific constraints, supported for non-fine-tuned models
                'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
                'minLength', 'maxLength', 'pattern', 'format',
                'minItems', 'maxItems', 'uniqueItems', 'patternProperties',
            ],
            self::Gemini => [
                // Verified against the Gemini API reference's own enumeration of the properties its
                // JSON-Schema channel supports.
                'type', 'format', 'title', 'description',
                'enum', 'items', 'prefixItems', 'minItems', 'maxItems', 'minimum', 'maximum',
                'anyOf', 'oneOf',
                'properties', 'additionalProperties', 'required',
                'propertyOrdering',
            ],
            self::Cohere => [
                // Verified against Cohere's structured-outputs guide and Chat v2 reference.
                'type', 'properties', 'required', 'items',
                'enum', 'const', 'pattern', 'format',
                'additionalProperties', 'anyOf',
            ],
        };
    }

    /**
     * Annotation-only keywords this dialect does not accept.
     *
     * Dropping these is safe **because they change no validation outcome** — unlike a validation keyword,
     * whose removal would leave the caller believing a constraint is enforced when it is not.
     *
     * @return list<string>
     */
    public function droppedKeywords(): array
    {
        return [
            '$schema', '$id', '$comment',
            'examples', 'example', 'default',
            'readOnly', 'writeOnly', 'deprecated',
        ];
    }

    /**
     * Whether a `$ref` has to be inlined because the dialect cannot receive one.
     *
     * OpenAI accepts references, so inlining there would be a defect rather than a service: it would break
     * every recursive schema the provider explicitly supports. Gemini also accepts them, and they are still
     * inlined — the provider only unrolls cyclic references inside non-required properties, and inlining
     * sidesteps that limitation for every schema that is not actually recursive.
     */
    public function inlinesReferences(): bool
    {
        return $this === self::Gemini || $this === self::Cohere;
    }

    /**
     * Whether the dialect can express recursion. A dialect that must inline references cannot, because
     * inlining a cycle never terminates.
     */
    public function allowsRecursion(): bool
    {
        return $this === self::OpenAi;
    }
}
