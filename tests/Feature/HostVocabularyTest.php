<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\AgentTrigger;
use EduLazaro\Laragents\MemoryDistiller;
use EduLazaro\Laragents\Models\Agent;
use EduLazaro\Laragents\Models\AgentTask;
use EduLazaro\Laragents\Models\ChatMessage;
use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Models\Memory;
use EduLazaro\Laragents\Tests\Fixtures\Matter;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Tests\TestCase;
use EduLazaro\Laragents\Tools\SaveMemoryTool;
use EduLazaro\Laragents\Tools\ToolContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Values the HOST writes are never words this package made up.
 *
 * This is the defect these tests exist to prevent, and it had shipped twice. The failure
 * mode is always the same and always silent: the package compares a value it does not own
 * against a string it invented, the comparison never matches, and the feature does
 * nothing at all. No exception, no log, nothing to search for.
 */
class HostVocabularyTest extends TestCase
{
    public function test_a_memory_is_addressed_by_the_apps_own_morph_alias(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'everyone' => [['memorable' => 'matter', 'content' => 'The hearing moved to June']],
                'actor' => [],
            ])], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ], 200)]);

        config()->set('laragents.memory.min_messages', 1);

        $matter = Matter::create();
        $session = ChatSession::create([
            'tenant_type' => 'firm',
            'tenant_id' => Owner::create()->id,
            'sessionable_type' => 'matter',
            'sessionable_id' => $matter->id,
        ]);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'the hearing moved']);

        // "matter" is this app's word, from its morph map. The package never wrote it.
        $this->assertSame(1, $this->app->make(MemoryDistiller::class)->distil($session));
        $this->assertSame('matter', Memory::sole()->memorable_type);
    }

    public function test_the_prompt_offers_the_apps_words_and_not_the_packages(): void
    {
        $session = ChatSession::create([
            'tenant_type' => 'firm',
            'tenant_id' => Owner::create()->id,
            'sessionable_type' => 'matter',
            'sessionable_id' => Matter::create()->id,
        ]);

        $distiller = new class ($this->app->make(\EduLazaro\Laragents\Contracts\ChatClient::class)) extends MemoryDistiller {
            public function prompt(ChatSession $session): string
            {
                return $this->systemPrompt($session);
            }
        };

        $prompt = $distiller->prompt($session);

        $this->assertStringContainsString('"matter"', $prompt);
        $this->assertStringContainsString('"firm"', $prompt);
        $this->assertStringNotContainsString('"subject"', $prompt);
        $this->assertStringNotContainsString('"owner"', $prompt);
    }

    public function test_save_memory_hands_back_the_valid_values_instead_of_failing_mute(): void
    {
        $session = ChatSession::create([
            'tenant_type' => 'firm',
            'tenant_id' => Owner::create()->id,
        ]);

        $result = (new SaveMemoryTool())->execute(
            ['content' => 'something', 'memorable' => 'potato', 'everyone' => true],
            ToolContext::make(['session' => $session]),
        );

        // The model gets told what it may say, and fixes it on the next turn. Dropping
        // the memory in silence is what the old closed list did.
        $this->assertSame(['firm'], $result['valid_values']);
        $this->assertSame(0, Memory::count());
    }

    public function test_an_app_whose_agents_are_live_and_not_active_still_runs_them(): void
    {
        Queue::fake();
        config()->set('laragents.agents.active_status', 'live');

        Agent::create([
            'tenant_type' => 'firm',
            'tenant_id' => Owner::create()->id,
            'name' => 'Triage',
            'status' => 'live',
            'event_triggers' => ['file.extracted'],
        ]);

        $tenant = Owner::find(Agent::sole()->tenant_id);

        // Your app writes agents.status. The package used to compare it against its own
        // word, so an app that said "live" had every agent silently ignored.
        $this->assertSame(1, $this->app->make(AgentTrigger::class)->fire('file.extracted', $tenant));
        $this->assertSame(1, AgentTask::count());
    }
}
