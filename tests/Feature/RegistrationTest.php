<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\Clients\AnthropicClient;
use EduLazaro\Laragents\Contracts\ChatClient;
use EduLazaro\Laragents\Contracts\Redactor;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use EduLazaro\Laragents\Laragents;
use EduLazaro\Laragents\Tests\Fixtures\SilentRedactor;
use EduLazaro\Laragents\Skills\SkillRegistry;
use EduLazaro\Laragents\Tests\Fixtures\EchoTool;
use EduLazaro\Laragents\Tests\Fixtures\TimelineSkill;
use EduLazaro\Laragents\Tests\TestCase;
use EduLazaro\Laragents\Tools\ToolRegistry;
use EduLazaro\Laragents\Usage\NullUsageRecorder;

/**
 * Telling the package which pieces to use, from one place in a service provider.
 *
 * The same shape as keep() in larakeep and meter() in larameter, moved up a level
 * because none of these hang off one model.
 */
class RegistrationTest extends TestCase
{
    public function test_nothing_registered_still_gives_a_working_package(): void
    {
        // Install it and it talks to OpenAI, meters nothing and redacts nothing. That
        // is a sensible thing to start from, and it means the first run needs no setup.
        $this->assertInstanceOf(NullUsageRecorder::class, app(UsageRecorder::class));
        $this->assertTrue(app()->bound(Redactor::class));
    }

    public function test_the_shipped_redactor_says_so_when_laranon_is_missing(): void
    {
        // Loud, and only when something actually asks to redact. An app that believes
        // it anonymises and does not is worse than one that never claimed to.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('edulazaro/laranon is not installed');

        app(Redactor::class);
    }

    public function test_an_app_can_redact_with_its_own(): void
    {
        Laragents::redactWith(SilentRedactor::class);

        $this->assertInstanceOf(SilentRedactor::class, app(Redactor::class));
    }

    public function test_the_provider_can_be_swapped(): void
    {
        Laragents::talkWith(AnthropicClient::class);

        $this->assertInstanceOf(AnthropicClient::class, app(ChatClient::class));
    }

    public function test_tools_and_skills_land_in_their_catalogues(): void
    {
        Laragents::tools([EchoTool::class]);
        Laragents::skills(TimelineSkill::class);

        $this->assertNotNull(app(ToolRegistry::class)->get('echo_tool'));
        $this->assertTrue(app(SkillRegistry::class)->has('timeline'));
    }

    public function test_a_tool_can_be_handed_over_already_built(): void
    {
        // For one that needs constructor arguments the container cannot guess.
        $tool = new EchoTool();

        Laragents::tools($tool);

        $this->assertSame($tool, app(ToolRegistry::class)->get('echo_tool'));
    }
}
