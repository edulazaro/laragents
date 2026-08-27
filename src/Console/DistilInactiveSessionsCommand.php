<?php

namespace EduLazaro\Laragents\Console;

use EduLazaro\Laragents\Jobs\DistilMemories;
use EduLazaro\Laragents\Models\ChatSession;
use Illuminate\Console\Command;

/**
 * Wraps up conversations that stopped.
 *
 * The main path is compression: a long chat crosses its token budget, gets summarised,
 * and distils on the way past. Most conversations never get that far. They are three
 * useful exchanges and then silence, and without this they are never distilled at all,
 * which is a strange failure: the package remembers the long conversations and forgets
 * the short ones.
 *
 * These pass a floor of 1, because a conversation that is over deserves distilling even
 * if it was brief. There will be no later chance.
 */
class DistilInactiveSessionsCommand extends Command
{
    protected $signature = 'laragents:distil-inactive-sessions {--limit=50}';

    protected $description = 'Distil memories from conversations that have gone quiet';

    /**
     * Run it.
     *
     * @return int
     */
    public function handle(): int
    {
        $minutes = (int) config('laragents.memory.inactive_after_minutes', 30);
        $limit = (int) $this->option('limit');

        $sessions = ChatSession::query()
            ->where('updated_at', '<', now()->subMinutes($minutes))
            // Nothing said since the last pass. Without this the same finished
            // conversation is picked up every half hour for ever, paying for a call each
            // time to decide again that there is nothing new.
            ->where(function ($query) {
                $query->whereNull('last_distilled_at')
                    ->orWhereColumn('updated_at', '>', 'last_distilled_at');
            })
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($sessions as $id) {
            DistilMemories::dispatch($id, minMessages: 1);
        }

        if ($sessions->isNotEmpty()) {
            $this->info("laragents: queued {$sessions->count()} session(s) for distillation.");
        }

        return self::SUCCESS;
    }
}
