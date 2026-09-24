<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Exception\MacroLLMException;
use MacroLLM\Exception\ProviderRequestException;

/**
 * Which failures justify trying a DIFFERENT provider.
 *
 * The package's central promise is that swapping providers is a one-string change, which makes the absence of a
 * fallback the missing consequence: if the chosen provider is down, a caller who configured three has exactly the
 * same outcome as a caller who configured one. This class answers the question that decision needs answered.
 *
 * **A hop spends the next provider's key, so the exclusions matter more than the inclusions.** An authentication
 * failure is NOT failoverable: the next provider rejects the same misconfiguration in the same way, so hopping
 * turns one clear error into a confusing chain — and with a paid provider, into real money spent on the wrong
 * account. A request the provider refused on its own terms is likewise not a transport problem, since the next
 * provider will refuse it identically.
 *
 * What remains is what another provider might genuinely survive: the transport never got through, or the provider
 * itself is rate limiting or broken.
 */
final class FailoverPolicy
{
    /**
     * Statuses that mean "this provider could not serve the request right now".
     *
     * 429 is a rate limit and 5xx is the provider's own failure, so another provider may well succeed. Every other
     * 4xx is a verdict on the request, and the next provider would render the same verdict.
     */
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    public static function isFailoverable(\Throwable $failure): bool
    {
        if ($failure instanceof ProviderRequestException) {
            return in_array($failure->statusCode, self::RETRYABLE_STATUSES, true);
        }

        // Any other package exception is about the request, the configuration or the schema — never the transport.
        if ($failure instanceof MacroLLMException) {
            return false;
        }

        // What is left is the transport's own wrapper for a connection or timeout failure, which is precisely the
        // case a second provider exists for.
        return $failure instanceof \RuntimeException;
    }

    /**
     * A short machine-readable reason, for messages and for tests — and for the exhausted-chain report, where the
     * caller needs to see WHY each provider was abandoned.
     */
    public static function reason(\Throwable $failure): string
    {
        if (!self::isFailoverable($failure)) {
            return 'not_failoverable';
        }

        if ($failure instanceof ProviderRequestException) {
            return $failure->statusCode === 429 ? 'rate_limited' : 'server_error';
        }

        return 'connection_failed';
    }
}
