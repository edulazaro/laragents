<?php

namespace EduLazaro\Laragents\Tests;

use EduLazaro\Laragents\LaragentsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LaragentsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // A morph map, because the package addresses scopes by the HOST's aliases. With
        // the default FQCN everything would still work and nothing would be proved.
        \Illuminate\Database\Eloquent\Relations\Relation::enforceMorphMap([
            'firm' => \EduLazaro\Laragents\Tests\Fixtures\Owner::class,
            'matter' => \EduLazaro\Laragents\Tests\Fixtures\Matter::class,
        ]);

        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('laragents.openai.api_key', 'sk-test');
        $app['config']->set('laragents.anthropic.api_key', 'sk-ant-test');
    }

    protected function defineDatabaseMigrations(): void
    {
        $migration = require __DIR__ . '/../database/migrations/create_laragents_tables.php.stub';
        $migration->up();

        // A stand-in for the host app's tenant model, so sessions have something to own
        // them without the package shipping one.
        \Illuminate\Support\Facades\Schema::create('owners', function ($table) {
            $table->id();
            $table->timestamps();
        });

        \Illuminate\Support\Facades\Schema::create('matters', function ($table) {
            $table->id();
            $table->timestamps();
        });
    }
}
