<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\Clients\AnthropicClient;
use EduLazaro\Laragents\Clients\OpenAiClient;
use EduLazaro\Laragents\Contracts\ChatClient;
use EduLazaro\Laragents\Laragents;
use EduLazaro\Laragents\Testing\FakeChatClient;
use EduLazaro\Laragents\Tests\TestCase;

class FakeClientAndProvidersTest extends TestCase
{
    public function test_the_fake_answers_what_was_queued_and_keeps_the_call(): void
    {
        $chat = FakeChatClient::bind()
            ->reply('first')
            ->reply(['category' => 'invoice']);

        $client = app(ChatClient::class);

        $this->assertSame($chat, $client, 'binding itself is what lets the app under test find it');

        $one = $client->chat(messages: [['role' => 'user', 'content' => 'hello']], model: 'claude-opus-5-5');
        $two = $client->chat(messages: [['role' => 'user', 'content' => 'again']], model: 'gpt-5', operation: 'classify');

        $this->assertSame('first', $one->content);
        $this->assertSame('{"category":"invoice"}', $two->content, 'an array is encoded, which is what a schema-backed call reads');

        $this->assertSame(2, $chat->callCount());
        $this->assertSame('gpt-5', $chat->lastCall()['model']);
        $this->assertSame('classify', $chat->lastCall()['operation']);
        $this->assertSame('again', $chat->lastPrompt());
    }

    public function test_past_the_queue_it_falls_back_instead_of_breaking(): void
    {
        $chat = FakeChatClient::bind()->always('whatever you ask');

        $this->assertSame('whatever you ask', app(ChatClient::class)->chat(messages: [], model: 'gpt-5')->content);
        $this->assertSame('whatever you ask', app(ChatClient::class)->chat(messages: [], model: 'gpt-5')->content);
    }

    public function test_the_fake_refuses_a_schema_the_provider_would_reject(): void
    {
        FakeChatClient::bind();

        $this->expectExceptionMessageMatches('/additionalProperties/');

        app(ChatClient::class)->chat(
            messages: [],
            model: 'gpt-5',
            schema: ['name' => 'r', 'schema' => [
                'type' => 'object',
                'properties' => ['a' => ['type' => 'string']],
                'required' => ['a'],
            ]],
        );
    }

    public function test_a_provider_can_be_asked_for_by_name(): void
    {
        $this->assertInstanceOf(OpenAiClient::class, Laragents::chat('openai'));
        $this->assertInstanceOf(AnthropicClient::class, Laragents::chat('anthropic'));
        $this->assertInstanceOf(AnthropicClient::class, Laragents::chat('Anthropic'));
    }

    public function test_an_unknown_provider_says_which_ones_there_are(): void
    {
        $this->expectExceptionMessageMatches('/Unknown provider \[gemini\].*openai, anthropic/');

        Laragents::chat('gemini');
    }

    public function test_a_key_is_what_decides_whether_a_provider_can_be_used(): void
    {
        $this->assertTrue(Laragents::hasKey('openai'));
        $this->assertTrue(Laragents::hasKey('anthropic'));
        $this->assertFalse(Laragents::hasKey('gemini'));
        $this->assertSame(['openai', 'anthropic'], Laragents::available());

        config(['laragents.anthropic.api_key' => '']);

        $this->assertFalse(Laragents::hasKey('anthropic'));
        $this->assertSame(['openai'], Laragents::available());
    }
}
