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
 * Backup of Proctor AI results.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Backs up one Proctor AI result per grade.
 *
 * Grading jobs are not backed up: they are transient and refer to API uploads that expire.
 * The marking key is assignment plugin config, which mod_assign backs up itself.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_assignfeedback_proctorai_subplugin extends backup_subplugin {
    /**
     * Structure under each grade element.
     *
     * @return backup_subplugin_element
     */
    protected function define_grade_subplugin_structure() {
        $subplugin = $this->get_subplugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $element = new backup_nested_element('feedback_proctorai', null, [
            'grade',
            'earned',
            'maxmarks',
            'percentage',
            'suggestedgrade',
            'appliedgrade',
            'needsreview',
            'reviewcount',
            'resultjson',
            'feedbacktext',
            'feedbackformat',
            'approved',
            'approvedby',
            'timeapproved',
            'timecreated',
            'timemodified',
        ]);

        $subplugin->add_child($wrapper);
        $wrapper->add_child($element);

        $element->set_source_table('assignfeedback_proctorai', ['grade' => backup::VAR_PARENTID]);
        $element->annotate_ids('user', 'approvedby');

        return $subplugin;
    }
}
