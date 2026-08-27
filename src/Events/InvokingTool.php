<?php

namespace EduLazaro\Laragents\Events;

use EduLazaro\Laragents\Models\ChatSession;

/**
 * A tool is about to run.
 *
 * Fired so an app can log, trace or measure without editing the loop. The app this
 * came from had a Log::debug wedged inside its executor for exactly this, which meant
 * every app got that one line of logging and no app could have its own.
 *
 * Observe only: what a listener does with these does not reach the model.
 */
class InvokingTool
{
    /**
     * @param string $tool
     * @param array<string, mixed> $arguments What the model asked for.
     * @param ChatSession $session
     * @return void
     */
    public function __construct(
        public readonly string $tool,
        public readonly array $arguments,
        public readonly ChatSession $session,
    ) {
    }
}
