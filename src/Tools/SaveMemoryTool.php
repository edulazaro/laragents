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
class SaveMemoryTool extends Tool
{
    /**
     * Its name.
     *
     * @return string
     */
    public function name(): string
    {
        return 'save_memory';
    }

    /**
     * Description.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Remember something for later conversations. Use it when the user asks you '
            . 'to remember something, or when you learn a durable fact worth keeping. Do '
            . 'not use it for things that only matter in this conversation.';
    }

    /**
     * Parameters.
     *
     * @return array
     */
    public function parameters(): array
    {
        // No enum of valid scopes here, and it is not an oversight: the schema is built
        // once by the registry, with no conversation in hand, so there is nothing to
        // enumerate. The valid values are checked when the tool runs and handed back in
        // the error, which the model reads and corrects on the next turn.
        return [
            'type' => 'object',
            'properties' => [
                'content' => [
                    'type' => 'string',
                    'description' => 'The fact, concise and self-contained. Under 200 characters.',
                ],
                'memorable' => [
                    'type' => 'string',
                    'description' => 'What this memory hangs off. Use one of the values offered '
                        . 'to you in this conversation, exactly as written.',
                ],
                'everyone' => [
                    'type' => 'boolean',
                    'description' => 'true when it is true for everyone in that scope, false when it '
                        . 'belongs to the person you are talking to. Anything about them '
                        . 'personally must be false.',
                ],
                'category' => ['type' => 'string'],
            ],
            'required' => ['content', 'memorable', 'everyone'],
        ];
    }

    /**
     * Is action.
     *
     * @return bool
     */
    public function isAction(): bool
    {
        return true;
    }

    /**
     * Tags.
     *
     * @return array
     */
    public function tags(): array
    {
        return ['*'];
    }

    /**
     * Execute.
     *
     * @param array $args
     * @param ToolContext $context
     * @return array
     */
    public function execute(array $args, ToolContext $context): array
    {
        $session = $context->get('session');

        if (! $session instanceof ChatSession) {
            return ['error' => 'No conversation to attach a memory to.'];
        }

        $content = trim((string) ($args['content'] ?? ''));
        $memorable = $this->scopes($session)[$args['memorable'] ?? ''] ?? null;

        if ($content === '') {
            return ['error' => 'Nothing to remember.'];
        }

        if (! $memorable) {
            return [
                'error' => 'That is not something this conversation can hang a memory off.',
                'valid_values' => array_keys($this->scopes($session)),
            ];
        }

        // Shared unless told otherwise would be the wrong default: it turns one person's
        // preference into everyone's, and the model would then act on it for the next
        // person who walks in.
        $everyone = (bool) ($args['everyone'] ?? false);
        $holder = $everyone ? null : $session->actor;

        if (! $everyone && ! $holder) {
            return ['error' => 'There is nobody in this conversation for a personal memory to apply to.'];
        }

        $memory = Memory::create([
            'memorable_type' => $memorable->getMorphClass(),
            'memorable_id' => $memorable->getKey(),
            'memory_type' => $holder?->getMorphClass(),
            'memory_id' => $holder?->getKey(),
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

    /**
     * What this conversation can hang a memory off, keyed by morph alias.
     *
     * The keys are the host app's own words, taken from its morph map, so nobody has to
     * translate their vocabulary into the package's and nothing is dropped for failing
     * to.
     *
     * @return array<string, \Illuminate\Database\Eloquent\Model>
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

    /** @return array<int, float>|null */
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
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
