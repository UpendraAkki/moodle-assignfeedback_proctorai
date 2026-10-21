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

namespace assignfeedback_proctorai;

use assignfeedback_proctorai\local\job_manager;
use assignfeedback_proctorai\task\grade_submission;
use assignfeedback_proctorai\tests\grading_testcase;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/grading_testcase.php');

/**
 * Tests for automatic grading on submission.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignfeedback_proctorai\observer
 */
final class observer_test extends grading_testcase {
    /**
     * Jobs for this assignment.
     *
     * @param \assign $assign The assignment.
     * @return array
     */
    private function jobs(\assign $assign): array {
        global $DB;
        return array_values($DB->get_records('assignfeedback_proctorai_job', ['assignment' => $assign->get_instance()->id]));
    }

    /**
     * With drafts off, uploads are debounced: several saves make one job that waits 10 minutes after the last.
     */
    public function test_uploads_debounced_without_drafts(): void {
        $assign = $this->create_assign(['submissiondrafts' => 0, 'assignfeedback_proctorai_autograde' => 1]);

        $this->submit_text($assign, 'First page');
        $jobs = $this->jobs($assign);
        $this->assertCount(1, $jobs);
        $this->assertSame(job_manager::ORIGIN_AUTO, $jobs[0]->origin);
        $this->assertGreaterThanOrEqual(time() + job_manager::DEBOUNCE - 5, (int) $jobs[0]->timeready);
        $firsthash = $jobs[0]->submissionhash;

        $this->submit_text($assign, 'First page and second page');
        $jobs = $this->jobs($assign);
        $this->assertCount(1, $jobs);
        $this->assertNotSame($firsthash, $jobs[0]->submissionhash);

        // Running before the quiet period ends only reschedules.
        $client = $this->fake_client();
        $before = count(\core\task\manager::get_adhoc_tasks(grade_submission::class));
        $this->run_job($jobs[0]->id);
        $this->assertCount(0, $client->requests);
        $this->assertSame(job_manager::STATUS_QUEUED, $this->jobs($assign)[0]->status);
        $this->assertCount($before + 1, \core\task\manager::get_adhoc_tasks(grade_submission::class));
    }

    /**
     * With drafts on, grading is queued at once when the student submits, not on each save.
     */
    public function test_submit_queues_with_drafts(): void {
        $assign = $this->create_assign(['submissiondrafts' => 1, 'assignfeedback_proctorai_autograde' => 1]);

        $sink = $this->redirectMessages();
        $this->add_submission($this->student, $assign, 'Draft');
        $this->assertCount(0, $this->jobs($assign));

        $this->submit_for_grading($this->student, $assign);
        $sink->close();
        $jobs = $this->jobs($assign);
        $this->assertCount(1, $jobs);
        $this->assertLessThanOrEqual(time(), (int) $jobs[0]->timeready);
    }

    /**
     * Nothing is queued when automatic grading is off for the assignment, or the admin has not consented.
     */
    public function test_nothing_queued_when_off(): void {
        $assign = $this->create_assign(['assignfeedback_proctorai_autograde' => 0]);
        $this->submit_text($assign);
        $this->assertCount(0, $this->jobs($assign));

        set_config('consent', 0, 'assignfeedback_proctorai');
        $assign2 = $this->create_assign(['assignfeedback_proctorai_autograde' => 1]);
        $this->submit_text($assign2);
        $this->assertCount(0, $this->jobs($assign2));
    }

    /**
     * The per-student cap stops repeated automatic gradings.
     */
    public function test_autograde_cap(): void {
        global $DB;
        set_config('autogradecap', 1, 'assignfeedback_proctorai');
        $assign = $this->create_assign(['submissiondrafts' => 0, 'assignfeedback_proctorai_autograde' => 1]);
        $this->submit_text($assign, 'One');
        $job = $this->jobs($assign)[0];
        // Pretend it ran.
        $DB->set_field('assignfeedback_proctorai_job', 'status', job_manager::STATUS_DONE, ['id' => $job->id]);

        $this->submit_text($assign, 'Two');
        $this->assertCount(1, $this->jobs($assign));
    }
}
