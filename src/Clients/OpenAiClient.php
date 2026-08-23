<?php

namespace EduLazaro\Laragents\Clients;

use EduLazaro\Laragents\Contracts\ChatClient;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use EduLazaro\Laragents\Exceptions\PlanCreditsExhausted;
use EduLazaro\Laragents\Exceptions\ProviderRateLimited;
use EduLazaro\Laragents\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiClient implements ChatClient
{
    public function __construct(
        protected UsageRecorder $usage,
        protected ?string $apiKey = null,
        protected string $baseUrl = 'https://api.openai.com/v1',
        protected int $timeout = 120,
    ) {
        $this->apiKey ??= config('laragents.openai.api_key');
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
    ): Response {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('OpenAI API key not configured.');
        }

        // Credit net. See UsageRecorder::hasCredits(): this is not the front door, it
        // catches the caller who forgot. No owner means platform cost, so it does not
        // apply.
        if ($owner && ! $this->usage->hasCredits($owner)) {
            Log::warning('laragents: credits exhausted, request not sent', [
                'tenant_id' => $owner->getKey(),
                'operation' => $operation,
                'model' => $model,
            ]);

            throw new PlanCreditsExhausted($owner->getKey(), $operation);
        }

        $body = [
            'model' => $model,
            'messages' => $messages,
        ];

        // Only what the caller explicitly asked for (null = not sent), and only where
        // the model accepts it. See ModelCapabilities for why that is per-model.
        if ($temperature !== null && ModelCapabilities::supportsTemperature($model)) {
            $body['temperature'] = $temperature;
        }

        // max_completion_tokens, not max_tokens: the former is accepted by both the 4o
        // and the 5 families, so there is one less axis to break on the next generation.
        if ($maxTokens !== null && ModelCapabilities::supportsOutputCap($model)) {
            $body['max_completion_tokens'] = $maxTokens;
        }

        if ($tools) {
            $body['tools'] = $tools;

            if ($toolChoice) {
                $body['tool_choice'] = $toolChoice;
            }
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout($this->timeout)->post($this->baseUrl . '/chat/completions', $body);

        if ($response->status() === 429) {
            throw new ProviderRateLimited('OpenAI');
        }

        if (! $response->successful()) {
            Log::error('OpenAI API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('OpenAI API error: ' . $response->status());
        }

        $data = $response->json();
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $content = $message['content'] ?? '';
        $finishReason = $choice['finish_reason'] ?? '';
        $usage = $data['usage'] ?? [];

        // Truncation is otherwise INVISIBLE: the reply arrives as ordinary content, cut
        // mid-sentence or empty, with no exception raised. Happens with no cap of your
        // own too, because the model has its own.
        if ($finishReason === 'length') {
            Log::warning('OpenAI: reply truncated by the token cap', [
                'operation' => $operation,
                'model' => $model,
                'max_tokens_requested' => $maxTokens,
                'content_empty' => $content === '',
            ]);
        }

        $result = new Response(
            content: $content,
            inputTokens: $usage['prompt_tokens'] ?? 0,
            outputTokens: $usage['completion_tokens'] ?? 0,
            model: $model,
            toolCalls: $message['tool_calls'] ?? [],
            finishReason: $finishReason,
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
}
