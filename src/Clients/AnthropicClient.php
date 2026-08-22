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

/**
 * Anthropic (Claude), speaking the same interface as the OpenAI client.
 *
 * Internally it translates the OpenAI shape the loop uses (messages, tools, tool_calls)
 * into Anthropic's /v1/messages shape, and translates the reply back. That is the whole
 * point: the loop accumulates messages and parses tool calls in ONE format and never
 * learns which provider is underneath.
 */
class AnthropicClient implements ChatClient
{
    /**
     * Splits the STABLE part of a system prompt (persona, fixed rules, retrieved
     * context) from the VOLATILE tail (today's date, anything that changes per message
     * and would kill the cache). Whoever builds the prompt inserts it right before the
     * volatile part; chat() caches everything before it. Without the marker the system
     * prompt is not cached, which is simply the previous behaviour.
     */
    public const CACHE_MARKER = "\n\n<<<LARAGENTS_CACHE_BREAKPOINT>>>\n\n";

    /** The Messages API REQUIRES max_tokens, so there has to be one. */
    private const DEFAULT_MAX_TOKENS = 2000;

    public function __construct(
        protected UsageRecorder $usage,
        protected ?string $apiKey = null,
        protected string $baseUrl = 'https://api.anthropic.com/v1',
        protected string $version = '2023-06-01',
        protected bool $promptCaching = true,
        protected int $timeout = 120,
    ) {
        $this->apiKey ??= config('laragents.anthropic.api_key');
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
            throw new \RuntimeException('Anthropic API key not configured.');
        }

        if ($owner && ! $this->usage->hasCredits($owner)) {
            Log::warning('laragents: credits exhausted, request not sent', [
                'owner_id' => $owner->getKey(),
                'operation' => $operation,
                'model' => $model,
            ]);

            throw new PlanCreditsExhausted($owner->getKey(), $operation);
        }

        [$system, $anthropicMessages] = $this->translateMessages($messages);

        // Incremental caching of the CONVERSATION: a breakpoint on the last message makes
        // the next turn of the tool loop read the whole preceding prefix (accumulated tool
        // results) from cache and only process the new turn. Without it the context is
        // reprocessed in full every turn.
        if ($this->promptCaching) {
            $this->cacheLastMessage($anthropicMessages);
        }

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens ?? self::DEFAULT_MAX_TOKENS,
            'messages' => $anthropicMessages,
        ];

        // Per-model, not per-provider. See ModelCapabilities: Opus 4.8 rejects it and
        // Sonnet 4.6 accepts it, measured.
        if ($temperature !== null && ModelCapabilities::supportsTemperature($model)) {
            $body['temperature'] = $temperature;
        }

        $systemCached = false;

        if ($system !== '') {
            if ($this->promptCaching && str_contains($system, self::CACHE_MARKER)) {
                // The breakpoint on the last system block caches the TOOLS too, since
                // they are rendered before the system prompt.
                [$stable, $volatile] = explode(self::CACHE_MARKER, $system, 2);

                $blocks = [[
                    'type' => 'text',
                    'text' => $stable,
                    'cache_control' => ['type' => 'ephemeral'],
                ]];

                if (trim($volatile) !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $volatile];
                }

                $body['system'] = $blocks;
                $systemCached = true;
            } else {
                $body['system'] = str_replace(self::CACHE_MARKER, "\n\n", $system);
            }
        }

        if (! empty($tools)) {
            $translatedTools = $this->translateTools($tools);

            // No marker in the system prompt? Then cache at least the tool block, which
            // is a big stable chunk rendered first. cache_control on the last tool caches
            // the whole array.
            if ($this->promptCaching && ! $systemCached && ! empty($translatedTools)) {
                $lastKey = array_key_last($translatedTools);
                $translatedTools[$lastKey]['cache_control'] = ['type' => 'ephemeral'];
            }

            $body['tools'] = $translatedTools;
            // OpenAI 'auto'|'required' -> Anthropic {type: auto|any}
            $body['tool_choice'] = ['type' => $toolChoice === 'required' ? 'any' : 'auto'];
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->version,
            'content-type' => 'application/json',
        ])->timeout($this->timeout)->post($this->baseUrl . '/messages', $body);

        if ($response->status() === 429) {
            throw new ProviderRateLimited('Anthropic');
        }

        if (! $response->successful()) {
            Log::error('Anthropic API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('Anthropic API error: ' . $response->status());
        }

        $data = $response->json();

        // Content blocks: text + tool_use -> a content string + tool calls in OpenAI shape.
        $content = '';
        $toolCalls = [];

        foreach ($data['content'] ?? [] as $block) {
            $type = $block['type'] ?? '';

            if ($type === 'text') {
                $content .= $block['text'] ?? '';
            } elseif ($type === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'] ?? '',
                    'type' => 'function',
                    'function' => [
                        'name' => $block['name'] ?? '',
                        'arguments' => json_encode($block['input'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE),
                    ],
                ];
            }
        }

        $usage = $data['usage'] ?? [];
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);
        $cacheCreation = (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? 0);

        // Effective input at full price: uncached input costs 1x, a cache write 1.25x and
        // a cache read 0.1x. Recording this rather than the raw count is what makes the
        // saving from caching show up in your accounting.
        $effectiveInput = (int) round($inputTokens + $cacheCreation * 1.25 + $cacheRead * 0.1);

        $stopReason = $data['stop_reason'] ?? '';

        // Anthropic spells truncation 'max_tokens' where OpenAI says 'length'. It matters
        // more here: this client ALWAYS sends a cap, because the API demands one.
        if ($stopReason === 'max_tokens') {
            Log::warning('Anthropic: reply truncated by the token cap', [
                'operation' => $operation,
                'model' => $model,
                'max_tokens' => $maxTokens ?? self::DEFAULT_MAX_TOKENS,
                'content_empty' => $content === '',
            ]);
        }

        $result = new Response(
            content: $content,
            inputTokens: $effectiveInput,
            outputTokens: $outputTokens,
            model: $data['model'] ?? $model,
            toolCalls: $toolCalls,
            finishReason: $stopReason,
            cacheCreationTokens: $cacheCreation,
            cacheReadTokens: $cacheRead,
        );

        if ($owner) {
            $this->usage->record($owner, $actor, $operation, $model, $effectiveInput, $outputTokens, $subject);
        }

        return $result;
    }

    /**
     * Marks the LAST message with an ephemeral cache_control, so Anthropic caches the
     * whole prefix: tools, system prompt and the conversation up to that point.
     *
     * cache_control lives on a content BLOCK, so a plain string has to become
     * [{type: text}] first. This adds one breakpoint, two counting the system one, and
     * Anthropic allows four.
     */
    private function cacheLastMessage(array &$messages): void
    {
        if (empty($messages)) {
            return;
        }

        $i = array_key_last($messages);
        $content = $messages[$i]['content'] ?? null;

        if (is_string($content) && $content !== '') {
            $messages[$i]['content'] = [[
                'type' => 'text',
                'text' => $content,
                'cache_control' => ['type' => 'ephemeral'],
            ]];
        } elseif (is_array($content) && ! empty($content)) {
            $block = array_key_last($content);
            $messages[$i]['content'][$block]['cache_control'] = ['type' => 'ephemeral'];
        }
    }

    /**
     * OpenAI message shape -> Anthropic.
     *
     *   system                     -> the top-level 'system' parameter (concatenated)
     *   user/assistant text        -> {role, content: string}
     *   assistant with tool_calls  -> {role: assistant, content: [text?, tool_use...]}
     *   tool results               -> {role: user, content: [tool_result...]}
     *
     * Consecutive tool results are merged into a SINGLE user turn, which Anthropic
     * requires: sending them as separate turns is rejected.
     *
     * @return array{0: string, 1: array}
     */
    protected function translateMessages(array $messages): array
    {
        $system = '';
        $out = [];
        $pendingToolResults = [];

        $flush = function () use (&$out, &$pendingToolResults) {
            if (! empty($pendingToolResults)) {
                $out[] = ['role' => 'user', 'content' => $pendingToolResults];
                $pendingToolResults = [];
            }
        };

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';

            if ($role === 'system') {
                $flush();
                $text = is_string($msg['content'] ?? '') ? $msg['content'] : '';
                $system .= ($system !== '' ? "\n\n" : '') . $text;
                continue;
            }

            if ($role === 'tool') {
                $pendingToolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $msg['tool_call_id'] ?? '',
                    'content' => is_string($msg['content'] ?? '')
                        ? $msg['content']
                        : json_encode($msg['content'] ?? null, JSON_UNESCAPED_UNICODE),
                ];
                continue;
            }

            $flush();

            if ($role === 'assistant') {
                $blocks = [];
                $text = $msg['content'] ?? null;

                if (is_string($text) && $text !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $text];
                }

                foreach ($msg['tool_calls'] ?? [] as $call) {
                    $args = $call['function']['arguments'] ?? '{}';
                    $input = is_string($args) ? (json_decode($args, true) ?? []) : (array) $args;

                    $blocks[] = [
                        'type' => 'tool_use',
                        'id' => $call['id'] ?? '',
                        'name' => $call['function']['name'] ?? '',
                        'input' => empty($input) ? new \stdClass() : $input,
                    ];
                }

                // An assistant turn with no blocks at all is rejected.
                if (empty($blocks)) {
                    $blocks[] = ['type' => 'text', 'text' => '...'];
                }

                $out[] = ['role' => 'assistant', 'content' => $blocks];
                continue;
            }

            $text = $msg['content'] ?? '';
            $out[] = [
                'role' => 'user',
                'content' => is_string($text) ? $text : json_encode($text, JSON_UNESCAPED_UNICODE),
            ];
        }

        $flush();

        return [$system, $out];
    }

    /**
     * OpenAI tool shape ({type: function, function: {name, description, parameters}})
     * -> Anthropic ({name, description, input_schema}).
     */
    protected function translateTools(array $tools): array
    {
        $out = [];

        foreach ($tools as $tool) {
            $fn = $tool['function'] ?? $tool;

            $out[] = [
                'name' => $fn['name'] ?? '',
                'description' => $fn['description'] ?? '',
                'input_schema' => $fn['parameters'] ?? new \stdClass(),
            ];
        }

        return $out;
    }
}
