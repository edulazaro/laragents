<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\AgentRunner;
use EduLazaro\Laragents\Clients\OpenAiClient;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use EduLazaro\Laragents\Models\Agent;
use EduLazaro\Laragents\Models\AgentLog;
use EduLazaro\Laragents\Models\AgentTask;
use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Tests\TestCase;
use EduLazaro\Laragents\Tools\ToolRegistry;
use Illuminate\Support\Facades\Http;

class AgentRunnerTest extends TestCase
{
    private function runner(): AgentRunner
    {
        return new AgentRunner(
            new OpenAiClient($this->app->make(UsageRecorder::class)),
            new ToolRegistry(),
        );
    }

    private function agent(array $config = ['model' => 'gpt-4o-mini'], array $attributes = []): Agent
    {
        return Agent::create([
            'owner_type' => Owner::class,
            'owner_id' => Owner::create()->id,
            'name' => 'Nightly reviewer',
            'system_prompt' => 'You review things.',
            'config' => $config,
            ...$attributes,
        ]);
    }

    private function task(Agent $agent, array $input = []): AgentTask
    {
        return AgentTask::create([
            'agent_id' => $agent->id,
            'status' => 'pending',
            'input' => $input,
        ]);
    }

    private function fakeAnswer(string $content = 'Reviewed.'): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8],
        ], 200)]);
    }

    public function test_a_run_records_its_result_and_moves_the_counters(): void
    {
        $this->fakeAnswer();
        $agent = $this->agent();
        $task = $this->task($agent);

        $response = $this->runner()->run($task);

        $this->assertSame('Reviewed.', $response->content);
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame('Reviewed.', $task->fresh()->output['content']);
        $this->assertSame(1, $agent->fresh()->tasks_completed);
        $this->assertSame(20, $agent->fresh()->total_tokens_used);
        $this->assertNotNull($agent->fresh()->last_run_at);
    }

    public function test_a_run_is_stored_as_a_session_like_any_conversation(): void
    {
        $this->fakeAnswer();
        $task = $this->task($this->agent());

        $this->runner()->run($task);

        $session = ChatSession::first();
        $this->assertNotNull($session);
        $this->assertNull($session->actor_id, 'nobody is at the other end of an agent run');
        $this->assertSame($task->id, $session->metadata['agent_task_id']);
    }

    public function test_a_paused_agent_does_not_run(): void
    {
        $this->fakeAnswer();
        $task = $this->task($this->agent(attributes: ['status' => 'paused']));

        $this->assertNull($this->runner()->run($task));
        $this->assertSame('failed', $task->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_the_daily_cap_stops_a_misconfiguration_becoming_an_invoice(): void
    {
        // An agent wired to an event that fires in a loop keeps spawning tasks, and
        // every one of them costs money.
        $this->fakeAnswer();
        $agent = $this->agent(['model' => 'gpt-4o-mini', 'max_tasks_per_day' => 2]);

        $this->task($agent);
        $this->task($agent);
        $third = $this->task($agent);

        $this->assertNull($this->runner()->run($third));
        $this->assertStringContainsString('Daily task limit', $third->fresh()->error_message);
        Http::assertNothingSent();
    }

    public function test_a_failure_is_written_down_rather_than_lost(): void
    {
        // Nobody is watching an autonomous run: the log is the only account of it.
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'boom'], 500)]);
        $agent = $this->agent();
        $task = $this->task($agent);

        $this->assertNull($this->runner()->run($task));

        $this->assertSame('failed', $task->fresh()->status);
        $this->assertNotEmpty($task->fresh()->error_message);
        $this->assertSame(1, $agent->fresh()->tasks_failed);
        $this->assertTrue(
            AgentLog::where('agent_task_id', $task->id)->where('level', 'error')->exists(),
        );
    }

    public function test_an_agent_with_no_model_configured_is_an_error_not_a_guess(): void
    {
        // Quietly picking one and billing for it is the failure mode this whole design
        // exists to prevent.
        $this->fakeAnswer();
        $task = $this->task($this->agent(config: []));

        $this->assertNull($this->runner()->run($task));
        $this->assertStringContainsString('no model in its config', $task->fresh()->error_message);
        Http::assertNothingSent();
    }
}
