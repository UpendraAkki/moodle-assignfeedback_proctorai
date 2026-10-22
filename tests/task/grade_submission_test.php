<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace assignfeedback_proctorai\task;

use assignfeedback_proctorai\api\api_exception;
use assignfeedback_proctorai\local\account;
use assignfeedback_proctorai\local\job_manager;
use assignfeedback_proctorai\tests\grading_testcase;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../grading_testcase.php');

/**
 * End-to-end tests of the grading task: queue, upload, grade, store, and every error path.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignfeedback_proctorai\task\grade_submission
 * @covers     \assignfeedback_proctorai\local\job_manager
 * @covers     \assignfeedback_proctorai\local\result_applier
 */
final class grade_submission_test extends grading_testcase {
    /**
     * Queue the student's submission and return the job.
     *
     * @param \assign $assign The assignment.
     * @return \stdClass
     */
    private function queue(\assign $assign): \stdClass {
        $job = job_manager::queue($assign, $this->student->id, job_manager::ORIGIN_MANUAL, $this->teacher->id);
        $this->assertNotNull($job);
        return $job;
    }

    /**
     * Load a job.
     *
     * @param int $id The job id.
     * @return \stdClass
     */
    private function job(int $id): \stdClass {
        global $DB;
        return $DB->get_record('assignfeedback_proctorai_job', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * The student's grade in the gradebook, or null.
     *
     * @param \assign $assign The assignment.
     * @return float|null
     */
    private function gradebook_grade(\assign $assign): ?float {
        $grades = grade_get_grades($this->course->id, 'mod', 'assign', $assign->get_instance()->id, $this->student->id);
        $grade = $grades->items[0]->grades[$this->student->id]->grade ?? null;
        return $grade === null ? null : (float) $grade;
    }

    /**
     * Marking workflow on: files are uploaded through \curl, the grade is pre-filled, the state is
     * "Ready for review" and the gradebook stays empty.
     */
    public function test_workflow_on_prefills_draft_grade(): void {
        global $DB;
        $assign = $this->create_assign(['markingworkflow' => 1]);
        $this->submit_text($assign);
        $this->add_file($assign);
        $job = $this->queue($assign);

        // Moodle's curl mock is a stack: push the last response first.
        \curl::mock_response(json_encode($this->grade_response()));
        \curl::mock_response('');
        \curl::mock_response(json_encode(['uploads' => [[
            'uploadId' => 'upl_0123456789abcdef01234567', 'method' => 'PUT',
            'url' => 'https://r2.example.test/upl', 'headers' => ['Content-Type' => 'image/jpeg'], 'expiresAt' => 'x',
        ]], 'retainedUntil' => 'y']));

        $this->run_job($job->id);

        $job = $this->job($job->id);
        $this->assertSame(job_manager::STATUS_DONE, $job->status);
        $this->assertSame('grd_0123456789abcdef01234567', $job->remoteid);
        $this->assertNull($job->requestjson);
        $this->assertSame('["upl_0123456789abcdef01234567"]', $job->uploads);

        $grade = $assign->get_user_grade($this->student->id, false);
        $this->assertEquals(70.0, (float) $grade->grade);
        $flags = $assign->get_user_flags($this->student->id, false);
        $this->assertSame(ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW, $flags->workflowstate);
        $this->assertNull($this->gradebook_grade($assign));

        $row = $DB->get_record('assignfeedback_proctorai', ['grade' => $grade->id], '*', MUST_EXIST);
        $this->assertEquals(7, $row->earned);
        $this->assertEquals(10, $row->maxmarks);
        $this->assertEquals(70, $row->appliedgrade);
        $this->assertEquals(1, $row->reviewcount);
        $this->assertEquals(0, $row->approved);
        $this->assertStringNotContainsString('Read Off The Sheet', $row->resultjson);
    }

    /**
     * Marking workflow off: only a suggestion is stored and students see nothing until a teacher saves.
     */
    public function test_workflow_off_stores_suggestion_only(): void {
        $assign = $this->create_assign(['markingworkflow' => 0]);
        $this->submit_text($assign);
        $job = $this->queue($assign);
        $client = $this->fake_client()->respond(200, $this->grade_response());

        $this->run_job($job->id);

        $this->assertSame(job_manager::STATUS_DONE, $this->job($job->id)->status);
        $body = json_decode($client->requests[0]['payload'], true);
        $this->assertSame('Paris. Plants make food from light.', $body['answerSheetText']);
        $this->assertArrayNotHasKey('files', $body);
        $this->assertArrayNotHasKey('studentName', $body);
        $this->assertSame('Grade 8', $body['level']);
        $this->assertCount(2, $body['questions']);

        $grade = $assign->get_user_grade($this->student->id, false);
        $this->assertLessThan(0, (float) $grade->grade);
        $this->assertNull($this->gradebook_grade($assign));

        $plugin = $assign->get_feedback_plugin_by_type('proctorai');
        $this->setUser($this->student);
        $this->assertTrue($plugin->is_empty($grade));
        $this->assertSame('', $plugin->view($grade));

        // The teacher saves the grader form: the feedback is approved and the student can see it.
        $this->setUser($this->teacher);
        $data = (object) ['assignfeedbackproctorai_editor' => ['text' => '<p>Well done.</p>', 'format' => FORMAT_HTML]];
        $this->assertTrue($plugin->is_feedback_modified($grade, $data));
        $plugin->save($grade, $data);
        $this->setUser($this->student);
        $this->assertFalse($plugin->is_empty($grade));
        $this->assertStringContainsString('Well done.', $plugin->view($grade));
    }

    /**
     * A grade a teacher already gave is never overwritten.
     */
    public function test_existing_human_grade_is_kept(): void {
        $assign = $this->create_assign(['markingworkflow' => 1]);
        $this->submit_text($assign);
        // Graded by hand. The generator helper for this was renamed in Moodle 5, so call the assign directly.
        $this->setUser($this->teacher);
        $assign->testable_apply_grade_to_user((object) ['grade' => 55.0], $this->student->id, 0);
        $this->setAdminUser();
        $job = $this->queue($assign);
        $this->fake_client()->respond(200, $this->grade_response());

        $this->run_job($job->id);

        $grade = $assign->get_user_grade($this->student->id, false);
        $this->assertEquals(55.0, (float) $grade->grade);
        global $DB;
        $row = $DB->get_record('assignfeedback_proctorai', ['grade' => $grade->id]);
        $this->assertEquals(70, $row->suggestedgrade);
        $this->assertNull($row->appliedgrade);
    }

    /**
     * Pre-filling a failing draft grade does not open a new attempt under "until pass" or "automatically".
     *
     * @dataProvider reopen_methods
     * @param string $method The attempt reopen method.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('reopen_methods')]
    public function test_draft_grade_does_not_reopen_attempt(string $method): void {
        global $DB;
        $assign = $this->create_assign([
            'markingworkflow' => 1,
            'attemptreopenmethod' => $method,
            'maxattempts' => ASSIGN_UNLIMITED_ATTEMPTS,
        ]);
        $gradeitem = $assign->get_grade_item();
        $gradeitem->gradepass = 80;
        $gradeitem->update();
        $this->submit_text($assign);
        $job = $this->queue($assign);
        $this->fake_client()->respond(200, $this->grade_response());

        $this->run_job($job->id);

        $this->assertEquals(70.0, (float) $assign->get_user_grade($this->student->id, false)->grade);
        $this->assertSame(1, $DB->count_records('assign_submission',
            ['assignment' => $assign->get_instance()->id, 'userid' => $this->student->id]));
        $this->assertEquals(0, $assign->get_user_submission($this->student->id, false)->attemptnumber);
    }

    /**
     * Reopen methods that act on a grade without a teacher.
     *
     * @return array
     */
    public static function reopen_methods(): array {
        return [
            'untilpass' => [ASSIGN_ATTEMPT_REOPEN_METHOD_UNTILPASS],
            'automatic' => [ASSIGN_ATTEMPT_REOPEN_METHOD_AUTOMATIC],
        ];
    }

    /**
     * 429: the job waits for Retry-After in a new task and keeps its attempt count.
     */
    public function test_rate_limited_reschedules(): void {
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $job = $this->queue($assign);
        $this->fake_client()->respond(429, ['error' => ['code' => 'rate_limited', 'message' => 'Slow down']],
            ['retry-after' => '45']);
        $before = count(\core\task\manager::get_adhoc_tasks(grade_submission::class));

        $this->run_job($job->id);

        $job = $this->job($job->id);
        $this->assertSame(job_manager::STATUS_QUEUED, $job->status);
        $this->assertEquals(0, $job->attempts);
        $tasks = \core\task\manager::get_adhoc_tasks(grade_submission::class);
        $this->assertCount($before + 1, $tasks);
        $latest = max(array_map(fn($task) => $task->get_next_run_time(), $tasks));
        $this->assertGreaterThanOrEqual(time() + 44, $latest);
    }

    /**
     * 502: the task throws so Moodle retries, and the retry sends the same request with the same key.
     */
    public function test_server_error_retries_with_same_idempotency_key(): void {
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $job = $this->queue($assign);
        $client = $this->fake_client()
            ->respond(502, ['error' => ['code' => 'grading_failed', 'message' => 'Try again']])
            ->respond(200, $this->grade_response());

        try {
            $this->run_job($job->id);
            $this->fail('Expected the task to throw');
        } catch (api_exception $e) {
            $this->assertSame(502, $e->httpstatus);
        }
        $after = $this->job($job->id);
        $this->assertSame(job_manager::STATUS_QUEUED, $after->status);
        $this->assertEquals(1, $after->attempts);
        $this->assertNotEmpty($after->requestjson);

        $this->run_job($job->id);

        $this->assertSame(job_manager::STATUS_DONE, $this->job($job->id)->status);
        $this->assertCount(2, $client->requests);
        $this->assertSame($job->idempotencykey, $client->request_headers(0)['idempotency-key']);
        $this->assertSame($job->idempotencykey, $client->request_headers(1)['idempotency-key']);
        $this->assertSame($client->requests[0]['payload'], $client->requests[1]['payload']);
    }

    /**
     * Transient errors stop at the attempt cap.
     */
    public function test_attempt_cap(): void {
        set_config('maxattempts', 1, 'assignfeedback_proctorai');
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $job = $this->queue($assign);
        $this->fake_client()->respond(503, ['error' => ['code' => 'unavailable', 'message' => 'Down']]);

        $this->run_job($job->id);

        $job = $this->job($job->id);
        $this->assertSame(job_manager::STATUS_FAILED, $job->status);
        $this->assertSame('unavailable', $job->errorcode);
    }

    /**
     * 402: the job stops as nobalance, admins and the teacher are told once, and later jobs skip the API.
     */
    public function test_insufficient_balance(): void {
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $job = $this->queue($assign);
        \cache::make('assignfeedback_proctorai', 'account')->set('account',
            ['balance' => 1, 'pricePerSheet' => 1, 'sheetsRemaining' => 1, 'fetchedat' => time()]);
        $client = $this->fake_client()->respond(402,
            ['error' => ['code' => 'insufficient_balance', 'message' => 'Buy more', 'balance' => 0]]);
        $sink = $this->redirectMessages();

        $this->run_job($job->id);

        $job = $this->job($job->id);
        $this->assertSame(job_manager::STATUS_NOBALANCE, $job->status);
        $messages = $sink->get_messages();
        $this->assertNotEmpty($messages);
        $this->assertContains((int) $this->teacher->id, array_map(fn($m) => (int) $m->useridto, $messages));
        $this->assertTrue(account::known_out_of_balance());

        // The next job ends without calling the API, and no second notice is sent.
        $sink->clear();
        $job2 = job_manager::queue($assign, $this->student->id, job_manager::ORIGIN_MANUAL, $this->teacher->id);
        $this->assertNotNull($job2);
        $this->assertNotEquals($job->id, $job2->id);
        $this->run_job($job2->id);
        $this->assertSame(job_manager::STATUS_NOBALANCE, $this->job($job2->id)->status);
        $this->assertCount(1, $client->requests);
        $this->assertCount(0, $sink->get_messages());
        $sink->close();
    }

    /**
     * 401: the plugin pauses site-wide; saving the settings resumes and requeues.
     */
    public function test_invalid_key_pauses_and_resumes(): void {
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $job = $this->queue($assign);
        $this->fake_client()->respond(401, ['error' => ['code' => 'invalid_api_key', 'message' => 'Revoked']]);
        $sink = $this->redirectMessages();

        $this->run_job($job->id);

        $this->assertTrue(account::is_paused());
        $this->assertSame(job_manager::STATUS_FAILED, $this->job($job->id)->status);
        $this->assertSame('paused', job_manager::assignment_blocker($assign));
        $this->assertNotEmpty($sink->get_messages());
        $sink->close();

        set_config('apikey', 'pai_test_new', 'assignfeedback_proctorai');
        account::settings_updated('s_assignfeedback_proctorai_apikey');

        $this->assertFalse(account::is_paused());
        $requeued = $this->job($job->id);
        $this->assertSame(job_manager::STATUS_QUEUED, $requeued->status);
        $this->assertNotSame($job->idempotencykey, $requeued->idempotencykey);
    }

    /**
     * 422 on the request itself fails for good with the API's message; upload errors get one fresh retry.
     */
    public function test_unprocessable_and_upload_retry(): void {
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $job = $this->queue($assign);
        $client = $this->fake_client()
            ->respond(422, ['error' => ['code' => 'upload_not_found', 'message' => 'Expired']])
            ->respond(422, ['error' => ['code' => 'too_many_pages', 'message' => 'More than 20 pages']]);

        $this->run_job($job->id);
        $retried = $this->job($job->id);
        $this->assertSame(job_manager::STATUS_QUEUED, $retried->status);
        $this->assertEquals(1, $retried->uploadretries);
        $this->assertNotSame($job->idempotencykey, $retried->idempotencykey);

        $this->run_job($job->id);
        $failed = $this->job($job->id);
        $this->assertSame(job_manager::STATUS_FAILED, $failed->status);
        $this->assertSame('too_many_pages', $failed->errorcode);
        $this->assertSame('More than 20 pages', $failed->errormessage);
        $this->assertCount(2, $client->requests);
    }

    /**
     * A submission changed after queuing is not graded.
     */
    public function test_changed_submission_is_stale(): void {
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $job = $this->queue($assign);
        $client = $this->fake_client();
        $this->submit_text($assign, 'A different answer');

        $this->run_job($job->id);

        $this->assertSame(job_manager::STATUS_STALE, $this->job($job->id)->status);
        $this->assertCount(0, $client->requests);
    }

    /**
     * Nothing is sent without the admin's consent.
     */
    public function test_no_consent_sends_nothing(): void {
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $job = $this->queue($assign);
        $client = $this->fake_client();
        set_config('consent', 0, 'assignfeedback_proctorai');

        $this->run_job($job->id);

        $this->assertSame(job_manager::STATUS_FAILED, $this->job($job->id)->status);
        $this->assertCount(0, $client->requests);
        $this->assertNull(job_manager::queue($assign, $this->student->id, job_manager::ORIGIN_MANUAL));
    }

    /**
     * A marking key that does not parse blocks queuing.
     */
    public function test_invalid_marking_key_blocks(): void {
        $assign = $this->create_assign(['assignfeedback_proctorai_markingkey' => "1. Q [0]"]);
        $this->submit_text($assign);
        $this->assertSame('markingkeyerror', job_manager::assignment_blocker($assign));
        $this->assertNull(job_manager::queue($assign, $this->student->id, job_manager::ORIGIN_MANUAL));
    }
}
