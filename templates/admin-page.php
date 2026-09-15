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
$gravityButtonAttributes = empty($gravityFlowHost['available']) ? ['disabled' => 'disabled'] : [];
$gravityTraces = is_array($inboxObservation['traces'] ?? null) ? $inboxObservation['traces'] : [];
$gravitySessionAnalysis = $inboxObservation['analysis'] ?? [];
$gravityBrowserAnalysis = $inboxObservation['browser_analysis'] ?? [];
$gravityBrowserClassification = is_string($gravityBrowserAnalysis['classification'] ?? null)
    ? $gravityBrowserAnalysis['classification']
    : 'BROWSER_EVIDENCE_INSUFFICIENT';

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

$browserGuidance = [
    'BROWSER_UI_SIGNAL_OBSERVED' => [
        __('A correlated Inbox refresh response reached this browser and a metadata-only title or DOM mutation signal was observed afterward.', 'wp-deep-diagnostics'),
        __('Confirm the expected Inbox row visually on the relevant Gravity Flow site. DEEP has not proved which content became visible to the user.', 'wp-deep-diagnostics'),
    ],
    'BROWSER_RESPONSE_RECEIVED' => [
        __('A correlated Inbox refresh response reached this browser, but no supported UI-side title or DOM mutation signal was observed in the short evidence window.', 'wp-deep-diagnostics'),
        __('Reproduce once more with the relevant Inbox open and visible. If the row still does not visibly update, inspect the technical evidence without treating the missing UI signal as proof that Gravity Flow is broken.', 'wp-deep-diagnostics'),
    ],
    'BROWSER_REFRESH_NOT_OBSERVED' => [
        __('The server produced a candidate-linked Inbox refresh signal, but this diagnostic session did not receive matching browser evidence.', 'wp-deep-diagnostics'),
        __('Keep the relevant Inbox open as a logged-in user who can access the Gravity Flow Inbox, then reproduce the submission during the active diagnostic window.', 'wp-deep-diagnostics'),
    ],
    'BROWSER_EVIDENCE_AMBIGUOUS' => [
        __('Browser evidence was received, but more than one candidate trace could plausibly be linked to the correlated refresh.', 'wp-deep-diagnostics'),
        __('Reproduce with one candidate submission in the diagnostic window so the existing opaque correlation can resolve the evidence without timing guesses.', 'wp-deep-diagnostics'),
    ],
    'BROWSER_EVIDENCE_INSUFFICIENT' => [
        __('There is not enough correlated browser evidence to say whether a relevant Live Refresh reached the browser.', 'wp-deep-diagnostics'),
        __('Start or keep the diagnostic active, keep/open the relevant Inbox, reproduce the submission, then return here. Tokenized or unauthenticated Inbox contexts are not qualified by this browser collector.', 'wp-deep-diagnostics'),
    ],
];
[$browserMeaning, $browserNextStep] = $browserGuidance[$gravityBrowserClassification]
    ?? $browserGuidance['BROWSER_EVIDENCE_INSUFFICIENT'];

$actionNotices = [
    'scheduled'                          => ['success', __('Cron qualification probe scheduled.', 'wp-deep-diagnostics')],
    'already_pending'                    => ['info', __('A Cron qualification is already pending; no duplicate probe was scheduled.', 'wp-deep-diagnostics')],
    'schedule_failed'                    => ['error', __('WordPress did not schedule the Cron qualification probe.', 'wp-deep-diagnostics')],
    'scheduled_event_not_observable'     => ['warning', __('The scheduling call returned successfully, but the probe event could not be observed afterward.', 'wp-deep-diagnostics')],
    'session_persistence_failed'         => ['error', __('The diagnostic session could not be persisted, so no probe was scheduled.', 'wp-deep-diagnostics')],
    'current_session_persistence_failed' => ['error', __('The current diagnostic session pointer could not be persisted, so no probe was scheduled.', 'wp-deep-diagnostics')],
];

$gravityActionNotices = [
    'observing'                          => ['success', __('Gravity diagnostic started. Submit entries and exercise the Gravity Flow Inbox during the bounded observation window.', 'wp-deep-diagnostics')],
    'already_observing'                  => ['info', __('A Gravity diagnostic is already active; the existing bounded session was kept.', 'wp-deep-diagnostics')],
    'gravity_flow_unavailable'           => ['warning', __('Gravity Flow is not available in this runtime, so the Gravity diagnostic was not started.', 'wp-deep-diagnostics')],
    'session_persistence_failed'         => ['error', __('The Gravity diagnostic session could not be persisted.', 'wp-deep-diagnostics')],
    'current_session_persistence_failed' => ['error', __('The current Gravity diagnostic pointer could not be persisted.', 'wp-deep-diagnostics')],
];
?>
<div class="wrap wddtf-wrap">
    <h1><?php esc_html_e('WP Deep Diagnostics', 'wp-deep-diagnostics'); ?></h1>

    <?php if ( isset($actionNotices[$cronAction]) ) : ?>
        <?php [$noticeType, $noticeText] = $actionNotices[$cronAction]; ?>
        <div class="notice notice-<?php echo esc_attr($noticeType); ?> inline" role="status"><p><?php echo esc_html($noticeText); ?></p></div>
    <?php endif; ?>

    <?php if ( isset($gravityActionNotices[$gravityAction]) ) : ?>
        <?php [$noticeType, $noticeText] = $gravityActionNotices[$gravityAction]; ?>
        <div class="notice notice-<?php echo esc_attr($noticeType); ?> inline" role="status"><p><?php echo esc_html($noticeText); ?></p></div>
    <?php endif; ?>

    <section class="wddtf-card" aria-labelledby="wddtf-cron-title">
        <h2 id="wddtf-cron-title"><?php esc_html_e('WP-Cron Diagnostics', 'wp-deep-diagnostics'); ?></h2>
        <p><?php esc_html_e('This section reports observable WordPress Cron configuration, currently due events, and one controlled Deep Diagnostics qualification probe. Evidence and interpretation are kept separate.', 'wp-deep-diagnostics'); ?></p>

        <?php if ( ! empty($configuration['wp_cron_disabled']) ) : ?>
            <div class="notice notice-warning inline"><p><?php esc_html_e('DISABLE_WP_CRON is enabled. Visitor-triggered WP-Cron spawning is disabled; an external scheduler or WP-CLI may still execute scheduled events.', 'wp-deep-diagnostics'); ?></p></div>
        <?php elseif ( ! empty($configuration['alternate_wp_cron']) ) : ?>
            <div class="notice notice-info inline"><p><?php esc_html_e('ALTERNATE_WP_CRON is enabled. Interpret execution timing in that configuration rather than assuming the standard visitor-triggered path.', 'wp-deep-diagnostics'); ?></p></div>
        <?php endif; ?>

        <?php if ( false === ($ready['api_available'] ?? true) ) : ?>
            <div class="notice notice-error inline"><p><?php esc_html_e('The public WordPress ready-Cron API is unavailable in this runtime; due-event observation is unavailable.', 'wp-deep-diagnostics'); ?></p></div>
        <?php endif; ?>

        <div class="wddtf-columns">
            <div>
                <h3><?php esc_html_e('Observable configuration', 'wp-deep-diagnostics'); ?></h3>
                <table class="widefat striped wddtf-kv-table"><tbody>
                    <tr><th scope="row"><?php esc_html_e('Mode', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($configuration['mode'] ?? 'unknown')); ?></code></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Automatic WP-Cron', 'wp-deep-diagnostics'); ?></th><td><?php echo ! empty($configuration['automatic_wp_cron_enabled']) ? esc_html__('Enabled in observable configuration', 'wp-deep-diagnostics') : esc_html__('Disabled by configuration', 'wp-deep-diagnostics'); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Due / ready events observed now', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) (int) ($ready['count'] ?? 0)); ?></code></td></tr>
                </tbody></table>
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
                <div class="notice notice-<?php echo esc_attr($statusNotice); ?> inline"><p>
                    <strong><?php echo esc_html($statusLabels[$status] ?? $statusLabels['unknown']); ?></strong>
                    <?php if ( 'pending' === $status && ! empty($qualification['due_not_observed']) ) : ?>
                        — <?php esc_html_e('The probe is due but execution has not been observed yet. This is an attention state, not proof of Cron failure.', 'wp-deep-diagnostics'); ?>
                    <?php elseif ( 'unknown' === $status ) : ?>
                        — <?php esc_html_e('Persisted or scheduled evidence is incomplete or inconsistent. No success or failure is inferred.', 'wp-deep-diagnostics'); ?>
                    <?php endif; ?>
                </p></div>

                <table class="widefat striped wddtf-kv-table"><tbody>
                    <tr><th scope="row"><?php esc_html_e('Diagnostic session', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($qualification['session_id'] ?? 'n/a')); ?></code></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Probe scheduled', 'wp-deep-diagnostics'); ?></th><td><?php echo ! empty($qualification['scheduled']) ? esc_html__('Yes', 'wp-deep-diagnostics') : esc_html__('No / not proven', 'wp-deep-diagnostics'); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Expected execution', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($qualification['expected_at'] ?? 'n/a')); ?></code></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Observed execution', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($qualification['observed_at'] ?? 'not observed')); ?></code></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Measured delay', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo null !== ($qualification['delay_seconds'] ?? null) ? esc_html((string) $qualification['delay_seconds'] . ' s') : esc_html__('n/a', 'wp-deep-diagnostics'); ?></code></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Observed execution context', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($qualification['execution_context']['trigger'] ?? 'unknown')); ?></code></td></tr>
                </tbody></table>
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
                <table class="widefat striped"><thead><tr>
                    <th scope="col"><?php esc_html_e('Hook', 'wp-deep-diagnostics'); ?></th>
                    <th scope="col"><?php esc_html_e('Scheduled', 'wp-deep-diagnostics'); ?></th>
                    <th scope="col"><?php esc_html_e('Delay (s)', 'wp-deep-diagnostics'); ?></th>
                    <th scope="col"><?php esc_html_e('Recurrence', 'wp-deep-diagnostics'); ?></th>
                </tr></thead><tbody>
                    <?php foreach ( $ready['events'] as $event ) : ?>
                        <tr>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($event['hook'] ?? '')); ?></code></td>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($event['scheduled_at'] ?? '')); ?></code></td>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($event['delay_seconds'] ?? 0)); ?></code></td>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($event['schedule'] ?? 'single')); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table>
            </div>
            <?php if ( ! empty($ready['truncated']) ) : ?>
                <p class="description"><?php esc_html_e('The displayed event list is bounded and truncated; the total count above includes all ready event instances observed.', 'wp-deep-diagnostics'); ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="wddtf-card" aria-labelledby="wddtf-gravity-title">
        <h2 id="wddtf-gravity-title"><?php esc_html_e('Gravity Forms / Gravity Flow Diagnostics', 'wp-deep-diagnostics'); ?></h2>
        <p><?php esc_html_e('This bounded diagnostic follows the server-side lifecycle into the Gravity Flow Inbox and, when an authorized Inbox browser is open, can separately record whether a candidate-linked refresh response reached that browser. It never stores Inbox row content, submitted field values, request/response bodies, raw host IDs, or generic AJAX traffic.', 'wp-deep-diagnostics'); ?></p>

        <h3><?php esc_html_e('How to run this diagnostic', 'wp-deep-diagnostics'); ?></h3>
        <ol>
            <li><?php esc_html_e('Start the Gravity diagnostic below. This opens one 15-minute bounded evidence window.', 'wp-deep-diagnostics'); ?></li>
            <li><?php esc_html_e('Keep or open the relevant Gravity Flow Inbox as the logged-in Inbox operator who normally receives the work.', 'wp-deep-diagnostics'); ?></li>
            <li><?php esc_html_e('Reproduce one submission. DEEP observes documented server lifecycle signals and only browser refreshes positively tagged by that server-side Inbox evidence.', 'wp-deep-diagnostics'); ?></li>
            <li><?php esc_html_e('Return to this page and read Result → meaning → next step. UNKNOWN or AMBIGUOUS means evidence is incomplete; it is not a product-failure verdict.', 'wp-deep-diagnostics'); ?></li>
        </ol>

        <div class="notice notice-info inline"><p>
            <strong><?php esc_html_e('Browser evidence ceiling:', 'wp-deep-diagnostics'); ?></strong>
            <?php esc_html_e('DEEP\'s own browser classification still does not prove which Entry became visible. The reference workflow was independently qualified on Gravity Forms 3.1.1.1, Gravity Flow 3.1.0, WordPress 6.5, PHP 8.1.34, and Chromium: the expected Entry became visible through native Live Data Refresh. That independent qualification does not extend to other Gravity versions, current-WordPress commercial runtime, SRWF-specific behavior, production load, notification/feed timing, Gravity View/Perks, or generalized network tracing.', 'wp-deep-diagnostics'); ?>
        </p></div>

        <div class="wddtf-columns">
            <div>
                <h3><?php esc_html_e('Host availability', 'wp-deep-diagnostics'); ?></h3>
                <table class="widefat striped wddtf-kv-table"><tbody>
                    <tr><th scope="row"><?php esc_html_e('Gravity Forms', 'wp-deep-diagnostics'); ?></th><td><?php echo ! empty($gravityFormsHost['available']) ? esc_html__('Available', 'wp-deep-diagnostics') : esc_html__('Not observed', 'wp-deep-diagnostics'); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Gravity Forms version', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($gravityFormsHost['version'] ?? 'n/a')); ?></code></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Gravity Flow', 'wp-deep-diagnostics'); ?></th><td><?php echo ! empty($gravityFlowHost['available']) ? esc_html__('Available', 'wp-deep-diagnostics') : esc_html__('Not observed', 'wp-deep-diagnostics'); ?></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Gravity Flow version', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($gravityFlowHost['version'] ?? 'n/a')); ?></code></td></tr>
                </tbody></table>
            </div>

            <div>
                <h3><?php esc_html_e('Active diagnostic session', 'wp-deep-diagnostics'); ?></h3>
                <?php
                $inboxNotice = 'info';
                if ( 'completed' === $inboxStatus ) {
                    $inboxNotice = 'success';
                } elseif ( in_array($inboxStatus, ['error', 'unknown'], true) ) {
                    $inboxNotice = 'warning';
                }
                ?>
                <div class="notice notice-<?php echo esc_attr($inboxNotice); ?> inline"><p>
                    <strong><?php echo esc_html($inboxStatusLabels[$inboxStatus] ?? $inboxStatusLabels['unknown']); ?></strong>
                    <?php if ( 'unknown' === $inboxStatus ) : ?>
                        — <?php esc_html_e('The bounded session is missing, expired, or inconsistent. No host failure is inferred.', 'wp-deep-diagnostics'); ?>
                    <?php endif; ?>
                </p></div>
                <table class="widefat striped wddtf-kv-table"><tbody>
                    <tr><th scope="row"><?php esc_html_e('Diagnostic session', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($inboxObservation['session_id'] ?? 'n/a')); ?></code></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Expires', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($inboxObservation['expires_at'] ?? 'n/a')); ?></code></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Candidate traces', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) (int) ($inboxObservation['trace_count'] ?? 0)); ?></code></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Server result', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($gravitySessionAnalysis['classification'] ?? 'ENTRY_NOT_OBSERVED')); ?></code></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Inbox samples observed', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) (int) ($inboxObservation['sample_count_total'] ?? 0)); ?></code></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Browser evidence samples', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) (int) ($inboxObservation['browser_evidence_count'] ?? 0)); ?></code></td></tr>
                </tbody></table>
            </div>
        </div>

        <h3><?php esc_html_e('Result', 'wp-deep-diagnostics'); ?></h3>
        <table class="widefat striped wddtf-kv-table"><tbody>
            <tr><th scope="row"><?php esc_html_e('Browser result', 'wp-deep-diagnostics'); ?></th><td><code class="wddtf-tech" dir="ltr"><?php echo esc_html($gravityBrowserClassification); ?></code></td></tr>
            <tr><th scope="row"><?php esc_html_e('Plain-language meaning', 'wp-deep-diagnostics'); ?></th><td><?php echo esc_html($browserMeaning); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Next step', 'wp-deep-diagnostics'); ?></th><td><?php echo esc_html($browserNextStep); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Entry visible to user proven', 'wp-deep-diagnostics'); ?></th><td><?php echo ! empty($gravityBrowserAnalysis['entry_visible_to_user_proven']) ? esc_html__('Yes', 'wp-deep-diagnostics') : esc_html__('No', 'wp-deep-diagnostics'); ?></td></tr>
        </tbody></table>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wddtf-action-form">
            <input type="hidden" name="action" value="wddtf_start_gravityflow_inbox_observation">
            <?php wp_nonce_field('wddtf_start_gravityflow_inbox_observation'); ?>
            <?php submit_button(__('Start Gravity Diagnostic', 'wp-deep-diagnostics'), 'secondary', 'submit', false, $gravityButtonAttributes); ?>
            <p class="description"><?php esc_html_e('Explicit action: opens one 15-minute diagnostic window, keeps at most 10 candidate entry traces, 24 lifecycle events per trace, and 20 Inbox request samples. Starting remains restricted to administrators; browser evidence collection uses the logged-in Gravity Flow Inbox permission and a session-bound nonce.', 'wp-deep-diagnostics'); ?></p>
        </form>

        <?php if ( ! empty($gravityTraces) ) : ?>
            <h3><?php esc_html_e('Technical evidence — causal traces', 'wp-deep-diagnostics'); ?></h3>
            <p class="description"><?php esc_html_e('Trace, form, step, and assignee references are opaque diagnostic identifiers. Evidence is ordered by observation time; missing evidence stays explicit instead of being converted into a failure claim.', 'wp-deep-diagnostics'); ?></p>
            <?php foreach ( $gravityTraces as $trace ) : ?>
                <?php $traceAnalysis = $trace['analysis'] ?? []; ?>
                <details class="wddtf-trace">
                    <summary>
                        <code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($trace['trace_ref'] ?? 'n/a')); ?></code>
                        — <code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($traceAnalysis['classification'] ?? 'INSUFFICIENT_EVIDENCE')); ?></code>
                    </summary>
                    <p>
                        <?php esc_html_e('Form reference', 'wp-deep-diagnostics'); ?>:
                        <code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($trace['form_ref'] ?? 'n/a')); ?></code>
                    </p>
                    <div class="wddtf-table-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e('Gravity causal trace chronology', 'wp-deep-diagnostics'); ?>">
                        <table class="widefat striped"><thead><tr>
                            <th scope="col"><?php esc_html_e('Observed', 'wp-deep-diagnostics'); ?></th>
                            <th scope="col"><?php esc_html_e('Event', 'wp-deep-diagnostics'); ?></th>
                            <th scope="col"><?php esc_html_e('Transport', 'wp-deep-diagnostics'); ?></th>
                            <th scope="col"><?php esc_html_e('Step', 'wp-deep-diagnostics'); ?></th>
                            <th scope="col"><?php esc_html_e('Step type / status', 'wp-deep-diagnostics'); ?></th>
                            <th scope="col"><?php esc_html_e('Assignees', 'wp-deep-diagnostics'); ?></th>
                        </tr></thead><tbody>
                            <?php foreach ( $trace['events'] ?? [] as $event ) : ?>
                                <tr>
                                    <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($event['observed_at'] ?? '')); ?></code></td>
                                    <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($event['type'] ?? 'unknown')); ?></code></td>
                                    <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($event['transport'] ?? 'unknown')); ?></code></td>
                                    <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($event['step_ref'] ?? $event['next_step_ref'] ?? 'n/a')); ?></code></td>
                                    <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html(trim((string) (($event['step_type'] ?? '') . ' ' . ($event['step_status'] ?? '')))); ?></code></td>
                                    <td><code class="wddtf-tech" dir="ltr"><?php echo array_key_exists('assignee_count', $event) ? esc_html((string) (int) $event['assignee_count']) : esc_html__('n/a', 'wp-deep-diagnostics'); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody></table>
                    </div>
                    <p class="description">
                        <?php esc_html_e('Analysis reason', 'wp-deep-diagnostics'); ?>:
                        <code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($traceAnalysis['reason'] ?? 'unknown')); ?></code>
                    </p>
                </details>
            <?php endforeach; ?>
            <?php if ( ! empty($inboxObservation['traces_truncated']) ) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e('The candidate-trace limit was reached. Additional candidate entries were intentionally not retained, so the session is incomplete by design.', 'wp-deep-diagnostics'); ?></p></div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ( ! empty($inboxObservation['samples']) ) : ?>
            <h3><?php esc_html_e('Technical evidence — observed Inbox request samples', 'wp-deep-diagnostics'); ?></h3>
            <div class="wddtf-table-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e('Observed Gravity Flow Inbox request samples', 'wp-deep-diagnostics'); ?>">
                <table class="widefat striped"><thead><tr>
                    <th scope="col"><?php esc_html_e('Observed', 'wp-deep-diagnostics'); ?></th>
                    <th scope="col"><?php esc_html_e('Transport', 'wp-deep-diagnostics'); ?></th>
                    <th scope="col"><?php esc_html_e('Server elapsed (ms)', 'wp-deep-diagnostics'); ?></th>
                    <th scope="col"><?php esc_html_e('Candidate trace links', 'wp-deep-diagnostics'); ?></th>
                    <th scope="col"><?php esc_html_e('Browser sample', 'wp-deep-diagnostics'); ?></th>
                    <th scope="col"><?php esc_html_e('Browser outcome / UI signal', 'wp-deep-diagnostics'); ?></th>
                </tr></thead><tbody>
                    <?php foreach ( $inboxObservation['samples'] as $sample ) : ?>
                        <tr>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($sample['observed_at'] ?? '')); ?></code></td>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($sample['transport'] ?? 'unknown')); ?></code></td>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($sample['elapsed_ms'] ?? 'n/a')); ?></code></td>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html(implode(', ', array_map('strval', $sample['candidate_trace_refs'] ?? []))); ?></code></td>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($sample['sample_ref'] ?? 'n/a')); ?></code></td>
                            <td><code class="wddtf-tech" dir="ltr"><?php echo esc_html(trim((string) (($sample['browser']['outcome'] ?? 'not received') . ' ' . ($sample['browser']['ui_signal'] ?? '')))); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table>
            </div>
        <?php endif; ?>
    </section>

    <section class="wddtf-card" aria-labelledby="wddtf-report-title">
        <h2 id="wddtf-report-title"><?php esc_html_e('Last normal-request report', 'wp-deep-diagnostics'); ?></h2>
        <?php if ( empty($report) ) : ?>
            <p><?php esc_html_e('No report yet. Load any admin page and refresh.', 'wp-deep-diagnostics'); ?></p>
        <?php else : ?>
            <?php
            $synthesis = is_array($report['synthesis'] ?? null) ? $report['synthesis'] : [];
            $synthesisFindings = is_array($synthesis['findings'] ?? null) ? $synthesis['findings'] : [];
            $synthesisStatus = is_string($synthesis['status'] ?? null) ? $synthesis['status'] : 'insufficient_evidence';
            $synthesisNotice = in_array($synthesisStatus, ['strong_signal', 'multiple_signals'], true) ? 'warning' : 'info';
            ?>

            <div class="notice notice-<?php echo esc_attr($synthesisNotice); ?> inline" role="status"><p>
                <strong><?php esc_html_e('Result:', 'wp-deep-diagnostics'); ?></strong>
                <?php echo esc_html((string) ($synthesis['result'] ?? __('No evidence-first synthesis is available for this report.', 'wp-deep-diagnostics'))); ?>
            </p></div>

            <?php foreach ( $synthesisFindings as $findingIndex => $finding ) : ?>
                <?php
                $findingId = 'wddtf-normal-finding-' . (int) $findingIndex;
                $facts = is_array($finding['observed_facts'] ?? null) ? $finding['observed_facts'] : [];
                ?>
                <article class="wddtf-finding" aria-labelledby="<?php echo esc_attr($findingId); ?>">
                    <h3 id="<?php echo esc_attr($findingId); ?>">
                        <?php echo esc_html((string) ($finding['title'] ?? __('Diagnostic finding', 'wp-deep-diagnostics'))); ?>
                        <code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($finding['strength'] ?? 'unknown')); ?></code>
                    </h3>
                    <table class="widefat striped wddtf-kv-table"><tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Plain-language meaning', 'wp-deep-diagnostics'); ?></th>
                            <td><?php echo esc_html((string) ($finding['meaning'] ?? $finding['result'] ?? '')); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Evidence that supports it', 'wp-deep-diagnostics'); ?></th>
                            <td>
                                <?php if ( ! empty($facts) ) : ?>
                                    <ul>
                                        <?php foreach ( $facts as $fact ) : ?>
                                            <li><?php echo esc_html((string) $fact); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else : ?>
                                    <?php esc_html_e('No additional retained fact is available.', 'wp-deep-diagnostics'); ?>
                                <?php endif; ?>
                                <?php if ( isset($finding['evidence']['end_boundary']) ) : ?>
                                    <p>
                                        <?php esc_html_e('Lifecycle end boundary:', 'wp-deep-diagnostics'); ?>
                                        <code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) $finding['evidence']['end_boundary']); ?></code>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('What DEEP cannot prove', 'wp-deep-diagnostics'); ?></th>
                            <td><?php echo esc_html((string) ($finding['claim_ceiling'] ?? __('The retained evidence does not support a stronger causal claim.', 'wp-deep-diagnostics'))); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Recommended next step', 'wp-deep-diagnostics'); ?></th>
                            <td><?php echo esc_html((string) ($finding['next_step'] ?? __('Collect another representative request with the missing timing evidence.', 'wp-deep-diagnostics'))); ?></td>
                        </tr>
                    </tbody></table>
                </article>
            <?php endforeach; ?>

            <details class="wddtf-trace">
                <summary><?php esc_html_e('Technical evidence and LLM-ready JSON', 'wp-deep-diagnostics'); ?></summary>
                <p class="description"><?php esc_html_e('The legacy heuristic bottleneck ranking is retained for backward compatibility only. The evidence-first synthesis above is the normal user guidance surface.', 'wp-deep-diagnostics'); ?></p>
                <p>
                    <?php esc_html_e('Legacy heuristic top signal:', 'wp-deep-diagnostics'); ?>
                    <code class="wddtf-tech" dir="ltr"><?php echo esc_html((string) ($report['bottlenecks'][0]['name'] ?? 'n/a')); ?></code>
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
            </details>
        <?php endif; ?>
    </section>
</div>
