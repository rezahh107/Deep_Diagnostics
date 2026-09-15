<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Diagnostics\Report_Builder;
use WDDTF\Diagnostics\SessionStore;
use WDDTF\Gravity\GravityDiagnostics;

final class WddtfBrowserStep {
    public function __construct(
        private int $id,
        private int $entryId,
        private int $formId,
    ) {
    }

    public function get_id(): int { return $this->id; }
    public function get_entry_id(): int { return $this->entryId; }
    public function get_form_id(): int { return $this->formId; }
    public function get_type(): string { return 'approval'; }
    public function get_status(): string { return 'pending'; }
}

final class WddtfBrowserAssignee {
    public function __construct(private string $key) {}
    public function get_key(): string { return $this->key; }
    public function get_id(): string { return '77'; }
}

final class GravityBrowserEvidenceTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['wddtf_test_actions'] = [];
        $GLOBALS['wddtf_test_filters'] = [];
        $GLOBALS['wddtf_test_transients'] = [];
        $GLOBALS['wddtf_test_transient_failures'] = [];
        $GLOBALS['wddtf_test_options'] = [];
        $GLOBALS['wddtf_test_is_ajax'] = false;
        $GLOBALS['wddtf_test_is_admin'] = false;
        $GLOBALS['wddtf_test_did_actions'] = ['gravityflow_loaded' => 1];
        $GLOBALS['wddtf_test_logged_in'] = false;
        $GLOBALS['wddtf_test_capabilities'] = [];
        $GLOBALS['wddtf_test_enqueued_scripts'] = [];
        $GLOBALS['wddtf_test_inline_scripts'] = [];
        $GLOBALS['wpdb'] = new wpdb();
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void {
        $_POST = [];
        unset($_SERVER['REQUEST_METHOD'], $GLOBALS['wpdb']);
    }

    public function test_registers_only_authenticated_browser_endpoint_and_public_gravity_enqueue_seams(): void {
        $diagnostics = $this->makeDiagnostics();
        $diagnostics->register();

        self::assertArrayHasKey('gravityflow_enqueue_admin_scripts', $GLOBALS['wddtf_test_actions']);
        self::assertArrayHasKey('gravityflow_enqueue_frontend_scripts', $GLOBALS['wddtf_test_actions']);
        self::assertArrayHasKey('gravityflow_inbox_args', $GLOBALS['wddtf_test_filters']);
        self::assertArrayHasKey('wp_ajax_' . GravityDiagnostics::BROWSER_EVIDENCE_ACTION, $GLOBALS['wddtf_test_actions']);
        self::assertArrayNotHasKey('wp_ajax_nopriv_' . GravityDiagnostics::BROWSER_EVIDENCE_ACTION, $GLOBALS['wddtf_test_actions']);
    }

    public function test_browser_observer_enqueues_only_for_active_authorized_inbox_context(): void {
        $diagnostics = $this->makeDiagnostics();
        $diagnostics->markBrowserEnqueueWindow();
        $diagnostics->maybeEnqueueBrowserObserver([]);
        self::assertSame([], $GLOBALS['wddtf_test_enqueued_scripts']);

        $diagnostics->startDiagnostic();
        $diagnostics->maybeEnqueueBrowserObserver([]);
        self::assertSame([], $GLOBALS['wddtf_test_enqueued_scripts']);

        $GLOBALS['wddtf_test_logged_in'] = true;
        $GLOBALS['wddtf_test_capabilities']['gravityflow_inbox'] = true;
        $diagnostics->maybeEnqueueBrowserObserver([]);

        self::assertArrayHasKey('wddtf-gravity-browser-observer', $GLOBALS['wddtf_test_enqueued_scripts']);
        $script = $GLOBALS['wddtf_test_enqueued_scripts']['wddtf-gravity-browser-observer'];
        self::assertStringEndsWith('assets/gravity-browser-observer.js', $script['src']);
        self::assertSame(['jquery'], $script['deps']);
        self::assertNotEmpty($GLOBALS['wddtf_test_inline_scripts']['wddtf-gravity-browser-observer']);
        $inline = $GLOBALS['wddtf_test_inline_scripts']['wddtf-gravity-browser-observer'][0]['data'];
        self::assertStringContainsString(GravityDiagnostics::BROWSER_EVIDENCE_ACTION, $inline);
        self::assertStringContainsString(GravityDiagnostics::BROWSER_SAMPLE_HEADER, $inline);
        self::assertStringNotContainsString('manage_options', $inline);
    }

    public function test_tagged_ajax_refresh_accepts_bounded_browser_evidence_without_changing_server_classification(): void {
        $now = 1000;
        $headers = [];
        $diagnostics = $this->makeDiagnostics($now, $headers);
        $this->authorizeInboxOperator();
        $diagnostics->startDiagnostic();
        $this->seedPositiveServerChain($diagnostics, 812, 91);

        $GLOBALS['wddtf_test_is_ajax'] = true;
        $diagnostics->observeInboxRender([], []);
        $rawEntry = [
            'id' => 812,
            'form_id' => 91,
            '1' => 'SecretBrowserFieldCanary123456789012345',
            '2' => 'private-browser-person@example.test',
        ];
        self::assertSame(
            'SecretRowValueCanary123456789012345',
            $diagnostics->observeInboxFieldValue('SecretRowValueCanary123456789012345', 91, 5, $rawEntry)
        );
        $diagnostics->persistObservedInboxRequest();

        self::assertCount(1, $headers);
        self::assertSame(GravityDiagnostics::BROWSER_SAMPLE_HEADER, $headers[0][0]);
        self::assertMatchesRegularExpression('/^gb-[a-f0-9]{20}$/', $headers[0][1]);

        $before = $diagnostics->currentInboxObservation();
        self::assertSame('TRACE_COMPLETE_TO_SERVER_INBOX_OBSERVATION', $before['analysis']['classification']);
        self::assertSame('BROWSER_REFRESH_NOT_OBSERVED', $before['browser_analysis']['classification']);
        self::assertSame(1, $before['sample_count_total']);
        self::assertSame($headers[0][1], $before['samples'][0]['sample_ref']);

        $response = $this->postBrowserEvidence(
            $diagnostics,
            $before['session_id'],
            $headers[0][1],
            ['ui_signal' => 'both', 'duration_ms' => '125.25']
        );
        self::assertTrue($response->success);
        self::assertSame(200, $response->statusCode);

        $after = $diagnostics->currentInboxObservation();
        self::assertSame('TRACE_COMPLETE_TO_SERVER_INBOX_OBSERVATION', $after['analysis']['classification']);
        self::assertSame('BROWSER_UI_SIGNAL_OBSERVED', $after['browser_analysis']['classification']);
        self::assertFalse($after['browser_analysis']['entry_visible_to_user_proven']);
        self::assertSame(1, $after['browser_evidence_count']);
        self::assertSame('visible', $after['samples'][0]['browser']['visibility']);
        self::assertSame(125.25, $after['samples'][0]['browser']['duration_ms']);
        self::assertTrue($after['samples'][0]['browser']['title_changed']);
        self::assertTrue($after['samples'][0]['browser']['dom_mutation_observed']);

        $encoded = (string) wp_json_encode($after);
        foreach ( [
            'SecretBrowserFieldCanary123456789012345',
            'SecretRowValueCanary123456789012345',
            'private-browser-person@example.test',
            '"entry_id"',
            '"form_id"',
            '"response_body":',
            '"request_body":',
        ] as $forbidden ) {
            self::assertStringNotContainsString($forbidden, $encoded);
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_candidate_linked_rest_refresh_is_tagged_without_broad_rest_capture(): void {
        $now = 1000;
        $headers = [];
        $diagnostics = $this->makeDiagnostics($now, $headers);
        $this->authorizeInboxOperator();
        $diagnostics->startDiagnostic();
        $this->seedPositiveServerChain($diagnostics, 812, 91);

        define('REST_REQUEST', true);
        $diagnostics->observeInboxRender([], []);
        $diagnostics->observeInboxFieldValue('row-value', 91, 5, ['id' => 812, 'form_id' => 91]);
        $diagnostics->persistObservedInboxRequest();

        self::assertCount(1, $headers);
        self::assertSame(GravityDiagnostics::BROWSER_SAMPLE_HEADER, $headers[0][0]);
        self::assertMatchesRegularExpression('/^gb-[a-f0-9]{20}$/', $headers[0][1]);

        $observation = $diagnostics->currentInboxObservation();
        self::assertSame(1, $observation['sample_count_total']);
        self::assertSame('rest', $observation['samples'][0]['transport']);
        self::assertSame($headers[0][1], $observation['samples'][0]['sample_ref']);
        self::assertCount(1, $observation['samples'][0]['candidate_trace_refs']);
        self::assertSame('TRACE_COMPLETE_TO_SERVER_INBOX_OBSERVATION', $observation['analysis']['classification']);
        self::assertSame('BROWSER_REFRESH_NOT_OBSERVED', $observation['browser_analysis']['classification']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_candidate_linked_rest_refresh_accepts_validated_logged_in_cookie_when_rest_context_has_no_current_user(): void {
        $now = 1000;
        $headers = [];
        $diagnostics = $this->makeDiagnostics($now, $headers);
        $diagnostics->startDiagnostic();
        $this->seedPositiveServerChain($diagnostics, 812, 91);

        $GLOBALS['wddtf_test_logged_in'] = false;
        $GLOBALS['wddtf_test_capabilities']['gravityflow_inbox'] = false;
        $GLOBALS['wddtf_test_validated_cookie_user'] = 77;
        $GLOBALS['wddtf_test_user_capabilities'][77]['gravityflow_inbox'] = true;

        define('REST_REQUEST', true);
        $diagnostics->observeInboxRender([], []);
        $diagnostics->observeInboxFieldValue('row-value', 91, 5, ['id' => 812, 'form_id' => 91]);
        $diagnostics->persistObservedInboxRequest();

        self::assertCount(1, $headers);
        $observation = $diagnostics->currentInboxObservation();
        self::assertSame('rest', $observation['samples'][0]['transport']);
        self::assertSame($headers[0][1], $observation['samples'][0]['sample_ref']);

        // Evidence writes retain the normal authenticated Inbox capability + session nonce boundary.
        $this->authorizeInboxOperator();
        $response = $this->postBrowserEvidence($diagnostics, $observation['session_id'], $headers[0][1]);
        self::assertTrue($response->success);
        self::assertSame(1, $diagnostics->currentInboxObservation()['browser_evidence_count']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_candidate_linked_rest_refresh_without_validated_inbox_cookie_is_not_tagged(): void {
        $now = 1000;
        $headers = [];
        $diagnostics = $this->makeDiagnostics($now, $headers);
        $diagnostics->startDiagnostic();
        $this->seedPositiveServerChain($diagnostics, 812, 91);
        $GLOBALS['wddtf_test_logged_in'] = false;
        $GLOBALS['wddtf_test_validated_cookie_user'] = false;

        define('REST_REQUEST', true);
        $diagnostics->observeInboxRender([], []);
        $diagnostics->observeInboxFieldValue('row-value', 91, 5, ['id' => 812, 'form_id' => 91]);
        $diagnostics->persistObservedInboxRequest();

        self::assertSame([], $headers);
        $observation = $diagnostics->currentInboxObservation();
        self::assertSame('rest', $observation['samples'][0]['transport']);
        self::assertNull($observation['samples'][0]['sample_ref']);
        self::assertSame('BROWSER_EVIDENCE_INSUFFICIENT', $observation['browser_analysis']['classification']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_unrelated_rest_never_becomes_server_or_browser_evidence(): void {
        $now = 1000;
        $headers = [];
        $diagnostics = $this->makeDiagnostics($now, $headers);
        $this->authorizeInboxOperator();
        $diagnostics->startDiagnostic();

        define('REST_REQUEST', true);
        $diagnostics->persistObservedInboxRequest();
        $observation = $diagnostics->currentInboxObservation();

        self::assertSame([], $headers);
        self::assertSame(0, $observation['sample_count_total']);
        self::assertSame(0, $observation['browser_evidence_count']);
        self::assertSame('BROWSER_EVIDENCE_INSUFFICIENT', $observation['browser_analysis']['classification']);
    }

    public function test_unrelated_ajax_never_becomes_server_or_browser_evidence(): void {
        $now = 1000;
        $headers = [];
        $diagnostics = $this->makeDiagnostics($now, $headers);
        $this->authorizeInboxOperator();
        $diagnostics->startDiagnostic();
        $GLOBALS['wddtf_test_is_ajax'] = true;

        $diagnostics->persistObservedInboxRequest();
        $observation = $diagnostics->currentInboxObservation();

        self::assertSame([], $headers);
        self::assertSame(0, $observation['sample_count_total']);
        self::assertSame(0, $observation['browser_evidence_count']);
        self::assertSame('BROWSER_EVIDENCE_INSUFFICIENT', $observation['browser_analysis']['classification']);
    }

    public function test_forged_sample_nonce_and_client_correlation_fields_are_rejected_without_persistence(): void {
        $now = 1000;
        $headers = [];
        $diagnostics = $this->makeDiagnostics($now, $headers);
        $this->authorizeInboxOperator();
        $diagnostics->startDiagnostic();
        $this->seedTaggedSample($diagnostics, 812, 91);
        $observation = $diagnostics->currentInboxObservation();
        $sampleRef = $observation['samples'][0]['sample_ref'];

        $forged = $this->postBrowserEvidence(
            $diagnostics,
            $observation['session_id'],
            'gb-aaaaaaaaaaaaaaaaaaaa'
        );
        self::assertFalse($forged->success);
        self::assertSame(404, $forged->statusCode);

        $badNonce = $this->postBrowserEvidence(
            $diagnostics,
            $observation['session_id'],
            $sampleRef,
            ['nonce' => 'forged-browser-evidence-nonce']
        );
        self::assertFalse($badNonce->success);
        self::assertSame(403, $badNonce->statusCode);

        $clientCorrelation = $this->postBrowserEvidence(
            $diagnostics,
            $observation['session_id'],
            $sampleRef,
            [
                'session_id' => 'ds-aaaaaaaaaaaaaaaa',
                'trace_ref' => 'gt-aaaaaaaaaaaaaaaa',
                'response_body' => 'SecretResponseBodyCanary123456789012345',
            ]
        );
        self::assertFalse($clientCorrelation->success);
        self::assertSame(400, $clientCorrelation->statusCode);
        self::assertSame(0, $diagnostics->currentInboxObservation()['browser_evidence_count']);
    }

    public function test_expired_session_and_missing_inbox_capability_fail_closed(): void {
        $now = 1000;
        $headers = [];
        $diagnostics = $this->makeDiagnostics($now, $headers);
        $this->authorizeInboxOperator();
        $diagnostics->startDiagnostic();
        $this->seedTaggedSample($diagnostics, 812, 91);
        $observation = $diagnostics->currentInboxObservation();
        $sampleRef = $observation['samples'][0]['sample_ref'];

        $GLOBALS['wddtf_test_capabilities']['gravityflow_inbox'] = false;
        $forbidden = $this->postBrowserEvidence($diagnostics, $observation['session_id'], $sampleRef);
        self::assertFalse($forbidden->success);
        self::assertSame(403, $forbidden->statusCode);

        $GLOBALS['wddtf_test_capabilities']['gravityflow_inbox'] = true;
        $now = 1901;
        $expired = $this->postBrowserEvidence($diagnostics, $observation['session_id'], $sampleRef);
        self::assertFalse($expired->success);
        self::assertSame(409, $expired->statusCode);
    }

    public function test_duplicate_browser_write_is_idempotent_and_bounded_by_server_sample(): void {
        $now = 1000;
        $headers = [];
        $diagnostics = $this->makeDiagnostics($now, $headers);
        $this->authorizeInboxOperator();
        $diagnostics->startDiagnostic();
        $this->seedTaggedSample($diagnostics, 812, 91);
        $observation = $diagnostics->currentInboxObservation();
        $sampleRef = $observation['samples'][0]['sample_ref'];

        $first = $this->postBrowserEvidence($diagnostics, $observation['session_id'], $sampleRef);
        $second = $this->postBrowserEvidence($diagnostics, $observation['session_id'], $sampleRef);

        self::assertTrue($first->success);
        self::assertTrue($second->success);
        self::assertFalse($first->data['duplicate']);
        self::assertTrue($second->data['duplicate']);
        $after = $diagnostics->currentInboxObservation();
        self::assertSame(1, $after['sample_count_total']);
        self::assertSame(1, $after['browser_evidence_count']);
    }

    public function test_multiple_candidate_refs_remain_browser_ambiguous_instead_of_timing_guessed(): void {
        $now = 1000;
        $headers = [];
        $diagnostics = $this->makeDiagnostics($now, $headers);
        $this->authorizeInboxOperator();
        $diagnostics->startDiagnostic();
        $diagnostics->observeEntryCreated(['id' => 812, 'form_id' => 91], ['id' => 91]);
        $diagnostics->observeEntryCreated(['id' => 813, 'form_id' => 91], ['id' => 91]);

        $GLOBALS['wddtf_test_is_ajax'] = true;
        $diagnostics->observeInboxRender([], []);
        $diagnostics->observeInboxFieldValue('row-a', 91, 5, ['id' => 812, 'form_id' => 91]);
        $diagnostics->observeInboxFieldValue('row-b', 91, 5, ['id' => 813, 'form_id' => 91]);
        $diagnostics->persistObservedInboxRequest();

        $observation = $diagnostics->currentInboxObservation();
        self::assertCount(2, $observation['samples'][0]['candidate_trace_refs']);
        $response = $this->postBrowserEvidence(
            $diagnostics,
            $observation['session_id'],
            $observation['samples'][0]['sample_ref'],
            ['ui_signal' => 'dom_mutation']
        );
        self::assertTrue($response->success);

        $after = $diagnostics->currentInboxObservation();
        self::assertSame('BROWSER_EVIDENCE_AMBIGUOUS', $after['browser_analysis']['classification']);
        self::assertSame('MULTIPLE_ENTRY_CANDIDATES', $after['analysis']['classification']);
        self::assertFalse($after['browser_analysis']['entry_visible_to_user_proven']);
    }

    public function test_markdown_contract_keeps_browser_interpretation_separate_from_visibility_claim(): void {
        $report = [
            'meta' => ['timestamp' => '2026-09-15T00:00:00Z'],
            'bottlenecks' => [],
            'layers' => [
                'cron' => [],
                'gravity' => [
                    'hosts' => [],
                    'inbox_observation' => [
                        'session_id' => 'ds-aaaaaaaaaaaaaaaa',
                        'status' => 'observing',
                        'trace_count' => 1,
                        'sample_count_total' => 1,
                        'ajax_sample_count' => 1,
                        'browser_evidence_count' => 1,
                        'analysis' => ['classification' => 'TRACE_COMPLETE_TO_SERVER_INBOX_OBSERVATION'],
                        'browser_analysis' => [
                            'classification' => 'BROWSER_RESPONSE_RECEIVED',
                            'reason' => 'correlated_response_received_without_ui_signal',
                            'entry_visible_to_user_proven' => false,
                        ],
                        'integrity' => ['uncertain' => false],
                        'evidence' => [],
                        'unknowns' => ['entry_visible_to_user_not_proven' => true],
                        'traces' => [],
                        'samples' => [],
                    ],
                ],
            ],
            'recommendations' => [],
            'top_offenders' => [],
        ];

        $markdown = (new Report_Builder())->toMarkdown($report);
        self::assertStringContainsString('Browser analysis: BROWSER_RESPONSE_RECEIVED', $markdown);
        self::assertStringContainsString('Browser evidence samples: 1', $markdown);
        self::assertStringContainsString('Entry visible to user proven: no', $markdown);
    }

    private function makeDiagnostics(int &$now = 1000, array &$headers = []): GravityDiagnostics {
        $store = new SessionStore(
            static function() use (&$now): int { return $now; },
            static fn(): string => 'ds-bbbbbbbbbbbbbbbb',
            static fn(): string => 'lk-bbbbbbbbbbbbbbbbbbbbbbbb',
            static function(int $microseconds): void {}
        );

        return new GravityDiagnostics(
            $store,
            static function() use (&$now): int { return $now; },
            999.5,
            static function(string $name, string $value) use (&$headers): bool {
                $headers[] = [$name, $value];
                return true;
            }
        );
    }

    private function authorizeInboxOperator(): void {
        $GLOBALS['wddtf_test_logged_in'] = true;
        $GLOBALS['wddtf_test_capabilities']['gravityflow_inbox'] = true;
    }

    private function seedPositiveServerChain(GravityDiagnostics $diagnostics, int $entryId, int $formId): void {
        $entry = ['id' => $entryId, 'form_id' => $formId];
        $form = ['id' => $formId];
        $step = new WddtfBrowserStep(301, $entryId, $formId);
        $diagnostics->observeEntryCreated($entry, $form);
        $diagnostics->observeAfterSubmission($entry, $form);
        $diagnostics->observeStepStart(301, $entryId, $formId, 'pending', $step);
        $diagnostics->observeStepAssignees([new WddtfBrowserAssignee('user_id|77')], $step);
    }

    private function seedTaggedSample(GravityDiagnostics $diagnostics, int $entryId, int $formId): void {
        $diagnostics->observeEntryCreated(['id' => $entryId, 'form_id' => $formId], ['id' => $formId]);
        $GLOBALS['wddtf_test_is_ajax'] = true;
        $diagnostics->observeInboxRender([], []);
        $diagnostics->observeInboxFieldValue('private-row-value', $formId, 5, ['id' => $entryId, 'form_id' => $formId]);
        $diagnostics->persistObservedInboxRequest();
    }

    private function postBrowserEvidence(
        GravityDiagnostics $diagnostics,
        string $sessionId,
        string $sampleRef,
        array $overrides = []
    ): WddtfJsonResponse {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array_merge(
            [
                'action' => GravityDiagnostics::BROWSER_EVIDENCE_ACTION,
                'nonce' => wp_create_nonce('wddtf-gravity-browser:v1|' . $sessionId),
                'sample_ref' => $sampleRef,
                'outcome' => 'success',
                'http_status' => '200',
                'client_received_ms' => '1789460000123',
                'visibility' => 'visible',
                'ui_signal' => 'none',
            ],
            $overrides
        );

        try {
            $diagnostics->recordBrowserEvidence();
        } catch ( WddtfJsonResponse $response ) {
            return $response;
        }

        self::fail('Browser evidence endpoint did not terminate with JSON response.');
    }
}
