<?php

namespace EduLazaro\Laragents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One conversation.
 *
 * The two references it carries are deliberately different things, and conflating them
 * is what makes a chat impossible to reuse across apps:
 *
 *   owner   - the tenant. Who pays, and whose data this conversation may see.
 *             Required. Usually an organisation or a team.
 *   subject - what the conversation is ABOUT. Optional, and often absent. In a legal
 *             product it is the case the whole conversation lives inside; in a real
 *             estate one there may be no subject at all, because a chat ranges over
 *             many properties and a property is data the tools fetch, not a frame.
 *
 * If you find yourself wanting a session per record, that record is data, not a subject.
 */
class ChatSession extends Model
{
    protected $table = 'laragents_chat_sessions';

    protected $fillable = [
        'ulid',
        'owner_type',
        'owner_id',
        'actor_type',
        'actor_id',
        'subject_type',
        'subject_id',
        'title',
        'total_tokens',
        'anonymize_pii',
        'compressed_summary',
        'compressed_until_id',
        'distilled_until_id',
        'last_distilled_at',
        'metadata',
    ];

    protected $casts = [
        'total_tokens' => 'integer',
        'anonymize_pii' => 'boolean',
        'last_distilled_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $session) {
            $session->ulid ??= (string) Str::ulid();
        });
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** Who is talking. A user, usually. */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'session_id');
    }

    public function addTokens(int $tokens): void
    {
        $this->increment('total_tokens', $tokens);
    }

    public function updateSummary(string $summary, int $untilMessageId): void
    {
        $this->update([
            'compressed_summary' => $summary,
            'compressed_until_id' => $untilMessageId,
        ]);
    }
}
