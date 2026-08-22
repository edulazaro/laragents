<?php

namespace EduLazaro\Laragents\Contracts;

use EduLazaro\Laragents\Tools\ToolContext;

/**
 * Something the model can call.
 *
 * The package ships the loop and the registry; the tools themselves are yours, because
 * they are the part that talks about your domain.
 */
interface Tool
{
    /** snake_case, and it must match the function name sent to the provider. */
    public function name(): string;

    /** What it does, written for the model. This is what makes it pick the right one. */
    public function description(): string;

    /** JSON Schema object for the arguments. */
    public function parameters(): array;

    public function execute(array $args, ToolContext $context): array;

    /**
     * Whether this tool writes. Actions are the ones you may want to confirm with the
     * user before running.
     */
    public function isAction(): bool;

    /**
     * Free-form labels the host app defines and the registry filters by. The package
     * compares strings and understands none of them.
     *
     * This replaces the two hardcoded axes the original had, `requiresCase()` and a
     * workspace-context enum, which were app concepts baked into a supposedly generic
     * API, and which had drifted into deducing one from the other. A tool that only
     * makes sense inside a case is just tagged 'case'; one that only fits a given
     * product is tagged with that product. Return ['*'] for always available.
     */
    public function tags(): array;
}
