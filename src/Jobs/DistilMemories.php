<?php

namespace EduLazaro\Laragents\Jobs;

use EduLazaro\Laragents\MemoryDistiller;
use EduLazaro\Laragents\Models\ChatSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Distils one session, off the request.
 *
 * Queued because it costs a model call. Charging the user's reply for the privilege of
 * remembering it is how a chat gets slow, and remembering is never the urgent part.
 */
class DistilMemories implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $sessionId,
        public ?int $minMessages = null,
    ) {
        $this->onQueue(config('laragents.memory.queue', 'default'));
    }

    public function handle(MemoryDistiller $distiller): void
    {
        $session = ChatSession::find($this->sessionId);

        if (! $session) {
            return;
        }

        $distiller->distil($session, $this->minMessages);
    }
}
