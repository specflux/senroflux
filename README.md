# SenroFlux

Agent runs for WordPress. SenroFlux runs a resumable, multi-step agent loop inside the
logged-in WordPress session: Abilities are the tools, the WordPress AI Client is the model
layer, and [Agent Safety](https://github.com/stephen1204paul/agent-safety) is the checkpoint
that gates, approves, and audits every step.

First consumer: [Specflux Marketing Analytics Chat](https://wordpress.org/plugins/marketing-analytics-chat/).

## Requirements

- WordPress 7.0+ (Abilities API + AI Client)
- PHP 8.1+
- **Agent Safety** active — a hard dependency. Without it, SenroFlux wires nothing but an
  admin notice and refuses to start any run (`senroflux_ungoverned`). Fail closed.

## Consumers

Vendoring (the Agent Safety model — one codebase, two channels):

```sh
composer require specflux/senroflux
```

The consumer contract is non-negotiable on one point: **vendoring provides
classes, never ungoverned execution**. A vendored copy still requires the
Agent Safety plugin active on the site; without its gate every entry point
returns `senroflux_ungoverned`. Every vendoring consumer must also ship
[Jetpack Autoloader](https://github.com/Automattic/jetpack-autoloader) so the
highest-version copy wins across plugins (same arbitration rule Agent Safety
and mcp-adapter use).

## What a run is

One goal, pursued on behalf of one logged-in user, across many model turns and tool calls:

1. A consumer calls `senroflux()->start( $consumer, $goal, $allow )` with the Abilities the
   run may use (exact ids or globs).
2. The browser drives `senroflux()->tick( $run_id, $step_count )` — one tick equals at most
   one model turn plus that turn's tool calls.
3. Every tool call passes Agent Safety's gate first (packs → tiers → approvals). When the
   gate demands human approval, the run parks mid-message and returns an approval payload;
   after the human decides (inline via Agent Safety's approvals API, or on the Pending Agent
   Actions screen), the next tick resumes exactly where it stopped.
4. Budget ceilings (steps / tool calls / tokens, filterable via `senroflux_default_budget`)
   bound every run; exceeding one fails the run with `budget_exceeded`.

Runs are session-bound and never execute in the background. The Agent Safety audit chain —
not the steps table — is the authoritative record of what executed.

## PHP API

```php
if ( function_exists( 'senroflux' )
    && null !== senroflux()
    && senroflux()->available() ) {

    $state = senroflux()->start( 'my-consumer', 'Refresh the data', array( 'my-plugin/*' ) );

    // ... drive ticks from the browser ...
    $state = senroflux()->tick( $state['run']['id'], $state['run']['step_count'] );

    // Approvals (when Agent Safety >= 0.3 exposes its API):
    senroflux()->approvals()->approve( $approval_id, get_current_user_id() );
}
```

HTTP mirrors: admin-ajax `senroflux_start|tick|cancel|get` and REST
`senroflux/v1/runs[/{id}/(tick|cancel)]` — same payloads.

## Filters

| Filter | Purpose |
|---|---|
| `senroflux_default_budget` | Default per-run ceilings (`max_steps`, `max_tool_calls`, `max_tokens`). |
| `senroflux_system_instruction` | Replace the prompt-injection posture instruction. |
| `senroflux_tool_result_max_bytes` | Payload cap handed back to the model (default 32 KB). |
| `senroflux_can_tick` | Who may advance a run (defaults to owner-only). |
| `senroflux_runs_capability` | Capability for the Runs screen. |
| `senroflux_model_gateway` | Swap the model seam (testing/hosting edge cases). |

## Development

```sh
composer install
composer check   # phpcs (WordPress-Core + Extra) · phpstan L8 · phpunit
```

CI runs the same gates plus the WordPress Plugin Check suite.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
