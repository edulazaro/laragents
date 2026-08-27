<?php

namespace EduLazaro\Laragents\Clients;

use EduLazaro\Laragents\Contracts\Transcriber;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use EduLazaro\Laragents\Exceptions\PlanCreditsExhausted;
use EduLazaro\Laragents\Exceptions\ProviderRateLimited;
use EduLazaro\Laragents\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiTranscribeClient implements Transcriber
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

        // Minutes of audio, not milliseconds of HTTP. An hour-long meeting takes a
        // while to upload before the provider has even started.
        protected int $timeout = 300,
    ) {
        $this->apiKey ??= config('laragents.openai.api_key');
    }

    /**
     * Transcribe an audio file through Whisper.
     *
     * @param string $filePath
     * @param Model|null $owner
     * @param Model|null $actor
     * @param string $operation
     * @param Model|null $subject
     * @param string|null $language
     * @return Response
     */
    public function transcribe(
        string $filePath,
        ?Model $owner = null,
        ?Model $actor = null,
        string $operation = 'transcription',
        ?Model $subject = null,
        ?string $language = null,
    ): Response {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('OpenAI API key not configured.');
        }

        if (! is_readable($filePath)) {
            throw new \RuntimeException("Audio file not readable: {$filePath}");
        }

        if ($owner && ! $this->usage->hasCredits($owner)) {
            throw new PlanCreditsExhausted($owner->getKey(), $operation);
        }

        $model = (string) config('laragents.openai.transcription_model', 'whisper-1');

        // verbose_json, because the plain response has no duration, and without the
        // duration there is nothing to meter: audio is billed by the minute.
        $payload = [
            'model' => $model,
            'response_format' => 'verbose_json',
        ];

        if ($language !== null) {
            $payload['language'] = $language;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
        ])
            ->timeout($this->timeout)
            ->attach('file', file_get_contents($filePath), basename($filePath))
            ->post($this->baseUrl . '/audio/transcriptions', $payload);

        if ($response->status() === 429) {
            throw new ProviderRateLimited('OpenAI');
        }

        if (! $response->successful()) {
            Log::error('OpenAI transcription error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'file' => basename($filePath),
            ]);

            throw new \RuntimeException('OpenAI transcription error: ' . $response->status());
        }

        $data = $response->json();

        $result = new Response(
            content: $data['text'] ?? '',
            inputTokens: $this->tokensFor((float) ($data['duration'] ?? 0)),
            outputTokens: 0,
            model: $model,
        );

        if ($owner) {
            $this->usage->record(
                $owner,
                $actor,
                $operation,
                $model,
                $result->inputTokens,
                $result->outputTokens,
                $subject,
            );
        }

        return $result;
    }

    /**
     * Audio has no tokens, so it is metered as if a minute were a thousand of them.
     *
     * A convention, not a measurement: it exists so a minute of speech and a page of
     * text can be charged against the same allowance. Price it in the rate table under
     * the model name, like anything else.
     *
     * @param float $seconds
     * @return int
     */
    protected function tokensFor(float $seconds): int
    {
        $perMinute = (int) config('laragents.openai.transcription_tokens_per_minute', 1000);

        return (int) ceil($seconds / 60 * $perMinute);
    }
}
