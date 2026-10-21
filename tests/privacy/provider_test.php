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

namespace assignfeedback_proctorai\privacy;

use assignfeedback_proctorai\local\job_manager;
use assignfeedback_proctorai\tests\grading_testcase;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\writer;
use mod_assign\privacy\assign_plugin_request_data;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../grading_testcase.php');

/**
 * Tests for the privacy provider.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignfeedback_proctorai\privacy\provider
 */
final class provider_test extends grading_testcase {
    /**
     * Grade the student's submission with a scripted result.
     *
     * @return array [assign, grade]
     */
    private function graded(): array {
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $job = job_manager::queue($assign, $this->student->id, job_manager::ORIGIN_MANUAL, $this->teacher->id);
        $this->fake_client()->respond(200, $this->grade_response());
        $this->run_job($job->id);
        return [$assign, $assign->get_user_grade($this->student->id, false)];
    }

    /**
     * Both tables and the Proctor AI external location are declared.
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('assignfeedback_proctorai'));
        $names = array_map(fn($item) => $item->get_name(), $collection->get_collection());
        $this->assertContains('assignfeedback_proctorai', $names);
        $this->assertContains('assignfeedback_proctorai_job', $names);
        $this->assertContains('proctorai', $names);
        foreach ($collection->get_collection() as $item) {
            if ($item->get_name() === 'proctorai') {
                $this->assertInstanceOf(\core_privacy\local\metadata\types\external_location::class, $item);
                $this->assertArrayHasKey('markingkey', $item->get_privacy_fields());
                $this->assertArrayHasKey('submissionfiles', $item->get_privacy_fields());
            }
        }
    }

    /**
     * The result is exported under the grade's subcontext.
     */
    public function test_export_feedback_user_data(): void {
        [$assign, $grade] = $this->graded();
        $context = $assign->get_context();

        provider::export_feedback_user_data(new assign_plugin_request_data($context, $assign, $grade, [], $this->student));

        $data = writer::with_context($context)->get_data([get_string('privacy:path', 'assignfeedback_proctorai')]);
        $this->assertEquals(7, $data->earned);
        $this->assertCount(2, $data->questions);
        $this->assertStringContainsString('A good start.', $data->feedbacktext);
    }

    /**
     * The teacher who requested grading is found, and the student is found through the teacher.
     */
    public function test_find_users(): void {
        global $DB;
        [$assign] = $this->graded();
        $context = $assign->get_context();

        $contextlist = new \core_privacy\local\request\contextlist();
        provider::get_context_for_userid_within_feedback($this->teacher->id, $contextlist);
        $this->assertContains((int) $context->id, array_map('intval', $contextlist->get_contextids()));

        $useridlist = new \mod_assign\privacy\useridlist($this->teacher->id, $assign->get_instance()->id);
        provider::get_student_user_ids($useridlist);
        $this->assertContains((int) $this->student->id, array_map(fn($u) => (int) $u->id, $useridlist->get_userids()));

        $userlist = new \core_privacy\local\request\userlist($context, 'assignfeedback_proctorai');
        provider::get_userids_from_context($userlist);
        $this->assertEqualsCanonicalizing([(int) $this->student->id, (int) $this->teacher->id],
            array_map('intval', $userlist->get_userids()));
        $this->assertTrue($DB->record_exists('assignfeedback_proctorai_job', ['userid' => $this->student->id]));
    }

    /**
     * Deleting for a grade removes the result and that student's jobs.
     */
    public function test_delete_feedback_for_grade(): void {
        global $DB;
        [$assign, $grade] = $this->graded();

        provider::delete_feedback_for_grade(new assign_plugin_request_data($assign->get_context(), $assign, $grade));

        $this->assertFalse($DB->record_exists('assignfeedback_proctorai', ['grade' => $grade->id]));
        $this->assertFalse($DB->record_exists('assignfeedback_proctorai_job', ['userid' => $this->student->id]));
    }

    /**
     * Deleting for several grades, and for the whole context.
     */
    public function test_delete_feedback_for_grades_and_context(): void {
        global $DB;
        [$assign, $grade] = $this->graded();
        $context = $assign->get_context();

        $request = new assign_plugin_request_data($context, $assign);
        $request->set_userids([$this->student->id]);
        $request->populate_submissions_and_grades();
        provider::delete_feedback_for_grades($request);
        $this->assertFalse($DB->record_exists('assignfeedback_proctorai', ['grade' => $grade->id]));
        $this->assertFalse($DB->record_exists('assignfeedback_proctorai_job', ['userid' => $this->student->id]));

        [$assign2] = $this->graded();
        provider::delete_feedback_for_context(new assign_plugin_request_data($assign2->get_context(), $assign2));
        $this->assertFalse($DB->record_exists('assignfeedback_proctorai', ['assignment' => $assign2->get_instance()->id]));
        $this->assertFalse($DB->record_exists('assignfeedback_proctorai_job', ['assignment' => $assign2->get_instance()->id]));
    }
}
