<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Http\HttpClient;

/**
 * Builds a provider's HTTP client the one way this package allows.
 *
 * Every provider that performs its own HTTP goes through here, so all of them share the retry policy,
 * the failure contract (HC-11) and the `@internal` handler seam that makes them testable offline. A
 * provider that constructs `GuzzleHttp\Client` itself silently loses all three, which is exactly what
 * the audio providers used to do.
 *
 * The using class must provide a `ProviderConfig $config` property (readonly is fine), a
 * `baseUrl(): string` method, a `headers(): array` method, and a `?\Closure $httpHandlerFactory`
 * property.
 */
trait ProviderHttpClientTrait
{
    /**
     * @param  int  $timeout  The provider's own request timeout in seconds.
     */
    protected function httpClient(int $timeout): HttpClient
    {
        return new HttpClient(
            $this->baseUrl(),
            $this->headers(),
            $timeout,
            $this->config->retries ?? 0,
            $this->config->retryDelayMs ?? 500,
            $this->httpHandlerFactory !== null ? ($this->httpHandlerFactory)() : null,
        );
    }
}
