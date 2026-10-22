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

namespace assignfeedback_proctorai\backup;

use assignfeedback_proctorai\local\job_manager;
use assignfeedback_proctorai\tests\grading_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../grading_testcase.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Backup and restore of Proctor AI results.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_assignfeedback_proctorai_subplugin
 * @covers     \restore_assignfeedback_proctorai_subplugin
 */
final class backup_restore_test extends grading_testcase {
    /**
     * Results and the marking key are restored with grade and user mappings; jobs are not.
     */
    public function test_backup_restore(): void {
        global $CFG, $DB, $USER;

        $assign = $this->create_assign();
        $this->submit_text($assign);
        $job = job_manager::queue($assign, $this->student->id, job_manager::ORIGIN_MANUAL, $this->teacher->id);
        $this->fake_client()->respond(200, $this->grade_response());
        $this->run_job($job->id);

        // The teacher approves the AI feedback.
        $grade = $assign->get_user_grade($this->student->id, false);
        $this->setUser($this->teacher);
        $assign->get_feedback_plugin_by_type('proctorai')->save($grade, (object) [
            'assignfeedbackproctorai_editor' => ['text' => '<p>Approved text</p>', 'format' => FORMAT_HTML],
        ]);

        $this->setAdminUser();
        $CFG->keeptempdirectoriesonbackup = 1;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $this->course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->execute_plan();
        $backupid = $bc->get_backupid();
        $bc->destroy();

        $newcourseid = \restore_dbops::create_new_course('Restored', 'RESTORED', $this->course->category);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $newassignid = $DB->get_field('assign', 'id', ['course' => $newcourseid], MUST_EXIST);
        $newgrade = $DB->get_record(
            'assign_grades',
            ['assignment' => $newassignid, 'userid' => $this->student->id],
            '*',
            MUST_EXIST
        );
        $row = $DB->get_record('assignfeedback_proctorai', ['grade' => $newgrade->id], '*', MUST_EXIST);
        $this->assertEquals($newassignid, $row->assignment);
        $this->assertEquals(7, $row->earned);
        $this->assertEquals(1, $row->approved);
        $this->assertEquals($this->teacher->id, $row->approvedby);
        $this->assertStringContainsString('Approved text', $row->feedbacktext);
        $this->assertNull($row->jobid);

        $this->assertFalse($DB->record_exists('assignfeedback_proctorai_job', ['assignment' => $newassignid]));
        $this->assertSame(self::KEY, $DB->get_field('assign_plugin_config', 'value', [
            'assignment' => $newassignid, 'plugin' => 'proctorai', 'subtype' => 'assignfeedback', 'name' => 'markingkey',
        ]));
    }
}
