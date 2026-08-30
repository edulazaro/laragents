# CLAUDE.md

Guidance for working in this repository.

## What this is

**Laragents** (`edulazaro/laragents`) is a Laravel package for AI agents: chat
sessions with automatic history compression, a provider-agnostic tool-calling
loop, memories distilled from what was said, and agents that run on a schedule or
on host events. Published on Packagist as a library — **there is no application
here**, only `src/` (the package) and `tests/`.

- PHP `>=8.2`, Laravel `>=12.0`.
- PSR-4: `EduLazaro\Laragents\` → `src/`, `EduLazaro\Laragents\Tests\` → `tests/`.
- Auto-discovered provider: `EduLazaro\Laragents\LaragentsServiceProvider`.
- Optional companions: `edulazaro/laranon` (privacy redaction) and
  `edulazaro/larameter` (usage metering, via `laragents.usage_recorder`).

## Critical project rules

> These come from accumulated project memory. Treat them as hard constraints.

- **The existing tests are an untouchable non-regression baseline.** Never edit an
  existing test to make a change pass — if a change breaks a test, the change is
  wrong, not the test. Add new tests for new behaviour.
- **Model names with dots must never be read through `config()` dot notation.**
  `config('laragents...gpt-5.6-terra')` splits at the dot and silently falls
  through to the `*` fallback. Rate tables are indexed directly
  (`$rates[$operation]`), and any new lookup must do the same. Getting this wrong
  undercharges and only shows up on the provider's bill.
- **Tools that ship with the package must be registered.** A tool nobody hands to
  the model is the same nothing as one that was never written.

## Commands

```bash
composer install                 # install deps (incl. orchestra/testbench)
vendor/bin/phpunit               # run the full suite
vendor/bin/phpunit --filter Memory   # run a subset by name
```

Tests run on Testbench (`phpunit.xml`).

## Architecture

Top-level classes in `src/`:

- `AgentLoop` — the tool-calling loop. Provider-agnostic: takes messages, a model
  name, a session, a `ToolContext` and tool definitions.
- `HistoryCompressor` — builds the replayed history and summarises what does not
  fit the token budget (`laragents.chat.max_history_tokens`).
- `MemoryDistiller` — turns a finished exchange into memories. Fires on compression
  (`memory.distil_on_compress`) and from the scheduled sweep.
- `AgentRunner` / `AgentTrigger` — stored agents that run on cron or host events.
- `ModelCapabilities`, `Response`, `Laragents` (the facade-ish entry point).

Subdirectories: `Clients` (OpenAI, Anthropic, failover), `Models` (`ChatSession`,
`ChatMessage`, `Memory`, `Agent`), `Tools`, `Skills`, `Privacy`, `Usage`,
`Console`, `Jobs`, `Events`, `Contracts`, `Capabilities`, `Concerns`, `Attributes`.

### Memory scoping

`Memory::scopedTo($memorables, $context, $tenant)` filters by `tenant_type` /
`tenant_id`, so memories are per tenant, not per session. A memory with a null
`memorable_id` is "about the tenant as a whole".

`Memory::promptBlock($session)` returns the newest N by recency (not semantic
search). `Memory::search()` is the semantic path and only uses embeddings when
`memory.embeddings` is on and an `Embedder` is bound; otherwise it degrades to
recency, which the caller cannot detect except in quality.

## Known gaps

> Things noticed in real integrations that are not bugs, but cost the host app
> something. Written down so the next person does not rediscover them.

### `save_memory` asks for a scope the package never offers

`SaveMemoryTool` requires a `memorable` argument and its description tells the
model to "use one of the values offered to you in this conversation". Those values
come from `scopes()`, which reads the morph aliases of `$session->sessionable` and
`$session->tenant`.

**But nothing in the package puts those values in front of the model.**
`Memory::promptBlock()` returns memories, not scopes. So unless the host app
happens to work this out and injects the alias into its own system prompt, the
model guesses, gets back `['error' => ..., 'valid_values' => [...]]`, and corrects
on the *next* turn.

It self-heals, which is why it goes unnoticed, but it costs an extra model call
per memory written, and a host app has no way of knowing that is what is happening.

Found while integrating into Tarotian (`/code/tarotian`), whose tenant alias is
`user`; the workaround there is a line in the app's own system prompt naming the
alias, derived from `$session->tenant?->getMorphClass()` so it cannot go stale.

Possible fixes, in order of how little they change:

1. `Memory::scopeBlock(ChatSession $session): string` — a sibling of
   `promptBlock()` that renders the valid `memorable` values, for the host to
   append to its prompt. Smallest change, still opt-in, still easy to miss.
2. Have `promptBlock()` include the scope line itself. Nothing to remember, but it
   changes what an existing method returns.
3. Put the valid values in the tool's own `description()` at registration time.
   Cleanest from the model's point of view, but the registry builds the schema
   once with no conversation in hand — which is exactly why `parameters()` has no
   enum today, and the reason is documented in the tool.

## Conventions

- Comments explain **why**, not what. A comment that restates the code is noise;
  one that records the reason a non-obvious choice was made is the point.
- Errors returned from tools are data the model reads and acts on, not exceptions.
  Include what a correct value would have been (`valid_values`) whenever possible.
- Defaults must fail safe. `save_memory` defaults `everyone` to false because
  shared-unless-told turns one person's preference into everyone's.
