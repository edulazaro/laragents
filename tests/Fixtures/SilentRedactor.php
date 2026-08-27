<?php

namespace EduLazaro\Laragents\Tests\Fixtures;

use EduLazaro\Laragents\Contracts\Redactor;

/** An app's own redactor, which happens to redact nothing. For tests. */
class SilentRedactor implements Redactor
{
    public function beginTurn(): void
    {
    }

    public function outbound(array $messages): array
    {
        return $messages;
    }

    public function inbound(string $text): string
    {
        return $text;
    }

    public function inboundToolCalls(array $toolCalls): array
    {
        return $toolCalls;
    }
}
