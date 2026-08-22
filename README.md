# Laragents

Agentic LLM chat for Laravel: sessions, message history with automatic compression, a
provider-agnostic tool-calling loop, and pluggable usage accounting.

Bring your own tools, your own models and your own credit system. The package owns the
parts that are the same in every project and expensive to get right; it stays out of the
parts that are yours.

> **Status: early.** Ported from a production app but not yet running from here. The
> contracts are settled; expect the surface around them to move.

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

    AgentLoop               call, run tools, feed results back, repeat, with a cap
    HistoryCompressor       summarises old turns once the replay outgrows its budget
    Clients\OpenAiClient     chat completions, tool calling
    Clients\AnthropicClient  the same interface, translating both dialects
    Clients\ModelCapabilities which model accepts what, measured against the live APIs
    Tools\ToolRegistry       registration and tag filtering
    Tools\BaseTool           defaults for your own tools
    Tools\ToolContext        whatever your tools need, carried opaquely
    Models\ChatSession       owner (tenant), actor (who talks), subject (optional frame)
    Models\ChatMessage       one turn, including the tool plumbing
    Privacy\Redactor         optional PII redaction, via edulazaro/laranon

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

**With the option on and laranon missing, the loop throws.** Not a warning: an app that
believes it anonymises and does not is worse than one that never claimed to, and a log
line nobody reads is exactly how that goes unnoticed.

## Wiring your accounting

Implement `Contracts\UsageRecorder` over whatever tables you already have and point the
config at it. Leave it null and the package records nothing and blocks nothing, which is
what you want while you are still wiring it up.

`hasCredits()` is a **net, not the front door**. Keep checking quota in your UI and jobs
too, where you can stop before assembling an expensive prompt, apply the right threshold
for that operation, and tell the user something useful. What the net catches is the caller
who forgets — which is exactly how an unmetered path ships unnoticed.

## Configuration

`config/laragents.php` holds literals. `env()` is called in that file and nowhere else in
the package: with `config:cache` Laravel skips loading `.env` entirely, so an `env()` call
outside a config file quietly returns its default from then on.

## License

MIT.
