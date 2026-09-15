# Gravity Forms / Gravity Flow Causal Trace Evidence Boundary

This capability is a bounded diagnostic observer. It does not own, modify, route, approve, assign, or complete Gravity Forms / Gravity Flow business workflow state.

## Explicit bounded session

An authorized operator starts the existing Gravity diagnostic session from Tools -> Deep Diagnostics. The session lasts at most 15 minutes and retains at most:

- 10 candidate entry traces;
- 24 lifecycle events per candidate trace;
- 20 Inbox request samples;
- 20 opaque assignee references per assignee-observation event.

Browser evidence does not create a second session or store. It is attached to an already-retained Inbox request sample inside the same Diagnostic Session, so browser evidence inherits the existing 20-sample bound.

Passive rendering does not start or repair a session. Stale state is repaired only by the explicit operator action.

## Documented host seams

The observer uses documented public extension points:

- Gravity Forms `gform_entry_created` for the entry-created boundary;
- Gravity Forms `gform_after_submission` for the post-submission boundary;
- Gravity Flow `gravityflow_step_start` for step-start evidence;
- Gravity Flow `gravityflow_step_assignees` for assignee evidence;
- Gravity Flow `gravityflow_step_complete` for step-completion evidence;
- Gravity Flow `gravityflow_post_process_workflow` for post-processing / next-step evidence;
- Gravity Flow `gravityflow_workflow_complete` for workflow-completion evidence;
- Gravity Flow `gravityflow_columns_inbox_table` for the existing request-level Inbox render signal;
- Gravity Flow `gravityflow_inbox_field_value` for a row-level signal that can link an Inbox render to an already-observed candidate trace;
- Gravity Flow `gravityflow_enqueue_admin_scripts` / `gravityflow_enqueue_frontend_scripts` plus `gravityflow_inbox_args` to scope the optional browser observer to Inbox rendering contexts.

Filters return their original host values unchanged. Actions do not mutate Entry, Form, Step, assignee, status, assignment, routing, or workflow state.

## Correlation and privacy

Raw technical host identifiers may be inspected only in the callback that needs them. Persisted/reportable evidence never stores raw Entry ID, Form ID, Step ID, assignee key/ID, submitted fields, names, email addresses, phone numbers, national IDs, uploads, notes, hook argument dumps, or request payloads.

Candidate entries, forms, steps, and assignees are represented as session-local opaque references. Every Entry/Form/Step/Assignee reference is derived through one Gravity correlation function using a non-exported server-side WordPress `auth` salt as the HMAC secret. The message is domain-separated by a diagnostic version literal, the public diagnostic session ID, the identity kind, and the raw technical identity. The public `session_id` participates in correlation scope but is never the cryptographic key. There is no fallback to session-ID-keyed HMAC, plain hashing, raw IDs, or reversible encoding.

The existing `gt-`, `gf-`, `gs-`, and `ga-` shapes are retained for report consumers. Knowledge of a public session ID and a guessed low-entropy host ID is therefore insufficient to reproduce the emitted reference without the server-held WordPress secret.

Assignee evidence contains only:

- observed count;
- safe assignee-type label when exposed by the documented assignee key convention;
- bounded opaque assignee references.

No generic expected-assignee rule is configured, so the diagnostics do not claim that an observed assignment is correct. They can prove only whether assignee evaluation was observed and whether the observed collection was empty or non-empty.

Every persisted Diagnostic Session write passes through `WDDTF\Privacy\Redactor`. Normal report, JSON, Markdown, and LLM-ready output also remain behind the same centralized privacy boundary.

## Browser / Live Refresh evidence

The browser layer is deliberately not a generalized AJAX or network recorder.

When an authenticated AJAX Inbox request reaches the existing row-level candidate correlation hook and at least one already-known opaque candidate trace is positively linked, Deep Diagnostics may add one bounded response header containing a random opaque `gb-...` sample reference. The server stores that same opaque reference on the existing Inbox request sample. Unrelated AJAX traffic that never produces candidate-linked Inbox evidence receives no diagnostic response tag and is not persisted as browser evidence.

The client observer is loaded only while an explicit Gravity diagnostic session is active and only in Gravity Flow Inbox rendering contexts reached through the documented enqueue/Inbox seams. It uses jQuery lifecycle events instead of monkey-patching `XMLHttpRequest`, `fetch`, or application networking. The observer may transiently see that requests started/completed, but it ignores every request unless the response carries the exact server-issued diagnostic sample header.

For a positively tagged refresh, the browser sends only the following bounded metadata back to WordPress:

- the opaque `gb-...` sample reference;
- client receipt timestamp;
- success/error outcome and numeric HTTP status as observable by jQuery;
- bounded client duration when measurable;
- document visibility state;
- one metadata-only UI signal: no signal, document-title change, DOM mutation, or both.

The observer never sends or stores the request URL, query string, request body, response body, cookies, general headers, Inbox row text, HTML, submitted field values, Entry content, names, or other DOM content. Mutation observation records only that mutation activity occurred; it does not capture mutated text or markup.

### Browser evidence security boundary

Starting a diagnostic remains an explicit `manage_options` administrator action. Browser evidence collection does not weaken that boundary.

The browser evidence write endpoint exists only as an authenticated `wp_ajax_...` action. It requires:

- a logged-in WordPress user;
- the Gravity Flow Inbox capability `gravityflow_inbox`;
- the current bounded Gravity Diagnostic Session to exist and be unexpired;
- a WordPress nonce bound to that current session;
- a strict allow-listed request schema;
- an opaque sample reference already present on a candidate-linked AJAX Inbox sample in that same current session.

The client cannot nominate a diagnostic `session_id`, Entry/Form/Step identity, or trace reference. Unknown/forged sample references, expired sessions, failed nonces, missing Inbox permission, or extra payload fields are rejected. Each server sample accepts at most one browser evidence object; repeated writes are idempotent and cannot expand storage beyond the existing sample bound.

This batch intentionally qualifies authenticated Inbox operators. Gravity Flow contexts that rely on unauthenticated/tokenized email-assignee access are not silently treated as equivalent; their browser evidence remains unqualified until an authentic-host security/permission path is demonstrated.

## Serialized session mutation and evidence integrity

Gravity lifecycle events, Inbox samples, and accepted browser evidence share one Diagnostic Session as their single evidence source of truth. Writers do not perform an unlocked load-modify-save sequence. `SessionStore` owns one per-session serialized mutation primitive:

- lock acquisition uses an atomic, non-autoloaded WordPress option;
- lock ownership is represented by an opaque owner token and a bounded lease;
- stale recovery and release use owner/value-matched conditional deletion so one writer cannot delete another writer's replacement lock;
- the session is reloaded only after exclusive lock ownership is established;
- the existing Redactor/save path persists the fresh mutated state;
- acquisition retries are bounded and a failed commit is not silently treated as success.

If lock acquisition, fresh-state mutation, persistence, or owned release cannot be established, Deep Diagnostics writes a separate bounded monotonic `session_integrity_uncertain` marker. Any observation carrying that marker analyzes as `INSUFFICIENT_EVIDENCE`; browser interpretation likewise becomes `BROWSER_EVIDENCE_INSUFFICIENT`. It cannot emit a normal success or absence-based diagnosis as though the chronology were complete.

## Causal interpretation

The trace keeps evidence and interpretation separate. Its deterministic first-inconsistent-point classifications include:

- `ENTRY_NOT_OBSERVED`;
- `SUBMISSION_COMPLETE_NOT_OBSERVED`;
- `WORKFLOW_NOT_OBSERVED`;
- `STEP_NOT_OBSERVED`;
- `NO_ASSIGNEE_OBSERVED`;
- `INBOX_REFRESH_NOT_OBSERVED`;
- `MULTIPLE_ENTRY_CANDIDATES`;
- `INSUFFICIENT_EVIDENCE`;
- `TRACE_COMPLETE_TO_SERVER_INBOX_OBSERVATION`.

`TRACE_COMPLETE_TO_SERVER_INBOX_OBSERVATION` remains a server-side classification. It means that the server-side evidence chain reached an Inbox row/render associated with that opaque trace. The browser batch does not redefine or upgrade it.

Separate browser interpretations are additive:

- `BROWSER_EVIDENCE_INSUFFICIENT`: no positively tagged browser refresh exists, or session integrity makes browser interpretation unsafe;
- `BROWSER_REFRESH_NOT_OBSERVED`: the server retained a tagged candidate-linked refresh but no matching browser receipt was accepted;
- `BROWSER_RESPONSE_RECEIVED`: the tagged refresh response reached the instrumented browser, with no supported UI-side signal observed;
- `BROWSER_UI_SIGNAL_OBSERVED`: the tagged response reached the browser and a metadata-only title/DOM mutation signal occurred in the short observation window;
- `BROWSER_EVIDENCE_AMBIGUOUS`: accepted browser evidence is associated with more than one plausible opaque candidate trace.

None of these browser classifications means that the expected Entry became visible to a human. A DOM mutation or title change is only a UI-side signal. Missing browser evidence is not converted into “Gravity Flow is broken.”

A trace whose retained chronology exceeded the 24-event bound is explicitly marked incomplete. If the retained events already positively prove the complete server-to-Inbox chain, that positive fact may remain proven. Otherwise no absence-dependent classification is emitted from the truncated history; analysis stops at `INSUFFICIENT_EVIDENCE` with reason `trace_event_limit_reached`. The existing session-level candidate-trace truncation remains `INSUFFICIENT_EVIDENCE` as well.

When multiple candidate entries occur during one diagnostic session, the session reports `MULTIPLE_ENTRY_CANDIDATES` rather than assuming which visitor submission caused a later Inbox request. Browser evidence also stays ambiguous if the tagged Inbox sample contains multiple plausible opaque trace references. No timing-only guess is introduced.

## Request scope

Ordinary AJAX and REST requests still do not finalize general Deep Diagnostics reports. The Gravity observer writes only when one of its documented host seams fires during an explicitly active bounded session. An unrelated AJAX request therefore does not become generalized trace data. Browser evidence is accepted only for a server-tagged, candidate-linked Inbox sample already persisted in that session.

## Verification and claim ceiling

Repository CI installs the real generated Deep Diagnostics artifact into disposable WordPress 6.5 and current-release environments. The existing CI-only causal host fixture reproduces the documented server hook signatures across real `admin-ajax.php` requests.

A separate CI-only browser fixture may model a Gravity Inbox page, a jQuery refresh request, the documented Inbox server hooks, and a modeled title/DOM update. Playwright/Chromium can then prove Deep Diagnostics’ own enqueue scoping, server tag, browser receipt, browser-to-server write, privacy filtering, unrelated-AJAX exclusion, report integration, and browser interpretation around those modeled seams.

The fixture is **not** Gravity Forms or Gravity Flow. A browser fixture PASS does not prove that the commercial Gravity Flow package uses identical networking, emits identical DOM mutations, or visibly presents the expected Entry. It proves only that Deep Diagnostics behaves correctly when the modeled documented/server-tagged signals occur.

No legally available authentic Gravity Forms / Gravity Flow package is required to complete this fixture-level batch. Authentic licensed host-runtime qualification, authentic Gravity Flow Live Data Refresh behavior, and actual SRWF browser behavior remain unverified until executed on a site containing those licensed/product components.

The sequential PHP development server used by fixture CI is cross-request/browser transport evidence, not production-load or concurrency proof. Session serialization remains covered by the deterministic SessionStore interleaving tests.

## Later authentic-host compatibility qualification

The implementation intentionally contains no SRWF-specific rules, fixed form/step/field IDs, or Gravity Flow internal AJAX action names. Later qualification should therefore require evidence rather than code changes:

1. record exact licensed Gravity Forms and Gravity Flow versions and the WordPress/PHP environment;
2. start one bounded Gravity diagnostic as an administrator;
3. keep the real relevant Inbox open as the actual authenticated operator with Inbox permission;
4. submit one real test Entry through the real product workflow;
5. confirm the server trace reaches the real candidate-linked Inbox observation;
6. confirm whether a correlated browser response and UI-side signal are recorded;
7. independently verify whether the expected Entry is actually visible to the operator — this human/product observation is stronger than DEEP's metadata signal and must be reported separately;
8. inspect JSON, Markdown, and LLM-ready output for absence of raw IDs, field values, names, payloads, URLs/query data, cookies, and PII;
9. repeat any tokenized/unauthenticated assignee path separately before claiming that security context is supported.

Browser/network Live Refresh fixture evidence does not qualify notification/feed timing, generalized AJAX/REST tracing, business-specific expected-assignee correctness, production-load overhead, or product-wide UI behavior.
