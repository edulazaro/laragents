<?php

namespace EduLazaro\Laragents\Events;

use EduLazaro\Laragents\Models\ChatSession;

/**
 * A tool has run.
 *
 * Carries how long it took, because "the chat is slow" is almost always one tool and
 * there is no way to know which without this.
 *
 * Observe only: the result has already gone back to the model by the time this fires,
 * so changing it here changes nothing.
 */
class ToolInvoked
{
    /**
     * @param string $tool
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $result
     * @param float $seconds
     * @param ChatSession $session
     * @return void
     */
    public function __construct(
        public readonly string $tool,
        public readonly array $arguments,
        public readonly array $result,
        public readonly float $seconds,
        public readonly ChatSession $session,
    ) {
    }
}
