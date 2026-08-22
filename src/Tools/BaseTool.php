<?php

namespace EduLazaro\Laragents\Tools;

use EduLazaro\Laragents\Contracts\Tool;

/**
 * Sensible defaults so a tool is a name, a description, a schema and an execute().
 *
 * Deliberately thin. Its predecessor grew a helper for resolving files scoped to a case,
 * which meant every tool in the app inherited a method about a concept only some of them
 * used, and the base class could never leave the app. Convenience shared by SOME of your
 * tools belongs in a trait of yours, not here.
 */
abstract class BaseTool implements Tool
{
    public function isAction(): bool
    {
        return false;
    }

    public function tags(): array
    {
        return ['*'];
    }

    /**
     * Write descriptions for the model, not for a human reading the code. It is the main
     * thing it uses to pick between twenty tools, and a vague one gets picked wrongly or
     * not at all.
     */
    abstract public function description(): string;

    /** @return array JSON Schema object. Use `{}` and not `[]` for an empty properties bag. */
    abstract public function parameters(): array;
}
