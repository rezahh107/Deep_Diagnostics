#!/usr/bin/env python3
"""Paired external overhead benchmark for WP Deep Diagnostics."""
from __future__ import annotations

import argparse
import http.cookiejar
import json
import os
import statistics
import subprocess
import sys
import time
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

SCHEMA_VERSION = 1


def run(*args: str, cwd: Path | None = None) -> str:
    completed = subprocess.run(
        list(args),
        cwd=str(cwd) if cwd else None,
        check=True,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    return completed.stdout.strip()


def percentile(values: list[float], fraction: float) -> float:
    ordered = sorted(values)
    if not ordered:
        raise ValueError("cannot summarize empty sample set")
    if len(ordered) == 1:
        return ordered[0]
    position = (len(ordered) - 1) * fraction
    lower = int(position)
    upper = min(lower + 1, len(ordered) - 1)
    weight = position - lower
    return ordered[lower] * (1 - weight) + ordered[upper] * weight


def summarize(values: list[float]) -> dict[str, float]:
    return {
        "median": round(statistics.median(values), 3),
        "min": round(min(values), 3),
        "p25": round(percentile(values, 0.25), 3),
        "p75": round(percentile(values, 0.75), 3),
        "max": round(max(values), 3),
    }


def load_records(path: Path) -> list[dict[str, Any]]:
    if not path.exists():
        return []
    records: list[dict[str, Any]] = []
    for line in path.read_text(encoding="utf-8").splitlines():
        if line.strip():
            records.append(json.loads(line))
    return records


def wait_for_record(path: Path, token: str, deadline_s: float = 5.0) -> tuple[dict[str, Any], float]:
    started = time.perf_counter_ns()
    deadline = time.monotonic() + deadline_s
    while time.monotonic() < deadline:
        for record in reversed(load_records(path)):
            if record.get("token") == token:
                waited_ms = (time.perf_counter_ns() - started) / 1_000_000
                return record, waited_ms
        time.sleep(0.005)
    raise RuntimeError(f"probe record not observed for token {token}")


def request(opener: urllib.request.OpenerDirector, url: str) -> tuple[int, float]:
    started = time.perf_counter_ns()
    with opener.open(url, timeout=15) as response:
        status = int(response.status)
        response.read()
    elapsed_ms = (time.perf_counter_ns() - started) / 1_000_000
    return status, elapsed_ms


def login(base_url: str, username: str, password: str) -> urllib.request.OpenerDirector:
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    opener.open(f"{base_url}/wp-login.php", timeout=15).read()
    payload = urllib.parse.urlencode(
        {
            "log": username,
            "pwd": password,
            "wp-submit": "Log In",
            "redirect_to": f"{base_url}/wp-admin/",
            "testcookie": "1",
        }
    ).encode()
    request_obj = urllib.request.Request(f"{base_url}/wp-login.php", data=payload)
    with opener.open(request_obj, timeout=15) as response:
        response.read()
        if "/wp-admin" not in response.geturl():
            raise RuntimeError("benchmark admin login did not reach wp-admin")
    return opener


def ensure_plugin_state(wp_path: Path, active: bool) -> None:
    completed = subprocess.run(
        ["wp", "plugin", "is-active", "wp-deep-diagnostics", "--path", str(wp_path)],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        text=True,
    )
    if (completed.returncode == 0) != active:
        if active:
            run("wp", "plugin", "activate", "wp-deep-diagnostics", "--quiet", "--path", str(wp_path))
        else:
            run("wp", "plugin", "deactivate", "wp-deep-diagnostics", "--quiet", "--path", str(wp_path))


def metric_values(samples: list[dict[str, Any]], key: str) -> list[float]:
    values: list[float] = []
    for sample in samples:
        value = sample.get(key)
        if isinstance(value, (int, float)):
            values.append(float(value))
    return values


def summarize_samples(samples: list[dict[str, Any]]) -> dict[str, Any]:
    fields = [
        "client_wall_ms",
        "completion_wall_ms",
        "post_response_wait_ms",
        "peak_memory_bytes",
        "memory_usage_bytes",
        "query_count",
        "plugins_loaded_window_ms",
        "shutdown_window_ms",
    ]
    summary: dict[str, Any] = {"sample_count": len(samples), "metrics": {}}
    for field in fields:
        values = metric_values(samples, field)
        summary["metrics"][field] = summarize(values) if len(values) == len(samples) else None
    return summary


def build_url(base_url: str, path: str, scenario: str, token: str) -> str:
    separator = "&" if "?" in path else "?"
    query = urllib.parse.urlencode({"wddtf_bench_scenario": scenario, "wddtf_bench": token})
    return f"{base_url}{path}{separator}{query}"


def run_state(
    *,
    state: str,
    active: bool,
    scenario_id: str,
    path: str,
    opener: urllib.request.OpenerDirector,
    base_url: str,
    metrics_file: Path,
    wp_path: Path,
    warmups: int,
    samples: int,
) -> dict[str, Any]:
    ensure_plugin_state(wp_path, active)
    metrics_file.write_text("", encoding="utf-8")

    for index in range(warmups):
        token = f"warmup-{scenario_id}-{state}-{index}"
        status, _ = request(opener, build_url(base_url, path, scenario_id, token))
        if status != 200:
            raise RuntimeError(f"warm-up returned HTTP {status} for {scenario_id}/{state}")
        wait_for_record(metrics_file, token)

    metrics_file.write_text("", encoding="utf-8")
    measured: list[dict[str, Any]] = []

    for index in range(samples):
        token = f"sample-{scenario_id}-{state}-{index}"
        request_started = time.perf_counter_ns()
        status, client_wall_ms = request(opener, build_url(base_url, path, scenario_id, token))
        record, post_wait_ms = wait_for_record(metrics_file, token)
        completion_wall_ms = (time.perf_counter_ns() - request_started) / 1_000_000

        if status != 200:
            raise RuntimeError(f"HTTP {status} for {scenario_id}/{state}")
        if record.get("scenario") != scenario_id:
            raise RuntimeError(f"probe scenario mismatch for {token}")
        if bool(record.get("deep_active")) != active:
            raise RuntimeError(f"plugin-state mismatch for {token}")

        measured.append(
            {
                "index": index,
                "client_wall_ms": round(client_wall_ms, 3),
                "completion_wall_ms": round(completion_wall_ms, 3),
                "post_response_wait_ms": round(post_wait_ms, 3),
                "peak_memory_bytes": record.get("peak_memory_bytes"),
                "memory_usage_bytes": record.get("memory_usage_bytes"),
                "query_count": record.get("query_count"),
                "plugins_loaded_window_ms": round(float(record["plugins_loaded_window_ms"]), 3)
                if isinstance(record.get("plugins_loaded_window_ms"), (int, float))
                else None,
                "shutdown_window_ms": round(float(record["shutdown_window_ms"]), 3)
                if isinstance(record.get("shutdown_window_ms"), (int, float))
                else None,
            }
        )

    return {"plugin_state": state, "samples": measured, "summary": summarize_samples(measured)}


def median_metric(state: dict[str, Any], metric: str) -> float | None:
    block = state["summary"]["metrics"].get(metric)
    return float(block["median"]) if isinstance(block, dict) else None


def render_summary(result: dict[str, Any]) -> str:
    lines = [
        "# Deep Diagnostics overhead qualification",
        "",
        f"Environment: WordPress {result['environment']['wordpress']}, PHP {result['environment']['php']}, DB {result['environment']['database']}",
        f"Warm-ups: {result['policy']['warmup_requests_per_state']} per state; measured samples: {result['policy']['measured_requests_per_state']} per state.",
        "",
        "| Scenario | Control median completion | Active median completion | Median delta | Peak-memory delta | Query-count delta | Active shutdown window |",
        "| --- | ---: | ---: | ---: | ---: | ---: | ---: |",
    ]
    for scenario in result["scenarios"]:
        delta = scenario["delta_active_minus_control"]
        control = scenario["states"]["inactive"]
        active = scenario["states"]["active"]
        lines.append(
            "| {name} | {c:.3f} ms | {a:.3f} ms | {d:.3f} ms | {m:.0f} B | {q:.3f} | {s:.3f} ms |".format(
                name=scenario["id"],
                c=median_metric(control, "completion_wall_ms") or 0.0,
                a=median_metric(active, "completion_wall_ms") or 0.0,
                d=delta.get("completion_wall_ms") or 0.0,
                m=delta.get("peak_memory_bytes") or 0.0,
                q=delta.get("query_count") or 0.0,
                s=median_metric(active, "shutdown_window_ms") or 0.0,
            )
        )
    lines.extend(
        [
            "",
            "`completion_wall_ms` is the primary external/request-level timing. It runs from client request start until the test-only probe has observed the WordPress shutdown hook after Deep Diagnostics finalization.",
            "`client_wall_ms` is also retained. A non-trivial `post_response_wait_ms` would indicate that the client saw the response before the post-finalization probe record became visible.",
            "No numeric product budget is enforced by this workflow; only benchmark validity and result-contract integrity are blocking.",
            "",
        ]
    )
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--wp-path", type=Path, required=True)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--metrics-file", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--summary", type=Path, required=True)
    parser.add_argument("--admin-user", default="admin")
    parser.add_argument("--admin-password", default="ci-password")
    parser.add_argument("--page-id", required=True)
    parser.add_argument("--warmups", type=int, default=5)
    parser.add_argument("--samples", type=int, default=15)
    args = parser.parse_args()

    if args.warmups < 1 or args.samples < 5:
        raise SystemExit("benchmark requires at least 1 warm-up and 5 measured samples per state")

    anonymous = urllib.request.build_opener()
    authenticated = login(args.base_url.rstrip("/"), args.admin_user, args.admin_password)

    scenarios = [
        {"id": "frontend", "path": f"/?page_id={args.page_id}", "authenticated": False, "savequeries": False},
        {"id": "admin", "path": "/wp-admin/index.php", "authenticated": True, "savequeries": False},
        {"id": "diagnostic_workload", "path": "/wp-admin/index.php", "authenticated": True, "savequeries": False},
    ]

    result: dict[str, Any] = {
        "schema_version": SCHEMA_VERSION,
        "generated_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
        "environment": {
            "wordpress": run("wp", "core", "version", "--path", str(args.wp_path)),
            "php": run("wp", "eval", "echo PHP_VERSION;", "--path", str(args.wp_path)),
            "database": run("wp", "eval", 'global $wpdb; echo $wpdb->get_var("SELECT VERSION()");', "--path", str(args.wp_path)),
            "deep_diagnostics": run("wp", "plugin", "get", "wp-deep-diagnostics", "--field=version", "--path", str(args.wp_path)),
            "runner_os": os.environ.get("RUNNER_OS", "unknown"),
            "php_sapi": "cli-server",
        },
        "policy": {
            "warmup_requests_per_state": args.warmups,
            "measured_requests_per_state": args.samples,
            "state_order": ["inactive", "active"],
            "primary_metric": "completion_wall_ms",
            "savequeries_default": False,
            "hard_overhead_budget": None,
        },
        "scenarios": [],
        "caveats": [
            "Disposable single-runner WordPress/MySQL qualification; not production traffic evidence.",
            "The MU-plugin probe is test-only and runs in both control and active states.",
            "SAVEQUERIES is disabled in canonical scenarios, so its generic WordPress overhead is not attributed to Deep Diagnostics.",
            "Phase windows are controlled-runtime attribution aids, not a generic profiler.",
        ],
    }

    for scenario in scenarios:
        opener = authenticated if scenario["authenticated"] else anonymous
        states: dict[str, Any] = {}
        states["inactive"] = run_state(
            state="inactive",
            active=False,
            scenario_id=scenario["id"],
            path=scenario["path"],
            opener=opener,
            base_url=args.base_url.rstrip("/"),
            metrics_file=args.metrics_file,
            wp_path=args.wp_path,
            warmups=args.warmups,
            samples=args.samples,
        )
        states["active"] = run_state(
            state="active",
            active=True,
            scenario_id=scenario["id"],
            path=scenario["path"],
            opener=opener,
            base_url=args.base_url.rstrip("/"),
            metrics_file=args.metrics_file,
            wp_path=args.wp_path,
            warmups=args.warmups,
            samples=args.samples,
        )

        delta: dict[str, float | None] = {}
        for metric in [
            "client_wall_ms",
            "completion_wall_ms",
            "post_response_wait_ms",
            "peak_memory_bytes",
            "memory_usage_bytes",
            "query_count",
            "plugins_loaded_window_ms",
            "shutdown_window_ms",
        ]:
            active_median = median_metric(states["active"], metric)
            inactive_median = median_metric(states["inactive"], metric)
            delta[metric] = round(active_median - inactive_median, 3) if active_median is not None and inactive_median is not None else None

        result["scenarios"].append(
            {
                "id": scenario["id"],
                "request_path": scenario["path"],
                "authenticated": scenario["authenticated"],
                "savequeries": scenario["savequeries"],
                "states": states,
                "delta_active_minus_control": delta,
            }
        )

    if result["schema_version"] != 1 or len(result["scenarios"]) != 3:
        raise RuntimeError("invalid result contract")
    for scenario in result["scenarios"]:
        for state in ("inactive", "active"):
            block = scenario["states"][state]
            if block["summary"]["sample_count"] != args.samples or len(block["samples"]) != args.samples:
                raise RuntimeError(f"missing required samples for {scenario['id']}/{state}")
            for sample in block["samples"]:
                for required in ("completion_wall_ms", "peak_memory_bytes", "query_count"):
                    if not isinstance(sample.get(required), (int, float)):
                        raise RuntimeError(f"malformed {required} for {scenario['id']}/{state}")

    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    args.summary.write_text(render_summary(result), encoding="utf-8")
    print(render_summary(result))
    return 0


if __name__ == "__main__":
    sys.exit(main())
