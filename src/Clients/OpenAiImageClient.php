<?php

namespace EduLazaro\Laragents\Clients;

use EduLazaro\Laragents\Contracts\Illustrator;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use EduLazaro\Laragents\Exceptions\PlanCreditsExhausted;
use EduLazaro\Laragents\Exceptions\ProviderRateLimited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiImageClient implements Illustrator
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
        protected int $timeout = 180,
    ) {
        $this->apiKey ??= config('laragents.openai.api_key');
    }

    /**
     * Draw something.
     *
     * @param string $prompt
     * @param int $count
     * @param string|null $size
     * @param Model|null $owner
     * @param Model|null $actor
     * @param string $operation
     * @param Model|null $subject
     * @return array<int, string>
     */
    public function draw(
        string $prompt,
        int $count = 1,
        ?string $size = null,
        ?Model $owner = null,
        ?Model $actor = null,
        string $operation = 'image',
        ?Model $subject = null,
    ): array {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('OpenAI API key not configured.');
        }

        if ($owner && ! $this->usage->hasCredits($owner)) {
            throw new PlanCreditsExhausted($owner->getKey(), $operation);
        }

        $model = (string) config('laragents.openai.image_model', 'gpt-image-1');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout($this->timeout)->post($this->baseUrl . '/images/generations', array_filter([
            'model' => $model,
            'prompt' => $prompt,
            'n' => $count,
            'size' => $size,
        ]));

        if ($response->status() === 429) {
            throw new ProviderRateLimited('OpenAI');
        }

        if (! $response->successful()) {
            Log::error('OpenAI image error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('OpenAI image error: ' . $response->status());
        }

        $data = $response->json();

        if ($owner) {
            // Billed per image, not per token, so what is metered is how many came back.
            $this->usage->record($owner, $actor, $operation, $model, 0, count($data['data'] ?? []), $subject);
        }

        // Decoded here rather than handed over as base64: every caller would decode it,
        // and one of them would forget and store the text.
        return array_map(
            fn (array $image) => base64_decode($image['b64_json'] ?? '', true) ?: '',
            $data['data'] ?? [],
        );
    }
}
