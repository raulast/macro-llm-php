<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Schema;

use MacroLLM\Exception\SchemaValidationException;
use MacroLLM\Schema\SchemaValidator;
use MacroLLM\Tests\TestCase;

/**
 * The validator's contract, and the reason it exists: a tool call the model produced is validated against the
 * schema the tool DECLARED, before the callable is invoked. Without it the package offers a JSON Schema parameter
 * and never enforces it, so a model can hand a tool arguments the tool never asked for.
 *
 * Two failure modes are kept apart on purpose:
 *  - a VALUE that does not match the schema, reported with its path;
 *  - a SCHEMA the validator cannot use, refused by name rather than assumed satisfiable.
 */
final class SchemaValidatorTest extends TestCase
{
    private function validator(): SchemaValidator
    {
        return new SchemaValidator();
    }

    // ── Types ───────────────────────────────────────────────────────────────

    public function test_matching_types_pass(): void
    {
        $validator = $this->validator();

        $validator->validate(['name' => 'Ada'], [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
        ]);
        $validator->validate('Ada', ['type' => 'string']);
        $validator->validate(1, ['type' => 'integer']);
        $validator->validate(1.5, ['type' => 'number']);
        $validator->validate(true, ['type' => 'boolean']);
        $validator->validate([1, 2], ['type' => 'array']);
        $validator->validate(null, ['type' => 'null']);

        $this->assertTrue(true, 'no exception means the values matched');
    }

    public function test_a_wrong_type_is_reported_with_its_path(): void
    {
        try {
            $this->validator()->validate(['age' => 'old'], [
                'type' => 'object',
                'properties' => ['age' => ['type' => 'integer']],
            ]);
            $this->fail('Expected a SchemaValidationException.');
        } catch (SchemaValidationException $e) {
            $this->assertSame('value_mismatch', $e->reason);
            $this->assertSame('$.age', $e->path);
            $this->assertSame('type', $e->keyword);
            $this->assertStringContainsString('integer', $e->getMessage());
        }
    }

    public function test_an_integer_satisfies_a_number_but_a_float_does_not_satisfy_an_integer(): void
    {
        $this->validator()->validate(2, ['type' => 'number']);

        $this->expectException(SchemaValidationException::class);
        $this->validator()->validate(2.5, ['type' => 'integer']);
    }

    public function test_a_union_type_accepts_any_of_its_members(): void
    {
        $this->validator()->validate(null, ['type' => ['string', 'null']]);
        $this->validator()->validate('x', ['type' => ['string', 'null']]);

        $this->expectException(SchemaValidationException::class);
        $this->validator()->validate(5, ['type' => ['string', 'null']]);
    }

    // ── Object keywords ─────────────────────────────────────────────────────

    public function test_a_missing_required_property_is_reported(): void
    {
        try {
            $this->validator()->validate(['name' => 'Ada'], [
                'type' => 'object',
                'required' => ['name', 'email'],
            ]);
            $this->fail('Expected a SchemaValidationException.');
        } catch (SchemaValidationException $e) {
            $this->assertSame('required', $e->keyword);
            $this->assertStringContainsString('email', $e->getMessage());
        }
    }

    public function test_additional_properties_false_forbids_undeclared_keys(): void
    {
        try {
            $this->validator()->validate(['name' => 'Ada', 'nickname' => 'A'], [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'additionalProperties' => false,
            ]);
            $this->fail('Expected a SchemaValidationException.');
        } catch (SchemaValidationException $e) {
            $this->assertSame('$.nickname', $e->path);
        }
    }

    /** `additionalProperties` only applies to keys `properties` did not declare. */
    public function test_additional_properties_ignores_declared_keys(): void
    {
        // The assertion is the absence of an exception; PHPUnit needs telling, or it reports the test as risky.
        $this->expectNotToPerformAssertions();

        $this->validator()->validate(['name' => 'Ada'], [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'additionalProperties' => false,
        ]);
    }

    public function test_additional_properties_as_a_schema_validates_the_undeclared_keys(): void
    {
        $this->validator()->validate(['name' => 'Ada', 'extra' => 'ok'], [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'additionalProperties' => ['type' => 'string'],
        ]);

        $this->expectException(SchemaValidationException::class);
        $this->validator()->validate(['name' => 'Ada', 'extra' => 5], [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'additionalProperties' => ['type' => 'string'],
        ]);
    }

    // ── Values, strings and arrays ──────────────────────────────────────────

    public function test_enum_and_const(): void
    {
        $this->validator()->validate('celsius', ['enum' => ['celsius', 'fahrenheit']]);
        $this->validator()->validate('fixed', ['const' => 'fixed']);

        $this->expectException(SchemaValidationException::class);
        $this->validator()->validate('kelvin', ['enum' => ['celsius', 'fahrenheit']]);
    }

    public function test_numeric_bounds(): void
    {
        $this->validator()->validate(5, ['minimum' => 0, 'maximum' => 10, 'multipleOf' => 5]);

        $this->expectException(SchemaValidationException::class);
        $this->validator()->validate(11, ['maximum' => 10]);
    }

    public function test_string_length_and_pattern(): void
    {
        $this->validator()->validate('abc', ['minLength' => 2, 'maxLength' => 5, 'pattern' => '/^a/']);

        $this->expectException(SchemaValidationException::class);
        $this->validator()->validate('abc', ['pattern' => '/^z/']);
    }

    public function test_array_items_and_sizes(): void
    {
        $this->validator()->validate([1, 2], ['items' => ['type' => 'integer'], 'minItems' => 1, 'maxItems' => 3]);

        try {
            $this->validator()->validate([1, 'two'], ['items' => ['type' => 'integer']]);
            $this->fail('Expected a SchemaValidationException.');
        } catch (SchemaValidationException $e) {
            $this->assertSame('$[1]', $e->path, 'an array index belongs in the path');
        }
    }

    public function test_unique_items(): void
    {
        $this->validator()->validate([1, 2], ['uniqueItems' => true]);

        $this->expectException(SchemaValidationException::class);
        $this->validator()->validate([1, 1], ['uniqueItems' => true]);
    }

    // ── Composition ─────────────────────────────────────────────────────────

    public function test_composition_keywords(): void
    {
        $this->validator()->validate('x', ['anyOf' => [['type' => 'string'], ['type' => 'integer']]]);
        $this->validator()->validate('x', ['oneOf' => [['type' => 'string'], ['type' => 'integer']]]);
        $this->validator()->validate(1, ['allOf' => [['type' => 'integer'], ['minimum' => 0]]]);
        $this->validator()->validate('x', ['not' => ['type' => 'integer']]);

        $this->expectException(SchemaValidationException::class);
        $this->validator()->validate(1, ['not' => ['type' => 'integer']]);
    }

    /** `oneOf` means exactly one, which is a different rule from `anyOf`. */
    public function test_one_of_rejects_a_value_matching_more_than_one_branch(): void
    {
        $this->expectException(SchemaValidationException::class);

        $this->validator()->validate(1, [
            'oneOf' => [
                ['type' => 'integer'],
                ['minimum' => 0],
            ],
        ]);
    }

    // ── The schema itself ───────────────────────────────────────────────────

    public function test_annotation_keywords_are_ignored_rather_than_refused(): void
    {
        $this->validator()->validate('Ada', [
            'type' => 'string',
            'description' => 'A name',
            'title' => 'Name',
            'default' => 'nobody',
            '$id' => 'https://example.test/name',
        ]);

        $this->assertTrue(true);
    }

    /**
     * A keyword the validator cannot enforce is refused by name. Ignoring it would report a schema as satisfied
     * when part of it was never checked — the same silent weakening the rest of this engine refuses.
     */
    public function test_an_unknown_keyword_is_refused_rather_than_ignored(): void
    {
        try {
            $this->validator()->validate('Ada', ['type' => 'string', 'x-vendor-hint' => true]);
            $this->fail('Expected a SchemaValidationException.');
        } catch (SchemaValidationException $e) {
            $this->assertSame('unsupported_keyword', $e->reason);
            $this->assertSame('x-vendor-hint', $e->keyword);
        }
    }

    /**
     * References are refused rather than half-resolved: the validator resolves nothing, and a schema it cannot
     * fully enforce must not be reported as satisfied.
     */
    public function test_a_reference_is_refused_with_an_actionable_reason(): void
    {
        try {
            $this->validator()->validate('Ada', ['$ref' => '#/$defs/name']);
            $this->fail('Expected a SchemaValidationException.');
        } catch (SchemaValidationException $e) {
            $this->assertSame('unsupported_keyword', $e->reason);
            $this->assertSame('$ref', $e->keyword);
            $this->assertStringContainsString('inline', $e->getMessage());
        }
    }

    /** A schema with no constraints at all accepts anything, including null. */
    public function test_an_empty_schema_accepts_anything(): void
    {
        $this->validator()->validate(null, []);
        $this->validator()->validate(['anything' => [1, 2]], []);

        $this->assertTrue(true);
    }
}
