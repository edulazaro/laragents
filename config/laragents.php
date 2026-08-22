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

    'usage_recorder' => null,

];
