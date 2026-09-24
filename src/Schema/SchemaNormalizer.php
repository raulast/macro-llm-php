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
     * Every keyword whose value is a schema, or holds one: what the walk recurses into.
     *
     * Public because this is the engine's own classification, and an invariant ABOUT it belongs in a test that
     * reads it — not in a comment, and not in a text search. The grep that once "proved" one HTTP path was a
     * heuristic; this is the honest form of the same idea.
     *
     * @return list<string>
     */
    public static function containerKeywords(): array
    {
        return [
            ...self::SCHEMA_MAPS,
            ...self::SCHEMA_LISTS,
            ...self::SCHEMA_SINGLE,
            ...self::SCHEMA_BOOL_OR_SCHEMA,
        ];
    }

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
     * Replaces a `$ref` node with the schema it points at, **keeping the node's other keywords**.
     *
     * JSON Schema 2020-12 allows siblings beside `$ref`, and all of them apply: `{$ref: X, minLength: 5}` means
     * "matches X AND minLength 5". The first version of this method returned X alone, which silently DELETED the
     * caller's constraint while still producing a schema the provider accepted — the exact failure this engine
     * exists to prevent, committed by the engine itself. It was found by an independent reader, not by its tests.
     *
     * The merge rules are chosen so that no keyword is ever decided silently:
     *  - `required`    both lists apply, so the result is their union;
     *  - `properties`  the two maps merge key by key, and a property named on BOTH sides is refused, because two
     *                  constraints on the same property would need an `allOf` to say what was meant;
     *  - annotations   the use site wins: it is the more specific of the two, and no validation outcome is at stake;
     *  - anything else a keyword defined on both sides is REFUSED by name, because merging would pick a winner.
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

        $stack = [...$inlining, $reference];
        $inlined = $this->walk($target, $dialect, $path, $root, $stack);

        $siblings = $schema;
        unset($siblings['$ref']);

        foreach ($siblings as $keyword => $value) {
            $keywordPath = $path . '.' . $keyword;

            if (in_array($keyword, $dialect->droppedKeywords(), true)) {
                continue;   // annotation-only: nothing to merge, and nothing lost
            }

            // A sibling goes through the SAME classification as any other keyword, never around it.
            if (!in_array($keyword, $dialect->supportedKeywords(), true)) {
                throw SchemaException::unsupportedKeyword($dialect, $keywordPath, $keyword);
            }

            $inlined = $this->mergeSibling(
                $inlined,
                $keyword,
                $this->normalizeValue($keyword, $value, $dialect, $keywordPath, $root, $stack),
                $dialect,
                $keywordPath,
                $reference,
            );
        }

        return $inlined;
    }

    /**
     * @param  array<string, mixed>  $inlined
     * @return array<string, mixed>
     */
    private function mergeSibling(
        array $inlined,
        string $keyword,
        mixed $sibling,
        SchemaDialect $dialect,
        string $keywordPath,
        string $reference,
    ): array {
        if (!array_key_exists($keyword, $inlined)) {
            $inlined[$keyword] = $sibling;

            return $inlined;
        }

        if ($keyword === 'required' && is_array($sibling) && is_array($inlined[$keyword])) {
            $inlined[$keyword] = array_values(array_unique([...$inlined[$keyword], ...$sibling]));

            return $inlined;
        }

        if ($keyword === 'properties' && is_array($sibling) && is_array($inlined[$keyword])) {
            foreach ($sibling as $name => $subSchema) {
                if (array_key_exists($name, $inlined[$keyword])) {
                    throw SchemaException::conflictingReferenceSibling(
                        $dialect,
                        $keywordPath . '.' . $name,
                        $reference,
                        $keyword . '.' . $name,
                    );
                }

                $inlined[$keyword][$name] = $subSchema;
            }

            return $inlined;
        }

        if (in_array($keyword, ['description', 'title'], true)) {
            $inlined[$keyword] = $sibling;

            return $inlined;
        }

        throw SchemaException::conflictingReferenceSibling($dialect, $keywordPath, $reference, $keyword);
    }

    /**
     * Completes a schema for OpenAI's `strict: true`.
     *
     * This is a NAMED operation rather than a side effect of {@see normalize()}, because it rewrites the
     * caller's schema. Strict mode has two hard requirements, both documented by OpenAI: every object must set
     * `additionalProperties: false`, and every property must be listed in `required` — strict mode has no notion
     * of an optional field, so genuine optionality is expressed as a nullable type instead.
     *
     * Both additions **strengthen** the contract: `additionalProperties: false` forbids keys the caller never
     * declared, and completing `required` is precisely what `strict: true` means. They therefore do not violate
     * the rule against silent degradation, and the operation is named and tested so they are never invisible.
     *
     * A schema-valued `additionalProperties` is left untouched: it is an explicit choice strict mode cannot
     * honour, and rewriting it to `false` would replace the caller's meaning.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function completeForStrictMode(array $schema): array
    {
        $properties = $schema['properties'] ?? null;

        if (is_array($properties)) {
            $declared = $schema['required'] ?? null;
            $existing = is_array($declared) ? array_values(array_filter($declared, 'is_string')) : [];

            // The caller's order is preserved and only the missing names are appended, rather than replacing
            // the list outright.
            $schema['required'] = array_values(array_unique([...$existing, ...array_keys($properties)]));

            if (!is_array($schema['additionalProperties'] ?? null)) {
                $schema['additionalProperties'] = false;
            }
        }

        foreach ($schema as $keyword => $value) {
            if (!is_array($value)) {
                continue;
            }

            if (in_array($keyword, self::SCHEMA_MAPS, true)) {
                foreach ($value as $name => $subSchema) {
                    if (is_array($subSchema)) {
                        $schema[$keyword][$name] = $this->completeForStrictMode($subSchema);
                    }
                }

                continue;
            }

            if (in_array($keyword, self::SCHEMA_LISTS, true)) {
                foreach (array_values($value) as $index => $subSchema) {
                    if (is_array($subSchema)) {
                        $schema[$keyword][$index] = $this->completeForStrictMode($subSchema);
                    }
                }

                continue;
            }

            $isSingle = in_array($keyword, self::SCHEMA_SINGLE, true)
                || in_array($keyword, self::SCHEMA_BOOL_OR_SCHEMA, true);

            if ($isSingle) {
                $schema[$keyword] = $this->completeForStrictMode($value);
            }
        }

        return $schema;
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
