<?php

declare(strict_types=1);

namespace MacroLLM\Schema;

use MacroLLM\Exception\SchemaException;

/**
 * Rewrites a JSON Schema into a shape a target dialect accepts — or refuses, by name.
 *
 * This is deliberately **not** a best-effort cleaner. It performs exactly two transformations, and both of
 * them preserve the caller's meaning:
 *
 *  1. **Annotation-only keywords the target rejects are dropped**, because dropping one changes no
 *     validation outcome.
 *  2. **Local `$ref` pointers are inlined** for dialects that cannot receive a reference at all.
 *
 * Everything else is either kept or refused. A validation keyword the target does not support raises
 * {@see SchemaException} instead of disappearing: a schema that arrives without its constraint still looks
 * like a successful request, which is the failure mode this engine exists to prevent.
 *
 * References are inlined **only where the dialect needs it**. OpenAI accepts `$ref` and `$defs` — recursive
 * schemas included — so inlining there would be a defect rather than a service, and the normalizer returns
 * such schemas untransformed.
 */
final class SchemaNormalizer
{
    /** Keyword → map of name → schema. */
    private const SCHEMA_MAPS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

    /** Keyword → list of schemas. */
    private const SCHEMA_LISTS = ['anyOf', 'allOf', 'oneOf', 'prefixItems'];

    /** Keyword → a single schema. */
    private const SCHEMA_SINGLE = ['items', 'not', 'if', 'then', 'else'];

    /** Keyword → a boolean or a single schema. */
    private const SCHEMA_BOOL_OR_SCHEMA = ['additionalProperties'];

    /** Keywords consumed by inlining instead of being sent. */
    private const REFERENCE_CONTAINERS = ['$defs', 'definitions'];

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     * @throws SchemaException  when the schema cannot be expressed in the dialect
     */
    public function normalize(array $schema, SchemaDialect $dialect): array
    {
        $declaredType = $schema['type'] ?? null;

        if ($declaredType !== 'object') {
            throw SchemaException::rootNotObject($dialect, is_string($declaredType) ? $declaredType : null);
        }

        return $this->walk($schema, $dialect, '$', $schema, []);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $root      What a pointer resolves against: always the root schema.
     * @param  list<string>          $inlining  References being inlined right now, to detect cycles.
     * @return array<string, mixed>
     */
    private function walk(array $schema, SchemaDialect $dialect, string $path, array $root, array $inlining): array
    {
        if (isset($schema['$ref']) && $dialect->inlinesReferences()) {
            return $this->inline($schema, $dialect, $path, $root, $inlining);
        }

        $normalized = [];

        foreach ($schema as $keyword => $value) {
            $keywordPath = $path . '.' . $keyword;

            if (in_array($keyword, self::REFERENCE_CONTAINERS, true) && $dialect->inlinesReferences()) {
                continue;   // consumed by inlining: a dialect that cannot send $ref cannot send $defs either
            }

            if (in_array($keyword, $dialect->supportedKeywords(), true)) {
                $normalized[$keyword] = $this->normalizeValue($keyword, $value, $dialect, $keywordPath, $root, $inlining);
                continue;
            }

            if (in_array($keyword, $dialect->droppedKeywords(), true)) {
                continue;   // annotation-only: the validation outcome is unchanged
            }

            throw SchemaException::unsupportedKeyword($dialect, $keywordPath, $keyword);
        }

        return $normalized;
    }

    private function normalizeValue(
        string $keyword,
        mixed $value,
        SchemaDialect $dialect,
        string $path,
        array $root,
        array $inlining,
    ): mixed {
        if (in_array($keyword, self::SCHEMA_MAPS, true) && is_array($value)) {
            $normalized = [];
            foreach ($value as $name => $subSchema) {
                $normalized[$name] = is_array($subSchema)
                    ? $this->walk($subSchema, $dialect, $path . '.' . $name, $root, $inlining)
                    : $subSchema;
            }

            return $normalized;
        }

        if (in_array($keyword, self::SCHEMA_LISTS, true) && is_array($value)) {
            $normalized = [];
            foreach (array_values($value) as $index => $subSchema) {
                $normalized[$index] = is_array($subSchema)
                    ? $this->walk($subSchema, $dialect, $path . '.' . $index, $root, $inlining)
                    : $subSchema;
            }

            return $normalized;
        }

        $isSingle = in_array($keyword, self::SCHEMA_SINGLE, true)
            || in_array($keyword, self::SCHEMA_BOOL_OR_SCHEMA, true);

        if ($isSingle && is_array($value)) {
            return $this->walk($value, $dialect, $path, $root, $inlining);
        }

        return $value;
    }

    /**
     * Replaces a `$ref` node with the schema it points at.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function inline(array $schema, SchemaDialect $dialect, string $path, array $root, array $inlining): array
    {
        $reference = $schema['$ref'];
        $referencePath = $path . '.$ref';

        if (!is_string($reference)) {
            throw SchemaException::unsupportedKeyword($dialect, $referencePath, '$ref');
        }

        if (in_array($reference, $inlining, true)) {
            throw SchemaException::recursiveReference($dialect, $referencePath, $reference);
        }

        $target = $this->resolve($reference, $root);

        if ($target === null) {
            throw SchemaException::unresolvableReference($dialect, $referencePath, $reference);
        }

        return $this->walk($target, $dialect, $path, $root, [...$inlining, $reference]);
    }

    /**
     * Resolves a local JSON Pointer (`#/...`) against the root schema. External references are not resolved.
     *
     * @param  array<string, mixed>  $root
     * @return array<string, mixed>|null
     */
    private function resolve(string $reference, array $root): ?array
    {
        if (!str_starts_with($reference, '#/')) {
            return null;
        }

        $node = $root;

        foreach (explode('/', substr($reference, 2)) as $segment) {
            // JSON Pointer escaping: ~1 is "/" and ~0 is "~".
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return is_array($node) ? $node : null;
    }
}
