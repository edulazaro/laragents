<?php

namespace EduLazaro\Laragents\Tests\Feature;

use EduLazaro\Laragents\Tools\Tool;
use EduLazaro\Laragents\Tests\Fixtures\BuiltSchemaTool;
use EduLazaro\Laragents\Tests\Fixtures\EchoTool;
use EduLazaro\Laragents\Tests\Fixtures\TaggedTool;
use EduLazaro\Laragents\Tests\TestCase;
use EduLazaro\Laragents\Tools\ToolContext;
use EduLazaro\Laragents\Tools\ToolRegistry;

/**
 * Declaring a tool's arguments with the framework's schema builder rather than by hand.
 *
 * Same shape laravel/ai asks its tools for, and nothing new installed:
 * Illuminate\JsonSchema ships with the framework.
 */
class BuiltSchemaTest extends TestCase
{
    public function test_the_builder_produces_what_the_provider_expects(): void
    {
        $parameters = (new BuiltSchemaTool())->parameters();

        $this->assertSame('object', $parameters['type']);
        $this->assertSame('string', $parameters['properties']['role']['type']);
        $this->assertSame(['admin', 'member'], $parameters['properties']['role']['enum']);
        $this->assertSame(['role'], $parameters['required'], 'limit was not marked required');
    }

    public function test_a_tool_with_no_arguments_sends_an_object_and_not_an_array(): void
    {
        $tool = new class extends Tool {
            public function name(): string { return 'nothing'; }
            public function description(): string { return 'Takes nothing.'; }
            public function execute(array $args, ToolContext $context): array { return []; }
        };

        // An empty PHP array serialises to `[]`. A provider that wanted an object
        // rejects the WHOLE request, not just this tool, so every other tool in the
        // call disappears with it.
        $this->assertInstanceOf(\stdClass::class, $tool->parameters()['properties']);
    }

    public function test_writing_the_array_by_hand_still_works(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new EchoTool());

        $definition = $registry->definitions()[0]['function'];

        $this->assertSame('echo_tool', $definition['name']);
        $this->assertSame('string', $definition['parameters']['properties']['value']['type']);
    }

    public function test_a_tool_can_declare_its_tags_with_an_attribute(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new TaggedTool());
        $registry->register(new EchoTool());   // tags(): ['test']

        $names = fn (array $tools) => array_map(fn ($t) => $t->name(), array_values($tools));

        $legal = $names($registry->for(tags: ['legal']));
        $test = $names($registry->for(tags: ['test']));

        $this->assertSame(['tagged_tool'], $legal);
        $this->assertSame(['echo_tool'], $test, 'the method still works');
    }

    public function test_a_tool_with_neither_belongs_to_every_run(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new BuiltSchemaTool());

        // Nobody restricted it, so it is not restricted. The other way round, a package
        // you just installed would start hiding tools it was never told to hide.
        $this->assertCount(1, $registry->for(tags: ['anything']));
    }
}
