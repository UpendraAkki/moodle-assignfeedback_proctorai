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

namespace assignfeedback_proctorai\external;

use assignfeedback_proctorai\local\job_manager;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Grading status and result summary for one student, polled by the grader panel.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_status extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assignid' => new external_value(PARAM_INT, 'The assign instance id'),
            'userid' => new external_value(PARAM_INT, 'The student'),
        ]);
    }

    /**
     * Get the status.
     *
     * @param int $assignid The assign instance id.
     * @param int $userid The student.
     * @return array
     */
    public static function execute(int $assignid, int $userid): array {
        global $DB;

        ['assignid' => $assignid, 'userid' => $userid] = self::validate_parameters(self::execute_parameters(), [
            'assignid' => $assignid,
            'userid' => $userid,
        ]);
        $cm = get_coursemodule_from_instance('assign', $assignid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/assign:grade', $context);
        require_capability('assignfeedback/proctorai:viewdetails', $context);

        $job = job_manager::latest_job($assignid, $userid);
        $result = [
            'status' => $job ? $job->status : 'none',
            'statuslabel' => get_string('status_' . ($job ? $job->status : 'none'), 'assignfeedback_proctorai'),
            'errormessage' => $job && $job->errormessage !== null ? (string) $job->errormessage : '',
            'hasresult' => false,
            'earned' => 0.0,
            'maxmarks' => 0.0,
            'percentage' => 0.0,
            'suggestedgrade' => null,
            'reviewcount' => 0,
            'timemodified' => $job ? (int) $job->timemodified : 0,
        ];
        if ($job && $job->status === job_manager::STATUS_DONE) {
            $sql = 'SELECT r.*
                      FROM {assignfeedback_proctorai} r
                      JOIN {assign_grades} g ON g.id = r.grade
                     WHERE g.assignment = :assignment AND g.userid = :userid AND g.attemptnumber = :attempt';
            $row = $DB->get_record_sql($sql, ['assignment' => $assignid, 'userid' => $userid, 'attempt' => $job->attempt]);
            if ($row) {
                $result['hasresult'] = true;
                $result['earned'] = (float) $row->earned;
                $result['maxmarks'] = (float) $row->maxmarks;
                $result['percentage'] = (float) $row->percentage;
                $result['suggestedgrade'] = $row->suggestedgrade !== null ? (float) $row->suggestedgrade : null;
                $result['reviewcount'] = (int) $row->reviewcount;
            }
        }
        return $result;
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(
                PARAM_ALPHA,
                'none, queued, uploading, grading, done, failed, nobalance, cancelled or stale'
            ),
            'statuslabel' => new external_value(PARAM_TEXT, 'Human-readable status'),
            'errormessage' => new external_value(PARAM_TEXT, 'Why the job stopped, if it did'),
            'hasresult' => new external_value(PARAM_BOOL, 'Whether an AI result is stored for the current attempt'),
            'earned' => new external_value(PARAM_FLOAT, 'Marks earned'),
            'maxmarks' => new external_value(PARAM_FLOAT, 'Marks available'),
            'percentage' => new external_value(PARAM_FLOAT, 'Percentage'),
            'suggestedgrade' => new external_value(
                PARAM_FLOAT,
                'The AI result on the assignment grade scale',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
            'reviewcount' => new external_value(PARAM_INT, 'Questions the AI flagged for review'),
            'timemodified' => new external_value(PARAM_INT, 'When the job last changed'),
        ]);
    }
}
