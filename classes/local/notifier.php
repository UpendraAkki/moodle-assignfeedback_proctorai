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

/**
 * Sends this plugin's notifications through the Moodle message API.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notifier {
    /**
     * Send one notification.
     *
     * @param string $provider A provider from db/messages.php.
     * @param \stdClass $userto The recipient.
     * @param string $subject Plain-text subject.
     * @param string $body Plain-text body.
     * @param \moodle_url|null $url A link to act on it.
     * @return bool Whether it was sent.
     */
    public static function send(
        string $provider,
        \stdClass $userto,
        string $subject,
        string $body,
        ?\moodle_url $url = null
    ): bool {
        $message = new \core\message\message();
        $message->component = 'assignfeedback_proctorai';
        $message->name = $provider;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $userto;
        $message->subject = $subject;
        $message->fullmessage = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = text_to_html(s($body), false, false, true);
        $message->smallmessage = $subject;
        $message->notification = 1;
        if ($url) {
            $message->contexturl = $url->out(false);
            $message->contexturlname = $subject;
        }
        try {
            return (bool) message_send($message);
        } catch (\Throwable $e) {
            // A broken message processor must never fail the grading task.
            debugging('Proctor AI notification failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }
}
