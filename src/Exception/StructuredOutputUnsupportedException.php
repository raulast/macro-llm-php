<?php

declare(strict_types=1);

namespace MacroLLM\Exception;

/**
 * A provider cannot honour the requested structured output, and saying so is the only honest option.
 *
 * Distinct from {@see SchemaException}, which means the **schema** is wrong for the target. This one means the
 * schema may be perfectly fine and the provider still cannot enforce it — because the request combines structured
 * output with something the provider documents as incompatible.
 *
 * Sending it anyway produces one of two worse outcomes: an unexplained provider error, or a response the caller
 * believes is constrained when it is not.
 */
final class StructuredOutputUnsupportedException extends MacroLLMException
{
    public function __construct(
        public readonly string $providerName,
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function conflictsWith(string $providerName, string $conflictingFeature, string $detail): self
    {
        return new self(
            providerName: $providerName,
            reason: 'conflicts_with_' . $conflictingFeature,
            message: sprintf(
                'Provider "%s" cannot honour structured output together with "%s" in the same request: %s. '
                . 'The request was refused rather than sent, because sending it would either fail with an error '
                . 'the caller cannot act on, or return output that is not constrained while looking as though it is.',
                $providerName,
                $conflictingFeature,
                $detail,
            ),
        );
    }

    public static function noSuchMode(string $providerName, string $formatType, string $detail): self
    {
        return new self(
            providerName: $providerName,
            reason: 'no_such_mode',
            message: sprintf(
                'Provider "%s" cannot honour a "%s" response format: %s. Refusing beats sending it, because the '
                . 'constraint would not exist on the wire while the caller believed it did.',
                $providerName,
                $formatType,
                $detail,
            ),
        );
    }
}
