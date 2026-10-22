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
     * @param MoodleQuickForm $mform The assignment settings form.
     */
    public function get_settings(MoodleQuickForm $mform) {
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
     * Whether a student may see this result's feedback text.
     *
     * Without marking workflow, only once a teacher has saved the grader form. With marking workflow,
     * mod_assign itself hides feedback until release, and release counts as approval.
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
     * Add the AI panel and the feedback editor to the grader form.
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
        $job = job_manager::latest_job($instance->id, $userid);
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
            ]]);
        }

        if (!$result) {
            return $added;
        }
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
                ? s($job->errormessage) : '',
            'hasresult' => (bool) $result,
            'questions' => [],
        ];
        if (!$result) {
            return $context;
        }

        $stored = json_decode((string) $result->resultjson, true) ?: [];
        foreach ((array) ($stored['questions'] ?? []) as $q) {
            $label = trim((string) ($q['label'] ?? ''));
            if ($label === '') {
                $label = $q['questionId'] . (isset($q['subQuestionId']) ? $q['subQuestionId'] : '');
            }
            $confidence = $q['confidence'] ?? null;
            if (is_numeric($confidence)) {
                $value = (float) $confidence;
                $confidence = format_float($value <= 1 ? $value * 100 : $value, 0) . '%';
            }
            $context['questions'][] = [
                'label' => $label,
                'earned' => format_float((float) $q['earned'], 2, true, true),
                'max' => format_float((float) $q['max'], 2, true, true),
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
        ];
        return $context;
    }

    /**
     * Whether the teacher changed the AI feedback, or is saving an unapproved result.
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
        return trim((string) $data->{self::EDITOR}['text']) !== trim((string) $result->feedbacktext);
    }

    /**
     * Save the teacher's edits and mark the result approved.
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
            $job = job_manager::latest_job($this->assignment->get_instance()->id, $grade->userid);
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
        $text = format_text(
            (string) $result->feedbacktext,
            $result->feedbackformat,
            ['context' => $this->assignment->get_context()]
        );
        $short = shorten_text($text, 140);
        $showviewlink = $short != $text;
        return $short;
    }

    /**
     * Full view of the feedback text.
     *
     * @param stdClass $grade The grade.
     * @return string
     */
    public function view(stdClass $grade) {
        $result = $this->get_result($grade->id);
        if (!$result || (!$this->viewer_can_grade() && !$this->is_released_to_student($result, $grade))) {
            return '';
        }
        return format_text(
            (string) $result->feedbacktext,
            $result->feedbackformat,
            ['context' => $this->assignment->get_context()]
        );
    }

    /**
     * Empty for students until a teacher approves (or releases) the result.
     *
     * @param stdClass $grade The grade.
     * @return bool
     */
    public function is_empty(stdClass $grade) {
        $result = $this->get_result($grade->id);
        if (!$result || trim(strip_tags((string) $result->feedbacktext)) === '') {
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
     * Whether the current user may queue gradings here.
     *
     * @return bool
     */
    protected function can_request(): bool {
        $context = $this->assignment->get_context();
        return has_all_capabilities(['mod/assign:grade', 'assignfeedback/proctorai:requestgrading'], $context);
    }

    /**
     * "Grade with Proctor AI" in the grading table's bulk actions (Moodle 4.5 API).
     *
     * @return array
     */
    public function get_grading_batch_operation_details() {
        global $OUTPUT;
        if (!$this->can_request()) {
            return [];
        }
        return [
            (object) [
                'key' => 'grade',
                'label' => get_string('batchgrade', 'assignfeedback_proctorai'),
                'icon' => $OUTPUT->pix_icon('i/grades', ''),
                'confirmationtitle' => get_string('batchgrade', 'assignfeedback_proctorai'),
                'confirmationquestion' => get_string('batchgradeconfirm', 'assignfeedback_proctorai'),
            ],
        ];
    }

    /**
     * Show the cost confirmation for the selected students.
     *
     * @param string $action The batch operation key.
     * @param array $users The selected user ids.
     * @return string
     */
    public function grading_batch_operation($action, $users) {
        if ($action !== 'grade') {
            return '';
        }
        return $this->view_confirm(array_map('intval', $users));
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
     * @param string $action gradeall, confirm, queue or status.
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
            case 'status':
                return $this->view_status();
        }
        return '';
    }

    /**
     * Students with a submission and no grade yet.
     *
     * @return int[]
     */
    protected function ungraded_userids(): array {
        $currentgroup = groups_get_activity_group($this->assignment->get_course_module(), true);
        $userids = [];
        foreach (array_keys($this->assignment->list_participants($currentgroup, true)) as $userid) {
            $submission = $this->assignment->get_user_submission($userid, false);
            if (!$submission || $submission->status !== ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
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
     * Render a whole page in the assignment's layout.
     *
     * @param string $title The heading.
     * @param string $body The page body.
     * @return string
     */
    protected function render_page(string $title, string $body): string {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/renderable.php');
        $renderer = $this->assignment->get_renderer();
        $header = new assign_header(
            $this->assignment->get_instance(),
            $this->assignment->get_context(),
            false,
            $this->assignment->get_course_module()->id,
            $title
        );
        return $renderer->render($header) . $body . $renderer->render_footer();
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

        $blocker = job_manager::assignment_blocker($this->assignment);
        $eligible = [];
        $skipped = [];
        if ($blocker === null) {
            foreach ($userids as $userid) {
                $reason = job_manager::user_blocker($this->assignment, $userid);
                if ($reason === null) {
                    $eligible[] = $userid;
                } else {
                    $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
                }
            }
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
            'accounterror' => $accounterror !== '' ? s($accounterror) : '',
            'count' => $count,
            'requested' => count($userids),
            'skipped' => $reasons,
            'hasskipped' => (bool) $reasons,
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
            'cancelurl' => (new moodle_url('/mod/assign/view.php', [
                'id' => $this->assignment->get_course_module()->id,
                'action' => 'grading',
            ]))->out(false),
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
        $userids = array_filter(array_map('intval', explode(',', $users)));
        $result = job_manager::queue_batch($this->assignment, $userids, (int) $USER->id);
        redirect(
            $this->page_url('status'),
            get_string('queuedn', 'assignfeedback_proctorai', $result['queued']),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    /**
     * The status page: every student's latest job and result.
     *
     * @return string
     */
    protected function view_status(): string {
        global $DB, $OUTPUT;
        require_capability('assignfeedback/proctorai:viewdetails', $this->assignment->get_context());

        $instance = $this->assignment->get_instance();
        $currentgroup = groups_get_activity_group($this->assignment->get_course_module(), true);
        $participants = $this->assignment->list_participants($currentgroup, false);
        $jobs = $DB->get_records('assignfeedback_proctorai_job', ['assignment' => $instance->id], 'id ASC');
        $latest = [];
        foreach ($jobs as $job) {
            $latest[$job->userid] = $job;
        }
        $counts = [];
        $rows = [];
        foreach ($participants as $user) {
            $job = $latest[$user->id] ?? null;
            $status = $job ? $job->status : 'none';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            if (!$job) {
                continue;
            }
            $grade = $this->assignment->get_user_grade($user->id, false, $job->attempt);
            $result = $grade ? $this->get_result($grade->id) : false;
            $name = $this->assignment->is_blind_marking()
                ? get_string('hiddenuser', 'assign') . $this->assignment->get_uniqueid_for_user($user->id)
                : fullname($user);
            $rows[] = [
                'name' => $name,
                'status' => $status,
                'statuslabel' => get_string('status_' . $status, 'assignfeedback_proctorai'),
                'error' => $job->errormessage !== null && $status !== job_manager::STATUS_DONE ? s($job->errormessage) : '',
                'marks' => $result ? get_string('summaryai', 'assignfeedback_proctorai', (object) [
                    'earned' => format_float((float) $result->earned, 2, true, true),
                    'max' => format_float((float) $result->maxmarks, 2, true, true),
                    'percentage' => format_float((float) $result->percentage, 1, true, true),
                ]) : '',
                'reviewcount' => $result ? (int) $result->reviewcount : 0,
                'approved' => $result && $result->approved,
                'time' => userdate($job->timemodified, get_string('strftimedatetimeshort', 'core_langconfig')),
            ];
        }
        $summary = [];
        foreach ($counts as $status => $n) {
            $summary[] = ['label' => get_string('status_' . $status, 'assignfeedback_proctorai'), 'count' => $n];
        }

        $account = account::get(false);
        $context = [
            'rows' => $rows,
            'hasrows' => (bool) $rows,
            'summary' => $summary,
            'balance' => $account ? (int) ($account['sheetsRemaining'] ?? 0) : null,
            'hasbalance' => $account !== null,
            'paused' => account::is_paused(),
            'gradeallurl' => $this->can_request() ? $this->page_url('gradeall')->out(false) : '',
            'backurl' => (new moodle_url('/mod/assign/view.php', [
                'id' => $this->assignment->get_course_module()->id,
                'action' => 'grading',
            ]))->out(false),
        ];
        return $this->render_page(
            get_string('statuspage', 'assignfeedback_proctorai'),
            $OUTPUT->render_from_template('assignfeedback_proctorai/status', $context)
        );
    }
}
