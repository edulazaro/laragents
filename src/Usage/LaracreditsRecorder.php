<?php

namespace EduLazaro\Laragents\Usage;

use EduLazaro\Laracredits\CreditMeter;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use Illuminate\Database\Eloquent\Model;

/**
 * Sends what the loop spends to edulazaro/laracredits.
 *
 * Enable with `'usage_recorder' => 'laracredits'` in config/laragents.php. It lives here
 * rather than in laracredits so the dependency runs one way: laracredits meters forms and
 * emails and invoices and has no business knowing an AI package exists.
 *
 * The model name is the priced operation, which is what makes a rate table read the way
 * the providers publish theirs.
 */
class LaracreditsRecorder implements UsageRecorder
{
    public function __construct(
        protected CreditMeter $meter,
    ) {}

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
            account: $owner,
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
     * Memoised: the loop asks before every iteration, and the balance is two aggregates.
     * See CreditMeter for why it deliberately does not notice spending mid-turn.
     */
    public function hasCredits(Model $owner): bool
    {
        return $this->meter->hasCreditsMemoized($owner);
    }
}
