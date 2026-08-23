=== SenroFlux ===
Contributors: specflux, stephen1204paul
Tags: ai, agents, automation, safety, approvals
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Resumable multi-step agent runs inside the logged-in WordPress session, with Abilities as tools and Agent Safety governing every call.

== Description ==

SenroFlux runs an agent loop on behalf of a logged-in user: one goal, many
model turns, many tool calls — pausing for human approval whenever Agent
Safety requires it, and resuming where it left off.

* **Runs are session-bound.** A run acts as the user who started it; nothing
  runs in the background.
* **Tools are WordPress Abilities**, restricted per run to the allow-list the
  consumer supplies.
* **Agent Safety is a hard dependency.** Every tool call passes through its
  gate (packs, tiers, approvals) and audit trail. Without Agent Safety,
  SenroFlux refuses to start any run.

= External Services =

SenroFlux itself makes no external requests. Model calls are made by the
WordPress AI Client (bundled with WordPress) using whichever provider your
site has connected under Settings → Connectors; those requests go to that
provider and are governed by your site's configuration.

== Installation ==

1. Install and activate **Agent Safety** first (required).
2. Install and activate SenroFlux.
3. Consumers (e.g. marketing-analytics-chat) detect `senroflux()` and offer
   multi-step runs automatically.

== Changelog ==

= 0.1.0 =
* Initial scaffold: hard-dependency gate against Agent Safety.
