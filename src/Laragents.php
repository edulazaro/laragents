<?php

namespace EduLazaro\Laragents;

use EduLazaro\Laragents\Contracts\ChatClient;
use EduLazaro\Laragents\Contracts\Redactor;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use EduLazaro\Laragents\Skills\SkillRegistry;
use EduLazaro\Laragents\Tools\ToolRegistry;

/**
 * Where an app tells laragents which pieces to use.
 *
 * One place, in your service provider, instead of knowing the contract names and
 * binding them yourself:
 *
 *     Laragents::redactWith(MyRedactor::class);
 *     Laragents::meterWith(LarameterRecorder::class);
 *     Laragents::tools([ListMembers::class, SendNotice::class]);
 *     Laragents::skills([Timeline::class]);
 *
 * Everything here is optional and everything has a default. Calling none of it gives a
 * package that talks to OpenAI, meters nothing, redacts nothing and has the two memory
 * tools, which is a sensible thing to start from.
 *
 * The same shape as keep() in larakeep and meter() in larameter, moved up a level
 * because none of these hang off one model: they are how the whole package behaves.
 */
class Laragents
{
    /**
     * Who keeps personal data out of what reaches the provider.
     *
     * Ships with LaranonRedactor. Bind your own and edulazaro/laranon is never
     * installed: what counts as personal data is your policy, not this package's.
     *
     * @param class-string<Redactor> $redactor
     * @return void
     */
    public static function redactWith(string $redactor): void
    {
        app()->bind(Redactor::class, $redactor);
    }

    /**
     * Who records what the loop spends.
     *
     * Ships with NullUsageRecorder, which records nothing. Point it at
     * LarameterRecorder and the tokens are charged against your plans.
     *
     * @param class-string<UsageRecorder> $recorder
     * @return void
     */
    public static function meterWith(string $recorder): void
    {
        app()->scoped(UsageRecorder::class, $recorder);
    }

    /**
     * Which provider answers by default.
     *
     * Ships with OpenAI. Which provider a given conversation uses is usually a runtime
     * decision made by your executor; this is the fallback for everything else.
     *
     * @param class-string<ChatClient> $client
     * @return void
     */
    public static function talkWith(string $client): void
    {
        app()->bind(ChatClient::class, $client);
    }

    /**
     * Who reads text aloud, and who draws.
     *
     * Both ship with OpenAI, and neither is called by anything here: they are for an
     * app that wants them, not for the loop.
     *
     * @param class-string<\EduLazaro\Laragents\Contracts\Speaker> $speaker
     * @return void
     */
    public static function speakWith(string $speaker): void
    {
        app()->bind(\EduLazaro\Laragents\Contracts\Speaker::class, $speaker);
    }

    /**
     * @param class-string<\EduLazaro\Laragents\Contracts\Illustrator> $illustrator
     * @return void
     */
    public static function drawWith(string $illustrator): void
    {
        app()->bind(\EduLazaro\Laragents\Contracts\Illustrator::class, $illustrator);
    }

    /**
     * Add tools to the catalogue.
     *
     * @param class-string|object|array<int, class-string|object> $tools
     * @return void
     */
    public static function tools(string|object|array $tools): void
    {
        $registry = app(ToolRegistry::class);

        // Not a cast: (array) on an object spreads its properties instead of wrapping it,
        // which hands the registry an array and fails somewhere else entirely.
        foreach (is_array($tools) ? $tools : [$tools] as $tool) {
            $registry->register(is_string($tool) ? app($tool) : $tool);
        }
    }

    /**
     * Add skills to the catalogue.
     *
     * @param class-string|array<int, class-string> $skills
     * @return void
     */
    public static function skills(string|array $skills): void
    {
        app(SkillRegistry::class)->register($skills);
    }
}
