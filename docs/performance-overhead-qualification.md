# Deep Diagnostics performance / overhead qualification

This repository has a dedicated characterization harness for measuring the **incremental request overhead caused by Deep Diagnostics itself**. It is evidence for later budget-setting; it is not a product performance budget and does not turn one runner result into a production guarantee.

## What is measured

The canonical CI job uses one disposable WordPress 6.5 / PHP 8.1 / MySQL 8 runtime and the generated plugin artifact from the exact triggering revision. Each scenario is measured in matched pairs with Deep Diagnostics inactive (`control`) and active (`active`). Odd pairs run control then active; even pairs reverse that order to reduce one-direction warm-cache or runner drift.

The three canonical scenarios are:

1. **Normal anonymous frontend** — the installed site's normal front page with no authenticated cookie jar.
2. **Normal authenticated wp-admin** — the normal Dashboard request using one already-authenticated administrator session.
3. **Bounded diagnostic workload** — a CI/local-only Tools page supplied by the benchmark probe. It performs a fixed set of local WordPress database reads and enqueues a bounded set of local WordPress assets so existing collection/finalization work has deterministic material to inspect. It makes no public-network request.

`SAVEQUERIES` is intentionally **off** in this baseline. Database-query count comes from `wpdb->num_queries`, so the generic cost of WordPress query timing is not attributed to Deep Diagnostics. If a later qualification enables `SAVEQUERIES`, that must be a separate named scenario/result.

External HTTP is blocked in the disposable runtime with `WP_HTTP_BLOCK_EXTERNAL=true` so update checks or other public services do not become benchmark noise.

## Lifecycle / timing boundary

The benchmark deliberately records two external timing boundaries rather than assuming that HTTP response completion means PHP request completion:

- **response wall time** — monotonic time around the external cURL request until the client has received the response;
- **full lifecycle wall time** — monotonic time from request start until a post-finalize shutdown marker is externally observable. This is the canonical total-overhead measure.

A test-only must-use plugin records a late-shutdown boundary at WordPress `shutdown` priority `9998` and writes the completion marker at `PHP_INT_MAX`. Deep Diagnostics currently finalizes its normal report at `shutdown` priority `9999`, so the active late-shutdown interval includes the real `Manager::finalize()` path: collector snapshots, centralized redaction, diagnostics analysis / causal synthesis, JSON and Markdown generation, file persistence, and transient persistence, plus later WordPress shutdown hooks before the marker.

The disposable PHP server can make a response visible before all shutdown work finishes. The benchmark therefore does **not** discard that case. After response completion it waits for the marker, polling every 250 microseconds with a five-second validity timeout, and records that interval separately as `post_response_ms`. The polling interval is a detection mechanism, not synthetic workload; its small observation latency is explicitly a caveat and no sub-millisecond precision is claimed from it.

If the marker never appears within the bounded timeout, the run fails as invalid instead of silently omitting finalization cost.

The probe is deliberately small and identical in control and active states. It records:

- full lifecycle external wall-clock time;
- HTTP response wall-clock time;
- externally observed post-response completion interval;
- PHP request peak memory;
- `wpdb->num_queries` at the post-shutdown boundary;
- late-shutdown duration around finalization;
- the exact active/inactive state used by the request.

The late-shutdown interval is a bounded phase boundary, not a general profiler. It can support a broad conclusion such as “most measured delta is in report finalization” only when the observed deltas actually support that interpretation.

## Warm-up and report-history policy

Every measured state sample has at least one same-state warm-up request first. The plugin report directory is warmed by that request. Prior `report-*` files are then removed **outside** the measured request so benchmark-generated history does not grow across samples and turn file-glob cleanup into a time-correlated artifact. The directory itself remains warm.

The machine-readable result records this policy and the runtime's opcache CLI state. The workflow does not enable Xdebug, Blackfire, Tideways, Query Monitor, `SAVEQUERIES`, or another profiler in the canonical baseline.

## Statistics and validity

The canonical CI job records 15 matched pairs per scenario. Raw samples are retained in the artifact. Summaries include median, 25th/75th percentiles, minimum, and maximum for each state, together with active-minus-control and paired active-minus-control deltas.

A benchmark run is blocking when qualification itself is invalid, including:

- a request cannot complete;
- the expected Deep Diagnostics active/inactive state is not the state actually observed;
- a control/active pair is missing or duplicated;
- the required sample count is incomplete;
- timing/memory/query/shutdown metrics are malformed;
- the post-shutdown marker does not appear within the bounded validity timeout;
- the output contract cannot be produced.

There is intentionally **no numeric overhead threshold** in this first characterization system. A later Owner decision can set a budget using collected evidence rather than an invented number.

## Result contract

`artifacts/performance-overhead.json` is the stable machine-readable result. `schema_version` is currently `1`. It contains:

- exact product/runtime environment and git revision;
- measurement and warm-up policy;
- canonical scenario identity and authentication mode;
- bounded raw samples for control and active states;
- distribution summaries and matched deltas;
- explicit measurement caveats.

`artifacts/performance-overhead.md` is the human-readable interpretation that is also appended to the GitHub Actions job summary. Both files are uploaded as a short-retention Actions artifact. Runner-specific results are **not committed** as universal constants.

## Reproducing locally

The harness is repository-contained, but it expects the same ordinary runtime tools as CI: PHP 8.1+, the PHP cURL extension, WP-CLI, a reachable MySQL-compatible database, and a local WordPress install served over HTTP.

After installing the generated `wp-deep-diagnostics` artifact into the local WordPress site (initially inactive), copy:

```text
tests/performance/benchmark-probe.php
```

to:

```text
wp-content/mu-plugins/wddtf-performance-benchmark-probe.php
```

Serve WordPress locally, create/login the benchmark administrator credentials used by the driver, then run:

```bash
php tests/performance/benchmark.php \
  --wordpress-dir=/absolute/path/to/wordpress \
  --base-url=http://127.0.0.1:8080 \
  --samples=15 \
  --warmups=1 \
  --git-sha="$(git rev-parse HEAD)" \
  --output=/tmp/performance-overhead.json \
  --summary=/tmp/performance-overhead.md
```

For exact CI reproduction, use the setup in `.github/workflows/performance-overhead.yml`, including WordPress 6.5, PHP 8.1, MySQL 8, the generated plugin ZIP, and `WP_HTTP_BLOCK_EXTERNAL=true`.

## What this evidence does and does not prove

A successful run verifies that the harness completed valid matched control/active measurements in the exercised disposable runtime, including Deep Diagnostics' normal late-shutdown finalization work. It produces evidence suitable for comparing broad request and finalization overhead in that environment.

It does **not** verify production traffic, arbitrary hosting stacks, all WordPress versions, every plugin/theme combination, commercial Gravity runtimes, SRWF production behavior, or a universal acceptable overhead budget. Those claims require separate evidence.
