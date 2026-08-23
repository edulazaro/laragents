<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Larameter\LarameterServiceProvider;
use EduLazaro\Larameter\Models\UsageRecord;
use EduLazaro\Laragents\Clients\OpenAiClient;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use EduLazaro\Laragents\Exceptions\PlanCreditsExhausted;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * The whole chain: the loop counts tokens, larameter turns them into credits against
 * your rates, and the next call is refused once the plan runs out.
 */
class LarameterIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LarameterServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('laragents.usage_recorder', 'larameter');
        $app['config']->set('larameter.windows', ['week' => ['days' => 7, 'anchor' => 'fixed']]);
        $app['config']->set('larameter.plans', ['free' => ['credits' => ['week' => 1000]]]);
        $app['config']->set('larameter.default_plan', 'free');
        $app['config']->set('larameter.rates', [
            'gpt-4o' => ['in' => 2.50, 'out' => 10.00, 'per' => 1_000_000],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $migration = require __DIR__ . '/../../vendor/edulazaro/larameter/database/migrations/create_larameter_tables.php.stub';
        $migration->up();
    }

    private function fakeAnswer(int $in = 100_000, int $out = 20_000): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out],
        ], 200)]);
    }

    public function test_pointing_the_option_at_larameter_is_the_whole_setup(): void
    {
        $this->assertInstanceOf(
            \EduLazaro\Laragents\Usage\LarameterRecorder::class,
            $this->app->make(UsageRecorder::class),
        );
    }

    public function test_tokens_become_credits_at_your_rates(): void
    {
        $this->fakeAnswer(in: 1_000_000, out: 1_000_000);
        $org = Owner::create();

        $this->app->make(OpenAiClient::class)->chat(
            messages: [['role' => 'user', 'content' => 'x']],
            model: 'gpt-4o',
            owner: $org,
            operation: 'chat',
        );

        $record = UsageRecord::first();

        $this->assertNotNull($record, 'the call was metered');
        $this->assertSame('gpt-4o', $record->operation, 'the model is the priced operation');
        $this->assertSame('token', $record->unit);
        $this->assertSame(1_000_000, $record->quantity_in);
        // $2.50 + $10.00 = $12.50 at 10000 credits per unit of cost.
        $this->assertSame('12.500000', $record->cost);
        $this->assertSame(125000, $record->credits);
        $this->assertSame('chat', $record->metadata['operation'], 'the feature, beside the model');
    }

    public function test_once_the_plan_runs_out_the_next_call_never_leaves(): void
    {
        // The point of the whole chain: not "we noticed afterwards" but "it did not spend".
        $org = Owner::create();
        $this->fakeAnswer(in: 1_000_000, out: 1_000_000);

        // First call: allowed, and it blows straight through the 1000 credit allowance.
        $this->app->make(OpenAiClient::class)->chat(
            messages: [['role' => 'user', 'content' => 'x']],
            model: 'gpt-4o',
            owner: $org,
        );

        Http::assertSentCount(1);

        // A new request or job, which is what flushes the memo. Within one turn it
        // deliberately does not notice, so that a turn in flight finishes.
        $this->app->forgetScopedInstances();

        $this->expectException(PlanCreditsExhausted::class);

        $this->app->make(OpenAiClient::class)->chat(
            messages: [['role' => 'user', 'content' => 'x']],
            model: 'gpt-4o',
            owner: $org,
        );
    }

    public function test_platform_cost_is_metered_to_nobody(): void
    {
        // Indexing a corpus has no customer to charge, so no owner and no record.
        $this->fakeAnswer();

        $this->app->make(OpenAiClient::class)->chat(
            messages: [['role' => 'user', 'content' => 'x']],
            model: 'gpt-4o',
            owner: null,
        );

        $this->assertSame(0, UsageRecord::count());
        Http::assertSentCount(1);
    }
}
