<?php

namespace EduLazaro\Laragents\Clients;

use EduLazaro\Laragents\Contracts\Embedder;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use EduLazaro\Laragents\Exceptions\PlanCreditsExhausted;
use EduLazaro\Laragents\Exceptions\ProviderRateLimited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiEmbedClient implements Embedder
{
    /**
     * @param UsageRecorder $usage
     * @param ?string $apiKey
     * @param string $baseUrl
     * @param int $timeout
     * @return void
     */
    public function __construct(
        protected UsageRecorder $usage,
        protected ?string $apiKey = null,
        protected string $baseUrl = 'https://api.openai.com/v1',
        protected int $timeout = 30,
    ) {
        $this->apiKey ??= config('laragents.openai.api_key');
    }

    /**
     * Embed.
     *
     * @param string $text
     * @param ?Model $owner
     * @param ?Model $actor
     * @param string $operation
     * @param ?Model $subject
     * @return array
     */
    public function embed(
        string $text,
        ?Model $owner = null,
        ?Model $actor = null,
        string $operation = 'embedding',
        ?Model $subject = null,
    ): array {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('OpenAI API key not configured.');
        }

        // Same net as the chat client. Embedding is cheap per call and relentless in
        // volume, which is exactly the shape of cost that runs an account dry unnoticed.
        if ($owner && ! $this->usage->hasCredits($owner)) {
            throw new PlanCreditsExhausted($owner->getKey(), $operation);
        }

        $model = (string) config('laragents.memory.embedding_model', 'text-embedding-3-small');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout($this->timeout)->post($this->baseUrl . '/embeddings', [
            'model' => $model,
            'input' => $text,
        ]);

        if ($response->status() === 429) {
            throw new ProviderRateLimited('OpenAI');
        }

        if (! $response->successful()) {
            Log::error('OpenAI embeddings error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('OpenAI embeddings error: ' . $response->status());
        }

        $data = $response->json();
        $vector = $data['data'][0]['embedding'] ?? null;

        if (! is_array($vector)) {
            throw new \RuntimeException('OpenAI embeddings returned no vector.');
        }

        if ($owner) {
            $this->usage->record(
                $owner,
                $actor,
                $operation,
                $model,
                (int) ($data['usage']['prompt_tokens'] ?? 0),
                0,
                $subject,
            );
        }

        return array_map('floatval', $vector);
    }

    /**
     * Embed many texts in one request.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embedBatch(
        array $texts,
        ?Model $owner = null,
        ?Model $actor = null,
        string $operation = 'embedding',
        ?Model $subject = null,
    ): array {
        if ($texts === []) {
            return [];
        }

        if (empty($this->apiKey)) {
            throw new \RuntimeException('OpenAI API key not configured.');
        }

        if ($owner && ! $this->usage->hasCredits($owner)) {
            throw new PlanCreditsExhausted($owner->getKey(), $operation);
        }

        $model = (string) config('laragents.memory.embedding_model', 'text-embedding-3-small');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout($this->timeout)->post($this->baseUrl . '/embeddings', [
            'model' => $model,

            // Reindexed: the provider answers by position, so a caller that handed us
            // a filtered array with holes in its keys would get its vectors misfiled.
            'input' => array_values($texts),
        ]);

        if ($response->status() === 429) {
            throw new ProviderRateLimited('OpenAI');
        }

        if (! $response->successful()) {
            Log::error('OpenAI embeddings error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'count' => count($texts),
            ]);

            throw new \RuntimeException('OpenAI embeddings error: ' . $response->status());
        }

        $data = $response->json();

        if ($owner) {
            $this->usage->record(
                $owner,
                $actor,
                $operation,
                $model,
                (int) ($data['usage']['prompt_tokens'] ?? 0),
                0,
                $subject,
            );
        }

        // Sorted by the index the provider echoes back: the order of `data` is not
        // guaranteed to match the order of `input`.
        $vectors = [];

        foreach ($data['data'] ?? [] as $item) {
            $vectors[(int) $item['index']] = array_map('floatval', $item['embedding'] ?? []);
        }

        ksort($vectors);

        return array_values($vectors);
    }
}
