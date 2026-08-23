<?php

namespace EduLazaro\Laragents;

use EduLazaro\Laragents\Clients\AnthropicClient;
use EduLazaro\Laragents\Clients\OpenAiClient;
use EduLazaro\Laragents\Clients\OpenAiEmbedder;
use EduLazaro\Laragents\Console\CleanupStaleTasksCommand;
use EduLazaro\Laragents\Console\RunDueAgentsCommand;
use EduLazaro\Laragents\Contracts\ChatClient;
use EduLazaro\Laragents\Contracts\Embedder;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use EduLazaro\Laragents\Tools\ToolRegistry;
use EduLazaro\Laragents\Usage\LarameterRecorder;
use EduLazaro\Laragents\Usage\NullUsageRecorder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class LaragentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/laragents.php', 'laragents');

        // No binding from the host app means nothing is recorded and nothing is blocked.
        // A package should not start refusing calls because you have not wired your
        // billing yet.
        // SCOPED, not singleton: an implementation may memoise the balance, and a queue
        // worker keeps singletons alive between jobs.
        $this->app->scoped(UsageRecorder::class, function ($app) {
            $recorder = $app['config']['laragents.usage_recorder'] ?? null;

            if ($recorder === null) {
                return new NullUsageRecorder();
            }

            // Shorthand for the adapter that ships with this package.
            if ($recorder === 'larameter') {
                if (! class_exists(\EduLazaro\Larameter\CreditMeter::class)) {
                    throw new \RuntimeException(
                        "laragents.usage_recorder is set to 'larameter' but "
                        . 'edulazaro/larameter is not installed. Either '
                        . '`composer require edulazaro/larameter` or point the option '
                        . 'at your own UsageRecorder.'
                    );
                }

                return $app->make(LarameterRecorder::class);
            }

            return $app->make($recorder);
        });

        $this->app->singleton(ToolRegistry::class);

        $this->app->bind(OpenAiClient::class, fn ($app) => new OpenAiClient($app->make(UsageRecorder::class)));
        $this->app->bind(AnthropicClient::class, fn ($app) => new AnthropicClient($app->make(UsageRecorder::class)));

        // Resolving the interface gives you OpenAI. Which provider a given conversation
        // uses is a runtime decision (the user's mode, whether a key is configured), so
        // it belongs in your executor, not in a container binding.
        $this->app->bind(ChatClient::class, OpenAiClient::class);

        // Always bound, and never conditioned on config here. A binding registered only
        // when a config flag is set looks tidy and is a trap: register() runs before the
        // config is necessarily in place, so the binding silently never happens and
        // everything downstream quietly degrades instead of failing.
        //
        // What actually gates the spending is the callers, which check
        // laragents.memory.embeddings before asking for a vector. Embedding costs money
        // on every memory written, so it stays off until you say otherwise.
        $this->app->bind(Embedder::class, OpenAiEmbedder::class);
        $this->app->bind(OpenAiEmbedder::class, fn ($app) => new OpenAiEmbedder($app->make(UsageRecorder::class)));
    }

    public function boot(): void
    {
        $this->registerEventTriggers();

        if ($this->app->runningInConsole()) {
            $this->commands([RunDueAgentsCommand::class, CleanupStaleTasksCommand::class]);
            $this->registerSchedule();
        }

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

    /**
     * Turn the app's own events into agent triggers.
     *
     * This is the bridge that is easy to leave out and impossible to notice missing. The
     * agent form offers "when a document is uploaded", somebody picks it, and unless
     * something listens for that event and calls the trigger, the agent never runs. No
     * error, no log, no complaint until somebody asks why the documents were never
     * triaged.
     *
     * A resolver class per event rather than a closure, because config gets cached and a
     * cached closure is a fatal error. And a class rather than a convention, because only
     * your app knows how to get an organisation out of a file.
     */
    protected function registerEventTriggers(): void
    {
        foreach ($this->app['config']['laragents.events'] ?? [] as $eventClass => $resolver) {
            Event::listen($eventClass, function ($event) use ($resolver) {
                $payload = $this->app->make($resolver)($event);

                // Null is a normal answer: not every upload belongs to a tenant, and a
                // resolver that cannot place one says so instead of guessing.
                if (! is_array($payload) || empty($payload['event']) || empty($payload['owner'])) {
                    return;
                }

                $this->app->make(AgentTrigger::class)->fire(
                    $payload['event'],
                    $payload['owner'],
                    $payload['subject'] ?? null,
                    $payload['context'] ?? [],
                );
            });
        }
    }

    /**
     * The tick, registered here so that installing the package is enough.
     *
     * Every minute, because that is the finest granularity a cron expression can name.
     * withoutOverlapping is safe now that Agent::isDue() measures from the last run: a
     * skipped tick is caught up on the next one instead of being lost.
     */
    protected function registerSchedule(): void
    {
        if (! ($this->app['config']['laragents.agents.schedule'] ?? true)) {
            return;
        }

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            $schedule->command(RunDueAgentsCommand::class)
                ->everyMinute()
                ->onOneServer()
                ->withoutOverlapping();

            $schedule->command(CleanupStaleTasksCommand::class)
                ->everyFiveMinutes()
                ->onOneServer()
                ->withoutOverlapping();
        });
    }
}
