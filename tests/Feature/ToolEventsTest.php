<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\AgentLoop;
use EduLazaro\Laragents\Clients\OpenAiClient;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use EduLazaro\Laragents\Events\InvokingTool;
use EduLazaro\Laragents\Events\ToolInvoked;
use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Tests\Fixtures\EchoTool;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Tests\TestCase;
use EduLazaro\Laragents\Tools\ToolContext;
use EduLazaro\Laragents\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Saying what the loop is doing, so an app can watch without editing it.
 *
 * The app this came from had a Log::debug wedged inside its executor for this, which
 * meant every app got that one line and no app could have its own.
 */
class ToolEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laragents.openai.api_key', 'test-key');
    }

    private function turn(): ChatSession
    {
        $registry = new ToolRegistry();
        $registry->register(new EchoTool());

        $session = ChatSession::create([
            'tenant_type' => 'firm',
            'tenant_id' => Owner::create()->id,
        ]);

        Http::fakeSequence()
            ->push([
                'choices' => [[
                    'message' => ['content' => '', 'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'echo_tool', 'arguments' => '{"value":"hola"}'],
                    ]]],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
            ], 200)
            ->push([
                'choices' => [['message' => ['content' => 'listo'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
            ], 200);

        (new AgentLoop(new OpenAiClient($this->app->make(UsageRecorder::class)), $registry))
            ->run([], 'gpt-4o', $session, ToolContext::make(), []);

        return $session;
    }

    public function test_both_events_fire_with_what_happened(): void
    {
        Event::fake([InvokingTool::class, ToolInvoked::class]);

        $session = $this->turn();

        Event::assertDispatched(InvokingTool::class, fn (InvokingTool $e) => $e->tool === 'echo_tool'
            && $e->arguments === ['value' => 'hola']
            && $e->session->is($session));

        Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $e) => $e->tool === 'echo_tool'
            && $e->result['echoed'] === 'hola'
            && $e->seconds >= 0);
    }

    public function test_nothing_fires_when_no_tool_runs(): void
    {
        Event::fake([InvokingTool::class, ToolInvoked::class]);

        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'sin tools'], 'finish_reason' => 'stop']],
            'usage' => [],
        ], 200)]);

        $session = ChatSession::create(['tenant_type' => 'firm', 'tenant_id' => Owner::create()->id]);

        (new AgentLoop(new OpenAiClient($this->app->make(UsageRecorder::class)), new ToolRegistry()))
            ->run([], 'gpt-4o', $session, ToolContext::make(), []);

        Event::assertNotDispatched(InvokingTool::class);
    }
}
