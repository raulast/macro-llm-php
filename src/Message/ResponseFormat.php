<?php

declare(strict_types=1);

namespace MacroLLM\Message;

/**
 * Enforces a specific response format from the provider.
 * Only JSON schema is currently supported for structured output.
 */
final class ResponseFormat
{
    private function __construct(
        public readonly string $type,            // 'json_object' | 'json_schema'
        public readonly ?string $name = null,
        public readonly ?array $schema = null,
        public readonly bool $strict = true,
    ) {}

    /** Force JSON output (no schema enforcement). */
    public static function json(): self
    {
        return new self(type: 'json_object');
    }

    /**
     * Enforce a specific JSON schema.
     *
     * @param string $name   Schema name (used by OpenAI)
     * @param array  $schema JSON Schema object
     * @param bool   $strict Whether to enforce strict schema validation (OpenAI only)
     */
    public static function jsonSchema(string $name, array $schema, bool $strict = true): self
    {
        return new self(type: 'json_schema', name: $name, schema: $schema, strict: $strict);
    }
}
