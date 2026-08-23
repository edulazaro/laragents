<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Models\Rule;
use EduLazaro\Laragents\Tests\Fixtures\Owner;
use EduLazaro\Laragents\Tests\TestCase;

/**
 * Rules are the hard half. A memory is weighed; a rule is obeyed, and the ordering is
 * what carries that difference to the model.
 */
class RuleTest extends TestCase
{
    private Owner $org;
    private Owner $case;
    private Owner $maria;
    private Owner $juan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Owner::create();
        $this->case = Owner::create();
        $this->maria = Owner::create();
        $this->juan = Owner::create();
    }

    private function rule(string $content, Owner $memorable, ?Owner $context = null, int $priority = 0, bool $enabled = true): Rule
    {
        return Rule::create([
            'memorable_type' => Owner::class,
            'memorable_id' => $memorable->id,
            'context_type' => $context ? Owner::class : null,
            'context_id' => $context?->id,
            'content' => $content,
            'priority' => $priority,
            'enabled' => $enabled,
        ]);
    }

    private function chat(?Owner $actor = null, ?Owner $subject = null): ChatSession
    {
        return ChatSession::create([
            'owner_type' => Owner::class,
            'owner_id' => $this->org->id,
            'actor_type' => $actor ? Owner::class : null,
            'actor_id' => $actor?->id,
            'subject_type' => $subject ? Owner::class : null,
            'subject_id' => $subject?->id,
        ]);
    }

    public function test_a_firm_rule_reaches_everyone_and_a_preference_does_not(): void
    {
        $this->rule('Never quote fees over chat', $this->org);
        $this->rule('Answer me in bullet points', $this->org, $this->maria);

        $seen = Rule::forSession($this->chat($this->juan))->pluck('content')->all();

        $this->assertContains('Never quote fees over chat', $seen);
        $this->assertNotContains('Answer me in bullet points', $seen);
    }

    public function test_shared_rules_are_rendered_before_personal_ones(): void
    {
        $this->rule('Answer me in bullet points', $this->org, $this->maria);
        $this->rule('Never quote fees over chat', $this->org);

        $block = Rule::promptBlock($this->chat($this->maria));

        // Even though the preference was written first. Interleaved, the model loses the
        // only signal that separates firm policy from one person's taste, and starts
        // trading one off against the other.
        $this->assertLessThan(
            strpos($block, 'Answer me in bullet points'),
            strpos($block, 'Never quote fees over chat'),
        );
    }

    public function test_the_wider_scope_comes_before_the_narrower_one(): void
    {
        $this->rule('Firm-wide policy', $this->org);
        $this->rule('Only on this matter', $this->case);

        $block = Rule::promptBlock($this->chat($this->maria, $this->case));

        // The narrow one reads as a refinement of the wide one, which is what it is.
        $this->assertLessThan(
            strpos($block, 'Only on this matter'),
            strpos($block, 'Firm-wide policy'),
        );
    }

    public function test_priority_orders_within_a_block_and_ties_keep_their_writing_order(): void
    {
        $this->rule('third', $this->org, priority: 10);
        $this->rule('first', $this->org, priority: 1);
        $this->rule('second', $this->org, priority: 1);

        $block = Rule::promptBlock($this->chat());

        $this->assertLessThan(strpos($block, 'second'), strpos($block, 'first'));
        $this->assertLessThan(strpos($block, 'third'), strpos($block, 'second'));
    }

    public function test_a_disabled_rule_is_kept_but_not_sent(): void
    {
        $rule = $this->rule('Paused for the holidays', $this->org, enabled: false);

        $this->assertStringNotContainsString('Paused', Rule::promptBlock($this->chat()));
        $this->assertDatabaseHas('laragents_rules', ['id' => $rule->id]);
    }

    public function test_a_run_with_nobody_behind_it_sees_only_the_firms_rules(): void
    {
        $this->rule('Never quote fees over chat', $this->org);
        $this->rule('Answer me in bullet points', $this->org, $this->maria);

        // An autonomous agent. With no person to scope to, no preference applies, and
        // applying somebody's anyway is how an agent starts acting like one user.
        $block = Rule::promptBlock($this->chat(actor: null));

        $this->assertStringContainsString('Never quote fees', $block);
        $this->assertStringNotContainsString('bullet points', $block);
    }

    public function test_the_wording_is_yours(): void
    {
        config()->set('laragents.rules.headings', [
            'shared' => 'INSTRUCCIONES OBLIGATORIAS DEL DESPACHO (NO las saltes bajo ninguna circunstancia):',
        ]);

        $this->rule('Nunca cites tarifas por chat', $this->org);

        $this->assertStringContainsString(
            'INSTRUCCIONES OBLIGATORIAS DEL DESPACHO',
            Rule::promptBlock($this->chat()),
        );
    }

    public function test_nothing_to_say_is_an_empty_string_not_a_heading(): void
    {
        $this->assertSame('', Rule::promptBlock($this->chat($this->maria)));
    }
}
