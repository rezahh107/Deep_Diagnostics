=== WP Deep Diagnostics ===
Contributors: openai
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 1.5.9

== Description ==
Forensic WordPress latency telemetry and privacy-safe, LLM-ready diagnostic reporting, with bounded evidence-first orchestration across already-collected lifecycle, HTTP, query, autoload/cache, asset, WP-Cron, and Gravity evidence.

== Engineering source of truth ==
The repository root (`wp-deep-diagnostics.php`, `src/`, `templates/`, `assets/`, and `uninstall.php`) is the only editable plugin implementation. `create_fixed_plugin.py` does not embed PHP source; it deterministically assembles the root source into `build/wp-deep-diagnostics/` and `dist/wp-deep-diagnostics.zip`.

Build the installable artifact with:

    python3 create_fixed_plugin.py

The generated `build/` and `dist/` directories are intentionally not committed.

== Verification ==
Development tests use PHPUnit 10. GitHub Actions runs PHP syntax checks and deterministic unit/regression tests on PHP 8.1 and 8.3. Disposable WordPress jobs exercise the declared baseline WordPress 6.5 on PHP 8.1 and the current WordPress release on PHP 8.1 by installing the generated artifact, booting it, completing a normal diagnostic finalization, and checking the persisted report contract.

The WordPress jobs also exercise the real WP-Cron qualification lifecycle: passive admin rendering must not schedule a probe, an explicit qualification schedules exactly one Deep Diagnostics single event, a duplicate pending qualification is rejected, WP-CLI executes the scheduled probe through WordPress Cron, the correlated callback evidence is observed and persisted, and disabled/stale states fail closed without invented success.

Gravity Forms / Gravity Flow unit coverage exercises bounded diagnostic-session behavior, lifecycle correlation, privacy-safe assignee evidence, first-inconsistent-point analysis, existing Inbox observation, and the additive report contract. Disposable WordPress jobs exercise Deep Diagnostics' documented-hook integration through CI-only host fixtures and real request boundaries. The causal-trace integration covers entry creation, after-submission, step start, assignee observation, step completion, next-step processing, workflow completion, Inbox row/render linkage, unrelated traffic that must remain unobserved, privacy canaries, and the next normal report.

Those public fixtures prove Deep Diagnostics behavior around the modeled/documented seams; they remain proprietary-free and are not themselves licensed Gravity Forms / Gravity Flow runtimes.

Separately, `docs/gravity-authentic-host-qualification.md` records the completed Owner-authorized authentic qualification from PR #9. The reference scenario ran Gravity Forms 3.1.1.1 and Gravity Flow 3.1.0 on WordPress 6.5 / PHP 8.1.34 with Chromium. The real workflow executed and the expected synthetic Entry independently became visible in the assigned operator's real Gravity Flow Inbox through native Live Data Refresh. Deep Diagnostics retained its narrower browser claim: `entry_visible_to_user_proven` remained `false`, and the actual row-visibility claim came from the independent browser assertion rather than being inferred from DEEP metadata.

That authentic qualification is exact-scenario evidence. It does not prove all Gravity versions, current-WordPress commercial runtime, tokenized/unauthenticated assignee contexts, SRWF-specific behavior, production load/performance, notification/feed timing, Gravity View/Perks, or generalized AJAX/REST/network tracing.

== Privacy boundary ==
Collectors may temporarily observe raw runtime values needed to correlate one request, but persisted/reportable data uses `WDDTF\Privacy\Redactor` as the centralized minimization authority before normal reports, transients, JSON, Markdown, or the LLM bundle are produced. The bounded cross-request Diagnostic Session store also applies the same Redactor before persisting session data. The boundary removes URL query/fragment data, masks identifier-like path segments, SQL literal values, email/IP/token/credential patterns, and explicitly sensitive keyed values while preserving diagnostic shape and timing metadata.

WP-Cron ready-event evidence intentionally omits Cron argument values. The Deep Diagnostics-owned qualification event carries only an opaque diagnostic session identifier.

Gravity diagnostics do not persist Gravity Forms submitted field values, Entry/Form/Step objects, raw Entry IDs, raw Form IDs, raw Step IDs, raw assignee keys/IDs, names, email addresses, phone numbers, national IDs, uploads, notes, host hook argument dumps, or raw request payloads. Technical host identifiers needed for correlation are converted immediately into bounded session-local opaque references before persistence. Assignee evidence contains only a safe type label when observable, a count, and bounded opaque references.

The existing Inbox observer also ignores host field values and request payloads. Its row-level signal is used only to associate an observed Inbox row with an already-known opaque candidate trace.

== Evidence-first diagnostic synthesis ==
Normal-request reports now add a deterministic `synthesis` section over the evidence already collected by DEEP. This is intentionally small and bounded rather than a generic graph/rule engine or profiler.

The synthesis separates:

- observed facts: request-local timing and state that DEEP actually measured;
- interpretation: what that retained evidence supports;
- claim ceiling / unknowns: what cannot be established from the retained evidence;
- next step: the smallest useful action that can discriminate between plausible explanations or collect the missing measurement.

Measured blocking HTTP time may be reported as a strong latency contributor only when it materially overlaps the same request. Mere HTTP presence is not a latency diagnosis. Measured query timing may support a database-latency finding, while unavailable query timing remains an explicit unknown rather than a fabricated diagnosis. The first materially slow retained lifecycle interval may be reported as a boundary without blaming the hook at the end of that interval. Large autoload size and high asset count remain risk signals unless request-local timing connects them to the observed delay. Multiple measured contributors remain multiple findings when DEEP cannot choose one culprit.

WP-Cron and Gravity evidence keep their existing independent classifications. Their presence in the same report never creates generic request-latency attribution.

The historical `bottlenecks` score list remains in the report for backward compatibility. It is legacy heuristic output, not the evidence-first causal authority. The Tools -> Deep Diagnostics normal-request surface leads with the synthesis result, plain-language meaning, supporting evidence, claim ceiling, and next step; technical/LLM-ready JSON is available through progressive disclosure.

== WP-Cron diagnostics ==
The Tools -> Deep Diagnostics screen exposes a native WordPress WP-Cron Diagnostics section. Passive rendering only reads evidence. The `Run Cron Qualification` form is an explicit capability- and nonce-protected POST action that schedules one single Deep Diagnostics-owned probe when another qualification is not already pending.

The report records observable configuration (`DISABLE_WP_CRON`, `ALTERNATE_WP_CRON`), currently ready events through the public WordPress Cron API, the qualification session identifier, expected and observed execution timestamps, measured delay, execution context, proven evidence, and unknowns. A due-but-not-observed probe remains pending/ambiguous; no universal healthy-delay threshold or root cause is invented.

Diagnostic sessions are bounded, automatically expire, and keep only the current WP-Cron qualification pointer. They are deliberately small correlation primitives intended to support cross-request diagnostics without creating a general tracing platform.

== Gravity Forms / Gravity Flow diagnostics ==
The Tools -> Deep Diagnostics screen reports observable Gravity Forms / Gravity Flow availability and version metadata when the host runtime exposes it. No business workflow state is inferred from product presence alone.

The `Start Gravity Diagnostic` form is an explicit `manage_options` and nonce-protected POST action. When Gravity Flow is observable, it opens one bounded 15-minute Diagnostic Session. The session keeps at most 10 candidate entry traces, 24 lifecycle events per trace, and 20 Inbox request samples. Passive admin rendering never starts the diagnostic or repairs stale state.

The causal observer uses documented Gravity Forms / Gravity Flow extension seams to reconstruct server-side evidence around:

`Entry created -> Submission completed -> Step started -> Assignees observed -> Step completed / next step -> Workflow completed`

and integrates that chronology with the existing Gravity Flow Inbox rendering observer.

The observer uses `gform_entry_created`, `gform_after_submission`, `gravityflow_step_start`, `gravityflow_step_assignees`, `gravityflow_step_complete`, `gravityflow_post_process_workflow`, and `gravityflow_workflow_complete`. Filters return the supplied host values unchanged and actions do not mutate Entry, Step, assignee, assignment, routing, status, or workflow state.

Entry, Form, Step, and assignee identities are represented in persisted/reportable evidence by session-local opaque references such as trace/form/step/assignee refs. Several candidate entries may coexist in the same session. If more than one candidate is observed, the diagnostic preserves that ambiguity rather than guessing which visitor submission caused later evidence.

The existing Inbox observer remains in place. `gravityflow_columns_inbox_table` provides a request-level render/refresh signal and `gravityflow_inbox_field_value` provides a row-level opportunity to associate an already-observed candidate entry with that request. Authentic Gravity Flow 3.1.0 qualification also established its native REST Live Data Refresh path; DEEP's repair remains narrowly scoped to candidate-linked Inbox evidence rather than generalized network tracing. No callback persists supplied field values, request/response bodies, or raw Entry content.

The deterministic analysis identifies the first missing/inconsistent evidence boundary with classifications such as `ENTRY_NOT_OBSERVED`, `SUBMISSION_COMPLETE_NOT_OBSERVED`, `WORKFLOW_NOT_OBSERVED`, `STEP_NOT_OBSERVED`, `NO_ASSIGNEE_OBSERVED`, `INBOX_REFRESH_NOT_OBSERVED`, `MULTIPLE_ENTRY_CANDIDATES`, `INSUFFICIENT_EVIDENCE`, and `TRACE_COMPLETE_TO_SERVER_INBOX_OBSERVATION`. These are evidence interpretations, not root-cause claims.

`TRACE_COMPLETE_TO_SERVER_INBOX_OBSERVATION` means only that the server-side chain reached an Inbox row/render associated with the opaque trace. It does not prove browser-visible refresh completion, network round-trip success, expected-assignee correctness, or workflow completion unless those separate events are also observed.

No generic expected-assignee policy is configured. The diagnostics can report assignee evaluation, type/count and opaque comparison references, but they do not call an assignment "correct" without an external expected value.

The next normal request includes the bounded Gravity causal/session evidence in the existing report, JSON, Markdown, and LLM-ready bundle. Ordinary AJAX and REST requests still do not finalize general Deep Diagnostics reports; only documented Gravity host seams inside an explicitly active bounded session persist their minimal evidence.

This module is reusable host diagnostics. It does not encode SRWF/application workflow rules, fixed form IDs, fixed step IDs, field mappings, user identities, or Gravity Flow business semantics.

See `docs/gravity-causal-trace-evidence.md` and `docs/gravity-authentic-host-qualification.md` for evidence and claim boundaries.

== Known context limitation ==
`Manager::finalize()` still intentionally skips ordinary AJAX and REST request reports because those contexts do not have general-purpose report/session correlation semantics. Gravity causal tracing is a narrow exception: during an explicit bounded session it stores only evidence from documented/candidate-linked Gravity host seams. WP-Cron callbacks similarly do not finalize ordinary latency reports; the Cron module persists only bounded correlated qualification evidence and the next normal request integrates that evidence into the report.

Authentic licensed qualification now exists for the exact PR #9 reference stack: Gravity Forms 3.1.1.1, Gravity Flow 3.1.0, WordPress 6.5, PHP 8.1.34, and Chromium. It must not be generalized to other host versions or current-WordPress commercial runtime without separate evidence. SRWF-specific behavior, notification/feed timing, Gravity View/Perks, production-load/overhead qualification, generalized AJAX/REST/network tracing, tokenized/unauthenticated assignee contexts, and business-specific expected-assignee rules remain outside that claim.

== Analyzer limitation ==
The legacy performance scores remain heuristic compatibility data. The evidence-first synthesis does not convert HTTP presence, autoload size, asset count, Cron state, or Gravity state into causal proof. A strong generic-latency finding requires request-local measured timing that materially overlaps the observed request; unavailable measurements remain unknown, and multiple plausible measured contributors remain separate.

The Gravity causal analyzer remains deterministic evidence-gap analysis rather than a root-cause engine. A missing hook means that hook was not observed in the bounded diagnostic session; it does not prove that Gravity Forms or Gravity Flow is broken.

== Changelog ==
= Unreleased foundation =
* Added bounded evidence-first normal-request synthesis across measured lifecycle, HTTP, query, autoload/cache, and asset evidence, with explicit facts, interpretations, claim ceilings, unknowns, and next steps.
* Added a self-guided native wp-admin normal-request result surface with technical/LLM JSON moved behind progressive disclosure while retaining the legacy report contract additively.
* Corrected stale post-PR9 documentation/UI claims to record the exact authentic Gravity Forms 3.1.1.1 / Gravity Flow 3.1.0 / WordPress 6.5 / PHP 8.1.34 / Chromium qualification without widening DEEP's own browser classification.
* Extended the bounded Gravity diagnostic session into a privacy-safe server-side causal trace from entry creation through workflow-step/assignee evidence and existing Inbox observation.
* Added opaque session-local Entry/Form/Step/Assignee correlation, multi-candidate ambiguity handling, bounded lifecycle retention, and deterministic first-inconsistent-point analysis.
* Added CI-only documented-hook lifecycle integration across real WordPress request boundaries while preserving the licensed-host evidence ceiling.
* Added bounded, explicit Gravity Flow Inbox observation using documented Inbox seams without persisting host business data.
* Added Gravity Forms / Gravity Flow host availability metadata and additive privacy-safe report/Markdown/LLM evidence.
* Added reusable bounded Diagnostic Session correlation for cross-request diagnostic evidence.
* Added generic WP-Cron Diagnostics using public WordPress scheduling/inspection APIs without persisting Cron arguments.
* Added explicit nonce/capability-protected WP-Cron qualification with pending/completed/error/unknown evidence states.
* Added additive Cron evidence to JSON, Markdown, and LLM-ready reports while preserving the centralized Redactor privacy authority.
* Added PHP unit coverage and real WordPress 6.5/current Cron lifecycle integration evidence, including disabled and stale-state checks.
* Established one authoritative editable plugin source and deterministic packaging.
* Added PHPUnit and GitHub Actions verification, including disposable WordPress smoke coverage.
* Fixed WordPress lifecycle hook callback signatures and HTTP fingerprint fatal risk.
* Corrected lifecycle elapsed-time attribution and autoload-option inspection for modern WordPress markers.
* Added a centralized privacy/minimization boundary for persisted and LLM-ready reports.

= 1.5.9 =
* Hardened transient keys by sanitizing and hashing remote addresses.
* Added dedicated input helper for superglobal access.
* Prevented report filename collisions with microsecond/random suffixes.
* Replaced shutdown did_action() fallback check with explicit Manager finalization state.
* Improved bootstrap metadata and admin wrapper CSS targeting.

= 1.5.8 =
* Fixed fatal error from undefined wp_get_untrusted_ip().
* Fixed broken HTTP fingerprint (removed random nonce).
* Moved collector hook registration to plugins_loaded.
* Finalize now skips AJAX/REST/Cron contexts.
* Prepared SQL in SystemInspector.
* Removed unreliable asset path fallback.
* Asset collection restricted to admin screens.
