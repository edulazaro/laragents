<?php

namespace EduLazaro\Laragents\Models;

use EduLazaro\Laragents\Contracts\Embedder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Something worth remembering between conversations.
 *
 * Two axes, and keeping them apart is what makes this reusable:
 *
 *   memorable - what the memory is attached to. A case, an organisation, a project.
 *               Required: a memory with no scope belongs to everyone and to no one.
 *   context   - the level it applies at. NULL means everyone within that scope; set,
 *               it applies only to that thing, usually a person but nothing here
 *               assumes so: a team or a role works the same.
 *
 * The second axis is about applicability, not secrecy, and that distinction decides how
 * you treat it. Showing one person's memory to another is not a leak, it is worse: the
 * model starts behaving with Juan as if he were María. "María prefers bullet points" is
 * not confidential, it simply does not apply to anyone else.
 *
 * So there is no such thing as a memory whose scope is a person. A personal preference is
 * a memory of the ORGANISATION that applies to that person, which conveniently means the
 * same person carries different preferences in different organisations, instead of
 * dragging one set everywhere.
 */
class Memory extends Model
{
    /** Written by {@see \EduLazaro\Laragents\MemoryDistiller} out of a conversation. */
    public const SOURCE_DISTILLED = 'distilled';

    /** Written deliberately, by a person or by the model calling a tool to save it. */
    public const SOURCE_EXPLICIT = 'explicit';

    protected $table = 'laragents_memories';

    protected $fillable = [
        'tenant_type',
        'tenant_id',
        'memorable_type',
        'memorable_id',
        'memory_type',
        'memory_id',
        'created_by_type',
        'created_by_id',
        'agent_id',
        'source',
        'category',
        'content',
        'embedding',
        'metadata',
    ];

    protected $casts = [
        'embedding' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Tenant.
     *
     * @return MorphTo
     */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Memorable.
     *
     * @return MorphTo
     */
    public function memorable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Holder.
     *
     * @return MorphTo
     */
    public function holder(): MorphTo
    {
        return $this->morphTo('memory');
    }

    /**
     * Who produced it. Audit only: it never decides what is visible and never reaches
     * the model. A memory written by an admin about a client applies to the client.
     */
    public function createdBy(): MorphTo
    {
        return $this->morphTo('created_by');
    }

    /**
     * Three axes, each answering something different: whose it is, what it is about,
     * and who it applies to.
     *
     * @param  array<Model>  $memorables  What is in play, usually a session's subject.
     *                                    Nulls are ignored.
     * @param  Model|null    $context     Who is asking. Null (an autonomous run with no
     *                                    human behind it) sees only the shared rows,
     *                                    which is the safe reading: with nobody to scope
     *                                    to, nothing personal applies.
     * @param  Model|null    $tenant      Whose rows to look at. Also brings in the ones
     *                                    with no memorable, which are about the tenant
     *                                    itself.
     */
    public static function scopedTo(array $memorables, ?Model $context = null, ?Model $tenant = null): Builder
    {
        $memorables = array_values(array_filter($memorables));

        if (empty($memorables) && ! $tenant) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()
            ->when($tenant, fn (Builder $query) => $query
                ->where('tenant_type', $tenant->getMorphClass())
                ->where('tenant_id', $tenant->getKey()))
            ->where(function (Builder $query) use ($memorables, $tenant) {
                // A null memorable is "about the tenant as a whole", so it is in play
                // whenever a tenant is. Before the tenant was a column of its own, that
                // was written by putting the organisation in the memorable, which meant
                // writing it twice to say one thing.
                if ($tenant) {
                    $query->whereNull('memorable_id');
                }

                foreach ($memorables as $memorable) {
                    $query->orWhere(function (Builder $scope) use ($memorable) {
                        $scope->where('memorable_type', $memorable->getMorphClass())
                            ->where('memorable_id', $memorable->getKey());
                    });
                }
            })
            ->where(function (Builder $query) use ($context) {
                $query->whereNull('memory_id');

                if ($context) {
                    $query->orWhere(function (Builder $mine) use ($context) {
                        $mine->where('memory_type', $context->getMorphClass())
                            ->where('memory_id', $context->getKey());
                    });
                }
            });
    }

    /** The memories in play for a conversation: its owner, its subject, its actor. */
    public static function forSession(ChatSession $session): Builder
    {
        return static::scopedTo([$session->sessionable], $session->actor, $session->tenant);
    }

    /**
     * The memories most like a question, best first.
     *
     * Falls back to recency whenever it cannot do better: no embedder bound, embeddings
     * turned off, the provider unreachable. Degrading to the older behaviour beats
     * returning nothing, and the caller cannot tell the difference except in quality.
     *
     * Scoring happens in PHP over a bounded pool rather than in the database, so this
     * needs no extension and no vector store. That pool is a real ceiling: past a few
     * thousand memories in one scope the right answer is a vector index, not a bigger
     * pool.
     *
     * @return Collection<int, static>
     */
    public static function search(ChatSession $session, string $query, int $limit = 10): Collection
    {
        $recent = fn () => static::forSession($session)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $vector = static::embedQuery($query, $session);

        if ($vector === null) {
            return $recent();
        }

        $pool = (int) config('laragents.memory.candidate_pool', 200);

        $candidates = static::forSession($session)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($pool)
            ->get();

        if ($candidates->isEmpty()) {
            return $candidates;
        }

        // A memory with no vector scores -INF: it sorts to the bottom but is still
        // reachable when the pool is thin, which matters right after turning embeddings
        // on, when most rows predate them.
        // Scored beside the models and not on them. Writing the score onto an Eloquent
        // model makes it an attribute, and an attribute is something a later save() will
        // try to put in a column that does not exist.
        return $candidates
            ->map(fn (self $memory) => [
                'memory' => $memory,
                'score' => is_array($memory->embedding) && $memory->embedding !== []
                    ? static::cosine($vector, $memory->embedding)
                    : -INF,
            ])
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn (array $row) => $row['memory'])
            ->values();
    }

    /** @return array<int, float>|null */
    protected static function embedQuery(string $query, ChatSession $session): ?array
    {
        if (! config('laragents.memory.embeddings', false)) {
            return null;
        }

        if (! app()->bound(Embedder::class)) {
            return null;
        }

        try {
            return app(Embedder::class)->embed(
                text: $query,
                owner: $session->tenant,
                actor: $session->actor,
                operation: 'memory_search',
                subject: $session->sessionable,
            );
        } catch (\Throwable $e) {
            Log::warning('laragents: memory search embedding failed, falling back to recency', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    protected static function cosine(array $a, array $b): float
    {
        if (count($a) !== count($b) || $a === []) {
            return 0.0;
        }

        $dot = $normA = $normB = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $normA += $value * $value;
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * The block to paste into a system prompt, newest first. Empty string when there is
     * nothing, so callers can concatenate without checking.
     *
     * Selection is by recency and nothing cleverer. Semantic retrieval would need an
     * embedding provider, and at the volumes a single scope produces, the newest N is
     * both what the app it came from actually ran and hard to beat.
     */
    public static function promptBlock(ChatSession $session, int $limit = 50): string
    {
        $memories = static::forSession($session)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        if ($memories->isEmpty()) {
            return '';
        }

        $block = "## Remembered from earlier\n";

        foreach (static::group($memories, $session->tenant?->getMorphClass()) as $heading => $lines) {
            $block .= "\n### {$heading}\n";

            foreach ($lines as $line) {
                $block .= "- {$line}\n";
            }
        }

        return $block;
    }

    /**
     * Grouped by scope and then by whether it applies to everyone, so the model can tell
     * "the firm works like this" from "this person prefers that". Flattened into one list
     * they blur, and it starts applying one person's preference to everybody.
     *
     * @param  Collection    $memories
     * @param  string|null   $tenant  Morph alias of the tenant, used to name the rows that
     *                                belong to it as a whole rather than to any one subject.
     * @return array<string, array<string>>
     */
    protected static function group(Collection $memories, ?string $tenant = null): array
    {
        $grouped = [];

        foreach ($memories as $memory) {
            $scope = $memory->memorable_type ?? $tenant ?? 'scope';
            $level = $memory->memory_id === null ? 'everyone' : 'actor';
            $heading = $level === 'everyone'
                ? "About this {$scope}"
                : "Applies to the current user in this {$scope}";

            $grouped[$heading][] = $memory->content;
        }

        return $grouped;
    }
}
