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
Development tests use PHPUnit 10. GitHub Actions runs PHP syntax checks and deterministic unit/regression tests on PHP 8.1 and 8.3. A disposable WordPress smoke job exercises the declared baseline WordPress 6.5 on PHP 8.1 and the current WordPress release on PHP 8.1 by installing the generated artifact, booting it, completing a normal diagnostic finalization, and checking the persisted report contract.

These checks do not claim browser-level, production-load, AJAX, REST, Cron, Gravity Forms, or Gravity Flow qualification.

== Privacy boundary ==
Collectors may temporarily observe raw runtime values needed to correlate one request, but persisted/reportable data crosses one minimization boundary in `WDDTF\Privacy\Redactor` before analysis, transients, JSON, Markdown, or the LLM bundle are produced. The boundary removes URL query/fragment data, masks identifier-like path segments, SQL literal values, email/IP/token/credential patterns, and explicitly sensitive keyed values while preserving diagnostic shape and timing metadata.

== Known context limitation ==
`Manager::finalize()` intentionally skips AJAX, REST, and WP-Cron requests today. This means current reports cannot qualify per-request correlation or latency in those contexts. Future WP-Cron and Gravity Forms/Gravity Flow diagnostic work must add explicit correlation/session semantics rather than treating normal-request finalization as proof for those contexts.

== Analyzer limitation ==
The current scores are heuristic signals, not causal proof. Reports rank the highest score first and avoid claiming that observed HTTP activity is the cause of latency without timing/correlation evidence.

== Changelog ==
= Unreleased foundation =
* Established one authoritative editable plugin source and deterministic packaging.
* Added PHPUnit and GitHub Actions verification, including disposable WordPress smoke coverage.
* Fixed WordPress lifecycle hook callback signatures and HTTP fingerprint fatal risk.
* Corrected lifecycle elapsed-time attribution and autoload-option inspection for modern WordPress markers.
* Added a centralized privacy/minimization boundary for persisted and LLM-ready reports.
* Preserved the current AJAX/REST/Cron finalization gap as an explicit future architecture constraint.

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
