<?php

namespace EduLazaro\Laragents\Skills;

use EduLazaro\Laragents\Response;

/**
 * What a skill produced, and what it cost.
 *
 * `meta` carries whatever the skill wants to say about the run beyond the text: which
 * skill it was, what it consulted, what it could not find.
 */
final class SkillResult
{
    /**
     * @param string $content The deliverable.
     * @param int $inputTokens
     * @param int $outputTokens
     * @param string $model
     * @param string $finishReason
     * @param array<string, mixed> $meta
     * @return void
     */
    public function __construct(
        public readonly string $content,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly string $model = '',
        public readonly string $finishReason = '',
        public readonly array $meta = [],
    ) {
    }

    /**
     * Build one from what the loop returned.
     *
     * @param Response $response
     * @param array<string, mixed> $meta
     * @return self
     */
    public static function from(Response $response, array $meta = []): self
    {
        return new self(
            content: $response->content,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
            model: $response->model,
            finishReason: $response->finishReason,
            meta: $meta,
        );
    }

    /**
     * Tokens in and out.
     *
     * @return int
     */
    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
