<?php

namespace EduLazaro\Laragents;

use EduLazaro\Laragents\Contracts\ChatClient;
use EduLazaro\Laragents\Models\ChatSession;
use Illuminate\Support\Facades\Log;

/**
 * Turns a stored session into the message array a provider gets, summarising the old
 * turns once the replay outgrows its token budget.
 *
 * The problem it solves: every turn replays the whole conversation, so a long chat costs
 * more and more for input alone until it hits the context limit. Compression trades the
 * older turns for a summary, once, and reuses it from then on.
 *
 * Two things it does NOT do, on purpose:
 *
 *   - It never compresses the most recent messages. Whatever the budget says, the last
 *     few turns go through verbatim, because that is where the thread of the exchange
 *     lives and a summary of "what we just said" is where these systems get vague.
 *   - It never touches tool calls or their results. Those belong to the turn that
 *     produced them; replaying them across turns is how a loop starts re-reading its own
 *     plumbing.
 */
class HistoryCompressor
{
    public function __construct(
        protected ChatClient $client,
        /** Cheap model: this is summarisation, not the conversation. */
        protected string $model = 'gpt-4o-mini',
        protected string $language = 'English',
    ) {}

    /**
     * @return array messages ready to send
     */
    public function buildHistory(ChatSession $session, string $systemPrompt): array
    {
        $maxHistoryTokens = (int) config('laragents.chat.max_history_tokens', 6000);
        $recentMin = (int) config('laragents.chat.recent_messages_min', 4);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Only prose. Tool plumbing is deliberately excluded, see the class docblock.
        $all = $session->messages()
            ->where(function ($query) {
                $query->where('role', 'user')
                    ->orWhere(function ($sub) {
                        $sub->where('role', 'assistant')->where('content', '!=', '');
                    });
            })
            ->orderBy('id')
            ->get();

        if ($all->isEmpty()) {
            return $messages;
        }

        $total = $all->count();
        $recentCount = min($recentMin, $total);
        $recent = $all->slice($total - $recentCount)->values();
        $older = $all->slice(0, $total - $recentCount)->values();

        $summary = $session->compressed_summary ?? '';
        $compressedUntilId = $session->compressed_until_id;

        // Anything older that the current summary does not cover yet.
        $pending = $compressedUntilId
            ? $older->filter(fn ($m) => $m->id > $compressedUntilId)->values()
            : $older;

        $budget = $this->estimateTokens($summary)
            + $pending->sum(fn ($m) => $this->estimateTokens((string) $m->content))
            + $recent->sum(fn ($m) => $this->estimateTokens((string) $m->content));

        if ($budget <= $maxHistoryTokens) {
            if ($summary !== '') {
                $messages[] = ['role' => 'system', 'content' => $this->summaryHeading() . $summary];
            }

            foreach ($pending as $message) {
                $messages[] = ['role' => $message->role, 'content' => $message->content];
            }

            foreach ($recent as $message) {
                $messages[] = ['role' => $message->role, 'content' => $message->content];
            }

            return $messages;
        }

        $summary = $this->compress($session, $summary, $pending->all());

        $lastCompressedId = $pending->isNotEmpty() ? $pending->last()->id : $compressedUntilId;

        if ($lastCompressedId) {
            $session->updateSummary($summary, $lastCompressedId);
        }

        $messages[] = ['role' => 'system', 'content' => $this->summaryHeading() . $summary];

        foreach ($recent as $message) {
            $messages[] = ['role' => $message->role, 'content' => $message->content];
        }

        return $messages;
    }

    /**
     * Rough token count. Deliberately a heuristic and not a tokeniser: this decides
     * whether to compress, and being 10% out just means compressing a turn early or
     * late. Pulling in a real tokeniser to get that right is not worth the dependency.
     *
     * ~4 characters per token holds well enough for Latin scripts.
     */
    public function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    protected function summaryHeading(): string
    {
        return "Summary of the earlier conversation:\n";
    }

    /**
     * Failure here returns the PREVIOUS summary rather than throwing. Losing the summary
     * would silently drop everything the conversation established, whereas keeping a
     * slightly stale one costs a little context and nothing else.
     */
    protected function compress(ChatSession $session, string $currentSummary, array $toCompress): string
    {
        if (empty($toCompress) && $currentSummary === '') {
            return '';
        }

        $transcript = '';

        foreach ($toCompress as $message) {
            $role = is_array($message) ? ($message['role'] ?? 'user') : $message->role;
            $content = is_array($message) ? ($message['content'] ?? '') : $message->content;
            $label = $role === 'user' ? 'User' : 'Assistant';
            $transcript .= "{$label}: {$content}\n\n";
        }

        $previous = $currentSummary !== '' ? "Previous summary:\n{$currentSummary}\n\n" : '';

        $prompt = "Summarise the following conversation, preserving:\n"
            . "- Concrete facts mentioned (names, figures, dates, decisions)\n"
            . "- The current state of what the user needs\n"
            . "- Actions already taken and how they turned out\n\n"
            . $previous
            . "New messages to fold in:\n{$transcript}\n"
            . "Write a concise summary in {$this->language}, 500 words at most.";

        try {
            $response = $this->client->chat(
                messages: [
                    ['role' => 'system', 'content' => 'You summarise conversations precisely and concisely.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                model: $this->model,
                owner: $session->owner,
                actor: $session->actor,
                operation: 'chat_compression',
                subject: $session,
            );

            return $response->content;
        } catch (\Throwable $e) {
            Log::error('laragents: history compression failed', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return $currentSummary;
        }
    }
}
