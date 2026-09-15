=== WP Deep Diagnostics ===
Contributors: openai
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 1.5.9

== Description ==
Forensic WordPress latency telemetry and privacy-safe, LLM-ready diagnostic reporting.

== Engineering source of truth ==
The repository root (`wp-deep-diagnostics.php`, `src/`, `templates/`, `assets/`, and `uninstall.php`) is the only editable plugin implementation. `create_fixed_plugin.py` does not embed PHP source; it deterministically assembles the root source into `build/wp-deep-diagnostics/` and `dist/wp-deep-diagnostics.zip`.

Build the installable artifact with:

    python3 create_fixed_plugin.py

The generated `build/` and `dist/` directories are intentionally not committed.

== Verification ==
Development tests use PHPUnit 10. GitHub Actions runs PHP syntax checks and deterministic unit/regression tests on PHP 8.1 and 8.3. Disposable WordPress jobs exercise the declared baseline WordPress 6.5 on PHP 8.1 and the current WordPress release on PHP 8.1 by installing the generated artifact, booting it, completing a normal diagnostic finalization, and checking the persisted report contract.

The WordPress jobs also exercise the real WP-Cron qualification lifecycle: passive admin rendering must not schedule a probe, an explicit qualification schedules exactly one Deep Diagnostics single event, a duplicate pending qualification is rejected, WP-CLI executes the scheduled probe through WordPress Cron, the correlated callback evidence is observed and persisted, and disabled/stale states fail closed without invented success.

Gravity Forms / Gravity Flow unit coverage exercises bounded observation/session behavior and the additive report contract. Disposable WordPress jobs also exercise the Deep Diagnostics cross-request AJAX observation path with a CI-only host fixture that fires the documented `gravityflow_columns_inbox_table` filter. The fixture proves Deep Diagnostics can correlate and persist an AJAX Inbox sample through a real `admin-ajax.php` request without storing the fixture's entry/form payload. It is not a licensed Gravity Flow runtime and does not substitute for authentic Gravity Flow integration evidence.

These checks do not claim browser-level acceptance, production-load qualification, general AJAX/REST report correlation, or authentic licensed Gravity Forms / Gravity Flow runtime qualification.

== Privacy boundary ==
Collectors may temporarily observe raw runtime values needed to correlate one request, but persisted/reportable data uses `WDDTF\Privacy\Redactor` as the centralized minimization authority before normal reports, transients, JSON, Markdown, or the LLM bundle are produced. The bounded cross-request Diagnostic Session store also applies the same Redactor before persisting session data. The boundary removes URL query/fragment data, masks identifier-like path segments, SQL literal values, email/IP/token/credential patterns, and explicitly sensitive keyed values while preserving diagnostic shape and timing metadata.

WP-Cron ready-event evidence intentionally omits Cron argument values. The Deep Diagnostics-owned qualification event carries only an opaque diagnostic session identifier.

Gravity Flow Inbox observation intentionally ignores host hook arguments when persisting evidence. It stores only bounded request metadata: observation timestamp, request transport, measured server-side request elapsed time, memory peak, database query count when observable, observer-hook identity, and hook-call count. It does not store form IDs, entry IDs, users, field values, or request payloads.

== WP-Cron diagnostics ==
The Tools -> Deep Diagnostics screen exposes a native WordPress WP-Cron Diagnostics section. Passive rendering only reads evidence. The `Run Cron Qualification` form is an explicit capability- and nonce-protected POST action that schedules one single Deep Diagnostics-owned probe when another qualification is not already pending.

The report records observable configuration (`DISABLE_WP_CRON`, `ALTERNATE_WP_CRON`), currently ready events through the public WordPress Cron API, the qualification session identifier, expected and observed execution timestamps, measured delay, execution context, proven evidence, and unknowns. A due-but-not-observed probe remains pending/ambiguous; no universal healthy-delay threshold or root cause is invented.

Diagnostic sessions are bounded, automatically expire, and keep only the current WP-Cron qualification pointer. They are deliberately small correlation primitives intended to support future cross-request diagnostics without creating a general tracing platform.

== Gravity Forms / Gravity Flow diagnostics ==
The Tools -> Deep Diagnostics screen reports observable Gravity Forms / Gravity Flow availability and version metadata when the host runtime exposes it. No business workflow state is inferred from product presence alone.

The `Start Inbox Observation` form is an explicit `manage_options` and nonce-protected POST action. When Gravity Flow is observable, it opens one bounded 15-minute Diagnostic Session with a maximum of 20 request samples. Passive admin rendering never starts an observation or repairs stale state.

The observer attaches to Gravity Flow's documented `gravityflow_columns_inbox_table` filter, which Gravity Flow documents as running on the initial Inbox render and on AJAX refreshes. The callback returns the host columns unchanged and does not persist the supplied host arguments. If the filter fires during an active observation window, Deep Diagnostics records one privacy-minimized request sample at shutdown. This lets an AJAX Inbox refresh be correlated without enabling ordinary Deep Diagnostics report finalization for every AJAX request.

The next normal request includes the current bounded Inbox observation in the existing report, JSON, Markdown, and LLM-ready bundle. The evidence distinguishes initial/admin and AJAX observations where the WordPress request context proves them. Server request elapsed time is observational evidence only: client/network round-trip time is not measured, and no latency threshold or root cause is inferred automatically.

This module is reusable host diagnostics. It does not encode SRWF/application workflow rules, form IDs, entry IDs, field mappings, user identities, or Gravity Flow business semantics.

== Known context limitation ==
`Manager::finalize()` still intentionally skips ordinary AJAX and REST request reports because those contexts do not have general-purpose report/session correlation semantics. The Gravity Flow Inbox observer is a narrow exception: during an explicit bounded session it stores only its minimal Inbox-render sample. WP-Cron callbacks similarly do not finalize ordinary latency reports; the Cron module persists only bounded correlated qualification evidence and the next normal request integrates that evidence into the report.

Authentic licensed Gravity Forms / Gravity Flow runtime execution is still required before claiming host-version-specific integration qualification. The CI-only fixture exercises the documented extension seam and Deep Diagnostics behavior, not proprietary host implementation internals.

== Analyzer limitation ==
The current performance scores are heuristic signals, not causal proof. Reports rank the highest score first and avoid claiming that observed HTTP, Cron, or Gravity Flow activity is the cause of latency without supporting timing/correlation evidence.

== Changelog ==
= Unreleased foundation =
* Added bounded, explicit Gravity Flow Inbox observation using the documented Inbox table filter for initial/AJAX render evidence without persisting host business data.
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
