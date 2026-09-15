<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

$configuration = $cron['configuration'] ?? [];
$ready = $cron['ready_events'] ?? [];
$qualification = $cron['qualification'] ?? [];
$status = is_string($qualification['status'] ?? null) ? $qualification['status'] : 'unknown';
$gravityHosts = $gravity['hosts'] ?? [];
$gravityFormsHost = $gravityHosts['gravity_forms'] ?? [];
$gravityFlowHost = $gravityHosts['gravity_flow'] ?? [];
$inboxObservation = $gravity['inbox_observation'] ?? [];
$inboxStatus = is_string($inboxObservation['status'] ?? null) ? $inboxObservation['status'] : 'unknown';

$statusLabels = [
    'not_started' => __('Not run yet', 'wp-deep-diagnostics'),
    'pending'     => __('Pending', 'wp-deep-diagnostics'),
    'completed'   => __('Completed', 'wp-deep-diagnostics'),
    'error'       => __('Error', 'wp-deep-diagnostics'),
    'unknown'     => __('Unknown / ambiguous', 'wp-deep-diagnostics'),
];

$inboxStatusLabels = [
    'not_started' => __('Not observing', 'wp-deep-diagnostics'),
    'observing'   => __('Observing', 'wp-deep-diagnostics'),
    'completed'   => __('Sample limit reached', 'wp-deep-diagnostics'),
    'error'       => __('Error', 'wp-deep-diagnostics'),
    'unknown'     => __('Unknown / expired', 'wp-deep-diagnostics'),
];

$actionNotices = [
    'scheduled'                          => ['success', __('Cron qualification probe scheduled.', 'wp-deep-diagnostics')],
    'already_pending'                    => ['info', __('A Cron qualification is already pending; no duplicate probe was scheduled.', 'wp-deep-diagnostics')],
    'schedule_failed'                    => ['error', __('WordPress did not schedule the Cron qualification probe.', 'wp-deep-diagnostics')],
    'scheduled_event_not_observable'     => ['warning', __('The scheduling call returned successfully, but the probe event could not be observed afterward.', 'wp-deep-diagnostics')],
    'session_persistence_failed'         => ['error', __('The diagnostic session could not be persisted, so no probe was scheduled.', 'wp-deep-diagnostics')],
    'current_session_persistence_failed' => ['error', __('The current diagnostic session pointer could not be persisted, so no probe was scheduled.', 'wp-deep-diagnostics')],
];

$gravityActionNotices = [
    'observing'                           => ['success', __('Gravity Flow Inbox observation started. Open or refresh the Inbox during the bounded observation window.', 'wp-deep-diagnostics')],
    'already_observing'                   => ['info', __('A Gravity Flow Inbox observation is already active; the existing bounded session was kept.', 'wp-deep-diagnostics')],
    'gravity_flow_unavailable'            => ['warning', __('Gravity Flow is not available in this runtime, so Inbox observation was not started.', 'wp-deep-diagnostics')],
    'session_persistence_failed'          => ['error', __('The Inbox diagnostic session could not be persisted.', 'wp-deep-diagnostics')],
    'current_session_persistence_failed'  => ['error', __('The current Inbox observation pointer could not be persisted.', 'wp-deep-diagnostics')],
];
?>
<div class="wrap wddtf-wrap">
    <h1><?php esc_html_e('WP Deep Diagnostics', 'wp-deep-diagnostics'); ?></h1>

    <?php if ( isset($actionNotices[$cronAction]) ) : ?>
        <?php [$noticeType, $noticeText] = $actionNotices[$cronAction]; ?>
        <div class="notice notice-<?php echo esc_attr($noticeType); ?> inline" role="status">
            <p><?php echo esc_html($noticeText); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( isset($gravityActionNotices[$gravityAction]) ) : ?>
        <?php [$noticeType, $noticeText] = $gravityActionNotices[$gravityAction]; ?>
        <div class="notice notice-<?php echo esc_attr($noticeType); ?> inline" role="status">
            <p><?php echo esc_html($noticeText); ?></p>
        </div>
    <?php endif; ?>

    <section class="wddtf-card" aria-labelledby="wddtf-cron-title">
        <h2 id="wddtf-cron-title"><?php esc_html_e('WP-Cron Diagnostics', 'wp-deep-diagnostics'); ?></h2>
        <p><?php esc_html_e('This section reports observable WordPress Cron configuration, currently due events, and one controlled Deep Diagnostics qualification probe. Evidence and interpretation are kept separate.', 'wp-deep-diagnostics'); ?></p>

        <?php if ( ! empty($configuration['wp_cron_disabled']) ) : ?>
            <div class="notice notice-warning inline">
                <p><?php esc_html_e('DISABLE_WP_CRON is enabled. Visitor-triggered WP-Cron spawning is disabled; an external scheduler or WP-CLI may still execute scheduled events.', 'wp-deep-diagnostics'); ?></p>
            </div>
        <?php elseif ( ! empty($configuration['alternate_wp_cron']) ) : ?>
            <div class="notice notice-info inline">
                <p><?php esc_html_e('ALTERNATE_WP_CRON is enabled. Interpret execution timing in that configuration rather than assuming the standard visitor-triggered path.', 'wp-deep-diagnostics'); ?></p>
            </div>
        <?php endif; ?>

        <?php if ( false === ($ready['api_available'] ?? true) ) : ?>
            <div class="notice notice-error inline">
                <p><?php esc_html_e('The public WordPress ready-Cron API is unavailable in this runtime; due-event observation is unavailable.', 'wp-deep-diagnostics'); ?></p>
            </div>
        <?php endif; ?>

        <div class="wddtf-columns">
            <div>
                <h3><?php esc_html_e('Observable configuration', 'wp-deep-diagnostics'); ?></h3>
                <table class="widefat striped wddtf-kv-table">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Mode', 'wp-deep-diagnostics'); ?></th>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($configuration['mode'] ?? 'unknown')); ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Automatic WP-Cron', 'wp-deep-diagnostics'); ?></th>
                            <td><?php echo ! empty($configuration['automatic_wp_cron_enabled']) ? esc_html__('Enabled in observable configuration', 'wp-deep-diagnostics') : esc_html__('Disabled by configuration', 'wp-deep-diagnostics'); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Due / ready events observed now', 'wp-deep-diagnostics'); ?></th>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) (int) ($ready['count'] ?? 0)); ?></code></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div>
                <h3><?php esc_html_e('Qualification evidence', 'wp-deep-diagnostics'); ?></h3>
                <?php
                $statusNotice = 'info';
                if ( 'completed' === $status ) {
                    $statusNotice = 'success';
                } elseif ( in_array($status, ['error', 'unknown'], true) || ! empty($qualification['due_not_observed']) ) {
                    $statusNotice = 'warning';
                }
                ?>
                <div class="notice notice-<?php echo esc_attr($statusNotice); ?> inline">
                    <p>
                        <strong><?php echo esc_html($statusLabels[$status] ?? $statusLabels['unknown']); ?></strong>
                        <?php if ( 'pending' === $status && ! empty($qualification['due_not_observed']) ) : ?>
                            — <?php esc_html_e('The probe is due but execution has not been observed yet. This is an attention state, not proof of Cron failure.', 'wp-deep-diagnostics'); ?>
                        <?php elseif ( 'unknown' === $status ) : ?>
                            — <?php esc_html_e('Persisted or scheduled evidence is incomplete or inconsistent. No success or failure is inferred.', 'wp-deep-diagnostics'); ?>
                        <?php endif; ?>
                    </p>
                </div>

                <table class="widefat striped wddtf-kv-table">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Diagnostic session', 'wp-deep-diagnostics'); ?></th>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($qualification['session_id'] ?? 'n/a')); ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Probe scheduled', 'wp-deep-diagnostics'); ?></th>
                            <td><?php echo ! empty($qualification['scheduled']) ? esc_html__('Yes', 'wp-deep-diagnostics') : esc_html__('No / not proven', 'wp-deep-diagnostics'); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Expected execution', 'wp-deep-diagnostics'); ?></th>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($qualification['expected_at'] ?? 'n/a')); ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Observed execution', 'wp-deep-diagnostics'); ?></th>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($qualification['observed_at'] ?? 'not observed')); ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Measured delay', 'wp-deep-diagnostics'); ?></th>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo null !== ($qualification['delay_seconds'] ?? null) ? esc_html((string) $qualification['delay_seconds'] . ' s') : esc_html__('n/a', 'wp-deep-diagnostics'); ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Observed execution context', 'wp-deep-diagnostics'); ?></th>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($qualification['execution_context']['trigger'] ?? 'unknown')); ?></code></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wddtf-action-form">
            <input type="hidden" name="action" value="wddtf_run_cron_qualification">
            <?php wp_nonce_field('wddtf_run_cron_qualification'); ?>
            <?php submit_button(__('Run Cron Qualification', 'wp-deep-diagnostics'), 'primary', 'submit', false); ?>
            <p class="description"><?php esc_html_e('Explicit action: schedules exactly one Deep Diagnostics-owned single Cron probe when no probe is already pending. It does not call wp-cron.php, make an external network request, or treat a pending probe as success.', 'wp-deep-diagnostics'); ?></p>
        </form>

        <?php if ( ! empty($ready['events']) ) : ?>
            <h3><?php esc_html_e('Due / ready event evidence', 'wp-deep-diagnostics'); ?></h3>
            <p class="description"><?php esc_html_e('Arguments are intentionally not collected. Delay is the measured difference between the event timestamp and this observation time; no universal health threshold is inferred.', 'wp-deep-diagnostics'); ?></p>
            <div class="wddtf-table-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e('Due Cron event evidence', 'wp-deep-diagnostics'); ?>">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Hook', 'wp-deep-diagnostics'); ?></th>
                            <th scope="col"><?php esc_html_e('Scheduled', 'wp-deep-diagnostics'); ?></th>
                            <th scope="col"><?php esc_html_e('Delay (s)', 'wp-deep-diagnostics'); ?></th>
                            <th scope="col"><?php esc_html_e('Recurrence', 'wp-deep-diagnostics'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $ready['events'] as $event ) : ?>
                            <tr>
                                <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($event['hook'] ?? '')); ?></code></td>
                                <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($event['scheduled_at'] ?? '')); ?></code></td>
                                <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($event['delay_seconds'] ?? 0)); ?></code></td>
                                <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($event['schedule'] ?? 'single')); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ( ! empty($ready['truncated']) ) : ?>
                <p class="description"><?php esc_html_e('The displayed event list is bounded and truncated; the total count above includes all ready event instances observed.', 'wp-deep-diagnostics'); ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="wddtf-card" aria-labelledby="wddtf-gravity-title">
        <h2 id="wddtf-gravity-title"><?php esc_html_e('Gravity Forms / Gravity Flow Diagnostics', 'wp-deep-diagnostics'); ?></h2>
        <p><?php esc_html_e('This observer uses the documented Gravity Flow Inbox table filter as a low-overhead signal for Inbox rendering, including live AJAX refreshes. It records bounded timing/context metadata only; form IDs, entry IDs, users, field values, and request payloads are not stored.', 'wp-deep-diagnostics'); ?></p>

        <div class="wddtf-columns">
            <div>
                <h3><?php esc_html_e('Host availability', 'wp-deep-diagnostics'); ?></h3>
                <table class="widefat striped wddtf-kv-table">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Gravity Forms', 'wp-deep-diagnostics'); ?></th>
                            <td><?php echo ! empty($gravityFormsHost['available']) ? esc_html__('Available', 'wp-deep-diagnostics') : esc_html__('Not observed', 'wp-deep-diagnostics'); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Gravity Forms version', 'wp-deep-diagnostics'); ?></th>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($gravityFormsHost['version'] ?? 'n/a')); ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Gravity Flow', 'wp-deep-diagnostics'); ?></th>
                            <td><?php echo ! empty($gravityFlowHost['available']) ? esc_html__('Available', 'wp-deep-diagnostics') : esc_html__('Not observed', 'wp-deep-diagnostics'); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Gravity Flow version', 'wp-deep-diagnostics'); ?></th>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($gravityFlowHost['version'] ?? 'n/a')); ?></code></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div>
                <h3><?php esc_html_e('Inbox observation', 'wp-deep-diagnostics'); ?></h3>
                <?php
                $inboxNotice = 'info';
                if ( 'completed' === $inboxStatus ) {
                    $inboxNotice = 'success';
                } elseif ( in_array($inboxStatus, ['error', 'unknown'], true) ) {
                    $inboxNotice = 'warning';
                }
                ?>
                <div class="notice notice-<?php echo esc_attr($inboxNotice); ?> inline">
                    <p>
                        <strong><?php echo esc_html($inboxStatusLabels[$inboxStatus] ?? $inboxStatusLabels['unknown']); ?></strong>
                        <?php if ( 'unknown' === $inboxStatus ) : ?>
                            — <?php esc_html_e('The bounded session is missing, expired, or inconsistent. No host failure is inferred.', 'wp-deep-diagnostics'); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <table class="widefat striped wddtf-kv-table">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Diagnostic session', 'wp-deep-diagnostics'); ?></th>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($inboxObservation['session_id'] ?? 'n/a')); ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Expires', 'wp-deep-diagnostics'); ?></th>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($inboxObservation['expires_at'] ?? 'n/a')); ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Samples observed', 'wp-deep-diagnostics'); ?></th>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) (int) ($inboxObservation['sample_count_total'] ?? 0)); ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('AJAX samples observed', 'wp-deep-diagnostics'); ?></th>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) (int) ($inboxObservation['ajax_sample_count'] ?? 0)); ?></code></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wddtf-action-form">
            <input type="hidden" name="action" value="wddtf_start_gravityflow_inbox_observation">
            <?php wp_nonce_field('wddtf_start_gravityflow_inbox_observation'); ?>
            <?php submit_button(__('Start Inbox Observation', 'wp-deep-diagnostics'), 'secondary', 'submit', false, ['disabled' => empty($gravityFlowHost['available'])]); ?>
            <p class="description"><?php esc_html_e('Explicit action: opens one 15-minute observation window and stores at most 20 Inbox request samples. Open the Gravity Flow Inbox or leave Live Data Refresh running during the window. Client/network round-trip time is not measured, and observed server timing is not treated as root-cause proof.', 'wp-deep-diagnostics'); ?></p>
        </form>

        <?php if ( ! empty($inboxObservation['samples']) ) : ?>
            <h3><?php esc_html_e('Observed Inbox request samples', 'wp-deep-diagnostics'); ?></h3>
            <div class="wddtf-table-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e('Observed Gravity Flow Inbox request samples', 'wp-deep-diagnostics'); ?>">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Observed', 'wp-deep-diagnostics'); ?></th>
                            <th scope="col"><?php esc_html_e('Transport', 'wp-deep-diagnostics'); ?></th>
                            <th scope="col"><?php esc_html_e('Server elapsed (ms)', 'wp-deep-diagnostics'); ?></th>
                            <th scope="col"><?php esc_html_e('DB queries', 'wp-deep-diagnostics'); ?></th>
                            <th scope="col"><?php esc_html_e('Memory peak', 'wp-deep-diagnostics'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $inboxObservation['samples'] as $sample ) : ?>
                            <tr>
                                <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($sample['observed_at'] ?? '')); ?></code></td>
                                <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($sample['transport'] ?? 'unknown')); ?></code></td>
                                <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($sample['elapsed_ms'] ?? 'n/a')); ?></code></td>
                                <td><code class="wddtf-tech" dir="ltr"><?php echo null !== ($sample['db_query_count'] ?? null) ? esc_html((string) $sample['db_query_count']) : esc_html__('n/a', 'wp-deep-diagnostics'); ?></code></td>
                                <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html(size_format((int) ($sample['memory_peak_bytes'] ?? 0))); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="wddtf-card" aria-labelledby="wddtf-report-title">
        <h2 id="wddtf-report-title"><?php esc_html_e('Last normal-request report', 'wp-deep-diagnostics'); ?></h2>
        <?php if ( empty($report) ) : ?>
            <p><?php esc_html_e('No report yet. Load any admin page and refresh.', 'wp-deep-diagnostics'); ?></p>
        <?php else : ?>
            <?php
            $queries_warning = $report['layers']['database']['warning'] ?? '';
            if ( $queries_warning ) :
                ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php echo esc_html($queries_warning); ?>
                        <?php esc_html_e(' Add define( "SAVEQUERIES", true ) to wp-config.php to enable precise query timing.', 'wp-deep-diagnostics'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <p>
                <?php esc_html_e('Top bottleneck', 'wp-deep-diagnostics'); ?>:
                <?php echo esc_html($report['bottlenecks'][0]['name'] ?? 'n/a'); ?>
            </p>

            <label class="screen-reader-text" for="wddtf-llm-bundle"><?php esc_html_e('LLM-ready diagnostic JSON', 'wp-deep-diagnostics'); ?></label>
            <textarea id="wddtf-llm-bundle" readonly rows="20" class="large-text code wddtf-code-output" dir="ltr"><?php
                echo esc_textarea(
                    wp_json_encode(
                        $report['llm_bundle'] ?? [],
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    )
                );
            ?></textarea>
        <?php endif; ?>
    </section>
</div>