<?php

namespace EduLazaro\Laragents\Skills;

use EduLazaro\Laragents\AgentLoop;
use EduLazaro\Laragents\Contracts\Skill;
use EduLazaro\Laragents\Tools\ToolRegistry;

/**
 * A skill that is one agent turn against a whitelist of tools.
 *
 * What it does lives in procedure(), the instructions, injected as the system message;
 * and in tools(), what it may touch. run() drives the loop.
 *
 * A skill that needs several steps or deterministic orchestration overrides run()
 * entirely: call the loop more than once, parse in between, decide. This base covers
 * the common case, which is instructions plus a whitelist plus one turn.
 *
 * The loop is resolved from the container rather than injected through the contract,
 * so an app that runs its own loop gets it here without touching any skill.
 */
abstract class BaseSkill implements Skill
{
    /**
     * The procedure: step by step instructions for the agent, injected as `system`.
     *
     * @return string
     */
    abstract protected function procedure(): string;

    /**
     * Tool names this skill may reach for. Empty means none.
     *
     * @return array<int, string>
     */
    public function tools(): array
    {
        return [];
    }

    /**
     * Run the procedure and return what it produced.
     *
     * @param SkillContext $context
     * @return SkillResult
     */
    public function run(SkillContext $context): SkillResult
    {
        $preamble = trim($this->preamble());

        $messages = [
            ['role' => 'system', 'content' => trim($preamble . "\n\n" . $this->procedure())],
            ['role' => 'user', 'content' => $this->prompt($context)],
        ];

        $response = $this->loop()->run(
            messages: $messages,
            model: $context->model,
            session: $context->session,
            context: $context->context,
            tools: $this->definitions(),
            persist: $context->persist,
        );

        return SkillResult::from($response, ['skill' => $this->name()]);
    }

    /**
     * The message that sets the procedure going. Override to phrase it from the args.
     *
     * @param SkillContext $context
     * @return string
     */
    protected function prompt(SkillContext $context): string
    {
        $args = $context->args
            ? "\n\nParameters: " . json_encode($context->args, JSON_UNESCAPED_UNICODE)
            : '';

        return "Run the '{$this->name()}' procedure." . $args;
    }

    /**
     * Rules that go before every skill's procedure. Empty here on purpose.
     *
     * The app this came from puts its house rules in it: do not invent citations, say
     * so when something is missing, write in this language. Override it once in your
     * own base class rather than repeating it in every procedure().
     *
     * @return string
     */
    protected function preamble(): string
    {
        return '';
    }

    /**
     * The tool definitions this skill is allowed, in provider shape.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function definitions(): array
    {
        $tools = $this->tools();

        return $tools === [] ? [] : app(ToolRegistry::class)->definitions(whitelist: $tools);
    }

    /**
     * The loop that runs it, from the container so an app can swap its own in.
     *
     * @return AgentLoop
     */
    protected function loop(): AgentLoop
    {
        return app(AgentLoop::class);
    }

    /**
     * Register this skill in the catalogue.
     *
     * @return void
     */
    public static function register(): void
    {
        app(SkillRegistry::class)->register(static::class);
    }
}
