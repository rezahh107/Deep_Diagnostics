# Authentic Gravity Forms / Gravity Flow Qualification

## Scope and claim boundary

This record qualifies Deep Diagnostics against Owner-authorized commercial Gravity Forms and Gravity Flow packages in a disposable local WordPress runtime. The commercial archives were test inputs only: they were not committed, uploaded as public CI artifacts, redistributed, or copied into the repository.

This qualification keeps three facts separate:

1. the authentic Gravity runtime executed the reference workflow;
2. Deep Diagnostics observed/correlated that authentic runtime;
3. the expected synthetic Entry actually became visible to the assigned operator through native Gravity Flow Live Data Refresh.

A positive result for one fact is not substituted for another.

## Inputs

- Starting repository baseline: `main@2c18f9a0632155c0e1919fbdb8bfcc1926a5974b`.
- Qualification branch: `qualify/authentic-gravity-runtime-final`.
- WordPress: 6.5.
- PHP: 8.1.34.
- Database: MariaDB 10.11.19.
- Browser: Chromium 144.0.7559.96 controlled through Playwright.
- Gravity Forms archive: `gravityforms-3.1.1.1-source-package.zip`.
  - Detected version after installation: 3.1.1.1.
  - SHA-256: `542f56ae0747f3661d1474996527298027db3fb8ed3e6469a6391aaabf61069b`.
- Gravity Flow archive: `gravityflow-3.1.0-owner-supplied-source-package.zip`.
  - Detected version after installation: 3.1.0.
  - SHA-256: `ac0573b75831380417a21a455176e25eb746d718bbbd0bb70d6da6f48cba5404`.
- Package provenance: Owner-authorized connected project/Drive material. No license secret was recorded.

Authentic runtime execution used Deep Diagnostics product files from commit `84964d13124a9ca60e46cc711f648b8668e944d5`. The deterministic plugin ZIP built from that commit had SHA-256 `3121b57aa322bc77439954becdfda0b5a9f4b47f7229d31335e7d30ca2383d3c`. Repository-only evidence/CI cleanup after that commit does not enter the packaged plugin; final PR validation must reproduce the same ZIP hash before merge.

## Reference workflow

The disposable site contained:

- one real Gravity Form with synthetic text/email/phone canaries;
- one real browser submission through Gravity Forms;
- one real Gravity Flow workflow;
- the workflow-start step followed by one real Approval step;
- one synthetic WordPress operator deliberately assigned to that Approval step;
- the real Gravity Flow Inbox opened as that operator;
- Gravity Flow's native Live Data Refresh left enabled at its host behavior/settings.

The test expectation that the controlled operator is the intended assignee exists only in this qualification. Deep Diagnostics still does not implement generic business-specific "correct assignee" rules.

## Authentic baseline falsification

Authentic runtime testing exposed three concrete compatibility defects before the final passing run:

1. `SystemInspector` passed maybe-unserialized scalar option values directly into `strlen()` under strict PHP 8.1. Real WordPress autoloaded options can be integers/bools. The fix measures `(string) maybe_serialize($value)` and adds a scalar regression test.
2. `AssetAnalyzer` assumed every registered dependency source was a string URL. Real WordPress/plugin dependency aliases can use non-string sources. The fix counts those handles but skips URL/path inspection when the source is not a non-empty string, with focused regression coverage.
3. Gravity Flow 3.1.0's native Inbox Live Data Refresh uses a REST `fetch()` path (`.../wp-json/gravityflow/internal/inbox/changes`) whose callback does not retain the WordPress current user, even though the same-origin browser carries a valid logged-in WordPress cookie. The previous Deep Diagnostics browser layer was AJAX/jQuery-only and rejected REST samples.

The third defect was repaired narrowly rather than broadening into generalized network tracing:

- candidate-linked REST Inbox samples are admitted alongside candidate-linked AJAX samples;
- response tagging first uses the existing authenticated Inbox boundary; for REST only, when the host callback has no current user, Deep Diagnostics validates the standard WordPress logged-in cookie with `wp_validate_auth_cookie()` and then requires `user_can(..., 'gravityflow_inbox')`;
- the evidence write endpoint is unchanged: authenticated `wp_ajax`, current bounded session, session nonce, Inbox capability, strict schema, and server-issued opaque sample reference are still required;
- the scoped Inbox observer adds a minimal `fetch()` wrapper only while the bounded authorized diagnostic observer is loaded. It reads only the server-issued `X-WDDTF-Gravity-Sample` response header and status/timing metadata. It does not read/store URL, request body, response body, DOM content, cookies, or unrelated headers.

## Passing authentic scenario

The final passing authentic scenario observed:

- real Gravity Forms browser submission succeeded;
- the new real Entry reached the authentic Gravity Flow Approval step;
- the step was assigned to the deliberately configured synthetic operator;
- the real operator Inbox was already open before submission;
- native Gravity Flow Live Data Refresh executed without manual reload;
- the expected Entry row became visible in the real Inbox without manual reload;
- the correlated authentic REST refresh returned HTTP 200 with a bounded opaque `gb-...` sample header;
- Deep Diagnostics persisted one candidate-linked REST sample and one bounded browser evidence object;
- Deep Diagnostics server classification: `TRACE_COMPLETE_TO_SERVER_INBOX_OBSERVATION`;
- Deep Diagnostics browser classification: `BROWSER_UI_SIGNAL_OBSERVED`;
- browser metadata recorded `ui_signal=dom_mutation`, `visibility=visible`, and a measured client duration;
- `entry_visible_to_user_proven` remained `false` inside Deep Diagnostics. The actual row visibility claim came from the separate browser assertion, not from Deep Diagnostics metadata.

Manual reload discrimination was not required in the passing run because the expected Entry was independently visible through native Live Refresh before any reload.

## Privacy and security

Synthetic canaries were used in submitted text/email/phone fields and browser/runtime payloads. Inspection of retained Diagnostic Session evidence and reportable Gravity output showed only opaque `gt-`, `gf-`, `gs-`, `ga-`, and `gb-` references plus bounded technical metadata. Raw synthetic field values, names, email/phone content, host IDs, row text, request URL/query strings, request/response bodies, cookies, and license material were not admitted to diagnostic evidence.

The real-host repair does not weaken diagnostic start authorization or browser evidence writes:

- starting the diagnostic remains administrator-controlled;
- the authentic Inbox operator has the legitimate Gravity Flow Inbox capability;
- REST response tagging requires a valid standard WordPress logged-in cookie for a user with Inbox permission when the host REST callback itself has no current user;
- forged sample references remain rejected;
- invalid nonces remain rejected;
- expired sessions remain rejected;
- unrelated AJAX/REST requests that do not pass the candidate-linked Inbox seams are not stored as browser evidence;
- a browser evidence sample remains idempotent and bounded by the existing Diagnostic Session limits.

## Public regression model

Commercial packages are not placed in public GitHub Actions. Public CI remains proprietary-free. PHPUnit models candidate-linked REST samples, missing REST current-user state, the validated WordPress logged-in-cookie fallback, rejection without that validated Inbox user, and REST evidence admission. Source-level browser tests lock the scoped native `fetch()` observer to the server-issued sample header and forbid URL/body/content inspection. Existing Chromium fixture coverage continues to verify the established browser-evidence transport/timing path.

The authentic Chromium run is the evidence that the native Gravity Flow REST `fetch()` path itself works with this repair; public fixture CI is complementary evidence and is not represented as commercial-host execution.

## Evidence ceiling

This qualification proves the reference scenario for the exact Owner-authorized Gravity Forms 3.1.1.1 / Gravity Flow 3.1.0 packages on WordPress 6.5 / PHP 8.1.34 in the disposable environment described above.

It does **not** prove:

- all Gravity Forms or Gravity Flow versions;
- tokenized/unauthenticated email-assignee Inbox contexts;
- SRWF-specific workflow/business behavior;
- production-load/performance characteristics;
- notification/feed timing;
- Gravity View or Gravity Perks integration;
- generalized AJAX/REST/network tracing.

The current WordPress-release public CI remains fixture-based. A second authentic qualification against current WordPress may be performed later with the same authorized archives, but this document does not convert fixture evidence into a commercial-host claim.
