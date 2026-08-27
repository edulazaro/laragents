<?php

namespace EduLazaro\Laragents\Contracts;

use EduLazaro\Laragents\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * A chat-completion provider.
 *
 * Chat is the ONLY capability every provider shares, which is why this interface
 * covers nothing else. Anthropic sells no embeddings and no transcription, so those
 * live on the concrete OpenAI client rather than here: an interface that promised
 * them would be a lie for half its implementations.
 */
interface ChatClient
{
    /**
     * $model is REQUIRED on purpose. The alternative is a default, and a default is
     * something you fall into without deciding: in the app this package came from, the
     * model used to be inferred from the prefix of the operation name, so renaming a
     * string silently changed which model ran and what it cost.
     *
     * $owner is the tenant the call is billed to, and it is what the UsageRecorder
     * receives. A null owner means platform cost (indexing a corpus, a scraper): there
     * is no customer to charge, so nothing is recorded and no credit check applies.
     *
     * $subject is what the call is ABOUT, and it is only carried through to the usage
     * record so spend can be traced back to it. The package never opens it.
     */
    public function chat(
        array $messages,
        string $model,
        ?Model $owner = null,
        ?Model $actor = null,
        string $operation = 'chat',
        ?Model $subject = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?array $tools = null,
        ?string $toolChoice = null,
        ?array $schema = null,
        array $capabilities = [],
    ): Response;
}
