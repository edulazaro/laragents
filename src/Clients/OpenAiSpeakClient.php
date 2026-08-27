<?php

namespace EduLazaro\Laragents\Clients;

use EduLazaro\Laragents\Contracts\Speaker;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use EduLazaro\Laragents\Exceptions\PlanCreditsExhausted;
use EduLazaro\Laragents\Exceptions\ProviderRateLimited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiSpeakClient implements Speaker
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
        protected int $timeout = 120,
    ) {
        $this->apiKey ??= config('laragents.openai.api_key');
    }

    /**
     * Read a piece of text aloud.
     *
     * @param string $text
     * @param string|null $voice
     * @param Model|null $owner
     * @param Model|null $actor
     * @param string $operation
     * @param Model|null $subject
     * @return string
     */
    public function speak(
        string $text,
        ?string $voice = null,
        ?Model $owner = null,
        ?Model $actor = null,
        string $operation = 'speech',
        ?Model $subject = null,
    ): string {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('OpenAI API key not configured.');
        }

        if ($owner && ! $this->usage->hasCredits($owner)) {
            throw new PlanCreditsExhausted($owner->getKey(), $operation);
        }

        $model = (string) config('laragents.openai.speech_model', 'tts-1');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout($this->timeout)->post($this->baseUrl . '/audio/speech', [
            'model' => $model,
            'input' => $text,
            'voice' => $voice ?? config('laragents.openai.voice', 'alloy'),
        ]);

        if ($response->status() === 429) {
            throw new ProviderRateLimited('OpenAI');
        }

        if (! $response->successful()) {
            Log::error('OpenAI speech error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('OpenAI speech error: ' . $response->status());
        }

        if ($owner) {
            // Billed per character, and no usage comes back to count, so the input is
            // metered as what it is: the characters that were read out.
            $this->usage->record($owner, $actor, $operation, $model, mb_strlen($text), 0, $subject);
        }

        return $response->body();
    }
}
