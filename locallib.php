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

/**
 * The Proctor AI assignment feedback plugin.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use assignfeedback_proctorai\local\account;
use assignfeedback_proctorai\local\job_manager;
use assignfeedback_proctorai\local\marking_key;
use assignfeedback_proctorai\local\result_applier;

/**
 * AI grading of handwritten (photo or PDF) and typed submissions, reviewed by the teacher.
 *
 * The marking key is stored in this plugin's assignment config. get_config_for_external()
 * is deliberately not overridden, so the base class returns nothing and the expected
 * answers are never exposed to students through mod_assign_get_assignments.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_feedback_proctorai extends assign_feedback_plugin {
    /** @var string The editor element in the grader form. */
    const EDITOR = 'assignfeedbackproctorai_editor';

    /** @var string The hidden grader form element holding per-question marks as JSON. */
    const MARKS = 'assignfeedbackproctorai_marks';

    /**
     * Plugin name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'assignfeedback_proctorai');
    }

    /**
     * Add the per-assignment settings: marking key, level, instructions and auto-grading.
     *
     * The marking key stays a plain textarea, which is what is saved and validated. The
     * structured editor (AMD) reads and writes the same text, and shows the total marks.
     *
     * @param MoodleQuickForm $mform The assignment settings form.
     */
    public function get_settings(MoodleQuickForm $mform) {
        global $PAGE;
        $enabled = 'assignfeedback_proctorai_enabled';

        $error = (string) $this->get_config('markingkeyerror');
        if ($error !== '') {
            $mform->addElement(
                'static',
                'assignfeedback_proctorai_keyerror',
                '',
                html_writer::div(
                    get_string('markingkeyerrorsaved', 'assignfeedback_proctorai', s($error)),
                    'alert alert-danger'
                )
            );
            $mform->hideIf('assignfeedback_proctorai_keyerror', $enabled, 'notchecked');
        }
        if (!account::is_configured()) {
            $mform->addElement(
                'static',
                'assignfeedback_proctorai_notconfigured',
                '',
                html_writer::div(get_string('notconfigured', 'assignfeedback_proctorai'), 'alert alert-warning')
            );
            $mform->hideIf('assignfeedback_proctorai_notconfigured', $enabled, 'notchecked');
        }

        $mform->addElement(
            'textarea',
            'assignfeedback_proctorai_markingkey',
            get_string('markingkey', 'assignfeedback_proctorai'),
            ['rows' => 12, 'cols' => 70, 'class' => 'text-monospace']
        );
        $mform->setType('assignfeedback_proctorai_markingkey', PARAM_RAW);
        $mform->addHelpButton('assignfeedback_proctorai_markingkey', 'markingkey', 'assignfeedback_proctorai');
        $mform->setDefault('assignfeedback_proctorai_markingkey', (string) $this->get_config('markingkey'));
        $mform->hideIf('assignfeedback_proctorai_markingkey', $enabled, 'notchecked');
        $PAGE->requires->js_call_amd('assignfeedback_proctorai/markingkey_editor', 'init', [[
            'textareaid' => 'id_assignfeedback_proctorai_markingkey',
            'gradefield' => 'grade[modgrade_point]',
        ]]);

        $mform->addElement(
            'text',
            'assignfeedback_proctorai_level',
            get_string('level', 'assignfeedback_proctorai'),
            ['size' => 40, 'maxlength' => 80]
        );
        $mform->setType('assignfeedback_proctorai_level', PARAM_TEXT);
        $mform->addHelpButton('assignfeedback_proctorai_level', 'level', 'assignfeedback_proctorai');
        $mform->setDefault('assignfeedback_proctorai_level', (string) $this->get_config('level'));
        $mform->hideIf('assignfeedback_proctorai_level', $enabled, 'notchecked');

        $mform->addElement(
            'textarea',
            'assignfeedback_proctorai_instructions',
            get_string('instructions', 'assignfeedback_proctorai'),
            ['rows' => 4, 'cols' => 70]
        );
        $mform->setType('assignfeedback_proctorai_instructions', PARAM_TEXT);
        $mform->addHelpButton('assignfeedback_proctorai_instructions', 'instructions', 'assignfeedback_proctorai');
        $mform->setDefault('assignfeedback_proctorai_instructions', (string) $this->get_config('instructions'));
        $mform->hideIf('assignfeedback_proctorai_instructions', $enabled, 'notchecked');

        $mform->addElement(
            'selectyesno',
            'assignfeedback_proctorai_autograde',
            get_string('autograde', 'assignfeedback_proctorai')
        );
        $mform->addHelpButton('assignfeedback_proctorai_autograde', 'autograde', 'assignfeedback_proctorai');
        $mform->setDefault('assignfeedback_proctorai_autograde', (int) $this->get_config('autograde'));
        $mform->hideIf('assignfeedback_proctorai_autograde', $enabled, 'notchecked');
    }

    /**
     * Save the per-assignment settings.
     *
     * Moodle has no hook for a feedback plugin to reject the settings form, so a marking key that
     * does not parse is saved with its error, which is shown on the form and blocks grading.
     *
     * @param stdClass $data The form data.
     * @return bool
     */
    public function save_settings(stdClass $data) {
        $key = str_replace(["\r\n", "\r"], "\n", (string) ($data->assignfeedback_proctorai_markingkey ?? ''));
        $this->set_config('markingkey', $key);
        $this->set_config('markingkeyerror', trim($key) === '' ? '' : (string) marking_key::validate($key));
        $this->set_config('level', trim((string) ($data->assignfeedback_proctorai_level ?? '')));
        $this->set_config('instructions', trim((string) ($data->assignfeedback_proctorai_instructions ?? '')));
        $this->set_config('autograde', empty($data->assignfeedback_proctorai_autograde) ? 0 : 1);
        return true;
    }

    /**
     * The stored AI result for a grade.
     *
     * @param int $gradeid The assign_grades id.
     * @return stdClass|false
     */
    public function get_result($gradeid) {
        global $DB;
        return $DB->get_record('assignfeedback_proctorai', ['grade' => $gradeid]);
    }

    /**
     * Whether a student may see this result's feedback and marks.
     *
     * Without marking workflow, only once a teacher has saved the grader form or applied the marks.
     * With marking workflow, mod_assign itself hides feedback until release, and release counts as approval.
     *
     * @param stdClass $result The assignfeedback_proctorai record.
     * @param stdClass $grade The assign_grades record.
     * @return bool
     */
    protected function is_released_to_student(stdClass $result, stdClass $grade): bool {
        if (!empty($result->approved)) {
            return true;
        }
        if ($this->assignment->get_instance()->markingworkflow) {
            return $this->assignment->get_grading_status($grade->userid) === ASSIGN_MARKING_WORKFLOW_STATE_RELEASED;
        }
        return false;
    }

    /**
     * Whether the current user grades in this assignment.
     *
     * @return bool
     */
    protected function viewer_can_grade(): bool {
        return has_capability('mod/assign:grade', $this->assignment->get_context());
    }

    /**
     * Add the AI panel, the per-question marks and the feedback editor to the grader form.
     *
     * @param stdClass|null $grade The grade, or null.
     * @param MoodleQuickForm $mform The grader form.
     * @param stdClass $data Form data, to set defaults on.
     * @param int $userid The student.
     * @return bool
     */
    public function get_form_elements_for_user($grade, MoodleQuickForm $mform, stdClass $data, $userid) {
        global $OUTPUT, $PAGE;

        $context = $this->assignment->get_context();
        $result = $grade ? $this->get_result($grade->id) : false;
        $instance = $this->assignment->get_instance();
        $job = job_manager::latest_job_for($this->assignment, (int) $userid);
        $added = false;

        if (has_capability('assignfeedback/proctorai:viewdetails', $context)) {
            $added = true;
            $panel = $this->panel_context($result ?: null, $job, $userid);
            $mform->addElement('html', $OUTPUT->render_from_template('assignfeedback_proctorai/grader_panel', $panel));
            $PAGE->requires->js_call_amd('assignfeedback_proctorai/grader_panel', 'init', [[
                'panelid' => $panel['panelid'],
                'assignid' => (int) $instance->id,
                'userid' => (int) $userid,
                'polling' => $job && in_array($job->status, job_manager::ACTIVE, true),
                'marksfield' => self::MARKS,
                'earned' => $result ? (float) $result->earned : 0.0,
                'maxmarks' => $result ? (float) $result->maxmarks : 0.0,
                'maxgrade' => !empty($panel['canusegrade']) ? (float) $instance->grade : 0.0,
            ]]);
        }

        if (!$result) {
            return $added;
        }
        $mform->addElement('hidden', self::MARKS, json_encode($this->current_marks($result)));
        $mform->setType(self::MARKS, PARAM_RAW);

        if (!isset($data->{self::EDITOR})) {
            $data->{self::EDITOR} = ['text' => (string) $result->feedbacktext, 'format' => (int) $result->feedbackformat];
        }
        $mform->addElement(
            'editor',
            self::EDITOR,
            get_string('feedbackforstudent', 'assignfeedback_proctorai'),
            null,
            $this->get_editor_options()
        );
        $mform->setType(self::EDITOR, PARAM_RAW);
        $mform->addHelpButton(self::EDITOR, 'feedbackforstudent', 'assignfeedback_proctorai');
        return true;
    }

    /**
     * Editor options: no files, so nothing extra needs backing up or exporting.
     *
     * @return array
     */
    protected function get_editor_options(): array {
        return ['maxfiles' => 0, 'context' => $this->assignment->get_context(), 'noclean' => false];
    }

    /**
     * The marks per question as they stand (teacher's or AI's), by question index.
     *
     * @param stdClass $result The stored result.
     * @return float[]
     */
    protected function current_marks(stdClass $result): array {
        $stored = json_decode((string) $result->resultjson, true) ?: [];
        return array_map([result_applier::class, 'effective_mark'], (array) ($stored['questions'] ?? []));
    }

    /**
     * Template context for the grader panel.
     *
     * @param stdClass|null $result The stored result.
     * @param stdClass|null $job The latest job.
     * @param int $userid The student.
     * @return array
     */
    protected function panel_context(?stdClass $result, ?stdClass $job, int $userid): array {
        $instance = $this->assignment->get_instance();
        $context = [
            'panelid' => html_writer::random_id('assignfeedback_proctorai_panel'),
            'status' => $job ? $job->status : 'none',
            'statuslabel' => get_string('status_' . ($job ? $job->status : 'none'), 'assignfeedback_proctorai'),
            'errormessage' => $job && $job->errormessage !== null && $job->status !== job_manager::STATUS_DONE
                ? $job->errormessage : '',
            'notsent' => $job ? $this->notsent_summary($job) : '',
            'hasresult' => (bool) $result,
            'questions' => [],
        ];
        if (!$result) {
            return $context;
        }

        $editable = $this->viewer_can_grade();
        $stored = json_decode((string) $result->resultjson, true) ?: [];
        foreach ((array) ($stored['questions'] ?? []) as $index => $q) {
            $label = trim((string) ($q['label'] ?? ''));
            if ($label === '') {
                $label = $q['questionId'] . (isset($q['subQuestionId']) ? $q['subQuestionId'] : '');
            }
            $confidence = $q['confidence'] ?? null;
            if (is_numeric($confidence)) {
                $value = (float) $confidence;
                $confidence = format_float($value <= 1 ? $value * 100 : $value, 0) . '%';
            }
            $mark = result_applier::effective_mark($q);
            $context['questions'][] = [
                'index' => $index,
                'label' => $label,
                'earned' => format_float($mark, 2, true, true),
                'markraw' => $mark,
                'max' => format_float((float) $q['max'], 2, true, true),
                'maxraw' => (float) $q['max'],
                'aiearned' => format_float((float) $q['earned'], 2, true, true),
                'edited' => isset($q['teacherEarned']),
                'editable' => $editable,
                'feedback' => (string) ($q['feedback'] ?? ''),
                'studentanswer' => (string) ($q['studentAnswer'] ?? ''),
                'reasoning' => (string) ($q['reasoning'] ?? ''),
                'confidence' => $confidence !== null ? (string) $confidence : '',
                'needsreview' => !empty($q['needsReview']),
                'reviewreason' => (string) ($q['reviewReason'] ?? ''),
            ];
        }

        $canprefill = $instance->grade > 0 && $result->suggestedgrade !== null
            && !result_applier::has_advanced_grading($this->assignment);
        $context += [
            'earned' => format_float((float) $result->earned, 2, true, true),
            'maxmarks' => format_float((float) $result->maxmarks, 2, true, true),
            'percentage' => format_float((float) $result->percentage, 1, true, true),
            'reviewcount' => (int) $result->reviewcount,
            'needsreview' => !empty($result->needsreview),
            'canusegrade' => $canprefill,
            'suggestedgrade' => $canprefill ? format_float((float) $result->suggestedgrade, 2, true, true) : '',
            'suggestedgraderaw' => $canprefill ? (float) $result->suggestedgrade : '',
            'maxgrade' => $instance->grade > 0 ? format_float((float) $instance->grade, 2, true, true) : '',
            'percentonly' => !$canprefill,
            'prefilled' => $result->appliedgrade !== null,
            'approved' => !empty($result->approved),
            'pagecount' => (int) ($stored['pageCount'] ?? 0),
            'editable' => $editable,
        ];
        return $context;
    }

    /**
     * Which submitted files the job could not send, and why, as one line for the teacher.
     *
     * @param stdClass $job The job.
     * @return string Plain text, empty when every file was sent.
     */
    protected function notsent_summary(stdClass $job): string {
        $files = !empty($job->notsent) ? json_decode((string) $job->notsent, true) : [];
        if (!is_array($files) || !$files) {
            return '';
        }
        $items = [];
        foreach ($files as $file) {
            $reason = in_array($file['reason'] ?? '', ['type', 'size', 'empty'], true) ? $file['reason'] : 'type';
            $items[] = get_string('notsent_' . $reason, 'assignfeedback_proctorai', (string) ($file['name'] ?? ''));
        }
        return get_string('notsent', 'assignfeedback_proctorai', implode('; ', $items));
    }

    /**
     * The per-question marks posted with the grader form, by question index.
     *
     * @param stdClass $data The grader form data.
     * @return array|null Null when the form had no marks field.
     */
    protected function posted_marks(stdClass $data): ?array {
        if (!isset($data->{self::MARKS}) || $data->{self::MARKS} === '') {
            return null;
        }
        $marks = json_decode((string) $data->{self::MARKS}, true);
        return is_array($marks) ? $marks : null;
    }

    /**
     * Whether the teacher changed the AI feedback or marks, or is saving an unapproved result.
     *
     * Saving the grader form approves the result, so an unapproved result always counts as modified.
     *
     * @param stdClass $grade The grade.
     * @param stdClass $data The grader form data.
     * @return bool
     */
    public function is_feedback_modified(stdClass $grade, stdClass $data) {
        $result = $this->get_result($grade->id);
        if (!$result || !isset($data->{self::EDITOR}['text'])) {
            return false;
        }
        if (empty($result->approved)) {
            return true;
        }
        $marks = $this->posted_marks($data);
        if ($marks !== null) {
            $copy = clone $result;
            if (result_applier::set_teacher_marks($this->assignment, $copy, $marks)) {
                return true;
            }
        }
        return trim((string) $data->{self::EDITOR}['text']) !== trim((string) $result->feedbacktext);
    }

    /**
     * Save the teacher's edits (feedback text and per-question marks) and mark the result approved.
     *
     * @param stdClass $grade The grade.
     * @param stdClass $data The grader form data.
     * @return bool
     */
    public function save(stdClass $grade, stdClass $data) {
        global $DB, $USER;
        $result = $this->get_result($grade->id);
        if (!$result || !isset($data->{self::EDITOR}['text'])) {
            return true;
        }
        $marks = $this->posted_marks($data);
        if ($marks !== null) {
            result_applier::set_teacher_marks($this->assignment, $result, $marks);
        }
        $result->feedbacktext = clean_text((string) $data->{self::EDITOR}['text'], FORMAT_HTML);
        $result->feedbackformat = (int) ($data->{self::EDITOR}['format'] ?? FORMAT_HTML);
        $result->approved = 1;
        $result->approvedby = $USER->id;
        $result->timeapproved = time();
        $result->timemodified = time();
        return $DB->update_record('assignfeedback_proctorai', $result);
    }

    /**
     * One-line summary: the AI result for graders, the released feedback for students.
     *
     * @param stdClass $grade The grade.
     * @param bool $showviewlink Set to true to show a link to the full view.
     * @return string
     */
    public function view_summary(stdClass $grade, &$showviewlink) {
        $result = $this->get_result($grade->id);
        if ($this->viewer_can_grade()) {
            $job = job_manager::latest_job_for($this->assignment, (int) $grade->userid);
            if ($job && $job->status !== job_manager::STATUS_DONE) {
                $text = get_string('status_' . $job->status, 'assignfeedback_proctorai');
                if ($job->errormessage !== null && $job->status !== job_manager::STATUS_QUEUED) {
                    $text .= ': ' . s(shorten_text($job->errormessage, 100));
                }
                return html_writer::span($text, 'assignfeedback_proctorai_status');
            }
            if (!$result) {
                return '';
            }
            $a = (object) [
                'earned' => format_float((float) $result->earned, 2, true, true),
                'max' => format_float((float) $result->maxmarks, 2, true, true),
                'percentage' => format_float((float) $result->percentage, 1, true, true),
            ];
            $text = get_string('summaryai', 'assignfeedback_proctorai', $a);
            if ($result->reviewcount > 0) {
                $text .= ' ' . html_writer::span(
                    get_string('summaryreview', 'assignfeedback_proctorai', $result->reviewcount),
                    'badge bg-warning text-dark'
                );
            }
            if (!$result->approved) {
                $text .= ' ' . html_writer::span(get_string('draft', 'assignfeedback_proctorai'), 'badge bg-secondary');
            }
            return $text;
        }

        if (!$result || !$this->is_released_to_student($result, $grade)) {
            return '';
        }
        // Students get the whole feedback and the marks per question here; there is nothing more to open.
        $showviewlink = false;
        return $this->view($grade);
    }

    /**
     * Full view: the feedback text and, once approved, the marks per question.
     *
     * @param stdClass $grade The grade.
     * @return string
     */
    public function view(stdClass $grade) {
        global $OUTPUT;
        $result = $this->get_result($grade->id);
        if (!$result) {
            return '';
        }
        $released = $this->is_released_to_student($result, $grade);
        if (!$this->viewer_can_grade() && !$released) {
            return '';
        }
        $html = format_text(
            (string) $result->feedbacktext,
            $result->feedbackformat,
            ['context' => $this->assignment->get_context()]
        );
        $rows = result_applier::student_rows($result);
        if ($released && $rows) {
            $html .= $OUTPUT->render_from_template('assignfeedback_proctorai/student_marks', ['rows' => $rows]);
        }
        return $html;
    }

    /**
     * Empty for students until a teacher approves (or releases) the result.
     *
     * @param stdClass $grade The grade.
     * @return bool
     */
    public function is_empty(stdClass $grade) {
        $result = $this->get_result($grade->id);
        if (!$result) {
            return true;
        }
        if (trim(strip_tags((string) $result->feedbacktext)) === '' && !result_applier::student_rows($result)) {
            return true;
        }
        if ($this->viewer_can_grade()) {
            return false;
        }
        return !$this->is_released_to_student($result, $grade);
    }

    /**
     * The approved feedback, if this plugin is chosen to push comments to the gradebook.
     *
     * @param stdClass $grade The grade.
     * @return string
     */
    public function text_for_gradebook(stdClass $grade) {
        $result = $this->get_result($grade->id);
        return $result && $result->approved ? (string) $result->feedbacktext : '';
    }

    /**
     * Format of text_for_gradebook().
     *
     * @param stdClass $grade The grade.
     * @return int
     */
    public function format_for_gradebook(stdClass $grade) {
        $result = $this->get_result($grade->id);
        return $result ? (int) $result->feedbackformat : FORMAT_HTML;
    }

    /**
     * Delete everything for this assignment.
     *
     * @return bool
     */
    public function delete_instance() {
        global $DB;
        $assignmentid = $this->assignment->get_instance()->id;
        $DB->delete_records('assignfeedback_proctorai', ['assignment' => $assignmentid]);
        $DB->delete_records('assignfeedback_proctorai_job', ['assignment' => $assignmentid]);
        return true;
    }

    /**
     * A banner for graders on the assignment page: paused, out of balance, or low balance.
     *
     * Only the cached balance is read, so viewing the assignment never waits for Proctor AI.
     *
     * @return string
     */
    public function view_header() {
        global $OUTPUT;
        if (!$this->viewer_can_grade() || !account::is_configured()) {
            return '';
        }
        $link = html_writer::link(
            $this->page_url('status'),
            get_string('statuspage', 'assignfeedback_proctorai')
        );
        if (account::is_paused()) {
            return $OUTPUT->notification(get_string('paused', 'assignfeedback_proctorai') . ' ' . $link, 'error', false);
        }
        $account = account::get(false);
        if (!$account || !isset($account['sheetsRemaining'])) {
            return '';
        }
        $sheets = (int) $account['sheetsRemaining'];
        if ($sheets < 1) {
            return $OUTPUT->notification(get_string('bannernobalance', 'assignfeedback_proctorai') . ' ' . $link, 'error', false);
        }
        $threshold = (int) get_config('assignfeedback_proctorai', 'lowbalance');
        if ($threshold > 0 && $sheets < $threshold) {
            return $OUTPUT->notification(
                get_string('bannerlowbalance', 'assignfeedback_proctorai', $sheets) . ' ' . $link,
                'warning',
                false
            );
        }
        return '';
    }

    /**
     * Whether the current user may queue gradings here.
     *
     * @return bool
     */
    protected function can_request(): bool {
        $context = $this->assignment->get_context();
        return has_all_capabilities(['mod/assign:grade', 'assignfeedback/proctorai:requestgrading'], $context);
    }

    /**
     * Bulk actions on the grading table (Moodle 4.5 API): "Grade with Proctor AI", and, without
     * marking workflow, "Apply Proctor AI marks".
     *
     * @return array
     */
    public function get_grading_batch_operation_details() {
        global $OUTPUT;
        $operations = [];
        if ($this->can_request()) {
            $operations[] = (object) [
                'key' => 'grade',
                'label' => get_string('batchgrade', 'assignfeedback_proctorai'),
                'icon' => $OUTPUT->pix_icon('i/grades', ''),
                'confirmationtitle' => get_string('batchgrade', 'assignfeedback_proctorai'),
                'confirmationquestion' => get_string('batchgradeconfirm', 'assignfeedback_proctorai'),
            ];
        }
        if ($this->viewer_can_grade() && !$this->assignment->get_instance()->markingworkflow) {
            $operations[] = (object) [
                'key' => 'apply',
                'label' => get_string('batchapply', 'assignfeedback_proctorai'),
                'icon' => $OUTPUT->pix_icon('i/checked', ''),
                'confirmationtitle' => get_string('batchapply', 'assignfeedback_proctorai'),
                'confirmationquestion' => get_string('batchapplyconfirm', 'assignfeedback_proctorai'),
            ];
        }
        return $operations;
    }

    /**
     * Show the confirmation page for a bulk action on the selected students.
     *
     * @param string $action The batch operation key.
     * @param array $users The selected user ids.
     * @return string
     */
    public function grading_batch_operation($action, $users) {
        $users = array_map('intval', $users);
        if ($action === 'grade') {
            return $this->view_confirm($users);
        }
        if ($action === 'apply') {
            return $this->view_apply_confirm($users);
        }
        return '';
    }

    /**
     * Grading actions menu entries.
     *
     * @return array
     */
    public function get_grading_actions() {
        $actions = [];
        if ($this->can_request()) {
            $actions['gradeall'] = get_string('gradeall', 'assignfeedback_proctorai');
        }
        if (has_capability('assignfeedback/proctorai:viewdetails', $this->assignment->get_context())) {
            $actions['status'] = get_string('statuspage', 'assignfeedback_proctorai');
        }
        return $actions;
    }

    /**
     * Pages reached through view.php?action=viewpluginpage.
     *
     * @param string $action gradeall, confirm, queue, apply, status, jobaction or retrynobalance.
     * @return string
     */
    public function view_page($action) {
        require_capability('mod/assign:grade', $this->assignment->get_context());
        switch ($action) {
            case 'gradeall':
                return $this->view_confirm($this->ungraded_userids());
            case 'confirm':
                $users = optional_param('selectedusers', '', PARAM_SEQUENCE);
                return $this->view_confirm(array_filter(array_map('intval', explode(',', $users))));
            case 'queue':
                $this->process_queue();
                return '';
            case 'applyconfirm':
                $users = optional_param('selectedusers', '', PARAM_SEQUENCE);
                return $this->view_apply_confirm(array_filter(array_map('intval', explode(',', $users))));
            case 'apply':
                $this->process_apply();
                return '';
            case 'status':
                return $this->view_status();
            case 'jobaction':
                $this->process_job_action();
                return '';
            case 'retrynobalance':
                $this->process_retry_nobalance();
                return '';
        }
        return '';
    }

    /**
     * Keep only the students the current user may grade here: with separate groups and no
     * "access all groups", those in the groups they can see. Posted user ids are not trusted.
     *
     * @param int[] $userids Student ids from the request.
     * @return int[]
     */
    protected function visible_userids(array $userids): array {
        $currentgroup = groups_get_activity_group($this->assignment->get_course_module(), true);
        $participants = $this->assignment->list_participants($currentgroup, true);
        return array_values(array_filter(
            array_unique(array_map('intval', $userids)),
            fn(int $userid) => isset($participants[$userid])
        ));
    }

    /**
     * Students with a submission and no grade yet. With team submissions, one member per group.
     *
     * @return int[]
     */
    protected function ungraded_userids(): array {
        $currentgroup = groups_get_activity_group($this->assignment->get_course_module(), true);
        $userids = [];
        $seen = [];
        foreach (array_keys($this->assignment->list_participants($currentgroup, true)) as $userid) {
            $submission = job_manager::submission_for($this->assignment, (int) $userid);
            if (!$submission || $submission->status !== ASSIGN_SUBMISSION_STATUS_SUBMITTED || isset($seen[$submission->id])) {
                continue;
            }
            $grade = $this->assignment->get_user_grade($userid, false);
            if ($grade && $this->get_result($grade->id)) {
                // This attempt already has an AI result.
                continue;
            }
            if ($grade && $grade->grade !== null && (float) $grade->grade >= 0) {
                // A teacher has graded this attempt.
                continue;
            }
            $seen[$submission->id] = true;
            $userids[] = (int) $userid;
        }
        return $userids;
    }

    /**
     * The URL of one of this plugin's pages.
     *
     * @param string $action The page.
     * @param array $params Extra parameters.
     * @return moodle_url
     */
    protected function page_url(string $action, array $params = []): moodle_url {
        return new moodle_url('/mod/assign/view.php', [
            'id' => $this->assignment->get_course_module()->id,
            'action' => 'viewpluginpage',
            'plugin' => 'proctorai',
            'pluginsubtype' => 'assignfeedback',
            'pluginaction' => $action,
        ] + $params);
    }

    /**
     * The grading page URL.
     *
     * @return moodle_url
     */
    protected function grading_url(): moodle_url {
        return new moodle_url('/mod/assign/view.php', [
            'id' => $this->assignment->get_course_module()->id,
            'action' => 'grading',
        ]);
    }

    /**
     * Render a whole page in the assignment's layout.
     *
     * @param string $title The heading.
     * @param string $body The page body.
     * @return string
     */
    protected function render_page(string $title, string $body): string {
        $renderer = $this->assignment->get_renderer();
        $header = new \mod_assign\output\assign_header(
            $this->assignment->get_instance(),
            $this->assignment->get_context(),
            false,
            $this->assignment->get_course_module()->id,
            $title
        );
        return $renderer->render($header) . $body . $renderer->render_footer();
    }

    /**
     * Hidden fields that bring a POST back to one of this plugin's pages.
     *
     * @param string $action The page.
     * @return array For the template's "hidden" list.
     */
    protected function hidden_fields(string $action): array {
        $fields = [];
        foreach ($this->page_url($action)->params() + ['sesskey' => sesskey()] as $name => $value) {
            $fields[] = ['name' => $name, 'value' => $value];
        }
        return $fields;
    }

    /**
     * The confirmation page: who will be graded and what it costs, before anything is queued.
     *
     * @param int[] $userids The students asked for.
     * @return string
     */
    protected function view_confirm(array $userids): string {
        global $OUTPUT;
        require_capability('assignfeedback/proctorai:requestgrading', $this->assignment->get_context());
        $userids = $this->visible_userids($userids);

        $blocker = job_manager::assignment_blocker($this->assignment);
        $eligible = [];
        $skipped = [];
        if ($blocker === null) {
            $sorted = job_manager::eligible($this->assignment, $userids);
            $eligible = $sorted['eligible'];
            $skipped = $sorted['skipped'];
        }

        $account = null;
        $accounterror = '';
        if ($blocker === null) {
            try {
                $account = account::get(true, 15);
            } catch (\Throwable $e) {
                $accounterror = $e instanceof \assignfeedback_proctorai\api\api_exception ? $e->apimessage : $e->getMessage();
            }
        }
        $price = (float) ($account['pricePerSheet'] ?? 0);
        $balance = (float) ($account['balance'] ?? 0);
        $sheets = (int) ($account['sheetsRemaining'] ?? 0);
        $count = count($eligible);
        $cost = $count * $price;

        $reasons = [];
        foreach ($skipped as $reason => $n) {
            $reasons[] = ['reason' => get_string('skip_' . $reason, 'assignfeedback_proctorai'), 'count' => $n];
        }

        $context = [
            'blocker' => $blocker !== null ? get_string($blocker, 'assignfeedback_proctorai') : '',
            'accounterror' => $accounterror,
            'count' => $count,
            'requested' => count($userids),
            'skipped' => $reasons,
            'hasskipped' => (bool) $reasons,
            'team' => !empty($this->assignment->get_instance()->teamsubmission),
            'price' => format_float($price, 2, true, true),
            'cost' => format_float($cost, 2, true, true),
            'balance' => format_float($balance, 2, true, true),
            'sheets' => $sheets,
            'hasaccount' => $account !== null,
            'insufficient' => $account !== null && $sheets < $count,
            'canqueue' => $blocker === null && $count > 0 && $account !== null && $sheets > 0,
            'actionurl' => (new moodle_url('/mod/assign/view.php'))->out(false),
            'cmid' => $this->assignment->get_course_module()->id,
            'selectedusers' => implode(',', $eligible),
            'sesskey' => sesskey(),
            'cancelurl' => $this->grading_url()->out(false),
        ];
        return $this->render_page(
            get_string('confirmtitle', 'assignfeedback_proctorai'),
            $OUTPUT->render_from_template('assignfeedback_proctorai/confirm', $context)
        );
    }

    /**
     * Queue the confirmed students and go to the status page.
     */
    protected function process_queue(): void {
        global $USER;
        require_sesskey();
        require_capability('assignfeedback/proctorai:requestgrading', $this->assignment->get_context());
        $users = required_param('selectedusers', PARAM_SEQUENCE);
        $userids = $this->visible_userids(array_filter(array_map('intval', explode(',', $users))));
        $result = job_manager::queue_batch($this->assignment, $userids, (int) $USER->id);
        redirect(
            $this->page_url('status'),
            get_string('queuedn', 'assignfeedback_proctorai', $result['queued']),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    /**
     * Confirm "Apply Proctor AI marks": whose grade will be written, and who is skipped and why.
     *
     * @param int[] $userids The students asked for.
     * @return string
     */
    protected function view_apply_confirm(array $userids): string {
        global $OUTPUT;
        $eligible = [];
        $skipped = [];
        $userids = $this->visible_userids($userids);
        foreach ($userids as $userid) {
            $reason = result_applier::apply_blocker($this->assignment, $userid);
            if ($reason === null) {
                $eligible[] = $userid;
            } else {
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
            }
        }
        $reasons = [];
        foreach ($skipped as $reason => $n) {
            $reasons[] = ['reason' => get_string('applyskip_' . $reason, 'assignfeedback_proctorai'), 'count' => $n];
        }
        $hidden = $this->hidden_fields('apply');
        $hidden[] = ['name' => 'selectedusers', 'value' => implode(',', $eligible)];
        $context = [
            'count' => count($eligible),
            'requested' => count($userids),
            'skipped' => $reasons,
            'hasskipped' => (bool) $reasons,
            'canapply' => (bool) $eligible,
            'actionurl' => (new moodle_url('/mod/assign/view.php'))->out(false),
            'hidden' => $hidden,
            'cancelurl' => $this->grading_url()->out(false),
        ];
        return $this->render_page(
            get_string('batchapply', 'assignfeedback_proctorai'),
            $OUTPUT->render_from_template('assignfeedback_proctorai/apply_confirm', $context)
        );
    }

    /**
     * Write the confirmed students' AI grades, then go back to the grading table.
     */
    protected function process_apply(): void {
        require_sesskey();
        if ($this->assignment->get_instance()->markingworkflow) {
            throw new \moodle_exception('applyworkflow', 'assignfeedback_proctorai');
        }
        $users = required_param('selectedusers', PARAM_SEQUENCE);
        $applied = 0;
        foreach ($this->visible_userids(array_filter(array_map('intval', explode(',', $users)))) as $userid) {
            if (result_applier::apply_to_grade($this->assignment, $userid) === 'applied') {
                $applied++;
            }
        }
        redirect(
            $this->grading_url(),
            get_string('appliedn', 'assignfeedback_proctorai', $applied),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    /**
     * Retry or cancel one job from the status page.
     */
    protected function process_job_action(): void {
        global $DB, $USER;
        require_sesskey();
        require_capability('assignfeedback/proctorai:requestgrading', $this->assignment->get_context());
        $jobid = required_param('jobid', PARAM_INT);
        $do = required_param('do', PARAM_ALPHA);
        $job = $DB->get_record(
            'assignfeedback_proctorai_job',
            ['id' => $jobid, 'assignment' => $this->assignment->get_instance()->id],
            '*',
            MUST_EXIST
        );
        if (!$this->visible_userids([(int) $job->userid])) {
            throw new \required_capability_exception(
                $this->assignment->get_context(),
                'moodle/site:accessallgroups',
                'nopermissions',
                ''
            );
        }
        if ($do === 'cancel') {
            $ok = job_manager::cancel($job);
            $message = get_string($ok ? 'jobcancelled' : 'jobnotcancelled', 'assignfeedback_proctorai');
        } else {
            $ok = (bool) job_manager::retry($this->assignment, $job, (int) $USER->id);
            $message = get_string($ok ? 'jobretried' : 'jobnotretried', 'assignfeedback_proctorai');
        }
        redirect(
            $this->page_url('status'),
            $message,
            null,
            $ok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
        );
    }

    /**
     * Queue again every student who was stopped by the balance running out.
     */
    protected function process_retry_nobalance(): void {
        global $USER;
        require_sesskey();
        require_capability('assignfeedback/proctorai:requestgrading', $this->assignment->get_context());
        $result = job_manager::retry_nobalance($this->assignment, (int) $USER->id, fn(array $ids) => $this->visible_userids($ids));
        redirect(
            $this->page_url('status'),
            get_string('queuedn', 'assignfeedback_proctorai', $result['queued']),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    /**
     * The status page: the balance, and every student's latest job and result, with retry and cancel.
     *
     * @return string
     */
    protected function view_status(): string {
        global $OUTPUT;
        $context = $this->assignment->get_context();
        require_capability('assignfeedback/proctorai:viewdetails', $context);

        $canrequest = $this->can_request();
        $currentgroup = groups_get_activity_group($this->assignment->get_course_module(), true);
        $participants = $this->assignment->list_participants($currentgroup, false);
        $counts = [];
        $rows = [];
        $hasnobalance = false;
        foreach ($participants as $user) {
            $job = job_manager::latest_job_for($this->assignment, (int) $user->id);
            $status = $job ? $job->status : 'none';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            if (!$job) {
                continue;
            }
            $hasnobalance = $hasnobalance || $status === job_manager::STATUS_NOBALANCE;
            $grade = $this->assignment->get_user_grade($user->id, false, $job->attempt);
            $result = $grade ? $this->get_result($grade->id) : false;
            $name = $this->assignment->is_blind_marking()
                ? get_string('hiddenuser', 'assign') . $this->assignment->get_uniqueid_for_user($user->id)
                : fullname($user);
            $cancancel = $canrequest && (($status === job_manager::STATUS_QUEUED && $job->timestarted === null)
                || ($status === job_manager::STATUS_FAILED && in_array($job->errorcode, job_manager::PAUSE_ERRORS, true)));
            $canretry = $canrequest && !job_manager::is_pending($job) && $status !== job_manager::STATUS_DONE;
            $rows[] = [
                'name' => $name,
                'status' => $status,
                'statuslabel' => get_string('status_' . $status, 'assignfeedback_proctorai'),
                'error' => $job->errormessage !== null && $status !== job_manager::STATUS_DONE ? $job->errormessage : '',
                'notsent' => $this->notsent_summary($job),
                'marks' => $result ? get_string('summaryai', 'assignfeedback_proctorai', (object) [
                    'earned' => format_float((float) $result->earned, 2, true, true),
                    'max' => format_float((float) $result->maxmarks, 2, true, true),
                    'percentage' => format_float((float) $result->percentage, 1, true, true),
                ]) : '',
                'reviewcount' => $result ? (int) $result->reviewcount : 0,
                'approved' => $result && $result->approved,
                'time' => userdate($job->timemodified, get_string('strftimedatetimeshort', 'core_langconfig')),
                'jobid' => (int) $job->id,
                'canretry' => $canretry,
                'cancancel' => $cancancel,
                'hasactions' => $canretry || $cancancel,
            ];
        }
        $summary = [];
        foreach ($counts as $status => $n) {
            $summary[] = ['label' => get_string('status_' . $status, 'assignfeedback_proctorai'), 'count' => $n];
        }

        // The teacher is looking at the balance: fetch it fresh.
        $account = account::refresh_for_display();
        $threshold = (int) get_config('assignfeedback_proctorai', 'lowbalance');
        $sheets = $account ? (int) ($account['sheetsRemaining'] ?? 0) : 0;
        $template = [
            'rows' => $rows,
            'hasrows' => (bool) $rows,
            'summary' => $summary,
            'hasbalance' => $account !== null,
            'balance' => $account ? format_float((float) ($account['balance'] ?? 0), 2, true, true) : '',
            'sheets' => $sheets,
            'price' => $account ? format_float((float) ($account['pricePerSheet'] ?? 0), 2, true, true) : '',
            'lowbalance' => $account !== null && $threshold > 0 && $sheets < $threshold,
            'nobalance' => $account !== null && $sheets < 1,
            'paused' => account::is_paused(),
            'actionurl' => (new moodle_url('/mod/assign/view.php'))->out(false),
            'jobhidden' => $this->hidden_fields('jobaction'),
            'retryallhidden' => $this->hidden_fields('retrynobalance'),
            'canretryall' => $canrequest && $hasnobalance,
            'gradeallurl' => $canrequest ? $this->page_url('gradeall')->out(false) : '',
            'backurl' => $this->grading_url()->out(false),
        ];
        return $this->render_page(
            get_string('statuspage', 'assignfeedback_proctorai'),
            $OUTPUT->render_from_template('assignfeedback_proctorai/status', $template)
        );
    }
}
