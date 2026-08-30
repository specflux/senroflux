# SenroFlux

Agent runs for WordPress. SenroFlux runs a resumable, multi-step agent loop inside the
logged-in WordPress session: Abilities are the tools, the WordPress AI Client is the model
layer, and [Agent Safety](https://github.com/stephen1204paul/agent-safety) is the checkpoint
that gates, approves, and audits every step.

First consumer: [Specflux Marketing Analytics Chat](https://wordpress.org/plugins/marketing-analytics-chat/).

Current version: `0.2.0-dev` (see `Version:` in `senroflux.php`).

## Requirements

- WordPress 7.0+ (Abilities API + AI Client)
- PHP 8.1+
- **Agent Safety** active — a hard dependency. Without it, SenroFlux wires nothing but an
  admin notice and refuses to start any run (`senroflux_ungoverned`). Fail closed.

## Consumers

SenroFlux ships as a WordPress plugin only — there is no composer-vendorable
library channel. Consumers integrate by feature detection:

```php
if ( function_exists( 'senroflux' ) && senroflux()->available() ) {
    // multi-step runs are on
}
```

The plugin deliberately does not load `vendor/autoload.php` at runtime and ships
no Jetpack Autoloader manifests, so nothing in its dev dependencies can shadow
WordPress core's bundled AI Client SDK on a site where another plugin boots the
Jetpack Autoloader. Runs are governed by the Agent Safety plugin, which must be
active; without its gate every entry point returns `senroflux_ungoverned`.

## What a run is

One goal, pursued on behalf of one logged-in user, across many model turns and tool calls:

1. A consumer calls `senroflux()->start( $consumer, $goal, $allow, $budget, $pack, $skills_disable )`.
   Either supply `$allow` directly (exact Ability ids or globs), or supply `$pack` (a
   registered pack name, e.g. `'pages'`) and let the pack derive the allow-list — a
   caller-supplied `$allow` is ignored once a pack is given. `$skills_disable` drops
   non-required pack/consumer skills (harness skills are always required and cannot be
   dropped) from the rendered system instruction.
2. The browser drives `senroflux()->tick( $run_id, $step_count, $resume )` — one tick equals
   at most one model turn plus that turn's tool calls. `$resume` is `null` except when
   resolving a park (see below); its shape must match the run's current park kind or the
   call fails with `resume_mismatch`.
3. Every tool call passes Agent Safety's gate first (packs → tiers → approvals). A run can
   also stop mid-tick at three other points:
   - **Approval park** (`status = awaiting_approval`) — a Tier-2 call Agent Safety blocked;
     resolve with `tick( $id, $count, [ 'action' => 'approve' | 'reject' ] )`, or approve/reject
     directly via `agent_safety()->approvals()`.
   - **Question park** (`status = awaiting_user`) — the model called `senroflux/ask-user`;
     resolve with `[ 'answer' => [ 'text' => ..., 'choice' => ... ] ]` or `[ 'skip' => true ]`.
   - **Plan park** (`status = awaiting_plan`) — the model called `senroflux/propose-plan`;
     resolve with `[ 'plan' => [ 'action' => 'accept' | 'accept_preapprove' | 'veto', 'note' => '...' ] ]`.
     `accept_preapprove` only succeeds when the `senroflux_enable_preapproval` filter returns
     `true` and Agent Safety exposes a grants API; otherwise it 400s with `preapproval_disabled`.
     Once a plan is accepted, its verb set is a fence: a Tier ≥ 1 call outside it is refused
     (`not_in_plan`) without being counted against the tool-call budget, and a call made before
     any plan is accepted is refused with `plan_required`, also uncounted.
   Whichever park is active, the tick response carries exactly one `ui` key —
   `ui.approval`, `ui.question`, or `ui.plan` — until the run finishes, when it carries
   `ui.report` instead.
4. Budget ceilings — `max_steps`, `max_tool_calls`, `max_tokens`, `max_questions`, `max_plans`
   (filterable via `senroflux_default_budget`) — bound every run; exceeding steps/tool
   calls/tokens fails the run with `budget_exceeded`. Running out of questions or plans is not
   a failure: `senroflux/ask-user` is withdrawn from the model's tool declarations at 0
   remaining questions, and a run whose last plan was vetoed at `max_plans` cancels with
   `plan_rejected`.

Runs are session-bound and never execute in the background. The Agent Safety audit chain —
not the steps table — is the authoritative record of what executed.

## PHP API

```php
if ( function_exists( 'senroflux' )
    && null !== senroflux()
    && senroflux()->available() ) {

    $state = senroflux()->start( 'my-consumer', 'Refresh the data', array( 'my-plugin/*' ) );

    // ...or start from a registered pack instead of a direct allow-list:
    $state = senroflux()->start( 'my-consumer', 'Draft a landing page', array(), array(), 'pages' );

    // ... drive ticks from the browser ...
    $state = senroflux()->tick( $state['run']['id'], $state['run']['step_count'] );

    // Resolve a park (shape must match the current park kind):
    $state = senroflux()->tick( $run_id, $step_count, array( 'action' => 'approve' ) );

    // Approvals are Agent Safety's, not SenroFlux's:
    agent_safety()->approvals()->approve( $approval_id, get_current_user_id() );
}
```

`senroflux()->get( $run_id )` returns the run's full state — status, allow-list, budget,
`pack`, `conversation_locale`, `content_locale`, `report` (once terminal) — and its recorded
steps, for a consumer that wants to render its own history view instead of polling `tick()`.

HTTP mirrors: admin-ajax `senroflux_start|tick|cancel|get` and REST
`POST senroflux/v1/runs`, `POST …/{id}/tick`, `POST …/{id}/cancel`, `GET …/{id}` — same
payloads, except that HTTP `start` never
accepts `allow`: the tool surface for HTTP-started runs comes entirely from the
`senroflux_http_consumers` filter (see below), so the browser cannot widen it. Ajax passes
`resume` as a JSON-encoded string field; REST accepts it as a JSON object.

## Filters

| Filter | Purpose |
|---|---|
| `senroflux_default_budget` | Default per-run ceilings (`max_steps`, `max_tool_calls`, `max_tokens`, `max_questions`, `max_plans`). |
| `senroflux_http_consumers` | Registers consumers that may start runs over admin-ajax/REST: `[ 'my-plugin' => [ 'allow' => [...], 'budget' => [...] ] ]`. The request never supplies `allow`; its `budget` can only lower the registered ceiling. Unregistered consumers get 403. |
| `senroflux_run_skills` | `(list<Skill> $skills, ?Pack $pack, string $consumer, string $goal)` — add, remove or reorder the skills rendered into a run's system instruction. Required skills cannot be dropped. |
| `senroflux_system_instruction` | Post-process the fully rendered system-instruction string before it is sent to the model. |
| `senroflux_skills_max_tokens` | Ceiling (rough token estimate) on the rendered skills block; exceeding it fails `start()`/preflight with `skills_too_large`. |
| `senroflux_tool_result_max_bytes` | Payload cap handed back to the model per tool result (default 32 KB). |
| `senroflux_verb_map` | `(array $map, int $run_id)` — contributes to Agent Safety's verb classification for a run's calls. |
| `senroflux_can_tick` | `(bool $can, Run $run)` — who may advance a run (defaults to owner-only). |
| `senroflux_runs_capability` | Capability required to view/use the Runs screen (default `manage_options`). |
| `senroflux_enable_preapproval` | Off by default. When `true` (and Agent Safety exposes a grants API), a plan may be accepted with pre-approval, minting Agent Safety grants for its Tier-2 verbs. |
| `senroflux_packs` | Registers packs beyond the bundled pages pack. |
| `senroflux_model_gateway` | Swap the model seam (testing/hosting edge cases). |
| `senroflux_language_name` | `(array $names)` — display names used when telling the model the conversation/content locale. |

The bundled pages pack also registers itself with **Agent Safety**, via
`agent_safety_pack_registry`, `agent_safety_governed_namespaces` (adds its `senroflux/`
polyfill namespace) and `agent_safety_verb_map` (classifies its create/update/publish calls
by tier), and hooks `agent_safety_approval_summary` (`PublishSummary`) to render a
human-readable preview on the Agent Safety pending-approvals screen for its Tier-2 publish
calls.

## Pages pack refusal codes

Every pages-pack write (`senroflux/create-post`, `senroflux/update-post`) validates `content`
before anything is persisted; a failure refuses the whole write. Codes, in the order checked:
`invalid_markup` (fails `serialize_blocks(parse_blocks())` round-trip), `unknown_block`
(outside `core/*` + the pack's vocabulary), `disallowed_markup` (a tag/attribute outside the
pack's allow-list — the pack's own XSS gate, since these calls run as an administrator who
holds `unfiltered_html`), `unresolved_placeholder` (leftover `{{...}}` text),
`unknown_pattern` / `slot_count` / `page_shape` (structural pattern-identity checks), plus
`status_not_allowed` (create accepts only `draft`; update only `draft|pending|publish`) and
`not_found` (unknown post id, or a post type/id outside the pack's allow-list).

## Development

```sh
composer install
composer check   # phpcs (WordPress-Core + Extra) · phpstan L8 · phpunit
```

CI runs the same gates plus the WordPress Plugin Check suite.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
