<?php

namespace EduLazaro\Laragents\Usage;

use EduLazaro\Larameter\UsageTracker;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use Illuminate\Database\Eloquent\Model;

/**
 * Sends what the loop spends to edulazaro/larameter.
 *
 * Enable with `'usage_recorder' => 'larameter'` in config/laragents.php. It lives here
 * rather than in larameter so the dependency runs one way: larameter meters forms and
 * emails and invoices and has no business knowing an AI package exists.
 *
 * The model name is the priced operation, which is what makes a rate table read the way
 * the providers publish theirs.
 */
class LarameterRecorder implements UsageRecorder
{
    /**
     * @param UsageTracker $meter
     * @return void
     */
    public function __construct(
        protected UsageTracker $meter,
    ) {}

    /**
     * Record.
     *
     * @param Model $owner
     * @param ?Model $actor
     * @param string $operation
     * @param string $model
     * @param int $inputTokens
     * @param int $outputTokens
     * @param ?Model $subject
     * @return void
     */
    public function record(
        Model $owner,
        ?Model $actor,
        string $operation,
        string $model,
        int $inputTokens,
        int $outputTokens,
        ?Model $subject = null,
    ): void {
        $this->meter->meter(
            meterable: $owner,
            operation: $model,
            unit: 'token',
            quantityIn: $inputTokens,
            quantityOut: $outputTokens,
            actor: $actor,
            subject: $subject,
            // What the call was for, kept alongside the model that served it, so a bill
            // can be read by feature and not only by model.
            metadata: ['operation' => $operation],
        );
    }

    /**
     * Memoised: the loop asks before every iteration. See UsageTracker for why it
     * deliberately does not notice spending mid-turn.
     */
    public function hasCredits(Model $owner): bool
    {
        return $this->meter->hasCreditsMemoized($owner);
    }
}
