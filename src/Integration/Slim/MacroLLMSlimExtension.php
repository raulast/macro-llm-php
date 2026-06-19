<?php

declare(strict_types=1);

namespace MacroLLM\Integration\Slim;

use MacroLLM\Config\Config;
use MacroLLM\Exception\ContainerBindingException;
use MacroLLM\MacroLLM;
use MacroLLM\Mcp\MCPServer;
use MacroLLM\Mcp\MCPServerMiddleware;
use MacroLLM\Provider\ProviderFactory;
use MacroLLM\Registry\ProviderRegistry;
use MacroLLM\Registry\SkillRegistry;
use MacroLLM\Registry\ToolRegistry;
use Psr\Container\ContainerInterface;

final class MacroLLMSlimExtension
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly array $config,
    ) {}

    /**
     * Register MacroLLM and dependencies into the PSR-11 container.
     *
     * @throws ContainerBindingException If the container does not support set() (read-only).
     */
    public function register(): void
    {
        if (!method_exists($this->container, 'set')) {
            throw new ContainerBindingException(get_class($this->container));
        }

        $cfg = Config::fromArray($this->config);
        $tools = new ToolRegistry();
        $skills = new SkillRegistry($tools);
        $registry = new ProviderRegistry();

        foreach ($this->config['providers'] ?? [] as $name => $providerConfig) {
            if (ProviderFactory::supports($name) && $cfg->provider($name) !== null) {
                $registry->register(ProviderFactory::make($name, $cfg->provider($name)));
            }
        }

        $macroLLM = new MacroLLM($cfg, $registry, $tools, $skills);
        $mcpServer = new MCPServer($tools);
        $mcpMiddleware = new MCPServerMiddleware($mcpServer);

        $this->container->set(MacroLLM::class, $macroLLM);
        $this->container->set(MCPServer::class, $mcpServer);
        $this->container->set(MCPServerMiddleware::class, $mcpMiddleware);
    }
}
