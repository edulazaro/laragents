<?php

namespace EduLazaro\Laragents\Tests\Unit;

use EduLazaro\Laragents\Tools\ToolContext;
use PHPUnit\Framework\TestCase;

class ToolContextTest extends TestCase
{
    public function test_carries_whatever_the_host_app_puts_in_it(): void
    {
        $context = ToolContext::make(['org' => 'acme', 'case' => 42]);

        $this->assertSame('acme', $context->get('org'));
        $this->assertSame(42, $context->get('case'));
        $this->assertNull($context->get('property'));
    }

    public function test_with_does_not_mutate_the_original(): void
    {
        $base = ToolContext::make(['org' => 'acme']);
        $derived = $base->with('case', 42);

        $this->assertFalse($base->has('case'));
        $this->assertTrue($derived->has('case'));
    }

    public function test_require_fails_loudly_when_a_tool_needs_something_absent(): void
    {
        // Better than a silent null reaching a query three frames down.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Missing 'org' in the tool context.");

        ToolContext::make()->require('org');
    }
}
