<?php

namespace EduLazaro\Laragents;

/**
 * One provider reply, normalised.
 *
 * Tool calls come back in OpenAI shape whatever the provider was, so the loop has a
 * single format to accumulate and parse. Translating Anthropic's tool_use into this is
 * the concrete client's job, not the loop's.
 */
class Response
{
    /**
     * @param string $content
     * @param int $inputTokens
     * @param int $outputTokens
     * @param string $model
     * @param array $toolCalls
     * @param string $finishReason
     * @param int $cacheCreationTokens
     * @param int $cacheReadTokens
     * @param string $reasoning
     * @param array $citations
     * @return void
     */
    public function __construct(
        public readonly string $content = '',
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly string $model = '',
        public readonly array $toolCalls = [],
        /**
         * Why the model stopped. Worth checking: providers spell truncation differently
         * ('length' in OpenAI, 'max_tokens' in Anthropic) and a truncated reply arrives
         * as ordinary content, cut mid-sentence or empty, with no exception raised.
         */
        public readonly string $finishReason = '',
        /** Cache accounting, when the provider reports it. */
        public readonly int $cacheCreationTokens = 0,
        public readonly int $cacheReadTokens = 0,

        /**
         * What the model worked through before answering, on models that show it.
         *
         * Kept apart from content because it is not the answer: showing it to a user as
         * if it were reads as the model talking to itself. Useful for a debug view, for
         * a log when an answer is wrong, and for nothing else.
         *
         * Providers that redact it (most, most of the time) leave this empty even
         * though the tokens were billed, which is why it is not proof of anything.
         */
        public readonly string $reasoning = '',

        /**
         * Where the model says it got this, when it searched.
         *
         * Only ever filled by a provider-side search: you asked it to look things up,
         * so it can say where. A model answering from memory cites nothing, and one
         * that offers a citation unprompted has invented it.
         *
         * @var array<int, array{title?: string, url?: string, text?: string}>
         */
        public readonly array $citations = [],
    ) {}

    /**
     * Has tool calls.
     *
     * @return bool
     */
    public function hasToolCalls(): bool
    {
        return ! empty($this->toolCalls);
    }

    /**
     * Total tokens.
     *
     * @return int
     */
    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * Was truncated.
     *
     * @return bool
     */
    public function wasTruncated(): bool
    {
        return in_array($this->finishReason, ['length', 'max_tokens'], true);
    }

    /**
     * Whether the model showed its working.
     *
     * @return bool
     */
    public function hasReasoning(): bool
    {
        return $this->reasoning !== '';
    }

    /**
     * Whether it said where it got this.
     *
     * @return bool
     */
    public function hasCitations(): bool
    {
        return $this->citations !== [];
    }
}
