<?php

namespace EduLazaro\Laragents\Jobs;

use EduLazaro\Laragents\AgentRunner;
use EduLazaro\Laragents\Models\AgentTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs one agent task.
 *
 * Queued, because an agent run is a loop of model calls and can take minutes. Put it on
 * a queue of its own with a worker of its own: sharing one with anything interactive
 * means a long run holds a worker while a person waits.
 */
class RunAgentTask implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $taskId)
    {
        $this->onQueue(config('laragents.agents.queue', 'default'));
    }

    public function handle(AgentRunner $runner): void
    {
        $task = AgentTask::find($this->taskId);

        // Deleted between dispatch and pickup. Nothing to do and nothing to report.
        if (! $task) {
            return;
        }

        $runner->run($task);
    }
}
