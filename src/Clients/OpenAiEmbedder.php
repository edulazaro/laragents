<?php

namespace EduLazaro\Laragents\Clients;

use EduLazaro\Laragents\Contracts\Embedder;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use EduLazaro\Laragents\Exceptions\PlanCreditsExhausted;
use EduLazaro\Laragents\Exceptions\ProviderRateLimited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiEmbedder implements Embedder
{
    public function __construct(
        protected UsageRecorder $usage,
        protected ?string $apiKey = null,
        protected string $baseUrl = 'https://api.openai.com/v1',
        protected int $timeout = 30,
    ) {
        $this->apiKey ??= config('laragents.openai.api_key');
    }

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
}
