<?php

namespace EduLazaro\Laragents\Tests\Fixtures;

use EduLazaro\Laragents\Tools\Tool;
use EduLazaro\Laragents\Tools\ToolContext;
use Illuminate\JsonSchema\JsonSchema;

/** Declares its arguments with the builder instead of a hand-written array. */
class BuiltSchemaTool extends Tool
{
    public function name(): string
    {
        return 'built_schema_tool';
    }

    public function description(): string
    {
        return 'For tests.';
    }

    protected function schema(): array
    {
        return [
            'role' => JsonSchema::string()->enum(['admin', 'member'])->description('Filter by role.')->required(),
            'limit' => JsonSchema::integer()->description('Max results.'),
        ];
    }

    public function execute(array $args, ToolContext $context): array
    {
        return ['items' => []];
    }
}
