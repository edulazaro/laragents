<?php

namespace EduLazaro\Laragents\Tests\Unit;

use EduLazaro\Laragents\ModelCapabilities;
use EduLazaro\Laragents\Tests\TestCase;

/**
 * The gpt-5.6 family refuses function tools unless reasoning is explicitly off.
 *
 * Measured against the API: with tools and no `reasoning_effort` the call is a 400
 * ("Function tools with reasoning_effort are not supported ... in
 * /v1/chat/completions"); with `reasoning_effort: 'none'` it is a 200. Omitting the
 * parameter is not the same as switching it off, which is the whole trap.
 */
class ToolsReasoningTest extends TestCase
{
    public function test_the_gpt_5_6_family_needs_reasoning_switched_off_for_tools(): void
    {
        foreach (['gpt-5.6-terra', 'gpt-5.6-luna', 'gpt-5.6'] as $model) {
            $this->assertTrue(
                ModelCapabilities::toolsRequireReasoningOff($model),
                "{$model} should require reasoning_effort: none alongside tools",
            );
        }
    }

    public function test_nothing_else_gets_the_parameter(): void
    {
        // o1 and o3 take low/medium/high and would 400 on 'none'; the 4 family does not
        // take the parameter at all. Sending it to any of them would break calls that
        // work today.
        foreach (['gpt-4.1', 'gpt-4o', 'gpt-4o-mini', 'o1', 'o3-mini', 'gpt-5-chat-latest', 'claude-opus-5'] as $model) {
            $this->assertFalse(
                ModelCapabilities::toolsRequireReasoningOff($model),
                "{$model} must not be sent reasoning_effort",
            );
        }
    }
}
