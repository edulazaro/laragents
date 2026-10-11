<?php

namespace EduLazaro\Laragents\Clients;

use EduLazaro\Laragents\Support\StrictSchema;
use EduLazaro\Laragents\Capabilities\Translator;
use EduLazaro\Laragents\Contracts\ChatClient;
use EduLazaro\Laragents\ModelCapabilities;
use EduLazaro\Laragents\Contracts\UsageRecorder;
use EduLazaro\Laragents\Exceptions\PlanCreditsExhausted;
use EduLazaro\Laragents\Exceptions\ProviderRateLimited;
use EduLazaro\Laragents\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiClient implements ChatClient
{
    /**
     * What a cached input token costs, as a share of an uncached one.
     *
     * Configurable rather than fixed, because unlike Anthropic's flat 0.1 OpenAI's
     * discount is not the same across the catalogue and has moved more than once. The
     * default errs HIGH on purpose: overstating the price of a cache read charges the
     * caller a little more than the invoice, while understating it charges less than you
     * pay, and only the second one loses money in silence.
     *
     * @return float
     */
    protected function cacheReadWeight(): float
    {
        $weight = (float) config('laragents.openai.cache_read_weight', 0.5);

        // A weight outside 0..1 is a typo, and either end of it is expensive: above one
        // the cache costs more than a miss, below zero it pays the caller to use it.
        return max(0.0, min(1.0, $weight));
    }

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
     * Chat.
     *
     * @param array $messages
     * @param string $model
     * @param ?Model $owner
     * @param ?Model $actor
     * @param string $operation
     * @param ?Model $subject
     * @param ?float $temperature
     * @param ?int $maxTokens
     * @param ?array $tools
     * @param ?string $toolChoice
     * @param ?array $schema
     * @param array $capabilities
     * @return Response
     */
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

        // Asking for JSON in the prompt is begging; this is the provider guaranteeing
        // it. `strict` also makes an enum in the schema binding, which is what you
        // actually wanted every time you set a low temperature on a classifier.
        if ($schema) {
            $strict = $schema['strict'] ?? true;

            if ($strict) {
                StrictSchema::assert($schema['schema'] ?? $schema);
            }

            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schema['name'] ?? 'response',
                    'strict' => $strict,
                    'schema' => $schema['schema'] ?? $schema,
                ],
            ];
        }

        // The provider's own tools sit in the same list as yours: it decides which to
        // reach for, runs its own without telling you, and hands yours back to run.
        $native = Translator::forOpenAi($capabilities);
        $tools = $native === [] ? $tools : [...$native, ...($tools ?? [])];

        if ($tools) {
            $body['tools'] = $tools;

            // Sent explicitly, because omitting it is not the same as switching it off:
            // the model falls back to its own default and rejects the call. See
            // ModelCapabilities::toolsRequireReasoningOff.
            if (ModelCapabilities::toolsRequireReasoningOff($model)) {
                $body['reasoning_effort'] = 'none';
            }

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

        // Where it says it got this, when it searched. OpenAI hangs them off the message
        // as annotations rather than off each sentence.
        $citations = [];

        foreach ($message['annotations'] ?? [] as $annotation) {
            if (($annotation['type'] ?? '') !== 'url_citation') {
                continue;
            }

            $citation = $annotation['url_citation'] ?? [];

            $citations[] = array_filter([
                'title' => $citation['title'] ?? null,
                'url' => $citation['url'] ?? null,
            ]);
        }

        // What the prefix cost, now that the provider charged less for it.
        //
        // OpenAI caches automatically: any prefix over about a thousand tokens is reused
        // on the next call and billed at a discount, with nothing to send and nothing to
        // opt into. It reports the reused count in `prompt_tokens_details.cached_tokens`,
        // already included in `prompt_tokens`.
        //
        // Recording the raw count instead is how an app ends up metering the discount
        // away: the provider bills the cheap number, the caller is charged the expensive
        // one, and the gap is invisible to both. It shows up as an allowance that runs
        // out faster than the invoice says it should.
        //
        // No cache-write premium here, unlike Anthropic: OpenAI does not charge for
        // filling the cache, so `cacheCreationTokens` stays zero rather than guessing.
        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $cacheRead = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);

        // Clamped because the two numbers come from the provider and only one of them is
        // documented as a subset of the other. A cached count above the total would make
        // the effective input negative and quietly credit the account.
        $cacheRead = max(0, min($cacheRead, $promptTokens));

        $effectiveInput = (int) round(
            ($promptTokens - $cacheRead) + $cacheRead * $this->cacheReadWeight()
        );

        $result = new Response(
            content: $content,
            inputTokens: $effectiveInput,
            outputTokens: $usage['completion_tokens'] ?? 0,
            model: $model,
            toolCalls: $message['tool_calls'] ?? [],
            finishReason: $finishReason,

            // Reasoning models bill for thinking and, on this endpoint, do not hand the
            // thinking back: the tokens show up in usage and the text does not. Captured
            // when a provider does return it, empty when it does not, and either way the
            // tokens are already counted in completion_tokens.
            reasoning: (string) ($message['reasoning'] ?? ''),
            citations: $citations,
            cacheReadTokens: $cacheRead,
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
