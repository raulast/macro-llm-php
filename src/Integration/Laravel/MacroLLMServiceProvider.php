<?php

declare(strict_types=1);

namespace MacroLLM\Integration\Laravel;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use MacroLLM\Config\Config;
use MacroLLM\MacroLLM;
use MacroLLM\Provider\ProviderFactory;
use MacroLLM\Registry\ProviderRegistry;
use MacroLLM\Registry\SkillRegistry;
use MacroLLM\Registry\ToolRegistry;

final class MacroLLMServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../../config/macro-llm.php',
            'macro-llm'
        );

        $this->app->singleton('macro-llm', function ($app) {
            $configArray = $app['config']->get('macro-llm', []);
            $config = Config::fromArray($configArray);

            $tools = new ToolRegistry();
            $skills = new SkillRegistry($tools);
            $registry = new ProviderRegistry();

            foreach ($configArray['providers'] ?? [] as $name => $providerConfig) {
                $apiKey = $providerConfig['api_key'] ?? null;
                $requiresKey = !in_array($name, ['ollama', 'llamacpp'], true);

                if ($requiresKey && (empty($apiKey) || $apiKey === null)) {
                    Log::warning(
                        "MacroLLM: skipping provider '{$name}' — missing api_key"
                    );
                    continue;
                }

                if (ProviderFactory::supports($name)) {
                    $registry->register(
                        ProviderFactory::make($name, $config->provider($name))
                    );
                }
            }

            return new MacroLLM($config, $registry, $tools, $skills);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../../config/macro-llm.php' => config_path('macro-llm.php'),
        ], 'macro-llm-config');

        // Register PendingRequest macros (Http::openai(...), etc.)
        $macroLLM = $this->app->make('macro-llm');
        foreach ($macroLLM->providers()->all() as $name => $_) {
            $macroLLM->registerMacro($name);
        }
        $macroLLM->providers()->onRegister(fn(string $name) => $macroLLM->registerMacro($name));
    }
}
