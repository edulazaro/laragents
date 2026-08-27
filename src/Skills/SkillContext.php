<?php

namespace EduLazaro\Laragents\Skills;

use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Tools\ToolContext;

/**
 * What a skill runs against: which session holds the turns, what the tools will be
 * given, and the skill's own parameters.
 *
 * The session is required because the loop writes its turns to it. To run a skill from
 * a button rather than from a conversation, make a session for it and pass that.
 */
final class SkillContext
{
    /**
     * @param ChatSession $session Where the turns are written.
     * @param ToolContext $context What the tools receive, unchanged.
     * @param array<string, mixed> $args The skill's own parameters.
     * @param string $model The model to run it on.
     * @param bool $persist False runs it headless, writing nothing: what a skill
     *                      invoked from inside a conversation wants, since the outer
     *                      conversation owns the history.
     * @return void
     */
    public function __construct(
        public readonly ChatSession $session,
        public readonly ToolContext $context,
        public readonly array $args = [],
        public readonly string $model = '',
        public readonly bool $persist = true,
    ) {
    }

    /**
     * One of the skill's parameters.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function arg(string $key, mixed $default = null): mixed
    {
        return $this->args[$key] ?? $default;
    }
}
