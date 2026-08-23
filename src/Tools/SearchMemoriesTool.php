<?php

namespace EduLazaro\Laragents\Tools;

use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Models\Memory;

/**
 * Looks past the memories already in the prompt.
 *
 * The block pasted into the system prompt is the most recent N, which is the right thing
 * to always carry. This is for when the model needs something older than that, and would
 * otherwise say it does not know rather than go and look.
 */
class SearchMemoriesTool extends BaseTool
{
    public function name(): string
    {
        return 'search_memories';
    }

    public function description(): string
    {
        return 'Search what you remember from earlier conversations. Use it when something '
            . 'may have been discussed before but is not in what you already have.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'What you are looking for.'],
                'limit' => ['type' => 'integer', 'description' => 'How many to return. Default 10, max 20.'],
            ],
            'required' => ['query'],
        ];
    }

    public function tags(): array
    {
        return ['*'];
    }

    public function execute(array $args, ToolContext $context): array
    {
        $session = $context->get('session');

        if (! $session instanceof ChatSession) {
            return ['error' => 'No conversation to search the memories of.'];
        }

        $query = trim((string) ($args['query'] ?? ''));

        if ($query === '') {
            return ['error' => 'Nothing to search for.'];
        }

        $limit = max(1, min(20, (int) ($args['limit'] ?? 10)));

        $memories = Memory::search($session, $query, $limit);

        return [
            'count' => $memories->count(),
            'memories' => $memories->map(fn (Memory $memory) => [
                'content' => $memory->content,
                'scope' => $memory->memorable_type,
                // Shared or personal, because the model has to know whether what it just
                // found applies to the person in front of it.
                'applies_to' => $memory->memory_id === null ? 'everyone in this scope' : 'the current user',
                'category' => $memory->category,
                'remembered_at' => $memory->created_at?->toDateString(),
            ])->all(),
        ];
    }
}
