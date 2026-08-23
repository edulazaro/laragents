<?php

namespace EduLazaro\Laragents;

use EduLazaro\Laragents\Contracts\ChatClient;
use EduLazaro\Laragents\Exceptions\PlanCreditsExhausted;
use EduLazaro\Laragents\Models\Agent;
use EduLazaro\Laragents\Models\AgentLog;
use EduLazaro\Laragents\Models\AgentTask;
use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Tools\ToolContext;
use EduLazaro\Laragents\Tools\ToolRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * Runs one agent task: builds the prompt, runs the loop, records what happened.
 *
 * It runs on the SAME AgentLoop the chat uses. In the app this came from these were two
 * executors, one a copy of the other, and the copy had quietly missed out on the PII
 * redaction the original gained later. One loop, one place to fix things.
 *
 * The run is wrapped so that a failure is always recorded on the task. An agent that
 * silently stops is the hardest kind of bug to notice: no output looks exactly like
 * nothing to do, and nobody is watching an autonomous run.
 *
 * Scheduling is NOT here. When to fire an agent depends on your queue and your clock,
 * and a package that assumes either breaks on someone else's setup. Dispatch a job that
 * calls run() from wherever suits you.
 */
class AgentRunner
{
    public function __construct(
        protected ChatClient $client,
        protected ToolRegistry $registry,
    ) {}

    /**
     * @param  ToolContext|null  $context  What the agent's tools need. The runner adds
     *                                     nothing to it: only your app knows what a tool
     *                                     of yours requires.
     */
    public function run(AgentTask $task, ?ToolContext $context = null): ?Response
    {
        $agent = $task->agent;

        if (! $agent) {
            $task->markFailed('The task has no agent.');

            return null;
        }

        if (! $agent->isActive()) {
            $task->markFailed('The agent is paused.');

            return null;
        }

        if ($guard = $this->guard($agent, $task)) {
            $task->markFailed($guard);
            $this->log($task, 'error', $guard);

            return null;
        }

        $task->markRunning();
        $this->log($task, 'info', 'Task started.');

        try {
            $response = $this->execute($agent, $task, $context ?? ToolContext::make());

            $task->markCompleted(
                output: ['content' => $response->content, 'finish_reason' => $response->finishReason],
                inputTokens: $response->inputTokens,
                outputTokens: $response->outputTokens,
            );

            $agent->increment('tasks_completed');
            $agent->increment('total_tokens_used', $response->totalTokens());
            $agent->forceFill(['last_run_at' => now()])->save();

            $this->log($task, 'info', 'Task finished.');

            return $response;
        } catch (PlanCreditsExhausted $e) {
            // Told apart from any other failure on purpose: this one is not a bug to
            // chase, it is a plan that ran out, and the fix is commercial.
            $task->markFailed('No credits left on the plan.');
            $this->log($task, 'error', 'No credits left on the plan.');
        } catch (\Throwable $e) {
            $task->markFailed($e->getMessage());
            $this->log($task, 'error', $e->getMessage(), ['exception' => $e::class]);
        }

        $agent->increment('tasks_failed');

        return null;
    }

    /**
     * A ceiling on how many times an agent may fire in a day.
     *
     * Not a nicety: an agent wired to an event that fires in a loop will keep spawning
     * tasks, and every one of them costs money. The cap is what stops a misconfiguration
     * from becoming an invoice.
     */
    protected function guard(Agent $agent, AgentTask $task): ?string
    {
        $max = (int) $agent->setting('max_tasks_per_day', 50);

        if ($max <= 0) {
            return null;
        }

        $today = AgentTask::where('agent_id', $agent->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        return $today > $max ? "Daily task limit reached ({$max})." : null;
    }

    protected function execute(Agent $agent, AgentTask $task, ToolContext $context): Response
    {
        // A session, so an agent run is stored and readable exactly like a conversation.
        // Its actor is null: nobody is at the other end, and that is also what keeps
        // personal memories out of an autonomous run.
        $session = ChatSession::create([
            'owner_type' => $agent->owner_type,
            'owner_id' => $agent->owner_id,
            'subject_type' => $task->subject_type,
            'subject_id' => $task->subject_id,
            'title' => $agent->name,
            'metadata' => ['agent_id' => $agent->id, 'agent_task_id' => $task->id],
        ]);

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($agent, $session)],
            ['role' => 'user', 'content' => $task->input['instruction'] ?? 'Run your scheduled task.'],
        ];

        $loop = new AgentLoop($this->client, $this->registry);

        return $loop->run(
            messages: $messages,
            model: $this->model($agent),
            session: $session,
            context: $context,
            tools: $this->registry->definitions(whitelist: $agent->allowed_tools),
        );
    }

    /** The agent's own prompt plus whatever it should remember. */
    protected function systemPrompt(Agent $agent, ChatSession $session): string
    {
        // Rules first, and above the agent's own prompt. They are the firm's policy and
        // the user's standing preferences, and a directive buried under a wall of
        // remembered context stops reading as a directive.
        $parts = [
            \EduLazaro\Laragents\Models\Rule::promptBlock($session),
            (string) $agent->system_prompt,
            \EduLazaro\Laragents\Models\Memory::promptBlock($session),
        ];

        return implode("\n\n", array_filter($parts, fn (string $part) => trim($part) !== ''));
    }

    /**
     * Which model this agent runs on. Required by the client, so there is no silent
     * default: an agent with none configured is a configuration error, not a reason to
     * quietly pick something and bill for it.
     */
    protected function model(Agent $agent): string
    {
        $model = $agent->setting('model');

        if (! $model) {
            throw new \RuntimeException(
                "Agent {$agent->id} has no model in its config. Set config.model to the "
                . 'model it should run on.'
            );
        }

        return $model;
    }

    protected function log(AgentTask $task, string $level, string $message, array $metadata = []): void
    {
        AgentLog::create([
            'agent_task_id' => $task->id,
            'agent_id' => $task->agent_id,
            'level' => $level,
            'message' => $message,
            'metadata' => $metadata ?: null,
        ]);
    }
}
