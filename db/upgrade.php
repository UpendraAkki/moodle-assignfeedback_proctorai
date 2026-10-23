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
 * Upgrade steps for the Proctor AI feedback plugin.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Run the upgrade steps between the installed version and this one.
 *
 * @param int $oldversion The version being upgraded from.
 * @return bool
 */
function xmldb_assignfeedback_proctorai_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026102300) {
        // Remember that a batch's completion notice was sent, so it is sent only once.
        $table = new xmldb_table('assignfeedback_proctorai_job');
        $field = new xmldb_field('batchnotified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'uploadretries');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Batches that already ended had their notice sent.
        $DB->execute("UPDATE {assignfeedback_proctorai_job}
                         SET batchnotified = 1
                       WHERE batchid IS NOT NULL AND status NOT IN ('queued', 'uploading', 'grading')");

        upgrade_plugin_savepoint(true, 2026102300, 'assignfeedback', 'proctorai');
    }

    return true;
}
