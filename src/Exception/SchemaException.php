<?php

declare(strict_types=1);

namespace MacroLLM\Exception;

use MacroLLM\Schema\SchemaDialect;

/**
 * A schema cannot be sent to a provider as written.
 *
 * Every reason is explicit and every message says what to change. The alternative — quietly dropping a
 * keyword the target does not accept — would keep the request working while silently weakening the
 * contract, which is this package's recurring defect class (structured output ignored, `strict`
 * discarded, tool arguments unvalidated).
 *
 * The offending token lives in the field that names it, so a caller never has to guess whether
 * `keyword` holds a keyword, a reference or a declared type.
 */
final class SchemaException extends MacroLLMException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $dialect,
        public readonly string $path,
        string $message,
        public readonly ?string $keyword = null,
        public readonly ?string $reference = null,
        public readonly ?string $declaredType = null,
    ) {
        parent::__construct($message);
    }

    public static function unsupportedKeyword(SchemaDialect $dialect, string $path, string $keyword): self
    {
        return new self(
            reason: 'unsupported_keyword',
            dialect: $dialect->value,
            path: $path,
            message: sprintf(
                'Schema keyword "%s" at %s is not supported by the "%s" dialect, and it will not be dropped '
                . 'silently: dropping it would weaken the contract while still looking like success. Remove or '
                . 'replace it, or target a dialect that supports it.',
                $keyword,
                $path,
                $dialect->value,
            ),
            keyword: $keyword,
        );
    }

    public static function recursiveReference(SchemaDialect $dialect, string $path, string $reference): self
    {
        return new self(
            reason: 'recursive_reference',
            dialect: $dialect->value,
            path: $path,
            message: sprintf(
                'Schema reference "%s" at %s is recursive, and the "%s" dialect cannot receive "$ref", so the '
                . 'reference has to be inlined — which cannot terminate for a cycle. Flatten the recursion, or '
                . 'target a dialect that accepts references.',
                $reference,
                $path,
                $dialect->value,
            ),
            reference: $reference,
        );
    }

    public static function unresolvableReference(SchemaDialect $dialect, string $path, string $reference): self
    {
        return new self(
            reason: 'unresolvable_reference',
            dialect: $dialect->value,
            path: $path,
            message: sprintf(
                'Schema reference "%s" at %s cannot be resolved inside this schema. Only local pointers into '
                . '"#/$defs/..." and "#/definitions/..." are resolved; external references are not. Dialect: "%s".',
                $reference,
                $path,
                $dialect->value,
            ),
            reference: $reference,
        );
    }

    public static function rootNotObject(SchemaDialect $dialect, ?string $declaredType): self
    {
        return new self(
            reason: 'root_not_object',
            dialect: $dialect->value,
            path: '$',
            message: sprintf(
                'The root schema must describe an object for the "%s" dialect; it declares type "%s". Wrap the '
                . 'value in an object schema.',
                $dialect->value,
                $declaredType ?? 'unknown',
            ),
            declaredType: $declaredType,
        );
    }
}
