# Laragents

Agentic LLM chat for Laravel: sessions, message history with automatic compression, a
provider-agnostic tool-calling loop, and pluggable usage accounting.

Bring your own tools, your own models and your own credit system. The package owns the
parts that are the same in every project and expensive to get right; it stays out of the
parts that are yours.

> **Status: skeleton.** The contracts below are settled. The loop, the session models and
> the clients are being ported from a production app.

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
