<?php

declare(strict_types=1);

namespace MacroLLM;

use Generator;
use MacroLLM\Agent\Agent;
use MacroLLM\Agent\AgentConfig;
use MacroLLM\Config\Config;
use MacroLLM\Exception\MissingApiKeyException;
use MacroLLM\Exception\ProviderRequestException;
use MacroLLM\Exception\StreamInterruptedException;
use MacroLLM\Http\HttpClient;
use MacroLLM\Message\FinishReason;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalResponse;
use MacroLLM\Message\StreamChunk;
use MacroLLM\Message\Usage;
use MacroLLM\Orchestration\Orchestrator;
use MacroLLM\Provider\ProviderFactory;
use MacroLLM\Registry\ProviderRegistry;
use MacroLLM\Registry\SkillRegistry;
use MacroLLM\Registry\ToolRegistry;

final class MacroLLM
{
    private readonly ToolRegistry $tools;
    private readonly SkillRegistry $skills;

    public function __construct(
        private readonly Config $config,
        private readonly ProviderRegistry $providers,
        ?ToolRegistry $tools = null,
        ?SkillRegistry $skills = null,
    ) {
        $this->tools = $tools ?? new ToolRegistry();
        $this->skills = $skills ?? new SkillRegistry($this->tools);
    }

    /**
     * Standalone bootstrap without a DI container.
     */
    public static function standalone(Config $config): self
    {
        $tools = new ToolRegistry();
        $skills = new SkillRegistry($tools);
        $providers = new ProviderRegistry();

        // Register providers from config
        $configArray = self::extractProviderNames($config);
        foreach ($configArray as $name) {
            $providerConfig = $config->provider($name);
            if ($providerConfig !== null && ProviderFactory::supports($name)) {
                $providers->register(ProviderFactory::make($name, $providerConfig));
            }
        }

        return new self($config, $providers, $tools, $skills);
    }

    /**
     * Registers one PendingRequest macro per provider.
     */
    public function registerMacros(): void
    {
        foreach ($this->providers->all() as $name => $provider) {
            $this->registerMacro($name);
        }
    }

    /**
     * Send a chat completion request.     */
    public function chat(InternalRequest $request, ?string $provider = null): InternalResponse
    {
        $providerName = $this->resolveProviderName($provider, $request);
        $providerInstance = $this->providers->get($providerName);

        $mergedConfig = $this->config->mergedWith($request->configOverride);

        // Validate API key early (will throw MissingApiKeyException if missing)
        $providerInstance->headers();

        $payload = $providerInstance->toPayload($request);

        $data = (new HttpClient(
            $providerInstance->baseUrl(),
            $providerInstance->headers(),
            $mergedConfig->timeout(),
            $mergedConfig->retries(),
            $mergedConfig->retryDelayMs(),
        ))->post($providerInstance->endpointPath(), $payload);

        return $providerInstance->toResponse($data);
    }

    /**
     * SSE streaming; yields StreamChunk objects.
     * Falls back to single chat() if provider doesn't support streaming.
     *
     * @return Generator<int, StreamChunk>
     */
    public function stream(InternalRequest $request, ?string $provider = null): Generator
    {
        $providerName = $this->resolveProviderName($provider, $request);
        $providerInstance = $this->providers->get($providerName);

        if (!$providerInstance->supportsStreaming()) {
            $response = $this->chat($request, $providerName);
            yield new StreamChunk(
                delta: $response->content ?? '',
                index: 0,
                finished: true,
                response: $response,
            );
            return;
        }

        $mergedConfig = $this->config->mergedWith($request->configOverride);
        $providerInstance->headers();

        $streamRequest = new InternalRequest(
            messages: $request->messages,
            tools: $request->tools,
            configOverride: $request->configOverride,
            stream: true,
        );

        $payload = $providerInstance->toPayload($streamRequest);

        $body = (new HttpClient(
            $providerInstance->baseUrl(),
            $providerInstance->headers(),
            $mergedConfig->timeout(),
            $mergedConfig->retries(),
            $mergedConfig->retryDelayMs(),
        ))->stream($providerInstance->endpointPath(), $payload);

        $chunks = [];
        $index = 0;
        $finished = false;

        // Parse SSE events from the response body
        $lines = explode("\n", $body);
        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'data: ')) {
                $data = substr($line, 6);

                if ($data === '[DONE]') {
                    $finished = true;
                    break;
                }

                $chunk = $providerInstance->parseStreamEvent($data, $index);
                if ($chunk !== null) {
                    if ($chunk->finished) {
                        $finished = true;
                        break;
                    }
                    $chunks[] = $chunk;
                    yield $chunk;
                    $index++;
                }
            }
        }

        if ($finished) {
            $fullContent = implode('', array_map(fn($c) => $c->delta, $chunks));
            $finalResponse = new InternalResponse(
                content: $fullContent !== '' ? $fullContent : null,
                finishReason: FinishReason::Stop,
                usage: new Usage(),
            );
            yield new StreamChunk(delta: '', index: $index, finished: true, response: $finalResponse);
            return;
        }

        throw new StreamInterruptedException($chunks);
    }

    public function providers(): ProviderRegistry
    {
        return $this->providers;
    }

    public function tools(): ToolRegistry
    {
        return $this->tools;
    }

    public function skills(): SkillRegistry
    {
        return $this->skills;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function agent(AgentConfig $config): Agent
    {
        return new Agent($this, $config);
    }

    public function orchestrator(): Orchestrator
    {
        return new Orchestrator();
    }

    /**
     * Returns the list of available models for the given provider.
     * Falls back to global default provider when $provider is null.
     * Returns an empty array if no provider is available.
     *
     * @return string[]
     */
    public function models(?string $provider = null): array
    {
        try {
            $providerName = $provider ?? $this->config->defaultProvider();
            if ($providerName === null) {
                return [];
            }
            return $this->providers->get($providerName)->getModels();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Register a single PendingRequest macro for a provider name.
     * Called from MacroLLMServiceProvider (Laravel only).
     */
    public function registerMacro(string $providerName): void
    {
        if (!class_exists(\Illuminate\Http\Client\PendingRequest::class)) {
            return;
        }

        $macroLLM = $this;

        \Illuminate\Http\Client\PendingRequest::macro($providerName, function (InternalRequest $request) use ($providerName, $macroLLM): InternalResponse {
            return $macroLLM->chat($request, $providerName);
        });
    }

    /**
     * Resolve which provider to use for a request.
     */
    private function resolveProviderName(?string $explicit, InternalRequest $request): string
    {
        if ($explicit !== null) {
            return $explicit;
        }

        if ($request->configOverride !== null) {
            $override = $request->configOverride->defaultProvider();
            if ($override !== null) {
                return $override;
            }
        }

        $default = $this->config->defaultProvider();
        if ($default !== null) {
            return $default;
        }

        // Fallback to first registered provider
        $all = $this->providers->all();
        if (count($all) > 0) {
            return array_key_first($all);
        }

        throw new \RuntimeException('No provider available. Register at least one provider or set a default.');
    }

    /**
     * Extract provider names from Config by checking all known providers.
     *
     * @return string[]
     */
    private static function extractProviderNames(Config $config): array
    {
        $names = [];
        foreach (ProviderFactory::supportedProviders() as $name) {
            if ($config->provider($name) !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
