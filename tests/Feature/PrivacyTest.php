<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\AgentLoop;
use EduLazaro\Laragents\Clients\OpenAiClient;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use EduLazaro\Laragents\Models\ChatMessage;
use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Contracts\Redactor as RedactorContract;
use EduLazaro\Laragents\Tests\TestCase;
use EduLazaro\Laragents\Tools\ToolContext;
use EduLazaro\Laragents\Tools\ToolRegistry;
use Illuminate\Support\Facades\Http;

class PrivacyTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            \EduLazaro\Laranon\LaranonServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }

    private function newSession(array $attributes = []): ChatSession
    {
        $owner = Owner::create();

        return ChatSession::create([
            'tenant_type' => 'firm',
            'tenant_id' => $owner->id,
            ...$attributes,
        ]);
    }

    private function loop(): AgentLoop
    {
        return new AgentLoop(
            new OpenAiClient($this->app->make(UsageRecorder::class)),
            new ToolRegistry(),
        );
    }

    private function fakeAnswer(string $content = 'ok'): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'usage' => [],
        ], 200)]);
    }

    /** The body actually sent to the provider. */
    private function sentBody(): array
    {
        $recorded = Http::recorded();

        return json_decode($recorded[0][0]->body(), true);
    }

    public function test_off_by_default_the_prompt_goes_out_untouched(): void
    {
        $this->fakeAnswer();

        $this->loop()->run(
            messages: [['role' => 'user', 'content' => 'Escribe a Juan Pérez, DNI 12345678Z.']],
            model: 'gpt-4o-mini',
            session: $this->newSession(),
            context: ToolContext::make(),
        );

        $sent = $this->sentBody()['messages'][0]['content'];
        $this->assertStringContainsString('12345678Z', $sent);
    }

    public function test_when_enabled_the_id_never_reaches_the_provider(): void
    {
        config(['laragents.privacy.enabled' => true]);
        $this->fakeAnswer();

        $this->loop()->run(
            messages: [['role' => 'user', 'content' => 'Escribe a Juan Pérez, DNI 12345678Z.']],
            model: 'gpt-4o-mini',
            session: $this->newSession(),
            context: ToolContext::make(),
        );

        $sent = $this->sentBody()['messages'][0]['content'];
        $this->assertStringNotContainsString('12345678Z', $sent, 'the ID left the building');
    }

    public function test_the_first_name_survives_because_it_carries_the_gender(): void
    {
        // Strip it and the model writes "el paciente ha sido informada".
        config(['laragents.privacy.enabled' => true]);
        $this->fakeAnswer();

        $this->loop()->run(
            messages: [['role' => 'user', 'content' => 'Escribe a Juan Pérez, DNI 12345678Z.']],
            model: 'gpt-4o-mini',
            session: $this->newSession(),
            context: ToolContext::make(),
        );

        $this->assertStringContainsString('Juan', $this->sentBody()['messages'][0]['content']);
    }

    public function test_a_session_can_opt_out_on_its_own(): void
    {
        config(['laragents.privacy.enabled' => true]);
        $this->fakeAnswer();

        $this->loop()->run(
            messages: [['role' => 'user', 'content' => 'DNI 12345678Z.']],
            model: 'gpt-4o-mini',
            session: $this->newSession(['anonymize_pii' => false]),
            context: ToolContext::make(),
        );

        $this->assertStringContainsString('12345678Z', $this->sentBody()['messages'][0]['content']);
    }

    public function test_nothing_anonymised_is_ever_persisted(): void
    {
        // The stored conversation is the source of truth and what a human reads later.
        config(['laragents.privacy.enabled' => true]);
        $this->fakeAnswer('Enviado a Juan Pérez, DNI 12345678Z.');

        $session = $this->newSession();

        $response = $this->loop()->run(
            messages: [['role' => 'user', 'content' => 'Escribe a Juan Pérez.']],
            model: 'gpt-4o-mini',
            session: $session,
            context: ToolContext::make(),
        );

        $this->assertStringContainsString('12345678Z', $response->content);
        $this->assertStringContainsString(
            '12345678Z',
            ChatMessage::where('session_id', $session->id)->where('role', 'assistant')->first()->content,
        );
    }

    public function test_an_app_can_bring_its_own_redactor(): void
    {
        // Anonymising is policy, not plumbing. A clinic cares about patient numbers, a
        // payroll app about bank accounts and not names at all. Binding the contract is
        // how an app says so, and laranon never gets a look in.
        config()->set('laragents.privacy.enabled', true);

        app()->bind(RedactorContract::class, fn () => new class implements RedactorContract {
            public function beginTurn(): void {}

            public function outbound(array $messages): array
            {
                return array_map(fn ($m) => [...$m, 'content' => str_replace('secreto', '[X]', $m['content'] ?? '')], $messages);
            }

            public function inbound(string $text): string
            {
                return str_replace('[X]', 'secreto', $text);
            }

            public function inboundToolCalls(array $toolCalls): array
            {
                return $toolCalls;
            }
        });

        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'dijiste [X]'], 'finish_reason' => 'stop']],
            'usage' => [],
        ], 200)]);

        $session = ChatSession::create(['tenant_type' => 'firm', 'tenant_id' => Owner::create()->id]);

        $response = (new AgentLoop(
            new OpenAiClient($this->app->make(UsageRecorder::class)),
            new ToolRegistry(),
        ))->run([['role' => 'user', 'content' => 'esto es secreto']], 'gpt-4o', $session, ToolContext::make(), []);

        $sent = Http::recorded()[0][0]->data();

        $this->assertSame('esto es [X]', $sent['messages'][0]['content'], 'it left redacted');
        $this->assertSame('dijiste secreto', $response->content, 'and came back restored');
    }

    public function test_compressing_a_private_conversation_does_not_hand_it_over_whole(): void
    {
        // The leak this covers is worse than never redacting: the turns go out tokenised
        // and then the whole transcript follows the moment the history outgrows its
        // budget, out of a conversation the user marked private.
        config([
            'laragents.privacy.enabled' => true,
            'laragents.chat.max_history_tokens' => 1,
            'laragents.chat.recent_messages_min' => 1,
            'laragents.memory.distil_on_compress' => false,
        ]);

        $this->fakeAnswer('Resumen: el cliente reclama.');

        $session = $this->newSession();

        foreach (['Mi DNI es 12345678Z.', 'Anotado.', 'Gracias.'] as $i => $content) {
            ChatMessage::create([
                'session_id' => $session->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => $content,
            ]);
        }

        $this->app->make(\EduLazaro\Laragents\HistoryCompressor::class)
            ->buildHistory($session, 'You are an assistant.');

        $sent = json_encode($this->sentBody(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('12345678Z', $sent, 'the ID left with the transcript');
    }

    public function test_the_stored_summary_is_in_the_clear(): void
    {
        // The mapping is a first-seen counter that dies with the call, so a summary saved
        // with the markers in it could never be read back: next turn's «AP_1» is somebody
        // else and there is nothing left to undo it with.
        config([
            'laragents.privacy.enabled' => true,
            'laragents.chat.max_history_tokens' => 1,
            'laragents.chat.recent_messages_min' => 1,
            'laragents.memory.distil_on_compress' => false,
        ]);

        $this->fakeAnswer('El cliente con DNI «DNI_1» reclama.');

        $session = $this->newSession();

        foreach (['Mi DNI es 12345678Z.', 'Anotado.', 'Gracias.'] as $i => $content) {
            ChatMessage::create([
                'session_id' => $session->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => $content,
            ]);
        }

        $this->app->make(\EduLazaro\Laragents\HistoryCompressor::class)
            ->buildHistory($session, 'You are an assistant.');

        $summary = (string) $session->fresh()->compressed_summary;

        $this->assertStringContainsString('12345678Z', $summary);
        $this->assertStringNotContainsString('DNI_1', $summary);
    }

    public function test_distilling_a_private_conversation_does_not_hand_it_over_whole(): void
    {
        // The widest of the three prompts: the new turns, the summary of everything
        // before them, and every memory already stored, in one call.
        config(['laragents.privacy.enabled' => true, 'laragents.memory.min_messages' => 1]);

        $this->fakeAnswer('{"everyone":[],"actor":[]}');

        $session = $this->newSession();

        ChatMessage::create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => 'Mi DNI es 12345678Z.',
        ]);

        $this->app->make(\EduLazaro\Laragents\MemoryDistiller::class)->distil($session);

        $sent = json_encode($this->sentBody(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('12345678Z', $sent, 'the ID left with the transcript');
    }

    public function test_a_stored_memory_is_in_the_clear(): void
    {
        // Saved with the marker in it, a memory could never be read back: the mapping is
        // a first-seen counter that died with the call that made it.
        config(['laragents.privacy.enabled' => true, 'laragents.memory.min_messages' => 1]);

        $this->fakeAnswer('{"everyone":[{"memorable":"firm","content":"Su DNI es «DNI_1».","category":"fact"}],"actor":[]}');

        $session = $this->newSession();

        ChatMessage::create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => 'Mi DNI es 12345678Z.',
        ]);

        $this->app->make(\EduLazaro\Laragents\MemoryDistiller::class)->distil($session);

        $memory = \EduLazaro\Laragents\Models\Memory::first();

        $this->assertNotNull($memory, 'nothing was written');
        $this->assertStringContainsString('12345678Z', $memory->content);
        $this->assertStringNotContainsString('DNI_1', $memory->content);
    }

    public function test_a_quote_in_a_restored_value_does_not_destroy_the_batch(): void
    {
        // Why the restore happens after json_decode and not before. Nothing laranon
        // detects can contain a quote (surnames, ids, IBANs, phones, emails), so this is
        // unreachable with the shipped redactor. It is reachable with yours: the contract
        // is public, and an app that pins its own entities can tokenise a company name.
        // Put that back while the reply is still a JSON document and the document breaks,
        // taking the whole window's memories with it and reporting nothing.
        config(['laragents.privacy.enabled' => true, 'laragents.memory.min_messages' => 1]);

        $this->app->bind(RedactorContract::class, fn () => new class implements RedactorContract {
            public function beginTurn(): void {}

            public function outbound(array $messages): array
            {
                return $messages;
            }

            public function inbound(string $text): string
            {
                return str_replace('«CO_1»', 'Casa "El Pino" S.L.', $text);
            }

            public function inboundToolCalls(array $toolCalls): array
            {
                return $toolCalls;
            }
        });

        $this->fakeAnswer('{"everyone":[{"memorable":"firm","content":"Trabaja en «CO_1».","category":"fact"}],"actor":[]}');

        $session = $this->newSession();

        ChatMessage::create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => 'Trabajo en Casa "El Pino" S.L.',
        ]);

        $this->app->make(\EduLazaro\Laragents\MemoryDistiller::class)->distil($session);

        $memory = \EduLazaro\Laragents\Models\Memory::first();

        $this->assertNotNull($memory, 'the batch was lost decoding the reply');
        $this->assertSame('Trabaja en Casa "El Pino" S.L..', $memory->content);
    }
}
