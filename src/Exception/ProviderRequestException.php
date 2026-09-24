<?php

declare(strict_types=1);

namespace MacroLLM\Exception;

final class ProviderRequestException extends MacroLLMException
{
    /**
     * @param string|null $providerName The provider resolved for this request, or `null` when the failure
     *                                  is raised by the transport, which does not know which provider it
     *                                  serves. Attach the identity with {@see self::forProvider()}.
     * @param string|null $endpoint     The base URL the request was sent to.
     */
    public function __construct(
        public readonly ?string $providerName,
        public readonly int $statusCode,
        public readonly string $responseBody,
        public readonly ?string $endpoint = null,
    ) {
        parent::__construct(
            self::describe($providerName, $statusCode, $responseBody, $endpoint),
            $statusCode,
        );
    }

    /**
     * A transport-level failure. `HttpClient` is provider-agnostic: it holds a base URL, never a provider
     * identity, so it must not present one as the other — which is what it used to do, producing the
     * message `Provider "http://host/v1" returned HTTP 400`. The identity is attached by the layer that
     * resolved the provider.
     */
    public static function forEndpoint(string $endpoint, int $statusCode, string $responseBody): self
    {
        return new self(null, $statusCode, $responseBody, $endpoint);
    }

    /** Immutable: the same failure, now attributed to the provider that was resolved. */
    public function forProvider(string $providerName): self
    {
        return new self($providerName, $this->statusCode, $this->responseBody, $this->endpoint);
    }

    /**
     * The message says which of the two things it actually knows: a provider identity, or the endpoint
     * that answered. It never states one as the other.
     */
    private static function describe(
        ?string $providerName,
        int $statusCode,
        string $responseBody,
        ?string $endpoint,
    ): string {
        if ($providerName !== null) {
            return sprintf('Provider "%s" returned HTTP %d: %s', $providerName, $statusCode, $responseBody);
        }

        return sprintf('HTTP %d from "%s": %s', $statusCode, $endpoint ?? 'unknown endpoint', $responseBody);
    }
}
