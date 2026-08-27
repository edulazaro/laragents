<?php

namespace EduLazaro\Laragents\Tests\Fixtures;

use EduLazaro\Laragents\Skills\BaseSkill;

/** A procedure that reads with one tool and writes a deliverable. For tests. */
class TimelineSkill extends BaseSkill
{
    public function name(): string
    {
        return 'timeline';
    }

    public function description(): string
    {
        return 'Builds a timeline of what happened.';
    }

    public function tools(): array
    {
        return ['echo_tool'];
    }

    protected function procedure(): string
    {
        return 'List every event in order, oldest first.';
    }
}
