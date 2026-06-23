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
        $request = $this->buildInitialRequest($input);
        $providerName = $this->resolveProvider();
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

            // Execute each tool call and append tool results after the assistant message
            foreach ($response->toolCalls as $toolCall) {
                $definition = $this->llm->tools()->get($toolCall->name);

                $this->fireStep(new AgentStep(
                    type: AgentStepType::ToolCall,
                    iteration: $stepIteration,
                    toolCall: $toolCall,
                ));

                try {
                    $result = ($definition->callable)($toolCall->arguments);
                    $toolResult = ToolResult::ok($toolCall->id, $toolCall->name, $result);
                } catch (\Throwable $e) {
                    $toolResult = ToolResult::error($toolCall->id, $toolCall->name, $e->getMessage());
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
     */
    private function buildInitialRequest(string|InternalRequest $input): InternalRequest
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

        // Resolve tools
        $tools = $this->resolveTools();

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
     * Resolve tools: skill tools first (priority), then direct tools.
     * Throws SkillToolConflictException if two skills define the same tool name.
     *
     * @return ToolDefinition[]
     */
    private function resolveTools(): array
    {
        /** @var array<string, ToolDefinition> $resolved */
        $resolved = [];

        /** @var array<string, string> $toolOwners tool name → skill name */
        $toolOwners = [];

        // Skill tools first (in order)
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

        return array_values($resolved);
    }
}
