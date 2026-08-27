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
}
