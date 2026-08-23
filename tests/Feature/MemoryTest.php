<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Models\Memory;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Tests\TestCase;

class MemoryTest extends TestCase
{
    private Owner $org;
    private Owner $otherOrg;
    private Owner $maria;
    private Owner $juan;

    protected function setUp(): void
    {
        parent::setUp();

        // Owner stands in for any host model; the package only ever sees morphs.
        $this->org = Owner::create();
        $this->otherOrg = Owner::create();
        $this->maria = Owner::create();
        $this->juan = Owner::create();
    }

    private function remember(string $content, Owner $memorable, ?Owner $context = null): Memory
    {
        return Memory::create([
            'memorable_type' => 'firm',
            'memorable_id' => $memorable->id,
            'memory_type' => $context ? 'firm' : null,
            'memory_id' => $context?->id,
            'content' => $content,
        ]);
    }

    private function sessionFor(Owner $org, ?Owner $actor = null): ChatSession
    {
        return ChatSession::create([
            'tenant_type' => 'firm',
            'tenant_id' => $org->id,
            'actor_type' => $actor ? 'firm' : null,
            'actor_id' => $actor?->id,
        ]);
    }

    public function test_shared_memories_reach_everyone_in_the_scope(): void
    {
        $this->remember('The firm bills fortnightly', $this->org);

        $seen = Memory::forSession($this->sessionFor($this->org, $this->juan))->pluck('content');

        $this->assertContains('The firm bills fortnightly', $seen->all());
    }

    public function test_a_personal_memory_does_not_apply_to_someone_else(): void
    {
        // Not a leak, something worse: the model would start behaving with Juan as if
        // he were Maria.
        $this->remember('Prefers bullet points', $this->org, $this->maria);

        $juanSees = Memory::forSession($this->sessionFor($this->org, $this->juan))->pluck('content');
        $mariaSees = Memory::forSession($this->sessionFor($this->org, $this->maria))->pluck('content');

        $this->assertNotContains('Prefers bullet points', $juanSees->all());
        $this->assertContains('Prefers bullet points', $mariaSees->all());
    }

    public function test_the_same_person_carries_different_memories_in_different_scopes(): void
    {
        // The reason there is no memory whose scope is a person: preferences belong to
        // the organisation you hold them in, and do not follow you everywhere.
        $this->remember('Writes in English here', $this->org, $this->maria);
        $this->remember('Writes in Spanish here', $this->otherOrg, $this->maria);

        $here = Memory::forSession($this->sessionFor($this->org, $this->maria))->pluck('content');

        $this->assertContains('Writes in English here', $here->all());
        $this->assertNotContains('Writes in Spanish here', $here->all());
    }

    public function test_a_run_with_nobody_behind_it_sees_only_the_shared_rows(): void
    {
        // An autonomous job has no human to scope to, so nothing personal applies.
        $this->remember('The firm bills fortnightly', $this->org);
        $this->remember('Prefers bullet points', $this->org, $this->maria);

        $seen = Memory::forSession($this->sessionFor($this->org))->pluck('content');

        $this->assertContains('The firm bills fortnightly', $seen->all());
        $this->assertNotContains('Prefers bullet points', $seen->all());
    }

    public function test_a_session_with_no_scope_at_all_gets_nothing(): void
    {
        $this->remember('The firm bills fortnightly', $this->org);

        $orphan = ChatSession::create(['title' => 'no owner, no subject']);

        $this->assertSame(0, Memory::forSession($orphan)->count());
    }

    public function test_the_prompt_block_keeps_shared_and_personal_apart(): void
    {
        // Flattened into one list they blur, and one person's preference starts applying
        // to everybody.
        $this->remember('The firm bills fortnightly', $this->org);
        $this->remember('Prefers bullet points', $this->org, $this->maria);

        $block = Memory::promptBlock($this->sessionFor($this->org, $this->maria));

        $this->assertStringContainsString('The firm bills fortnightly', $block);
        $this->assertStringContainsString('Prefers bullet points', $block);
        $this->assertStringContainsString('Applies to the current user', $block);
    }

    public function test_no_memories_means_an_empty_string_not_an_empty_heading(): void
    {
        // So callers can concatenate without checking first.
        $this->assertSame('', Memory::promptBlock($this->sessionFor($this->org, $this->maria)));
    }
}
