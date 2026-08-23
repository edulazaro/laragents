<?php

namespace EduLazaro\Laragents\Console;

use EduLazaro\Laragents\Models\AgentTask;
use Illuminate\Console\Command;

/**
 * Fails tasks that have been "running" longer than any run could take.
 *
 * A worker killed mid-run (a deploy, an OOM, a machine going away) leaves its task
 * marked running for ever. Nothing retries it and nothing reports it, so the agent looks
 * busy rather than broken, and the next scheduled run may skip it for that reason.
 */
class CleanupStaleTasksCommand extends Command
{
    protected $signature = 'laragents:cleanup-stale-tasks';

    protected $description = 'Mark orphaned running tasks as failed';

    public function handle(): int
    {
        $minutes = (int) config('laragents.agents.stale_after_minutes', 30);

        $stale = AgentTask::query()
            ->where('status', 'running')
            ->where('started_at', '<', now()->subMinutes($minutes))
            ->update([
                'status' => 'failed',
                'error_message' => "No worker reported back within {$minutes} minutes. Marked failed by laragents:cleanup-stale-tasks.",
                'completed_at' => now(),
            ]);

        if ($stale > 0) {
            $this->warn("laragents: {$stale} stale task(s) marked failed.");
        }

        return self::SUCCESS;
    }
}
