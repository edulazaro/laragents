<?php

namespace EduLazaro\Laragents\Exceptions;

use Exception;

/**
 * The owner has run out of credit, per the host app's UsageRecorder.
 *
 * Deliberately distinct from a provider rate-limit (HTTP 429). They read alike and call
 * for opposite reactions:
 *
 *   provider 429          -> their side, after the call went out, temporary, RETRY.
 *   PlanCreditsExhausted  -> your side, before the call goes out, not temporary,
 *                            DO NOT retry. The plan has to change or the period roll over.
 *
 * Collapsing both into one exception leaves whoever catches it unable to decide whether
 * retrying is worth anything, and showing the user the wrong message half the time.
 */
class PlanCreditsExhausted extends Exception
{
    /**
     * @param ?int $ownerId
     * @param ?string $operation
     * @return void
     */
    public function __construct(
        public readonly ?int $ownerId = null,
        public readonly ?string $operation = null,
    ) {
        parent::__construct('The owner has exhausted the credits on their plan.');
    }
}
