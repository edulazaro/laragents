<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\Models\Agent;
use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Models\Memory;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Tests\TestCase;
use Illuminate\Database\QueryException;

/**
 * What happens to a conversation when the agent that held it is gone.
 *
 * All seven tables ship in one migration precisely so this can be enforced rather than
 * hoped for.
 */
class ForeignKeyTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Off by default on SQLite, which would make every assertion here pass for the
        // wrong reason.
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);
    }

    private function agent(): Agent
    {
        return Agent::create([
            'tenant_type' => 'firm',
            'tenant_id' => Owner::create()->id,
            'name' => 'Triage',
        ]);
    }

    public function test_deleting_an_agent_does_not_delete_the_conversations_it_held(): void
    {
        $agent = $this->agent();

        $session = ChatSession::create([
            'tenant_type' => 'firm',
            'tenant_id' => Owner::create()->id,
            'agent_id' => $agent->id,
        ]);

        $agent->forceDelete();

        // The history stays. It just stops naming who ran it. Cascading here would delete
        // people's conversations because somebody retired a bot.
        $this->assertNotNull($session->fresh());
        $this->assertNull($session->fresh()->agent_id);
    }

    public function test_what_an_agent_learned_outlives_the_agent(): void
    {
        $agent = $this->agent();
        $org = Owner::create();

        $memory = Memory::create([
            'tenant_type' => 'firm',
            'tenant_id' => $org->id,
            'agent_id' => $agent->id,
            'content' => 'The bank replies slowly',
        ]);

        $agent->forceDelete();

        $this->assertSame('The bank replies slowly', $memory->fresh()->content);
        $this->assertNull($memory->fresh()->agent_id);
    }

    public function test_a_session_cannot_point_at_an_agent_that_does_not_exist(): void
    {
        $this->expectException(QueryException::class);

        ChatSession::create([
            'tenant_type' => 'firm',
            'tenant_id' => Owner::create()->id,
            'agent_id' => 9999,
        ]);
    }
}
