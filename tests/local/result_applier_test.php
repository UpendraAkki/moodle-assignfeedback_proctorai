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

use assignfeedback_proctorai\tests\grading_testcase;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../grading_testcase.php');

/**
 * Tests for teacher-edited marks, the student's per-question table, "Apply Proctor AI marks" and the banner.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignfeedback_proctorai\local\result_applier
 * @covers     \assign_feedback_proctorai
 */
final class result_applier_test extends grading_testcase {
    /**
     * Grade the student's submission with the scripted 7/10 result.
     *
     * @param array $params Extra assignment settings.
     * @return array [assign, grade, plugin]
     */
    private function graded(array $params = []): array {
        $assign = $this->create_assign($params);
        $this->submit_text($assign);
        $job = job_manager::queue($assign, $this->student->id, job_manager::ORIGIN_MANUAL, $this->teacher->id);
        $this->fake_client()->respond(200, $this->grade_response());
        $this->run_job($job->id);
        return [$assign, $assign->get_user_grade($this->student->id, false), $assign->get_feedback_plugin_by_type('proctorai')];
    }

    /**
     * Teacher marks are clamped, move the total by their difference, and update the suggested grade.
     */
    public function test_set_teacher_marks(): void {
        global $DB;
        [$assign, $grade] = $this->graded();
        $row = $DB->get_record('assignfeedback_proctorai', ['grade' => $grade->id]);

        // Question 2 was 3/6 from the AI; the teacher gives 5, and tries 9 on question 1 (max 4).
        $this->assertTrue(result_applier::set_teacher_marks($assign, $row, [0 => 9, 1 => 5]));
        $this->assertEquals(9, $row->earned);
        $this->assertEquals(90, $row->percentage);
        $this->assertEquals(90, $row->suggestedgrade);
        $questions = json_decode($row->resultjson, true)['questions'];
        $this->assertArrayNotHasKey('teacherEarned', $questions[0]);
        $this->assertEquals(5, $questions[1]['teacherEarned']);
        $this->assertEquals(3, $questions[1]['earned']);

        // The same marks again change nothing; going back to the AI mark clears the override.
        $this->assertFalse(result_applier::set_teacher_marks($assign, $row, [0 => 4, 1 => 5]));
        $this->assertTrue(result_applier::set_teacher_marks($assign, $row, [1 => 3]));
        $this->assertArrayNotHasKey('teacherEarned', json_decode($row->resultjson, true)['questions'][1]);
        $this->assertEquals(7, $row->earned);
    }

    /**
     * Saving the grader form with changed marks stores them; students see the table only once approved.
     */
    public function test_grader_marks_and_student_table(): void {
        [$assign, $grade, $plugin] = $this->graded(['markingworkflow' => 0]);

        $this->setUser($this->student);
        $this->assertSame('', $plugin->view($grade));

        $this->setUser($this->teacher);
        $data = (object) [
            'assignfeedbackproctorai_editor' => ['text' => '<p>Good.</p>', 'format' => FORMAT_HTML],
            'assignfeedbackproctorai_marks' => json_encode([4, 5]),
        ];
        $this->assertTrue($plugin->is_feedback_modified($grade, $data));
        $plugin->save($grade, $data);
        $result = $plugin->get_result($grade->id);
        $this->assertEquals(9, $result->earned);
        $this->assertEquals(1, $result->approved);

        // Saved and approved: an unchanged form is not modified; a changed mark is.
        $this->assertFalse($plugin->is_feedback_modified($grade, $data));
        $data->assignfeedbackproctorai_marks = json_encode([4, 6]);
        $this->assertTrue($plugin->is_feedback_modified($grade, $data));

        $this->setUser($this->student);
        $html = $plugin->view($grade);
        $this->assertStringContainsString('Good.', $html);
        $this->assertStringContainsString(get_string('perquestion', 'assignfeedback_proctorai'), $html);
        $this->assertStringContainsString('5 / 6', $html);
    }

    /**
     * "Apply Proctor AI marks" writes the grade to the gradebook and approves it, but never over a teacher's grade.
     */
    public function test_apply_to_grade(): void {
        [$assign, $grade, $plugin] = $this->graded(['markingworkflow' => 0]);
        $this->setUser($this->teacher);
        $sink = $this->redirectMessages();

        $this->assertNull(result_applier::apply_blocker($assign, $this->student->id));
        $this->assertSame('applied', result_applier::apply_to_grade($assign, $this->student->id));
        $sink->close();

        $grade = $assign->get_user_grade($this->student->id, false);
        $this->assertEquals(70.0, (float) $grade->grade);
        $gradebook = grade_get_grades($this->course->id, 'mod', 'assign', $assign->get_instance()->id, $this->student->id);
        $this->assertEquals(70.0, (float) $gradebook->items[0]->grades[$this->student->id]->grade);
        $result = $plugin->get_result($grade->id);
        $this->assertEquals(1, $result->approved);
        $this->assertEquals(70, $result->appliedgrade);

        // Applying again is fine (it is still our grade); after a teacher changes it, it is left alone.
        $this->assertNull(result_applier::apply_blocker($assign, $this->student->id));
        $assign->testable_apply_grade_to_user((object) ['grade' => 55.0], $this->student->id, 0);
        $this->assertSame('graded', result_applier::apply_blocker($assign, $this->student->id));
        $this->assertSame('graded', result_applier::apply_to_grade($assign, $this->student->id));
        $this->assertEquals(55.0, (float) $assign->get_user_grade($this->student->id, false)->grade);

        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->assertSame('noresult', result_applier::apply_blocker($assign, $other->id));
    }

    /**
     * The apply action is offered only without marking workflow, and only to graders.
     */
    public function test_apply_batch_operation_offered_without_workflow(): void {
        $keys = function (\assign $assign): array {
            $plugin = $assign->get_feedback_plugin_by_type('proctorai');
            return array_map(fn($op) => $op->key, $plugin->get_grading_batch_operation_details());
        };
        $this->setUser($this->teacher);
        $this->assertSame(['grade', 'apply'], $keys($this->create_assign(['markingworkflow' => 0])));
        $this->assertSame(['grade'], $keys($this->create_assign(['markingworkflow' => 1])));
    }

    /**
     * Graders see a banner on the assignment page when the balance is low or gone; students never do.
     */
    public function test_low_balance_banner(): void {
        set_config('lowbalance', 10, 'assignfeedback_proctorai');
        $assign = $this->create_assign();
        $plugin = $assign->get_feedback_plugin_by_type('proctorai');
        $this->setUser($this->teacher);

        $this->assertSame('', $plugin->view_header());

        account::record_balance(4.0, 1.0);
        $this->assertStringContainsString(get_string('bannerlowbalance', 'assignfeedback_proctorai', 4), $plugin->view_header());

        account::record_balance(0.0, 1.0);
        $this->assertStringContainsString(get_string('bannernobalance', 'assignfeedback_proctorai'), $plugin->view_header());

        account::record_balance(50.0, 1.0);
        $this->assertSame('', $plugin->view_header());

        set_config('paused', time(), 'assignfeedback_proctorai');
        $this->assertStringContainsString(get_string('paused', 'assignfeedback_proctorai'), $plugin->view_header());

        $this->setUser($this->student);
        $this->assertSame('', $plugin->view_header());
    }
}
