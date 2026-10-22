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

namespace assignfeedback_proctorai\admin;

use assignfeedback_proctorai\local\account;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * The "Connection" heading on the settings page, with the connection and balance status.
 *
 * Moodle builds the whole admin tree on every admin settings page, so the status
 * (which may call GET /account) is only worked out when this heading is displayed.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_status extends \admin_setting_heading {
    /**
     * Show the heading with the current status as its description.
     *
     * @param mixed $data Unused.
     * @param string $query The admin search query, for highlighting.
     * @return string HTML.
     */
    public function output_html($data, $query = '') {
        $this->description = account::status_html();
        return parent::output_html($data, $query);
    }
}
