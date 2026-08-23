<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;

/**
 * That installing the package is enough for a scheduled agent to actually run.
 *
 * A schedule nobody registered has no symptom: the agent sits there with a cron
 * expression, the form says it runs every morning, and it never does. This is the same
 * failure as a queue with no worker, and it is found by asking rather than by noticing.
 */
class ScheduleTest extends TestCase
{
    /** @return array<int, string> */
    private function scheduled(): array
    {
        return array_map(
            fn ($event) => (string) $event->command,
            $this->app->make(Schedule::class)->events(),
        );
    }

    private function has(string $signature): bool
    {
        foreach ($this->scheduled() as $command) {
            if (str_contains($command, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function test_the_minute_tick_is_registered_without_the_app_doing_anything(): void
    {
        // Every minute, because that is the finest granularity a cron expression names.
        $this->assertTrue($this->has('laragents:run-due-agents'));
    }

    public function test_the_stale_task_sweep_is_registered(): void
    {
        $this->assertTrue($this->has('laragents:cleanup-stale-tasks'));
    }

    public function test_the_distillation_sweep_is_registered(): void
    {
        $this->assertTrue($this->has('laragents:distil-inactive-sessions'));
    }

    public function test_the_tick_runs_every_minute_and_only_on_one_server(): void
    {
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, 'laragents:run-due-agents')) {
                $this->assertSame('* * * * *', $event->expression);
                // Several web nodes, one tick. And skipping an overlapping minute is safe
                // now that dueness is measured from the last run.
                $this->assertTrue($event->onOneServer);

                return;
            }
        }

        $this->fail('The tick is not on the schedule.');
    }
}
