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
 * Grades one queued submission with Proctor AI.
 *
 * Custom data: {jobid: int, run: int}. All state lives in the job record;
 * a throw from execute() makes Moodle rerun this task with backoff.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_submission extends \core\task\adhoc_task {
    /**
     * Task name shown in the admin task list.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskgradesubmission', 'assignfeedback_proctorai');
    }

    /**
     * Run the job.
     */
    public function execute() {
        $data = $this->get_custom_data();
        if (empty($data->jobid)) {
            return;
        }
        job_manager::run((int) $data->jobid);
    }
}
