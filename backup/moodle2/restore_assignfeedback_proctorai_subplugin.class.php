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
 * Restore of Proctor AI results.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores Proctor AI results, mapping grades and the approving teacher.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_assignfeedback_proctorai_subplugin extends restore_subplugin {
    /**
     * Paths under each grade element.
     *
     * @return restore_path_element[]
     */
    protected function define_grade_subplugin_structure() {
        $paths = [];
        $paths[] = new restore_path_element($this->get_namefor('grade'), $this->get_pathfor('/feedback_proctorai'));
        return $paths;
    }

    /**
     * Restore one result.
     *
     * @param array $data The backed-up record.
     */
    public function process_assignfeedback_proctorai_grade($data) {
        global $DB;

        $data = (object) $data;
        $data->assignment = $this->get_new_parentid('assign');
        // The grade mapping is set by mod_assign when it restores the grade element.
        $data->grade = $this->get_mappingid('grade', $data->grade);
        if (!$data->grade) {
            return;
        }
        $data->jobid = null;
        if (!empty($data->approvedby)) {
            $data->approvedby = $this->get_mappingid('user', $data->approvedby) ?: null;
        } else {
            $data->approvedby = null;
        }
        $DB->insert_record('assignfeedback_proctorai', $data);
    }
}
