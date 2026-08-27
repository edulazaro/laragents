<?php

namespace EduLazaro\Laragents\Contracts;

use EduLazaro\Laragents\Skills\SkillContext;
use EduLazaro\Laragents\Skills\SkillResult;

/**
 * A named procedure: the agent runs against a whitelist of tools, following written
 * instructions, and produces a deliverable.
 *
 * Not a tool, which is one atomic call that returns data. A skill is a whole agent
 * turn nested inside another: it has its own instructions, its own subset of tools,
 * and it runs its own loop until it has something to hand back. "List the members" is
 * a tool; "build a timeline of this matter" is a skill.
 */
interface Skill
{
    /** Unique identifier, snake_case. The catalogue key. */
    public function name(): string;

    /** What it does, for the catalogue and for a model deciding whether to invoke it. */
    public function description(): string;

    /**
     * Tool names this skill may reach for. Empty means no tools: reasoning alone over
     * whatever context it was handed.
     *
     * @return array<int, string>
     */
    public function tools(): array;

    /** Run it, and return the deliverable. */
    public function run(SkillContext $context): SkillResult;
}
