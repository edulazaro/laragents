<?php

namespace EduLazaro\Laragents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One run of an agent: why it fired, what it was given, what came back.
 *
 * The token and credit columns are a READ MIRROR for showing a run's cost next to it.
 * They are not the accounting: that belongs to whatever implements UsageRecorder, which
 * is also what the plan is checked against. Two places holding the same number is a
 * smell, but the alternative is joining an app-owned table to render a list.
 */
class AgentTask extends Model
{
    protected $table = 'laragents_agent_tasks';

    protected $fillable = [
        'agent_id',
        'taskable_type',
        'taskable_id',
        'trigger_type',
        'trigger_event',
        'status',
        'input',
        'output',
        'input_tokens',
        'output_tokens',
        'credits_consumed',
        'started_at',
        'completed_at',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'input' => 'array',
        'output' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Agent.
     *
     * @return BelongsTo
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** What the run is about, when there is such a thing. Often null. */
    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Logs.
     *
     * @return HasMany
     */
    public function logs(): HasMany
    {
        return $this->hasMany(AgentLog::class);
    }

    /**
     * Mark running.
     *
     * @return void
     */
    public function markRunning(): void
    {
        $this->update(['status' => 'running', 'started_at' => now()]);
    }

    /**
     * Mark completed.
     *
     * @param array $output
     * @param int $inputTokens
     * @param int $outputTokens
     * @param int $credits
     * @return void
     */
    public function markCompleted(array $output, int $inputTokens = 0, int $outputTokens = 0, int $credits = 0): void
    {
        $this->update([
            'status' => 'completed',
            'output' => $output,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'credits_consumed' => $credits,
            'completed_at' => now(),
        ]);
    }

    /**
     * Failure is recorded, not thrown away. An agent that quietly stops running is the
     * hardest kind of bug to notice, because the absence of output looks like the
     * absence of work to do.
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
            'completed_at' => now(),
        ]);
    }
}
