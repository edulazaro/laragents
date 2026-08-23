<?php

namespace EduLazaro\Laragents\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

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
    protected $table = 'laragents_memories';

    protected $fillable = [
        'memorable_type',
        'memorable_id',
        'context_type',
        'context_id',
        'created_by_type',
        'created_by_id',
        'source',
        'category',
        'content',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function memorable(): MorphTo
    {
        return $this->morphTo();
    }

    public function context(): MorphTo
    {
        return $this->morphTo();
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
     * @param  array<Model>  $memorables  The scopes in play, usually a session's owner
     *                                    and subject. Nulls are ignored.
     * @param  Model|null    $context     Who is asking. Null (an autonomous run with no
     *                                    human behind it) sees only the shared rows,
     *                                    which is the safe reading: with nobody to scope
     *                                    to, nothing personal applies.
     */
    public static function scopedTo(array $memorables, ?Model $context = null): Builder
    {
        $memorables = array_values(array_filter($memorables));

        if (empty($memorables)) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()
            ->where(function (Builder $query) use ($memorables) {
                foreach ($memorables as $memorable) {
                    $query->orWhere(function (Builder $scope) use ($memorable) {
                        $scope->where('memorable_type', $memorable->getMorphClass())
                            ->where('memorable_id', $memorable->getKey());
                    });
                }
            })
            ->where(function (Builder $query) use ($context) {
                $query->whereNull('context_id');

                if ($context) {
                    $query->orWhere(function (Builder $mine) use ($context) {
                        $mine->where('context_type', $context->getMorphClass())
                            ->where('context_id', $context->getKey());
                    });
                }
            });
    }

    /** The memories in play for a conversation: its owner, its subject, its actor. */
    public static function forSession(ChatSession $session): Builder
    {
        return static::scopedTo([$session->owner, $session->subject], $session->actor);
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
            ->limit($limit)
            ->get();

        if ($memories->isEmpty()) {
            return '';
        }

        $block = "## Remembered from earlier\n";

        foreach (static::group($memories) as $heading => $lines) {
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
     * @return array<string, array<string>>
     */
    protected static function group(Collection $memories): array
    {
        $grouped = [];

        foreach ($memories as $memory) {
            $scope = $memory->memorable_type;
            $level = $memory->context_id === null ? 'shared' : 'personal';
            $heading = $level === 'shared'
                ? "About this {$scope}"
                : "Applies to the current user in this {$scope}";

            $grouped[$heading][] = $memory->content;
        }

        return $grouped;
    }
}
