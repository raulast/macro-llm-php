<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Schema;

use MacroLLM\Exception\MacroLLMException;
use MacroLLM\Exception\SchemaException;
use MacroLLM\Schema\SchemaDialect;
use MacroLLM\Tests\TestCase;

/**
 * The dialect allow-lists are DATA, and data that is wrong in either direction is a defect: too
 * permissive earns a provider 400, too strict rejects a schema the provider would have accepted.
 *
 * These tests pin the lists themselves, including the invariant that matters most — **no dropped keyword
 * may be a validation keyword**, because dropping one would weaken the contract silently. They also pin
 * the failure type, whose whole job is to make a refusal actionable.
 *
 * The Gemini half of these tests is the record of a correction: the allow-list was first written from a
 * secondary source, then verified against the API reference, and **eight entries were wrong**. The pairs
 * `test_gemini_supports_the_two_keywords_the_provisional_list_refused_by_mistake` and
 * `test_gemini_refuses_the_string_keywords_the_provisional_list_allowed_by_mistake` exist so the
 * correction cannot silently regress, and so the cost of guessing an allow-list stays visible.
 */
final class SchemaDialectTest extends TestCase
{
    /**
     * Keywords whose removal changes what is enforced. None of these may ever appear in
     * `droppedKeywords()`, because the schema would then arrive without the caller's constraint while
     * looking like a successful request.
     */
    private const VALIDATION_KEYWORDS = [
        'type', 'properties', 'required', 'items', 'additionalProperties', 'patternProperties',
        'enum', 'const', 'anyOf', 'allOf', 'oneOf', 'not', 'if', 'then', 'else',
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
        'minLength', 'maxLength', 'pattern', 'format',
        'minItems', 'maxItems', 'uniqueItems',
        'dependentRequired', 'dependentSchemas',
    ];

    public function test_no_dialect_may_drop_a_validation_keyword(): void
    {
        foreach (SchemaDialect::cases() as $dialect) {
            $overlap = array_intersect($dialect->droppedKeywords(), self::VALIDATION_KEYWORDS);

            $this->assertSame(
                [],
                $overlap,
                sprintf(
                    'Dialect "%s" would drop %s, and dropping a validation keyword weakens the contract '
                    . 'silently. It must be refused instead.',
                    $dialect->value,
                    implode(', ', $overlap),
                ),
            );
        }
    }

    public function test_both_dialects_drop_the_same_annotation_set(): void
    {
        $this->assertSame(
            SchemaDialect::OpenAi->droppedKeywords(),
            SchemaDialect::Gemini->droppedKeywords(),
            'the droppable set is annotation-only and provider-independent; a divergence here needs a reason',
        );
    }

    /**
     * `default` is annotation-only by the JSON Schema spec — the Gemini reference itself says it "is intended
     * for documentation generators and doesn't affect validation" — so it is dropped rather than refused.
     */
    public function test_default_is_droppable_because_it_affects_no_validation(): void
    {
        $this->assertContains('default', SchemaDialect::OpenAi->droppedKeywords());
        $this->assertContains('default', SchemaDialect::Gemini->droppedKeywords());
    }

    // ── OpenAI ──────────────────────────────────────────────────────────────

    public function test_openai_supports_references_and_recursion(): void
    {
        $dialect = SchemaDialect::OpenAi;

        $this->assertFalse($dialect->inlinesReferences(), 'OpenAI documents $ref and $defs as supported');
        $this->assertTrue($dialect->allowsRecursion(), 'OpenAI documents recursive schemas as supported');
        $this->assertContains('$ref', $dialect->supportedKeywords());
        $this->assertContains('$defs', $dialect->supportedKeywords());
    }

    public function test_openai_supports_the_composition_it_documents(): void
    {
        $dialect = SchemaDialect::OpenAi;

        $this->assertContains('anyOf', $dialect->supportedKeywords());
        $this->assertContains('additionalProperties', $dialect->supportedKeywords(), 'strict mode requires it');
        $this->assertContains('description', $dialect->supportedKeywords());
    }

    public function test_openai_refuses_the_composition_its_guide_lists_as_unsupported(): void
    {
        $dialect = SchemaDialect::OpenAi;

        foreach (['allOf', 'not', 'dependentRequired', 'dependentSchemas', 'if', 'then', 'else'] as $keyword) {
            $this->assertNotContains(
                $keyword,
                $dialect->supportedKeywords(),
                sprintf('OpenAI documents "%s" as unsupported composition.', $keyword),
            );
        }
    }

    /**
     * `oneOf` is the one deliberate conservatism in the OpenAI list: the guide's unsupported list does not
     * name it and no example uses it, so it is refused pending verification. Pinned so the choice is
     * visible rather than buried.
     */
    public function test_openai_refuses_one_of_pending_verification(): void
    {
        $this->assertNotContains('oneOf', SchemaDialect::OpenAi->supportedKeywords());
    }

    // ── Gemini ──────────────────────────────────────────────────────────────

    public function test_gemini_inlines_references_deliberately_and_cannot_express_recursion(): void
    {
        $dialect = SchemaDialect::Gemini;

        $this->assertTrue(
            $dialect->inlinesReferences(),
            'the provider only unrolls cyclic references inside non-required properties, so inlining avoids '
            . 'that trap entirely for every non-recursive schema',
        );
        $this->assertFalse($dialect->allowsRecursion(), 'inlining a cycle cannot terminate');
        $this->assertNotContains(
            '$ref',
            $dialect->supportedKeywords(),
            'the provider accepts $ref, but the normalizer inlines it, so it is never KEPT in the emitted schema',
        );
        $this->assertNotContains('$defs', $dialect->supportedKeywords());
    }

    /**
     * The allow-list verified against the Gemini API reference's own enumeration of the properties its
     * JSON-Schema channel supports. This is the list the provisional one was corrected INTO.
     */
    public function test_gemini_supports_the_json_schema_properties_the_api_reference_enumerates(): void
    {
        $dialect = SchemaDialect::Gemini;

        $verified = [
            'type', 'format', 'title', 'description', 'enum',
            'items', 'prefixItems', 'minItems', 'maxItems', 'minimum', 'maximum',
            'anyOf', 'oneOf', 'properties', 'additionalProperties', 'required',
            'propertyOrdering',
        ];

        foreach ($verified as $keyword) {
            $this->assertContains($keyword, $dialect->supportedKeywords(), $keyword);
        }
    }

    /**
     * The two properties the provisional list got backwards, pinned so the correction cannot silently
     * regress: the reference supports BOTH.
     */
    public function test_gemini_supports_the_two_keywords_the_provisional_list_refused_by_mistake(): void
    {
        $dialect = SchemaDialect::Gemini;

        $this->assertContains('additionalProperties', $dialect->supportedKeywords());
        $this->assertContains('oneOf', $dialect->supportedKeywords());
    }

    /**
     * The other half of the correction: these were provisionally ALLOWED and are not in the reference's
     * list, so they would have earned a provider error.
     */
    public function test_gemini_refuses_the_string_keywords_the_provisional_list_allowed_by_mistake(): void
    {
        $dialect = SchemaDialect::Gemini;

        foreach (['minLength', 'maxLength', 'pattern', 'nullable', 'default'] as $keyword) {
            $this->assertNotContains($keyword, $dialect->supportedKeywords(), $keyword);
        }
    }

    public function test_gemini_refuses_the_keywords_outside_that_list(): void
    {
        $dialect = SchemaDialect::Gemini;

        $unsupported = [
            'patternProperties', 'allOf', 'not', 'const', 'uniqueItems',
            'multipleOf', 'exclusiveMinimum', 'exclusiveMaximum',
            'if', 'then', 'else', 'dependentRequired', 'dependentSchemas',
            'minProperties', 'maxProperties',
        ];

        foreach ($unsupported as $keyword) {
            $this->assertNotContains($keyword, $dialect->supportedKeywords(), $keyword);
        }
    }

    public function test_gemini_does_not_drop_const_because_it_is_a_validation_keyword(): void
    {
        $this->assertNotContains(
            'const',
            SchemaDialect::Gemini->droppedKeywords(),
            'const constrains a value; dropping it would silently disable that constraint',
        );
    }

    // ── The failure type ────────────────────────────────────────────────────

    public function test_every_refusal_is_a_package_exception_carrying_its_reason(): void
    {
        $cases = [
            SchemaException::unsupportedKeyword(SchemaDialect::Gemini, '$.additionalProperties', 'additionalProperties'),
            SchemaException::recursiveReference(SchemaDialect::Gemini, '$.$ref', '#/$defs/node'),
            SchemaException::unresolvableReference(SchemaDialect::Gemini, '$.properties.x.$ref', '#/$defs/nope'),
            SchemaException::rootNotObject(SchemaDialect::OpenAi, 'array'),
        ];

        $reasons = [];

        foreach ($cases as $exception) {
            $this->assertInstanceOf(MacroLLMException::class, $exception);
            $reasons[] = $exception->reason;
        }

        $this->assertSame(
            ['unsupported_keyword', 'recursive_reference', 'unresolvable_reference', 'root_not_object'],
            $reasons,
        );
    }

    public function test_the_offending_token_lands_in_the_field_that_names_it(): void
    {
        $keyword = SchemaException::unsupportedKeyword(SchemaDialect::Gemini, '$.x', 'const');
        $this->assertSame('const', $keyword->keyword);
        $this->assertNull($keyword->reference);
        $this->assertNull($keyword->declaredType);

        $reference = SchemaException::recursiveReference(SchemaDialect::Gemini, '$.x.$ref', '#/$defs/node');
        $this->assertSame('#/$defs/node', $reference->reference);
        $this->assertNull($reference->keyword);

        $root = SchemaException::rootNotObject(SchemaDialect::OpenAi, 'array');
        $this->assertSame('array', $root->declaredType);
        $this->assertSame('$', $root->path);
    }

    public function test_the_message_says_what_to_change_instead_of_only_what_failed(): void
    {
        $exception = SchemaException::unsupportedKeyword(
            SchemaDialect::Gemini,
            '$.properties.address.additionalProperties',
            'additionalProperties',
        );

        $message = $exception->getMessage();

        $this->assertStringContainsString('additionalProperties', $message);
        $this->assertStringContainsString('$.properties.address.additionalProperties', $message);
        $this->assertStringContainsString('gemini', $message);
        $this->assertStringContainsString('not be dropped silently', $message);
    }
}
