<?php

namespace EduLazaro\Laragents\Clients;

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

    /**
     * @param UsageRecorder $usage
     * @param ?string $apiKey
     * @param string $baseUrl
     * @param string $version
     * @param bool $promptCaching
     * @param int $timeout
     * @return void
     */
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
            throw new \RuntimeException('Anthropic API key not configured.');
        }

        if ($owner && ! $this->usage->hasCredits($owner)) {
            Log::warning('laragents: credits exhausted, request not sent', [
                'tenant_id' => $owner->getKey(),
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

        // Anthropic has no response_format. Its documented way to guarantee a shape is
        // to declare one tool whose input schema IS that shape and leave the model no
        // choice but to call it; the arguments then come back already validated.
        //
        // Which means a schema and a tool loop are mutually exclusive here: the model
        // is being forced into one call, so it cannot reach for anything else. The
        // caller asked for an answer in a shape, not for a conversation.
        if ($schema) {
            $name = $schema['name'] ?? 'response';

            $body['tools'] = [[
                'name' => $name,
                'description' => 'Return the answer in this shape.',
                'input_schema' => $schema['schema'] ?? $schema,
            ]];

            $body['tool_choice'] = ['type' => 'tool', 'name' => $name];
        }

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

        // Their own server tools: the model runs these on its side and you never see the
        // call. Versioned by date, and there is no file search, so asking for one drops
        // it rather than faking it.
        $native = Translator::forAnthropic($capabilities);

        if (! empty($tools) || $native !== []) {
            $translatedTools = [...$native, ...$this->translateTools($tools ?? [])];

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
        $reasoning = '';
        $citations = [];

        foreach ($data['content'] ?? [] as $block) {
            $type = $block['type'] ?? '';

            if ($type === 'text') {
                $content .= $block['text'] ?? '';

                // Only ever present when the model searched. One answering from memory
                // cites nothing, and one offering a citation unprompted invented it.
                foreach ($block['citations'] ?? [] as $citation) {
                    $citations[] = array_filter([
                        'title' => $citation['title'] ?? null,
                        'url' => $citation['url'] ?? null,
                        'text' => $citation['cited_text'] ?? null,
                    ]);
                }
            } elseif ($type === 'thinking' || $type === 'redacted_thinking') {
                // Extended thinking. Kept out of content: it is not the answer, and
                // showing it as if it were reads as the model talking to itself.
                //
                // A redacted block has been withheld by the provider and arrives with
                // no text, so an empty reasoning does not mean it did not think: it
                // means it did not say. The tokens were billed either way.
                $reasoning .= $block['thinking'] ?? '';
            } elseif ($type === 'tool_use' && $schema) {
                // The forced call IS the answer. Handed back as text, so a caller
                // decoding a schema-backed reply reads response->content either way,
                // whichever provider served it.
                $content = json_encode($block['input'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE);
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
            reasoning: $reasoning,
            citations: $citations,
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
