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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/grade/grading/lib.php');

/**
 * Stores a Proctor AI result as a draft for the teacher to review.
 *
 * Nothing here releases a grade. With marking workflow on, a points grade is
 * pre-filled and the student is moved to "Ready for review", which keeps the
 * gradebook empty until a teacher releases it. With marking workflow off the
 * result is only a suggestion until a teacher saves the grader form.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_applier {
    /**
     * Save the result for the job's student and attempt.
     *
     * @param \assign $assign The assignment.
     * @param \stdClass $job The job record.
     * @param array $result The POST /grade response body.
     * @return \stdClass The assignfeedback_proctorai record.
     */
    public static function apply(\assign $assign, \stdClass $job, array $result): \stdClass {
        global $DB;

        $instance = $assign->get_instance();
        $grade = $assign->get_user_grade($job->userid, true, $job->attempt);
        $row = $DB->get_record('assignfeedback_proctorai', ['grade' => $grade->id]);
        $now = time();

        $marks = is_array($result['marks'] ?? null) ? $result['marks'] : [];
        $earned = max(0.0, (float) ($marks['earned'] ?? 0));
        $max = max(0.0, (float) ($marks['max'] ?? 0));
        if ($max > 0) {
            $percentage = $earned / $max * 100;
        } else {
            $percentage = (float) ($marks['percentage'] ?? 0);
        }
        $percentage = min(100.0, max(0.0, $percentage));

        $suggested = null;
        if ($instance->grade > 0) {
            $suggested = round($percentage / 100 * (float) $instance->grade, 2);
            $suggested = min((float) $instance->grade, max(0.0, $suggested));
        }

        $questions = [];
        $reviewcount = 0;
        foreach ((array) ($result['questions'] ?? []) as $q) {
            if (!is_array($q)) {
                continue;
            }
            $needsreview = !empty($q['needsReview']);
            $reviewcount += $needsreview ? 1 : 0;
            $questions[] = [
                'questionId' => (string) ($q['questionId'] ?? ''),
                'subQuestionId' => isset($q['subQuestionId']) ? (string) $q['subQuestionId'] : null,
                'label' => isset($q['label']) ? (string) $q['label'] : null,
                'earned' => (float) ($q['earned'] ?? 0),
                'max' => (float) ($q['max'] ?? 0),
                'status' => (string) ($q['status'] ?? ''),
                'feedback' => (string) ($q['feedback'] ?? ''),
                'reasoning' => isset($q['reasoning']) ? (string) $q['reasoning'] : null,
                'studentAnswer' => isset($q['studentAnswer']) ? (string) $q['studentAnswer'] : null,
                'pages' => array_values(array_map('intval', (array) ($q['pages'] ?? []))),
                'confidence' => $q['confidence'] ?? null,
                'needsReview' => $needsreview,
                'reviewReason' => isset($q['reviewReason']) ? (string) $q['reviewReason'] : null,
            ];
        }

        // The name and roll number the AI read off the sheet are not kept.
        $stored = [
            'id' => (string) ($result['id'] ?? ''),
            'marks' => ['earned' => $earned, 'max' => $max, 'percentage' => $percentage],
            'questions' => $questions,
            'strengths' => array_values(array_map('strval', (array) ($result['strengths'] ?? []))),
            'improvements' => array_values(array_map('strval', (array) ($result['improvements'] ?? []))),
            'overallFeedback' => (string) ($result['overallFeedback'] ?? ''),
            'pageCount' => (int) ($result['pageCount'] ?? 0),
        ];

        $data = $row ?: (object) [
            'assignment' => $instance->id,
            'grade' => $grade->id,
            'approved' => 0,
            'timecreated' => $now,
        ];
        $data->jobid = $job->id;
        $data->earned = $earned;
        $data->maxmarks = $max;
        $data->percentage = round($percentage, 2);
        $data->suggestedgrade = $suggested;
        $data->needsreview = $reviewcount > 0 ? 1 : 0;
        $data->reviewcount = $reviewcount;
        $data->resultjson = json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $data->timemodified = $now;
        if (empty($data->approved)) {
            // Once a teacher has approved the feedback text, a re-grade does not overwrite it.
            $data->feedbacktext = self::feedback_html($stored);
            $data->feedbackformat = FORMAT_HTML;
        }

        if ($instance->markingworkflow && $suggested !== null && self::can_prefill($assign, $grade, $row ?: null)) {
            if (self::prefill($assign, $grade, $suggested)) {
                $data->appliedgrade = $suggested;
            }
        }

        if ($row) {
            $DB->update_record('assignfeedback_proctorai', $data);
        } else {
            $data->id = $DB->insert_record('assignfeedback_proctorai', $data);
        }
        return $data;
    }

    /**
     * Whether the AI grade may be written into the assignment grade.
     *
     * Only for point grades, with no advanced grading method, where no person has graded this attempt
     * and the workflow has not moved past "Ready for review".
     *
     * @param \assign $assign The assignment.
     * @param \stdClass $grade The assign_grades record.
     * @param \stdClass|null $row The existing assignfeedback_proctorai record.
     * @return bool
     */
    public static function can_prefill(\assign $assign, \stdClass $grade, ?\stdClass $row): bool {
        $instance = $assign->get_instance();
        if (!$instance->markingworkflow || $instance->grade <= 0 || !empty($instance->teamsubmission)) {
            return false;
        }
        if (self::has_advanced_grading($assign)) {
            return false;
        }
        if ($row && !empty($row->approved)) {
            return false;
        }
        if ($grade->grade !== null && (float) $grade->grade >= 0) {
            // A grade is there. It is ours only if it is still exactly what this plugin wrote.
            $ours = $row && $row->appliedgrade !== null
                && !grade_floats_different((float) $grade->grade, (float) $row->appliedgrade);
            if (!$ours) {
                return false;
            }
        }
        $flags = $assign->get_user_flags($grade->userid, false);
        $state = $flags && !empty($flags->workflowstate) ? $flags->workflowstate : ASSIGN_MARKING_WORKFLOW_STATE_NOTMARKED;
        return in_array($state, [
            ASSIGN_MARKING_WORKFLOW_STATE_NOTMARKED,
            ASSIGN_MARKING_WORKFLOW_STATE_INMARKING,
            ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW,
        ], true);
    }

    /**
     * Whether the assignment uses a rubric, marking guide or other advanced grading method.
     *
     * @param \assign $assign The assignment.
     * @return bool
     */
    public static function has_advanced_grading(\assign $assign): bool {
        $manager = get_grading_manager($assign->get_context(), 'mod_assign', 'submissions');
        return (bool) $manager->get_active_method();
    }

    /**
     * Write the grade and set "Ready for review". The workflow state is set first, so the
     * gradebook update that follows sees an unreleased grade and leaves the gradebook empty.
     *
     * @param \assign $assign The assignment.
     * @param \stdClass $grade The assign_grades record.
     * @param float $value The grade to write.
     * @return bool Whether it was written.
     */
    protected static function prefill(\assign $assign, \stdClass $grade, float $value): bool {
        global $DB;

        $flags = $assign->get_user_flags($grade->userid, true);
        if ($flags->workflowstate !== ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW) {
            $flags->workflowstate = ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW;
            $assign->update_user_flags($flags);
            $user = \core_user::get_user($grade->userid);
            if ($user) {
                \mod_assign\event\workflow_state_updated::create_from_user($assign, $user,
                    ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW)->trigger();
            }
        }

        $grade->grade = $value;
        $reopenauto = defined('ASSIGN_ATTEMPT_REOPEN_METHOD_AUTOMATIC')
            && $assign->get_instance()->attemptreopenmethod === ASSIGN_ATTEMPT_REOPEN_METHOD_AUTOMATIC;
        if ($reopenauto) {
            // Under "reopen automatically", update_grade() would open a new attempt for a draft grade.
            // The gradebook has nothing to show before release, so store the draft directly.
            $grade->timemodified = time();
            return $DB->update_record('assign_grades', $grade);
        }
        return $assign->update_grade($grade, false);
    }

    /**
     * The first draft of the student-facing feedback, built from the AI result.
     *
     * @param array $result The stored result.
     * @return string HTML.
     */
    public static function feedback_html(array $result): string {
        $html = '';
        if (trim($result['overallFeedback']) !== '') {
            $html .= \html_writer::tag('p', s(trim($result['overallFeedback'])));
        }
        foreach (['strengths', 'improvements'] as $section) {
            $items = array_filter(array_map('trim', $result[$section]), 'strlen');
            if ($items) {
                $html .= \html_writer::tag('p', \html_writer::tag('strong', get_string($section, 'assignfeedback_proctorai')));
                $html .= \html_writer::alist(array_map('s', $items));
            }
        }
        return $html;
    }
}
