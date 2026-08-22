<?php

namespace EduLazaro\Laragents\Usage;

use EduLazaro\Laragents\Contracts\UsageRecorder;
use Illuminate\Database\Eloquent\Model;

/** Records nothing, blocks nothing. The default until you bind your own. */
class NullUsageRecorder implements UsageRecorder
{
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

    public function hasCredits(Model $owner): bool
    {
        return true;
    }
}
