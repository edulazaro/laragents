<?php

namespace EduLazaro\Laragents\Tools;

use EduLazaro\Laragents\Contracts\Embedder;
use EduLazaro\Laragents\Models\ChatSession;
use EduLazaro\Laragents\Models\Memory;
use Illuminate\Support\Facades\Log;

/**
 * Lets the model write a memory on purpose, rather than waiting for the distiller.
 *
 * The two are not redundant. The distiller reads a finished exchange and decides what was
 * worth keeping; this fires the moment somebody says "remember that", which is exactly
 * when they expect it to have happened, and would otherwise wait for a background job
 * they know nothing about.
 */
class SaveMemoryTool extends BaseTool
{
    public function name(): string
    {
        return 'save_memory';
    }

    public function description(): string
    {
        return 'Remember something for later conversations. Use it when the user asks you '
            . 'to remember something, or when you learn a durable fact worth keeping. Do '
            . 'not use it for things that only matter in this conversation.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'content' => [
                    'type' => 'string',
                    'description' => 'The fact, concise and self-contained. Under 200 characters.',
                ],
                'scope' => [
                    'type' => 'string',
                    'enum' => ['owner', 'subject'],
                    'description' => 'owner for the wider scope, subject for what this conversation is about.',
                ],
                'shared' => [
                    'type' => 'boolean',
                    'description' => 'true when it is true for everyone in that scope, false when it '
                        . 'applies only to the person you are talking to. Anything about them '
                        . 'personally must be false.',
                ],
                'category' => ['type' => 'string'],
            ],
            'required' => ['content', 'scope', 'shared'],
        ];
    }

    public function isAction(): bool
    {
        return true;
    }

    public function tags(): array
    {
        return ['*'];
    }

    public function execute(array $args, ToolContext $context): array
    {
        $session = $context->get('session');

        if (! $session instanceof ChatSession) {
            return ['error' => 'No conversation to attach a memory to.'];
        }

        $content = trim((string) ($args['content'] ?? ''));
        $memorable = ($args['scope'] ?? null) === 'subject' ? $session->subject : $session->owner;

        if ($content === '' || ! $memorable) {
            return ['error' => 'Nothing to remember, or no scope to remember it against.'];
        }

        // Shared unless told otherwise would be the wrong default: it turns one person's
        // preference into everyone's, and the model would then act on it for the next
        // person who walks in.
        $shared = (bool) ($args['shared'] ?? false);
        $memoryContext = $shared ? null : $session->actor;

        if (! $shared && ! $memoryContext) {
            return ['error' => 'There is nobody in this conversation for a personal memory to apply to.'];
        }

        $memory = Memory::create([
            'memorable_type' => $memorable->getMorphClass(),
            'memorable_id' => $memorable->getKey(),
            'context_type' => $memoryContext?->getMorphClass(),
            'context_id' => $memoryContext?->getKey(),
            'created_by_type' => $session->actor?->getMorphClass(),
            'created_by_id' => $session->actor?->getKey(),
            'agent_id' => $session->agent_id,
            'source' => Memory::SOURCE_EXPLICIT,
            'category' => is_string($args['category'] ?? null) ? $args['category'] : null,
            'content' => $content,
            'embedding' => $this->embed($content, $session),
        ]);

        return ['saved' => true, 'memory_id' => $memory->getKey()];
    }

    /** @return array<int, float>|null */
    protected function embed(string $content, ChatSession $session): ?array
    {
        if (! config('laragents.memory.embeddings', false) || ! app()->bound(Embedder::class)) {
            return null;
        }

        try {
            return app(Embedder::class)->embed(
                text: $content,
                owner: $session->owner,
                actor: $session->actor,
                operation: 'memory_embedding',
                subject: $session->subject,
            );
        } catch (\Throwable $e) {
            Log::warning('laragents: memory embedding failed, storing without one', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
