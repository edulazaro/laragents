<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\AgentLoop;
use EduLazaro\Laragents\Clients\OpenAiClient;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use EduLazaro\Laragents\Models\ChatMessage;
use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Tests\Fixtures\EchoTool;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Tests\Fixtures\SearchTool;
use EduLazaro\Laragents\Tests\TestCase;
use EduLazaro\Laragents\Tools\ToolContext;
use EduLazaro\Laragents\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/**
 * Telling a model to stop searching when it keeps finding nothing.
 *
 * Without this, an empty result makes it reformulate and try again until the iteration
 * cap ends the run, and the user gets `max_iterations` instead of "there is nothing
 * there". The cap stops the spending; this stops the spiral.
 */
class EmptyStreakTest extends TestCase
{
    use RefreshDatabase;

    private function chat(): ChatSession
    {
        return ChatSession::create([
            'tenant_type' => 'firm',
            'tenant_id' => Owner::create()->id,
            'title' => 'test',
        ]);
    }

    private function loop(object $tool): AgentLoop
    {
        $registry = new ToolRegistry();
        $registry->register($tool);

        return new AgentLoop(
            new OpenAiClient($this->app->make(UsageRecorder::class)),
            $registry,
        );
    }

    /** N replies asking for the tool, then one in prose. */
    private function fakeCalls(string $tool, int $times): void
    {
        $sequence = Http::fakeSequence();

        for ($i = 0; $i < $times; $i++) {
            $sequence->push([
                'choices' => [[
                    'message' => [
                        'content' => '',
                        'tool_calls' => [[
                            'id' => "call_{$i}",
                            'type' => 'function',
                            'function' => ['name' => $tool, 'arguments' => '{"q":"algo"}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
            ], 200);
        }

        $sequence->push([
            'choices' => [['message' => ['content' => 'No he encontrado nada.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
        ], 200);
    }

    private function toolResults(ChatSession $session): array
    {
        return ChatMessage::where('session_id', $session->id)
            ->where('role', 'tool')
            ->orderBy('id')
            ->pluck('content')
            ->map(fn (string $json) => json_decode($json, true))
            ->all();
    }

    public function test_the_second_empty_search_in_a_row_is_told_to_stop(): void
    {
        $tool = new SearchTool();
        $tool->returns = [['items' => []], ['items' => []]];

        $session = $this->chat();
        $this->fakeCalls('search_tool', 2);

        $this->loop($tool)->run([], 'gpt-4o', $session, ToolContext::make(), []);

        [$first, $second] = $this->toolResults($session);

        $this->assertArrayNotHasKey('_stop_searching', $first, 'once is a reformulation');
        $this->assertTrue($second['_stop_searching']);
        $this->assertStringContainsString('2 searches in a row', $second['_hint']);
    }

    public function test_finding_something_forgives_what_came_before(): void
    {
        $tool = new SearchTool();
        $tool->returns = [['items' => []], ['items' => ['algo']], ['items' => []]];

        $session = $this->chat();
        $this->fakeCalls('search_tool', 3);

        $this->loop($tool)->run([], 'gpt-4o', $session, ToolContext::make(), []);

        $results = $this->toolResults($session);

        $this->assertArrayNotHasKey('_stop_searching', $results[2], 'the streak restarted');
    }

    public function test_a_tool_that_returns_no_collection_neither_counts_nor_forgives(): void
    {
        // A tool that files a document has not searched: it neither found nor failed to
        // find. If it reset the streak, one bookkeeping call between two dead searches
        // would hide the spiral.
        $search = new SearchTool();
        $search->returns = [['items' => []], ['items' => []]];

        $registry = new ToolRegistry();
        $registry->register($search);
        $registry->register(new EchoTool());

        $loop = new AgentLoop(
            new OpenAiClient($this->app->make(UsageRecorder::class)),
            $registry,
        );

        $session = $this->chat();

        Http::fakeSequence()
            ->push($this->callFor('search_tool', 'a'), 200)
            ->push($this->callFor('echo_tool', 'b', '{"value":"x"}'), 200)
            ->push($this->callFor('search_tool', 'c'), 200)
            ->push([
                'choices' => [['message' => ['content' => 'Nada.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
            ], 200);

        $loop->run([], 'gpt-4o', $session, ToolContext::make(), []);

        $results = $this->toolResults($session);

        $this->assertTrue($results[2]['_stop_searching'], 'the echo in between did not forgive it');
    }

    public function test_a_failing_tool_counts_as_finding_nothing(): void
    {
        // Same spiral, different cause: a model retrying a tool that errors will retry
        // it until the iterations run out.
        $tool = new SearchTool();
        $tool->returns = [['error' => 'boom'], ['error' => 'boom']];

        $session = $this->chat();
        $this->fakeCalls('search_tool', 2);

        $this->loop($tool)->run([], 'gpt-4o', $session, ToolContext::make(), []);

        $this->assertTrue($this->toolResults($session)[1]['_stop_searching']);
    }

    public function test_the_tools_own_hint_survives(): void
    {
        // The tool's hint says HOW to reformulate. Replacing it leaves the model with an
        // order to stop and no idea what it was doing wrong.
        $tool = new SearchTool();
        $tool->returns = [
            ['items' => [], '_hint' => 'Prueba con el número de expediente.'],
            ['items' => [], '_hint' => 'Prueba con el número de expediente.'],
        ];

        $session = $this->chat();
        $this->fakeCalls('search_tool', 2);

        $this->loop($tool)->run([], 'gpt-4o', $session, ToolContext::make(), []);

        $hint = $this->toolResults($session)[1]['_hint'];

        $this->assertStringContainsString('número de expediente', $hint);
        $this->assertStringContainsString('searches in a row', $hint);
    }

    public function test_it_can_be_turned_off(): void
    {
        config()->set('laragents.chat.empty_streak_after', 0);

        $tool = new SearchTool();
        $tool->returns = [['items' => []], ['items' => []]];

        $session = $this->chat();
        $this->fakeCalls('search_tool', 2);

        $this->loop($tool)->run([], 'gpt-4o', $session, ToolContext::make(), []);

        $this->assertArrayNotHasKey('_stop_searching', $this->toolResults($session)[1]);
    }

    private function callFor(string $tool, string $id, string $args = '{"q":"algo"}'): array
    {
        return [
            'choices' => [[
                'message' => [
                    'content' => '',
                    'tool_calls' => [[
                        'id' => $id,
                        'type' => 'function',
                        'function' => ['name' => $tool, 'arguments' => $args],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
        ];
    }
}
