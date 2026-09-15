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

These checks do not claim browser-level, production-load, AJAX, REST, Gravity Forms, or Gravity Flow qualification.

== Privacy boundary ==
Collectors may temporarily observe raw runtime values needed to correlate one request, but persisted/reportable data uses `WDDTF\Privacy\Redactor` as the centralized minimization authority before normal reports, transients, JSON, Markdown, or the LLM bundle are produced. The bounded cross-request Diagnostic Session store also applies the same Redactor before persisting session data. The boundary removes URL query/fragment data, masks identifier-like path segments, SQL literal values, email/IP/token/credential patterns, and explicitly sensitive keyed values while preserving diagnostic shape and timing metadata.

WP-Cron ready-event evidence intentionally omits Cron argument values. The Deep Diagnostics-owned qualification event carries only an opaque diagnostic session identifier.

== WP-Cron diagnostics ==
The Tools -> Deep Diagnostics screen exposes a native WordPress WP-Cron Diagnostics section. Passive rendering only reads evidence. The `Run Cron Qualification` form is an explicit capability- and nonce-protected POST action that schedules one single Deep Diagnostics-owned probe when another qualification is not already pending.

The report records observable configuration (`DISABLE_WP_CRON`, `ALTERNATE_WP_CRON`), currently ready events through the public WordPress Cron API, the qualification session identifier, expected and observed execution timestamps, measured delay, execution context, proven evidence, and unknowns. A due-but-not-observed probe remains pending/ambiguous; no universal healthy-delay threshold or root cause is invented.

Diagnostic sessions are bounded, automatically expire, and keep only the current WP-Cron qualification pointer. They are deliberately small correlation primitives intended to support future cross-request diagnostics without creating a general tracing platform.

== Known context limitation ==
`Manager::finalize()` still intentionally skips AJAX and REST requests because those contexts do not yet have explicit report/session correlation semantics. WP-Cron callbacks also do not finalize ordinary latency reports; the Cron module persists only its bounded correlated qualification evidence and the next normal request integrates that evidence into the report.

Gravity Forms / Gravity Flow diagnostics remain unimplemented by design in this batch.

== Analyzer limitation ==
The current performance scores are heuristic signals, not causal proof. Reports rank the highest score first and avoid claiming that observed HTTP or Cron activity is the cause of latency without supporting timing/correlation evidence.

== Changelog ==
= Unreleased foundation =
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
