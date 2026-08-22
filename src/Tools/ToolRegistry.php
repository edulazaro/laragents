<?php

namespace EduLazaro\Laragents\Tools;

use EduLazaro\Laragents\Contracts\Tool;

/**
 * The tools available to a run, filtered by tags.
 *
 * Tags are strings the host app invents and this class only compares. That is the whole
 * design: the predecessor filtered on `requiresCase()` and a workspace enum, two app
 * concepts hardcoded into a supposedly generic API, and by the end one was being derived
 * from the other. Tag a tool 'case' or 'billing' or 'admin-only' and filter on whatever
 * the current run should see.
 */
class ToolRegistry
{
    /** @var array<string, Tool> */
    protected array $tools = [];

    public function register(Tool $tool): static
    {
        $this->tools[$tool->name()] = $tool;

        return $this;
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /** @return array<string, Tool> */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * @param  array<string>|null  $tags       Keep tools carrying at least one of these
     *                                         (a tool tagged '*' always passes). Null
     *                                         keeps everything.
     * @param  array<string>|null  $whitelist  Keep only these names, whatever their tags.
     * @return array<Tool>
     */
    public function for(?array $tags = null, ?array $whitelist = null): array
    {
        $tools = $this->tools;

        if ($tags !== null) {
            $tools = array_filter($tools, function (Tool $tool) use ($tags) {
                $own = $tool->tags();

                return in_array('*', $own, true) || array_intersect($own, $tags) !== [];
            });
        }

        if ($whitelist !== null) {
            $tools = array_filter($tools, fn (Tool $tool) => in_array($tool->name(), $whitelist, true));
        }

        return array_values($tools);
    }

    /**
     * The tool definitions in the shape a provider expects. OpenAI shape is the one the
     * loop speaks; the Anthropic client translates from it.
     */
    public function definitions(?array $tags = null, ?array $whitelist = null): array
    {
        return array_map(
            fn (Tool $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->parameters(),
                ],
            ],
            $this->for($tags, $whitelist),
        );
    }
}
