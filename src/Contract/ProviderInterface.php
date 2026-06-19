<?php

declare(strict_types=1);

namespace MacroLLM\Contract;

use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalResponse;
use MacroLLM\Message\StreamChunk;

interface ProviderInterface
{
    public function name(): string;

    /** Provider-specific base URL. */
    public function baseUrl(): string;

    /** Normalize InternalRequest → provider payload array. */
    public function toPayload(InternalRequest $request): array;

    /** Normalize provider response array → InternalResponse. */
    public function toResponse(array $providerResponse): InternalResponse;

    /** Parse one raw SSE data line into a StreamChunk, or null to skip. */
    public function parseStreamEvent(string $rawEvent, int $index): ?StreamChunk;

    public function supportsStreaming(): bool;

    /** Auth + provider-specific headers applied to the HTTP request. */
    public function headers(): array;

    /** Endpoint path appended to baseUrl() for API requests. */
    public function endpointPath(): string;
}
