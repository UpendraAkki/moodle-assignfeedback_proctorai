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

use assignfeedback_proctorai\tests\grading_testcase;
use core_external\external_api;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/grading_testcase.php');
require_once($CFG->dirroot . '/mod/assign/externallib.php');

/**
 * Tests for the feedback plugin class.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assign_feedback_proctorai
 */
final class locallib_test extends grading_testcase {
    /**
     * Students cannot read the marking key through the web service.
     */
    public function test_marking_key_not_exposed_to_students(): void {
        $assign = $this->create_assign();
        $plugin = $assign->get_feedback_plugin_by_type('proctorai');
        $this->assertSame([], $plugin->get_config_for_external());

        $this->setUser($this->student);
        $result = \mod_assign_external::get_assignments([$this->course->id]);
        $result = external_api::clean_returnvalue(\mod_assign_external::get_assignments_returns(), $result);
        $configs = $result['courses'][0]['assignments'][0]['configs'];
        foreach ($configs as $config) {
            $this->assertNotSame('proctorai', $config['plugin']);
            $this->assertStringNotContainsString('Paris', (string) $config['value']);
        }
    }

    /**
     * A key with an error is saved with the error, and fixing it clears the error.
     */
    public function test_save_settings_records_key_error(): void {
        $assign = $this->create_assign(['assignfeedback_proctorai_markingkey' => "Intro\n1. Q [1]"]);
        $plugin = $assign->get_feedback_plugin_by_type('proctorai');
        $this->assertStringContainsString('Line 1', $plugin->get_config('markingkeyerror'));

        $plugin->save_settings((object) ['assignfeedback_proctorai_markingkey' => '1. Q [1]']);
        $this->assertSame('', $plugin->get_config('markingkeyerror'));
    }

    /**
     * The batch operation and grading actions appear only for teachers who may request grading.
     */
    public function test_batch_operation_needs_capability(): void {
        $assign = $this->create_assign();
        $plugin = $assign->get_feedback_plugin_by_type('proctorai');

        $this->setUser($this->teacher);
        $operations = $plugin->get_grading_batch_operation_details();
        $this->assertCount(1, $operations);
        $this->assertSame('grade', $operations[0]->key);
        $this->assertArrayHasKey('gradeall', $plugin->get_grading_actions());

        $nonediting = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->setUser($nonediting);
        $this->assertSame([], $plugin->get_grading_batch_operation_details());
        $this->assertArrayNotHasKey('gradeall', $plugin->get_grading_actions());
        $this->assertArrayHasKey('status', $plugin->get_grading_actions());
    }

    /**
     * The status web service reports the job and the result.
     */
    public function test_get_status(): void {
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $this->setUser($this->teacher);
        $status = external\get_status::execute($assign->get_instance()->id, $this->student->id);
        $this->assertSame('none', $status['status']);

        $job = local\job_manager::queue($assign, $this->student->id, local\job_manager::ORIGIN_MANUAL, $this->teacher->id);
        $this->fake_client()->respond(200, $this->grade_response());
        $this->run_job($job->id);

        $status = external\get_status::execute($assign->get_instance()->id, $this->student->id);
        $status = external_api::clean_returnvalue(external\get_status::execute_returns(), $status);
        $this->assertSame('done', $status['status']);
        $this->assertTrue($status['hasresult']);
        $this->assertEquals(7, $status['earned']);
        $this->assertEquals(70, $status['suggestedgrade']);
        $this->assertSame(1, $status['reviewcount']);

        $this->setUser($this->student);
        $this->expectException(\required_capability_exception::class);
        external\get_status::execute($assign->get_instance()->id, $this->student->id);
    }

    /**
     * The cost confirmation page renders inside the assignment's layout.
     */
    public function test_confirm_page_renders(): void {
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $this->fake_client()->respond(200, ['balance' => 5, 'pricePerSheet' => 1, 'sheetsRemaining' => 5]);
        $plugin = $this->setup_page_as_teacher($assign);

        $html = $plugin->grading_batch_operation('grade', [$this->student->id]);

        $this->assertStringContainsString(get_string('confirmtitle', 'assignfeedback_proctorai'), $html);
        $this->assertStringContainsString(get_string('confirmbutton', 'assignfeedback_proctorai', 1), $html);
    }

    /**
     * The status page renders inside the assignment's layout.
     */
    public function test_status_page_renders(): void {
        $assign = $this->create_assign();
        $plugin = $this->setup_page_as_teacher($assign);

        $html = $plugin->view_page('status');

        $this->assertStringContainsString(get_string('statuspage', 'assignfeedback_proctorai'), $html);
    }

    /**
     * Log in as the teacher and set up $PAGE as mod/assign/view.php does.
     *
     * @param \assign $assign The assignment.
     * @return \assign_feedback_proctorai
     */
    protected function setup_page_as_teacher(\assign $assign): \assign_feedback_proctorai {
        global $PAGE;
        $this->setUser($this->teacher);
        $PAGE->set_url(new \moodle_url('/mod/assign/view.php', ['id' => $assign->get_course_module()->id]));
        $PAGE->set_context($assign->get_context());
        $PAGE->set_cm($assign->get_course_module());
        return $assign->get_feedback_plugin_by_type('proctorai');
    }
}
