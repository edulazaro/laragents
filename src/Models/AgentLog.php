<?php

namespace EduLazaro\Laragents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an agent did, step by step.
 *
 * Worth keeping even when nothing goes wrong: an autonomous run has no one watching it,
 * so the log is the only account of why it decided what it decided. No updated_at, since
 * a log line is written once and never touched again.
 */
class AgentLog extends Model
{
    public $timestamps = false;

    protected $table = 'laragents_agent_logs';

    protected $fillable = [
        'agent_task_id',
        'agent_id',
        'level',
        'message',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Booted.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::creating(function (self $log) {
            $log->created_at ??= now();
        });
    }

    /**
     * Task.
     *
     * @return BelongsTo
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(AgentTask::class, 'agent_task_id');
    }

    /**
     * Agent.
     *
     * @return BelongsTo
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
