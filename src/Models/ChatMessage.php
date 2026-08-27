<?php

namespace EduLazaro\Laragents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn.
 *
 * Four roles, and two of them are not what a user sees: an `assistant` row may carry
 * tool_calls in its metadata instead of prose, and a `tool` row is the result fed back
 * to the model. A UI that renders every row will show the plumbing.
 */
class ChatMessage extends Model
{
    protected $table = 'laragents_chat_messages';

    protected $fillable = [
        'session_id',
        'role',
        'content',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Session.
     *
     * @return BelongsTo
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }

    /**
     * Is user.
     *
     * @return bool
     */
    public function isUser(): bool
    {
        return $this->role === 'user';
    }

    /**
     * Is assistant.
     *
     * @return bool
     */
    public function isAssistant(): bool
    {
        return $this->role === 'assistant';
    }

    /**
     * Is tool call.
     *
     * @return bool
     */
    public function isToolCall(): bool
    {
        return $this->role === 'assistant' && ! empty($this->metadata['tool_calls']);
    }

    /**
     * Is tool result.
     *
     * @return bool
     */
    public function isToolResult(): bool
    {
        return $this->role === 'tool';
    }

    /**
     * Tool name.
     *
     * @return ?string
     */
    public function toolName(): ?string
    {
        return $this->metadata['tool_name'] ?? null;
    }

    /** Rows a person should actually see: prose, not plumbing. */
    public function isVisible(): bool
    {
        return ($this->isUser() || $this->isAssistant())
            && ! $this->isToolCall()
            && trim((string) $this->content) !== '';
    }
}
