<?php

declare(strict_types=1);

namespace MacroLLM\Registry;

use Closure;
use MacroLLM\Contract\ProviderInterface;
use MacroLLM\Exception\UnregisteredProviderException;

final class ProviderRegistry
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    /** @var Closure[] */
    private array $listeners = [];

    /**
     * Register a provider. Replaces on duplicate name (Req 1.7).
     * Fires all registered listeners after registration.
     */
    public function register(ProviderInterface $provider): void
    {
        $this->providers[$provider->name()] = $provider;

        foreach ($this->listeners as $listener) {
            $listener($provider->name());
        }
    }

    /**
     * Get a registered provider by name.
     *
     * @throws UnregisteredProviderException
     */
    public function get(string $name): ProviderInterface
    {
        if (!$this->has($name)) {
            throw new UnregisteredProviderException($name);
        }

        return $this->providers[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    /** @return array<string, ProviderInterface> */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * Register a listener that fires after each provider registration.
     * The listener receives the provider name string.
     */
    public function onRegister(Closure $listener): void
    {
        $this->listeners[] = $listener;
    }
}
