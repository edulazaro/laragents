<?php

namespace EduLazaro\Laragents\Testing;

use EduLazaro\Laragents\Contracts\ChatClient;
use EduLazaro\Laragents\Response;
use EduLazaro\Laragents\Support\StrictSchema;
use Illuminate\Database\Eloquent\Model;

/**
 * A chat client that answers what the test told it to, and keeps what it was sent.
 *
 * It exists because the alternative was extending a real client and re-declaring a
 * twelve-parameter `chat()`, which every app was doing separately and which breaks
 * the day a thirteenth parameter arrives. Here that signature is written once, in
 * the package that owns it.
 *
 *     $chat = FakeChatClient::bind()->reply(['category' => 'invoice']);
 *
 *     // run the thing under test
 *
 *     $this->assertSame('claude-opus-5-5', $chat->lastCall()['model']);
 *
 * It also checks the schema exactly as the real clients do, so a test cannot pass
 * with a schema the provider would have rejected.
 */
class FakeChatClient implements ChatClient
{
    /** Every call, in order, with each argument under its own name. */
    public array $calls = [];

    /** @var list<Response> */
    private array $queue = [];

    private ?Response $fallback = null;

    /** Puts itself in the container as the ChatClient, and hands itself back. */
    public static function bind(): static
    {
        $fake = new static();

        app()->instance(ChatClient::class, $fake);

        return $fake;
    }

    /**
     * Queues an answer. A string is the content as it comes; an array is encoded as
     * JSON, which is what a schema-backed call reads; a Response is used as given.
     *
     * Queued answers are returned in order, one per call. Past the end, the last
     * `always()` is used, or an empty answer.
     */
    public function reply(string|array|Response $answer): static
    {
        $this->queue[] = $this->toResponse($answer);

        return $this;
    }

    /** The answer for every call, and for anything past the queue. */
    public function always(string|array|Response $answer): static
    {
        $this->fallback = $this->toResponse($answer);

        return $this;
    }

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
        // The same check the real clients make. A fake that skipped it would let a
        // test pass on a schema that fails in production, which is the whole bug
        // this is supposed to catch early.
        if ($schema && ($schema['strict'] ?? true)) {
            StrictSchema::assert($schema['schema'] ?? $schema);
        }

        $this->calls[] = compact(
            'messages', 'model', 'owner', 'actor', 'operation', 'subject',
            'temperature', 'maxTokens', 'tools', 'toolChoice', 'schema', 'capabilities',
        );

        return array_shift($this->queue)
            ?? $this->fallback
            ?? new Response(model: $model);
    }

    public function lastCall(): ?array
    {
        return $this->calls === [] ? null : $this->calls[count($this->calls) - 1];
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    /** The text of the last user message sent, which is usually what a test asserts on. */
    public function lastPrompt(): ?string
    {
        $messages = $this->lastCall()['messages'] ?? [];

        foreach (array_reverse($messages) as $message) {
            if (($message['role'] ?? null) === 'user') {
                return is_string($message['content'] ?? null) ? $message['content'] : null;
            }
        }

        return null;
    }

    public function forget(): static
    {
        $this->calls = [];
        $this->queue = [];
        $this->fallback = null;

        return $this;
    }

    private function toResponse(string|array|Response $answer): Response
    {
        if ($answer instanceof Response) {
            return $answer;
        }

        return new Response(
            content: is_array($answer) ? json_encode($answer) : $answer,
        );
    }
}
