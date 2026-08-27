<?php

namespace EduLazaro\Laragents;

use EduLazaro\Laragents\Capabilities\Capability;
use EduLazaro\Laragents\Contracts\ChatClient;
use EduLazaro\Laragents\Events\InvokingTool;
use EduLazaro\Laragents\Events\ToolInvoked;
use EduLazaro\Laragents\Models\ChatMessage;
use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Contracts\Redactor;
use EduLazaro\Laragents\Tools\ToolContext;
use EduLazaro\Laragents\Tools\ToolRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Call the model, run whatever tools it asks for, feed the results back, repeat until it
 * answers in prose or the iteration cap runs out.
 *
 * The cap is not a nicety. Without it a model that keeps reformulating the same failing
 * search will loop until something times out, having spent real money on every turn.
 *
 * Everything app-specific is a protected method you override: filtering messages on the
 * way out and back (PII redaction, say), enriching tool arguments, and post-processing a
 * tool result. The loop itself should stay boring.
 */
class AgentLoop
{
    protected int $inputTokens = 0;
    protected int $outputTokens = 0;
    protected ?Redactor $redactor = null;

    /** @var int Consecutive tool results that found nothing, within this run. */
    protected int $emptyStreak = 0;

    /**
     * @param ChatClient $client
     * @param ToolRegistry $registry
     * @return void
     */
    public function __construct(
        protected ChatClient $client,
        protected ToolRegistry $registry,
    ) {}

    /**
     * @param  array  $messages  Conversation so far, OpenAI shape, system prompt included.
     * @param  array  $tools     Tool definitions, from ToolRegistry::definitions(). Yours:
     *                           the model asks, this loop runs them.
     * @param  array<int, Capability>  $capabilities  Things the provider does on its own
     *                           side. Switched on here and never seen again: no call
     *                           reaches your server, so they miss the redactor, the usage
     *                           recorder and the empty-streak guard.
     */
    public function run(
        array $messages,
        string $model,
        ChatSession $session,
        ToolContext $context,
        array $tools = [],
        bool $persist = true,
        array $capabilities = [],
    ): Response {
        // The session and the model go in here and not at every call site, so a tool that
        // needs to know which conversation it is in, or which tier it was called on, can
        // always ask. Leaving it to callers means the memory tools work in agent runs and
        // quietly fail in the chat, or the other way round, depending on who remembered.
        $context = $context->with('session', $session)->with('model', $model);

        $maxIterations = (int) config('laragents.chat.max_tool_iterations', 7);
        $maxTokens = config('laragents.chat.max_response_tokens');

        $iteration = 0;
        $last = null;
        $this->emptyStreak = 0;

        $this->redactor = $this->redactorFor($session);

        while ($iteration < $maxIterations) {
            // A fresh mapping per turn. It is deterministic, so the same person keeps the
            // same marker within the turn, and nothing needs to survive between turns.
            $this->redactor?->beginTurn();

            $response = $this->client->chat(
                messages: $this->outbound($messages),
                model: $model,
                owner: $session->tenant,
                actor: $session->actor,
                operation: 'chat',
                subject: $session,
                maxTokens: $maxTokens,
                tools: $tools ?: null,
                toolChoice: $tools ? 'auto' : null,
                capabilities: $capabilities,
            );

            $this->inputTokens += $response->inputTokens;
            $this->outputTokens += $response->outputTokens;
            $last = $response;

            $content = $this->inbound($response->content);
            $toolCalls = $this->inboundToolCalls($response->toolCalls);

            // Prose, not plumbing: this is the answer.
            if (empty($toolCalls)) {
                if ($persist && trim($content) !== '') {
                    $this->store($session, 'assistant', $content, [
                        'model' => $response->model,
                        'finish_reason' => $response->finishReason,
                    ]);
                }

                return new Response(
                    content: $content,
                    inputTokens: $this->inputTokens,
                    outputTokens: $this->outputTokens,
                    model: $response->model,
                    finishReason: $response->finishReason,
                );
            }

            if ($persist) {
                $this->store($session, 'assistant', $content, ['tool_calls' => $toolCalls]);
            }

            // The assistant turn goes back in even when its content is empty: the tool
            // results that follow refer to these call ids, and a provider rejects results
            // whose call is missing from the history.
            $messages[] = [
                'role' => 'assistant',
                'content' => $content ?: null,
                'tool_calls' => $toolCalls,
            ];

            foreach ($toolCalls as $call) {
                $name = $call['function']['name'] ?? '';
                $callId = $call['id'] ?? '';
                $args = json_decode($call['function']['arguments'] ?? '{}', true) ?? [];

                $arguments = $this->arguments($name, $args, $context);

                Event::dispatch(new InvokingTool($name, $arguments, $session));
                $startedAt = microtime(true);

                $result = $this->dispatch($name, $arguments, $context);
                $result = $this->afterTool($name, $result, $context);
                $result = $this->watchEmptyStreak($result);

                Event::dispatch(new ToolInvoked(
                    $name,
                    $arguments,
                    $result,
                    microtime(true) - $startedAt,
                    $session,
                ));

                $encoded = $this->encodeResult($result);

                if ($persist) {
                    $this->store($session, 'tool', $encoded, [
                        'tool_name' => $name,
                        'tool_call_id' => $callId,
                    ]);
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'content' => $encoded,
                ];
            }

            $iteration++;
        }

        // Cap reached with the model still asking for tools. Returning the last content
        // is better than nothing, but the finish reason says plainly what happened so it
        // is not mistaken for a real answer.
        Log::warning('laragents: iteration cap reached', [
            'session_id' => $session->id,
            'max_iterations' => $maxIterations,
        ]);

        return new Response(
            content: $this->inbound($last?->content ?? ''),
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            model: $last?->model ?? $model,
            finishReason: 'max_iterations',
        );
    }

    /**
     * Dispatch.
     *
     * @param string $name
     * @param array $args
     * @param ToolContext $context
     * @return array
     */
    protected function dispatch(string $name, array $args, ToolContext $context): array
    {
        $tool = $this->registry->get($name);

        if (! $tool) {
            // Back to the model rather than thrown: it can recover by picking another
            // tool, which it cannot do from a stack trace.
            return ['error' => "Unknown tool: {$name}"];
        }

        try {
            return $tool->execute($args, $context);
        } catch (\Throwable $e) {
            Log::error('laragents: tool failed', [
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Tool results are truncated before going back: one unbounded result can eat the
     * whole context window and push the actual conversation out of it.
     */
    protected function encodeResult(array $result): string
    {
        $max = (int) config('laragents.chat.max_tool_result_chars', 16000);
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE) ?: '{}';

        if (mb_strlen($encoded) > $max) {
            $encoded = mb_substr($encoded, 0, $max) . '… [truncated]';
        }

        return $encoded;
    }

    /**
     * Store.
     *
     * @param ChatSession $session
     * @param string $role
     * @param ?string $content
     * @param array $metadata
     * @return void
     */
    protected function store(ChatSession $session, string $role, ?string $content, array $metadata = []): void
    {
        ChatMessage::create([
            'session_id' => $session->id,
            'role' => $role,
            'content' => $content,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Whether this conversation gets redacted.
     *
     * Two switches, and both have to say yes: the app-wide option, and the per-session
     * flag on top of it. The global one is off by default because turning it on changes
     * what the model sees; the per-session one is on by default, so a product that
     * redacts does it unless this user asked otherwise in this conversation.
     */
    protected function redactorFor(ChatSession $session): ?Redactor
    {
        if (! config('laragents.privacy.enabled', false)) {
            return null;
        }

        if ($session->anonymize_pii === false) {
            return null;
        }

        // From the container, so an app with its own idea of what personal data is binds
        // its own and this never reaches laranon. The shipped one throws when laranon is
        // missing, on purpose: an app that believes it anonymises and does not is worse
        // than one that never claimed to.
        return app(Redactor::class);
    }

    /** Messages on their way to the provider. Override to add your own transform. */
    protected function outbound(array $messages): array
    {
        return $this->redactor ? $this->redactor->outbound($messages) : $messages;
    }

    /** The model's text on the way back, before it is shown or stored. */
    protected function inbound(string $content): string
    {
        return $this->redactor ? $this->redactor->inbound($content) : $content;
    }

    /** Tool arguments, restored BEFORE the tools run so they query with real values. */
    protected function inboundToolCalls(array $toolCalls): array
    {
        return $this->redactor ? $this->redactor->inboundToolCalls($toolCalls) : $toolCalls;
    }

    /** Override to add caller data to every tool's arguments. */
    protected function arguments(string $tool, array $args, ToolContext $context): array
    {
        return $args;
    }

    /** Override to inspect or annotate a result before the model sees it. */
    protected function afterTool(string $tool, array $result, ToolContext $context): array
    {
        return $result;
    }

    /**
     * Tell the model to stop when its searches keep finding nothing.
     *
     * A model that gets an empty result reformulates and tries again, which is the
     * right instinct once and a spiral after that: it will spend every iteration it is
     * given rather than tell the user there is nothing there. The iteration cap ends
     * the spiral, but ends it with `max_iterations` and no answer, which is the worst
     * of both.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    protected function watchEmptyStreak(array $result): array
    {
        $after = (int) config('laragents.chat.empty_streak_after', 2);

        if ($after <= 0) {
            return $result;
        }

        $verdict = $this->foundSomething($result);

        // Null means the result is not a collection, so it says nothing either way: a
        // tool that files a document neither found nor failed to find. Those must not
        // reset the streak, or one bookkeeping call between two dead searches hides it.
        if ($verdict === null) {
            return $result;
        }

        if ($verdict === true) {
            $this->emptyStreak = 0;

            return $result;
        }

        $this->emptyStreak++;

        if ($this->emptyStreak < $after) {
            return $result;
        }

        $result['_stop_searching'] = true;

        // Appended, never replaced. The tool's own hint usually explains HOW to
        // reformulate, and dropping it leaves the model with an order to stop and no
        // idea what it was doing wrong.
        // Coalesced, not defaulted: the published config ships the key set to null, and
        // config() hands back a null that exists rather than falling back.
        $hint = config('laragents.chat.empty_streak_hint')
            ?: 'That is :count searches in a row with no results. If you have run out of '
                . 'reasonable reformulations, tell the user you found nothing rather than '
                . 'searching again.';

        $result['_hint'] = trim(
            ($result['_hint'] ?? '') . ' ' . str_replace(':count', (string) $this->emptyStreak, $hint),
        );

        return $result;
    }

    /**
     * Whether a tool result found anything: true, false, or null when it cannot say.
     *
     * A package cannot know what your tools return, so it reads the shape: a result
     * carrying any of the configured collection keys is a search, and a search with
     * every one of them empty found nothing. An error counts as nothing found, since a
     * model retrying a failing tool is the same spiral.
     *
     * @param array<string, mixed> $result
     * @return bool|null
     */
    protected function foundSomething(array $result): ?bool
    {
        if (! empty($result['error'])) {
            return false;
        }

        $keys = (array) config('laragents.chat.result_keys', ['items', 'results', 'matches', 'passages', 'data']);
        $present = array_intersect_key($result, array_flip($keys));

        if ($present === []) {
            return null;
        }

        foreach ($present as $value) {
            if (! empty($value)) {
                return true;
            }
        }

        return false;
    }
}
