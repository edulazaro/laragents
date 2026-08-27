<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\Clients\AnthropicClient;
use EduLazaro\Laragents\Clients\OpenAiClient;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use EduLazaro\Laragents\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * Making the provider guarantee the shape of an answer, instead of asking for it in the
 * prompt and hoping.
 *
 * The distiller is why this exists: it asked for JSON in words and cleaned up the reply
 * with a regex, so one stray quote lost the whole window, silently, having already paid
 * for the call.
 */
class StructuredOutputTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laragents.openai.api_key', 'test-key');
        $app['config']->set('laragents.anthropic.api_key', 'test-key');
    }

    private function schema(): array
    {
        return [
            'name' => 'memories',
            'schema' => [
                'type' => 'object',
                'properties' => ['content' => ['type' => 'string']],
                'required' => ['content'],
                'additionalProperties' => false,
            ],
        ];
    }

    public function test_openai_is_told_the_shape_and_told_to_be_strict(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => '{"content":"algo"}'], 'finish_reason' => 'stop']],
            'usage' => [],
        ], 200)]);

        app(OpenAiClient::class)->chat([], 'gpt-4o', schema: $this->schema());

        $sent = Http::recorded()[0][0]->data();

        $this->assertSame('json_schema', $sent['response_format']['type']);
        $this->assertSame('memories', $sent['response_format']['json_schema']['name']);
        $this->assertTrue($sent['response_format']['json_schema']['strict'], 'strict is what makes it binding');
    }

    public function test_anthropic_gets_there_by_forcing_a_tool(): void
    {
        // It has no response_format. Its documented way is to declare one tool whose
        // input schema IS the shape and leave the model no choice but to call it.
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'memories', 'input' => ['content' => 'algo']]],
            'usage' => [],
            'stop_reason' => 'tool_use',
        ], 200)]);

        $response = app(AnthropicClient::class)->chat([], 'claude-sonnet-4-6', schema: $this->schema());

        $sent = Http::recorded()[0][0]->data();

        $this->assertSame('memories', $sent['tools'][0]['name']);
        $this->assertSame(['type' => 'tool', 'name' => 'memories'], $sent['tool_choice']);

        // And the forced call comes back as content, so a caller decoding a
        // schema-backed reply reads ->content whichever provider served it.
        $this->assertSame('{"content":"algo"}', $response->content);
        $this->assertSame([], $response->toolCalls, 'the answer is not a tool call to run');
    }

    public function test_without_a_schema_nothing_changes(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'hola'], 'finish_reason' => 'stop']],
            'usage' => [],
        ], 200)]);

        app(OpenAiClient::class)->chat([], 'gpt-4o');

        $this->assertArrayNotHasKey('response_format', Http::recorded()[0][0]->data());
    }
}
