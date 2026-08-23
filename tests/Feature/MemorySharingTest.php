<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\MemoryDistiller;
use EduLazaro\Laragents\Models\ChatMessage;
use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Models\Memory;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * Whether what one person says may end up in everyone else's memory.
 *
 * Three states and not two. Null is not false: it means nobody ever asked them, so the
 * configured default applies, and you can change that default later without silently
 * overwriting the people who chose the old one on purpose.
 */
class MemorySharingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('laragents.memory.min_messages', 1);

        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'everyone' => [['memorable' => 'firm', 'content' => 'The bank replies slowly']],
                'actor' => [['memorable' => 'firm', 'content' => 'I keep meaning to chase it']],
            ])], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ], 200)]);
    }

    private function distil(?bool $choice): int
    {
        $actor = Owner::create(['share_memories' => $choice]);

        $session = ChatSession::create([
            'tenant_type' => 'firm',
            'tenant_id' => Owner::create()->id,
            'actor_type' => 'firm',
            'actor_id' => $actor->id,
        ]);

        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'the bank again']);

        return $this->app->make(MemoryDistiller::class)->distil($session);
    }

    public function test_never_having_chosen_follows_the_configured_default(): void
    {
        config()->set('laragents.memory.share_memories', true);

        $this->assertSame(2, $this->distil(null));
        $this->assertSame(1, Memory::whereNull('memory_id')->count());
    }

    public function test_the_default_can_be_the_other_way_round(): void
    {
        config()->set('laragents.memory.share_memories', false);

        // Only their own. Nothing of theirs reaches the shared pool.
        $this->assertSame(1, $this->distil(null));
        $this->assertSame(0, Memory::whereNull('memory_id')->count());
    }

    public function test_a_decision_beats_the_default_in_both_directions(): void
    {
        config()->set('laragents.memory.share_memories', false);
        $this->assertSame(2, $this->distil(true), 'they said yes even though the default is no');

        Memory::query()->delete();

        config()->set('laragents.memory.share_memories', true);
        $this->assertSame(1, $this->distil(false), 'they said no even though the default is yes');
    }

    public function test_opting_out_never_costs_them_their_own_memories(): void
    {
        config()->set('laragents.memory.share_memories', true);

        $this->distil(false);

        $this->assertSame('I keep meaning to chase it', Memory::sole()->content);
        $this->assertNotNull(Memory::sole()->memory_id, 'still theirs, still written');
    }

    public function test_an_actor_that_cannot_be_asked_shares(): void
    {
        config()->set('laragents.memory.share_memories', true);

        $session = ChatSession::create([
            'tenant_type' => 'firm',
            'tenant_id' => Owner::create()->id,
        ]);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'the bank again']);

        // Nobody at the other end. Silence is consent: a package should not switch off a
        // feature nobody asked it to switch off.
        $this->assertSame(1, $this->app->make(MemoryDistiller::class)->distil($session));
        $this->assertSame(0, Memory::whereNotNull('memory_id')->count());
    }

    public function test_changing_your_mind_is_a_method_and_not_a_column_you_go_and_poke(): void
    {
        $actor = Owner::create();

        $this->assertNull($actor->share_memories);

        $actor->setMemorySharing(false);
        $this->assertFalse($actor->fresh()->sharesMemories());

        // And back to never-chose, so the default applies again.
        $actor->setMemorySharing(null);
        config()->set('laragents.memory.share_memories', true);
        $this->assertTrue($actor->fresh()->sharesMemories());
    }
}
