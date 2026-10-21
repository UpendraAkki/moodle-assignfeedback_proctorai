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

use assignfeedback_proctorai\api\api_exception;
use assignfeedback_proctorai\api\client;
use assignfeedback_proctorai\task\grade_submission;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Queues grading jobs and runs them: upload, grade, store, and react to every API error.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class job_manager {
    /** @var string Waiting for the adhoc task. */
    const STATUS_QUEUED = 'queued';
    /** @var string Sending the files. */
    const STATUS_UPLOADING = 'uploading';
    /** @var string Waiting for POST /grade. */
    const STATUS_GRADING = 'grading';
    /** @var string The result is stored. */
    const STATUS_DONE = 'done';
    /** @var string Stopped for good; see errorcode. */
    const STATUS_FAILED = 'failed';
    /** @var string Stopped because the account balance ran out. */
    const STATUS_NOBALANCE = 'nobalance';
    /** @var string Stopped because the assignment or plugin went away. */
    const STATUS_CANCELLED = 'cancelled';
    /** @var string Stopped because the student changed the submission after it was queued. */
    const STATUS_STALE = 'stale';

    /** @var string[] Statuses of a job that is still going. */
    const ACTIVE = [self::STATUS_QUEUED, self::STATUS_UPLOADING, self::STATUS_GRADING];

    /** @var string[] Error codes that the admin fixes in settings; changing the settings requeues them. */
    const PAUSE_ERRORS = ['invalid_api_key', 'paused', 'notconfigured'];

    /** @var string[] Error codes that mean the uploads expired or never landed: worth one fresh upload. */
    const UPLOAD_ERRORS = ['upload_not_found', 'upload_incomplete', 'upload_failed'];

    /** @var int Seconds an upload-triggered job waits for more uploads before it starts. */
    const DEBOUNCE = 10 * MINSECS;

    /** @var int Seconds to wait after a 409 or 429 that sent no Retry-After. */
    const DEFAULT_RETRY_AFTER = 30;

    /** @var string Queued by a teacher for one or a few students. */
    const ORIGIN_MANUAL = 'manual';
    /** @var string Queued from a batch operation or "Grade all ungraded". */
    const ORIGIN_BATCH = 'batch';
    /** @var string Queued by a student's submission. */
    const ORIGIN_AUTO = 'auto';

    /**
     * Why this assignment cannot use Proctor AI right now, or null if it can.
     *
     * @param \assign $assign The assignment.
     * @return string|null A string identifier in this plugin's language file.
     */
    public static function assignment_blocker(\assign $assign): ?string {
        if (!account::is_configured()) {
            return 'notconfigured';
        }
        if (account::is_paused()) {
            return 'paused';
        }
        $plugin = $assign->get_feedback_plugin_by_type('proctorai');
        if (!$plugin || !$plugin->is_enabled() || !$plugin->is_visible()) {
            return 'notenabled';
        }
        if (!empty($assign->get_instance()->teamsubmission)) {
            return 'teamnotsupported';
        }
        if (trim((string) $plugin->get_config('markingkey')) === '') {
            return 'markingkeymissing';
        }
        if ((string) $plugin->get_config('markingkeyerror') !== '') {
            return 'markingkeyerror';
        }
        return null;
    }

    /**
     * Why this student's submission cannot be queued, or null if it can.
     *
     * @param \assign $assign The assignment.
     * @param int $userid The student.
     * @return string|null A string identifier in this plugin's language file.
     */
    public static function user_blocker(\assign $assign, int $userid): ?string {
        global $DB;
        $submission = $assign->get_user_submission($userid, false);
        if (!$submission || $submission->status !== ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
            return 'notsubmitted';
        }
        $active = $DB->record_exists_select('assignfeedback_proctorai_job',
            'assignment = :assignment AND submission = :submission AND attempt = :attempt AND status IN (:q, :u, :g)', [
                'assignment' => $assign->get_instance()->id,
                'submission' => $submission->id,
                'attempt' => $submission->attemptnumber,
                'q' => self::STATUS_QUEUED,
                'u' => self::STATUS_UPLOADING,
                'g' => self::STATUS_GRADING,
            ]);
        if ($active) {
            return 'alreadyqueued';
        }
        $collected = submission_collector::collect($assign, $submission);
        if (!$collected->files && $collected->text === '') {
            return 'nothingtograde';
        }
        if (count($collected->files) > submission_collector::MAX_FILES) {
            return 'toomanyfiles';
        }
        return null;
    }

    /**
     * Queue one student's latest submission for grading.
     *
     * @param \assign $assign The assignment.
     * @param int $userid The student.
     * @param string $origin One of the ORIGIN_ constants.
     * @param int $requestedby The user asking, or 0 for automatic grading.
     * @param string|null $batchid Groups jobs for the completion notice.
     * @param int $delay Seconds before the job may start.
     * @return \stdClass|null The job, or null if it could not be queued.
     */
    public static function queue(\assign $assign, int $userid, string $origin, int $requestedby = 0,
            ?string $batchid = null, int $delay = 0): ?\stdClass {
        global $DB;

        if (self::assignment_blocker($assign) !== null) {
            return null;
        }
        $submission = $assign->get_user_submission($userid, false);
        if (!$submission || $submission->status !== ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
            return null;
        }
        $collected = submission_collector::collect($assign, $submission);
        if (!$collected->files && $collected->text === '') {
            return null;
        }

        $assignmentid = $assign->get_instance()->id;
        $existing = $DB->get_records_select('assignfeedback_proctorai_job',
            'assignment = :assignment AND submission = :submission AND attempt = :attempt',
            ['assignment' => $assignmentid, 'submission' => $submission->id, 'attempt' => $submission->attemptnumber],
            'id DESC');
        $last = $existing ? reset($existing) : null;
        if ($last && $last->status === self::STATUS_QUEUED && $last->timestarted === null) {
            // Not started yet: fold this request into it. This is the upload debounce.
            $last->submissionhash = $collected->hash;
            $last->timeready = max((int) $last->timeready, time() + $delay);
            $last->timemodified = time();
            $DB->update_record('assignfeedback_proctorai_job', $last);
            return $last;
        }
        if ($last && in_array($last->status, self::ACTIVE, true)) {
            // Running now. If the content changed, the job ends as stale and the next event queues again.
            return $last;
        }
        if ($last && $last->status === self::STATUS_DONE && $origin === self::ORIGIN_AUTO) {
            if ($last->submissionhash === $collected->hash || !get_config('assignfeedback_proctorai', 'regradeonresubmit')) {
                // Already graded this content, or the admin turned off re-grading on resubmission.
                return null;
            }
        }

        if ($origin === self::ORIGIN_AUTO) {
            $cap = (int) get_config('assignfeedback_proctorai', 'autogradecap');
            $count = $DB->count_records_select('assignfeedback_proctorai_job',
                'assignment = :assignment AND userid = :userid AND origin = :origin AND status <> :stale',
                ['assignment' => $assignmentid, 'userid' => $userid, 'origin' => self::ORIGIN_AUTO, 'stale' => self::STATUS_STALE]);
            if ($cap > 0 && $count >= $cap) {
                return null;
            }
        }

        $now = time();
        $job = (object) [
            'assignment' => $assignmentid,
            'userid' => $userid,
            'submission' => $submission->id,
            'attempt' => $submission->attemptnumber,
            'batchid' => $batchid,
            'status' => self::STATUS_QUEUED,
            'origin' => $origin,
            'requestedby' => $requestedby,
            'idempotencykey' => \core\uuid::generate(),
            'submissionhash' => $collected->hash,
            'attempts' => 0,
            'uploadretries' => 0,
            'timeready' => $now + $delay,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $job->id = $DB->insert_record('assignfeedback_proctorai_job', $job);
        $job->timestarted = null;
        self::schedule($job, $job->timeready);
        return $job;
    }

    /**
     * Queue several students. Students who cannot be queued are skipped.
     *
     * @param \assign $assign The assignment.
     * @param int[] $userids The students.
     * @param int $requestedby The teacher.
     * @return array ['queued' => int, 'skipped' => int, 'batchid' => string].
     */
    public static function queue_batch(\assign $assign, array $userids, int $requestedby): array {
        $batchid = \core\uuid::generate();
        $queued = 0;
        foreach (array_unique(array_map('intval', $userids)) as $userid) {
            if (self::user_blocker($assign, $userid) !== null) {
                continue;
            }
            if (self::queue($assign, $userid, self::ORIGIN_BATCH, $requestedby, $batchid)) {
                $queued++;
            }
        }
        return ['queued' => $queued, 'skipped' => count($userids) - $queued, 'batchid' => $batchid];
    }

    /**
     * Put a stopped job back in the queue, for example after the admin fixes the key.
     *
     * @param \stdClass $job The job.
     * @param bool $newkey Use a fresh idempotency key and drop the stored request (the old one cannot be replayed).
     */
    public static function requeue(\stdClass $job, bool $newkey = false): void {
        global $DB;
        $job->status = self::STATUS_QUEUED;
        $job->errorcode = null;
        $job->errormessage = null;
        $job->attempts = 0;
        $job->timestarted = null;
        $job->timecompleted = null;
        $job->timeready = time();
        $job->timemodified = time();
        if ($newkey) {
            $job->idempotencykey = \core\uuid::generate();
            $job->requestjson = null;
            $job->uploads = null;
        }
        $DB->update_record('assignfeedback_proctorai_job', $job);
        self::schedule($job, time());
    }

    /**
     * Queue the adhoc task that runs a job.
     *
     * @param \stdClass $job The job.
     * @param int $runat Earliest run time.
     */
    public static function schedule(\stdClass $job, int $runat): void {
        $task = new grade_submission();
        // The run counter keeps a rescheduled task distinct from the one currently running.
        $task->set_custom_data(['jobid' => (int) $job->id, 'run' => random_int(1, PHP_INT_MAX)]);
        $task->set_component('assignfeedback_proctorai');
        $task->set_next_run_time($runat);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Run one job. Called by the grade_submission adhoc task.
     *
     * Transient failures (5xx, network) are rethrown so Moodle retries the task with backoff;
     * the retry sends the same stored request with the same idempotency key, so it is charged once.
     *
     * @param int $jobid The job id.
     * @throws api_exception On a transient API failure below the attempt cap.
     */
    public static function run(int $jobid): void {
        global $DB;

        $lock = \core\lock\lock_config::get_lock_factory('assignfeedback_proctorai')->get_lock('job' . $jobid, 0);
        if (!$lock) {
            // Another cron process holds this job; check again shortly.
            $job = $DB->get_record('assignfeedback_proctorai_job', ['id' => $jobid]);
            if ($job && in_array($job->status, self::ACTIVE, true)) {
                self::schedule($job, time() + MINSECS);
            }
            return;
        }
        try {
            self::run_locked($jobid);
        } finally {
            $lock->release();
        }
    }

    /**
     * The body of run(), with the job lock held.
     *
     * @param int $jobid The job id.
     * @throws api_exception On a transient API failure below the attempt cap.
     */
    protected static function run_locked(int $jobid): void {
        global $DB;

        $job = $DB->get_record('assignfeedback_proctorai_job', ['id' => $jobid]);
        if (!$job || !in_array($job->status, self::ACTIVE, true)) {
            return;
        }
        if ((int) $job->timeready > time()) {
            // More uploads arrived since this task was queued (debounce); run when they stop.
            self::schedule($job, (int) $job->timeready);
            return;
        }

        if (!account::is_configured()) {
            self::finish($job, self::STATUS_FAILED, 'notconfigured', get_string('notconfigured', 'assignfeedback_proctorai'));
            return;
        }
        if (account::is_paused()) {
            self::finish($job, self::STATUS_FAILED, 'paused', get_string('paused', 'assignfeedback_proctorai'));
            return;
        }

        $assign = self::load_assign((int) $job->assignment);
        if (!$assign) {
            self::finish($job, self::STATUS_CANCELLED, 'assignmentdeleted', null);
            return;
        }
        $blocker = self::assignment_blocker($assign);
        if ($blocker === 'notenabled' || $blocker === 'teamnotsupported') {
            self::finish($job, self::STATUS_CANCELLED, $blocker, get_string($blocker, 'assignfeedback_proctorai'));
            return;
        }
        if ($blocker !== null) {
            self::finish($job, self::STATUS_FAILED, $blocker, get_string($blocker, 'assignfeedback_proctorai'));
            return;
        }

        $submission = $DB->get_record('assign_submission', ['id' => $job->submission]);
        $latest = $assign->get_user_submission($job->userid, false);
        if (!$submission || !$latest || (int) $latest->id !== (int) $submission->id
                || (int) $submission->attemptnumber !== (int) $job->attempt
                || $submission->status !== ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
            self::finish($job, self::STATUS_STALE, 'submissionchanged', null);
            return;
        }
        $collected = submission_collector::collect($assign, $submission);
        if ($collected->hash !== $job->submissionhash) {
            self::finish($job, self::STATUS_STALE, 'submissionchanged', null);
            return;
        }

        if (account::known_out_of_balance()) {
            self::finish($job, self::STATUS_NOBALANCE, 'insufficient_balance',
                get_string('nobalance', 'assignfeedback_proctorai'));
            account::notify_nobalance((int) $job->requestedby);
            return;
        }

        $maxattempts = max(1, (int) get_config('assignfeedback_proctorai', 'maxattempts'));
        $job->attempts = (int) $job->attempts + 1;
        $job->timestarted = $job->timestarted ?: time();
        if ($job->attempts > $maxattempts) {
            self::finish($job, self::STATUS_FAILED, 'toomanyattempts', get_string('toomanyattempts', 'assignfeedback_proctorai'));
            return;
        }
        self::set_status($job, self::STATUS_UPLOADING);

        try {
            $request = $job->requestjson ? json_decode($job->requestjson, true) : null;
            if (!$request) {
                $request = self::build_request($assign, $job, $collected);
                $job->requestjson = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $DB->update_record('assignfeedback_proctorai_job', $job);
            }

            self::set_status($job, self::STATUS_GRADING);
            $response = client::instance()->grade($request, $job->idempotencykey);
            $result = $response['body'];

            result_applier::apply($assign, $job, $result);
            $job->remoteid = isset($result['id']) ? substr((string) $result['id'], 0, 64) : null;
            if (isset($result['billing']['balance']) && is_numeric($result['billing']['balance'])) {
                account::record_balance((float) $result['billing']['balance']);
            }
            self::finish($job, self::STATUS_DONE, null, null);
        } catch (api_exception $e) {
            self::handle_error($job, $e, $maxattempts);
        } catch (\Throwable $e) {
            // Something on this site failed (database, file storage). Retry like a network error.
            if ($job->attempts >= $maxattempts) {
                self::finish($job, self::STATUS_FAILED, 'internal', $e->getMessage());
                return;
            }
            self::set_status($job, self::STATUS_QUEUED);
            throw $e;
        }
    }

    /**
     * Upload the files and build the POST /grade body.
     *
     * @param \assign $assign The assignment.
     * @param \stdClass $job The job.
     * @param \stdClass $collected From submission_collector::collect().
     * @return array The request body.
     * @throws api_exception
     */
    protected static function build_request(\assign $assign, \stdClass $job, \stdClass $collected): array {
        global $DB;

        if (count($collected->files) > submission_collector::MAX_FILES) {
            throw new api_exception(422, 'toomanyfiles', get_string('toomanyfiles', 'assignfeedback_proctorai'));
        }
        $plugin = $assign->get_feedback_plugin_by_type('proctorai');
        $key = marking_key::parse((string) $plugin->get_config('markingkey'));

        $fileids = [];
        if ($collected->files) {
            $meta = [];
            foreach ($collected->files as $index => $file) {
                // Files are named by position only, so no student file name leaves the site.
                $meta[] = [
                    'contentType' => submission_collector::mimetype($file),
                    'size' => (int) $file->get_filesize(),
                    'name' => 'page-' . ($index + 1),
                ];
            }
            $client = client::instance();
            $created = $client->create_uploads($meta);
            foreach ($created['uploads'] as $index => $upload) {
                $client->put_file($upload, $collected->files[$index]);
                $fileids[] = ['uploadId' => (string) $upload['uploadId']];
            }
            $job->uploads = json_encode(array_column($fileids, 'uploadId'));
            $DB->update_record('assignfeedback_proctorai_job', $job);
        }

        $instance = $assign->get_instance();
        $request = [
            'title' => \core_text::substr(format_string($instance->name, true, ['context' => $assign->get_context()]), 0, 200),
            'questions' => $key->questions,
        ];
        $level = trim((string) $plugin->get_config('level'));
        if ($level !== '') {
            $request['level'] = \core_text::substr($level, 0, 80);
        }
        $instructions = trim((string) $plugin->get_config('instructions'));
        if ($instructions !== '') {
            $request['instructions'] = \core_text::substr($instructions, 0, 2000);
        }
        if ($fileids) {
            $request['files'] = $fileids;
        }
        if ($collected->text !== '') {
            $request['answerSheetText'] = $collected->text;
        }
        return $request;
    }

    /**
     * React to an API error, following the plan's error table.
     *
     * @param \stdClass $job The job.
     * @param api_exception $e The error.
     * @param int $maxattempts The attempt cap.
     * @throws api_exception When Moodle should retry the task.
     */
    protected static function handle_error(\stdClass $job, api_exception $e, int $maxattempts): void {
        global $DB;

        $status = $e->httpstatus;
        $message = $e->apimessage;

        if ($status === 401) {
            account::pause($message);
            self::finish($job, self::STATUS_FAILED, 'invalid_api_key', $message);
            return;
        }

        if ($status === 402) {
            if (isset($e->details['balance']) && is_numeric($e->details['balance'])) {
                account::record_balance((float) $e->details['balance']);
            }
            self::finish($job, self::STATUS_NOBALANCE, $e->apicode, $message);
            account::notify_nobalance((int) $job->requestedby);
            return;
        }

        if ($status === 409 || $status === 429) {
            // Not this job's fault: wait as asked and try again, without using up an attempt.
            $job->attempts = max(0, (int) $job->attempts - 1);
            $job->status = self::STATUS_QUEUED;
            $job->errorcode = $e->apicode;
            $job->errormessage = $message;
            $job->timemodified = time();
            $DB->update_record('assignfeedback_proctorai_job', $job);
            self::schedule($job, time() + ($e->retryafter ?? self::DEFAULT_RETRY_AFTER));
            return;
        }

        if (in_array($e->apicode, self::UPLOAD_ERRORS, true) && (int) $job->uploadretries < 1) {
            // The uploads expired or never landed: upload once more under a new key (nothing was charged).
            $job->uploadretries = (int) $job->uploadretries + 1;
            $DB->update_record('assignfeedback_proctorai_job', $job);
            self::requeue($job, true);
            return;
        }

        if ($e->is_transient()) {
            if ((int) $job->attempts >= $maxattempts) {
                self::finish($job, self::STATUS_FAILED, $e->apicode, $message);
                return;
            }
            // Leave the stored request and key in place; Moodle reruns this task with backoff.
            $job->status = self::STATUS_QUEUED;
            $job->errorcode = $e->apicode;
            $job->errormessage = $message;
            $job->timemodified = time();
            $DB->update_record('assignfeedback_proctorai_job', $job);
            throw $e;
        }

        // 400, 422 and anything else: the request itself is wrong. Show the teacher why.
        self::finish($job, self::STATUS_FAILED, $e->apicode, $message);
    }

    /**
     * Set a job's status while it runs.
     *
     * @param \stdClass $job The job.
     * @param string $status The new status.
     */
    protected static function set_status(\stdClass $job, string $status): void {
        global $DB;
        $job->status = $status;
        $job->timemodified = time();
        $DB->update_record('assignfeedback_proctorai_job', $job);
    }

    /**
     * Put a job in an end state, and send the batch notice if it was the last of its batch.
     *
     * @param \stdClass $job The job.
     * @param string $status The end status.
     * @param string|null $errorcode Why, if it did not finish.
     * @param string|null $errormessage Message for the teacher.
     */
    protected static function finish(\stdClass $job, string $status, ?string $errorcode, ?string $errormessage): void {
        global $DB;
        $job->status = $status;
        $job->errorcode = $errorcode;
        $job->errormessage = $errormessage !== null ? shorten_text($errormessage, 1000) : null;
        $job->timecompleted = time();
        $job->timemodified = time();
        if ($status === self::STATUS_DONE || $status === self::STATUS_STALE || $status === self::STATUS_CANCELLED) {
            // The request holds the student's text; it is only needed while the job can still be retried.
            $job->requestjson = null;
        }
        $DB->update_record('assignfeedback_proctorai_job', $job);
        self::maybe_notify_batch($job);
    }

    /**
     * Tell the teacher when every job in a batch has ended.
     *
     * @param \stdClass $job The job that just ended.
     */
    protected static function maybe_notify_batch(\stdClass $job): void {
        global $DB;
        if (empty($job->batchid) || empty($job->requestedby)) {
            return;
        }
        $counts = $DB->get_records_sql_menu(
            'SELECT status, COUNT(1) FROM {assignfeedback_proctorai_job} WHERE batchid = :batchid GROUP BY status',
            ['batchid' => $job->batchid]);
        foreach (self::ACTIVE as $active) {
            if (!empty($counts[$active])) {
                return;
            }
        }
        $teacher = \core_user::get_user($job->requestedby);
        $assign = self::load_assign((int) $job->assignment);
        if (!$teacher || !$assign) {
            return;
        }
        $url = new \moodle_url('/mod/assign/view.php', [
            'id' => $assign->get_course_module()->id,
            'action' => 'viewpluginpage',
            'plugin' => 'proctorai',
            'pluginsubtype' => 'assignfeedback',
            'pluginaction' => 'status',
        ]);
        $a = (object) [
            'name' => format_string($assign->get_instance()->name, true, ['context' => $assign->get_context()]),
            'done' => (int) ($counts[self::STATUS_DONE] ?? 0),
            'failed' => array_sum($counts) - (int) ($counts[self::STATUS_DONE] ?? 0),
            'url' => $url->out(false),
        ];
        notifier::send('batchcomplete', $teacher, get_string('msgbatchsubject', 'assignfeedback_proctorai', $a),
            get_string('msgbatchbody', 'assignfeedback_proctorai', $a), $url);
    }

    /**
     * Load an assignment by instance id.
     *
     * @param int $assignmentid The assign id.
     * @return \assign|null
     */
    public static function load_assign(int $assignmentid): ?\assign {
        $cm = get_coursemodule_from_instance('assign', $assignmentid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return null;
        }
        $context = \context_module::instance($cm->id, IGNORE_MISSING);
        if (!$context) {
            return null;
        }
        return new \assign($context, $cm, null);
    }

    /**
     * The latest job for a student's current attempt.
     *
     * @param int $assignmentid The assign id.
     * @param int $userid The student.
     * @return \stdClass|null
     */
    public static function latest_job(int $assignmentid, int $userid): ?\stdClass {
        global $DB;
        $jobs = $DB->get_records('assignfeedback_proctorai_job', ['assignment' => $assignmentid, 'userid' => $userid],
            'id DESC', '*', 0, 1);
        return $jobs ? reset($jobs) : null;
    }
}
