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

namespace assignfeedback_proctorai\local;

use assignfeedback_proctorai\api\api_exception;
use assignfeedback_proctorai\api\client;
use assignfeedback_proctorai\external\get_status;
use assignfeedback_proctorai\privacy\provider;
use assignfeedback_proctorai\tests\grading_testcase;
use core_privacy\local\request\writer;
use mod_assign\privacy\assign_plugin_request_data;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../grading_testcase.php');

/**
 * What students see stays what a teacher reviewed, nothing is charged twice, and teachers
 * only act on students they can see: the release checks.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignfeedback_proctorai\local\job_manager
 * @covers     \assignfeedback_proctorai\local\result_applier
 * @covers     \assignfeedback_proctorai\local\submission_collector
 * @covers     \assignfeedback_proctorai\api\client
 * @covers     \assignfeedback_proctorai\external\get_status
 * @covers     \assignfeedback_proctorai\privacy\provider
 */
final class review_safety_test extends grading_testcase {
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
     * Grade the student's current submission with the scripted result, as a teacher's request.
     *
     * @param \assign $assign The assignment.
     * @return \stdClass The finished job.
     */
    private function grade_now(\assign $assign): \stdClass {
        $job = job_manager::queue($assign, $this->student->id, job_manager::ORIGIN_MANUAL, $this->teacher->id);
        $this->assertNotNull($job);
        $this->fake_client()->respond(200, $this->grade_response());
        $this->run_job($job->id);
        return $this->job($job->id);
    }

    /**
     * Save the grader form as the teacher, which approves the result.
     *
     * @param \assign $assign The assignment.
     */
    private function approve(\assign $assign): void {
        $grade = $assign->get_user_grade($this->student->id, false);
        $plugin = $assign->get_feedback_plugin_by_type('proctorai');
        $this->setUser($this->teacher);
        $plugin->save($grade, (object) [
            'assignfeedbackproctorai_editor' => ['text' => '<p>Checked by the teacher.</p>', 'format' => FORMAT_HTML],
        ]);
        $this->setAdminUser();
    }

    /**
     * The stored result of the student's current attempt.
     *
     * @param \assign $assign The assignment.
     * @return \stdClass
     */
    private function stored_result(\assign $assign): \stdClass {
        global $DB;
        $grade = $assign->get_user_grade($this->student->id, false);
        return $DB->get_record('assignfeedback_proctorai', ['grade' => $grade->id], '*', MUST_EXIST);
    }

    /**
     * After a teacher approves, a resubmission is not graded automatically: the student keeps
     * seeing what the teacher approved.
     */
    public function test_auto_regrade_skips_approved_result(): void {
        $assign = $this->create_assign(['markingworkflow' => 0, 'assignfeedback_proctorai_autograde' => 1]);
        $this->submit_text($assign);
        $this->grade_now($assign);
        $this->approve($assign);

        $this->submit_text($assign, 'A changed answer.');
        $this->assertNull(job_manager::queue($assign, $this->student->id, job_manager::ORIGIN_AUTO));

        $row = $this->stored_result($assign);
        $this->assertEquals(1, $row->approved);
        $this->assertStringContainsString('Checked by the teacher.', $row->feedbacktext);
    }

    /**
     * A teacher's own re-grade of an approved result makes it a draft again: the student stops
     * seeing AI marks until a teacher approves the new result.
     */
    public function test_manual_regrade_resets_approval(): void {
        $assign = $this->create_assign(['markingworkflow' => 0]);
        $this->submit_text($assign);
        $this->grade_now($assign);
        $this->approve($assign);

        $this->submit_text($assign, 'A changed answer.');
        $job = $this->grade_now($assign);
        $this->assertSame(job_manager::STATUS_DONE, $job->status);

        $row = $this->stored_result($assign);
        $this->assertEquals(0, $row->approved);
        $this->assertNull($row->approvedby);
        $grade = $assign->get_user_grade($this->student->id, false);
        $plugin = $assign->get_feedback_plugin_by_type('proctorai');
        $this->setUser($this->student);
        $this->assertTrue($plugin->is_empty($grade));
    }

    /**
     * With marking workflow, a released grade cannot be sent again until the state moves back.
     */
    public function test_released_grade_is_not_regraded(): void {
        $assign = $this->create_assign(['markingworkflow' => 1]);
        $this->submit_text($assign);
        $this->grade_now($assign);
        $flags = $assign->get_user_flags($this->student->id, true);
        $flags->workflowstate = ASSIGN_MARKING_WORKFLOW_STATE_RELEASED;
        $assign->update_user_flags($flags);

        $this->assertSame('released', job_manager::user_blocker($assign, $this->student->id));
        $this->assertNull(job_manager::queue($assign, $this->student->id, job_manager::ORIGIN_MANUAL, $this->teacher->id));
        $this->assertNull(job_manager::queue($assign, $this->student->id, job_manager::ORIGIN_AUTO));
    }

    /**
     * Retrying a job that failed after its request may have been graded resends the same request with
     * the same idempotency key, so Proctor AI replays the result instead of charging again.
     */
    public function test_retry_after_network_failure_reuses_key(): void {
        set_config('maxattempts', 1, 'assignfeedback_proctorai');
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $job = job_manager::queue($assign, $this->student->id, job_manager::ORIGIN_MANUAL, $this->teacher->id);
        $this->fake_client()->respond(0);
        $this->run_job($job->id);
        $failed = $this->job($job->id);
        $this->assertSame(job_manager::STATUS_FAILED, $failed->status);
        $this->assertSame('network_error', $failed->errorcode);

        $retried = job_manager::retry($assign, $failed, $this->teacher->id);
        $this->assertNotNull($retried);
        $this->assertEquals($failed->id, $retried->id);
        $again = $this->job($failed->id);
        $this->assertSame(job_manager::STATUS_QUEUED, $again->status);
        $this->assertSame($failed->idempotencykey, $again->idempotencykey);

        $client = $this->fake_client()->respond(200, $this->grade_response(), ['idempotent-replayed' => 'true']);
        $this->run_job($failed->id);
        $this->assertSame(job_manager::STATUS_DONE, $this->job($failed->id)->status);
        $this->assertSame($failed->idempotencykey, $client->request_headers(0)['idempotency-key']);
    }

    /**
     * A file Proctor AI cannot read is left out, and the teacher is told which one and why.
     */
    public function test_files_not_sent_are_recorded(): void {
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $this->add_file($assign, 'notes.docx', 'PK fake word document');
        $job = $this->grade_now($assign);

        $this->assertSame(job_manager::STATUS_DONE, $job->status);
        $notsent = json_decode((string) $job->notsent, true);
        $this->assertSame([['name' => 'notes.docx', 'reason' => 'type']], $notsent);
    }

    /**
     * The API key is only sent over https, or plain http to this machine.
     */
    public function test_api_key_never_sent_over_plain_http(): void {
        $this->assertTrue(client::is_secure_url('https://agent.proctorai.io/api/v1'));
        $this->assertTrue(client::is_secure_url('http://localhost:8000/fake'));
        $this->assertFalse(client::is_secure_url('http://agent.proctorai.io/api/v1'));
        $this->assertFalse(client::is_secure_url('ftp://example.com'));

        set_config('apiurl', 'http://api.example.test/api/v1', 'assignfeedback_proctorai');
        $client = $this->fake_client();
        try {
            $client->get_account();
            $this->fail('An http API URL must be refused.');
        } catch (api_exception $e) {
            $this->assertSame('insecure_url', $e->apicode);
        }
        $this->assertSame([], $client->requests);
    }

    /**
     * The privacy export includes what was sent for grading, not only the result.
     */
    public function test_privacy_export_includes_requests(): void {
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $this->add_file($assign, 'notes.docx', 'PK fake word document');
        $this->grade_now($assign);
        $grade = $assign->get_user_grade($this->student->id, false);
        $context = $assign->get_context();

        provider::export_feedback_user_data(new assign_plugin_request_data($context, $assign, $grade, [], $this->student));

        $path = [get_string('privacy:path', 'assignfeedback_proctorai')];
        $related = writer::with_context($context)->get_related_data($path, 'requests');
        $this->assertCount(1, $related->requests);
        $this->assertSame(job_manager::STATUS_DONE, $related->requests[0]->status);
        $this->assertSame('notes.docx', $related->requests[0]->filesnotsent[0]['name']);
    }

    /**
     * With separate groups, a teacher without "access all groups" cannot read another group's results.
     */
    public function test_status_service_respects_groups(): void {
        $assign = $this->create_assign(['groupmode' => SEPARATEGROUPS]);
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $this->student->id]);
        $marker = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $marker->id]);

        $this->setUser($marker);
        $this->expectException(\required_capability_exception::class);
        $this->expectExceptionMessage(get_capability_string('moodle/site:accessallgroups'));
        get_status::execute((int) $assign->get_instance()->id, (int) $this->student->id);
    }
}
