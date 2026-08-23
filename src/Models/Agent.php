<?php

namespace EduLazaro\Laragents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A standing instruction to run the loop: a persona, a set of tools it may reach for,
 * and something that sets it off.
 *
 * Soft deleted on purpose. Tasks and logs point here, and hard deleting an agent would
 * orphan the record of everything it ever did, which is usually the part you wanted to
 * keep.
 */
class Agent extends Model
{
    use SoftDeletes;

    protected $table = 'laragents_agents';

    protected $fillable = [
        'ulid',
        'owner_type',
        'owner_id',
        'created_by_type',
        'created_by_id',
        'name',
        'specialty',
        'system_prompt',
        'status',
        'schedule_cron',
        'event_triggers',
        'allowed_tools',
        'config',
        'tasks_completed',
        'tasks_failed',
        'total_tokens_used',
        'last_run_at',
    ];

    protected $casts = [
        'event_triggers' => 'array',
        'allowed_tools' => 'array',
        'config' => 'array',
        'last_run_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $agent) {
            $agent->ulid ??= (string) Str::ulid();
        });
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): MorphTo
    {
        return $this->morphTo('created_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AgentTask::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AgentLog::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Anything the host app wants to hang off an agent without the package growing a
     * column for it. Model tier, iteration cap, which records it watches.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function maxIterations(): int
    {
        return (int) $this->setting('max_iterations', 10);
    }

    /**
     * Whether this agent reacts to a given event. The events themselves are the app's:
     * the package only compares strings.
     */
    public function listensTo(string $event): bool
    {
        return in_array($event, $this->event_triggers ?? [], true);
    }
}
