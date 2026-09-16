<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

$connection = is_array($providerDiagnostics['connection'] ?? null) ? $providerDiagnostics['connection'] : [];
$interpretation = is_array($providerDiagnostics['interpretation'] ?? null) ? $providerDiagnostics['interpretation'] : [];
$currentEntry = is_array($providerDiagnostics['current_entry'] ?? null) ? $providerDiagnostics['current_entry'] : null;
$snapshot = is_array($providerDiagnostics['technical_evidence'] ?? null) ? $providerDiagnostics['technical_evidence'] : null;
$comparison = is_array($providerDiagnostics['comparison'] ?? null) ? $providerDiagnostics['comparison'] : [];
$correlation = is_array($providerDiagnostics['correlation'] ?? null) ? $providerDiagnostics['correlation'] : [];
$incidents = is_array($providerDiagnostics['incidents'] ?? null) ? $providerDiagnostics['incidents'] : [];
$recentSuccess = is_array($providerDiagnostics['recent_success'] ?? null) ? $providerDiagnostics['recent_success'] : [];
$components = is_array($snapshot['current']['components'] ?? null) ? $snapshot['current']['components'] : [];
$unresolved = is_array($snapshot['unresolved'] ?? null) ? $snapshot['unresolved'] : [];
$environment = is_array($snapshot['environment'] ?? null) ? $snapshot['environment'] : [];
$privacy = is_array($snapshot['privacy_boundary'] ?? null) ? $snapshot['privacy_boundary'] : [];

$providerActionNotices = [
    'bundle_loaded' => ['success', __('Compatible GPP Support Bundle loaded. The original uploaded file was not retained.', 'wp-deep-diagnostics')],
    'bundle_already_loaded' => ['info', __('This Support Bundle was already loaded; no duplicate history entry was created.', 'wp-deep-diagnostics')],
    'direct_snapshot_loaded' => ['success', __('A new privacy-safe direct provider snapshot was loaded.', 'wp-deep-diagnostics')],
    'direct_snapshot_unchanged' => ['info', __('The direct provider snapshot is unchanged; no duplicate history entry was created.', 'wp-deep-diagnostics')],
    'invalid_json' => ['error', __('Import failed: the selected file is not valid JSON.', 'wp-deep-diagnostics')],
    'unsupported_bundle_type' => ['error', __('Import failed: this is not a supported GPP Support Bundle.', 'wp-deep-diagnostics')],
    'unsupported_schema_version' => ['error', __('Import failed: this GPP Support Bundle schema version is not supported by this build.', 'wp-deep-diagnostics')],
    'import_too_large' => ['error', __('Import failed: the selected file exceeds the bounded import size.', 'wp-deep-diagnostics')],
    'empty_import' => ['error', __('Import failed: the selected file is empty.', 'wp-deep-diagnostics')],
    'upload_error' => ['error', __('Import failed: WordPress did not provide a readable uploaded file.', 'wp-deep-diagnostics')],
    'provider_persistence_failed' => ['error', __('Import failed: Deep Diagnostics could not safely persist normalized provider evidence.', 'wp-deep-diagnostics')],
    'direct_provider_unavailable' => ['warning', __('No compatible direct GPP provider is registered. Import a Support Bundle instead.', 'wp-deep-diagnostics')],
    'direct_provider_error' => ['error', __('The direct provider could not return compatible privacy-safe evidence.', 'wp-deep-diagnostics')],
    'direct_schema_mismatch' => ['error', __('The direct provider snapshot schema does not match its registration.', 'wp-deep-diagnostics')],
];

$stateExplanations = [
    'UNBOUND' => __('GPP has no authoritative source mapped for this semantic meaning yet.', 'wp-deep-diagnostics'),
    'NOT_PROVEN' => __('GPP has not established the evidence needed to claim this mapping/readiness condition is proven.', 'wp-deep-diagnostics'),
    'DEGRADED' => __('GPP recorded a degraded path. A fallback may have preserved host behavior; this is not proof that the host failed.', 'wp-deep-diagnostics'),
    'FAIL' => __('GPP recorded a failure at a provider-owned decision boundary.', 'wp-deep-diagnostics'),
    'SKIP' => __('GPP intentionally skipped a provider path at this boundary, often alongside a fallback.', 'wp-deep-diagnostics'),
];
?>
<section class="wddtf-card" id="wddtf-gpp-diagnostics" aria-labelledby="wddtf-gpp-title">
    <h2 id="wddtf-gpp-title"><?php esc_html_e('GPP Diagnostics', 'wp-deep-diagnostics'); ?></h2>

    <?php if ( str_contains(WDDTF_VERSION, '-') ) : ?>
        <div class="notice notice-warning inline" role="status"><p><strong><?php esc_html_e('Trial / pre-release build:', 'wp-deep-diagnostics'); ?></strong> <code class="wddtf-tech" dir="ltr"><?php echo esc_html(WDDTF_VERSION); ?></code> — <?php esc_html_e('for controlled evaluation; this is not a stable public release.', 'wp-deep-diagnostics'); ?></p></div>
    <?php endif; ?>

    <?php if ( isset($providerActionNotices[$providerAction]) ) : ?>
        <?php [$noticeType, $noticeText] = $providerActionNotices[$providerAction]; ?>
        <div class="notice notice-<?php echo esc_attr($noticeType); ?> inline" role="status"><p><?php echo esc_html($noticeText); ?></p></div>
    <?php endif; ?>

    <div class="wddtf-columns">
        <div>
            <h3><?php esc_html_e('What is this?', 'wp-deep-diagnostics'); ?></h3>
            <p><?php esc_html_e('This section explains whether Gravity Presentation Profiles (GPP) reports consistent presentation state, mappings/readiness, and recorded incidents. GPP remains authoritative for profiles, bindings, readiness, reason codes, incidents, and fallbacks; Deep Diagnostics only consumes and explains privacy-safe evidence.', 'wp-deep-diagnostics'); ?></p>
            <h3><?php esc_html_e('What does it read?', 'wp-deep-diagnostics'); ?></h3>
            <p><?php esc_html_e('Only versioned provider fields: provider/runtime versions, active/current components, binding/readiness facts, bounded incident timelines, recent success, privacy declarations, and an exact correlation reference only when the provider explicitly supplies one.', 'wp-deep-diagnostics'); ?></p>
            <h3><?php esc_html_e('What does it intentionally not read?', 'wp-deep-diagnostics'); ?></h3>
            <p><?php esc_html_e('It does not inspect GPP private wp_options or collect submitted values, names, national IDs, phone numbers, uploaded file names/contents, cookies, tokens, credentials, sensitive headers, raw bodies, absolute server paths, or exception argument values.', 'wp-deep-diagnostics'); ?></p>
        </div>
        <div>
            <h3><?php esc_html_e('Connection / evidence source', 'wp-deep-diagnostics'); ?></h3>
            <div class="notice notice-info inline"><p><strong><?php echo esc_html((string) ($connection['label'] ?? __('Provider state unavailable', 'wp-deep-diagnostics'))); ?></strong><br><?php echo esc_html((string) ($connection['meaning'] ?? '')); ?></p></div>
            <h3><?php esc_html_e('What does it need?', 'wp-deep-diagnostics'); ?></h3>
            <p><?php esc_html_e('A compatible plugin may register a read-only direct provider. Otherwise import a compatible GPP Support Bundle. A bundle is diagnostic evidence, not configuration; importing it does not change GPP.', 'wp-deep-diagnostics'); ?></p>
            <h3><?php esc_html_e('What should I do?', 'wp-deep-diagnostics'); ?></h3>
            <ol>
                <li><?php esc_html_e('Generate a Support Bundle in GPP unless a compatible direct provider is available here.', 'wp-deep-diagnostics'); ?></li>
                <li><?php esc_html_e('Import the JSON bundle below, or explicitly refresh the direct provider.', 'wp-deep-diagnostics'); ?></li>
                <li><?php esc_html_e('Read Current result → What this means → What should I do next before technical details.', 'wp-deep-diagnostics'); ?></li>
                <li><?php esc_html_e('Open an incident to see its ordered chain and first inconsistent boundary.', 'wp-deep-diagnostics'); ?></li>
            </ol>
        </div>
    </div>

    <div class="wddtf-provider-actions">
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wddtf-action-form">
            <input type="hidden" name="action" value="wddtf_import_diagnostic_provider_bundle">
            <?php wp_nonce_field('wddtf_import_diagnostic_provider_bundle'); ?>
            <label for="wddtf-provider-bundle"><strong><?php esc_html_e('Import GPP Support Bundle', 'wp-deep-diagnostics'); ?></strong></label><br>
            <input id="wddtf-provider-bundle" type="file" name="wddtf_provider_bundle" accept="application/json,.json" required>
            <?php submit_button(__('Import diagnostic evidence', 'wp-deep-diagnostics'), 'secondary', 'submit', false); ?>
            <p class="description"><?php echo esc_html(sprintf(__('JSON only; maximum %d KiB. DEEP stores only normalized evidence and bounded history, not the original file.', 'wp-deep-diagnostics'), intdiv((int) ($providerDiagnostics['import_limit_bytes'] ?? 0), 1024))); ?></p>
        </form>
        <?php if ( ! empty($providerDiagnostics['direct_available']) ) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wddtf-action-form">
                <input type="hidden" name="action" value="wddtf_refresh_diagnostic_provider"><input type="hidden" name="provider_key" value="gpp">
                <?php wp_nonce_field('wddtf_refresh_diagnostic_provider'); ?>
                <?php submit_button(__('Refresh direct GPP evidence', 'wp-deep-diagnostics'), 'secondary', 'submit', false); ?>
                <p class="description"><?php esc_html_e('Explicit read-only acquisition. DEEP does not poll GPP continuously.', 'wp-deep-diagnostics'); ?></p>
            </form>
        <?php endif; ?>
    </div>

    <h3><?php esc_html_e('Current result', 'wp-deep-diagnostics'); ?></h3>
    <div class="notice <?php echo empty($unresolved) ? 'notice-info' : 'notice-warning'; ?> inline" role="status"><p><strong><?php echo esc_html((string) ($interpretation['result'] ?? __('No provider interpretation is available.', 'wp-deep-diagnostics'))); ?></strong><br><?php echo esc_html((string) ($interpretation['meaning'] ?? '')); ?></p></div>

    <h3><?php esc_html_e('What this means', 'wp-deep-diagnostics'); ?></h3>
    <table class="widefat striped wddtf-kv-table"><tbody>
        <tr><th scope="row"><?php esc_html_e('Evidence source time', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($snapshot['source']['observed_at_utc'] ?? 'not available')); ?></code></td></tr>
        <tr><th scope="row"><?php esc_html_e('Captured by DEEP', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($currentEntry['ingested_at_utc'] ?? 'not available')); ?></code></td></tr>
        <tr><th scope="row"><?php esc_html_e('GPP version', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($snapshot['provider']['version'] ?? 'not available')); ?></code></td></tr>
        <tr><th scope="row"><?php esc_html_e('WordPress / PHP', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) (($environment['wordpress_version'] ?? 'n/a') . ' / ' . ($environment['php_version'] ?? 'n/a'))); ?></code></td></tr>
        <tr><th scope="row"><?php esc_html_e('Gravity Forms / Gravity Flow', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) (($environment['gravity_forms_version'] ?? 'n/a') . ' / ' . ($environment['gravity_flow_version'] ?? 'n/a'))); ?></code></td></tr>
        <tr><th scope="row"><?php esc_html_e('Retained snapshots', 'wp-deep-diagnostics'); ?></th><td><?php echo esc_html((string) ((int) ($providerDiagnostics['history_count'] ?? 0))) . ' / ' . esc_html((string) ((int) ($providerDiagnostics['retention_limit'] ?? 0))); ?></td></tr>
    </tbody></table>

    <?php if ( ! empty($components) ) : ?>
        <h4><?php esc_html_e('Active/current GPP components', 'wp-deep-diagnostics'); ?></h4>
        <table class="widefat striped"><thead><tr><th><?php esc_html_e('Surface', 'wp-deep-diagnostics'); ?></th><th><?php esc_html_e('Status', 'wp-deep-diagnostics'); ?></th><th><?php esc_html_e('Profile', 'wp-deep-diagnostics'); ?></th></tr></thead><tbody>
        <?php foreach ( $components as $component ) : ?><tr><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($component['key'] ?? '')); ?></code></td><td><?php echo esc_html((string) ($component['status'] ?? '')); ?></td><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($component['profile_id'] ?? 'n/a')); ?></code></td></tr><?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>

    <h4><?php esc_html_e('Unresolved / unproven current facts', 'wp-deep-diagnostics'); ?></h4>
    <?php if ( empty($unresolved) ) : ?>
        <p><?php esc_html_e('No unresolved fact was admitted from this snapshot. That is not proof that every unobserved runtime path is healthy.', 'wp-deep-diagnostics'); ?></p>
    <?php else : ?>
        <table class="widefat striped"><thead><tr><th><?php esc_html_e('Kind', 'wp-deep-diagnostics'); ?></th><th><?php esc_html_e('Meaning / claim', 'wp-deep-diagnostics'); ?></th><th><?php esc_html_e('Provider state', 'wp-deep-diagnostics'); ?></th><th><?php esc_html_e('Plain-language meaning', 'wp-deep-diagnostics'); ?></th></tr></thead><tbody>
        <?php foreach ( $unresolved as $fact ) : $factState = (string) ($fact['state'] ?? 'UNKNOWN'); ?><tr><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($fact['kind'] ?? 'unknown')); ?></code></td><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($fact['key'] ?? 'unknown')); ?></code></td><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html($factState); ?></code></td><td><?php echo esc_html($stateExplanations[$factState] ?? __('GPP marked this fact unresolved/unproven. Review it in GPP rather than repairing it in DEEP.', 'wp-deep-diagnostics')); ?></td></tr><?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>

    <div class="wddtf-columns">
        <div><h3><?php esc_html_e('What this proves', 'wp-deep-diagnostics'); ?></h3><ul><?php foreach ( $interpretation['proven'] ?? [] as $item ) : ?><li><?php echo esc_html((string) $item); ?></li><?php endforeach; ?></ul></div>
        <div><h3><?php esc_html_e('What remains unresolved / unproven', 'wp-deep-diagnostics'); ?></h3><ul><?php foreach ( $interpretation['unresolved'] ?? [] as $item ) : ?><li><?php echo esc_html((string) $item); ?></li><?php endforeach; ?></ul></div>
    </div>
    <h3><?php esc_html_e('What should I do next?', 'wp-deep-diagnostics'); ?></h3><p><strong><?php echo esc_html((string) ($interpretation['next_step'] ?? '')); ?></strong></p>

    <?php if ( ! empty($comparison['available']) ) : ?>
        <h3><?php esc_html_e('What changed since the previous compatible snapshot?', 'wp-deep-diagnostics'); ?></h3>
        <?php if ( empty($comparison['changes']) ) : ?><p><?php esc_html_e('No meaningful normalized change was detected.', 'wp-deep-diagnostics'); ?></p><?php else : ?><ul><?php foreach ( $comparison['changes'] as $change ) : ?><li><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($change['kind'] ?? 'change')); ?></code><?php if ( isset($change['added'], $change['removed']) ) echo ' — ' . esc_html(sprintf(__('added: %d, removed: %d', 'wp-deep-diagnostics'), (int) $change['added'], (int) $change['removed'])); elseif ( isset($change['count']) ) echo ' — ' . esc_html(sprintf(__('count: %d', 'wp-deep-diagnostics'), (int) $change['count'])); ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php endif; ?>

    <?php if ( ! empty($incidents) ) : ?>
        <h3><?php esc_html_e('Recorded provider incidents', 'wp-deep-diagnostics'); ?></h3><p class="description"><?php esc_html_e('These are historical provider records. They do not overwrite the current snapshot above.', 'wp-deep-diagnostics'); ?></p>
        <?php foreach ( $incidents as $index => $incident ) : $first = is_array($incident['first_inconsistent_boundary'] ?? null) ? $incident['first_inconsistent_boundary'] : []; ?>
            <details class="wddtf-trace"><summary><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($incident['surface'] ?? 'provider')); ?></code> — <code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($incident['status'] ?? 'unknown')); ?></code> — <code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($incident['observed_at_utc'] ?? 'time unavailable')); ?></code></summary>
                <p><strong><?php esc_html_e('Practical meaning:', 'wp-deep-diagnostics'); ?></strong> <?php echo esc_html((string) ($incident['plain_meaning'] ?? '')); ?></p>
                <p><strong><?php esc_html_e('First inconsistent / failing boundary:', 'wp-deep-diagnostics'); ?></strong> <code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($first['stage'] ?? 'not established')); ?></code></p>
                <p><strong><?php esc_html_e('Provider reason:', 'wp-deep-diagnostics'); ?></strong> <code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($first['reason_code'] ?? 'not supplied')); ?></code></p>
                <div class="wddtf-table-scroll"><table class="widefat striped"><thead><tr><th>#</th><th><?php esc_html_e('Stage', 'wp-deep-diagnostics'); ?></th><th><?php esc_html_e('Result', 'wp-deep-diagnostics'); ?></th><th><?php esc_html_e('Reason', 'wp-deep-diagnostics'); ?></th><th><?php esc_html_e('Fallback', 'wp-deep-diagnostics'); ?></th></tr></thead><tbody><?php foreach ( $incident['events'] ?? [] as $event ) : ?><tr><td><?php echo esc_html((string) ($event['seq'] ?? '')); ?></td><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($event['stage'] ?? '')); ?></code></td><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($event['result'] ?? '')); ?></code></td><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($event['reason_code'] ?? '')); ?></code></td><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($event['fallback'] ?? '')); ?></code></td></tr><?php endforeach; ?></tbody></table></div>
                <p><strong><?php esc_html_e('What this proves:', 'wp-deep-diagnostics'); ?></strong> <?php echo esc_html((string) ($incident['proves'] ?? '')); ?></p>
                <p><strong><?php esc_html_e('What it does NOT prove:', 'wp-deep-diagnostics'); ?></strong> <?php echo esc_html((string) ($incident['does_not_prove'] ?? '')); ?></p>
                <p><strong><?php esc_html_e('Recommended next action:', 'wp-deep-diagnostics'); ?></strong> <?php echo esc_html((string) ($incident['next_step'] ?? '')); ?></p>
                <p><strong><?php esc_html_e('Cross-system correlation:', 'wp-deep-diagnostics'); ?></strong> <?php echo esc_html((string) ($incident['correlation']['meaning'] ?? '')); ?></p>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ( ! empty($recentSuccess) ) : ?><h3><?php esc_html_e('Recent provider success', 'wp-deep-diagnostics'); ?></h3><ul><?php foreach ( $recentSuccess as $success ) : ?><li><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($success['surface'] ?? 'surface')); ?></code> — <?php esc_html_e('provider-recorded PASS at', 'wp-deep-diagnostics'); ?> <code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($success['observed_at_utc'] ?? 'time unavailable')); ?></code></li><?php endforeach; ?></ul><?php endif; ?>

    <h3><?php esc_html_e('Correlation boundary', 'wp-deep-diagnostics'); ?></h3><p><?php echo esc_html((string) ($correlation['meaning'] ?? '')); ?></p>
    <h3><?php esc_html_e('Limitations', 'wp-deep-diagnostics'); ?></h3><ul><?php foreach ( $interpretation['limitations'] ?? [] as $item ) : ?><li><?php echo esc_html((string) $item); ?></li><?php endforeach; ?><li><?php esc_html_e('Bundle evidence is point-in-time and can become stale after it is generated.', 'wp-deep-diagnostics'); ?></li><li><?php esc_html_e('DEEP never treats timestamp proximity alone as proof that a GPP incident and DEEP trace share the same cause.', 'wp-deep-diagnostics'); ?></li></ul>

    <?php if ( ! empty($privacy) ) : ?><details class="wddtf-trace"><summary><?php esc_html_e('Provider-declared privacy boundary', 'wp-deep-diagnostics'); ?></summary><table class="widefat striped wddtf-kv-table"><tbody><?php foreach ( $privacy as $key => $value ) : ?><tr><th><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) $key); ?></code></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) $value); ?></code></td></tr><?php endforeach; ?></tbody></table></details><?php endif; ?>

    <?php if ( null !== $snapshot ) : ?>
        <details class="wddtf-trace"><summary><?php esc_html_e('Technical details / normalized JSON evidence', 'wp-deep-diagnostics'); ?></summary><p class="description"><?php esc_html_e('Technical JSON is secondary evidence. It contains only normalized admitted fields, not the original uploaded file.', 'wp-deep-diagnostics'); ?></p><textarea readonly rows="20" class="large-text code wddtf-code-output" dir="ltr"><?php echo esc_textarea((string) wp_json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></textarea><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wddtf-action-form"><input type="hidden" name="action" value="wddtf_export_diagnostic_provider_evidence"><input type="hidden" name="provider_key" value="gpp"><?php wp_nonce_field('wddtf_export_diagnostic_provider_evidence'); ?><?php submit_button(__('Export normalized provider evidence', 'wp-deep-diagnostics'), 'secondary', 'submit', false); ?></form></details>
    <?php endif; ?>
</section>
