<?php

namespace EduLazaro\Laragents;

use EduLazaro\Laragents\Clients\AnthropicClient;
use EduLazaro\Laragents\Clients\OpenAiClient;
use EduLazaro\Laragents\Contracts\ChatClient;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use EduLazaro\Laragents\Tools\ToolRegistry;
use EduLazaro\Laragents\Usage\NullUsageRecorder;
use Illuminate\Support\ServiceProvider;

class LaragentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/laragents.php', 'laragents');

        // No binding from the host app means nothing is recorded and nothing is blocked.
        // A package should not start refusing calls because you have not wired your
        // billing yet.
        $this->app->singleton(UsageRecorder::class, function ($app) {
            $class = $app['config']['laragents.usage_recorder'] ?? null;

            return $class ? $app->make($class) : new NullUsageRecorder();
        });

        $this->app->singleton(ToolRegistry::class);

        $this->app->bind(OpenAiClient::class, fn ($app) => new OpenAiClient($app->make(UsageRecorder::class)));
        $this->app->bind(AnthropicClient::class, fn ($app) => new AnthropicClient($app->make(UsageRecorder::class)));

        // Resolving the interface gives you OpenAI. Which provider a given conversation
        // uses is a runtime decision (the user's mode, whether a key is configured), so
        // it belongs in your executor, not in a container binding.
        $this->app->bind(ChatClient::class, OpenAiClient::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/laragents.php' => config_path('laragents.php'),
        ], 'laragents-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/create_laragents_tables.php.stub' => database_path(
                'migrations/' . date('Y_m_d_His') . '_create_laragents_tables.php',
            ),
        ], 'laragents-migrations');

        // Separate tag: an app that only wants an interactive chat should not end up
        // with three empty tables for a feature it does not use.
        $this->publishes([
            __DIR__ . '/../database/migrations/create_laragents_agent_tables.php.stub' => database_path(
                'migrations/' . date('Y_m_d_His', time() + 1) . '_create_laragents_agent_tables.php',
            ),
        ], 'laragents-agent-migrations');
    }
}
