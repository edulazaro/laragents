<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\AgentTrigger;
use EduLazaro\Laragents\Jobs\RunAgentTask;
use EduLazaro\Laragents\Models\Agent;
use EduLazaro\Laragents\Models\AgentTask;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * What makes an agent actually run.
 *
 * Without this the agents table is furniture: rows carrying a cron expression and a list
 * of events that nothing reads. That failure has no symptom, which is what makes it
 * expensive: the form says the agent fires on uploads, it never fires, and nobody finds
 * out until they ask why nothing was triaged.
 */
class AgentTriggerTest extends TestCase
{
    private Owner $org;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->org = Owner::create();
    }

    private function agent(array $attributes = []): Agent
    {
        return Agent::create(array_merge([
            'tenant_type' => 'firm',
            'tenant_id' => $this->org->id,
            'name' => 'Triage',
            'status' => 'active',
        ], $attributes));
    }

    private function trigger(): AgentTrigger
    {
        return $this->app->make(AgentTrigger::class);
    }

    // ─── Events ─────────────────────────────────────────────────────

    public function test_an_event_wakes_the_agents_listening_for_it(): void
    {
        $listening = $this->agent(['event_triggers' => ['file.extracted']]);
        $this->agent(['name' => 'Deaf', 'event_triggers' => ['reply.created']]);

        $this->assertSame(1, $this->trigger()->fire('file.extracted', $this->org));

        $task = AgentTask::sole();

        $this->assertSame($listening->id, $task->agent_id);
        $this->assertSame('event', $task->trigger_type);
        $this->assertSame('file.extracted', $task->trigger_event);

        Queue::assertPushed(RunAgentTask::class);
    }

    public function test_an_agent_of_another_tenant_never_hears_it(): void
    {
        $this->agent(['event_triggers' => ['file.extracted']]);

        $this->assertSame(0, $this->trigger()->fire('file.extracted', Owner::create()));
        $this->assertSame(0, AgentTask::count());
    }

    public function test_a_paused_agent_stays_paused(): void
    {
        $this->agent(['event_triggers' => ['file.extracted'], 'status' => 'paused']);

        $this->assertSame(0, $this->trigger()->fire('file.extracted', $this->org));
    }

    public function test_the_same_event_twice_in_a_moment_runs_once(): void
    {
        $this->agent(['event_triggers' => ['file.extracted']]);
        $subject = Owner::create();

        // An observer fires on every save, and one logical change written in three
        // statements is three events. Without the debounce the agent answers three times
        // and is billed for all three.
        $this->trigger()->fire('file.extracted', $this->org, $subject);
        $this->trigger()->fire('file.extracted', $this->org, $subject);
        $this->trigger()->fire('file.extracted', $this->org, $subject);

        $this->assertSame(1, AgentTask::count());
    }

    public function test_the_same_event_on_a_different_subject_is_a_different_thing(): void
    {
        $this->agent(['event_triggers' => ['file.extracted']]);

        $this->trigger()->fire('file.extracted', $this->org, Owner::create());
        $this->trigger()->fire('file.extracted', $this->org, Owner::create());

        // Two documents uploaded together are two documents, not one event twice.
        $this->assertSame(2, AgentTask::count());
    }

    public function test_the_context_reaches_the_task(): void
    {
        $this->agent(['event_triggers' => ['file.extracted']]);

        $this->trigger()->fire('file.extracted', $this->org, null, ['file_name' => 'sentencia.pdf']);

        $input = AgentTask::sole()->input;

        $this->assertSame('sentencia.pdf', $input['file_name']);
        $this->assertSame('file.extracted', $input['event']);
    }

    // ─── Schedule ───────────────────────────────────────────────────

    public function test_a_schedule_that_has_come_round_dispatches(): void
    {
        Carbon::setTestNow('2026-03-02 08:59:00');
        $agent = $this->agent(['schedule_cron' => '0 9 * * *']);

        $this->assertSame(0, $this->trigger()->due(), 'not yet');

        Carbon::setTestNow('2026-03-02 09:00:00');

        $this->assertSame(1, $this->trigger()->due());
        $this->assertSame('schedule', AgentTask::sole()->trigger_type);
        $this->assertNotNull($agent->fresh()->last_run_at);
    }

    public function test_a_missed_tick_is_caught_up_instead_of_lost(): void
    {
        Carbon::setTestNow('2026-03-02 08:00:00');
        $this->agent(['schedule_cron' => '0 9 * * *']);

        // The worker was down at 09:00, or withoutOverlapping skipped that minute.
        // isDue(now()) is true only during the exact minute named, so measuring against
        // the clock loses the run for ever. Measuring from the last run picks it up.
        Carbon::setTestNow('2026-03-02 09:07:00');

        $this->assertSame(1, $this->trigger()->due());
    }

    public function test_it_does_not_run_twice_for_the_same_slot(): void
    {
        Carbon::setTestNow('2026-03-02 08:00:00');
        $this->agent(['schedule_cron' => '0 9 * * *']);

        Carbon::setTestNow('2026-03-02 09:00:00');
        $this->assertSame(1, $this->trigger()->due());

        Carbon::setTestNow('2026-03-02 09:01:00');
        $this->assertSame(0, $this->trigger()->due(), 'the tick runs every minute; the agent does not');
    }

    public function test_switching_an_agent_on_does_not_make_it_fire_on_the_spot(): void
    {
        Carbon::setTestNow('2026-03-02 14:00:00');
        $this->agent(['schedule_cron' => '0 9 * * *']);

        // Created at 14:00 with a 09:00 schedule. The baseline for one that has never run
        // is when it was created, so the next 09:00 is tomorrow's.
        $this->assertSame(0, $this->trigger()->due());

        Carbon::setTestNow('2026-03-03 09:00:00');
        $this->assertSame(1, $this->trigger()->due());
    }

    public function test_one_bad_cron_expression_does_not_stop_the_tick_for_everybody(): void
    {
        Carbon::setTestNow('2026-03-02 08:00:00');
        $this->agent(['name' => 'Broken', 'schedule_cron' => 'every tuesday please']);
        $this->agent(['name' => 'Fine', 'schedule_cron' => '0 9 * * *']);

        Carbon::setTestNow('2026-03-02 09:00:00');

        $this->assertSame(1, $this->trigger()->due());
        $this->assertSame('Fine', AgentTask::sole()->agent->name);
    }

    public function test_a_scheduled_agent_can_run_once_per_subject(): void
    {
        Carbon::setTestNow('2026-03-02 08:00:00');
        $this->agent(['schedule_cron' => '0 9 * * *']);
        $cases = [Owner::create(), Owner::create(), Owner::create()];

        Carbon::setTestNow('2026-03-02 09:00:00');

        $this->assertSame(3, $this->trigger()->due($cases));
        $this->assertSame(3, AgentTask::count());
    }

    public function test_an_agent_with_no_schedule_is_never_due(): void
    {
        $this->agent(['event_triggers' => ['file.extracted']]);

        $this->assertSame(0, $this->trigger()->due());
    }
}
