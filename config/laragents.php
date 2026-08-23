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
    ],

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
    |                 'owner' => $event->file->organization,
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
    | For per-scope wording, add a key of the form 'shared.case' or 'personal.organization'
    | and it wins over the template for that scope.
    */

    'rules' => [
        'headings' => [
            // 'shared' => 'MANDATORY INSTRUCTIONS FOR THIS :scope (do NOT skip them under any circumstance):',
            // 'personal' => 'PREFERENCES OF THE CURRENT USER IN THIS :scope (respect them unless they contradict the mandatory instructions):',
        ],
    ],

    'usage_recorder' => null,

];
