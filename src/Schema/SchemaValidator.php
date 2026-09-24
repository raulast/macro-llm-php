<?php

declare(strict_types=1);

namespace MacroLLM\Schema;

use MacroLLM\Exception\SchemaValidationException;

/**
 * Validates a decoded value against a JSON Schema.
 *
 * It exists because the package lets a caller declare a schema and then does nothing with it: `ToolDefinition`
 * accepts a JSON Schema for its parameters, and nothing ever checked that a model's tool call honoured it. A model
 * could hand a tool arguments the tool never asked for, and the callable simply ran.
 *
 * Three rules shape the behaviour:
 *
 *  1. **Keywords are scoped to their type**, as JSON Schema specifies: `minLength` on a non-string is vacuously
 *     true, not an error. Only `type` decides whether a value is the right shape at all.
 *  2. **An unknown keyword is refused by name**, because reporting a schema as satisfied when part of it was never
 *     checked is the silent weakening the rest of this engine refuses. Annotation-only keywords are ignored, since
 *     they enforce nothing.
 *  3. **References are refused**, not half-resolved: the validator resolves nothing, and it says so. Inline the
 *     schema first — `SchemaNormalizer` does that for a provider dialect.
 *
 * One deliberate leniency: PHP cannot tell an empty JSON array from an empty JSON object, because
 * `json_decode(..., true)` maps both to `[]`. An empty array therefore satisfies both `array` and `object`. Any
 * other rule would reject legitimate empty objects.
 */
final class SchemaValidator
{
    /** Keywords that constrain nothing and are therefore ignored. */
    private const ANNOTATION_KEYWORDS = [
        'description', 'title', 'default', 'examples', 'example',
        '$comment', '$schema', '$id', '$anchor',
        'readOnly', 'writeOnly', 'deprecated',
    ];

    /**
     * @param  array<string, mixed>  $schema
     * @throws SchemaValidationException  when the value does not match, or the schema cannot be enforced
     */
    public function validate(mixed $value, array $schema, string $path = '$'): void
    {
        foreach ($schema as $keyword => $expected) {
            $keyword = (string) $keyword;

            if (in_array($keyword, self::ANNOTATION_KEYWORDS, true)) {
                continue;
            }

            match ($keyword) {
                // Refused explicitly rather than falling through to the generic branch, because a reference is not
                // a typo: it is a legitimate schema the validator simply cannot enforce, and the caller deserves
                // to be told what to do about it.
                '$ref' => throw SchemaValidationException::unsupportedKeyword(
                    '$ref',
                    $path,
                    'the validator resolves no references; inline the schema first, which SchemaNormalizer does for a '
                    . 'provider dialect',
                ),
                'type' => $this->assertType($value, $expected, $path),
                'enum' => $this->assertEnum($value, $expected, $path),
                'const' => $this->assertConst($value, $expected, $path),
                'required' => $this->assertRequired($value, $expected, $path),
                'properties' => $this->assertProperties($value, $expected, $path),
                'additionalProperties' => $this->assertAdditionalProperties($value, $expected, $schema, $path),
                'items', 'minItems', 'maxItems', 'uniqueItems',
                'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
                'minLength', 'maxLength', 'pattern',
                'anyOf', 'oneOf', 'allOf', 'not' => $this->assertKeyword($keyword, $value, $expected, $path),
                default => throw SchemaValidationException::unsupportedKeyword(
                    $keyword,
                    $path,
                    'the validator does not know this keyword, and ignoring it would report an unchecked constraint '
                    . 'as satisfied',
                ),
            };
        }
    }

    // ── Types ───────────────────────────────────────────────────────────────

    private function assertType(mixed $value, mixed $expected, string $path): void
    {
        $types = is_array($expected) ? $expected : [$expected];

        foreach ($types as $type) {
            if (is_string($type) && $this->matchesType($value, $type, $path)) {
                return;
            }
        }

        throw SchemaValidationException::valueMismatch(
            $path,
            'type',
            is_array($expected) ? implode(' or ', $expected) : (string) $expected,
            $value,
        );
    }

    private function matchesType(mixed $value, string $type, string $path): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            // The test asserts a float does NOT satisfy `integer`, so `number` widens in one direction only.
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && ($value === [] || array_is_list($value)),
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'null' => $value === null,
            default => throw SchemaValidationException::unsupportedKeyword(
                'type',
                $path,
                sprintf('"%s" is not a JSON Schema type', $type),
            ),
        };
    }

    // ── Objects ─────────────────────────────────────────────────────────────

    private function assertRequired(mixed $value, mixed $expected, string $path): void
    {
        if (!is_array($expected) || !is_array($value)) {
            return;   // `required` is scoped to objects, so it is vacuous anywhere else
        }

        foreach ($expected as $name) {
            if (!is_string($name)) {
                throw SchemaValidationException::unsupportedKeyword('required', $path, 'every entry must be a string');
            }

            if (!array_key_exists($name, $value)) {
                throw SchemaValidationException::valueMismatch(
                    $path,
                    'required',
                    sprintf('a "%s" property', $name),
                    'missing',
                );
            }
        }
    }

    private function assertProperties(mixed $value, mixed $expected, string $path): void
    {
        if (!is_array($expected) || !is_array($value)) {
            return;
        }

        foreach ($expected as $name => $subSchema) {
            if (!is_array($subSchema) || !array_key_exists($name, $value)) {
                continue;
            }

            $this->validate($value[$name], $subSchema, $path . '.' . $name);
        }
    }

    private function assertAdditionalProperties(mixed $value, mixed $expected, array $schema, string $path): void
    {
        if (!is_array($value)) {
            return;
        }

        $declared = is_array($schema['properties'] ?? null) ? array_keys($schema['properties']) : [];

        foreach ($value as $name => $actual) {
            if (in_array($name, $declared, true)) {
                continue;
            }

            if ($expected === false) {
                throw SchemaValidationException::valueMismatch(
                    $path . '.' . $name,
                    'additionalProperties',
                    'no undeclared property',
                    $actual,
                );
            }

            if (is_array($expected)) {
                $this->validate($actual, $expected, $path . '.' . $name);
            }
        }
    }

    // ── Everything else that constrains a value ─────────────────────────────

    private function assertKeyword(string $keyword, mixed $value, mixed $expected, string $path): void
    {
        match ($keyword) {
            'enum' => $this->assertEnum($value, $expected, $path),
            'const' => $this->assertConst($value, $expected, $path),
            'items' => is_array($expected) && is_array($value)
                ? $this->assertItems($value, $expected, $path)
                : null,
            'minItems', 'maxItems' => is_int($expected) && is_array($value)
                ? $this->assertArraySize($keyword, $value, $expected, $path)
                : null,
            'uniqueItems' => $expected === true && is_array($value)
                ? $this->assertUniqueItems($value, $path)
                : null,
            'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf' => is_numeric($expected)
                ? $this->assertNumeric($keyword, $value, (float) $expected, $path)
                : null,
            'minLength', 'maxLength' => is_int($expected)
                ? $this->assertStringLength($keyword, $value, $expected, $path)
                : null,
            'pattern' => is_string($expected) ? $this->assertPattern($value, $expected, $path) : null,
            'anyOf', 'oneOf', 'allOf' => is_array($expected) ? $this->assertComposition($keyword, $value, $expected, $path) : null,
            'not' => is_array($expected) ? $this->assertNot($value, $expected, $path) : null,
            default => null,
        };
    }

    private function assertEnum(mixed $value, mixed $expected, string $path): void
    {
        if (!is_array($expected) || in_array($value, $expected, true)) {
            return;
        }

        throw SchemaValidationException::valueMismatch(
            $path,
            'enum',
            'one of ' . json_encode($expected),
            $value,
        );
    }

    private function assertConst(mixed $value, mixed $expected, string $path): void
    {
        if ($value === $expected) {
            return;
        }

        throw SchemaValidationException::valueMismatch($path, 'const', json_encode($expected) ?: 'null', $value);
    }

    /** @param array<int|string, mixed> $value */
    private function assertItems(array $value, mixed $expected, string $path): void
    {
        foreach (array_values($value) as $index => $item) {
            $this->validate($item, $expected, $path . '[' . $index . ']');
        }
    }

    /** @param array<int|string, mixed> $value */
    private function assertArraySize(string $keyword, array $value, int $expected, string $path): void
    {
        $count = count($value);
        $ok = $keyword === 'minItems' ? $count >= $expected : $count <= $expected;

        if (!$ok) {
            throw SchemaValidationException::valueMismatch($path, $keyword, (string) $expected, $count);
        }
    }

    /** @param array<int|string, mixed> $value */
    private function assertUniqueItems(array $value, string $path): void
    {
        $encoded = array_map(static fn (mixed $item): string => json_encode($item) ?: 'null', $value);

        if (count($encoded) !== count(array_unique($encoded))) {
            throw SchemaValidationException::valueMismatch($path, 'uniqueItems', 'unique entries', 'a duplicate');
        }
    }

    private function assertNumeric(string $keyword, mixed $value, float $expected, string $path): void
    {
        if (!is_int($value) && !is_float($value)) {
            return;   // numeric keywords are scoped to numbers
        }

        $actual = (float) $value;

        $ok = match ($keyword) {
            'minimum' => $actual >= $expected,
            'maximum' => $actual <= $expected,
            'exclusiveMinimum' => $actual > $expected,
            'exclusiveMaximum' => $actual < $expected,
            'multipleOf' => $expected !== 0.0 && fmod($actual, $expected) === 0.0,
            default => true,
        };

        if (!$ok) {
            throw SchemaValidationException::valueMismatch($path, $keyword, (string) $expected, $value);
        }
    }

    private function assertStringLength(string $keyword, mixed $value, int $expected, string $path): void
    {
        if (!is_string($value)) {
            return;
        }

        $length = $this->length($value);
        $ok = $keyword === 'minLength' ? $length >= $expected : $length <= $expected;

        if (!$ok) {
            throw SchemaValidationException::valueMismatch($path, $keyword, (string) $expected, $length);
        }
    }

    private function assertPattern(mixed $value, string $pattern, string $path): void
    {
        if (!is_string($value)) {
            return;
        }

        $result = @preg_match($pattern, $value);

        if ($result === false) {
            throw SchemaValidationException::unsupportedKeyword(
                'pattern',
                $path,
                sprintf('"%s" is not a usable PCRE pattern (delimiters are required)', $pattern),
            );
        }

        if ($result === 0) {
            throw SchemaValidationException::valueMismatch($path, 'pattern', $pattern, $value);
        }
    }

    /** @param array<int|string, mixed> $branches */
    private function assertComposition(string $keyword, mixed $value, array $branches, string $path): void
    {
        $matching = 0;

        foreach (array_values($branches) as $index => $branch) {
            if (!is_array($branch)) {
                continue;
            }

            if ($this->satisfies($value, $branch, $path . '.' . $index)) {
                $matching++;
            }
        }

        $ok = match ($keyword) {
            'anyOf' => $matching >= 1,
            'oneOf' => $matching === 1,
            'allOf' => $matching === count($branches),
            default => true,
        };

        if (!$ok) {
            throw SchemaValidationException::valueMismatch($path, $keyword, (string) $matching . ' matching branch(es)', $value);
        }
    }

    /** @param array<string, mixed> $branch */
    private function assertNot(mixed $value, array $branch, string $path): void
    {
        if ($this->satisfies($value, $branch, $path)) {
            throw SchemaValidationException::valueMismatch($path, 'not', 'a value outside the excluded schema', $value);
        }
    }

    /**
     * Whether a value satisfies a subschema, for the composition keywords.
     *
     * A branch that FAILS the value returns false; a branch whose own schema is unusable is rethrown, because that
     * is a problem with the schema rather than a verdict on the value.
     *
     * @param  array<string, mixed>  $schema
     */
    private function satisfies(mixed $value, array $schema, string $path): bool
    {
        try {
            $this->validate($value, $schema, $path);

            return true;
        } catch (SchemaValidationException $e) {
            if ($e->reason !== 'value_mismatch') {
                throw $e;
            }

            return false;
        }
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}
