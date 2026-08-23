<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Models\Memory;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * Semantic recall, and everything it degrades to.
 *
 * The degrading matters more than the ranking: embeddings are off by default and the
 * provider can be down, so search has to keep answering either way.
 */
class EmbeddingTest extends TestCase
{
    private Owner $org;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laragents.memory.embeddings', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Owner::create();
    }

    private function chat(): ChatSession
    {
        return ChatSession::create([
            'tenant_type' => 'firm',
            'tenant_id' => $this->org->id,
        ]);
    }

    /** @param array<int, float> $vector */
    private function remember(string $content, ?array $vector): Memory
    {
        return Memory::create([
            'memorable_type' => 'firm',
            'memorable_id' => $this->org->id,
            'content' => $content,
            'embedding' => $vector,
        ]);
    }

    /** @param array<int, float> $vector */
    private function providerReturns(array $vector): void
    {
        Http::fake(['api.openai.com/v1/embeddings' => Http::response([
            'data' => [['embedding' => $vector]],
            'usage' => ['prompt_tokens' => 8],
        ], 200)]);
    }

    public function test_the_closest_memory_comes_first(): void
    {
        $this->remember('about deadlines', [1.0, 0.0, 0.0]);
        $this->remember('about invoices', [0.0, 1.0, 0.0]);
        $this->remember('about meetings', [0.0, 0.0, 1.0]);

        $this->providerReturns([0.9, 0.1, 0.0]);

        $found = Memory::search($this->chat(), 'when is it due', 3);

        $this->assertSame('about deadlines', $found->first()->content);
        $this->assertSame('about meetings', $found->last()->content);
    }

    public function test_direction_decides_and_not_magnitude(): void
    {
        // Both score the same under a raw dot product: 1x1 + 0x0 = 1 for the note, and
        // 1x1 + 0x50 = 1 for the document. Only dividing by length separates them, which
        // is the whole reason for using cosine: a long document and a short note about
        // the same thing must rank together, and a long one about something else must
        // not win on size alone.
        $this->remember('short note', [1.0, 0.0]);
        $this->remember('long document about something else', [1.0, 50.0]);

        $this->providerReturns([1.0, 0.0]);

        $this->assertSame('short note', Memory::search($this->chat(), 'anything', 2)->first()->content);
    }

    public function test_a_memory_written_before_embeddings_were_on_still_turns_up(): void
    {
        $this->remember('with a vector', [1.0, 0.0]);
        $this->remember('from before', null);

        $this->providerReturns([1.0, 0.0]);

        $found = Memory::search($this->chat(), 'anything', 5);

        // Last, not gone. Right after switching embeddings on, most rows have no vector,
        // and dropping them would empty the memory rather than rank it.
        $this->assertSame('with a vector', $found->first()->content);
        $this->assertSame('from before', $found->last()->content);
    }

    public function test_a_provider_that_is_down_falls_back_to_recency(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'boom'], 500)]);

        $this->remember('older', [1.0, 0.0]);
        $newest = $this->remember('newest', [0.0, 1.0]);

        $found = Memory::search($this->chat(), 'anything', 5);

        // Degrading to what this did before embeddings existed beats returning nothing,
        // and the caller cannot tell except in quality.
        $this->assertSame($newest->id, $found->first()->id);
    }

    public function test_with_embeddings_off_nothing_is_sent_and_recency_answers(): void
    {
        config()->set('laragents.memory.embeddings', false);
        Http::fake();

        $this->remember('older', null);
        $newest = $this->remember('newest', null);

        $this->assertSame($newest->id, Memory::search($this->chat(), 'anything', 5)->first()->id);

        Http::assertNothingSent();
    }

    public function test_the_distiller_stores_the_vector_it_was_given(): void
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.25, 0.5]]],
                'usage' => ['prompt_tokens' => 8],
            ], 200),
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'everyone' => [['memorable' => 'firm', 'content' => 'The bank replies slowly']],
                    'actor' => [],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
            ], 200),
        ]);

        config()->set('laragents.memory.min_messages', 1);

        $session = $this->chat();
        \EduLazaro\Laragents\Models\ChatMessage::create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => 'the bank still has not replied',
        ]);

        $this->app->make(\EduLazaro\Laragents\MemoryDistiller::class)->distil($session);

        $this->assertSame([0.25, 0.5], Memory::sole()->embedding);
    }

    public function test_an_embedding_that_fails_still_leaves_the_memory(): void
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response(['error' => 'boom'], 500),
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'everyone' => [['memorable' => 'firm', 'content' => 'The bank replies slowly']],
                    'actor' => [],
                ])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
            ], 200),
        ]);

        config()->set('laragents.memory.min_messages', 1);

        $session = $this->chat();
        \EduLazaro\Laragents\Models\ChatMessage::create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => 'the bank still has not replied',
        ]);

        $this->app->make(\EduLazaro\Laragents\MemoryDistiller::class)->distil($session);

        // Losing the memory over a rate limit would be the worse trade. It is written
        // without a vector and simply sorts last.
        $this->assertSame('The bank replies slowly', Memory::sole()->content);
        $this->assertNull(Memory::sole()->embedding);
    }
}
