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

use core_privacy\local\metadata\collection;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_assign\privacy\assign_plugin_request_data;
use mod_assign\privacy\useridlist;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Privacy provider for the Proctor AI feedback plugin.
 *
 * Student work is sent to Proctor AI to be graded, so an external location is declared.
 * The results are stored per grade, and mod_assign drives export and deletion through the
 * assignfeedback provider interfaces.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \mod_assign\privacy\assignfeedback_provider,
        \mod_assign\privacy\assignfeedback_user_provider {

    /**
     * Describe the data this plugin stores and sends.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('assignfeedback_proctorai', [
            'assignment' => 'privacy:metadata:assignmentid',
            'grade' => 'privacy:metadata:grade',
            'earned' => 'privacy:metadata:earned',
            'maxmarks' => 'privacy:metadata:maxmarks',
            'suggestedgrade' => 'privacy:metadata:suggestedgrade',
            'resultjson' => 'privacy:metadata:resultjson',
            'feedbacktext' => 'privacy:metadata:feedbacktext',
            'approvedby' => 'privacy:metadata:approvedby',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:resulttable');

        $collection->add_database_table('assignfeedback_proctorai_job', [
            'assignment' => 'privacy:metadata:assignmentid',
            'userid' => 'privacy:metadata:jobuserid',
            'submission' => 'privacy:metadata:submission',
            'requestedby' => 'privacy:metadata:requestedby',
            'requestjson' => 'privacy:metadata:requestjson',
            'status' => 'privacy:metadata:status',
            'errormessage' => 'privacy:metadata:errormessage',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:jobtable');

        $collection->add_external_location_link('proctorai', [
            'submissionfiles' => 'privacy:metadata:proctorai:submissionfiles',
            'onlinetext' => 'privacy:metadata:proctorai:onlinetext',
            'assignmentname' => 'privacy:metadata:proctorai:assignmentname',
            'markingkey' => 'privacy:metadata:proctorai:markingkey',
        ], 'privacy:metadata:proctorai');

        return $collection;
    }

    /**
     * Add contexts where this user queued jobs or approved results (as a teacher).
     *
     * Student contexts come from mod_assign's own grade and submission tables.
     *
     * @param int $userid The user.
     * @param contextlist $contextlist The list to add to.
     */
    public static function get_context_for_userid_within_feedback(int $userid, contextlist $contextlist) {
        $sql = "SELECT ctx.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                 WHERE cm.instance IN (
                           SELECT j.assignment FROM {assignfeedback_proctorai_job} j WHERE j.requestedby = :requestedby
                           UNION
                           SELECT r.assignment FROM {assignfeedback_proctorai} r WHERE r.approvedby = :approvedby
                       )";
        $contextlist->add_from_sql($sql, [
            'modname' => 'assign',
            'contextlevel' => CONTEXT_MODULE,
            'requestedby' => $userid,
            'approvedby' => $userid,
        ]);
    }

    /**
     * Add the students whose results this teacher approved or whose grading this teacher requested.
     *
     * @param useridlist $useridlist The list to add to.
     */
    public static function get_student_user_ids(useridlist $useridlist) {
        $params = [
            'assignid' => $useridlist->get_assignid(),
            'teacherid' => $useridlist->get_teacherid(),
            'assignid2' => $useridlist->get_assignid(),
            'teacherid2' => $useridlist->get_teacherid(),
        ];
        $sql = "SELECT g.userid AS id
                  FROM {assignfeedback_proctorai} r
                  JOIN {assign_grades} g ON g.id = r.grade
                 WHERE r.assignment = :assignid AND r.approvedby = :teacherid
                 UNION
                SELECT j.userid AS id
                  FROM {assignfeedback_proctorai_job} j
                 WHERE j.assignment = :assignid2 AND j.requestedby = :teacherid2";
        $useridlist->add_from_sql($sql, $params);
    }

    /**
     * Add the teachers who requested or approved gradings in this context.
     *
     * @param userlist $userlist The list to add to.
     */
    public static function get_userids_from_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('assign', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $userlist->add_from_sql('requestedby',
            'SELECT requestedby FROM {assignfeedback_proctorai_job} WHERE assignment = :assignment AND requestedby > 0',
            ['assignment' => $cm->instance]);
        $userlist->add_from_sql('userid',
            'SELECT userid FROM {assignfeedback_proctorai_job} WHERE assignment = :assignment',
            ['assignment' => $cm->instance]);
        $userlist->add_from_sql('approvedby',
            'SELECT approvedby FROM {assignfeedback_proctorai} WHERE assignment = :assignment AND approvedby IS NOT NULL',
            ['assignment' => $cm->instance]);
    }

    /**
     * Export the AI result for one grade.
     *
     * @param assign_plugin_request_data $exportdata The grade to export.
     */
    public static function export_feedback_user_data(assign_plugin_request_data $exportdata) {
        global $DB;
        $grade = $exportdata->get_pluginobject();
        if (!$grade) {
            return;
        }
        $row = $DB->get_record('assignfeedback_proctorai', ['grade' => $grade->id]);
        if (!$row) {
            return;
        }
        $context = $exportdata->get_context();
        $result = json_decode((string) $row->resultjson, true) ?: [];
        $data = (object) [
            'earned' => $row->earned,
            'maxmarks' => $row->maxmarks,
            'percentage' => $row->percentage,
            'suggestedgrade' => $row->suggestedgrade,
            'needsreview' => transform::yesno($row->needsreview),
            'questions' => $result['questions'] ?? [],
            'feedbacktext' => format_text((string) $row->feedbacktext, $row->feedbackformat, ['context' => $context]),
            'approved' => transform::yesno($row->approved),
            'timemodified' => transform::datetime($row->timemodified),
        ];
        $path = array_merge($exportdata->get_subcontext(), [get_string('privacy:path', 'assignfeedback_proctorai')]);
        writer::with_context($context)->export_data($path, $data);
    }

    /**
     * Delete all results and jobs for an assignment.
     *
     * @param assign_plugin_request_data $requestdata The context to delete for.
     */
    public static function delete_feedback_for_context(assign_plugin_request_data $requestdata) {
        global $DB;
        $assignid = $requestdata->get_assignid();
        $DB->delete_records('assignfeedback_proctorai', ['assignment' => $assignid]);
        $DB->delete_records('assignfeedback_proctorai_job', ['assignment' => $assignid]);
    }

    /**
     * Delete the result for one grade, and that student's jobs in this assignment.
     *
     * @param assign_plugin_request_data $requestdata The grade to delete for.
     */
    public static function delete_feedback_for_grade(assign_plugin_request_data $requestdata) {
        global $DB;
        $grade = $requestdata->get_pluginobject();
        if (!$grade) {
            return;
        }
        $assignid = $requestdata->get_assignid();
        $DB->delete_records('assignfeedback_proctorai', ['assignment' => $assignid, 'grade' => $grade->id]);
        $DB->delete_records('assignfeedback_proctorai_job', ['assignment' => $assignid, 'userid' => $grade->userid]);
    }

    /**
     * Delete the results for several grades, and those students' jobs.
     *
     * @param assign_plugin_request_data $deletedata The grades to delete for.
     */
    public static function delete_feedback_for_grades(assign_plugin_request_data $deletedata) {
        global $DB;
        $assignid = $deletedata->get_assignid();
        if ($gradeids = $deletedata->get_gradeids()) {
            [$sql, $params] = $DB->get_in_or_equal($gradeids, SQL_PARAMS_NAMED);
            $params['assignment'] = $assignid;
            $DB->delete_records_select('assignfeedback_proctorai', "assignment = :assignment AND grade $sql", $params);
        }
        if ($userids = $deletedata->get_userids()) {
            [$sql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $params['assignment'] = $assignid;
            $DB->delete_records_select('assignfeedback_proctorai_job', "assignment = :assignment AND userid $sql", $params);
        }
    }
}
