<?php

namespace EduLazaro\Laragents\Tests\Fixtures;

use EduLazaro\Laragents\Tools\Tool;
use EduLazaro\Laragents\Tools\ToolContext;

/**
 * A tool that returns a collection, which is what makes it judgeable by the loop's
 * empty-streak guard. Say what it should find, in order.
 */
class SearchTool extends Tool
{
    /** @var array<int, array<string, mixed>> One result per call, consumed in order. */
    public array $returns = [];

    public function name(): string
    {
        return 'search_tool';
    }

    public function description(): string
    {
        return 'Searches. For tests.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['q' => ['type' => 'string']],
            'required' => ['q'],
        ];
    }

    public function execute(array $args, ToolContext $context): array
    {
        return array_shift($this->returns) ?? ['items' => []];
    }
}
