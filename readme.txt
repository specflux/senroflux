=== SenroFlux ===
Contributors: specflux, stephen1204paul
Tags: ai, agents, automation, safety, approvals
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Resumable multi-step agent runs inside the logged-in WordPress session, with Abilities as tools and Agent Safety governing every call.

== Description ==

SenroFlux runs an agent loop on behalf of a logged-in user: one goal, many
model turns, many tool calls — pausing for a human whenever Agent Safety
demands an approval, whenever the model needs to ask a clarifying question,
or before its first side-effecting write — and resuming exactly where it
left off.

* **Runs are session-bound.** A run acts as the user who started it; nothing
  runs in the background.
* **Tools are WordPress Abilities**, restricted per run to an allow-list
  supplied directly by the consumer, or derived from a registered **pack**
  (a named tool surface, e.g. the bundled pages pack).
* **Agent Safety is a hard dependency.** Every tool call passes through its
  gate (packs, tiers, approvals) and audit trail. Without Agent Safety,
  SenroFlux refuses to start any run.
* **Three kinds of park.** An **approval park** (a Tier-2 tool call Agent
  Safety blocked), a **question park** (the model asks the user one
  structured question), and a **plan park** (the model proposes a numbered
  plan before its first write; once accepted, that plan's tool set is a
  fence — calls outside it are refused until a new plan is accepted).
* **Budgets bound every run.** Steps, tool calls, tokens, questions and
  plans. A consumer may only lower a registered ceiling; running out of
  questions is not a failure — the model loses the ability to ask and
  proceeds on stated assumptions.
* **Pages pack (bundled).** Five polyfill Abilities (`senroflux/read-content`,
  `create-post`, `update-post`, `get-preview-url`, `list-patterns`) let a run
  compose a page from a curated block-pattern vocabulary, create it as a
  draft, and publish only through a gated status transition. Every write is
  validated before anything is persisted: unrenderable or non-round-tripping
  markup, blocks outside the `core/*` + vocabulary set, disallowed tags or
  attributes, unresolved `{{placeholder}}` text, an unrecognised pattern
  shape, a slot count out of range, or a page-shape rule (e.g. hero must
  come first) — all refuse the whole write and persist nothing.

= External Services =

SenroFlux itself makes no external requests directly. Model calls are made
through the WordPress AI Client (bundled with WordPress 7.0+), using
whichever provider your site has connected under Settings → Connectors —
commonly OpenAI, but any provider the AI Client supports.

On every model turn (at most one per tick), SenroFlux sends that provider:

* the run's full conversation history so far (the goal, every model
  message, and every tool result already produced in the run);
* a system instruction assembled from the harness's own operating rules,
  the active pack's skills (e.g. the pages pack's block-pattern vocabulary
  and copy constraints), and anything the consumer or `senroflux_system_instruction`
  filter contributes;
* the declared tool (Ability) schemas the run is allowed to call, and the
  results those tools return, which are fed back to the model on the next
  turn (capped at 32 KB per result by default, filterable via
  `senroflux_tool_result_max_bytes`).

No file, database or site content is sent beyond what a run's own tool
calls read and return as results. Requests happen only while a human is
actively driving a run from their own browser session (SenroFlux never runs
in the background). Sending and handling this data is governed by the
connected provider's own terms; see, for example, OpenAI's:
https://openai.com/policies/row-terms-of-use/ and
https://openai.com/policies/row-privacy-policy/ — consult your specific
provider's terms if you have connected a different one.

== Installation ==

1. Install and activate **Agent Safety** first (required).
2. Install and activate SenroFlux.
3. Consumers (e.g. marketing-analytics-chat) detect `senroflux()` and offer
   multi-step runs automatically.

== Changelog ==

= 0.2.0 =
* Schema v2: run status/step-kind enums, `resume` object replaces the
  0.1 `approval_action` parameter (breaking), `max_questions`/`max_plans`
  budget keys.
* Default budget ceilings raised to `max_steps` 60, `max_tool_calls` 30 and
  `max_tokens` 250000 — a live pages run costs 31-45 steps and up to 90k
  tokens once the model retries a refused write, and a consumer may only
  lower a ceiling.
* Question parks (`senroflux/ask-user`) and plan parks
  (`senroflux/propose-plan`) with an accept/veto fence around Tier ≥ 1
  writes.
* Packs: a named tool surface a run may start from (`pack` argument to
  `start()`), plus the bundled pages pack and its five polyfill Abilities.
* Skills and a rendered system-instruction pipeline
  (`senroflux_run_skills` filter), with a per-run disable list
  (`skills_disable`).
* Verification read-back and a per-run report surfaced on completion.
* Runs screen: new-run form with preflight, three park cards, polling
  detail view.

= 0.1.0 =
* Initial scaffold: hard-dependency gate against Agent Safety.
