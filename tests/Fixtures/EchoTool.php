<?php

namespace EduLazaro\Laragents\Tests\Fixtures;

use EduLazaro\Laragents\Tools\BaseTool;
use EduLazaro\Laragents\Tools\ToolContext;

class EchoTool extends BaseTool
{
    public array $calls = [];

    public function name(): string
    {
        return 'echo_tool';
    }

    public function description(): string
    {
        return 'Returns whatever it is given. For tests.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['value' => ['type' => 'string']],
            'required' => ['value'],
        ];
    }

    public function tags(): array
    {
        return ['test'];
    }

    public function execute(array $args, ToolContext $context): array
    {
        $this->calls[] = $args;

        return ['echoed' => $args['value'] ?? null, 'tenant' => $context->get('tenant')];
    }
}
