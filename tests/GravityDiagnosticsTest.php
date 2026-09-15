<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WDDTF\Diagnostics\Manager;
use WDDTF\Diagnostics\SessionStore;
use WDDTF\Gravity\GravityDiagnostics;

if ( ! class_exists('GFForms') ) {
    final class GFForms {
        public static string $version = '3.1.1.1';
    }
}

if ( ! class_exists('Gravity_Flow') ) {
    final class Gravity_Flow {
    }
}

final class WddtfFakeStep {
    public function __construct(
        private int $id,
        private int $entryId,
        private int $formId,
        private string $type = 'approval',
        private string $status = 'pending'
    ) {
    }

    public function get_id(): int { return $this->id; }
    public function get_entry_id(): int { return $this->entryId; }
    public function get_form_id(): int { return $this->formId; }
    public function get_type(): string { return $this->type; }
    public function get_status(): string { return $this->status; }
}

final class WddtfFakeAssignee {
    public function __construct(private string $key, private string $id) {
    }

    public function get_key(): string { return $this->key; }
    public function get_id(): string { return $this->id; }
}

final class GravityDiagnosticsTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['wddtf_test_actions'] = [];
        $GLOBALS['wddtf_test_filters'] = [];
        $GLOBALS['wddtf_test_transients'] = [];
        $GLOBALS['wddtf_test_transient_failures'] = [];
        $GLOBALS['wddtf_test_is_ajax'] = false;
        $GLOBALS['wddtf_test_is_admin'] = false;
        $GLOBALS['wddtf_test_did_actions'] = [];
    }

    public function test_register_uses_documented_lifecycle_and_inbox_hooks(): void {
        $diagnostics = $this->makeDiagnostics();
        $diagnostics->register();

        foreach ( [
            'gform_entry_created' => 2,
            'gform_after_submission' => 2,
            'gravityflow_step_start' => 5,
            'gravityflow_step_complete' => 4,
            'gravityflow_post_process_workflow' => 4,
            'gravityflow_workflow_complete' => 3,
        ] as $hook => $acceptedArgs ) {
            self::assertArrayHasKey($hook, $GLOBALS['wddtf_test_actions']);
            self::assertSame(PHP_INT_MAX, $GLOBALS['wddtf_test_actions'][$hook][0][1]);
            self::assertSame($acceptedArgs, $GLOBALS['wddtf_test_actions'][$hook][0][2]);
        }

        self::assertArrayHasKey('gravityflow_step_assignees', $GLOBALS['wddtf_test_filters']);
        self::assertSame(2, $GLOBALS['wddtf_test_filters']['gravityflow_step_assignees'][0][2]);
        self::assertArrayHasKey(GravityDiagnostics::INBOX_FILTER, $GLOBALS['wddtf_test_filters']);
        self::assertArrayHasKey(GravityDiagnostics::INBOX_FIELD_VALUE_FILTER, $GLOBALS['wddtf_test_filters']);
        self::assertArrayHasKey('shutdown', $GLOBALS['wddtf_test_actions']);
    }

    public function test_start_is_bounded_and_duplicate_observation_is_not_created(): void {
        $diagnostics = $this->makeDiagnostics();

        $started = $diagnostics->startDiagnostic();
        $duplicate = $diagnostics->startInboxObservation();

        self::assertTrue($started['started']);
        self::assertSame('observing', $started['reason']);
        self::assertSame(20, $started['observation']['sample_limit']);
        self::assertSame(10, $started['observation']['trace_limit']);
        self::assertSame(24, $started['observation']['trace_event_limit']);
        self::assertLessThanOrEqual(900, $started['observation']['remaining_seconds']);
        self::assertFalse($duplicate['started']);
        self::assertSame('already_observing', $duplicate['reason']);
    }

    public function test_entry_submission_step_assignee_and_inbox_correlate_without_raw_host_ids(): void {
        $diagnostics = $this->makeDiagnostics();
        $diagnostics->startDiagnostic();
        $entry = [
            'id' => 812,
            'form_id' => 91,
            '1' => 'SecretNameCanary123456789012345',
            '2' => 'person@example.test',
        ];
        $form = ['id' => 91, 'title' => 'Secret Form'];
        $step = new WddtfFakeStep(301, 812, 91, 'approval', 'pending');
        $assignees = [
            new WddtfFakeAssignee('user_id|77', '77'),
            new WddtfFakeAssignee('email|person@example.test', 'person@example.test'),
        ];

        $diagnostics->observeEntryCreated($entry, $form);
        $diagnostics->observeAfterSubmission($entry, $form);
        $diagnostics->observeStepStart(301, 812, 91, 'pending', $step);
        self::assertSame($assignees, $diagnostics->observeStepAssignees($assignees, $step));

        $GLOBALS['wddtf_test_is_ajax'] = true;
        self::assertSame(['entry' => 'Entry'], $diagnostics->observeInboxRender(['entry' => 'Entry'], []));
        self::assertSame(
            'SecretInboxCanary123456789012345',
            $diagnostics->observeInboxFieldValue('SecretInboxCanary123456789012345', 91, 5, $entry)
        );
        $diagnostics->persistObservedInboxRequest();

        $observation = $diagnostics->snapshot()['inbox_observation'];
        $trace = $observation['traces'][0];
        $encoded = (string) wp_json_encode($observation);
        $assigneeEvent = array_values(array_filter(
            $trace['events'],
            static fn(array $event): bool => 'assignees_observed' === $event['type']
        ))[0];

        self::assertSame('TRACE_COMPLETE_TO_SERVER_INBOX_OBSERVATION', $trace['analysis']['classification']);
        self::assertTrue($trace['analysis']['proven']['entry_created']);
        self::assertTrue($trace['analysis']['proven']['submission_completed']);
        self::assertTrue($trace['analysis']['proven']['step_started']);
        self::assertTrue($trace['analysis']['proven']['positive_assignee_count']);
        self::assertTrue($trace['analysis']['proven']['ajax_inbox_linked']);
        self::assertSame(2, $assigneeEvent['assignee_count']);
        self::assertSame(['email' => 1, 'user_id' => 1], $assigneeEvent['assignee_types']);
        self::assertCount(2, $assigneeEvent['assignee_refs']);
        self::assertMatchesRegularExpression('/^gt-[a-f0-9]{16}$/', $trace['trace_ref']);
        self::assertMatchesRegularExpression('/^gf-[a-f0-9]{16}$/', $trace['form_ref']);
        self::assertStringNotContainsString('SecretNameCanary123456789012345', $encoded);
        self::assertStringNotContainsString('SecretInboxCanary123456789012345', $encoded);
        self::assertStringNotContainsString('person@example.test', $encoded);
        self::assertStringNotContainsString('"entry_id"', $encoded);
        self::assertStringNotContainsString('"form_id"', $encoded);
        self::assertFalse($observation['evidence']['raw_form_entry_values_stored']);
        self::assertFalse($observation['evidence']['raw_host_identifiers_stored']);
        self::assertFalse($observation['evidence']['raw_assignee_identity_stored']);
    }

    public function test_step_complete_post_process_next_step_and_workflow_complete_preserve_chronology(): void {
        $diagnostics = $this->makeDiagnostics();
        $diagnostics->startDiagnostic();
        $entry = ['id' => 812, 'form_id' => 91];
        $form = ['id' => 91];
        $first = new WddtfFakeStep(301, 812, 91, 'approval', 'approved');
        $second = new WddtfFakeStep(302, 812, 91, 'user_input', 'pending');

        $diagnostics->observeEntryCreated($entry, $form);
        $diagnostics->observeAfterSubmission($entry, $form);
        $diagnostics->observeStepStart(301, 812, 91, 'pending', $first);
        $diagnostics->observeStepAssignees([new WddtfFakeAssignee('role|editor', 'editor')], $first);
        $diagnostics->observeStepComplete(301, 812, 91, 'approved');
        $diagnostics->observePostProcessWorkflow($form, 812, 301, 302);
        $diagnostics->observeStepStart(302, 812, 91, 'pending', $second);
        $diagnostics->observeStepAssignees([new WddtfFakeAssignee('user_id|88', '88')], $second);
        $diagnostics->observeWorkflowComplete(812, $form, 'complete');

        $events = $diagnostics->snapshot()['inbox_observation']['traces'][0]['events'];
        self::assertSame(
            [
                'entry_created',
                'submission_completed',
                'step_started',
                'assignees_observed',
                'step_completed',
                'workflow_processed',
                'step_started',
                'assignees_observed',
                'workflow_completed',
            ],
            array_column($events, 'type')
        );
        self::assertSame($events[5]['next_step_ref'], $events[6]['step_ref']);
        self::assertSame('user_input', $events[6]['step_type']);
        self::assertSame('complete', $events[8]['workflow_status']);
    }

    public function test_first_inconsistent_point_distinguishes_missing_entry_and_submission_boundaries(): void {
        $diagnostics = $this->makeDiagnostics();
        $diagnostics->startDiagnostic();
        $step = new WddtfFakeStep(301, 812, 91);

        $diagnostics->observeStepStart(301, 812, 91, 'pending', $step);
        $trace = $diagnostics->snapshot()['inbox_observation']['traces'][0];
        self::assertSame('ENTRY_NOT_OBSERVED', $trace['analysis']['classification']);

        $GLOBALS['wddtf_test_transients'] = [];
        $fresh = $this->makeDiagnostics('ds-eeeeeeeeeeeeeeee');
        $fresh->startDiagnostic();
        $fresh->observeEntryCreated(['id' => 812, 'form_id' => 91], ['id' => 91]);
        $trace = $fresh->snapshot()['inbox_observation']['traces'][0];
        self::assertSame('SUBMISSION_COMPLETE_NOT_OBSERVED', $trace['analysis']['classification']);
    }

    public function test_first_inconsistent_point_distinguishes_missing_workflow_and_zero_assignees(): void {
        $diagnostics = $this->makeDiagnostics();
        $diagnostics->startDiagnostic();
        $entry = ['id' => 812, 'form_id' => 91];
        $form = ['id' => 91];
        $diagnostics->observeEntryCreated($entry, $form);
        $diagnostics->observeAfterSubmission($entry, $form);

        $trace = $diagnostics->snapshot()['inbox_observation']['traces'][0];
        self::assertSame('WORKFLOW_NOT_OBSERVED', $trace['analysis']['classification']);

        $step = new WddtfFakeStep(301, 812, 91);
        $diagnostics->observeStepStart(301, 812, 91, 'pending', $step);
        $diagnostics->observeStepAssignees([], $step);
        $trace = $diagnostics->snapshot()['inbox_observation']['traces'][0];

        self::assertSame('NO_ASSIGNEE_OBSERVED', $trace['analysis']['classification']);
        self::assertTrue($trace['analysis']['proven']['assignee_evaluation_observed']);
        self::assertFalse($trace['analysis']['proven']['positive_assignee_count']);
    }

    public function test_multiple_candidate_entries_are_preserved_as_ambiguous(): void {
        $diagnostics = $this->makeDiagnostics();
        $diagnostics->startDiagnostic();

        foreach ( [812, 813] as $entryId ) {
            $entry = ['id' => $entryId, 'form_id' => 91];
            $diagnostics->observeEntryCreated($entry, ['id' => 91]);
            $diagnostics->observeAfterSubmission($entry, ['id' => 91]);
        }

        $observation = $diagnostics->snapshot()['inbox_observation'];
        self::assertSame(2, $observation['trace_count']);
        self::assertSame('MULTIPLE_ENTRY_CANDIDATES', $observation['analysis']['classification']);
        self::assertNotSame($observation['traces'][0]['trace_ref'], $observation['traces'][1]['trace_ref']);
    }

    public function test_inbox_render_without_row_level_link_is_not_fabricated_as_trace_success(): void {
        $diagnostics = $this->makeDiagnostics();
        $diagnostics->startDiagnostic();
        $entry = ['id' => 812, 'form_id' => 91];
        $form = ['id' => 91];
        $step = new WddtfFakeStep(301, 812, 91);
        $diagnostics->observeEntryCreated($entry, $form);
        $diagnostics->observeAfterSubmission($entry, $form);
        $diagnostics->observeStepStart(301, 812, 91, 'pending', $step);
        $diagnostics->observeStepAssignees([new WddtfFakeAssignee('user_id|77', '77')], $step);

        $GLOBALS['wddtf_test_is_ajax'] = true;
        $diagnostics->observeInboxRender([], []);
        $diagnostics->persistObservedInboxRequest();

        $trace = $diagnostics->snapshot()['inbox_observation']['traces'][0];
        self::assertSame('INSUFFICIENT_EVIDENCE', $trace['analysis']['classification']);
        self::assertSame('inbox_observed_without_row_level_candidate_link', $trace['analysis']['reason']);
    }

    public function test_passive_snapshot_does_not_create_or_repair_state(): void {
        set_transient('wddtf_gravityflow_inbox_observation_current', 'malformed-pointer', 900);
        $before = $GLOBALS['wddtf_test_transients'];
        $diagnostics = $this->makeDiagnostics();

        $first = $diagnostics->snapshot()['inbox_observation'];
        $second = $diagnostics->snapshot()['inbox_observation'];

        self::assertSame('unknown', $first['status']);
        self::assertSame('expired_or_invalid_session', $first['reason']);
        self::assertSame($first, $second);
        self::assertSame($before, $GLOBALS['wddtf_test_transients']);
    }

    public function test_expired_session_stops_accepting_lifecycle_events_without_passive_cleanup(): void {
        $now = 1000;
        $store = new SessionStore(
            static function() use (&$now): int { return $now; },
            static fn(): string => 'ds-aaaaaaaaaaaaaaaa'
        );
        $diagnostics = new GravityDiagnostics($store, static function() use (&$now): int { return $now; });
        $diagnostics->startDiagnostic();
        $pointerBefore = get_transient('wddtf_gravityflow_inbox_observation_current');
        $now = 2000;

        $diagnostics->observeEntryCreated(['id' => 812, 'form_id' => 91], ['id' => 91]);
        $observation = $diagnostics->snapshot()['inbox_observation'];

        self::assertSame('unknown', $observation['status']);
        self::assertSame('expired_or_invalid_session', $observation['reason']);
        self::assertSame($pointerBefore, get_transient('wddtf_gravityflow_inbox_observation_current'));
    }

    public function test_trace_and_event_retention_are_bounded(): void {
        $diagnostics = $this->makeDiagnostics();
        $diagnostics->startDiagnostic();

        for ( $entryId = 800; $entryId < 812; ++$entryId ) {
            $diagnostics->observeEntryCreated(['id' => $entryId, 'form_id' => 91], ['id' => 91]);
        }
        $observation = $diagnostics->snapshot()['inbox_observation'];
        self::assertSame(10, $observation['trace_count']);
        self::assertTrue($observation['traces_truncated']);
        self::assertSame('INSUFFICIENT_EVIDENCE', $observation['analysis']['classification']);

        $GLOBALS['wddtf_test_transients'] = [];
        $fresh = $this->makeDiagnostics('ds-cccccccccccccccc');
        $fresh->startDiagnostic();
        for ( $i = 0; $i < 30; ++$i ) {
            $fresh->observeAfterSubmission(['id' => 812, 'form_id' => 91], ['id' => 91]);
        }
        $trace = $fresh->snapshot()['inbox_observation']['traces'][0];
        self::assertSame(30, $trace['event_count_total']);
        self::assertCount(24, $trace['events']);
        self::assertTrue($trace['events_truncated']);
    }

    public function test_observation_stops_writing_after_twenty_inbox_samples(): void {
        $now = 1000;
        $store = new SessionStore(
            static function() use (&$now): int { return $now; },
            static fn(): string => 'ds-aaaaaaaaaaaaaaaa'
        );
        $first = new GravityDiagnostics($store, static function() use (&$now): int { return $now; }, 999.0);
        $first->startDiagnostic();

        for ( $i = 0; $i < 20; ++$i ) {
            $now = 1001 + $i;
            $request = new GravityDiagnostics($store, static function() use (&$now): int { return $now; }, microtime(true));
            $request->observeInboxRender([], []);
            $request->persistObservedInboxRequest();
        }

        $complete = (new GravityDiagnostics($store, static function() use (&$now): int { return $now; }))->snapshot()['inbox_observation'];
        self::assertSame('completed', $complete['status']);
        self::assertSame(20, $complete['sample_count_total']);
        self::assertCount(20, $complete['samples']);
    }

    public function test_host_snapshot_uses_privacy_safe_version_label_and_manager_presents_version_after_redaction(): void {
        $diagnostics = $this->makeDiagnostics();
        $hosts = $diagnostics->snapshot()['hosts'];
        $presented = (new Manager(null, $diagnostics))->getGravityDiagnostics()['hosts'];

        self::assertTrue($hosts['gravity_forms']['available']);
        self::assertSame('v3.1.1.1', $hosts['gravity_forms']['version']);
        self::assertSame('3.1.1.1', $presented['gravity_forms']['version']);
        self::assertTrue($hosts['gravity_flow']['available']);
    }

    private function makeDiagnostics(string $sessionId = 'ds-bbbbbbbbbbbbbbbb'): GravityDiagnostics {
        $now = 1000;
        $clock = static function() use (&$now): int { return $now; };
        $store = new SessionStore($clock, static fn(): string => $sessionId);

        return new GravityDiagnostics($store, $clock, microtime(true));
    }
}
