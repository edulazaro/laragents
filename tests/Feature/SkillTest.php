<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\Models\ChatMessage;
use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Skills\SkillContext;
use EduLazaro\Laragents\Skills\SkillRegistry;
use EduLazaro\Laragents\Tests\Fixtures\EchoTool;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Tests\Fixtures\TimelineSkill;
use EduLazaro\Laragents\Tests\TestCase;
use EduLazaro\Laragents\Tools\RunSkillTool;
use EduLazaro\Laragents\Tools\ToolContext;
use EduLazaro\Laragents\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/**
 * Skills: a named procedure that runs as an agent turn of its own.
 *
 * The distinction that matters is against tools. A tool is one call that returns data.
 * A skill has instructions, a subset of tools, and its own loop, and it hands back a
 * deliverable.
 */
class SkillTest extends TestCase
{
    use RefreshDatabase;

    private function chat(): ChatSession
    {
        return ChatSession::create([
            'tenant_type' => 'firm',
            'tenant_id' => Owner::create()->id,
        ]);
    }

    private function answers(string $text): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7],
        ], 200)]);
    }

    public function test_a_skill_runs_and_returns_its_deliverable(): void
    {
        app(ToolRegistry::class)->register(new EchoTool());
        app(SkillRegistry::class)->register(TimelineSkill::class);
        $this->answers('1990: nace. 2020: reclama.');

        $result = app(SkillRegistry::class)->run('timeline', new SkillContext(
            session: $this->chat(),
            context: ToolContext::make(),
            model: 'gpt-4o',
        ));

        $this->assertSame('1990: nace. 2020: reclama.', $result->content);
        $this->assertSame('timeline', $result->meta['skill']);
        $this->assertSame(18, $result->totalTokens());
    }

    public function test_a_skill_only_gets_the_tools_it_asked_for(): void
    {
        $registry = app(ToolRegistry::class);
        $registry->register(new EchoTool());
        app(SkillRegistry::class)->register(TimelineSkill::class);
        $this->answers('hecho');

        app(SkillRegistry::class)->run('timeline', new SkillContext(
            session: $this->chat(),
            context: ToolContext::make(),
            model: 'gpt-4o',
        ));

        $sent = Http::recorded()[0][0]->data();
        $names = array_column(array_column($sent['tools'] ?? [], 'function'), 'name');

        // Its whitelist is ['echo_tool'], so that is all it sees, however many are
        // registered. A procedure runs bounded or it is not a procedure.
        $this->assertSame(['echo_tool'], $names);
    }

    public function test_an_unknown_skill_says_which_ones_exist(): void
    {
        app(SkillRegistry::class)->register(TimelineSkill::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('There is: timeline');

        app(SkillRegistry::class)->run('cronologia', new SkillContext(
            session: $this->chat(),
            context: ToolContext::make(),
        ));
    }

    public function test_the_catalogue_is_rendered_into_the_tool_the_model_sees(): void
    {
        app(SkillRegistry::class)->register(TimelineSkill::class);

        $description = app(RunSkillTool::class)->description();

        // Registering a skill is enough for the model to learn it exists. Nobody has to
        // remember to also write it into a prompt.
        $this->assertStringContainsString('timeline: Builds a timeline', $description);
        $this->assertSame(['timeline'], app(RunSkillTool::class)->parameters()['properties']['skill']['enum']);
    }

    public function test_running_a_skill_from_a_conversation_writes_none_of_its_turns(): void
    {
        app(ToolRegistry::class)->register(new EchoTool());
        app(SkillRegistry::class)->register(TimelineSkill::class);
        $this->answers('la cronología');

        $session = $this->chat();

        $result = app(RunSkillTool::class)->execute(
            ['skill' => 'timeline'],
            ToolContext::make(['session' => $session, 'model' => 'gpt-4o']),
        );

        $this->assertSame('la cronología', $result['content']);

        // The outer conversation owns its history. Interleaving the inner agent's
        // plumbing would break the pairing of assistant turns to their tool results
        // when the history is replayed next turn.
        $this->assertSame(0, ChatMessage::where('session_id', $session->id)->count());
    }

    public function test_run_skill_outside_a_conversation_says_so(): void
    {
        app(SkillRegistry::class)->register(TimelineSkill::class);

        $result = app(RunSkillTool::class)->execute(['skill' => 'timeline'], ToolContext::make());

        $this->assertStringContainsString('needs a conversation', $result['error']);
    }
}
