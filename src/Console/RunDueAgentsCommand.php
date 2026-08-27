<?php

namespace EduLazaro\Laragents\Console;

use EduLazaro\Laragents\AgentTrigger;
use Illuminate\Console\Command;

/**
 * The tick. Registered on the scheduler by the service provider, every minute, because
 * that is the finest granularity a cron expression can name.
 *
 * Every minute is cheap: one query for active agents with a schedule, and an expression
 * evaluated per row. It only costs anything on the minutes something is actually due.
 */
class RunDueAgentsCommand extends Command
{
    protected $signature = 'laragents:run-due-agents';

    protected $description = 'Dispatch a task for every agent whose schedule has come round';

    /**
     * Run it.
     *
     * @param AgentTrigger $trigger
     * @return int
     */
    public function handle(AgentTrigger $trigger): int
    {
        $dispatched = $trigger->due();

        if ($dispatched > 0) {
            $this->info("laragents: dispatched {$dispatched} scheduled task(s).");
        }

        return self::SUCCESS;
    }
}
