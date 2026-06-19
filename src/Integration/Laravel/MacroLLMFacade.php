<?php

declare(strict_types=1);

namespace MacroLLM\Integration\Laravel;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \MacroLLM\Message\InternalResponse chat(\MacroLLM\Message\InternalRequest $request, ?string $provider = null)
 * @method static \Generator stream(\MacroLLM\Message\InternalRequest $request, ?string $provider = null)
 * @method static \MacroLLM\MacroLLM standalone(\MacroLLM\Config\Config $config)
 * @method static \MacroLLM\Registry\ProviderRegistry providers()
 * @method static \MacroLLM\Registry\ToolRegistry tools()
 * @method static \MacroLLM\Registry\SkillRegistry skills()
 * @method static \MacroLLM\Agent\Agent agent(\MacroLLM\Agent\AgentConfig $config)
 * @method static \MacroLLM\Orchestration\Orchestrator orchestrator()
 *
 * @see \MacroLLM\MacroLLM
 */
final class MacroLLMFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'macro-llm';
    }
}
