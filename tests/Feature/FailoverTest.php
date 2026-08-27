<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\Clients\AnthropicClient;
use EduLazaro\Laragents\Clients\FailoverClient;
use EduLazaro\Laragents\Clients\OpenAiClient;
use EduLazaro\Laragents\Contracts\ChatClient;
use EduLazaro\Laragents\Exceptions\PlanCreditsExhausted;
use EduLazaro\Laragents\Exceptions\ProviderRateLimited;
use EduLazaro\Laragents\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * Going to the other provider when the first one says "not now".
 *
 * A 429 is temporary and not your fault, and the person waiting for the reply does not
 * care whose fault it is. With two providers already speaking one contract, the second
 * is idle while the first refuses.
 */
class FailoverTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laragents.openai.api_key', 'test-key');
        $app['config']->set('laragents.anthropic.api_key', 'test-key');
        $app['config']->set('laragents.failover', [
            ['client' => AnthropicClient::class, 'model' => 'claude-sonnet-4-6'],
        ]);
    }

    private function client(): ChatClient
    {
        return $this->app->make(ChatClient::class);
    }

    public function test_a_rate_limit_goes_to_the_next_provider(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([], 429),
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'contesto yo']],
                'usage' => [],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $response = $this->client()->chat([], 'gpt-4o');

        $this->assertSame('contesto yo', $response->content);
        $this->assertSame('claude-sonnet-4-6', $response->model, 'and the reply says who answered');
    }

    public function test_anything_that_is_not_a_rate_limit_stops_there(): void
    {
        // No key, a malformed request, a refusal: none of those go better elsewhere,
        // and retrying them everywhere turns one failure into as many as you have
        // providers, each one billed.
        $failing = new class implements ChatClient {
            public function chat(
                array $messages, string $model, $owner = null, $actor = null,
                string $operation = 'chat', $subject = null, ?float $temperature = null,
                ?int $maxTokens = null, ?array $tools = null, ?string $toolChoice = null,
                ?array $schema = null, array $capabilities = [],
            ): \EduLazaro\Laragents\Response {
                throw new PlanCreditsExhausted(1, 'chat');
            }
        };

        Http::fake();

        $client = new FailoverClient($failing, [
            ['client' => app(AnthropicClient::class), 'model' => 'claude-sonnet-4-6'],
        ]);

        $this->expectException(PlanCreditsExhausted::class);

        $client->chat([], 'gpt-4o');

        Http::assertNothingSent();
    }

    public function test_everyone_rate_limiting_raises_the_last_one(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([], 429),
            'api.anthropic.com/*' => Http::response([], 429),
        ]);

        $this->expectException(ProviderRateLimited::class);

        $this->client()->chat([], 'gpt-4o');
    }

    public function test_with_no_fallbacks_configured_the_client_is_the_plain_one(): void
    {
        config()->set('laragents.failover', []);
        $this->app->forgetInstance(ChatClient::class);

        // Not wrapped: failing over means answering with a model the caller did not
        // choose, so it stays off until someone says otherwise.
        $this->assertInstanceOf(OpenAiClient::class, $this->app->make(ChatClient::class));
    }
}
