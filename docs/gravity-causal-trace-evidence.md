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

Candidate entries, forms, steps, and assignees are represented as session-local opaque references derived from the technical identity and the opaque diagnostic session ID. These references exist only for diagnostic comparison inside the bounded session and are not reversible product identities.

Assignee evidence contains only:

- observed count;
- safe assignee-type label when exposed by the documented assignee key convention;
- bounded opaque assignee references.

No generic expected-assignee rule is configured, so the diagnostics do not claim that an observed assignment is correct. They can prove only whether assignee evaluation was observed and whether the observed collection was empty or non-empty.

Every persisted Diagnostic Session write passes through `WDDTF\Privacy\Redactor`. Normal report, JSON, Markdown, and LLM-ready output also remain behind the same centralized privacy boundary.

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

When multiple candidate entries occur during one diagnostic session, the session reports `MULTIPLE_ENTRY_CANDIDATES` rather than assuming which visitor submission caused a later Inbox request. A row-level Inbox hook can link an observed row to an already-known candidate without persisting the row value or raw Entry object.

## Request scope

Ordinary AJAX and REST requests still do not finalize general Deep Diagnostics reports. The Gravity observer writes only when one of its documented host seams fires during an explicitly active bounded session. An unrelated AJAX request therefore does not become generalized trace data.

## Verification and claim ceiling

Repository CI installs the real generated Deep Diagnostics artifact into disposable WordPress 6.5 and current-release environments. A CI-only host fixture reproduces the documented hook signatures and exercises the lifecycle across real `admin-ajax.php` requests, including an unrelated AJAX request that must remain unobserved.

That fixture can verify Deep Diagnostics' correlation, ordering, bounded retention, privacy behavior, Inbox-row linking, report integration, and fail-closed interpretation around the documented extension contracts.

The fixture is **not** Gravity Forms or Gravity Flow. No legally available authentic Gravity Forms / Gravity Flow package was found in the repository, execution environment, or connected Drive during this implementation. Therefore authentic licensed host-runtime qualification remains unverified and no host-version-specific behavioral claim is made from the fixture.

Browser JavaScript/network Live Refresh behavior, user-perceived refresh completion, generalized AJAX/REST tracing, notification/feed timing, production-load overhead, and business-specific expected-assignee rules remain outside this batch.
