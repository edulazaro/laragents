<?php

namespace EduLazaro\Laragents\Models;

use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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
        'tenant_type',
        'tenant_id',
        'created_by_type',
        'created_by_id',
        'name',
        'avatar',
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

    public function tenant(): MorphTo
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

    /**
     * Whether this agent may run.
     *
     * The value comes from config and is not assumed, because YOUR app writes this column
     * and the package only ever reads it. The migration calls it a free string on purpose;
     * comparing it against a word of ours would quietly turn it into an enum, and an app
     * that says 'enabled' or 'live' would get no agents firing and no error to explain it.
     */
    public function isActive(): bool
    {
        return $this->status === static::activeStatus();
    }

    public static function activeStatus(): string
    {
        return (string) config('laragents.agents.active_status', 'active');
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

    /**
     * Whether the schedule has come round.
     *
     * Measured from the LAST RUN and not against the current minute, which is the
     * difference between a schedule and a coincidence. `isDue(now())` is true only during
     * the exact minute the expression names, so a tick lost to a restart, an overlapping
     * run or a busy queue takes that execution with it, silently, for ever. Asking
     * instead whether the next run after the last one has already passed means a missed
     * minute is picked up on the following tick.
     *
     * The baseline for an agent that has never run is when it was created, so switching
     * one on does not make it fire on the spot.
     */
    public function isDue(?\DateTimeInterface $at = null): bool
    {
        if (! $this->schedule_cron) {
            return false;
        }

        $at = $at ? Carbon::instance($at) : now();

        try {
            $cron = new CronExpression($this->schedule_cron);
        } catch (\Throwable $e) {
            // A typo in one agent's expression must not stop the tick for everybody
            // else, so it is logged and skipped rather than thrown.
            Log::warning('laragents: invalid cron expression on agent', [
                'agent_id' => $this->getKey(),
                'schedule_cron' => $this->schedule_cron,
            ]);

            return false;
        }

        $since = $this->last_run_at ?? $this->created_at;

        if (! $since) {
            return $cron->isDue($at);
        }

        return $cron->getNextRunDate($since, 0, false) <= $at;
    }
}
