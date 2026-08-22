<?php

namespace EduLazaro\Laragents\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * How the host app accounts for what the LLM costs.
 *
 * This is the seam that lets the package stay out of your billing. Each app implements
 * it over its own tables: one project records into a single `ai_token_usage` row per
 * call, another splits it across usage and token transactions. The package neither
 * knows nor cares.
 *
 * Bind an implementation in your service provider; without one, the package records
 * nothing and never blocks a call.
 */
interface UsageRecorder
{
    /**
     * Called AFTER a successful call, with what the provider reported.
     *
     * $subject is whatever the caller passed as the thing this call was about (a case,
     * a conversation, a document), so the spend can be traced back to it.
     */
    public function record(
        Model $owner,
        ?Model $actor,
        string $operation,
        string $model,
        int $inputTokens,
        int $outputTokens,
        ?Model $subject = null,
    ): void;

    /**
     * Called BEFORE the request leaves, as a last line of defence.
     *
     * Return false and the call is refused with a PlanCreditsExhausted exception,
     * before spending anything.
     *
     * IMPORTANT: this is a net, not the front door. Check quota in your own UI and jobs
     * too, where you can stop before assembling an expensive prompt, apply the right
     * threshold for that operation, and tell the user something useful. What this catches
     * is the caller who forgets, which is exactly how an unmetered path ships.
     *
     * The agentic loop calls the client once per iteration, so implementations should
     * expect to be asked repeatedly within a single turn and memoise accordingly.
     */
    public function hasCredits(Model $owner): bool;
}
