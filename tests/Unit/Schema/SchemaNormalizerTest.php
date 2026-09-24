<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Schema;

use MacroLLM\Exception\SchemaException;
use MacroLLM\Schema\SchemaDialect;
use MacroLLM\Schema\SchemaNormalizer;
use MacroLLM\Tests\TestCase;

/**
 * The normalizer's contract: a schema either comes out in a shape the target accepts, or the call fails
 * with a named reason. It never drops a validation keyword, because dropping one silently weakens the
 * contract while looking like success.
 *
 * Allow-list provenance:
 *  - OpenAI: read from the structured-outputs guide. `$defs` + `$ref` (including RECURSIVE schemas) are
 *    supported, `anyOf` is supported, and the unsupported composition list is explicit: `allOf`, `not`,
 *    `dependentRequired`, `dependentSchemas`, `if`, `then`, `else`.
 *  - Gemini: **verified** against the Gemini API reference, which enumerates the properties its JSON-Schema
 *    channel supports. The verification corrected the provisional list in EIGHT places, and every one of
 *    those corrections is pinned by a test.
 */
final class SchemaNormalizerTest extends TestCase
{
    private function normalizer(): SchemaNormalizer
    {
        return new SchemaNormalizer();
    }

    // ── OpenAI: references stay, because OpenAI supports them ───────────────

    public function test_openai_keeps_defs_and_ref_because_recursive_schemas_are_supported(): void
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'next' => ['$ref' => '#/$defs/node'],
            ],
            'required' => ['next'],
            '$defs' => [
                'node' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => ['value' => ['type' => 'string']],
                    'required' => ['value'],
                ],
            ],
        ];

        $result = $this->normalizer()->normalize($schema, SchemaDialect::OpenAi);

        $this->assertSame(['$ref' => '#/$defs/node'], $result['properties']['next']);
        $this->assertArrayHasKey('$defs', $result, 'OpenAI supports $defs; inlining them would break recursion');
    }

    public function test_openai_accepts_any_of(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'value' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
            ],
        ];

        $result = $this->normalizer()->normalize($schema, SchemaDialect::OpenAi);

        $this->assertSame(
            [['type' => 'string'], ['type' => 'null']],
            $result['properties']['value']['anyOf'],
        );
    }

    /** `allOf` heads OpenAI's explicit unsupported-composition list. */
    public function test_openai_rejects_all_of_with_a_named_reason(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'value' => ['allOf' => [['type' => 'string']]],
            ],
        ];

        try {
            $this->normalizer()->normalize($schema, SchemaDialect::OpenAi);
            $this->fail('Expected a SchemaException.');
        } catch (SchemaException $e) {
            $this->assertSame('unsupported_keyword', $e->reason);
            $this->assertSame('allOf', $e->keyword);
            $this->assertSame('openai', $e->dialect);
            $this->assertSame('$.properties.value.allOf', $e->path);
            $this->assertStringContainsString('allOf', $e->getMessage());
        }
    }

    // ── Gemini: references are inlined, cycles are impossible ───────────────

    public function test_gemini_inlines_refs_and_drops_defs(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'address' => ['$ref' => '#/$defs/address'],
            ],
            '$defs' => [
                'address' => [
                    'type' => 'object',
                    'properties' => ['city' => ['type' => 'string']],
                    'required' => ['city'],
                ],
            ],
        ];

        $result = $this->normalizer()->normalize($schema, SchemaDialect::Gemini);

        $this->assertSame('object', $result['properties']['address']['type']);
        $this->assertSame(['type' => 'string'], $result['properties']['address']['properties']['city']);
        $this->assertArrayNotHasKey('$defs', $result, 'a dialect that cannot send $ref cannot send $defs either');
    }

    public function test_gemini_rejects_a_validation_keyword_it_does_not_support(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'allOf' => [['minLength' => 1]]],
            ],
        ];

        try {
            $this->normalizer()->normalize($schema, SchemaDialect::Gemini);
            $this->fail('Expected a SchemaException.');
        } catch (SchemaException $e) {
            $this->assertSame('unsupported_keyword', $e->reason);
            $this->assertSame('allOf', $e->keyword);
            $this->assertSame('$.properties.name.allOf', $e->path);
        }
    }

    /**
     * The user-visible consequence of the allow-list verification: this schema was refused before it and is
     * accepted now, because the Gemini reference supports `additionalProperties` and the provisional list
     * did not. Pinned end-to-end rather than only on the enum.
     */
    public function test_gemini_accepts_additional_properties_now_that_the_list_is_verified(): void
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ];

        $result = $this->normalizer()->normalize($schema, SchemaDialect::Gemini);

        $this->assertFalse($result['additionalProperties']);
        $this->assertSame(['name'], $result['required']);
    }

    /**
     * `oneOf` is the other half of the same correction, and it must be walked as a container: a violation
     * nested inside a branch has to be found, not skipped.
     */
    public function test_gemini_accepts_one_of_and_still_walks_its_branches(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'value' => [
                    'oneOf' => [
                        ['type' => 'string'],
                        ['type' => 'object', 'properties' => ['x' => ['type' => 'string', 'const' => 'a']]],
                    ],
                ],
            ],
        ];

        try {
            $this->normalizer()->normalize($schema, SchemaDialect::Gemini);
            $this->fail('Expected a SchemaException: `const` is unsupported, even inside a oneOf branch.');
        } catch (SchemaException $e) {
            $this->assertSame('const', $e->keyword);
            $this->assertSame('$.properties.value.oneOf.1.properties.x.const', $e->path);
        }
    }

    /** A cycle cannot be inlined: it would never terminate, so it fails by name instead. */
    public function test_gemini_rejects_a_recursive_reference(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['next' => ['$ref' => '#/$defs/node']],
            '$defs' => [
                'node' => [
                    'type' => 'object',
                    'properties' => ['next' => ['$ref' => '#/$defs/node']],
                ],
            ],
        ];

        try {
            $this->normalizer()->normalize($schema, SchemaDialect::Gemini);
            $this->fail('Expected a SchemaException.');
        } catch (SchemaException $e) {
            $this->assertSame('recursive_reference', $e->reason);
            $this->assertSame('#/$defs/node', $e->reference);
        }
    }

    public function test_unresolvable_reference_fails_by_name(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['x' => ['$ref' => '#/$defs/missing']],
        ];

        try {
            $this->normalizer()->normalize($schema, SchemaDialect::Gemini);
            $this->fail('Expected a SchemaException.');
        } catch (SchemaException $e) {
            $this->assertSame('unresolvable_reference', $e->reason);
            $this->assertSame('#/$defs/missing', $e->reference);
            $this->assertSame('$.properties.x.$ref', $e->path);
        }
    }

    // ── Structured output: normalization, strict completion, refusal ───────

    /**
     * Strict mode has no notion of an optional field: OpenAI requires every object to set
     * `additionalProperties: false` and every property to appear in `required`. Both additions STRENGTHEN the
     * contract, which is why completing them is not the silent degradation the engine otherwise refuses.
     */
    public function testStrictModeCompletionAddsTheTwoThingsStrictModeRequires(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age' => ['type' => 'integer'],
            ],
        ];

        $completed = (new SchemaNormalizer())->completeForStrictMode($schema);

        $this->assertFalse($completed['additionalProperties']);
        $this->assertSame(['name', 'age'], $completed['required']);
    }

    public function testStrictModeCompletionReachesNestedObjectsAndArrays(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'address' => [
                    'type' => 'object',
                    'properties' => ['city' => ['type' => 'string']],
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['label' => ['type' => 'string']],
                    ],
                ],
            ],
        ];

        $completed = (new SchemaNormalizer())->completeForStrictMode($schema);

        $this->assertFalse($completed['properties']['address']['additionalProperties']);
        $this->assertSame(['city'], $completed['properties']['address']['required']);
        $this->assertFalse($completed['properties']['tags']['items']['additionalProperties']);
        $this->assertSame(['label'], $completed['properties']['tags']['items']['required']);
    }

    public function testStrictModeCompletionKeepsAnExistingRequiredOrderAndAppendsTheRest(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']],
            'required' => ['b'],
        ];

        $completed = (new SchemaNormalizer())->completeForStrictMode($schema);

        $this->assertSame(['b', 'a'], $completed['required'], "the caller's order is preserved, not replaced");
    }

    /**
     * A schema-valued `additionalProperties` is an explicit choice the caller made; strict mode cannot honour
     * it, so it is left alone rather than silently rewritten to `false`.
     */
    public function testStrictModeCompletionDoesNotOverwriteASchemaValuedAdditionalProperties(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'string']],
            'additionalProperties' => ['type' => 'string'],
        ];

        $completed = (new SchemaNormalizer())->completeForStrictMode($schema);

        $this->assertSame(['type' => 'string'], $completed['additionalProperties']);
    }

    public function testStrictModeCompletionAlsoReachesDefsAndCompositionBranches(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['value' => ['anyOf' => [['type' => 'object', 'properties' => ['x' => ['type' => 'string']]]]]],
            '$defs' => ['thing' => ['type' => 'object', 'properties' => ['y' => ['type' => 'integer']]]],
        ];

        $completed = (new SchemaNormalizer())->completeForStrictMode($schema);

        $this->assertSame(
            ['x'],
            $completed['properties']['value']['anyOf'][0]['required'],
        );
        $this->assertSame(['y'], $completed['$defs']['thing']['required']);
    }

    public function testNormalizationAndStrictCompletionCompose(): void
    {
        $normalizer = new SchemaNormalizer();

        $schema = $normalizer->normalize([
            'type' => 'object',
            'title' => 'Person',
            'properties' => ['name' => ['type' => 'string']],
        ], SchemaDialect::OpenAi);

        $schema = $normalizer->completeForStrictMode($schema);

        $this->assertSame('Person', $schema['title']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['name'], $schema['required']);
    }

    // ── Shape and keyword hygiene ───────────────────────────────────────────

    public function test_a_non_object_root_is_rejected(): void
    {
        try {
            $this->normalizer()->normalize(['type' => 'array', 'items' => ['type' => 'string']], SchemaDialect::OpenAi);
            $this->fail('Expected a SchemaException.');
        } catch (SchemaException $e) {
            $this->assertSame('root_not_object', $e->reason);
            $this->assertSame('array', $e->declaredType);
        }
    }

    public function test_supported_annotations_pass_through_and_unsupported_ones_are_dropped(): void
    {
        $schema = [
            'type' => 'object',
            'title' => 'Person',
            'description' => 'A person',
            '$id' => 'https://example.test/person',
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'properties' => ['name' => ['type' => 'string', 'description' => 'The name']],
        ];

        $result = $this->normalizer()->normalize($schema, SchemaDialect::OpenAi);

        $this->assertSame('Person', $result['title']);
        $this->assertSame('A person', $result['description']);
        $this->assertSame('The name', $result['properties']['name']['description']);
        $this->assertArrayNotHasKey('$id', $result, 'annotation-only keywords may be dropped: they change no validation');
        $this->assertArrayNotHasKey('$schema', $result);
    }

    public function test_an_unknown_keyword_is_rejected_rather_than_ignored(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['x' => ['type' => 'string', 'x-vendor-hint' => true]],
        ];

        try {
            $this->normalizer()->normalize($schema, SchemaDialect::OpenAi);
            $this->fail('Expected a SchemaException.');
        } catch (SchemaException $e) {
            $this->assertSame('unsupported_keyword', $e->reason);
            $this->assertSame('x-vendor-hint', $e->keyword);
            $this->assertSame('$.properties.x.x-vendor-hint', $e->path);
        }
    }

    public function test_nested_containers_are_walked_so_a_deep_violation_is_found(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['deep' => ['type' => 'string', 'allOf' => [['type' => 'string']]]],
                    ],
                ],
            ],
        ];

        try {
            $this->normalizer()->normalize($schema, SchemaDialect::OpenAi);
            $this->fail('Expected a SchemaException.');
        } catch (SchemaException $e) {
            $this->assertSame('$.properties.items.items.properties.deep.allOf', $e->path);
        }
    }
}
