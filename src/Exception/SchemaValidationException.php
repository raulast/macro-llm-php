<?php

declare(strict_types=1);

namespace MacroLLM\Exception;

/**
 * A value does not satisfy a schema, or a schema cannot be enforced at all.
 *
 * The two cases are kept apart by `reason`, and the distinction matters:
 *
 *  - `value_mismatch` — the payload is wrong, and the caller (or the model, in a tool loop) can fix it. The path
 *    points at the exact place.
 *  - `unsupported_keyword` — the SCHEMA is the problem. Reporting such a schema as satisfied would claim a
 *    constraint was checked when it never was, which is the silent weakening this whole engine refuses.
 */
final class SchemaValidationException extends MacroLLMException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $path,
        string $message,
        public readonly ?string $keyword = null,
        public readonly ?string $expected = null,
        public readonly mixed $actual = null,
    ) {
        parent::__construct($message);
    }

    public static function valueMismatch(string $path, string $keyword, string $expected, mixed $actual): self
    {
        return new self(
            reason: 'value_mismatch',
            path: $path,
            message: sprintf(
                'Value at %s does not satisfy "%s": expected %s, got %s.',
                $path,
                $keyword,
                $expected,
                self::describe($actual),
            ),
            keyword: $keyword,
            expected: $expected,
            actual: $actual,
        );
    }

    public static function unsupportedKeyword(string $keyword, string $path, string $detail): self
    {
        return new self(
            reason: 'unsupported_keyword',
            path: $path,
            message: sprintf(
                'The validator cannot enforce "%s" at %s: %s. A schema it cannot fully check is refused rather than '
                . 'reported as satisfied.',
                $keyword,
                $path,
                $detail,
            ),
            keyword: $keyword,
        );
    }

    /** A short, readable description of a value, safe for any type. */
    private static function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => sprintf('string "%s"', $value),
            is_int($value), is_float($value) => (string) $value,
            is_array($value) => 'array',
            default => get_debug_type($value),
        };
    }
}
