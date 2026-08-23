<?php

namespace EduLazaro\Laragents;

use EduLazaro\Laragents\Jobs\RunAgentTask;
use EduLazaro\Laragents\Models\Agent;
use EduLazaro\Laragents\Models\AgentTask;
use Illuminate\Database\Eloquent\Model;

/**
 * What actually makes an agent run.
 *
 * Without this the agents table is furniture: rows with a schedule_cron and an
 * event_triggers list that nothing ever reads. That failure has no symptom, which is what
 * makes it expensive. Somebody creates a document-triage agent, the form says it fires on
 * uploads, and it never fires. No error, no log, no complaint until somebody asks why the
 * documents were never triaged.
 *
 * Two ways in, and they meet at the same place:
 *
 *   fire()  something happened. Every agent listening to that event gets a task.
 *   due()   the clock. Every agent whose cron expression has come round gets one.
 */
class AgentTrigger
{
    /**
     * Something happened worth waking an agent for.
     *
     * @param  string      $event    Your name for it. The package compares strings and
     *                               understands none of them.
     * @param  Model       $owner    Whose agents may respond. Also who pays.
     * @param  Model|null  $subject  What it was about, carried through to the task.
     * @return int  How many tasks were dispatched.
     */
    public function fire(string $event, Model $owner, ?Model $subject = null, array $context = []): int
    {
        $agents = Agent::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('status', 'active')
            ->get()
            ->filter(fn (Agent $agent) => $agent->listensTo($event));

        $dispatched = 0;

        foreach ($agents as $agent) {
            if ($this->firedRecently($agent, $event, $subject)) {
                continue;
            }

            $this->dispatch($agent, 'event', $subject, array_merge($context, ['event' => $event]), $event);
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Every agent whose schedule has come round.
     *
     * @param  iterable<Model>|null  $subjects  Run the agent once per subject when it
     *                                          works on things one at a time. Null runs
     *                                          it once, unscoped.
     */
    public function due(?iterable $subjects = null): int
    {
        $agents = Agent::query()
            ->where('status', 'active')
            ->whereNotNull('schedule_cron')
            ->get();

        $dispatched = 0;

        foreach ($agents as $agent) {
            if (! $agent->isDue()) {
                continue;
            }

            $targets = $subjects === null ? [null] : (is_array($subjects) ? $subjects : iterator_to_array($subjects));

            foreach ($targets ?: [null] as $subject) {
                $this->dispatch($agent, 'schedule', $subject, []);
                $dispatched++;
            }

            // Written BEFORE the tasks finish, and on purpose: it records that the
            // schedule fired, not that the work succeeded. It is also what lets a missed
            // tick be caught up rather than lost, so it has to move even when the run
            // goes on to fail.
            $agent->forceFill(['last_run_at' => now()])->save();
        }

        return $dispatched;
    }

    protected function dispatch(
        Agent $agent,
        string $triggerType,
        ?Model $subject,
        array $input,
        ?string $event = null,
    ): void {
        $task = AgentTask::create([
            'agent_id' => $agent->getKey(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'trigger_type' => $triggerType,
            'trigger_event' => $event,
            'status' => 'pending',
            'input' => $input,
        ]);

        RunAgentTask::dispatch($task->getKey());
    }

    /**
     * A guard against the same thing firing twice.
     *
     * Observers fire on every save, and a save that touches three columns in three
     * statements is three events. Without this an agent answers the same message three
     * times and is billed for all three.
     */
    protected function firedRecently(Agent $agent, string $event, ?Model $subject): bool
    {
        $seconds = (int) config('laragents.agents.debounce_seconds', 30);

        if ($seconds <= 0) {
            return false;
        }

        return AgentTask::query()
            ->where('agent_id', $agent->getKey())
            ->where('trigger_event', $event)
            ->when(
                $subject,
                fn ($q) => $q->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey()),
                fn ($q) => $q->whereNull('subject_id'),
            )
            ->where('created_at', '>=', now()->subSeconds($seconds))
            ->exists();
    }
}
