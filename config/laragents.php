<?php

/*
| Every value here is a literal. env() is called in THIS file and nowhere else in the
| package, because with config:cache Laravel skips loading the .env entirely and an
| env() call outside a config file quietly returns its default from then on. Also: a
| key that sits under a name like 'limits' but limits nothing is a trap for whoever
| reads it next, so do not add knobs here until something turns them.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Chat
    |--------------------------------------------------------------------------
    | No model names live here on purpose. Each call declares the model it uses, at
    | the call site, as a required argument. Inferring it from config or from the
    | operation name is how a rename ends up changing what a feature costs.
    */

    'chat' => [
        // How many times the loop may come back with tool results before giving up.
        'max_tool_iterations' => 7,

        // Tool results longer than this are truncated before going back to the model.
        'max_tool_result_chars' => 16000,

        /*
         * Stop a model that keeps searching and finding nothing.
         *
         * An empty result makes a model reformulate and try again, which is right once
         * and a spiral after that: it will spend every iteration it has rather than say
         * there is nothing there. After this many consecutive empty results, the loop
         * adds a hint telling it to answer instead. 0 turns it off.
         */
        'empty_streak_after' => 2,

        /*
         * Which keys make a tool result a search. A result carrying any of these is
         * judged; one carrying none of them says nothing either way and does not count.
         * An error always counts as nothing found.
         */
        'result_keys' => ['items', 'results', 'matches', 'passages', 'data'],

        // Appended to the tool's own hint, never replacing it. `:count` is the streak.
        // Write it in the language your model answers in.
        'empty_streak_hint' => null,

        // Budget for the replayed history, in estimated tokens. Above it, the older
        // messages get compressed into a summary.
        'max_history_tokens' => 6000,

        // Messages never compressed, however long the conversation gets.
        'recent_messages_min' => 4,

        // Output cap per call, or null for none. Careful: on a reasoning model this
        // budget also covers the thinking you never see, so a number sized for the
        // visible answer can be spent entirely on it. ModelCapabilities drops the cap
        // for those models rather than let that happen.
        'max_response_tokens' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    | Keys only. Models are NOT configured here: every call names the model it uses,
    | at the call site, as a required argument.
    */

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),

        // Only read by OpenAiTranscribeClient, which nothing here calls: it is for an app
        // that records meetings or voice notes.
        'transcription_model' => 'whisper-1',

        // Audio has no tokens. Providers bill it by the minute, so a minute is metered
        // as if it were this many, and the rate table prices it under the model name
        // like anything else. A convention, so speech and text share one allowance.
        'transcription_tokens_per_minute' => 1000,

        // Speech and images, neither of which anything here calls. Both are billed by
        // something that is not a token (characters read out, images returned), so the
        // recorder is given that number and your rate table prices it under the model
        // name like anything else.
        'speech_model' => 'tts-1',
        'voice' => 'alloy',
        'image_model' => 'gpt-image-1',

        // What a cached input token costs, as a share of an uncached one.
        //
        // OpenAI caches long prefixes by itself and bills the reused part at a discount.
        // This is what turns that discount into your accounting, so a system prompt that
        // never changes stops being charged in full on every call.
        //
        // Not a fixed 0.1 like Anthropic's because OpenAI's share differs by model and
        // has changed more than once. Set it to what your model's page says. The default
        // errs high: charging a little above the invoice is a rounding error, charging
        // below it is a loss you only find on the bill.
        'cache_read_weight' => 0.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Failover
    |--------------------------------------------------------------------------
    | Where to go when a provider answers 429. A rate limit is the other side saying
    | "not now", and a conversation in front of a person does not get to wait.
    |
    | Empty means no failover, which is the default: failing over means the reply comes
    | from a model the caller did not choose, and that is a decision to make on purpose.
    |
    |     ['client' => AnthropicClient::class, 'model' => 'claude-sonnet-4-6'],
    |
    | Tried in order, and ONLY on a rate limit. A missing key or a malformed request is
    | not going to go better elsewhere, and retrying those everywhere turns one failure
    | into as many as you have providers, each one billed.
    */

    'failover' => [],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy
    |--------------------------------------------------------------------------
    | Replaces personal data with markers before the prompt leaves for the provider,
    | and puts the real values back when it returns. Requires edulazaro/laranon.
    |
    | Off by default because turning it on changes what the model sees, and therefore
    | what it answers. That is your call, not the package's.
    |
    | With it on and laranon missing, the loop THROWS rather than logging a warning.
    | An app that believes it anonymises and does not is worse than one that never
    | claimed to: nobody reads the warning, and the false confidence is the damage.
    */

    'privacy' => [
        'enabled' => false,

        /*
        | Entity types to leave in the clear. 'person' is the interesting one: in
        | gendered languages the first name carries the grammatical gender, and
        | stripping it leaves the model writing "el paciente ha sido informada".
        | Surnames, ID numbers, IBANs, phones and emails still get tokenised.
        |
        | Set to [] to tokenise everything.
        */
        'except' => ['person'],

        /*
        | Where the markers have to be swapped inside each message. Tool call
        | arguments are in there for a reason: they are restored BEFORE the tool
        | runs, so your tools query the database with real values. Miss this and
        | they search for "«AP_1»" and quietly find nothing.
        */
        'keys' => ['content', 'tool_calls.*.function.arguments'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage accounting
    |--------------------------------------------------------------------------
    | Your implementation of EduLazaro\Laragents\Contracts\UsageRecorder. Leave it null
    | and the package records nothing and never blocks a call, which is what you want
    | while you are still wiring it up.
    */

    /*
    |--------------------------------------------------------------------------
    | Memory distillation
    |--------------------------------------------------------------------------
    | What turns a conversation into durable memories. Run it from a queued job AFTER the
    | answer has gone out: it costs a model call, and charging the user's reply for the
    | privilege of remembering it is how a chat gets slow.
    |
    | `min_messages` is the floor below which it will not bother calling the model. The
    | cron that wraps up abandoned conversations should pass 1 instead: a conversation
    | that is over deserves distilling even if it was short, because there is no later.
    |
    | `prompt` replaces the packaged one wholesale. The default is English and generic and
    | knows nothing about your domain. Whatever you write must still return the same
    | shape, and must use "owner" and "subject" as the scope values, because those are
    | what the distiller knows how to resolve:
    |
    |   {"shared":   [{"scope": "owner|subject", "content": "...", "category": "..."}],
    |    "personal": [{"scope": "owner|subject", "content": "...", "category": "..."}]}
    */

    'memory' => [
        'model' => 'gpt-4o-mini',
        'min_messages' => 4,
        'existing_context' => 30,
        'lock_seconds' => 120,
        'prompt' => null,

        /*
        | Semantic recall. OFF by default: embedding costs money on every memory written,
        | and a package should not start spending it because you installed it.
        |
        | Off, memories are recalled by recency, which is a perfectly good answer at the
        | volumes one scope produces. On, Memory::search() ranks by cosine similarity and
        | falls back to recency whenever it cannot do better, so nothing breaks when the
        | provider is unreachable.
        |
        | Scoring is done in PHP over `candidate_pool` rows, so there is no extension and
        | no vector store to run. That pool is also the ceiling: past a few thousand
        | memories in one scope the answer is a vector index, not a bigger pool.
        */
        'embeddings' => false,
        'embedding_model' => 'text-embedding-3-small',
        'candidate_pool' => 200,

        /*
        | save_memory and search_memories, registered for you. Turn this off if you would
        | rather write your own, or if the model should not be able to remember on its
        | own initiative.
        |
        | save_memory is an action: it writes. search_memories only reads, and matters
        | because the prompt carries the most recent memories and nothing older, so
        | without it the model says it does not know rather than going to look.
        */
        'tools' => true,

        /*
        | Whether what somebody says may end up in everyone else's memory.
        |
        | This is only the DEFAULT, for people who have never chosen. Put the
        | SharesMemories trait on whoever talks to the model and they get a column with
        | three states: null means they never decided and this value applies, and a stored
        | true or false is their decision and beats this for ever.
        |
        | The third state is what lets you change your mind about the default later
        | without silently overwriting people who had chosen the old one on purpose.
        |
        | Turning it off stops PRIVATE material reaching the shared pool from then on.
        | Their own memories keep being written, and nothing already stored is hidden or
        | removed: this is not a retraction.
        */
        'share_memories' => true,

        // Which table the published opt-out migration adds `share_memories` to. It is the
        // one migration in this package that touches a table the package does not own, so
        // it asks rather than guesses, and it skips a column that is already there.
        'actor_table' => 'users',

        /*
        | When memories get written.
        |
        | `distil_on_compress` is the main path: crossing the history budget means a lot
        | has been said, which is exactly when there is something worth keeping.
        |
        | `sweep` is the companion, every half hour, for conversations that stopped before
        | they ever grew big enough to be summarised. Without it the package remembers
        | long chats and forgets short ones, which is a strange thing to explain.
        |
        | `queue` should not be the one your chat replies on. Distilling costs a model
        | call and is never the urgent part.
        */
        'distil_on_compress' => true,
        'sweep' => true,
        'inactive_after_minutes' => 30,
        'queue' => 'default',
    ],

    /*
    |--------------------------------------------------------------------------
    | Agents
    |--------------------------------------------------------------------------
    | `schedule` registers the every-minute tick that runs agents whose cron expression
    | has come round, plus a five-minute sweep that fails tasks whose worker died. Turn it
    | off only if you would rather register those commands yourself.
    |
    | Every minute is not expensive: one query for active agents with a schedule, and an
    | expression evaluated per row. It costs something only on the minutes work is due.
    |
    | `debounce_seconds` swallows a repeat of the same event on the same subject.
    | Observers fire on every save, and one logical change saved in three statements is
    | three events: without this the agent answers three times and is billed three times.
    |
    | `queue` should be a queue of its own with a worker of its own. An agent run is a
    | loop of model calls and can take minutes; sharing a queue with anything interactive
    | means one run holds a worker while a person waits.
    */

    'agents' => [
        /*
        | The value of agents.status that means "may run". Your app writes that column,
        | the package only reads it, so it is configurable rather than assumed: an app
        | that says 'enabled' or 'live' would otherwise get no agents firing at all, with
        | no error and nothing in the log to explain it.
        */
        'active_status' => 'active',

        'schedule' => true,
        'queue' => 'default',
        'debounce_seconds' => 30,
        'stale_after_minutes' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Event triggers
    |--------------------------------------------------------------------------
    | The bridge between your events and your agents, and the piece that is easy to leave
    | out and impossible to notice missing. Your agent form offers "when a document is
    | uploaded", somebody picks it, and unless something listens for that event and calls
    | the trigger, the agent never runs. No error, no log, no complaint.
    |
    | Map an event class to an invokable class that places it:
    |
    |     'events' => [
    |         \EduLazaro\Laracrate\Events\FileProcessed::class => \App\Laragents\FileUploaded::class,
    |     ],
    |
    |     class FileUploaded
    |     {
    |         public function __invoke($event): ?array
    |         {
    |             return [
    |                 'event' => 'file.extracted',
    |                 'tenant' => $event->file->organization,
    |                 'subject' => $event->file->fileable,
    |                 'context' => ['file_id' => $event->file->id, 'file_name' => $event->file->name],
    |             ];
    |         }
    |     }
    |
    | Return null and nothing fires, which is the right answer for an upload that belongs
    | to no tenant.
    |
    | A class and not a closure because config gets cached and a cached closure is a fatal
    | error. And a class and not a convention because only your app knows how to get an
    | organisation out of a file.
    |
    | For anything you already have a hook for, skip all this and call it directly:
    |
    |     app(AgentTrigger::class)->fire('reply.created', $org, $case, [...]);
    */

    'events' => [],

    /*
    |--------------------------------------------------------------------------
    | Rules
    |--------------------------------------------------------------------------
    | How a rule is announced to the model. Shared rules are rendered before personal
    | ones and worded as mandatory, so a user preference can never quietly override a
    | firm policy.
    |
    | :scope is replaced with the morph alias of what the rule hangs off, so keep your
    | morph map readable: it ends up in the prompt.
    |
    | The defaults are blunt on purpose, because a rule the model reads as a suggestion
    | is not a rule. They are also English and generic, and yours are probably neither.
    |
    | For per-scope wording, add a key of the form 'everyone.case' or 'actor.organization'
    | and it wins over the template for that scope.
    */

    'rules' => [
        'headings' => [
            // 'everyone' => 'MANDATORY INSTRUCTIONS FOR THIS :scope (do NOT skip them under any circumstance):',
            // 'actor' => 'PREFERENCES OF THE CURRENT USER IN THIS :scope (respect them unless they contradict the mandatory instructions):',
        ],
    ],

    'usage_recorder' => null,

];
