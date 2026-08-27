<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\Clients\AnthropicClient;
use EduLazaro\Laragents\Clients\OpenAiClient;
use EduLazaro\Laragents\Clients\OpenAiImageClient;
use EduLazaro\Laragents\Clients\OpenAiSpeakClient;
use EduLazaro\Laragents\Capabilities\FileSearch;
use EduLazaro\Laragents\Capabilities\Translator;
use EduLazaro\Laragents\Capabilities\WebSearch;
use EduLazaro\Laragents\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * What a provider can do on its own side: search the web, show its working, say where it
 * got something, speak, draw.
 *
 * None of it is a tool of yours. Yours are classes the loop runs against your database;
 * these the model reaches for itself and you never see the call.
 */
class ProviderCapabilitiesTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laragents.openai.api_key', 'test-key');
        $app['config']->set('laragents.anthropic.api_key', 'test-key');
    }

    public function test_web_search_is_asked_for_in_each_providers_own_words(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']], 'usage' => []], 200),
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']], 'usage' => [], 'stop_reason' => 'end_turn'], 200),
        ]);

        app(OpenAiClient::class)->chat([], 'gpt-4o', capabilities: [new WebSearch()]);
        app(AnthropicClient::class)->chat([], 'claude-sonnet-4-6', capabilities: [new WebSearch()]);

        [$openai, $anthropic] = array_map(fn ($pair) => $pair[0]->data(), Http::recorded()->all());

        $this->assertSame('web_search', $openai['tools'][0]['type']);

        // Anthropic versions its server tools by date, so the same name has to become a
        // different string. That is the whole reason this translation exists.
        $this->assertSame('web_search_20250305', $anthropic['tools'][0]['type']);
    }

    public function test_asking_anthropic_for_file_search_drops_it_rather_than_faking_it(): void
    {
        // It has no equivalent. Sending something invented would fail at the provider,
        // and pretending it worked is worse than doing without.
        $this->assertSame([], Translator::forAnthropic([new FileSearch()]));
        $this->assertNotSame([], Translator::forOpenAi([new FileSearch()]));
    }

    public function test_thinking_comes_back_apart_from_the_answer(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'thinking', 'thinking' => 'Primero miro el plazo.'],
                ['type' => 'text', 'text' => 'Prescribe en cinco años.'],
            ],
            'usage' => [],
            'stop_reason' => 'end_turn',
        ], 200)]);

        $response = app(AnthropicClient::class)->chat([], 'claude-sonnet-4-6');

        // Apart, because it is not the answer: shown as if it were, it reads as the
        // model talking to itself.
        $this->assertSame('Prescribe en cinco años.', $response->content);
        $this->assertSame('Primero miro el plazo.', $response->reasoning);
        $this->assertTrue($response->hasReasoning());
    }

    public function test_redacted_thinking_leaves_no_trace_but_was_still_billed(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'redacted_thinking', 'data' => 'cifrado'],
                ['type' => 'text', 'text' => 'La respuesta.'],
            ],
            'usage' => ['output_tokens' => 900],
            'stop_reason' => 'end_turn',
        ], 200)]);

        $response = app(AnthropicClient::class)->chat([], 'claude-sonnet-4-6');

        // Empty reasoning does not mean it did not think. It means it did not say, and
        // the tokens are in the bill either way.
        $this->assertFalse($response->hasReasoning());
        $this->assertSame(900, $response->outputTokens);
    }

    public function test_citations_come_back_when_it_searched(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => [
                'content' => 'Según el BOE...',
                'annotations' => [[
                    'type' => 'url_citation',
                    'url_citation' => ['title' => 'BOE-A-2015-1', 'url' => 'https://boe.es/x'],
                ]],
            ], 'finish_reason' => 'stop']],
            'usage' => [],
        ], 200)]);

        $response = app(OpenAiClient::class)->chat([], 'gpt-4o', capabilities: [new WebSearch()]);

        $this->assertTrue($response->hasCitations());
        $this->assertSame('https://boe.es/x', $response->citations[0]['url']);
    }

    public function test_speech_comes_back_as_bytes_and_is_written_nowhere(): void
    {
        Http::fake(['api.openai.com/*' => Http::response('AUDIO-BYTES', 200)]);

        // Where it is stored, under what name and for how long is the app's decision,
        // and a package that picked a disk would be wrong for most of them.
        $this->assertSame('AUDIO-BYTES', app(OpenAiSpeakClient::class)->speak('Hola'));
    }

    public function test_images_come_back_decoded(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'data' => [['b64_json' => base64_encode('PNG-BYTES')]],
        ], 200)]);

        // Decoded here, because every caller would decode it and one would forget and
        // store the base64 text as if it were an image.
        $this->assertSame(['PNG-BYTES'], app(OpenAiImageClient::class)->draw('un gato'));
    }

    public function test_the_loop_can_switch_one_on(): void
    {
        // The gap this closes: the clients understood capabilities and the loop had no
        // way to pass any, so the whole thing was unreachable from run().
        \Illuminate\Support\Facades\Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => [],
        ], 200)]);

        $session = \EduLazaro\Laragents\Models\ChatSession::create([
            'tenant_type' => 'firm',
            'tenant_id' => \EduLazaro\Laragents\Tests\Fixtures\Owner::create()->id,
        ]);

        (new \EduLazaro\Laragents\AgentLoop(
            app(\EduLazaro\Laragents\Clients\OpenAiClient::class),
            new \EduLazaro\Laragents\Tools\ToolRegistry(),
        ))->run([], 'gpt-4o', $session, \EduLazaro\Laragents\Tools\ToolContext::make(), [], true, [new WebSearch()]);

        $this->assertSame('web_search', Http::recorded()[0][0]->data()['tools'][0]['type']);
    }
}
