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

use assignfeedback_proctorai\local\account;
use assignfeedback_proctorai\local\job_manager;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Queues automatic grading when students submit.
 *
 * With submission drafts on, the "submit for grading" click is final, so grading is queued at once.
 * With drafts off, every save is a submission and students often upload page by page, so grading
 * waits until no upload has arrived for 10 minutes.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A submission was submitted for grading (or saved, with drafts off).
     *
     * @param \mod_assign\event\assessable_submitted $event The event.
     */
    public static function assessable_submitted(\mod_assign\event\assessable_submitted $event): void {
        self::handle($event, false);
    }

    /**
     * A file or online text was saved to a submission.
     *
     * @param \core\event\base $event An assignsubmission_file or assignsubmission_onlinetext assessable_uploaded event.
     */
    public static function assessable_uploaded(\core\event\base $event): void {
        self::handle($event, true);
    }

    /**
     * Queue auto-grading for the event's submission when the assignment asks for it.
     *
     * @param \core\event\base $event The event; objectid is the assign_submission id.
     * @param bool $upload Whether this is an upload event (only acted on with drafts off).
     */
    protected static function handle(\core\event\base $event, bool $upload): void {
        global $DB;

        // While paused, still queue: the job waits as "paused" and resuming sends it.
        if (!account::is_configured() || $event->contextlevel != CONTEXT_MODULE) {
            return;
        }
        $submission = $DB->get_record('assign_submission', ['id' => $event->objectid]);
        if (!$submission) {
            return;
        }
        // A group submission has no userid; queue it for the member who submitted.
        $userid = (int) ($submission->userid ?: ($event->relateduserid ?: $event->userid));
        if (!$userid) {
            return;
        }
        $cm = get_coursemodule_from_id('assign', $event->contextinstanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $assign = new \assign(\context_module::instance($cm->id), $cm, null);
        $plugin = $assign->get_feedback_plugin_by_type('proctorai');
        if (!$plugin || !$plugin->is_enabled() || !$plugin->get_config('autograde')) {
            return;
        }

        $drafts = !empty($assign->get_instance()->submissiondrafts);
        if ($drafts && $upload) {
            // With drafts on, wait for the student to press "Submit for grading".
            return;
        }
        $delay = $drafts ? 0 : job_manager::DEBOUNCE;
        job_manager::queue($assign, $userid, job_manager::ORIGIN_AUTO, 0, null, $delay);
    }
}
