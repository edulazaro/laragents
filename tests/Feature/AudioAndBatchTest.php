<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\Clients\OpenAiEmbedClient;
use EduLazaro\Laragents\Clients\OpenAiTranscribeClient;
use EduLazaro\Laragents\Contracts\Transcriber;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/**
 * The two ways of talking to a provider that are not a conversation: many texts at
 * once, and recorded speech.
 *
 * Neither is used by anything in this package. They are here because an app that
 * indexes documents or records meetings would otherwise write the plumbing again, and
 * the app this came from had written it twice.
 */
class AudioAndBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laragents.openai.api_key', 'test-key');
    }

    public function test_many_texts_go_in_one_request(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'data' => [
                ['index' => 0, 'embedding' => [0.1, 0.2]],
                ['index' => 1, 'embedding' => [0.3, 0.4]],
            ],
            'usage' => ['prompt_tokens' => 12],
        ], 200)]);

        $vectors = app(OpenAiEmbedClient::class)->embedBatch(['uno', 'dos']);

        $this->assertSame([[0.1, 0.2], [0.3, 0.4]], $vectors);
        Http::assertSentCount(1);
    }

    public function test_the_vectors_come_back_in_the_order_the_texts_went_in(): void
    {
        // The provider answers with an index and is under no obligation to answer in
        // order. Trusting the order of `data` misfiles every vector after the first
        // one that arrives late, and nothing about the result looks wrong.
        Http::fake(['api.openai.com/*' => Http::response([
            'data' => [
                ['index' => 2, 'embedding' => [3.0]],
                ['index' => 0, 'embedding' => [1.0]],
                ['index' => 1, 'embedding' => [2.0]],
            ],
            'usage' => ['prompt_tokens' => 9],
        ], 200)]);

        $vectors = app(OpenAiEmbedClient::class)->embedBatch(['a', 'b', 'c']);

        $this->assertSame([[1.0], [2.0], [3.0]], $vectors);
    }

    public function test_nothing_to_embed_costs_no_request(): void
    {
        Http::fake();

        $this->assertSame([], app(OpenAiEmbedClient::class)->embedBatch([]));
        Http::assertNothingSent();
    }

    public function test_speech_comes_back_as_text(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'text' => 'Buenos días, la reunión empieza.',
            'duration' => 120.0,
        ], 200)]);

        $file = tempnam(sys_get_temp_dir(), 'aud');
        file_put_contents($file, 'not really audio');

        $response = app(Transcriber::class)->transcribe($file, language: 'es');

        unlink($file);

        $this->assertSame('Buenos días, la reunión empieza.', $response->content);
        $this->assertSame('whisper-1', $response->model);

        // Two minutes, metered as two thousand tokens: audio has none of its own, so a
        // minute is worth a configured number and the rate table prices it like any
        // other model. Without this there is nothing to charge an account for.
        $this->assertSame(2000, $response->inputTokens);
    }

    public function test_a_missing_file_says_so_before_asking_the_provider(): void
    {
        Http::fake();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not readable');

        app(OpenAiTranscribeClient::class)->transcribe('/no/such/recording.mp3');
    }
}
