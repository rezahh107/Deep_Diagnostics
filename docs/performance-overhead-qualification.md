# Performance / overhead qualification

Deep Diagnostics measures WordPress. This qualification measures Deep Diagnostics itself.

The canonical benchmark runs in a disposable WordPress + MySQL runtime and compares the same request workload with the plugin inactive and active. `report.meta.elapsed_ms` is intentionally **not** used as the primary overhead measure because it describes a request observed while the plugin is already running; it cannot establish the incremental cost caused by the plugin.

## Canonical scenarios

The workflow characterizes three bounded scenarios with `SAVEQUERIES` disabled:

- `frontend`: anonymous request for one stable local page;
- `admin`: authenticated WordPress Dashboard request;
- `diagnostic_workload`: authenticated Dashboard request plus a small deterministic fixture that performs local database reads and three blocking HTTP requests to a local test server.

The local HTTP fixture exists only to give the existing HTTP collector real, bounded work. No public network service is used by the measured scenarios. WordPress automatic cron is disabled in the lab so incidental loopback spawning does not contaminate the samples.

`SAVEQUERIES` remains disabled in these canonical scenarios. Query-count evidence comes from WordPress's normal request query counter. If precise query-timing overhead is characterized later with `SAVEQUERIES=true`, it must be a separate scenario so WordPress's own `SAVEQUERIES` cost is not attributed to Deep Diagnostics.

## Measurement model

For every state/scenario pair the harness performs five warm-up requests followed by fifteen measured requests. The machine-readable artifact retains every measured sample and reports median, minimum, 25th percentile, 75th percentile, and maximum.

The primary timing is `completion_wall_ms`: external client request start until a test-only must-use plugin observes the WordPress `shutdown` hook **after** Deep Diagnostics' normal `Manager::finalize()` callback. That means normal snapshot collection, privacy redaction, analysis / causal synthesis, JSON and Markdown generation, file persistence, and transient persistence are inside the active measurement.

`client_wall_ms` is retained separately. The harness also records `post_response_wait_ms`; a non-trivial value would show that the client received the response before post-finalization evidence became visible. This prevents silent exclusion of shutdown work.

The test-only probe runs in both inactive and active states and records peak memory, final memory usage, WordPress query count, a narrow `plugins_loaded` timing window, and a narrow shutdown window around the product's finalizer. Those phase windows are attribution aids for this controlled runtime; they are not a generic profiler and are not shipped with the plugin.

## CI contract

`.github/workflows/performance-overhead.yml` blocks when the benchmark cannot complete, the requested plugin state does not match the observed state, required sample counts are absent, a probe record is missing, or the result contract is malformed.

This first characterization intentionally has **no numeric overhead budget**. The workflow publishes evidence; it does not decide what number should become a product acceptance threshold.

## Reproduction

The GitHub Actions workflow is the canonical reproducible path because it provisions the whole disposable runtime. The benchmark script itself is `tests/performance/benchmark.py`; it expects an already-installed WordPress site, the test-only probe installed as an MU-plugin, a running HTTP server for WordPress, and the local deterministic HTTP fixture URL stored in `wddtf_benchmark_http_url`.

The output artifact `performance-result.json` is runner evidence, not a universal constant. Do not commit one run's measurements into the repository as a product guarantee.

## Claim boundary

This qualification can support claims about the exercised disposable runtime and the exact request scenarios above. It does not prove production traffic performance, arbitrary hosting stacks, every WordPress/plugin/theme combination, commercial Gravity runtimes, or SRWF production behavior.
