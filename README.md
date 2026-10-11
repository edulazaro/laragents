![Laragents](art/banner.png)

# Laragents - AI agents for Laravel

<p align="center">
    <a href="https://github.com/edulazaro/laragents/actions/workflows/tests.yml"><img src="https://github.com/edulazaro/laragents/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
    <a href="https://packagist.org/packages/edulazaro/laragents"><img src="https://img.shields.io/packagist/v/edulazaro/laragents" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/edulazaro/laragents"><img src="https://img.shields.io/packagist/dt/edulazaro/laragents" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/edulazaro/laragents"><img src="https://img.shields.io/packagist/php-v/edulazaro/laragents" alt="PHP Version"></a>
    <a href="https://github.com/edulazaro/laragents/blob/main/LICENSE.md"><img src="https://img.shields.io/packagist/l/edulazaro/laragents" alt="License"></a>
</p>

Chat sessions with automatic history compression, a provider-agnostic tool-calling loop,
memories distilled from what was said, hard rules written by a person, and agents that run
on a schedule or on your own events.

Bring your own tools, your own models and your own credit system. The package owns the
parts that are the same in every project and expensive to get right; it stays out of the
parts that are yours.

> **Status: early.** Ported from a production app but not yet running from here. The
> contracts are settled; expect the surface around them to move.

## Names

Every table names the thing it is about with an `-able`, and everything else says what it
is. Read this once and the rest of the package follows.

| Column | Holds | In a law firm |
|---|---|---|
| `chat_sessions.tenant` | whose conversation this is, and who the usage is charged to | the firm |
| `chat_sessions.actor` | who is talking | the lawyer |
| `chat_sessions.sessionable` | what the conversation is about, or null | the case |
| `memories.tenant` | whose memory it is | the firm |
| `memories.memorable` | what it is about, or null for the tenant as a whole | the case |
| `memories.memory` | who it applies to, or null for everyone in that scope | the lawyer, a team |
| `rules.tenant` | whose rule it is | the firm |
| `rules.memorable` | what it is about, or null for the tenant as a whole | the case |
| `rules.memory` | who it applies to, or null for everyone in that scope | the lawyer |
| `agents.tenant` | whose agent it is | the firm |
| `agent_tasks.taskable` | what the run is about, or null | the case |

All of them are polymorphic, so each is two columns (`_type` and `_id`) and the package
never learns what they point at.

Three things follow from that table and are worth stating outright:

**`tenant` and `sessionable` are not the same axis.** One is who pays, the other is what
it is about. A background job holding only a session id still has to know who to bill, and
the package cannot walk from a case to the firm that owns it. Two scopes is also what lets
a conversation about one case produce a memory about the whole firm.

**Values in `_type` columns are YOUR morph aliases, never the package's words.** A scope
called `matter` is addressed as `"matter"`. The distiller reads the valid values off the
session and offers them to the model; nothing has to be translated and nothing is dropped
for failing to translate.

**`memories.memory` is not who wrote it.** That is `created_by`, and which agent produced
it is `agent_id`. This column is only who it belongs to.

## What it does, and what it refuses to do

**It does:** the agentic loop (call, run tools, feed results back, repeat, with a cap),
history compression when the conversation outgrows its token budget, normalising two
provider dialects into one response shape, and asking your app whether there is credit
before spending any.

**It does not:** know what a case, a property or an office is; own your tables; decide
which model you use; or contain a single tool. Those are yours.

## Three decisions worth knowing up front

**The model is a required argument.** Not a default, not config, not inferred. In the app
this came from, the model used to be derived from the prefix of the operation name, so
renaming a string silently changed which model ran and what it cost. A required argument
has no default to fall into.

**Tools receive a `ToolContext`, not your models.** The predecessor declared
`execute(array $args, ?CaseModel $case, Organization $org)`: two app models nailed into an
interface implemented by 95 tools, so adding a third piece of context meant editing all 95.
And no package can host that signature, because one app's context is a case and another's
is a property or nothing.

**Two kinds of "out of credit", two exceptions.** A provider 429 is temporary and should be
retried; an exhausted plan is not and should not. One exception for both leaves the catcher
unable to tell them apart and the user reading the wrong message half the time.

## What is in it

    AgentLoop                  call, run tools, feed results back, repeat, with a cap
    HistoryCompressor          summarises old turns once the replay outgrows its budget
    MemoryDistiller            turns a finished exchange into memories
    AgentRunner                runs one task: prompt, loop, record what happened
    AgentTrigger               what makes an agent run: an event, or the clock
    ModelCapabilities          which model accepts what, measured against the live APIs

    Clients\OpenAiClient       chat completions, tool calling
    Clients\AnthropicClient    the same interface, translating both dialects
    Clients\FailoverClient     one provider, then the next, on a rate limit
    Clients\OpenAiEmbedClient  optional, for semantic recall

    Tools\Tool                 extend it, write two methods
    Tools\ToolRegistry         registration and tag filtering
    Tools\ToolContext          whatever your tools need, carried opaquely
    Tools\SaveMemoryTool       lets the model remember on purpose
    Tools\SearchMemoriesTool   lets it look past what is already in the prompt

    Models\ChatSession         owner (tenant), actor (who talks), subject (optional frame)
    Models\ChatMessage         one turn, including the tool plumbing
    Models\Memory              what to remember between conversations
    Models\Rule                hard directives, rendered at the top of the prompt
    Models\Agent               a standing instruction to run the loop
    Models\AgentTask           one run: why it fired, what came back
    Models\AgentLog            what it did, step by step
    Jobs\RunAgentTask          one queued run

    Contracts\Redactor         optional PII redaction, via edulazaro/laranon
    Concerns\ResolvesRedactor  whether a session gets redacted, in one place
    Capabilities\Capability    things the provider does on its own side
## Memory

Three axes, and keeping them apart is what makes it reusable:

    tenant      whose it is: the firm, the workspace, the account
    memorable   what it is about: a case, a project. NULL = the tenant as a whole,
                which is where "we bill fortnightly" belongs
    memory      who it applies to. NULL = everyone in that scope; set = only that
                thing, usually a person, though a team or a role works the same

`tenant` is the one that has to be right. The other two decide what the model is told and
when; this one decides whose data it is at all. Two tenants on one install share the morph
aliases and can share the ids behind them, so a row without a tenant is reachable from the
next tenant's conversation. Everything the package writes fills it in from the session.

The `memory` axis is **applicability, not secrecy**, and the distinction decides how you
treat it. Showing one person's memory to another is not a leak, it is worse: the model
starts behaving with Juan as if he were María. "María prefers bullet points" is not
confidential, it simply does not apply to anyone else.

Which is why no memory has a person as its scope. A personal preference is a memory of the
**organisation** that applies to that person, so the same person carries different
preferences in different organisations instead of dragging one set everywhere.

`Memory::promptBlock($session)` renders the block for a system prompt, grouped so shared
and personal stay visibly apart. Selection is by recency: semantic retrieval would need an
embedding provider, and at the volumes one scope produces, the newest N is hard to beat.

### Filling it

`MemoryDistiller` reads what has been said since last time and writes the memories.
Without it the table gets read into every prompt and nothing ever puts a row in it.

    dispatch(function () use ($session) {
        app(MemoryDistiller::class)->distil($session);
    })->afterResponse();

It runs on its own, two ways, and you should know which is which.

**When a conversation is compressed.** Crossing the history budget means a lot has been
said, which is exactly when there is something worth keeping, so `HistoryCompressor`
queues a distillation on the way past. This is the main path.

**Every half hour, for conversations that stopped.** Most never grow big enough to be
summarised: three useful exchanges and then silence. Without the sweep the package would
remember long chats and forget short ones, which is a strange thing to explain. Those pass
a floor of 1, because a conversation that is over deserves distilling even if it was brief.

Both go through a queue, and it should not be the one your chat replies on: distilling
costs a model call and is never the urgent part. To run it yourself:

    dispatch(new DistilMemories($session->id));

It asks for two independent lists. **shared** is objective fact useful to anyone, written
impersonally; **personal** is what matters to the one person, in their own voice. The
prompt spends most of its length on the rule that keeps them apart: nothing that mentions
the user, their doubts or their gaps may appear in shared, and anything doubtful goes in
personal or nowhere.

Two fields on the session keep it honest. `distilled_until_id` is where to resume;
`last_distilled_at` is whether there is any point. Both are written even when nothing was
kept, including after a failure, because retrying a window that just failed usually fails
again and every attempt is paid for. A cron that wakes up abandoned conversations passes
`minMessages: 1`, since a conversation that is over deserves distilling even if it was
short.

The packaged prompt is English and knows nothing about your domain. Replace it wholesale
with `laragents.memory.prompt`; it just has to return the same shape, and its `memorable`
values are read off the session, so they are your morph aliases and not ours.

### Recalling it

By default, recall is by recency: the newest N memories in scope go into every prompt.
That is a good answer at the volumes one scope produces, and it costs nothing.

Turn on `laragents.memory.embeddings` and `Memory::search()` ranks by cosine similarity
instead, which is what `search_memories` uses when the model needs something older than
what it is already carrying. It is **off by default because embedding costs money on every
memory written**, and a package should not start spending it because you installed it.

`Embedder` is a separate contract from `ChatClient` on purpose. Anthropic does not do
embeddings, so a chat client forced to carry `embed()` would have one implementation that
only ever throws.

Everything degrades rather than breaks: no embedder, embeddings off, provider unreachable,
a memory written before you turned it on. Each of those falls back to recency or sorts
last, because losing a memory to a rate limit is the worse trade.

Scoring happens in PHP over a bounded pool, so there is no extension and no vector store
to run. **That pool is a real ceiling.** Past a few thousand memories in one scope the
answer is a vector index, not a bigger pool.

### Two tools, registered for you

    save_memory        the model remembers something on purpose
    search_memories    it looks past what is already in the prompt

They come registered, because a tool that ships in a package and is never handed to the
model is the same nothing as one that was never written. `laragents.memory.tools` turns
them off if you would rather write your own.

`save_memory` is an action: it writes, so it is the sort of thing you may want to confirm.
It refuses to save a personal memory when nobody is at the other end, rather than falling
back to shared, because that would turn one person's preference into everyone's and the
model would act on it for the next person who walks in.

`search_memories` matters because the prompt carries the most recent memories and nothing
older. Without it the model says it does not know instead of going to look. Its results
say whether each memory applies to everyone or only to the person it is talking to.

Both find their conversation through the tool context, which `AgentLoop` fills in for
every run, so they work in an agent run and in an interactive chat without the caller
arranging anything.

### Letting people opt out

Put `SharesMemories` on whoever talks to the model and they get a say in whether what they
say reaches everyone else's memory.

    php artisan vendor:publish --tag=laragents-optout-migration

    class User extends Authenticatable
    {
        use EduLazaro\Laragents\Concerns\SharesMemories;
    }

**Three states, and the third is the point.** Null means this person never chose, so
`laragents.memory.share_memories` applies. A stored true or false is a decision, and it
beats the default for ever. Without that third state you could never change your mind
about the default without silently overwriting the people who had picked the old one on
purpose.

It gates only what is written from here on, and only the shared half: their own memories
keep being written either way, and nothing already stored is hidden or removed. Turning it
off is not a retraction.

An actor with no say on the matter, or no actor at all, shares. A package should not switch
off a feature nobody asked it to switch off.

## Rules

The hard half. A memory is distilled and weighed; a **rule** is written by a person, never
distilled, and rendered at the top of the prompt in imperative language. "The firm never
quotes fees over chat" is a rule. "This client tends to reply late" is a memory.

Same two axes as a memory, and here the second one is also the precedence:

    Rule::promptBlock($session)

Shared rules render first and worded as mandatory, personal ones after and worded as
preferences, never interleaved. Flatten them together and the model loses the only signal
separating firm policy from one person's taste, and starts trading one off against the
other. Within each block, `priority` ascending, ties in the order they were written.

`enabled` turns a rule off without deleting it, so a firm can pause one for a week and put
it back without retyping it. The wording of the headings is yours, in
`laragents.rules.headings`; the defaults are blunt on purpose, because a rule the model
reads as a suggestion is not a rule.

## Making agents run

Seven tables, one migration. A session and a memory both carry `agent_id` as a real
foreign key, **null on delete**: retiring an agent must not take people's conversations
with it, and what it learned outlives it. That is the reason the agent tables are not
published separately. A split saved three empty tables for apps that only wanted a chat,
and cost the constraint, since a session cannot point at an agent whose table may not
exist.


An agent that nothing fires is furniture. This is the part that is easy to leave out and
impossible to notice missing: the form offers "when a document is uploaded", somebody
picks it, and with nothing listening the agent never runs. No error, no log, no complaint
until somebody asks why nothing was triaged.

**By the clock.** Installing the package is enough: the service provider registers an
every-minute tick that dispatches a task for every agent whose cron expression has come
round, plus a five-minute sweep that fails tasks whose worker died mid-run. Set
`laragents.agents.schedule` to false to register those yourself.

Dueness is measured **from the last run, not against the current minute**. `isDue(now())`
is true only during the exact minute the expression names, so a tick lost to a restart, an
overlapping run or a busy queue takes that execution with it, silently and for ever.
Asking whether the next run after the last one has already passed means a missed minute is
picked up on the following tick.

**By an event.** Map your event to a class that places it:

    'events' => [
        \App\Events\FileProcessed::class => \App\Laragents\FileUploaded::class,
    ],

    class FileUploaded
    {
        public function __invoke($event): ?array
        {
            return [
                'event' => 'file.extracted',
                'tenant' => $event->file->organization,
                'subject' => $event->file->case,
                'context' => ['file_id' => $event->file->id],
            ];
        }
    }

Return null and nothing fires, which is the right answer for an upload belonging to no
tenant. A class and not a closure because config gets cached and a cached closure is a
fatal error; a class and not a convention because only your app knows how to get an
organisation out of a file.

Where you already have a hook, skip the mapping and call it:

    app(AgentTrigger::class)->fire('reply.created', $org, $case, [...]);

The same event on the same subject within `debounce_seconds` runs once. Observers fire on
every save, and one logical change written in three statements is three events: without
that, the agent answers three times and is billed for all three.

Put agent runs on a queue of their own with a worker of their own. A run is a loop of
model calls and can take minutes; sharing a queue with anything interactive means one run
holds a worker while a person waits.

## Privacy

Off by default. Turn on `laragents.privacy.enabled` and personal data is replaced with
markers before the prompt leaves, and put back when it returns.

The ordering is the part worth knowing, because getting it wrong fails quietly. A COPY of
the prompt is tokenised, so the stored conversation stays in the clear and nothing
anonymised is ever persisted. The reply is restored before it is shown or saved. And tool
call arguments are restored BEFORE the tools run, so your tools query the database with
real values rather than searching for `«AP_1»` and finding nothing.

`person` is left in the clear by default. In gendered languages the first name carries the
grammatical gender, and stripping it leaves the model writing "el paciente ha sido
informada". Surnames, ID numbers, IBANs, phones and emails are still tokenised. Set
`privacy.except` to `[]` to redact everything.

Each session carries an `anonymize_pii` flag, on by default, so a user can opt one
conversation out without changing the app-wide setting.

**All three callers redact, not just the chat.** Three things here send a conversation to
a model: the turn, `HistoryCompressor` summarising the older turns, and `MemoryDistiller`
extracting what is worth remembering. The last two are the ones that get missed and the
worse leak: summarising sends the whole transcript again, with a prompt that asks the
model to keep names, figures and dates, and distilling sends the widest prompt in the
package, the new turns plus the summary plus every memory already stored, in one call. A
chat that redacts turn by turn and then hands the lot over the moment the history outgrows
its budget is a promise broken rather than a promise never made, and it has no symptom.

Which is why the decision of whether a session gets redacted lives in
`Concerns\ResolvesRedactor` rather than inside the loop. It was a private method on
`AgentLoop` until 1.1, and the other two did not redact for the plainest reason there is:
there was nowhere to get the answer from.

**With the option on and laranon missing, it throws.** Not a warning: an app that
believes it anonymises and does not is worse than one that never claimed to, and a log
line nobody reads is exactly how that goes unnoticed.

## Metering what it spends

The clients report tokens and nothing else. What a token costs, and whether an account may
spend more, is not this package's business.

**The short way**, if you use edulazaro/larameter:

    'usage_recorder' => 'larameter',

That is the whole wiring. Tokens become credits at your rates, and a call is refused before
it leaves once the plan runs out.

**Your own tables**: implement `Contracts\UsageRecorder` and name the class instead. Leave
it null and nothing is recorded and nothing is blocked, which is what you want while you
are still wiring it up.

### What a cached prefix costs

Both providers reuse a long, unchanged prefix across calls and bill the reused part at a
discount. A system prompt and a tool list that never change are the whole prefix of every
call you make, so on a chat that is most of the input.

What gets recorded is what the call **actually cost**, not the raw prompt count. Recording
the raw number is how the discount goes missing: the provider bills the cheap figure, the
caller is charged the expensive one, and the only visible symptom is an allowance that
runs out faster than the invoice says it should.

**Anthropic** has to be asked. The client marks the system prompt and the last tool with
`cache_control`, then weights what comes back: a write at 1.25 and a read at 0.1, which
are Anthropic's published multipliers.

**OpenAI** does it by itself. Anything over roughly a thousand tokens is cached with
nothing to send and nothing to opt into, and the reused count arrives in
`prompt_tokens_details.cached_tokens`. The share is not fixed across the catalogue, so it
is yours to set:

    'openai' => ['cache_read_weight' => 0.5],

The default errs high on purpose. Overstating the price of a cache read charges a rounding
error above the invoice; understating it charges below what you pay, and that one only
turns up on the bill, months later.

## Wiring your accounting

Implement `Contracts\UsageRecorder` over whatever tables you already have and point the
config at it. Leave it null and the package records nothing and blocks nothing, which is
what you want while you are still wiring it up.

`hasCredits()` is a **net, not the front door**. Keep checking quota in your UI and jobs
too, where you can stop before assembling an expensive prompt, apply the right threshold
for that operation, and tell the user something useful. What the net catches is the caller
who forgets — which is exactly how an unmetered path ships unnoticed.

## Agents

An agent is a chat session with nobody at the other end. Same loop, same clients, same
tools, same memory, same accounting — which is why they live here rather than in a package
of their own. The app this came from had a separate executor for them, a near-copy of the
chat one, and the copy had drifted: it never got the PII redaction the chat had.

Their tables publish under their own tag, so an app that only wants an interactive chat
does not end up with three empty tables:

    php artisan vendor:publish --tag=laragents-migrations   all seven tables

Scheduling is yours. The package holds the agent, the run and the log; when to fire them
depends on your queue and your clock, and a package that assumes either is a package that
breaks on someone else's setup.

## Answers in a shape

Pass a `schema` and the answer comes back in that shape, guaranteed by the provider
rather than asked for in the prompt. OpenAI does it with `response_format`; Anthropic
has no such thing, so it declares one tool whose input schema IS the shape and leaves
the model no choice but to call it.

Strict mode is on unless you say otherwise, and it has two requirements that are easy
to miss: every object needs `additionalProperties: false`, and **it has no optional
properties** — every property goes in `required`, and the ones that may be absent are
made nullable instead.

```php
schema: [
    'name' => 'classification',
    'schema' => [
        'type' => 'object',
        'properties' => [
            'category' => ['type' => 'string'],
            'note'     => ['type' => ['string', 'null']],
        ],
        'required' => ['category', 'note'],
        'additionalProperties' => false,
    ],
]
```

A schema that does not meet them is refused before the call, naming the exact path:

```
Strict schema at `schema.properties.findings.items` leaves out of `required`: score.
```

Both clients check the same thing, even though only one of the two APIs would have
complained. Otherwise a schema written against Claude works until the day somebody
switches provider, and then it breaks in production with a 400. Pass `strict: false`
in the schema to call without any of this.

## Picking a provider

Resolving the contract gives you whatever is bound, which is OpenAI unless you said
otherwise. Which provider a given conversation uses is a runtime decision, so ask
for one by name:

```php
Laragents::chat('anthropic')->chat($messages, 'claude-opus-5-5', owner: $firm);

if (! Laragents::hasKey('anthropic')) {
    // fall back, or tell them to configure it
}

Laragents::available();   // ['openai', 'anthropic'], minus any without a key
```

There is no per-provider default model on purpose: `$model` is required on `chat()`
for the reason the contract spells out, and a default is something you fall into
without deciding.

## Testing against it

`FakeChatClient` puts itself in the container, answers what you queued and keeps
every call, so nothing reaches a provider and nothing is billed:

```php
$chat = FakeChatClient::bind()->reply(['category' => 'invoice']);

$this->action->run($document);

$this->assertSame('claude-opus-5-5', $chat->lastCall()['model']);
$this->assertStringContainsString('Acme', $chat->lastPrompt());
```

`reply()` queues one answer per call and `always()` answers everything; a string is
the content, an array is encoded as JSON, a `Response` is used as given. It checks
the schema the same way the real clients do, so a test cannot pass on a schema the
provider would reject.

## Configuration

`config/laragents.php` holds literals. `env()` is called in that file and nowhere else in
the package: with `config:cache` Laravel skips loading `.env` entirely, so an `env()` call
outside a config file quietly returns its default from then on.

## Sponsors

Laragents is supported by the following sponsors. Thank you for keeping it growing:

<p>
  <a href="https://kenodo.com"><img src="art/logo-kenodo.png" width="24" alt="Kenodo"></a>&nbsp;<a href="https://kenodo.com">Kenodo</a>&nbsp;&nbsp;&nbsp;&nbsp;
  <a href="https://andorradev.com"><img src="art/logo-andorradev.png" width="24" alt="AndorraDev"></a>&nbsp;<a href="https://andorradev.com">AndorraDev</a>
</p>

## Author

Created by [Edu Lazaro](https://edulazaro.com)

## License

Laragents is open-sourced software licensed under the [MIT license](LICENSE.md).
