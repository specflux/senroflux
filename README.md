# SenroFlux

Agent runs for WordPress. SenroFlux runs a resumable, multi-step agent loop inside the
logged-in WordPress session: Abilities are the tools, the WordPress AI Client is the model
layer, and every governed call is gated and approved by
[SenroGate](https://github.com/stephen1204paul/senrogate) when it's active, or by
SenroFlux's own built-in minimal gate when it isn't.

First consumer: [Specflux Marketing Analytics Chat](https://wordpress.org/plugins/specflux-marketing-analytics-chat/).

Current version: `0.3.0` (see `Version:` in `senroflux.php`).

## Requirements

- WordPress 7.0+ (Abilities API + AI Client)
- PHP 8.1+
- Not supported on multisite — the plugin refuses to activate.
- **Agent Safety** is optional. When active, every governed call goes through its gate
  (packs, tiers, approvals) and audit trail. When it isn't, SenroFlux falls back to a
  built-in minimal gate (0.3 S3): every call that changes the site still pauses for a
  person to approve it on SenroFlux's own Runs screen; reads do not. A run's gate mode is
  resolved once at `start()` and pinned — if the environment's mode changes mid-run
  (Agent Safety gets activated/deactivated), the run fails cleanly with
  `gate_mode_changed` instead of silently switching enforcement.

## Consumers

SenroFlux ships as a WordPress plugin only - there is no composer-vendorable
library channel. Consumers integrate by feature detection:

```php
if ( function_exists( 'senroflux' ) && senroflux()->available() ) {
    // multi-step runs are on
}
```

The plugin deliberately does not load `vendor/autoload.php` at runtime and ships
no Jetpack Autoloader manifests, so nothing in its dev dependencies can shadow
WordPress core's bundled AI Client SDK on a site where another plugin boots the
Jetpack Autoloader. `senroflux_ungoverned` is reserved for two narrower cases: no
WordPress session, and a third-party HTTP consumer starting or ticking a
built-in-mode run — not "Agent Safety is missing," which is now a supported
configuration (see Requirements above).

## Bundled packs

Four packs ship in 0.3, each a named tool surface a run may start from
(`pack` argument to `start()`):

- **pages** — create/update pages from a curated block-pattern vocabulary; publish is a
  separate, higher-tier step.
- **posts** — the same create/update/publish split for standard posts.
- **site** — navigation, front page selection, and homepage patterns.
- **commerce** — WooCommerce catalogue and order operations, when WooCommerce is active.

Across the content packs, `create-post` never publishes (it refuses a `publish`/`future`
status outright), `update-post` is draft-state edits only, and any transition to
`publish`/`future` — or any edit to an already-public post — goes through the separate
`publish-post` ability. `create-post` also refuses `slug_collision` (409) when a
non-trashed page or post of the same type already holds the requested slug, or matches the
title case-insensitively.

## What a run is

One goal, pursued on behalf of one logged-in user, across many model turns and tool calls:

1. A consumer calls `senroflux()->start( $consumer, $goal, $allow, $budget, $pack, $skills_disable )`.
   Either supply `$allow` directly (exact Ability ids or globs), or supply `$pack` (a
   registered pack name, e.g. `'pages'`) and let the pack derive the allow-list - a
   caller-supplied `$allow` is ignored once a pack is given. `$skills_disable` drops
   non-required pack/consumer skills (harness skills are always required and cannot be
   dropped) from the rendered system instruction.
2. The browser drives `senroflux()->tick( $run_id, $step_count, $resume )` - one tick equals
   at most one model turn plus that turn's tool calls. `$resume` is `null` except when
   resolving a park (see below); its shape must match the run's current park kind or the
   call fails with `resume_mismatch`.
3. Every tool call passes the run's gate first — Agent Safety's (packs → tiers →
   approvals) when it's active, or SenroFlux's own built-in minimal gate otherwise (see
   "Requirements" above). A run can also stop mid-tick at three other points:
   - **Approval park** (`status = awaiting_approval`) - a governed call the gate blocked;
     resolve with `tick( $id, $count, [ 'action' => 'approve' | 'reject' ] )`, or (in Agent
     Safety mode) approve/reject directly via `agent_safety()->approvals()`.
   - **Question park** (`status = awaiting_user`) - the model called `senroflux/ask-user`;
     resolve with `[ 'answer' => [ 'text' => ..., 'choice' => ... ] ]` or `[ 'skip' => true ]`.
   - **Plan park** (`status = awaiting_plan`) - the model called `senroflux/propose-plan`;
     resolve with `[ 'plan' => [ 'action' => 'accept' | 'accept_preapprove' | 'veto', 'note' => '...' ] ]`.
     `accept_preapprove` only succeeds when the `senroflux_enable_preapproval` filter returns
     `true` and Agent Safety exposes a grants API; otherwise it 400s with `preapproval_disabled`.
     Once a plan is accepted, its verb set is a fence: a Tier ≥ 1 call outside it is refused
     (`not_in_plan`) without being counted against the tool-call budget, and a call made before
     any plan is accepted is refused with `plan_required`, also uncounted.
   Whichever park is active, the tick response carries exactly one `ui` key -
   `ui.approval`, `ui.question`, or `ui.plan` - until the run finishes, when it carries
   `ui.report` instead.
4. Budget ceilings - `max_steps` (60), `max_tool_calls` (30), `max_tokens` (250000),
   `max_questions` (5), `max_plans` (3), filterable via `senroflux_default_budget` - bound
   every run; exceeding steps/tool calls/tokens fails the run with `budget_exceeded`.
   Running out of questions or plans is not a failure: `senroflux/ask-user` is withdrawn from the model's tool declarations at 0
   remaining questions, and a run whose last plan was vetoed at `max_plans` cancels with
   `plan_rejected`.

Runs are session-bound and never execute in the background. In Agent Safety mode, its audit
chain - not the steps table - is the authoritative record of what executed; in built-in mode
the steps table and run report are authoritative.

## External services

SenroFlux makes two kinds of outbound request, only while a human is actively driving a run:

- **Model calls**, via the WordPress AI Client, to whichever provider the site has connected
  under Settings → Connectors. Each model turn sends the run's conversation history so far,
  the rendered system instruction, the declared tool schemas the run may call, and prior tool
  results (capped at 32 KB each by default). Governed by the connected provider's own terms
  (e.g. OpenAI: https://openai.com/policies/row-terms-of-use/,
  https://openai.com/policies/row-privacy-policy/).
- **Stock photo search**, via Openverse's public API (`https://api.openverse.org/v1/images/`
  and its per-image detail endpoint), when a pages/site run searches for or fetches a stock
  image. Only the model-generated search text and a selected result's id are sent - no
  credentials, site content, or personal data. See
  https://docs.openverse.org/terms_of_service.html and https://openverse.org/privacy.

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

`senroflux()->get( $run_id )` returns the run's full state - status, allow-list, budget,
`pack`, `conversation_locale`, `content_locale`, `report` (once terminal) - and its recorded
steps, for a consumer that wants to render its own history view instead of polling `tick()`.

HTTP mirrors: admin-ajax `senroflux_start|tick|cancel|get` and REST
`POST senroflux/v1/runs`, `POST …/{id}/tick`, `POST …/{id}/cancel`, `GET …/{id}` - same
payloads, except that HTTP `start` never
accepts `allow`: the tool surface for HTTP-started runs comes entirely from the
`senroflux_http_consumers` filter (see below), so the browser cannot widen it. Ajax passes
`resume` as a JSON-encoded string field; REST accepts it as a JSON object.

## Filters

`@api`? marks the three filters declared part of the stable extension surface (S23) — see
[Extension API](#extension-api) below. Every other filter, including `senroflux_can_tick` and
`senroflux_http_consumers`, may change shape without a `SENROFLUX_API_VERSION` bump.

| Filter | `@api`? | Purpose |
|---|---|---|
| `senroflux_default_budget` | Yes | Default per-run ceilings: `max_steps` 60, `max_tool_calls` 30, `max_tokens` 250000, `max_questions` 5, `max_plans` 3. A consumer may only lower them. |
| `senroflux_http_consumers` | No (Consumer rules) | Registers consumers that may start runs over admin-ajax/REST: `[ 'my-plugin' => [ 'allow' => [...], 'budget' => [...] ] ]`. The request never supplies `allow`; its `budget` can only lower the registered ceiling. Unregistered consumers get 403. |
| `senroflux_run_skills` | Yes | `(list<Skill> $skills, ?Pack $pack, string $consumer, string $goal)` - add, remove or reorder the skills rendered into a run's system instruction. Required skills cannot be dropped. |
| `senroflux_system_instruction` | No | Post-process the fully rendered system-instruction string before it is sent to the model. |
| `senroflux_skills_max_tokens` | No | Ceiling (rough token estimate) on the rendered skills block; exceeding it fails `start()`/preflight with `skills_too_large`. |
| `senroflux_tool_result_max_bytes` | No | Payload cap handed back to the model per tool result (default 32 KB). |
| `senroflux_verb_map` | No | `(array $map, int $run_id)` - contributes to Agent Safety's verb classification for a run's calls. |
| `senroflux_can_tick` | No (Consumer rules) | `(bool $can, Run $run)` - who may advance a run (defaults to owner-only). |
| `senroflux_runs_capability` | No | Capability required to view/use the Runs screen (default `manage_options`). |
| `senroflux_enable_preapproval` | No | Off by default. When `true` (and Agent Safety exposes a grants API), a plan may be accepted with pre-approval, minting Agent Safety grants for its Tier-2 verbs. |
| `senroflux_packs` | Yes | Registers packs beyond the bundled pages pack. |
| `senroflux_model_gateway` | No | Swap the model seam (testing/hosting edge cases). |
| `senroflux_language_name` | No | `(array $names)` - display names used when telling the model the conversation/content locale. |
| `senroflux_theme_patterns` | No | `(array $eligible)` - the active theme's patterns the pages pack may build from, applied last. It can only remove: an entry not already eligible is ignored, and a non-array return leaves the list unchanged. The `senroflux_use_theme_patterns` option (Settings checkbox) empties the list before this runs. |
| `senroflux_layout_profiles` | No | `(array $profiles)` - layout profiles keyed by theme slug: which pattern builds each layout on that theme. Twenty Twenty-Five and Ollie ship built in; a theme without one is matched automatically or falls back to the curated layouts. |

## Extension API

`SENROFLUX_API_VERSION` (currently `0.3.0`, defined in `senroflux.php`) versions the DECLARED
extension surface below, independently of the plugin's own `Version:` header. **Semver
promise:** a removal or signature change to anything on this list needs a major bump; an
addition needs a minor bump. This applies starting at `0.3.0`, below the plugin's own 1.0 —
tightening the gate (making something stricter) is never itself a break.
`tests/Api/PublicSurfaceTest.php` enforces this by reflection against the committed
`tests/Api/public-surface.json`; regenerate it after a deliberate, version-bumped change with:

```sh
SENROFLUX_UPDATE_SURFACE=1 vendor/bin/phpunit --filter PublicSurfaceTest
```

**The `@api` list:**

- **`Specflux\SenroFlux\Packs\Pack`** (abstract) — the base class for a capability pack. Every
  method tagged `@api` in its own docblock is part of the surface: `name()`, `roles()`,
  `abilityNamespaces()`, `inputProperties()`, `verbFor()`, `objectIdKey()`, `objectIdPrefix()`,
  `objectIdForWrite()`, `objectIdForRead()`, `roleCapabilities()`, `withheldRoleNotice()`,
  `verbMap()`, `roleVerbs()`, `ungrantableVerbs()`, `governedNamespaces()`,
  `agentSafetyVerbMap()`, `defaultBudget()`, `skills()`, `agentSafetyPack()`, `validateCall()`,
  `setupChecks()`, `runCapability()`, `requiresAgentSafety()`, `agentSafetyBindingError()`,
  `guidesHash()`. Everything else on `Pack` (`resolveAbilities()`, `allowList()`,
  `gateVerbFor()`, `preflight()`, `baseName()`, `copyRulesLines()`) is `@internal` — derived
  implementation detail a pack has no reason to call or override.
- **`Specflux\SenroFlux\Api\LayoutVocabulary`** (final) — a thin `@api` facade over the
  (`@internal`) pages-pack layout renderer: `names()`, `sectionSchema()`, `validate()`,
  `imageUrls()`, `rulesLines()`. See its own docblock for `sectionSchema()`'s documented
  approximation and why `validate()` renders and discards rather than duplicating rules.
- **`Specflux\SenroFlux\Skills\Skill`**, **`SkillSource`**, **`Specflux\SenroFlux\Setup\SetupCheck`**
  — the value types a pack constructs and returns from `skills()`/`setupChecks()`.
- The filters `senroflux_packs`, `senroflux_run_skills`, `senroflux_default_budget` (see the
  Filters table above).

**REST is the public `@api` consumer surface** (`Specflux\SenroFlux\Http\Rest`, routes listed
above under "PHP API" and documented per-route in `Rest`'s own class docblock: params,
response shape). **admin-ajax (`Specflux\SenroFlux\Http\Ajax`) is `@internal`** — the bundled
Runs screen's own private transport, not guaranteed to stay a superset or subset of the REST
routes.

**Deprecation policy:** a break goes through `_deprecated_hook()` / `_deprecated_function()`
for at least one minor release before being removed at the next major. No deprecations exist
yet.

A fixture third-party pack built from only the symbols above lives in
`tests/Api/Fixtures/FixturePack.php`; `tests/Api/FixturePackTest.php` proves it registers,
resolves its roles and gets its verbs tiered, and that it references no non-`@api` SenroFlux
symbol.

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
pack's allow-list - the pack's own XSS gate, since these calls run as an administrator who
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

## Upgrading from 0.2

0.3 has five breaking changes. See `readme.txt`'s Changelog for the full list; in short:

1. `update-post` is draft-state edits only now; publishing goes through the new
   `publish-post` ability.
2. `create-post` refuses on a slug/title collision (`slug_collision`, 409).
3. Agent Safety is optional, not required - a built-in gate takes over when it's absent.
4. A run still parked or running under 0.2 at the moment of upgrade fails on its next tick
   ("started under 0.2") instead of continuing.
5. `GET /runs` (and the Runs screen's list) is scoped to what the current viewer may see or
   resolve, not every run on the site.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
