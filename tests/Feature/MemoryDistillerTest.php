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
 * What fills the memories table. Without it the loop pastes memories into every prompt
 * and nothing ever puts one there.
 *
 * No real calls: Http::fake stands in for the provider throughout.
 */
class MemoryDistillerTest extends TestCase
{
    private Owner $org;
    private Owner $case;
    private Owner $maria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Owner::create();
        $this->case = Owner::create();
        $this->maria = Owner::create();

        config()->set('laragents.memory.min_messages', 2);
    }

    private function chat(?Owner $actor = null, ?Owner $subject = null): ChatSession
    {
        $session = ChatSession::create([
            'tenant_type' => 'firm',
            'tenant_id' => $this->org->id,
            'actor_type' => $actor ? 'firm' : null,
            'actor_id' => $actor?->id,
            'sessionable_type' => $subject ? 'firm' : null,
            'sessionable_id' => $subject?->id,
        ]);

        foreach ([['user', 'the bank still has not replied'], ['assistant', 'noted, I will chase it']] as [$role, $content]) {
            ChatMessage::create([
                'session_id' => $session->id,
                'role' => $role,
                'content' => $content,
            ]);
        }

        return $session;
    }

    private function answers(array $payload): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($payload)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
        ], 200)]);
    }

    private function distiller(): MemoryDistiller
    {
        return $this->app->make(MemoryDistiller::class);
    }

    public function test_it_writes_what_the_model_kept(): void
    {
        $this->answers([
            'everyone' => [['memorable' => 'firm', 'content' => 'The bank replies slowly', 'category' => 'pattern']],
            'actor' => [['memorable' => 'firm', 'content' => 'I keep meaning to chase the bank']],
        ]);

        $session = $this->chat($this->maria);

        $this->assertSame(2, $this->distiller()->distil($session));

        $shared = Memory::whereNull('memory_id')->first();
        $personal = Memory::whereNotNull('memory_id')->first();

        $this->assertSame('The bank replies slowly', $shared->content);
        $this->assertSame('pattern', $shared->category);
        $this->assertSame(Memory::SOURCE_DISTILLED, $shared->source);
        $this->assertSame($this->maria->id, $personal->memory_id);
    }

    public function test_what_the_model_calls_the_firm_is_written_as_the_tenant(): void
    {
        // The model answers with a morph alias because that is the vocabulary it was
        // given, and "firm" happens to be the tenant's. What comes back as a subject has
        // to be stored as the tenant, or the row would be about the firm rather than the
        // firm's.
        $this->answers([
            'everyone' => [['memorable' => 'firm', 'content' => 'The bank replies slowly']],
            'actor' => [],
        ]);

        $this->distiller()->distil($this->chat($this->maria));

        $memory = Memory::first();

        $this->assertSame($this->org->id, $memory->tenant_id);
        $this->assertNull($memory->memorable_id);
    }

    public function test_a_personal_memory_is_dropped_when_there_is_nobody_to_attach_it_to(): void
    {
        $this->answers([
            'everyone' => [['memorable' => 'firm', 'content' => 'The bank replies slowly']],
            'actor' => [['memorable' => 'firm', 'content' => 'I am worried about this']],
        ]);

        // An autonomous run. Attaching a personal memory to the scope instead would make
        // it apply to everybody, which is how one person's worry becomes the firm's.
        $this->assertSame(1, $this->distiller()->distil($this->chat(actor: null)));
        $this->assertSame(0, Memory::whereNotNull('memory_id')->count());
    }

    public function test_a_scope_the_session_does_not_have_is_ignored(): void
    {
        $this->answers([
            'everyone' => [
                ['memorable' => 'matter', 'content' => 'Only true of this matter'],
                ['memorable' => 'firm', 'content' => 'True of the firm'],
            ],
            'actor' => [],
        ]);

        // No subject on this session, so a memory scoped to one has nowhere to hang.
        $this->assertSame(1, $this->distiller()->distil($this->chat($this->maria)));
        $this->assertSame('True of the firm', Memory::first()->content);
    }

    public function test_it_does_not_write_the_same_memory_twice(): void
    {
        $this->answers([
            'everyone' => [['memorable' => 'firm', 'content' => 'The bank replies slowly']],
            'actor' => [],
        ]);

        $session = $this->chat($this->maria);
        $this->distiller()->distil($session);

        // A second window that produces the same conclusion.
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => 'still nothing']);
        ChatMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => 'still chasing']);

        $this->assertSame(0, $this->distiller()->distil($session));
        $this->assertSame(1, Memory::count());
    }

    public function test_it_never_reads_the_same_window_twice(): void
    {
        $this->answers(['everyone' => [], 'actor' => []]);

        $session = $this->chat($this->maria);
        $this->distiller()->distil($session);

        Http::assertSentCount(1);

        // Nothing new since. Calling again must not pay for another call.
        $this->distiller()->distil($session);

        Http::assertSentCount(1);
        $this->assertNotNull($session->fresh()->distilled_until_id);
    }

    public function test_a_short_exchange_is_not_worth_a_model_call(): void
    {
        config()->set('laragents.memory.min_messages', 10);
        $this->answers(['everyone' => [], 'actor' => []]);

        $this->assertSame(0, $this->distiller()->distil($this->chat($this->maria)));

        Http::assertNothingSent();
    }

    public function test_a_conversation_that_is_over_is_distilled_anyway(): void
    {
        config()->set('laragents.memory.min_messages', 10);
        $this->answers([
            'everyone' => [['memorable' => 'firm', 'content' => 'The bank replies slowly']],
            'actor' => [],
        ]);

        // The cron that wraps up abandoned conversations passes 1. There will be no
        // later chance for this one.
        $this->assertSame(1, $this->distiller()->distil($this->chat($this->maria), minMessages: 1));
    }

    public function test_a_reply_wrapped_in_fences_and_chatter_is_still_read(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => "Sure! Here you go:\n```json\n"
                . '{"everyone":[{"memorable":"firm","content":"The bank replies slowly"}],"actor":[]}'
                . "\n```"], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ], 200)]);

        $this->assertSame(1, $this->distiller()->distil($this->chat($this->maria)));
    }

    public function test_a_reply_that_is_not_json_costs_the_window_and_not_the_session(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'I could not do that'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ], 200)]);

        $session = $this->chat($this->maria);

        $this->assertSame(0, $this->distiller()->distil($session));

        // Sealed regardless. Retrying a window that just failed usually fails again, and
        // every attempt is paid for.
        $this->assertNotNull($session->fresh()->last_distilled_at);
    }

    public function test_a_provider_error_seals_the_window_instead_of_retrying_it_for_ever(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'boom'], 500)]);

        $session = $this->chat($this->maria);

        $this->assertSame(0, $this->distiller()->distil($session));
        $this->assertNotNull($session->fresh()->distilled_until_id);
    }

    public function test_a_session_with_no_scope_is_sealed_without_a_call(): void
    {
        $this->answers(['everyone' => [], 'actor' => []]);

        $orphan = ChatSession::create(['title' => 'nobody owns this']);
        ChatMessage::create(['session_id' => $orphan->id, 'role' => 'user', 'content' => 'hello']);
        ChatMessage::create(['session_id' => $orphan->id, 'role' => 'assistant', 'content' => 'hi']);

        $this->assertSame(0, $this->distiller()->distil($orphan));

        Http::assertNothingSent();
        $this->assertNotNull($orphan->fresh()->last_distilled_at);
    }
}
