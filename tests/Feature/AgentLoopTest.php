<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\AgentLoop;
use EduLazaro\Laragents\Clients\OpenAiClient;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use EduLazaro\Laragents\Models\ChatMessage;
use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Tests\Fixtures\EchoTool;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Tests\TestCase;
use EduLazaro\Laragents\Tools\ToolContext;
use EduLazaro\Laragents\Tools\ToolRegistry;
use Illuminate\Support\Facades\Http;

class AgentLoopTest extends TestCase
{
    private function newSession(): ChatSession
    {
        $owner = Owner::create();

        return ChatSession::create([
            'tenant_type' => 'firm',
            'tenant_id' => $owner->id,
            'title' => 'test',
        ]);
    }

    private function loop(EchoTool $tool): AgentLoop
    {
        $registry = new ToolRegistry();
        $registry->register($tool);

        return new AgentLoop(
            new OpenAiClient($this->app->make(UsageRecorder::class)),
            $registry,
        );
    }

    /** Two replies in order: first asking for a tool, then answering in prose. */
    private function fakeConversation(): void
    {
        Http::fakeSequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => '',
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'echo_tool', 'arguments' => '{"value":"hola"}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200)
            ->push([
                'choices' => [['message' => ['content' => 'Dijiste hola.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 7],
            ], 200);
    }

    public function test_runs_the_tool_and_comes_back_with_prose(): void
    {
        $this->fakeConversation();
        $tool = new EchoTool();

        $response = $this->loop($tool)->run(
            messages: [['role' => 'user', 'content' => 'di hola']],
            model: 'gpt-4o-mini',
            session: $this->newSession(),
            context: ToolContext::make(['tenant' => 'acme']),
            tools: [['type' => 'function', 'function' => ['name' => 'echo_tool', 'description' => 'x', 'parameters' => []]]],
        );

        $this->assertSame('Dijiste hola.', $response->content);
        $this->assertCount(1, $tool->calls);
        $this->assertSame('hola', $tool->calls[0]['value']);

        // Tokens accumulate across the whole run, not just the last call.
        $this->assertSame(30, $response->inputTokens);
        $this->assertSame(12, $response->outputTokens);
    }

    public function test_the_tool_reads_the_context_it_was_given(): void
    {
        $this->fakeConversation();
        $tool = new EchoTool();

        $this->loop($tool)->run(
            messages: [['role' => 'user', 'content' => 'di hola']],
            model: 'gpt-4o-mini',
            session: $this->newSession(),
            context: ToolContext::make(['tenant' => 'acme']),
            tools: [['type' => 'function', 'function' => ['name' => 'echo_tool', 'description' => 'x', 'parameters' => []]]],
        );

        // The loop carried it through without knowing what a tenant is.
        $result = ChatMessage::where('role', 'tool')->first();
        $this->assertStringContainsString('acme', $result->content);
    }

    public function test_persists_the_plumbing_as_well_as_the_answer(): void
    {
        $this->fakeConversation();
        $session = $this->newSession();

        $this->loop(new EchoTool())->run(
            messages: [['role' => 'user', 'content' => 'di hola']],
            model: 'gpt-4o-mini',
            session: $session,
            context: ToolContext::make(),
            tools: [['type' => 'function', 'function' => ['name' => 'echo_tool', 'description' => 'x', 'parameters' => []]]],
        );

        $roles = ChatMessage::where('session_id', $session->id)->orderBy('id')->pluck('role')->all();

        // assistant (the tool request) -> tool (the result) -> assistant (the answer).
        $this->assertSame(['assistant', 'tool', 'assistant'], $roles);
    }

    public function test_an_unknown_tool_goes_back_to_the_model_instead_of_throwing(): void
    {
        // It can recover by picking another tool. It cannot recover from a stack trace.
        Http::fakeSequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'content' => '',
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'does_not_exist', 'arguments' => '{}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => [],
            ], 200)
            ->push([
                'choices' => [['message' => ['content' => 'No puedo hacer eso.'], 'finish_reason' => 'stop']],
                'usage' => [],
            ], 200);

        $response = $this->loop(new EchoTool())->run(
            messages: [['role' => 'user', 'content' => 'x']],
            model: 'gpt-4o-mini',
            session: $this->newSession(),
            context: ToolContext::make(),
            tools: [],
        );

        $this->assertSame('No puedo hacer eso.', $response->content);
        $this->assertStringContainsString('Unknown tool', ChatMessage::where('role', 'tool')->first()->content);
    }

    public function test_stops_at_the_iteration_cap_instead_of_looping_for_ever(): void
    {
        // A model that keeps asking for tools would otherwise run until something times
        // out, paying for every turn.
        config(['laragents.chat.max_tool_iterations' => 3]);

        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_x',
                        'type' => 'function',
                        'function' => ['name' => 'echo_tool', 'arguments' => '{"value":"otra vez"}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => [],
        ], 200)]);

        $tool = new EchoTool();

        $response = $this->loop($tool)->run(
            messages: [['role' => 'user', 'content' => 'x']],
            model: 'gpt-4o-mini',
            session: $this->newSession(),
            context: ToolContext::make(),
            tools: [['type' => 'function', 'function' => ['name' => 'echo_tool', 'description' => 'x', 'parameters' => []]]],
        );

        $this->assertSame('max_iterations', $response->finishReason);
        $this->assertCount(3, $tool->calls);
        Http::assertSentCount(3);
    }

    public function test_privacy_on_without_laranon_throws_instead_of_passing_data_through(): void
    {
        // The loudest possible failure, on purpose. A warning would be read by nobody and
        // the app would carry on believing it anonymises while the data leaves in clear.
        // This test case does NOT register laranon, which is the point.
        config(['laragents.privacy.enabled' => true]);

        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => [],
        ], 200)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('edulazaro/laranon is not installed');

        $this->loop(new EchoTool())->run(
            messages: [['role' => 'user', 'content' => 'x']],
            model: 'gpt-4o-mini',
            session: $this->newSession(),
            context: ToolContext::make(),
        );

        Http::assertNothingSent();
    }
}
