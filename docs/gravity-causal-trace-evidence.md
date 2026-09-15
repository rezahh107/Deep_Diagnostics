# Gravity Forms / Gravity Flow Causal Trace Evidence Boundary

This capability is a bounded diagnostic observer. It does not own, modify, route, approve, assign, or complete Gravity Forms / Gravity Flow business workflow state.

## Explicit bounded session

An authorized operator starts the existing Gravity diagnostic session from Tools -> Deep Diagnostics. The session lasts at most 15 minutes and retains at most:

- 10 candidate entry traces;
- 24 lifecycle events per candidate trace;
- 20 Inbox request samples;
- 20 opaque assignee references per assignee-observation event.

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
- Gravity Flow `gravityflow_inbox_field_value` for a row-level signal that can link an Inbox render to an already-observed candidate trace.

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

## Serialized session mutation and evidence integrity

Gravity lifecycle events and Inbox samples share one Diagnostic Session as their single evidence source of truth. Writers do not perform an unlocked load-modify-save sequence. `SessionStore` owns one per-session serialized mutation primitive:

- lock acquisition uses an atomic, non-autoloaded WordPress option;
- lock ownership is represented by an opaque owner token and a bounded lease;
- stale recovery and release use owner/value-matched conditional deletion so one writer cannot delete another writer's replacement lock;
- the session is reloaded only after exclusive lock ownership is established;
- the existing Redactor/save path persists the fresh mutated state;
- acquisition retries are bounded and a failed commit is not silently treated as success.

If lock acquisition, fresh-state mutation, persistence, or owned release cannot be established, Deep Diagnostics writes a separate bounded monotonic `session_integrity_uncertain` marker. Any observation carrying that marker analyzes as `INSUFFICIENT_EVIDENCE`; it cannot emit a normal success or absence-based diagnosis as though the chronology were complete.

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

`TRACE_COMPLETE_TO_SERVER_INBOX_OBSERVATION` means that the server-side evidence chain reached an Inbox row/render associated with that opaque trace. It does **not** mean that the browser visibly refreshed, that a user saw the row, that an expected assignee was correct, or that the workflow later completed unless those separate events are present in the chronology.

Missing evidence is not converted into a product-failure claim. For example, a missing step-start hook becomes `STEP_NOT_OBSERVED`; it does not become "Gravity Flow is broken".

A trace whose retained chronology exceeded the 24-event bound is explicitly marked incomplete. If the retained events already positively prove the complete server-to-Inbox chain, that positive fact may remain proven. Otherwise no absence-dependent classification is emitted from the truncated history; analysis stops at `INSUFFICIENT_EVIDENCE` with reason `trace_event_limit_reached`. The existing session-level candidate-trace truncation remains `INSUFFICIENT_EVIDENCE` as well.

When multiple candidate entries occur during one diagnostic session, the session reports `MULTIPLE_ENTRY_CANDIDATES` rather than assuming which visitor submission caused a later Inbox request. A row-level Inbox hook can link an observed row to an already-known candidate without persisting the row value or raw Entry object.

## Request scope

Ordinary AJAX and REST requests still do not finalize general Deep Diagnostics reports. The Gravity observer writes only when one of its documented host seams fires during an explicitly active bounded session. An unrelated AJAX request therefore does not become generalized trace data.

## Verification and claim ceiling

Repository CI installs the real generated Deep Diagnostics artifact into disposable WordPress 6.5 and current-release environments. A CI-only host fixture reproduces the documented hook signatures and exercises the lifecycle across real `admin-ajax.php` requests, including an unrelated AJAX request that must remain unobserved.

Focused tests also falsify the three repair classes directly: public-session-key pseudonym reproduction, stale interleaving/lost updates and lock ownership/recovery, and absence-based diagnosis from truncated event history.

The fixture can verify Deep Diagnostics' correlation, ordering, bounded retention, privacy behavior, Inbox-row linking, report integration, and fail-closed interpretation around the documented extension contracts. The sequential PHP development server used by the existing causal workflow is cross-request evidence, not concurrency proof; concurrency serialization is proven by the deterministic SessionStore interleaving tests rather than inferred from that server.

The fixture is **not** Gravity Forms or Gravity Flow. No legally available authentic Gravity Forms / Gravity Flow package was found in the repository, execution environment, or connected Drive during this implementation. Therefore authentic licensed host-runtime qualification remains unverified and no host-version-specific behavioral claim is made from the fixture.

Browser JavaScript/network Live Refresh behavior, user-perceived refresh completion, generalized AJAX/REST tracing, notification/feed timing, production-load overhead, and business-specific expected-assignee rules remain outside this batch.
