# SenroFlux — domain glossary

Terms as used in specs, code, and admin UI. Glossary only; no implementation detail.

- **Run** — one goal pursued on behalf of one logged-in user across many model turns and
  tool calls. Session-bound; never executes in the background.
- **Tick** — the browser advancing a run by at most one model turn plus that turn's tool
  calls.
- **Step** — one recorded unit of a run: user message, model message, tool result, or a park.
- **Park** — a step at which the run stops and waits for a human. Kinds:
  - **Approval park** — a tool call stopped by the gate before it runs, waiting for a human's
    approve or reject; resolvable by whoever may tick the run. With Agent Safety active the gate
    is its verdict pipeline and only Tier-2 calls park. Without it, SenroFlux's built-in gate
    parks every call whose verb is above Tier 0, shows no tier, and offers no batching: one
    call, one click. Resumes on approve or reject.
  - **Question park** — the model asks the user one structured clarifying question (text,
    optional choices, one-line rationale); resumes with the answer, or a skip, returned to the
    model as the result of its question.
  - **Plan park** — the model has proposed a plan and waits for the human's accept or veto.
- **Stalled run** — a run left mid-flight because the human closed the browser rather than because
  the run asked for anything. It is not a park and needs no new state: nothing expires, so the run
  simply waits in the table until someone ticks it again. The distinction matters only to the
  person reading the Runs screen, where a stalled run is presented exactly like a park — as work
  waiting on them — because that is what it is from their side.
- **Plan step** — the model proposing a numbered plan (goal, steps with the abilities each
  will use, assumptions) as a park before its first side-effecting write; the human accepts,
  accepts with pre-approval, or vetoes it, optionally with a note. Once accepted, the plan's
  ability set is the fence: side-effecting calls outside it are refused until a new plan is
  accepted. A veto returns the note to the model and reopens planning.
- **Pre-approval grant** — a human's advance approval, given when accepting a plan, of the
  irreversible actions that plan lists; scoped to one run, one verb, a count, and a lifetime, and
  spent one action at a time. Anything outside the grant, or any call after the grant has expired,
  still parks. An **ungrantable verb** never enters a grant: every call to it parks on its own,
  whatever the plan lists. Verbs that move money or reach a person outside the site (a refund, a
  customer-visible order note) are ungrantable.
- **Budget** — per-run ceilings: steps, tool calls, tokens, questions, plans, generated
  images, and refunds (a count, not a sum of money). A consumer may only lower the registered ceiling. Running out of questions is not a
  failure: the model loses the ability to ask and proceeds on stated assumptions.
- **Park resolution** — the human's reply that resumes a parked run: approve or reject for an
  approval park, answer or skip for a question park, accept / accept-with-pre-approval / veto
  for a plan park.
- **Consumer** — the plugin or screen that starts a run and renders its parks, registered on the
  `senroflux_http_consumers` allow-list. SenroFlux's own Runs screen is a consumer. Because the
  consumer's own rendering is what makes an approval meaningful, any consumer other than that
  screen requires Agent Safety; under the built-in gate only standalone runs are allowed.
- **Standalone run** — a run whose consumer is SenroFlux's own Runs screen rather than another
  plugin; started by a human from the "New run" form and driven from the run detail page.
- **Pack** — a named tool surface a run may use (e.g. the **pages pack**): the roles it
  needs, the verbs it exposes with their tiers, its skills, its pattern vocabulary, the run
  capability it requires and the setup checks it declares. A SenroFlux pack produces the Agent
  Safety capability pack that governs it.
- **Setup check** — a condition shown on the Runs screen before a run can be started, declared
  by the harness or by a registered pack and identified by a namespaced id. **Blocking** checks
  disable Start while they fail (no model provider); **advisory** ones only recommend (Agent
  Safety absent). Severity is the contributor's to declare, since only it knows whether its own
  absence is fatal. Each check is worded for the viewer: a way to fix it for someone who can —
  a destination, never a fix performed in place — and who to ask for someone who can't; the
  capability that gates the fix travels with it. A check is the run's own preflight seen early,
  not a second set of rules, so Start re-decides regardless of what the screen last showed.
  Advisory checks can be dismissed by a viewer who has decided against them; blocking checks
  never can, because dismissing one would hide why Start is dead.
- **Skill** — a named, versioned instruction fragment that a pack (or the harness itself)
  contributes to every run's system instruction. Skills shape *how* the model produces content;
  they never grant or widen authority — that stays with verbs and tiers. Harness skills are
  required; pack skills may be disabled for a run; skills are always on, never conditional.
- **Role** — what a pack needs an ability *for* (read, create, update, preview, patterns),
  independent of which registered ability fills it — core's when it exists and fits, a
  polyfill otherwise.
- **Run capability** — the WordPress capability a pack requires of whoever starts a run with it
  (`edit_posts` for the posts pack, `edit_pages` for the pages pack, `manage_options` for the site
  pack, `manage_woocommerce` for commerce). It answers "may this
  person set this pack going at all", which is a different question from what the gate lets the
  run do once started, and a different word from **Role** above. Holding no pack's run
  capability is what makes SenroFlux invisible to a user.
- **Site navigation** — the one navigation the active theme actually renders to visitors, named as
  a single domain object so a run never has to know which of WordPress's two menu worlds it is in:
  in a block theme, the navigation entry the theme's header refers to; in a classic theme, the menu
  assigned to the theme's location. Creating a navigation, or choosing where it appears, is not part
  of the concept — a run edits the navigation the site already has. Because it is by definition the
  one visitors see, every change to it is Tier 2.

- **Verb** — the unit Agent Safety tiers, grants and audits. A pack maps each (role, input
  shape) to a verb, so one ability can be several verbs (editing a draft and publishing it
  are different verbs).
- **Polyfill ability** — an ability SenroFlux registers only because upstream does not yet
  provide it, named to mirror the ability upstream is expected to ship, and withdrawn the
  release after it does. The mirror is the ability's name, never its namespace: a polyfill
  always lives in SenroFlux's own namespace, never in one another plugin owns. Upstream is core where core is the natural home, and a canonical
  upstream plugin where it is not.
- **Pattern vocabulary** — the curated set of core-block patterns a pack ships and the model
  composes content from. Content is a sequence of pattern instances and nothing else; anything
  outside the vocabulary is refused, never trimmed. A vocabulary separates **prose patterns**,
  which may repeat without limit, from **feature patterns**, which carry per-vocabulary caps.
- **Pattern shape** — the structural definition of a pattern: which blocks nest in which, and
  how many of each repeated part are allowed. Shape is how the harness recognises a pattern
  in what the model wrote; the name the model gives it is only a hint.
- **Verification read-back** — after a write, the model re-reading the changed object before
  finishing. Validation that can refuse a write belongs to the ability that performs it; the
  re-read only informs the report and can never mark anything done. A finish without a
  re-read is allowed once nudged, and the object is reported as unverified.
- **Stale write** — a write refused because the object changed between the run last reading it and
  the run trying to write it. The refusal belongs to the ability that performs the write, not to the
  model's judgement, so a run parked for days cannot silently overwrite an edit a human made in the
  meantime. A stale write is a refusal the model must react to, never a warning it may proceed past.
- **Report** — the run's closing summary: the model's prose plus a harness-built list of every
  object written (status, edit and preview links, verified or not). Links come from the
  harness, never from the model. Cancelled and failed runs still get a partial report.
- **Conversation language** — the language the model uses when it speaks to the human
  (questions, plan, report): the locale of the user who started the run, fixed for the run's
  life even if another admin answers a park. Told to the model; not enforced.
- **Content language** — the language of what the run produces for the site's visitors (page
  content): the site's locale unless the goal or an answer says otherwise. Told to the model;
  not enforced. Distinct from conversation language.
- **Tier** — a verb's classification, declared by the pack that exposes it: 0 read, 1
  side-effecting reversible, 2 irreversible (publish, delete, send, pay, change settings). An
  unmapped verb is Tier 2. One declaration serves both gates: Agent Safety consumes the full
  scale and parks Tier 2, while the built-in gate reads it only as zero-or-above and parks
  anything above. Where one ability spans verbs of several tiers, the ability registers at the
  highest. For an ability another plugin owns (WooCommerce's), the tier — including any raise
  that depends on the call's arguments, such as a price change — is declared by Agent Safety's
  integration for that plugin, and the pack's own declaration must agree with it.
- **Gate mode** — which gate governs a run: Agent Safety's, or SenroFlux's built-in minimal
  gate. Resolved once when the run starts, pinned to the run, and reported with it. If the live
  environment stops matching the pinned mode — Agent Safety activated or deactivated mid-run —
  the next tick fails the run with a partial report rather than switching gates under an
  already-accepted plan. A pack may refuse the built-in gate: the commerce pack is unavailable
  unless Agent Safety is active, and says so as a blocking setup check.

**Grant** — A human-issued, run-scoped permission to execute a named verb up to N times,
not bound to a specific object. Distinct from an Approval, which is bound to one exact
action. Created when a Plan is accepted with pre-approval; spent as matching actions run;
ends with the Run, or at its **expiry**, whichever comes first. A grant expires because
consent is given against a plan the human has just read, and a run may sit parked for days:
after expiry the verb parks again for a fresh approval. This is the run model's only
dependence on wall-clock time.
