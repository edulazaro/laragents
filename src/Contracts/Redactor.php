<?php

namespace EduLazaro\Laragents\Contracts;

/**
 * Keeps personal data out of what reaches the provider, and puts it back on the way in.
 *
 * A contract and not one class because anonymising is policy, not plumbing: a law firm
 * cares about surnames and national ids, a clinic about patient numbers, a payroll app
 * about bank accounts and not names at all. No package can decide that for you.
 *
 * laragents ships one implementation, over edulazaro/laranon, and it is only a default.
 * Bind your own and laranon is not installed at all.
 *
 * The ordering is the whole point, and getting it wrong fails quietly:
 *
 *   1. outbound() tokenises a COPY. The stored conversation stays in the clear: it is
 *      the source of truth and what a human reads later.
 *   2. inbound() restores the model's text before it is shown or saved.
 *   3. inboundToolCalls() restores the ARGUMENTS before the tools run, so they query
 *      your database with real values. Skip it and a tool searches for "AP_1", finds
 *      nothing, and reports no error.
 */
interface Redactor
{
    /**
     * Start a fresh mapping for this turn.
     *
     * Called once per iteration. Deterministic within the turn, so the same person keeps
     * the same marker, and nothing has to survive between turns.
     *
     * @return void
     */
    public function beginTurn(): void;

    /**
     * Tokenise what is about to leave.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    public function outbound(array $messages): array;

    /**
     * Put the real values back into the model's text.
     *
     * @param string $text
     * @return string
     */
    public function inbound(string $text): string;

    /**
     * Put the real values back into the tool call arguments, before they run.
     *
     * @param array<int, array<string, mixed>> $toolCalls
     * @return array<int, array<string, mixed>>
     */
    public function inboundToolCalls(array $toolCalls): array;
}
