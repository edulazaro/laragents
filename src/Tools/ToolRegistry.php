<?php

namespace EduLazaro\Laragents\Tools;

use EduLazaro\Laragents\Attributes\Tags as TagsAttribute;
use EduLazaro\Laragents\Tools\Tool;

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

    /**
     * Register the package's bindings.
     *
     * @param Tool $tool
     * @return static
     */
    public function register(Tool $tool): static
    {
        $this->tools[$tool->name()] = $tool;

        return $this;
    }

    /**
     * Get.
     *
     * @param string $name
     * @return ?Tool
     */
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
                $own = $this->tagsOf($tool);

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

    /**
     * A tool's tags: its own method first, then the attribute, then everything.
     *
     * Both, in that order, the way the framework resolves an agent's options: the
     * attribute is the ordinary way to say it, and the method is there for a tool whose
     * tags depend on something it can only know at runtime.
     *
     * @param Tool $tool
     * @return array<int, string>
     */
    protected function tagsOf(Tool $tool): array
    {
        $own = $tool->tags();

        if ($own !== [] && $own !== ['*']) {
            return $own;
        }

        $attributes = (new \ReflectionClass($tool))->getAttributes(TagsAttribute::class);

        return $attributes === [] ? ['*'] : $attributes[0]->newInstance()->tags;
    }
}
