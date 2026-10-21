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

use assignfeedback_proctorai\api\api_exception;
use assignfeedback_proctorai\local\account;

/**
 * Refreshes the cached Proctor AI balance and warns admins when it runs low.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh_balance extends \core\task\scheduled_task {
    /**
     * Task name shown in the admin task list.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskrefreshbalance', 'assignfeedback_proctorai');
    }

    /**
     * Refresh the balance.
     */
    public function execute() {
        if (!account::is_ready()) {
            return;
        }
        try {
            $account = account::refresh();
        } catch (api_exception $e) {
            // A 401 has already paused the plugin; anything else is tried again next hour.
            mtrace('Proctor AI: could not refresh the balance: ' . $e->apimessage);
            return;
        }
        mtrace('Proctor AI: ' . (int) ($account['sheetsRemaining'] ?? 0) . ' sheets remaining.');
        account::check_low_balance($account);
    }
}
