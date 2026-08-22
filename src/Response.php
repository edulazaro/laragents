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
    ) {}

    public function hasToolCalls(): bool
    {
        return ! empty($this->toolCalls);
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function wasTruncated(): bool
    {
        return in_array($this->finishReason, ['length', 'max_tokens'], true);
    }
}
