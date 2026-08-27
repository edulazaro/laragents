<?php

namespace EduLazaro\Laragents\Tests\Fixtures;

use EduLazaro\Laragents\Tools\Tool;
use EduLazaro\Laragents\Attributes\Tags;
use EduLazaro\Laragents\Tools\ToolContext;

/** Declares where it belongs with the attribute, not with a method. */
#[Tags('matter', 'legal')]
class TaggedTool extends Tool
{
    public function name(): string
    {
        return 'tagged_tool';
    }

    public function description(): string
    {
        return 'For tests.';
    }

    public function execute(array $args, ToolContext $context): array
    {
        return [];
    }
}
