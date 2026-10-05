=== SenroFlux ===
Contributors: specflux, stephen1204paul
Tags: ai, agents, automation, safety, approvals
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Resumable multi-step agent runs inside the logged-in WordPress session: Abilities as tools, a governed approval gate on every write.

== Description ==

SenroFlux runs an agent loop on behalf of a logged-in user: one goal, many
model turns, many tool calls — pausing for a human whenever a write needs
approval, whenever the model needs to ask a clarifying question, or before
its first side-effecting write — and resuming exactly where it left off.

* **Runs are session-bound.** A run acts as the user who started it; nothing
  runs in the background.
* **Tools are WordPress Abilities**, restricted per run to an allow-list
  supplied directly by the consumer, or derived from a registered **pack**
  (a named tool surface — pages, posts, site, or commerce, all bundled).
* **Two gate modes, resolved once per run.** If the **Agent Safety** plugin
  is active, every call is governed by its gate (packs, tiers, approvals)
  and audit trail. Without it, SenroFlux falls back to a **built-in minimal
  gate**: every call that changes the site still stops for a person to
  approve it on SenroFlux's own Runs screen before it runs; reads do not.
  A run's mode is pinned at start and never changes underneath it — if the
  environment's mode changes mid-run, the run fails cleanly instead of
  silently switching enforcement.
* **Three kinds of park.** An **approval park** (a governed write, resolved
  by a person), a **question park** (the model asks the user one structured
  question), and a **plan park** (the model proposes a numbered plan before
  its first write; once accepted, that plan's tool set is a fence — calls
  outside it are refused until a new plan is accepted).
* **Budgets bound every run.** Steps, tool calls, tokens, questions and
  plans. A consumer may only lower a registered ceiling; running out of
  questions is not a failure — the model loses the ability to ask and
  proceeds on stated assumptions.
* **Four bundled packs.** Pages and posts (create/update/publish, with
  publish handled as its own governed step), site (navigation, front page,
  homepage patterns), and commerce (WooCommerce catalogue and order
  operations, when WooCommerce is active). Every content write is validated
  before anything is persisted: unrenderable or non-round-tripping markup,
  blocks outside the allowed set, disallowed tags or attributes, unresolved
  `{{placeholder}}` text, an unrecognised pattern shape, a slot count out of
  range, a page-shape rule (e.g. hero must come first), or a slug/title
  collision with an existing page or post — all refuse the whole write and
  persist nothing.
* **Not for multisite.** SenroFlux refuses to activate on a WordPress
  multisite install.

== Installation ==

1. Install and activate SenroFlux. It works standalone with a built-in
   approval gate.
2. Optionally install and activate **Agent Safety** for its full audit
   trail, tiered approvals, and cross-plugin governance — SenroFlux detects
   it automatically and switches gate modes on the next run started after
   activation.
3. Consumers (e.g. marketing-analytics-chat) detect `senroflux()` and offer
   multi-step runs automatically.

== Frequently Asked Questions ==

= Does it work without Agent Safety? =

Yes. Agent Safety is optional, not required. If it isn't active, SenroFlux
uses its own built-in minimal gate: every call that changes the site still
stops for a person to approve it on SenroFlux's Runs screen before it runs;
read-only calls do not. Installing Agent Safety later adds its full audit
trail, tiered approval thresholds, and governance shared across other
plugins — new runs pick it up automatically.

= Does a run keep going while I'm not looking? =

No. Runs are session-bound and driven from the browser; nothing executes in
the background. A parked run simply waits until someone with permission
resolves it.

= What happens to a run in progress when I upgrade from 0.2? =

A run that was still parked or running at the moment of the upgrade fails
on its next tick with a partial report and the reason "started under 0.2" —
0.2's `update-post` tier changed under it, so it cannot safely continue.
Completed 0.2 runs still render their full history and report. Nothing is
silently re-tiered or auto-resumed.

= Which post statuses can create-post and update-post write? =

`create-post` never publishes — it refuses a `publish` or `future` status
outright and only ever creates a draft. `update-post` covers draft-state
edits only; any transition to `publish`/`future`, or any edit to an
already-public post, goes through the separate `publish-post` ability,
which is a higher-tier, separately governed call.

= Will pages use my theme's design? =

On block themes, yes. SenroFlux builds each section from one of your theme's
own patterns when one fits the content, and otherwise uses its own layouts
with your theme's spacing and colours. It never adds colours of its own to a
theme pattern. To use only SenroFlux's layouts, untick "Use my theme's block
patterns" under SenroFlux → Settings. Developers can drop individual patterns
with the `senroflux_theme_patterns` filter.

= Does SenroFlux support WordPress multisite? =

No. SenroFlux refuses to activate on a multisite install.

== External services ==

SenroFlux itself makes two kinds of outbound request, both only while a
human is actively driving a run from their own browser session — SenroFlux
never runs in the background.

**Model calls (AI Client).** Made through the WordPress AI Client (bundled
with WordPress 7.0+), using whichever provider your site has connected
under Settings → Connectors — commonly OpenAI, but any provider the AI
Client supports. On every model turn (at most one per tick), SenroFlux
sends that provider:

* the run's full conversation history so far (the goal, every model
  message, and every tool result already produced in the run);
* a system instruction assembled from the harness's own operating rules,
  the active pack's skills (e.g. a pack's block-pattern vocabulary and
  copy constraints), and anything the consumer or `senroflux_system_instruction`
  filter contributes;
* the declared tool (Ability) schemas the run is allowed to call, and the
  results those tools return, which are fed back to the model on the next
  turn (capped at 32 KB per result by default, filterable via
  `senroflux_tool_result_max_bytes`).

No file, database or site content is sent beyond what a run's own tool
calls read and return as results. Sending and handling this data is
governed by the connected provider's own terms. The site owner chooses the
AI provider, so that provider's terms and privacy policy apply; see, for
example, OpenAI's:
https://openai.com/policies/row-terms-of-use/ and
https://openai.com/policies/row-privacy-policy/ — consult your specific
provider's terms if you have connected a different one.

**Stock photo search (Openverse).** When a run searches for a stock image
(pages/site packs), SenroFlux sends the model-generated search text as a
query string to Openverse's public search API
(`https://api.openverse.org/v1/images/`) and to Openverse's individual
image-detail endpoint when fetching a chosen result. No account
credentials, site content, or personal data are sent — only the search
terms and the id of a selected result. This runs only during an active,
human-driven run. See Openverse's terms and privacy policy:
https://openverse.org/terms-of-service and
https://openverse.org/privacy (Openverse is a WordPress.org project;
its privacy policy is https://wordpress.org/about/privacy/).

**Image downloads.** When a run imports a chosen stock photo or a generated
image, the plugin downloads the image file from the URL Openverse or the AI
provider returned (Openverse results are hosted by third-party sites such
as Flickr or Wikimedia Commons; an AI provider may instead return the image
data inline, in which case nothing is downloaded). Only the image URL is
requested, with no site data, and the file is then saved to your Media
Library.

== Privacy ==

SenroFlux stores each run in two custom tables: the ID of the user who
started it, the goal they typed, the conversation with the AI model, the
tool calls and their results, and timestamps. Runs stay until you remove
them or delete the plugin with the opt-in below.

Runs are sent off-site to the AI provider you connected, and search terms
are sent to Openverse; see "External services" above for exactly what.

SenroFlux adds suggested text for your privacy policy under Settings →
Privacy → Policy Guide. It does not register a personal-data exporter or
eraser.

Deleting the plugin keeps your runs by default. To remove all SenroFlux
data (both tables, its options, transients and per-user flags) when the
plugin is deleted, opt in first: `wp option update senroflux_uninstall_delete_data 1`,
then delete the plugin.

== Developers ==

= Extension API =

`SENROFLUX_API_VERSION` (currently 0.3.0, defined in senroflux.php) versions the declared
extension surface, independently of this plugin's own Stable tag. Semver promise: a removal
or signature change needs a major bump; an addition needs a minor bump — this applies starting
at 0.3.0, even below 1.0.

The `@api` list: the `Pack` abstract class's `@api`-tagged methods (name, roles,
abilityNamespaces, inputProperties, verbFor, objectIdKey/Prefix/ForWrite/ForRead,
roleCapabilities, withheldRoleNotice, verbMap, roleVerbs, ungrantableVerbs, governedNamespaces,
agentSafetyVerbMap, defaultBudget, skills, agentSafetyPack, validateCall, setupChecks,
runCapability, requiresAgentSafety, agentSafetyBindingError, guidesHash); the
`Api\LayoutVocabulary` facade (names, sectionSchema, validate, imageUrls, rulesLines); the
Skill/SkillSource/SetupCheck value types a pack constructs; and the `senroflux_packs`,
`senroflux_run_skills` and `senroflux_default_budget` filters. Every other filter, including
`senroflux_can_tick` and `senroflux_http_consumers`, is not part of this surface and may change
without a version bump.

REST (`senroflux/v1`) is the public `@api` consumer surface; admin-ajax is this plugin's own private transport for its
bundled Runs screen and is not guaranteed to match REST's shape.

Deprecation: a break goes through `_deprecated_hook()`/`_deprecated_function()` for at least
one minor release before removal at the next major. None exist yet.

See the "Extension API" section of README.md in the public repository,
https://github.com/specflux/senroflux, for the full reference, including how to
regenerate the reflection snapshot (tests/Api/public-surface.json there) that
enforces this.

= Source code and build =

The JavaScript in `build/` is compiled from the sources in `assets/src/` in
the public repository, https://github.com/specflux/senroflux. To rebuild it:

1. `git clone https://github.com/specflux/senroflux.git && cd senroflux`
2. `npm ci`
3. `npm run build`

`npm run build` runs `wp-scripts build` and writes `build/`.

== Changelog ==

= 0.3.0 =

New:

* Two gate modes: Agent Safety when active, otherwise a built-in minimal
  gate that still pauses every governed write for human approval on the
  Runs screen. A run's mode is pinned at start.
* Three new packs alongside pages: posts, site (navigation, front page,
  homepage patterns), and commerce (WooCommerce catalogue/order
  operations).
* Stock photo search and alt-text/image-generation abilities via the
  WordPress AI Client and Openverse.
* The Runs screen moved out of Tools to its own top-level "SenroFlux" menu
  and is now a React app (`assets/src/runs/`, built with
  `@wordpress/scripts`); `tools.php?page=senroflux-runs` redirects there.
* Extension API: `SENROFLUX_API_VERSION`, a reflection-enforced `@api`
  surface for third-party packs (see "Extension API" above).
* Pages follow your theme. Each page layout (hero, text, text with image,
  services, FAQ, call to action) is built from the active theme's own
  block patterns where one fits, filled with the run's copy. Twenty
  Twenty-Five and Ollie have built-in mappings; other block themes are
  matched automatically, and anything that doesn't fit falls back to
  SenroFlux's own layouts, using the theme's spacing and colours.
* Section tone: a section can sit on a contrast or accent band taken from
  the theme palette, only where the text keeps WCAG AA contrast.
* A Settings checkbox, "Use my theme's block patterns", to build every page
  from SenroFlux's own layouts instead.

**Breaking changes from 0.2:**

* `senroflux/update-post` is now draft-state edits only (Tier 1) — it
  refuses an already-public target (publish/future/private) and a
  requested publish/future transition. New `senroflux/publish-post`
  (Tier 2) covers every publish/future transition and any edit to an
  already-public post. Update your integration to call `publish-post` for
  those cases.
* `senroflux/create-post` now refuses `slug_collision` (409) when a
  non-trashed page or post of the same type already holds the requested
  slug, or matches the title case-insensitively.
* Agent Safety is no longer a hard requirement. 0.2 always failed closed
  without it; 0.3 falls back to a built-in minimal gate instead (see the
  FAQ above). If you relied on `senroflux_ungoverned` meaning "Agent Safety
  is missing," check the run's `gate_mode` instead.
* A run still parked or running under 0.2 at the moment you upgrade to 0.3
  fails on its next tick with reason "started under 0.2" instead of
  continuing — it is not migrated or resumed.
* `GET /runs` (and the Runs screen's run list) is now scoped to what the
  current viewer may see or resolve, instead of returning every run on the
  site to any user who could reach the endpoint.

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

== Upgrade Notice ==

= 0.3.0 =
Breaking: update-post no longer publishes (use publish-post); create-post refuses slug/title collisions; Agent Safety is optional; runs still parked under 0.2 fail on their next tick; run list is per viewer; see the changelog.
