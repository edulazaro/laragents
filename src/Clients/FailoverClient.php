<?php

namespace EduLazaro\Laragents\Clients;

use EduLazaro\Laragents\Contracts\ChatClient;
use EduLazaro\Laragents\Exceptions\ProviderRateLimited;
use EduLazaro\Laragents\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;

/**
 * Tries one provider, then the next, when the first is rate limiting.
 *
 * A 429 is the other side saying "not now", and the conversation in front of a person
 * does not get to wait. With two providers already implementing one contract, the
 * second one is sitting there idle while the first says no.
 *
 * Only on ProviderRateLimited, on purpose. A missing key, a malformed request or a
 * refusal are not going to go better elsewhere, and retrying them everywhere turns one
 * failure into as many as you have providers, each one billed.
 *
 * Off by default: failing over means the reply comes from a model the caller did not
 * choose, and that is a decision, not a default.
 */
class FailoverClient implements ChatClient
{
    /**
     * @param array<int, array{client: ChatClient, model: string}> $fallbacks Tried in order.
     */
    public function __construct(
        protected ChatClient $primary,
        protected array $fallbacks = [],
    ) {
    }

    /**
     * Ask the primary, and the fallbacks if it is rate limiting.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param string $model
     * @param Model|null $owner
     * @param Model|null $actor
     * @param string $operation
     * @param Model|null $subject
     * @param float|null $temperature
     * @param int|null $maxTokens
     * @param array<int, array<string, mixed>>|null $tools
     * @param string|null $toolChoice
     * @param array<string, mixed>|null $schema
     * @return Response
     * @throws ProviderRateLimited When every provider is rate limiting.
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
    ): Response {
        $attempts = [['client' => $this->primary, 'model' => $model], ...$this->fallbacks];
        $last = null;

        foreach ($attempts as $index => $attempt) {
            try {
                return $attempt['client']->chat(
                    messages: $messages,
                    model: $attempt['model'],
                    owner: $owner,
                    actor: $actor,
                    operation: $operation,
                    subject: $subject,
                    temperature: $temperature,
                    maxTokens: $maxTokens,
                    tools: $tools,
                    toolChoice: $toolChoice,
                    schema: $schema,
                    capabilities: $capabilities,
                );
            } catch (ProviderRateLimited $e) {
                $last = $e;

                // Logged at the moment of switching, not at the end: without this the
                // only trace of a failover is a reply from a model nobody asked for.
                Log::warning('laragents: provider rate limited, falling over', [
                    'from' => $attempt['model'],
                    'to' => $attempts[$index + 1]['model'] ?? null,
                    'operation' => $operation,
                ]);
            }
        }

        throw $last;
    }
}
