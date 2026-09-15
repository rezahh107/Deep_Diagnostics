# Gravity Flow Inbox Observation Evidence Boundary

This capability adds reusable host diagnostics, not application workflow logic.

## Supported host seam

Deep Diagnostics observes documented Gravity Flow Inbox extension seams and returns supplied host values unchanged. The server-side row/render observer does not persist host business data. The later authentic-host repair also admits candidate-linked native Gravity Flow REST Live Data Refresh samples through the same bounded diagnostic session; it does not turn DEEP into a generalized AJAX/REST/network recorder.

## Bounded evidence

An authorized operator explicitly starts one observation window from Tools → Deep Diagnostics. The session lasts at most 15 minutes and stores at most 20 observed Inbox-request samples. Samples contain only bounded technical metadata such as request transport, server-side elapsed time, memory/query counts when observable, opaque candidate/sample references, and observation timestamps.

The observer does not persist form IDs, entry IDs, users, field values, request/response bodies, cookies, or arbitrary Gravity Flow hook arguments. Session persistence uses the existing `WDDTF\Diagnostics\SessionStore`, which applies the centralized `WDDTF\Privacy\Redactor` before writing transient state.

## Claim ceiling

Public repository CI proves Deep Diagnostics behavior around modeled/documented host seams using proprietary-free fixtures in disposable WordPress runtimes. Those fixture runs are not themselves authentic commercial Gravity Flow execution.

Separately, PR #9 completed an Owner-authorized authentic reference qualification with Gravity Forms 3.1.1.1, Gravity Flow 3.1.0, WordPress 6.5, PHP 8.1.34, and Chromium. In that exact scenario, the real workflow ran and the expected synthetic Entry independently became visible in the assigned operator's real Gravity Flow Inbox through native Live Data Refresh. Deep Diagnostics' own browser metadata deliberately remained narrower: `entry_visible_to_user_proven` stayed `false`, and the visibility proof came from the independent browser assertion.

That qualification does not prove all Gravity versions, current-WordPress commercial runtime, tokenized/unauthenticated assignee contexts, SRWF-specific behavior, production load/performance, notification/feed timing, Gravity View/Perks, or generalized AJAX/REST/network tracing. The bounded evidence also does not by itself establish root cause.
