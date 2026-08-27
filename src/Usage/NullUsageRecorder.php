<?php

namespace EduLazaro\Laragents\Usage;

use EduLazaro\Laragents\Contracts\UsageRecorder;
use Illuminate\Database\Eloquent\Model;

/** Records nothing, blocks nothing. The default until you bind your own. */
class NullUsageRecorder implements UsageRecorder
{
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
        //
    }

    /**
     * Has credits.
     *
     * @param Model $owner
     * @return bool
     */
    public function hasCredits(Model $owner): bool
    {
        return true;
    }
}
