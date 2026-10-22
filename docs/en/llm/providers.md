---
id: llm/providers
section: llm
slug: providers
title: Providers & Backends
summary: Provider contracts, backend resolution, reserve models that answer when the first one fails, named routes that put several models to work at once, and the environment knobs that shape them.
order: 20
locale: en
status: canonical
verified_against: 2026.09.19.1020
keywords:
  - LlmProviderInterface
  - LlmProviderResolver
  - FailoverProvider
  - LLM_FALLBACK
  - LLM_ROUTE
  - local Ollama
  - remote Ollama
---
# Providers & Backends

The provider layer isolates the assistant from any one backend. `semitexa/llm` talks to an `LlmProviderInterface`; `LlmProviderResolver` decides which backend answers: local Ollama, remote Ollama, Gemini or Claude. One application can use several of them at once, each feature on the models it needs, with reserve models behind them that answer when the first one cannot.

## How it works

Inject `LlmProviderResolver` and ask it for a provider:

```php
$this->llm->provider()->complete(new LlmRequest($system, $message));
```

| Call | Answers with |
|---|---|
| `provider()` | `LLM_BACKEND`, one model |
| `provider(LlmBackend::Anthropic)` | that backend, one model |
| `deciderProvider()` | the same on its decider model |
| `route('chat')` | the models of route `chat`, best first, each a reserve for the one before |
| `deciderRoute('chat')` | route `chat` on its decider models |

`provider()` stays one model on purpose: its callers tune it by its class (`withLimits()` on a `GeminiProvider`, tools only when it is one). Reserves come with a route.

The **decider** is the cheaper model that silently picks a tool or classifies; the provider's own model writes the reply. Its model is `LLM_DECIDER_MODEL_<BACKEND>` (`LLM_DECIDER_MODEL_ANTHROPIC=claude-haiku-5-5`), which also wins over a model a route names for that backend. The plain `LLM_DECIDER_MODEL` applies to the `LLM_BACKEND` provider only, since it names a model of that provider. Gemini and Claude can switch models; the Ollama providers always use their own, so a model named for them in a route is ignored.

Every provider speaks HTTP through the framework's client (`HttpClientInterface`): a failed connection or a timeout, a 429, a 500, 502, 503, 504 or 529 (overloaded) is tried again up to the provider's retry count (`LLM_RETRIES`, `LLM_REMOTE_OLLAMA_RETRIES`, `GEMINI_RETRIES`, `ANTHROPIC_RETRIES`), 0.5 s, 1 s, 1.5 s apart, a `Retry-After` honoured up to 5 s; a 4xx, a TLS failure and any other status are not. A timeout of `0` means one hour, not none. The connect timeout (`*_CONNECT_TIMEOUT`, 5 s by default) is kept apart from the generation timeout, so an endpoint that has gone away costs seconds, not minutes.

### Several models at once, with reserves: routes

A route is a name a feature asks for; the operator decides what serves it.

```dotenv
LLM_ROUTE_CHAT=anthropic:claude-opus-5-5,gemini
LLM_DECIDER_MODEL_ANTHROPIC=claude-haiku-5-5
```

```php
$reply = $this->llm->route('chat')->complete($request);           // Opus, then Gemini
$pick  = $this->llm->deciderRoute('chat')->complete($decision);   // Haiku, then Gemini's decider
```

Each member is `<backend>` or `<backend>:<model>`; a name `LlmBackend` does not know is left out, not read as `local`, and logged as `llm.route_unknown_backend` (once per worker). One model at one address is asked once, also when it is named twice or as `gemini` beside `gemini:<its default model>`. A route nobody configured is `LLM_BACKEND` with the `LLM_FALLBACK` reserves behind it:

```dotenv
LLM_BACKEND=anthropic
LLM_FALLBACK=gemini,local
```

so a feature can ask for its route before anyone sets one, and gets Claude, then Gemini, then local Ollama. Every member but the last gives up at its first failure instead of retrying, so the next one is asked at once.

A conversation turn that asks twice (the decider, then the reply) reads the route once and builds both from it, so a setting changed mid-turn cannot split the turn across two sets of models:

```php
$specs    = $this->llm->routeSpecs('chat');                  // ['anthropic', 'gemini']
$decider  = $this->llm->fromMembers($specs, decider: true);
$narrator = $this->llm->fromMembers($specs);
$tools    = $this->llm->membersSupportTools($specs);
```

To decide a route while the application runs (an administrator's setting rather than the env), bind an `LlmRouteOverrideInterface`:

```php
#[SatisfiesServiceContract(of: LlmRouteOverrideInterface::class)]
final class BrainSetting implements LlmRouteOverrideInterface
{
    public function members(string $route): ?array
    {
        return $route === 'chat' && $this->chosen() === 'claude' ? ['anthropic', 'gemini'] : null;
    }
}
```

It is asked on every `route()`, `deciderRoute()` and `routeSpecs()` call, before `LLM_ROUTE_<NAME>`; `null` leaves the route to its env, and so does an exception (logged as `llm.route_override_failed`): a setting that cannot be read does not silence the feature. Keep it cheap.

### What a failover does

- **The answer names who gave it.** `LlmResponse::$servedBy` is `<provider>:<model>` (`anthropic:claude-opus-5-5`); `name()` and `model()` of a route name its first choice. A route of one model is that provider itself, which sets no `servedBy`: log `$response->servedBy ?? $provider->name() . ':' . $provider->model()`.
- **Failures have a kind.** `LlmResponse::$failure` is an `LlmFailure`: `Unconfigured`, `Unauthorized`, `NoCredit`, `RateLimited`, `QuotaExhausted` (a daily quota), `Timeout`, `Network`, `ServerError`, `BadRequest`, `Refused` or `Malformed`. The HTTP status decides first; only a 400 or 403 is read further, from the API's own error fields. Every failure moves on to the next model, a refusal too: a model that declined is not asked to decide for the others. When all fail, the last failure comes back, its `error` listing every model's reason.
- **What will repeat is set aside.** No key, a refused key, no credit, a reached spend cap or a used-up daily quota (`LlmFailure::isLasting()`) sets that model aside for `LLM_BENCH_SECONDS` (300 by default, `0` never). A model that does not answer at all (a timeout, an unreachable host) is set aside for `LLM_BENCH_TRANSIENT_SECONDS` (30 by default), so the next calls do not each wait out the same timeout; a timeout under a caller's own `withLimits()` sets nothing aside, since it was that caller's budget. Gemini's `FAILED_PRECONDITION` (billing not enabled, a region not served) counts as `Unconfigured`, and so does a remote Ollama with no `LLM_REMOTE_OLLAMA_URL`. A member that throws instead of answering counts as `Malformed`, and the next one is asked. While a worker drains, a call that got no answer (a timeout, a network failure) is returned as it is: the drain cancelled it, so nothing is set aside and no reserve is asked. A 429 per minute or a 5xx is not set aside: the next call may pass. A provider of your own that sets no `failure` is never set aside.
- **One list for every worker.** Under the server the set-aside list is shared; `LlmProviderResolver::resetBenches()` called in a request (after a key or credit is fixed) asks every model again in every worker. Called from a console command it clears only that command's process.
- **When every model is set aside, all are asked anyway**, in order.
- **A request with tools goes only to models that take tools** (Gemini, Claude): a text-only model would answer with text the caller reads as a tool call. `routeSupportsTools('chat')` is true only when every model of the route takes tools; otherwise ask for JSON in text, and every reserve can answer.
- **Each failure is logged** as `llm.failover` (from, failure, error, seconds set aside); an answer after a failover as `llm.failover.served`.
- **The time adds up.** A reserve is asked only after the model before it gave up, its timeout included. For a live chat, keep the first model's timeout short.

A `FailoverProvider` can also be built by hand: `new FailoverProvider([$claude, $gemini], $health, $logger)`. Its `withLimits()` reaches every member that takes limits, with the retries given to the last member only, so the others still hand over at their first failure; its `withModel()` switches the first choice only.

### Configuration

| Key | Default | Meaning |
|---|---|---|
| `LLM_BACKEND` | `local` | `local`, `remote_ollama`, `gemini` or `anthropic` |
| `LLM_FALLBACK` | empty | reserves behind `LLM_BACKEND` on a route nobody configured, in order |
| `LLM_ROUTE_<NAME>` | `LLM_BACKEND` + reserves | the models of route `<name>`, best first |
| `LLM_DECIDER_MODEL_<BACKEND>` | the backend's model | the decider model of that backend |
| `LLM_DECIDER_MODEL` | none | the decider model of `LLM_BACKEND` |
| `LLM_BENCH_SECONDS` | `300` | how long a model that will keep failing is set aside |
| `LLM_BENCH_TRANSIENT_SECONDS` | `30` | how long a model that did not answer at all is set aside |
| `GEMINI_API_KEY`, `ANTHROPIC_API_KEY` | empty | a backend with no key fails as `Unconfigured` and is set aside |

### Testing

`LlmProviderResolver::withProvider($scripted)` answers every `provider()`, `route()`, `fromMembers()` and decider call with one provider, so no test reaches a network. `ScriptedProvider` takes replies as text, or as `LlmResponse` objects to script a failure (`LlmResponse::failed('HTTP 402', LlmFailure::NoCredit)`) or a tool call, and can be given a name and model to stand in for one member of a route.

## Why this matters

This keeps the assistant runtime swappable without changing the planner or executor, and it keeps an assistant answering when one vendor is down or out of credit. The command surface does not need to care which backend actually answered: the response says so.
