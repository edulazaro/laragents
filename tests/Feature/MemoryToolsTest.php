<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Models\Memory;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Tests\TestCase;
use EduLazaro\Laragents\Tools\SaveMemoryTool;
use EduLazaro\Laragents\Tools\SearchMemoriesTool;
use EduLazaro\Laragents\Tools\ToolContext;
use EduLazaro\Laragents\Tools\ToolRegistry;

/**
 * The two tools that let the model manage its own memory, and the wiring that makes them
 * reachable. A tool that ships in a package and is never handed to the model is the same
 * nothing as one that was never written.
 */
class MemoryToolsTest extends TestCase
{
    private Owner $org;
    private Owner $maria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Owner::create();
        $this->maria = Owner::create();
    }

    private function chat(?Owner $actor = null): ChatSession
    {
        return ChatSession::create([
            'tenant_type' => 'firm',
            'tenant_id' => $this->org->id,
            'actor_type' => $actor ? 'firm' : null,
            'actor_id' => $actor?->id,
        ]);
    }

    private function context(ChatSession $session): ToolContext
    {
        return ToolContext::make(['session' => $session]);
    }

    public function test_they_come_registered(): void
    {
        $registry = $this->app->make(ToolRegistry::class);

        $this->assertNotNull($registry->get('save_memory'));
        $this->assertNotNull($registry->get('search_memories'));
    }

    public function test_an_app_that_wants_its_own_can_turn_them_off(): void
    {
        config()->set('laragents.memory.tools', false);
        $this->app->forgetInstance(ToolRegistry::class);

        $this->assertNull($this->app->make(ToolRegistry::class)->get('save_memory'));
    }

    public function test_saving_a_shared_memory_reaches_everyone_in_the_scope(): void
    {
        $result = (new SaveMemoryTool())->execute(
            ['content' => 'The bank replies slowly', 'memorable' => 'firm', 'everyone' => true],
            $this->context($this->chat($this->maria)),
        );

        $this->assertTrue($result['saved']);
        $this->assertNull(Memory::sole()->memory_id);
        $this->assertSame(Memory::SOURCE_EXPLICIT, Memory::sole()->source);
    }

    public function test_a_personal_memory_is_attached_to_the_person_talking(): void
    {
        (new SaveMemoryTool())->execute(
            ['content' => 'Prefers bullet points', 'memorable' => 'firm', 'everyone' => false],
            $this->context($this->chat($this->maria)),
        );

        $this->assertSame($this->maria->id, Memory::sole()->memory_id);
    }

    public function test_a_personal_memory_with_nobody_talking_is_refused(): void
    {
        // Falling back to shared would turn one person's preference into everyone's, and
        // the model would then act on it for the next person who walks in.
        $result = (new SaveMemoryTool())->execute(
            ['content' => 'Prefers bullet points', 'memorable' => 'firm', 'everyone' => false],
            $this->context($this->chat(actor: null)),
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, Memory::count());
    }

    public function test_a_scope_the_conversation_does_not_have_is_refused(): void
    {
        $result = (new SaveMemoryTool())->execute(
            ['content' => 'Only true here', 'memorable' => 'matter', 'everyone' => true],
            $this->context($this->chat($this->maria)),
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, Memory::count());
    }

    public function test_saving_is_an_action_because_it_writes(): void
    {
        $this->assertTrue((new SaveMemoryTool())->isAction());
        $this->assertFalse((new SearchMemoriesTool())->isAction());
    }

    public function test_searching_says_whether_what_it_found_applies_to_this_person(): void
    {
        $session = $this->chat($this->maria);

        Memory::create([
            'memorable_type' => 'firm',
            'memorable_id' => $this->org->id,
            'content' => 'The firm bills fortnightly',
        ]);
        Memory::create([
            'memorable_type' => 'firm',
            'memorable_id' => $this->org->id,
            'memory_type' => 'firm',
            'memory_id' => $this->maria->id,
            'content' => 'Prefers bullet points',
        ]);

        $result = (new SearchMemoriesTool())->execute(['query' => 'anything'], $this->context($session));

        $applies = array_column($result['memories'], 'belongs_to', 'content');

        // The model has to be able to tell a firm-wide fact from this one person's
        // preference, or it starts applying the second to everybody.
        $this->assertSame('everyone in this scope', $applies['The firm bills fortnightly']);
        $this->assertSame('the current user', $applies['Prefers bullet points']);
    }

    public function test_a_tool_called_outside_a_conversation_says_so_instead_of_failing(): void
    {
        $result = (new SearchMemoriesTool())->execute(['query' => 'anything'], ToolContext::make());

        $this->assertArrayHasKey('error', $result);
    }
}
