# SenroFlux — domain glossary

Terms as used in specs, code, and admin UI. Glossary only; no implementation detail.

- **Run** — one goal pursued on behalf of one logged-in user across many model turns and
  tool calls. Session-bound; never executes in the background.
- **Tick** — the browser advancing a run by at most one model turn plus that turn's tool
  calls.
- **Step** — one recorded unit of a run: user message, model message, tool result, or a park.
- **Park** — a step at which the run stops and waits for a human. Kinds:
  - **Approval park** — a Tier-2 tool call blocked by Agent Safety; resumes on approve/reject.
  - **Question park** — the model asks the user one structured clarifying question (text,
    optional choices, one-line rationale); resumes with the answer, or a skip, returned to the
    model as the result of its question.
  - **Plan park** — the model has proposed a plan and waits for the human's accept or veto.
- **Plan step** — the model proposing a numbered plan (goal, steps with the abilities each
  will use, assumptions) as a park before its first side-effecting write; the human accepts,
  accepts with pre-approval, or vetoes it, optionally with a note. Once accepted, the plan's
  ability set is the fence: side-effecting calls outside it are refused until a new plan is
  accepted. A veto returns the note to the model and reopens planning.
- **Pre-approval grant** — a human's advance approval, given when accepting a plan, of the
  irreversible actions that plan lists; scoped to one run, one verb, and a count, and spent one
  action at a time. Anything outside the grant still parks.
- **Budget** — per-run ceilings: steps, tool calls, tokens, questions, and plans. A consumer may only
  lower the registered ceiling. Running out of questions is not a failure: the model loses the
  ability to ask and proceeds on stated assumptions.
- **Park resolution** — the human's reply that resumes a parked run: approve or reject for an
  approval park, answer or skip for a question park, accept / accept-with-pre-approval / veto
  for a plan park.
- **Consumer** — the plugin or screen that starts a run and renders its parks. SenroFlux's own
  Runs screen is a consumer.
- **Standalone run** — a run whose consumer is SenroFlux's own Runs screen rather than another
  plugin; started by a human from the "New run" form and driven from the run detail page.
- **Pack** — a named tool surface a run may use (e.g. the **pages pack**): the roles it
  needs, the verbs it exposes with their tiers, its skills and its pattern vocabulary. A
  SenroFlux pack produces the Agent Safety capability pack that governs it.
- **Skill** — a named, versioned instruction fragment that a pack (or the harness itself)
  contributes to every run's system instruction. Skills shape *how* the model produces content;
  they never grant or widen authority — that stays with verbs and tiers. Harness skills are
  required; pack skills may be disabled for a run; skills are always on, never conditional.
- **Role** — what a pack needs an ability *for* (read, create, update, preview, patterns),
  independent of which registered ability fills it — core's when it exists and fits, a
  polyfill otherwise.
- **Verb** — the unit Agent Safety tiers, grants and audits. A pack maps each (role, input
  shape) to a verb, so one ability can be several verbs (editing a draft and publishing it
  are different verbs).
- **Polyfill ability** — an ability SenroFlux registers only because core does not yet provide
  it, named to mirror the expected core ability, and withdrawn the release after core ships it.
- **Pattern vocabulary** — the curated set of core-block patterns a pack ships and the model
  composes page content from. A page is a sequence of pattern instances and nothing else;
  content outside the vocabulary is refused, never trimmed.
- **Pattern shape** — the structural definition of a pattern: which blocks nest in which, and
  how many of each repeated part are allowed. Shape is how the harness recognises a pattern
  in what the model wrote; the name the model gives it is only a hint.
- **Verification read-back** — after a write, the model re-reading the changed object before
  finishing. Validation that can refuse a write belongs to the ability that performs it; the
  re-read only informs the report and can never mark anything done. A finish without a
  re-read is allowed once nudged, and the object is reported as unverified.
- **Report** — the run's closing summary: the model's prose plus a harness-built list of every
  object written (status, edit and preview links, verified or not). Links come from the
  harness, never from the model. Cancelled and failed runs still get a partial report.
- **Conversation language** — the language the model uses when it speaks to the human
  (questions, plan, report): the locale of the user who started the run, fixed for the run's
  life even if another admin answers a park. Told to the model; not enforced.
- **Content language** — the language of what the run produces for the site's visitors (page
  content): the site's locale unless the goal or an answer says otherwise. Told to the model;
  not enforced. Distinct from conversation language.
- **Tier** — Agent Safety's classification of a verb: 0 read, 1 side-effecting reversible,
  2 irreversible (publish, delete, send, pay, change settings). Tier 2 always parks.

**Grant** — A human-issued, run-scoped permission to execute a named verb up to N times,
not bound to a specific object. Distinct from an Approval, which is bound to one exact
action. Created when a Plan is accepted with pre-approval; spent as matching actions run;
ends with the Run.
