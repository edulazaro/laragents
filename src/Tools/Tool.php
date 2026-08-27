<?php

namespace EduLazaro\Laragents\Tools;

use Illuminate\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Serializer;

/**
 * Something the model can call.
 *
 * The package ships the loop and the registry; the tools themselves are yours, because
 * they are the part that talks about your domain. Extend this and write two methods:
 *
 *     class ListMembers extends Tool
 *     {
 *         public function description(): string { ... }
 *         public function execute(array $args, ToolContext $context): array { ... }
 *     }
 *
 * A class rather than an interface plus a base class, which is what this was: two names
 * to learn and `extends BaseTool implements Tool` written at the top of every tool. The
 * cost is PHP's single inheritance, and a tool that needs to share code with others puts
 * it in a trait.
 *
 * Deliberately thin. Its predecessor grew a helper for resolving files scoped to a case,
 * which meant every tool in the app inherited a method about a concept only some of them
 * used, and the class could never leave the app. Convenience shared by SOME of your tools
 * belongs in a trait of yours, not here.
 */
abstract class Tool
{
    /**
     * The name the model calls it by, snake_case.
     *
     * @return string
     */
    abstract public function name(): string;

    /**
     * What it does, written for the model rather than for someone reading the code. It
     * is the main thing it uses to pick between twenty tools, and a vague one gets
     * picked wrongly or not at all.
     *
     * @return string
     */
    abstract public function description(): string;

    /**
     * Do the work.
     *
     * @param array<string, mixed> $args What the model asked for. Untrusted.
     * @param ToolContext $context What your app put there. Trusted.
     * @return array<string, mixed>
     */
    abstract public function execute(array $args, ToolContext $context): array;

    /**
     * Is action.
     *
     * @return bool
     */
    public function isAction(): bool
    {
        return false;
    }

    /**
     * Tags.
     *
     * @return array
     */
    public function tags(): array
    {
        return ['*'];
    }

    /**
     * What the model may pass, as JSON Schema.
     *
     * Override `schema()` instead and this builds it for you. Written by hand it is an
     * array that has to stay in step with what execute() actually reads, and nothing
     * checks that: add a filter to the body and forget the schema, and the model never
     * learns the filter exists.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        $properties = $this->schema();

        if ($properties === []) {
            // An empty PHP array serialises to `[]`, and a provider that wanted an
            // object rejects the whole request, not just this tool.
            return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
        }

        return Serializer::serialize(JsonSchema::object($properties));
    }

    /**
     * The arguments, built rather than written.
     *
     * Uses Illuminate\JsonSchema, which ships with the framework, so nothing new is
     * installed and the shape is the same one laravel/ai asks its tools for:
     *
     *     return [
     *         'role'  => JsonSchema::string()->enum(['admin', 'member'])->description('...'),
     *         'limit' => JsonSchema::integer()->description('Max results.'),
     *     ];
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    protected function schema(): array
    {
        return [];
    }
}
