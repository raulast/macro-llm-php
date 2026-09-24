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
 *  - **Gemini** — the conservative `responseSchema` subset (a documented subset of OpenAPI 3.0), taken from
 *    a secondary source rather than the reference itself: **provisional, verified against the Gemini Schema
 *    reference in unit 2.2**, where any correction lands with a test.
 *
 * `oneOf` is treated as unsupported for OpenAI **pending verification**: the guide's unsupported list does
 * not name it and no example uses it, so the safe direction is to reject it with a message that points at
 * `anyOf`. That single entry is the one deliberate conservatism in the OpenAI list.
 */
enum SchemaDialect: string
{
    case OpenAi = 'openai';
    case Gemini = 'gemini';

    /**
     * Keywords this dialect accepts, and that MUST therefore be kept.
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
                // The responseSchema field list
                'type', 'properties', 'required', 'items', 'enum', 'nullable',
                'format', 'description', 'title',
                'minimum', 'maximum', 'minItems', 'maxItems', 'minLength', 'maxLength', 'pattern',
                'default', 'propertyOrdering',
                // Gemini documents anyOf for conditional schemas
                'anyOf',
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
            'examples', 'example',
            'readOnly', 'writeOnly', 'deprecated',
        ];
    }

    /**
     * Whether a `$ref` has to be inlined because the dialect cannot receive one.
     *
     * OpenAI accepts references, so inlining there would be a defect rather than a service: it would break
     * every recursive schema the provider explicitly supports.
     */
    public function inlinesReferences(): bool
    {
        return $this === self::Gemini;
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
