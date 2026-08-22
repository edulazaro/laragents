<?php

namespace EduLazaro\Laragents;

use EduLazaro\Laragents\Contracts\UsageRecorder;
use EduLazaro\Laragents\Usage\NullUsageRecorder;
use Illuminate\Support\ServiceProvider;

class LaragentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/laragents.php', 'laragents');

        // Nothing bound by the host app means nothing is recorded and nothing is
        // blocked. A package should not start refusing calls because you have not got
        // round to wiring your billing yet.
        $this->app->singleton(UsageRecorder::class, function ($app) {
            $class = $app['config']['laragents.usage_recorder'] ?? null;

            return $class ? $app->make($class) : new NullUsageRecorder();
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/laragents.php' => config_path('laragents.php'),
        ], 'laragents-config');
    }
}
