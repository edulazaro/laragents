<?php
namespace EduLazaro\Laragents\Tests\Feature;
use EduLazaro\Laragents\Models\Agent;
use EduLazaro\Laragents\Models\AgentTask;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Tests\TestCase;
class TaskMetadataTest extends TestCase
{
    public function test_a_run_can_carry_whatever_the_app_needs(): void
    {
        $agent = Agent::create(['tenant_type' => 'firm', 'tenant_id' => Owner::create()->id, 'name' => 'A']);

        $task = AgentTask::create([
            'agent_id' => $agent->id,
            'metadata' => ['territorial_scope' => 'es-md', 'audience_mode' => 'professional'],
        ]);

        // Cast, so it comes back an array and not a string. Without the cast this passes
        // on the way in and fails silently on the way out.
        $this->assertSame('es-md', $task->fresh()->metadata['territorial_scope']);
    }
}
