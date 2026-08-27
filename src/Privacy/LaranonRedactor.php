<?php

namespace EduLazaro\Laragents\Privacy;

use EduLazaro\Laragents\Contracts\Redactor as RedactorContract;
use RuntimeException;

/**
 * The redactor laragents ships with: edulazaro/laranon.
 *
 * A default, not the only option. Anonymising is policy, so bind your own implementation
 * of the contract and this file is never loaded.
 *
 * The ordering is the whole point, and getting it wrong fails quietly:
 *
 *   1. Tokenise a COPY of the prompt. The stored conversation stays in the clear: it is
 *      the source of truth and what a human reads later. Nothing anonymised is persisted.
 *   2. Restore the model's text before it is shown or saved.
 *   3. Restore the tool call ARGUMENTS before the tools run, so they query your database
 *      with real values. Skip this one and a tool searches for "«AP_1»" and finds nothing,
 *      with no error anywhere.
 *
 * A fresh laranon session per turn: the mapping is deterministic, so the same person gets
 * the same marker within a turn and nothing has to survive between them.
 */
class LaranonRedactor implements RedactorContract
{
    private mixed $session = null;

    /**
     * @param array $except
     * @param array $keys
     * @return void
     */
    public function __construct(
        private array $except = ['person'],
        private array $keys = ['content', 'tool_calls.*.function.arguments'],
    ) {
        if (! app()->bound('laranon')) {
            // Loud on purpose. An app that believes it anonymises and does not is worse
            // than one that never claimed to.
            throw new RuntimeException(
                'Privacy is enabled in laragents.privacy.enabled but edulazaro/laranon is '
                . 'not installed. Either `composer require edulazaro/laranon` or turn the '
                . 'option off.'
            );
        }
    }

    /** Starts a turn. Called once per model round-trip. */
    public function beginTurn(): void
    {
        $anonymizer = app('laranon');

        if (! empty($this->except)) {
            $anonymizer = $anonymizer->except($this->except);
        }

        $this->session = $anonymizer->newSession();
    }

    /**
     * Outbound.
     *
     * @param array $messages
     * @return array
     */
    public function outbound(array $messages): array
    {
        return $this->session ? $this->session->anonymize($messages, $this->keys) : $messages;
    }

    /**
     * Inbound.
     *
     * @param string $content
     * @return string
     */
    public function inbound(string $content): string
    {
        return $this->session ? $this->session->restore($content) : $content;
    }

    /**
     * Inbound tool calls.
     *
     * @param array $toolCalls
     * @return array
     */
    public function inboundToolCalls(array $toolCalls): array
    {
        if (! $this->session || empty($toolCalls)) {
            return $toolCalls;
        }

        return $this->session->restore($toolCalls, 'function.arguments');
    }
}
