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

use assignfeedback_proctorai\admin\setting_status;
use assignfeedback_proctorai\task\grade_submission;
use assignfeedback_proctorai\tests\grading_testcase;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../grading_testcase.php');

/**
 * Tests for the 0.2.0 job handling: pauses, batch notices, team submissions, inline uploads,
 * the status page actions and the balance cache.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignfeedback_proctorai\local\job_manager
 * @covers     \assignfeedback_proctorai\local\account
 */
final class job_manager_test extends grading_testcase {
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
     * Messages of one provider from a sink.
     *
     * @param \phpunit_message_sink $sink The sink.
     * @param string $provider The provider name.
     * @return array
     */
    private function messages(\phpunit_message_sink $sink, string $provider): array {
        return array_values(array_filter($sink->get_messages(), fn($m) => $m->eventtype === $provider));
    }

    /**
     * A submission made while the plugin is paused waits for the resume instead of being dropped.
     */
    public function test_submission_during_pause_is_queued_on_resume(): void {
        global $DB;
        $assign = $this->create_assign(['submissiondrafts' => 1, 'assignfeedback_proctorai_autograde' => 1]);
        set_config('paused', time(), 'assignfeedback_proctorai');
        $before = count(\core\task\manager::get_adhoc_tasks(grade_submission::class));

        $this->submit_text($assign);

        $jobs = array_values($DB->get_records('assignfeedback_proctorai_job', ['assignment' => $assign->get_instance()->id]));
        $this->assertCount(1, $jobs);
        $this->assertSame(job_manager::STATUS_FAILED, $jobs[0]->status);
        $this->assertSame('paused', $jobs[0]->errorcode);
        $this->assertCount($before, \core\task\manager::get_adhoc_tasks(grade_submission::class));

        account::resume();

        $this->assertSame(job_manager::STATUS_QUEUED, $this->job($jobs[0]->id)->status);
        $this->assertCount($before + 1, \core\task\manager::get_adhoc_tasks(grade_submission::class));
    }

    /**
     * A batch that hits a 401 sends one "finished" notice, after the resume, with the real outcome.
     */
    public function test_batch_notice_sent_once_across_a_pause(): void {
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $batch = job_manager::queue_batch($assign, [$this->student->id], $this->teacher->id);
        $this->assertSame(1, $batch['queued']);
        $job = job_manager::latest_job($assign->get_instance()->id, $this->student->id);
        $client = $this->fake_client()
            ->respond(401, ['error' => ['code' => 'invalid_api_key', 'message' => 'Revoked']])
            ->respond(200, $this->grade_response());
        $sink = $this->redirectMessages();

        $this->run_job($job->id);
        $this->assertCount(1, $this->messages($sink, 'paused'));
        $this->assertCount(0, $this->messages($sink, 'batchcomplete'));

        account::resume();
        $this->run_job($job->id);
        $this->assertSame(job_manager::STATUS_DONE, $this->job($job->id)->status);
        $notices = $this->messages($sink, 'batchcomplete');
        $this->assertCount(1, $notices);
        $this->assertEquals($this->teacher->id, $notices[0]->useridto);
        $this->assertStringContainsString('1 graded, 0 not graded', $notices[0]->fullmessage);

        // Running it again changes nothing and sends nothing.
        $this->run_job($job->id);
        $this->assertCount(1, $this->messages($sink, 'batchcomplete'));
        $this->assertEquals(1, $this->job($job->id)->batchnotified);
        $this->assertCount(2, $client->requests);
        $sink->close();
    }

    /**
     * Team submissions are graded once per group and the result goes to every member.
     */
    public function test_team_submission_graded_once_for_every_member(): void {
        global $DB;
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $this->student->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $other->id]);
        $assign = $this->create_assign([
            'teamsubmission' => 1,
            'requireallteammemberssubmit' => 0,
            'preventsubmissionnotingroup' => 0,
            'markingworkflow' => 1,
        ]);
        $this->submit_text($assign);
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $assign->get_instance()->id,
            'userid' => 0,
            'groupid' => $group->id,
        ], '*', MUST_EXIST);
        $this->assertSame(ASSIGN_SUBMISSION_STATUS_SUBMITTED, $submission->status);

        $sorted = job_manager::eligible($assign, [$this->student->id, $other->id]);
        $this->assertSame([(int) $this->student->id], $sorted['eligible']);
        $this->assertSame(['samegroup' => 1], $sorted['skipped']);

        $batch = job_manager::queue_batch($assign, [$this->student->id, $other->id], $this->teacher->id);
        $this->assertSame(1, $batch['queued']);
        $client = $this->fake_client()->respond(200, $this->grade_response());
        $job = job_manager::latest_job_for($assign, (int) $other->id);
        $this->assertNotNull($job);
        $sink = $this->redirectMessages();
        $this->run_job($job->id);
        $sink->close();

        $this->assertSame(job_manager::STATUS_DONE, $this->job($job->id)->status);
        $this->assertCount(1, $client->requests);
        // The group's submission is untouched: still submitted, still attempt 0.
        $after = $DB->get_record('assign_submission', ['id' => $submission->id], '*', MUST_EXIST);
        $this->assertSame(ASSIGN_SUBMISSION_STATUS_SUBMITTED, $after->status);
        $this->assertEquals(0, $after->attemptnumber);
        foreach ([$this->student, $other] as $member) {
            $grade = $DB->get_record('assign_grades', [
                'assignment' => $assign->get_instance()->id,
                'userid' => $member->id,
                'attemptnumber' => 0,
            ], '*', MUST_EXIST);
            $this->assertEquals(70.0, (float) $grade->grade, 'Grade for member ' . $member->id);
            $this->assertTrue($DB->record_exists('assignfeedback_proctorai', ['grade' => $grade->id]));
            $flags = $assign->get_user_flags($member->id, false);
            $this->assertSame(ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW, $flags->workflowstate);
        }
    }

    /**
     * The inline upload method sends images as data URLs in the grade request, without /uploads.
     */
    public function test_inline_upload_method(): void {
        set_config('uploadmethod', 'inline', 'assignfeedback_proctorai');
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $this->add_file($assign, 'page1.jpg');
        $job = job_manager::queue($assign, $this->student->id, job_manager::ORIGIN_MANUAL, $this->teacher->id);
        $client = $this->fake_client()->respond(200, $this->grade_response());

        $this->run_job($job->id);

        $this->assertSame(job_manager::STATUS_DONE, $this->job($job->id)->status);
        $this->assertCount(1, $client->requests);
        $this->assertStringEndsWith('/grade', $client->requests[0]['url']);
        $body = json_decode($client->requests[0]['payload'], true);
        $this->assertArrayNotHasKey('files', $body);
        $this->assertCount(1, $body['pages']);
        $this->assertSame('data:image/jpeg;base64,' . base64_encode("\xFF\xD8\xFF\xE0 fake jpeg bytes"), $body['pages'][0]);
    }

    /**
     * The inline method refuses PDFs and submissions over 4 MB, with a message for the teacher.
     *
     * @dataProvider inline_refusals
     * @param string $filename The file name.
     * @param int $size Bytes of content.
     * @param string $code The expected error code.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('inline_refusals')]
    public function test_inline_upload_refusals(string $filename, int $size, string $code): void {
        set_config('uploadmethod', 'inline', 'assignfeedback_proctorai');
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $this->add_file($assign, $filename, str_repeat('x', $size));
        $job = job_manager::queue($assign, $this->student->id, job_manager::ORIGIN_MANUAL, $this->teacher->id);
        $client = $this->fake_client();

        $this->run_job($job->id);

        $job = $this->job($job->id);
        $this->assertSame(job_manager::STATUS_FAILED, $job->status);
        $this->assertSame($code, $job->errorcode);
        $this->assertNotEmpty($job->errormessage);
        $this->assertCount(0, $client->requests);
    }

    /**
     * Files the inline method cannot send.
     *
     * @return array
     */
    public static function inline_refusals(): array {
        return [
            'pdf' => ['scan.pdf', 1000, 'inlinepdf'],
            'too large' => ['big.jpg', (int) (3.2 * 1024 * 1024), 'inlinetoolarge'],
        ];
    }

    /**
     * Cancel stops a job that has not started, and only such a job.
     */
    public function test_cancel(): void {
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $job = job_manager::queue($assign, $this->student->id, job_manager::ORIGIN_MANUAL, $this->teacher->id);
        $this->setUser($this->teacher);

        $this->assertTrue(job_manager::cancel($this->job($job->id)));
        $this->assertSame(job_manager::STATUS_CANCELLED, $this->job($job->id)->status);

        $started = job_manager::queue($assign, $this->student->id, job_manager::ORIGIN_MANUAL, $this->teacher->id);
        global $DB;
        $record = $this->job($started->id);
        $record->status = job_manager::STATUS_GRADING;
        $record->timestarted = time();
        $DB->update_record('assignfeedback_proctorai_job', $record);
        $this->assertFalse(job_manager::cancel($record));
        $this->assertSame(job_manager::STATUS_GRADING, $this->job($started->id)->status);
    }

    /**
     * Retry queues a stopped job's student again as a new job; "retry all" does it for every nobalance job.
     */
    public function test_retry_and_retry_nobalance(): void {
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $job = job_manager::queue($assign, $this->student->id, job_manager::ORIGIN_MANUAL, $this->teacher->id);
        $this->fake_client()->respond(422, ['error' => ['code' => 'too_many_pages', 'message' => 'Too many']]);
        $this->run_job($job->id);
        $failed = $this->job($job->id);
        $this->assertSame(job_manager::STATUS_FAILED, $failed->status);

        $retried = job_manager::retry($assign, $failed, $this->teacher->id);
        $this->assertNotNull($retried);
        $this->assertNotEquals($failed->id, $retried->id);
        $this->assertSame(job_manager::STATUS_QUEUED, $this->job($retried->id)->status);
        $this->assertNull(job_manager::retry($assign, $this->job($retried->id), $this->teacher->id));

        // Run it out of balance, then retry all such jobs.
        \cache::make('assignfeedback_proctorai', 'account')->set(
            'account',
            ['balance' => 0, 'pricePerSheet' => 1, 'sheetsRemaining' => 0, 'fetchedat' => time()]
        );
        $sink = $this->redirectMessages();
        $this->run_job($retried->id);
        $sink->close();
        $this->assertSame(job_manager::STATUS_NOBALANCE, $this->job($retried->id)->status);

        $result = job_manager::retry_nobalance($assign, $this->teacher->id);
        $this->assertSame(1, $result['queued']);
        $latest = job_manager::latest_job($assign->get_instance()->id, $this->student->id);
        $this->assertSame(job_manager::STATUS_QUEUED, $latest->status);
        $this->assertNotEquals($retried->id, $latest->id);
    }

    /**
     * A zero balance from a 402 is trusted while fresh, then checked again so a top-up resumes grading.
     */
    public function test_out_of_balance_rechecked_when_stale(): void {
        account::record_balance(0.0, 1.0);
        $client = $this->fake_client()->respond(200, ['balance' => 5, 'pricePerSheet' => 1, 'sheetsRemaining' => 5]);

        $this->assertTrue(account::known_out_of_balance());
        $this->assertCount(0, $client->requests);

        $cache = \cache::make('assignfeedback_proctorai', 'account');
        $stale = $cache->get('account');
        $stale['fetchedat'] = time() - account::CACHE_TTL - 1;
        $cache->set('account', $stale);

        $this->assertFalse(account::known_out_of_balance());
        $this->assertCount(1, $client->requests);
        $this->assertSame(5, account::get(false)['sheetsRemaining']);
    }

    /**
     * The settings page and the status page show a freshly fetched balance, not the cached one.
     */
    public function test_balance_refreshed_for_display(): void {
        account::record_balance(1.0, 1.0);
        $this->fake_client()
            ->respond(200, ['balance' => 9, 'pricePerSheet' => 1, 'sheetsRemaining' => 9])
            ->respond(200, ['balance' => 8, 'pricePerSheet' => 1, 'sheetsRemaining' => 8]);

        $html = (new setting_status('assignfeedback_proctorai/statusheading', 'Connection', ''))->output_html(null);
        $this->assertStringContainsString('9', $html);
        $this->assertSame(9, account::get(false)['sheetsRemaining']);

        $account = account::refresh_for_display();
        $this->assertSame(8, $account['sheetsRemaining']);
    }
}
