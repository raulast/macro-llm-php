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

    public function test_gemini_inlines_references_and_cannot_express_recursion(): void
    {
        $dialect = SchemaDialect::Gemini;

        $this->assertTrue($dialect->inlinesReferences());
        $this->assertFalse($dialect->allowsRecursion(), 'inlining a cycle cannot terminate');
        $this->assertNotContains('$ref', $dialect->supportedKeywords());
        $this->assertNotContains('$defs', $dialect->supportedKeywords());
    }

    public function test_gemini_supports_its_documented_field_list(): void
    {
        $dialect = SchemaDialect::Gemini;

        foreach (['type', 'properties', 'required', 'items', 'enum', 'nullable', 'anyOf', 'propertyOrdering', 'description'] as $keyword) {
            $this->assertContains($keyword, $dialect->supportedKeywords());
        }
    }

    public function test_gemini_refuses_the_keywords_outside_that_list(): void
    {
        $dialect = SchemaDialect::Gemini;

        foreach (['additionalProperties', 'patternProperties', 'allOf', 'oneOf', 'not', 'const', 'uniqueItems'] as $keyword) {
            $this->assertNotContains($keyword, $dialect->supportedKeywords());
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
