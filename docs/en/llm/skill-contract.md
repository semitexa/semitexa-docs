---
id: llm/skill-contract
section: llm
slug: skill-contract
title: AsAiSkill Contract
summary: What a class must satisfy to be discovered and invoked as an AI skill.
order: 50
locale: en
status: canonical
keywords:
  - AsAiSkill
  - skill
  - contract
---

# AsAiSkill Contract

platform-ui integrates with `semitexa/llm` via the **tool-use model**: commands annotated with `#[AsAiSkill]` are automatically discoverable by the Planner (`semitexa/llm/src/Planner/`) and executable by the `SkillExecutor`. Users interact in prose ("create a skin about sunset at sea"); the LLM routes to `skins:generate`.

## Skills shipped in v1

Inspect the live registry:
```bash
bin/semitexa ai:skills --json
```

### `skins:generate`

- **riskLevel**: `Low`
- **confirmation**: `WhenMutating` (asks before `--write`)
- **supportsDryRun**: `true`
- **argumentPolicy**: `Allowlisted` — `algorithm`, `hex`, `prompt`, `name`, `mode`, `write`
- **executionKind**: `DirectCommand`

### `skins:explain-prompt`

- **riskLevel**: `Low`
- **confirmation**: `Never` (read-only)
- **supportsDryRun**: `false`
- **argumentPolicy**: `Allowlisted` — `prompt` (required)

### `skins:refine` — not a skill

Refinement is an operator tool, not a Planner route. `skin:refine` has no `#[AsAiSkill]` attribute, so prose like "make it darker" reaches `skin:generate` (fresh regeneration from prompt) rather than `skin:refine`. Add the attribute in a consumer project if you want LLM-driven refinement routing.

## Adding a skill to a consumer project

Any Console command can become a skill. Annotate with `#[AsAiSkill]` and the registry picks it up.

```php
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Policy\{AiRiskLevel, AiConfirmationMode, AiArgumentPolicy, AiExecutionKind};

#[AsCommand(name: 'my-app:report:generate', description: '...')]
#[AsAiSkill(
    summary: 'Generate a PDF report for a given period.',
    useWhen: 'user asks for a report, PDF export, period summary',
    avoidWhen: 'user just wants to preview data — use my-app:report:show',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::WhenMutating,
    supportsDryRun: true,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['period', 'format', 'output'],
    executionKind: AiExecutionKind::DirectCommand,
    // Without this the skill is CLI-only — see Channels below.
    channels: ['console', 'web'],
)]
final class GenerateReportCommand extends Command { /* ... */ }
```

## Channels — which surfaces a skill appears on

`channels` says where a skill exists. It is not decoration: `SkillManifest::forChannels()`
is what a planner is shown, so a skill on the wrong channel is a skill the assistant will
never propose, and `SkillExecutor` re-checks the channel at run time and refuses a skill
that was not exposed there.

**The default is `['console']`, and that is the thing that catches people.** A skill that
declares nothing is a CLI skill: the OS console will not see it, and neither will a bot.

| Channel | Who asks for it |
|---|---|
| `console` | the CLI assistant (`bin/semitexa ai`) |
| `web` | HTTP surfaces, and the OS console — `SkillLoopRunner` asks for `web` + `ui` |
| `ui` | the dialog handler; see the overload below |
| `telegram` | a Telegram bot |

A skill may declare several. `content-list` ships as
`channels: ['console', 'telegram', 'web']` so the same listing answers on the CLI, in a bot
and in the OS console.

### The `ui` overload

`ui` does not mean "has a web page". It means **this skill opens a window instead of
running**. A `ui` skill needs a `name` and an `entry` route, does not implement
`InvocableSkillInterface`, and never executes — the OS raises its `entry` as a dialog in
Focus. Its declared inputs ride the entry as query parameters, so a UI skill can be opened
at a particular record:

```php
#[AsAiSkill(
    name: 'Content',
    summary: 'Open a page of the site for editing.',
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['name', 'ref'],
    channels: ['ui'],
    entry: '/os/app/cms',
)]
final class ContentEditorSkill {}
```

Only inputs the skill declares reach the URL; anything else a planner proposes is dropped.
Because it opens a window, a `ui` skill has no meaning on `telegram` — a bot has nowhere to
raise it.

### Channels from the environment

`channels` accepts a string as well as a list, so one variable can carry the whole set —
useful when a project decides which surfaces a skill belongs on:

```php
channels: 'env::MY_APP_SKILL_CHANNELS::console,web'   // whole list from one var
channels: ['console', 'env::MY_APP_EXTRA_CHANNEL::']  // one entry from a var
```

Both forms are split on commas and de-duplicated after resolving; an entry that resolves to
nothing drops out.

**A skill must resolve to at least one channel.** An empty result is rejected rather than
accepted, because a skill on no surface sits in the manifest and can never be returned from
it — invisible everywhere, with no signal anywhere, and an unset variable with no default is
one typo away. To turn a skill off, say so: `allowed: false`.

## Internal LLM consumption (not skill-based)

When a platform-ui command needs to call the LLM **inside** its own logic (not via Planner), it depends on `LlmProviderInterface` directly:

```php
public function __construct(private readonly LlmProviderInterface $provider) {
    parent::__construct();
}
```

`PromptResolver` in platform-ui uses exactly this pattern — the `--prompt` mode of `skin:generate` is a normal CLI invocation that reaches into the LLM internally, not a Planner decision.

## Reproducibility guarantee

Every LLM-generated skin includes its full provenance in `skin.json`:

```json
{
  "source": "prompt",
  "prompt": "...",
  "llm": {
    "skill": "platform-ui.skin.resolve-prompt",
    "skill_version": "1.0",
    "model": "gemma4:e2b",
    "attempts": 1,
    "rationale": "..."
  },
  "algorithm": "brutalist",
  "seed": "#d93025",
  "knobs": { "shadow_offset": "pronounced", "contrast_boost": "high", "shadow_color_mode": "brand" },
  "mode": "light",
  "tokens": { /* ... */ }
}
```

Any LLM-generated skin can be re-generated offline via `skin:generate <algorithm> "<resolved.seed>" --knob=…` without touching an LLM. The LLM is a UX layer, never a load-bearing dependency. `history[]` on the manifest records every generate + refine event on the skin — see [skin-refinement.md](skin-refinement.md).
