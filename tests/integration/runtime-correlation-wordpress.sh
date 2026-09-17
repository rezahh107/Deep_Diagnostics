#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
wp_dir="${1:-$repo_root/wordpress}"
base_url="http://127.0.0.1:8080"

mkdir -p "$wp_dir/wp-content/mu-plugins"
cp "$repo_root/tests/fixtures/runtime-correlation-probe.php" \
   "$wp_dir/wp-content/mu-plugins/wddtf-runtime-correlation-probe.php"

(
    cd "$wp_dir"
    wp option update home "$base_url" --quiet
    wp option update siteurl "$base_url" --quiet
)

cat > "$wp_dir/.wddtf-ci-router.php" <<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . ($path ?: '/');
if ('/' !== $path && is_file($file)) {
    return false;
}
require __DIR__ . '/index.php';
PHP

php -S 127.0.0.1:8080 -t "$wp_dir" "$wp_dir/.wddtf-ci-router.php" > "$repo_root/runtime-correlation-server.log" 2>&1 &
server_pid=$!
cleanup() {
    kill "$server_pid" 2>/dev/null || true
}
trap cleanup EXIT

for _ in $(seq 1 40); do
    if curl -fsS "$base_url/wp-json/" >/dev/null 2>&1; then
        break
    fi
    sleep 0.25
done
curl -fsS "$base_url/wp-json/" >/dev/null

# Reproduces PRI-FND-001 against a real standalone REST endpoint. The Provider-facing
# accessor is called from inside the route callback, after WordPress has classified REST.
rest_json="$(curl -fsS "$base_url/wp-json/wddtf-ci/v1/correlation")"
php -r '
$d = json_decode($argv[1], true);
if (!is_array($d) || !array_key_exists("ref", $d) || null !== $d["ref"]) {
    fwrite(STDERR, "standalone REST exposed a correlation ref\n");
    exit(1);
}
' "$rest_json"
echo "runtime correlation REST negative ok"

# Ordinary front-controller execution crosses parse_request after Core's REST loader.
front_json="$(curl -fsS "$base_url/?wddtf_ci_front=1")"
front_ref="$(php -r '
$d = json_decode($argv[1], true);
$ref = is_array($d) ? ($d["ref"] ?? null) : null;
if (!is_string($ref) || 1 !== preg_match("/^dx1_[A-Za-z0-9_-]{16}$/D", $ref)) {
    fwrite(STDERR, "ordinary front request missing valid correlation ref\n");
    exit(1);
}
echo $ref;
' "$front_json")"
(
    cd "$wp_dir"
    FRONT_REF="$front_ref" wp eval '
        $front = getenv("FRONT_REF");
        if ($front !== get_option("wddtf_ci_front_ref")) {
            fwrite(STDERR, "front callback reference mismatch\n"); exit(1);
        }
        $report = get_transient("wddtf_last_report");
        if (!is_array($report) || $front !== ($report["meta"]["execution_correlation_ref"] ?? null)) {
            fwrite(STDERR, "front finalized report reference mismatch\n"); exit(1);
        }
        echo "ordinary front correlation retained\n";
    '
)

# admin_init must establish the reference before admin-post/provider-facing actions execute.
admin_json="$(curl -fsS "$base_url/wp-admin/admin-post.php?action=wddtf_ci_correlation")"
php -r '
$d = json_decode($argv[1], true);
$ref = is_array($d) ? ($d["ref"] ?? null) : null;
if (!is_string($ref) || 1 !== preg_match("/^dx1_[A-Za-z0-9_-]{16}$/D", $ref)) {
    fwrite(STDERR, "admin-post callback missing supported correlation ref\n");
    exit(1);
}
' "$admin_json"
echo "runtime correlation admin-post positive ok"

# admin_init also fires for admin-ajax.php, but AJAX was already authoritatively unsupported.
ajax_json="$(curl -fsS "$base_url/wp-admin/admin-ajax.php?action=wddtf_ci_correlation")"
php -r '
$d = json_decode($argv[1], true);
$ref = is_array($d) ? ($d["data"]["ref"] ?? "missing") : "missing";
if (null !== $ref) {
    fwrite(STDERR, "admin AJAX was promoted to supported correlation\n");
    exit(1);
}
' "$ajax_json"
echo "runtime correlation AJAX negative ok"

# A real wp-cron.php root execution must remain unsupported.
(
    cd "$wp_dir"
    wp eval '
        delete_option("wddtf_ci_cron_ref");
        wp_clear_scheduled_hook("wddtf_ci_cron_correlation");
        wp_schedule_single_event(time() - 1, "wddtf_ci_cron_correlation");
    '
)
curl -fsS "$base_url/wp-cron.php?doing_wp_cron=$(date +%s).123456" >/dev/null
(
    cd "$wp_dir"
    wp eval '
        if ("NULL" !== get_option("wddtf_ci_cron_ref")) {
            fwrite(STDERR, "Cron root execution exposed a correlation ref\n"); exit(1);
        }
        echo "runtime correlation Cron negative ok\n";
    '
)

# WP-CLI does not traverse parse_request/admin_init. The context must stay unavailable to
# same-execution callers, then finalization may establish identity only for the retained report.
(
    cd "$wp_dir"
    wp eval '
        delete_transient("wddtf_last_report");
        if (null !== \WDDTF\Providers\ProviderRuntimeContext::currentExecutionCorrelationRef()) {
            fwrite(STDERR, "fallback execution exposed a pre-finalize correlation ref\n"); exit(1);
        }
        echo "fallback pre-finalize accessor null\n";
    '
    wp eval '
        $report = get_transient("wddtf_last_report");
        $ref = is_array($report) ? ($report["meta"]["execution_correlation_ref"] ?? null) : null;
        if (!is_string($ref) || 1 !== preg_match("/^dx1_[A-Za-z0-9_-]{16}$/D", $ref)) {
            fwrite(STDERR, "fallback finalization did not retain valid correlation ref\n"); exit(1);
        }
        echo "runtime correlation finalization fallback ok\n";
    '
)
