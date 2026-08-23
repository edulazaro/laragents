<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\Jobs\RunAgentTask;
use EduLazaro\Laragents\Models\Agent;
use EduLazaro\Laragents\Models\AgentTask;
use EduLazaro\Laragents\Tests\Fixtures\FileWasUploaded;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Tests\Fixtures\PlaceUploadedFile;
use EduLazaro\Laragents\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

/**
 * The bridge between the app's own events and its agents.
 *
 * This is the piece that is easy to leave out and impossible to notice missing: the form
 * offers "when a document is uploaded", somebody picks it, and with nothing listening the
 * agent simply never runs. Declaring the mapping should be the whole job.
 */
class EventBridgeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laragents.events', [
            FileWasUploaded::class => PlaceUploadedFile::class,
        ]);
    }

    public function test_declaring_the_mapping_is_the_whole_job(): void
    {
        Queue::fake();

        $org = Owner::create();
        $case = Owner::create();

        Agent::create([
            'tenant_type' => 'firm',
            'tenant_id' => $org->id,
            'name' => 'Triage',
            'status' => 'active',
            'event_triggers' => ['file.extracted'],
        ]);

        // The app dispatches its own event. Nothing here knows what a file is.
        event(new FileWasUploaded($org, $case));

        $task = AgentTask::sole();

        $this->assertSame('file.extracted', $task->trigger_event);
        $this->assertSame($case->id, $task->taskable_id);
        $this->assertSame('sentencia.pdf', $task->input['file_name']);

        Queue::assertPushed(RunAgentTask::class);
    }

    public function test_a_resolver_that_cannot_place_the_event_stops_it(): void
    {
        Queue::fake();

        Agent::create([
            'tenant_type' => 'firm',
            'tenant_id' => Owner::create()->id,
            'name' => 'Triage',
            'status' => 'active',
            'event_triggers' => ['file.extracted'],
        ]);

        // An upload belonging to no tenant. The resolver returns null and nothing fires.
        event(new FileWasUploaded(org: null));

        $this->assertSame(0, AgentTask::count());
        Queue::assertNothingPushed();
    }
}
