# Gravity Flow Inbox Observation Evidence Boundary

This batch adds reusable host diagnostics, not application workflow logic.

## Supported host seam

Deep Diagnostics observes the documented Gravity Flow `gravityflow_columns_inbox_table` filter. Gravity Flow documents this filter as applying to both the initial Inbox table and AJAX Inbox refreshes. The observer returns the supplied columns unchanged and ignores host arguments for persisted evidence.

## Bounded evidence

An authorized operator explicitly starts one observation window from Tools → Deep Diagnostics. The session lasts at most 15 minutes and stores at most 20 observed Inbox-request samples. Samples contain only request transport, server-side elapsed time, memory peak, database query count when observable, hook identity, hook-call count, and observation timestamp.

The observer does not persist form IDs, entry IDs, users, field values, request payloads, or Gravity Flow hook arguments. Session persistence uses the existing `WDDTF\Diagnostics\SessionStore`, which applies the centralized `WDDTF\Privacy\Redactor` before writing transient state.

## Claim ceiling

Repository CI can prove Deep Diagnostics behavior around the documented extension seam by using a CI-only fixture that fires the same public filter through a real WordPress `admin-ajax.php` request. That is not an authentic licensed Gravity Flow runtime. A real Gravity Forms / Gravity Flow package execution remains required before claiming host-version-specific integration qualification.

The evidence also does not measure browser/network round trip, user-perceived refresh completion, production-load overhead, or root cause. It does not enable ordinary AJAX/REST report finalization and it does not contain SRWF-specific business logic.
