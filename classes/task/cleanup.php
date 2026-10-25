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

namespace assignfeedback_proctorai\task;

use assignfeedback_proctorai\local\job_manager;

/**
 * Daily housekeeping for grading jobs.
 *
 * - Drops stored requests (which hold student text) once they can no longer be replayed.
 * - Requeues jobs left mid-flight by a crashed cron process; the same idempotency key makes this safe.
 * - Deletes ended jobs after 90 days. Results live in the other table and are kept.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup extends \core\task\scheduled_task {
    /** @var int Uploads and idempotency records live this long on the API. */
    const API_RETENTION = 72 * HOURSECS;

    /** @var int A job in uploading or grading for this long has lost its process. */
    const STUCK_AFTER = HOURSECS;

    /** @var int Ended jobs are deleted after this long. */
    const KEEP_JOBS = 90 * DAYSECS;

    /**
     * Task name shown in the admin task list.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskcleanup', 'assignfeedback_proctorai');
    }

    /**
     * Clean up.
     */
    public function execute() {
        global $DB;
        $now = time();

        [$activesql, $params] = $DB->get_in_or_equal(job_manager::ACTIVE, SQL_PARAMS_NAMED, 'st', false);
        $params['cutoff'] = $now - self::API_RETENTION;
        $DB->set_field_select(
            'assignfeedback_proctorai_job',
            'requestjson',
            null,
            "requestjson IS NOT NULL AND status $activesql AND timemodified < :cutoff",
            $params
        );

        $stuck = $DB->get_records_select(
            'assignfeedback_proctorai_job',
            'status IN (:uploading, :grading) AND timemodified < :cutoff',
            [
                'uploading' => job_manager::STATUS_UPLOADING,
                'grading' => job_manager::STATUS_GRADING,
                'cutoff' => $now - self::STUCK_AFTER,
            ]
        );
        $locks = \core\lock\lock_config::get_lock_factory('assignfeedback_proctorai');
        foreach ($stuck as $stale) {
            // A run holding the lock is not stuck, just slow; and the record must be read under the lock,
            // or a job that finished meanwhile would be put back in the queue and sent again.
            $lock = $locks->get_lock('job' . $stale->id, 0);
            if (!$lock) {
                continue;
            }
            try {
                $job = $DB->get_record('assignfeedback_proctorai_job', ['id' => $stale->id]);
                if (!$job || !in_array($job->status, [job_manager::STATUS_UPLOADING, job_manager::STATUS_GRADING], true)) {
                    continue;
                }
                // Older than the API keeps idempotency records: a retry could be charged again, so start clean.
                $expired = $job->timestarted && $job->timestarted < $now - self::API_RETENTION;
                mtrace("Proctor AI: requeueing stuck job {$job->id}.");
                $job->status = job_manager::STATUS_QUEUED;
                $DB->update_record('assignfeedback_proctorai_job', $job);
                if ($expired) {
                    job_manager::requeue($job, true);
                } else {
                    job_manager::schedule($job, $now);
                }
            } finally {
                $lock->release();
            }
        }

        [$endedsql, $params] = $DB->get_in_or_equal(job_manager::ACTIVE, SQL_PARAMS_NAMED, 'st', false);
        $params['cutoff'] = $now - self::KEEP_JOBS;
        $DB->delete_records_select('assignfeedback_proctorai_job', "status $endedsql AND timemodified < :cutoff", $params);
    }
}
