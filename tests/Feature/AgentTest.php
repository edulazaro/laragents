<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\Models\Agent;
use EduLazaro\Laragents\Models\AgentLog;
use EduLazaro\Laragents\Models\AgentTask;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Tests\TestCase;

class AgentTest extends TestCase
{
    private function agent(array $attributes = []): Agent
    {
        $owner = Owner::create();

        return Agent::create([
            'owner_type' => Owner::class,
            'owner_id' => $owner->id,
            'name' => 'Nightly reviewer',
            ...$attributes,
        ]);
    }

    public function test_an_agent_gets_a_ulid_without_being_asked(): void
    {
        $this->assertNotEmpty($this->agent()->ulid);
    }

    public function test_config_carries_whatever_the_app_needs(): void
    {
        // So the package does not grow a column every time an app wants one more knob.
        $agent = $this->agent(['config' => ['model' => 'nova', 'max_iterations' => 3]]);

        $this->assertSame('nova', $agent->setting('model'));
        $this->assertSame(3, $agent->maxIterations());
        $this->assertSame(10, $this->agent()->maxIterations(), 'falls back to a sane cap');
    }

    public function test_event_triggers_are_just_strings_to_the_package(): void
    {
        $agent = $this->agent(['event_triggers' => ['reply.created', 'case.closed']]);

        $this->assertTrue($agent->listensTo('reply.created'));
        $this->assertFalse($agent->listensTo('invoice.paid'));
    }

    public function test_a_failed_run_is_recorded_not_discarded(): void
    {
        // An agent that quietly stops running is the hardest bug to notice: no output
        // looks exactly like no work to do.
        $task = AgentTask::create(['agent_id' => $this->agent()->id, 'status' => 'pending']);

        $task->markRunning();
        $this->assertSame('running', $task->fresh()->status);
        $this->assertNotNull($task->fresh()->started_at);

        $task->markFailed('the provider timed out');

        $this->assertSame('failed', $task->fresh()->status);
        $this->assertSame('the provider timed out', $task->fresh()->error_message);
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_deleting_an_agent_keeps_the_record_of_what_it_did(): void
    {
        // Soft delete: hard deleting would cascade the tasks and logs away, which is
        // usually the part you wanted to keep.
        $agent = $this->agent();
        $task = AgentTask::create(['agent_id' => $agent->id, 'status' => 'completed']);
        AgentLog::create(['agent_task_id' => $task->id, 'agent_id' => $agent->id, 'message' => 'done']);

        $agent->delete();

        $this->assertSoftDeleted($agent);
        $this->assertDatabaseHas('laragents_agent_tasks', ['id' => $task->id]);
        $this->assertDatabaseHas('laragents_agent_logs', ['message' => 'done']);
    }

    public function test_a_log_line_stamps_itself_and_is_never_updated(): void
    {
        $agent = $this->agent();
        $log = AgentLog::create(['agent_id' => $agent->id, 'message' => 'started']);

        $this->assertNotNull($log->created_at);
        $this->assertFalse($log->timestamps, 'a log line is written once');
    }

    public function test_a_task_can_point_at_whatever_it_is_about(): void
    {
        $subject = Owner::create();

        $task = AgentTask::create([
            'agent_id' => $this->agent()->id,
            'subject_type' => Owner::class,
            'subject_id' => $subject->id,
            'status' => 'pending',
        ]);

        $this->assertTrue($task->subject->is($subject));
    }
}
