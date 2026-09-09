<?php

declare(strict_types=1);

namespace MacroLLM\Agent;

use MacroLLM\Exception\MaxToolIterationsException;
use MacroLLM\Exception\SkillToolConflictException;
use MacroLLM\MacroLLM;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalResponse;
use MacroLLM\Tool\ToolDefinition;
use MacroLLM\Tool\ToolResult;

final class Agent
{
    public function __construct(
        private readonly MacroLLM $llm,
        private readonly AgentConfig $config,
    ) {}

    /**
     * Run the agent with automatic tool-call loop.
     *
     * If {@see AgentConfig::$onStep} is set, the callback is invoked at each
     * meaningful event: LlmResponse, ToolCall (before execution), ToolResult
     * (after execution), and FinalResponse (before returning). Callback
     * exceptions propagate to the caller unmodified.
     */
    public function run(string|InternalRequest $input): InternalResponse
    {
        $providerName = $this->resolveProvider();

        // Resolve the offered tool map ONCE. This is the single source of truth
        // for both building the request payload and executing tool calls.
        // Tools supplied only via AgentConfig::tools are included here and are
        // fully executable without also being registered in the global ToolRegistry.
        $toolMap = $this->resolveToolMap();

        $request = $this->buildInitialRequest($input, array_values($toolMap));
        $iterations = 0;
        $stepIteration = 0;

        do {
            $stepIteration++;

            $response = $this->llm->chat($request, $providerName);

            // Append assistant message to memory
            $this->config->memory->append(
                InternalMessage::assistant($response->content, $response->toolCalls)
            );

            if (!$response->hasToolCalls()) {
                $this->fireStep(new AgentStep(
                    type: AgentStepType::FinalResponse,
                    iteration: $stepIteration,
                    response: $response,
                ));

                return $response;
            }

            $this->fireStep(new AgentStep(
                type: AgentStepType::LlmResponse,
                iteration: $stepIteration,
                response: $response,
            ));

            if (++$iterations >= $this->config->maxIterations) {
                throw new MaxToolIterationsException($iterations, $response);
            }

            // Append assistant message (with tool calls) to request first
            $request = $request->appended(
                InternalMessage::assistant($response->content, $response->toolCalls)
            );

            // Execute each tool call using the scoped tool map, not the global registry.
            // If the model requests a tool that was not offered to this agent, produce
            // an error result and continue — the model can self-correct on the next turn.
            foreach ($response->toolCalls as $toolCall) {
                $this->fireStep(new AgentStep(
                    type: AgentStepType::ToolCall,
                    iteration: $stepIteration,
                    toolCall: $toolCall,
                ));

                if (!isset($toolMap[$toolCall->name])) {
                    // Defense-in-depth: tool was not offered to this agent.
                    // Return a descriptive error so the model can recover.
                    $toolResult = ToolResult::error(
                        $toolCall->id,
                        $toolCall->name,
                        "Tool '{$toolCall->name}' is not available to this agent.",
                    );
                } else {
                    $definition = $toolMap[$toolCall->name];
                    try {
                        $result = ($definition->callable)($toolCall->arguments);
                        $toolResult = ToolResult::ok($toolCall->id, $toolCall->name, $result);
                    } catch (\Throwable $e) {
                        $toolResult = ToolResult::error($toolCall->id, $toolCall->name, $e->getMessage());
                    }
                }

                $this->fireStep(new AgentStep(
                    type: AgentStepType::ToolResult,
                    iteration: $stepIteration,
                    toolCall: $toolCall,
                    toolResult: $toolResult,
                ));

                $toolMessage = InternalMessage::tool($toolResult);
                $this->config->memory->append($toolMessage);
                $request = $request->appended($toolMessage);
            }
        } while (true);
    }

    /**
     * Invoke the onStep callback if configured. Exceptions propagate to caller.
     */
    private function fireStep(AgentStep $step): void
    {
        if ($this->config->onStep !== null) {
            ($this->config->onStep)($step);
        }
    }

    /**
     * Build the initial InternalRequest from user input.
     *
     * @param ToolDefinition[] $tools Pre-resolved tool list to include in the request.
     */
    private function buildInitialRequest(string|InternalRequest $input, array $tools): InternalRequest
    {
        if (is_string($input)) {
            $input = new InternalRequest(
                messages: [InternalMessage::user($input)],
            );
        }

        // Prepend memory history
        $historyMessages = $this->config->memory->getHistory();

        // Assemble system prompt
        $systemPrompt = $this->assembleSystemPrompt();

        $messages = [];
        if ($systemPrompt !== '') {
            $messages[] = InternalMessage::system($systemPrompt);
        }
        $messages = array_merge($messages, $historyMessages, $input->messages);

        return new InternalRequest(
            messages: $messages,
            tools: $tools,
            configOverride: $input->configOverride,
            stream: false,
        );
    }

    /**
     * Resolve provider: AgentConfig > skill config override > global default.
     */
    private function resolveProvider(): string
    {
        if ($this->config->provider !== null) {
            return $this->config->provider;
        }

        // Check skill config overrides (first skill with a provider wins)
        foreach ($this->config->skillNames as $skillName) {
            $skill = $this->llm->skills()->get($skillName);
            $override = $skill->getConfigOverride();
            if ($override !== null && $override->defaultProvider() !== null) {
                return $override->defaultProvider();
            }
        }

        $default = $this->llm->config()->defaultProvider();
        if ($default !== null) {
            return $default;
        }

        $all = $this->llm->providers()->all();
        if (count($all) > 0) {
            return array_key_first($all);
        }

        throw new \RuntimeException('No provider available for agent.');
    }

    /**
     * Assemble system prompt: AgentConfig prompt first, then skill prompts.
     */
    private function assembleSystemPrompt(): string
    {
        $parts = [];

        if ($this->config->systemPrompt !== null && $this->config->systemPrompt !== '') {
            $parts[] = $this->config->systemPrompt;
        }

        foreach ($this->config->skillNames as $skillName) {
            $skill = $this->llm->skills()->get($skillName);
            $prompt = $skill->getSystemPrompt();
            if ($prompt !== '') {
                $parts[] = $prompt;
            }
        }

        return implode($this->config->skillSeparator, $parts);
    }

    /**
     * Resolve tools offered to this agent, keyed by tool name.
     *
     * Precedence (highest → lowest):
     * - Skill tools, in skill composition order. Two skills declaring the same name throw.
     * - Direct AgentConfig::tools, appended after skill tools. Skill wins silently on collision.
     *
     * Direct tools in AgentConfig::tools are intentionally NOT required to be registered
     * in the global ToolRegistry — they are resolved from the config object directly and
     * are fully executable by this agent.
     *
     * @return array<string, ToolDefinition>
     * @throws SkillToolConflictException
     */
    private function resolveToolMap(): array
    {
        /** @var array<string, ToolDefinition> $resolved */
        $resolved = [];

        /** @var array<string, string> $toolOwners tool name → skill name */
        $toolOwners = [];

        // Skill tools first (in order) — looked up from global registry as before
        foreach ($this->config->skillNames as $skillName) {
            $skill = $this->llm->skills()->get($skillName);

            foreach ($skill->getTools() as $toolName) {
                if (isset($toolOwners[$toolName])) {
                    throw new SkillToolConflictException(
                        $toolName,
                        $toolOwners[$toolName],
                        $skillName,
                    );
                }

                $toolOwners[$toolName] = $skillName;
                $resolved[$toolName] = $this->llm->tools()->get($toolName);
            }
        }

        // Then append direct tools (skill wins silently on name collision)
        foreach ($this->config->tools as $tool) {
            if (!isset($resolved[$tool->name])) {
                $resolved[$tool->name] = $tool;
            }
        }

        return $resolved;
    }

    /**
     * Resolve tools as an ordered list for use in the request payload.
     *
     * @return ToolDefinition[]
     */
    private function resolveTools(): array
    {
        return array_values($this->resolveToolMap());
    }
}
