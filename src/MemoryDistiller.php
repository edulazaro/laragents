<?php

namespace EduLazaro\Laragents;

use EduLazaro\Laragents\Contracts\ChatClient;
use EduLazaro\Laragents\Contracts\Embedder;
use EduLazaro\Laragents\Models\ChatMessage;
use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Models\Memory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Reads what has been said since last time and turns it into durable memories.
 *
 * Without this, laragents_memories is a table that gets read and never written. The loop
 * pastes memories into the prompt, and something has to put them there.
 *
 * Run it AFTER the answer has gone out, from a queued job. It costs a model call, and
 * charging the user's reply for the privilege of remembering it is how a chat gets slow.
 *
 * Conservative by design: when it cannot confidently extract anything it writes nothing,
 * and it still advances the checkpoint so the same window is never paid for twice.
 */
class MemoryDistiller
{
    public function __construct(
        protected ChatClient $client,
    ) {}

    /**
     * @param  int|null  $minMessages  Overrides the configured floor. The cron that
     *                                 wraps up abandoned conversations passes 1: a
     *                                 conversation that is over should be distilled even
     *                                 if it only had a couple of new messages, because
     *                                 there will be no later chance.
     * @return int  How many memories were created.
     */
    public function distil(ChatSession $session, ?int $minMessages = null): int
    {
        $seconds = (int) config('laragents.memory.lock_seconds', 120);
        $lock = Cache::lock("laragents:distil:{$session->getKey()}", $seconds);

        // Two workers on the same session would each pay for a model call and race to
        // write the same rows. The loser simply leaves; the winner's checkpoint means
        // there is nothing left to do anyway.
        if (! $lock->get()) {
            return 0;
        }

        try {
            return $this->run($session, $minMessages);
        } finally {
            $lock->release();
        }
    }

    protected function run(ChatSession $session, ?int $minMessages): int
    {
        $session->refresh();

        // A memory with no scope belongs to everyone and to no one. Seal it anyway, or
        // the cron picks this session up for ever.
        if (! $session->tenant && ! $session->sessionable) {
            $this->seal($session);

            return 0;
        }

        $messages = $this->newMessages($session);
        $floor = $minMessages ?? (int) config('laragents.memory.min_messages', 4);

        if ($messages->count() < $floor) {
            $this->seal($session);

            return 0;
        }

        $lastId = $messages->last()->getKey();

        try {
            $proposed = $this->ask($session, $messages);
        } catch (\Throwable $e) {
            Log::warning('laragents: memory distillation failed', [
                'session_id' => $session->getKey(),
                'error' => $e->getMessage(),
            ]);

            // Advance anyway. Retrying a window that just failed usually fails again, and
            // each attempt costs a call.
            $this->seal($session, $lastId);

            return 0;
        }

        $created = $this->persist($proposed, $session);

        $this->seal($session, $lastId);

        return $created;
    }

    /**
     * User and assistant text only. Tool calls and their results are the machinery of
     * the answer, not the substance of it, and they crowd out the part worth reading.
     */
    protected function newMessages(ChatSession $session): Collection
    {
        return ChatMessage::query()
            ->where('session_id', $session->getKey())
            ->when($session->distilled_until_id, fn ($q) => $q->where('id', '>', $session->distilled_until_id))
            ->whereIn('role', ['user', 'assistant'])
            ->where('content', '!=', '')
            ->whereNotNull('content')
            ->orderBy('id')
            ->get();
    }

    /** @return array{everyone: array, actor: array} */
    protected function ask(ChatSession $session, Collection $messages): array
    {
        $response = $this->client->chat(
            messages: [
                ['role' => 'system', 'content' => $this->systemPrompt($session)],
                ['role' => 'user', 'content' => $this->userPrompt($session, $messages)],
            ],
            model: (string) config('laragents.memory.model', 'gpt-4o-mini'),
            owner: $session->tenant,
            actor: $session->actor,
            operation: 'memory_distillation',
            subject: $session->sessionable,
        );

        return $this->parse($response->content);
    }

    /**
     * What this conversation can hang a memory off, keyed by morph alias.
     *
     * The keys are YOUR words: whatever your morph map calls them, which is what ends up
     * in memorable_type anyway. The package never invents a value here, so an app whose
     * scopes are a matter and a firm sees "matter" and "firm", and one whose scopes are
     * potatoes sees "potato".
     *
     * The narrower one goes first, so on the rare occasion both are the same type it is
     * the specific one that stays addressable.
     *
     * @return array<string, Model>
     */
    protected function scopes(ChatSession $session): array
    {
        $scopes = [];

        foreach ([$session->sessionable, $session->tenant] as $model) {
            if ($model) {
                $scopes[$model->getMorphClass()] ??= $model;
            }
        }

        return $scopes;
    }

    protected function systemPrompt(ChatSession $session): string
    {
        $configured = config('laragents.memory.prompt');

        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        $lines = [];

        if ($session->sessionable) {
            $lines[] = '"' . $session->sessionable->getMorphClass()
                . '" for anything true only of what this conversation is about';
        }

        if ($session->tenant) {
            $lines[] = '"' . $session->tenant->getMorphClass()
                . '" for anything true of the wider scope it belongs to';
        }

        $scopeList = '- ' . implode("\n- ", $lines);

        return <<<PROMPT
        You distil durable memories out of a conversation. Read the new messages and
        return TWO INDEPENDENT LISTS. Never invent anything. If a list has no clear
        material, return it empty.

        "everyone" holds objective facts useful to anyone working on this, written
        impersonally. Facts about the matter, the parties involved, observed patterns,
        events that happened.

        HARD RULES FOR "everyone". Break these and you are leaking personal data:
        - Never mention the person you are talking to, in any person.
        - Never include their doubts, hypotheses, opinions or state of mind.
        - Never include what they are learning or where their gaps are.
        - Write in the third person or impersonally, as a fact about the world.
        - If you have the slightest doubt whether something exposes them, it does not go
          in "everyone". It goes in "actor" or nowhere.

        "actor" holds what matters to that one person, in their own voice: their open
        threads, their working hypotheses, what they keep meaning to check.

        Standing instructions about HOW to answer ("always use bullet points", "never
        quote fees") are NOT memories. They are rules, a person writes them, and they
        live elsewhere. If you spot one, ignore it.

        The two lists are independent, not translations of each other. They may overlap
        conceptually, they may be disjoint, and either may be empty. Do not force
        symmetry.

        Each entry carries a "memorable", which says what it hangs off. The only values
        allowed are these, exactly as written:
        {$scopeList}

        Common rules:
        - Do not repeat a memory that already exists in the list given to you below.
        - Each entry under 200 characters, concise, and self-contained.
        - Do not summarise the conversation. Discrete facts only.

        Return ONLY a valid JSON object of exactly this shape:
        {"everyone": [{"memorable": "...", "content": "...", "category": "..."}],
         "actor": [{"memorable": "...", "content": "...", "category": "..."}]}
        PROMPT;
    }

    protected function userPrompt(ChatSession $session, Collection $messages): string
    {
        $limit = (int) config('laragents.memory.existing_context', 30);

        $existing = Memory::forSession($session)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['memorable_type', 'memory_id', 'content']);

        $existingBlock = $existing->isEmpty()
            ? '(none yet)'
            : $existing->map(function (Memory $memory) {
                $level = $memory->memory_id === null ? 'everyone' : 'actor';

                return "[{$memory->memorable_type}/{$level}] {$memory->content}";
            })->implode("\n");

        $conversation = $messages
            ->map(fn (ChatMessage $m) => ucfirst($m->role) . ': ' . $m->content)
            ->implode("\n\n");

        $summary = $session->compressed_summary
            ? "Earlier in this conversation (summarised):\n{$session->compressed_summary}\n\n"
            : '';

        return $summary
            . "Existing memories, do not duplicate these:\n{$existingBlock}\n\n"
            . "New messages to read:\n{$conversation}";
    }

    /**
     * Pull the two lists out of the reply.
     *
     * Tolerant of code fences and stray prose around the object, because the reply is
     * asked for in the prompt rather than guaranteed by the API. When the client learns
     * response_format with a schema, this becomes unnecessary and the failure mode stops
     * being "sometimes the whole window is lost to a stray quote".
     *
     * @return array{everyone: array, actor: array}
     */
    protected function parse(string $raw): array
    {
        $empty = ['everyone' => [], 'actor' => []];

        $raw = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($raw)));

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');

        if ($start === false || $end === false || $end <= $start) {
            return $empty;
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

        if (! is_array($decoded)) {
            return $empty;
        }

        return [
            'everyone' => is_array($decoded['everyone'] ?? null) ? $decoded['everyone'] : [],
            'actor' => is_array($decoded['actor'] ?? null) ? $decoded['actor'] : [],
        ];
    }

    /** @param array{everyone: array, actor: array} $proposed */
    protected function persist(array $proposed, ChatSession $session): int
    {
        $created = 0;

        if ($this->maySharePublicly($session->actor)) {
            foreach ($proposed['everyone'] as $item) {
                $created += $this->insert($item, $session, holder: null) ? 1 : 0;
            }
        }

        // No actor means an autonomous run with nobody behind it. There is no one for a
        // personal memory to apply to, so those are dropped rather than attached to the
        // scope, where they would start applying to everybody.
        if ($session->actor) {
            foreach ($proposed['actor'] as $item) {
                $created += $this->insert($item, $session, holder: $session->actor) ? 1 : 0;
            }
        }

        return $created;
    }

    /**
     * Whether this person's conversation may feed the shared pool.
     *
     * Silence is consent here, deliberately: an actor with no say on the matter, or no
     * actor at all, shares. A package should not turn off a feature nobody asked it to
     * turn off, and the ones who care will have said so.
     */
    protected function maySharePublicly(?Model $actor): bool
    {
        if (! $actor || ! method_exists($actor, 'sharesMemories')) {
            return true;
        }

        return $actor->sharesMemories();
    }

    protected function insert(mixed $item, ChatSession $session, ?Model $holder): bool
    {
        if (! is_array($item)) {
            return false;
        }

        $content = trim((string) ($item['content'] ?? ''));

        // Resolved against what this conversation actually has, by morph alias. A fixed
        // list of package words here would mean every app had to translate its own
        // vocabulary into ours, and anything it could not translate was dropped in
        // silence.
        $memorable = $this->scopes($session)[$item['memorable'] ?? ''] ?? null;

        if ($content === '' || ! $memorable) {
            return false;
        }

        $duplicate = Memory::query()
            ->where('memorable_type', $memorable->getMorphClass())
            ->where('memorable_id', $memorable->getKey())
            ->where('content', $content)
            ->when(
                $holder,
                fn ($q) => $q->where('memory_type', $holder->getMorphClass())->where('memory_id', $holder->getKey()),
                fn ($q) => $q->whereNull('memory_id'),
            )
            ->exists();

        if ($duplicate) {
            return false;
        }

        Memory::create([
            'memorable_type' => $memorable->getMorphClass(),
            'memorable_id' => $memorable->getKey(),
            'memory_type' => $holder?->getMorphClass(),
            'memory_id' => $holder?->getKey(),
            'created_by_type' => $session->actor?->getMorphClass(),
            'created_by_id' => $session->actor?->getKey(),
            'agent_id' => $session->agent_id,
            'source' => Memory::SOURCE_DISTILLED,
            'category' => is_string($item['category'] ?? null) ? $item['category'] : null,
            'content' => $content,
            'embedding' => $this->embed($content, $session),
        ]);

        return true;
    }

    /**
     * The vector for a memory, or null.
     *
     * Null is a normal outcome, not a failure: embeddings are off by default, and when
     * they are on the provider can still be unreachable. The row is written either way
     * and simply sorts last in a semantic search, which beats losing the memory over a
     * rate limit.
     *
     * @return array<int, float>|null
     */
    protected function embed(string $content, ChatSession $session): ?array
    {
        if (! config('laragents.memory.embeddings', false) || ! app()->bound(Embedder::class)) {
            return null;
        }

        try {
            return app(Embedder::class)->embed(
                text: $content,
                owner: $session->tenant,
                actor: $session->actor,
                operation: 'memory_embedding',
                subject: $session->sessionable,
            );
        } catch (\Throwable $e) {
            Log::warning('laragents: memory embedding failed, storing without one', [
                'session_id' => $session->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Mark the window as dealt with.
     *
     * Both fields, always, including when nothing was written. The timestamp is what
     * stops a cron paying for a call every night to decide again that this conversation
     * had nothing worth keeping.
     */
    protected function seal(ChatSession $session, ?int $untilId = null): void
    {
        $session->forceFill(array_filter([
            'distilled_until_id' => $untilId,
            'last_distilled_at' => now(),
        ], fn ($value) => $value !== null))->save();
    }
}
