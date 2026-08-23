<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\HistoryCompressor;
use EduLazaro\Laragents\Jobs\DistilMemories;
use EduLazaro\Laragents\Models\ChatMessage;
use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * When memories actually get written.
 *
 * The distiller existed and nothing called it, which meant the memories table was read
 * into every prompt and never filled. Two paths close that, and the second exists because
 * most conversations never reach the first.
 */
class DistilTriggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'a summary of the older turns'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
        ], 200)]);
    }

    private function chat(): ChatSession
    {
        return ChatSession::create([
            'owner_type' => Owner::class,
            'owner_id' => Owner::create()->id,
        ]);
    }

    private function say(ChatSession $session, string $content): void
    {
        ChatMessage::create(['session_id' => $session->id, 'role' => 'user', 'content' => $content]);
    }

    public function test_crossing_the_budget_compresses_and_distils(): void
    {
        config()->set('laragents.chat.max_history_tokens', 50);
        config()->set('laragents.chat.recent_messages_min', 2);

        $session = $this->chat();

        foreach (range(1, 8) as $i) {
            $this->say($session, str_repeat("a lot has been said here {$i} ", 10));
        }

        $this->app->make(HistoryCompressor::class)->buildHistory($session, 'You are helpful.');

        // Crossing the budget means a substantial amount was said, which is exactly when
        // there is something worth keeping.
        $this->assertNotNull($session->fresh()->compressed_summary);
        Queue::assertPushed(DistilMemories::class);
    }

    public function test_a_conversation_under_the_budget_distils_nothing_yet(): void
    {
        config()->set('laragents.chat.max_history_tokens', 100000);

        $session = $this->chat();
        $this->say($session, 'hello');

        $this->app->make(HistoryCompressor::class)->buildHistory($session, 'You are helpful.');

        Queue::assertNothingPushed();
    }

    public function test_a_conversation_that_stopped_early_is_still_wrapped_up(): void
    {
        Carbon::setTestNow('2026-03-02 09:00:00');

        $session = $this->chat();
        $this->say($session, 'three useful exchanges and then silence');
        $session->touch();

        Carbon::setTestNow('2026-03-02 10:00:00');

        $this->artisan('laragents:distil-inactive-sessions')->assertSuccessful();

        // Most conversations never grow big enough to be summarised. Without this the
        // package would remember long chats and forget short ones.
        Queue::assertPushed(DistilMemories::class, fn ($job) => $job->minMessages === 1);
    }

    public function test_a_conversation_still_going_is_left_alone(): void
    {
        Carbon::setTestNow('2026-03-02 09:00:00');

        $session = $this->chat();
        $this->say($session, 'still typing');
        $session->touch();

        Carbon::setTestNow('2026-03-02 09:05:00');

        $this->artisan('laragents:distil-inactive-sessions')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_a_session_already_dealt_with_is_not_paid_for_twice(): void
    {
        Carbon::setTestNow('2026-03-02 09:00:00');

        $session = $this->chat();
        $this->say($session, 'finished');
        $session->forceFill(['updated_at' => now(), 'last_distilled_at' => now()])->save();

        Carbon::setTestNow('2026-03-02 12:00:00');

        $this->artisan('laragents:distil-inactive-sessions')->assertSuccessful();

        // Nothing said since the last pass. Picking it up every half hour for ever would
        // pay for a call each time to decide again that there is nothing new.
        Queue::assertNothingPushed();
    }
}
