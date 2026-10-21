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

namespace assignfeedback_proctorai\api;

/**
 * An error response (or no response) from the Proctor AI API.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_exception extends \moodle_exception {
    /** @var int HTTP status, or 0 when the request never got a response. */
    public readonly int $httpstatus;

    /** @var string The API's error code, such as insufficient_balance, or network_error. */
    public readonly string $apicode;

    /** @var string The API's human-readable message. */
    public readonly string $apimessage;

    /** @var int|null Seconds from the Retry-After header, when sent. */
    public readonly ?int $retryafter;

    /** @var array The decoded error object, for extra fields such as balance. */
    public readonly array $details;

    /**
     * Constructor.
     *
     * @param int $httpstatus HTTP status, or 0 for a network error.
     * @param string $apicode The API error code.
     * @param string $apimessage The API error message.
     * @param int|null $retryafter Seconds to wait before retrying, if the API said.
     * @param array $details The full decoded error object.
     */
    public function __construct(int $httpstatus, string $apicode, string $apimessage, ?int $retryafter = null,
            array $details = []) {
        $this->httpstatus = $httpstatus;
        $this->apicode = $apicode;
        $this->apimessage = $apimessage;
        $this->retryafter = $retryafter;
        $this->details = $details;
        parent::__construct('apierror', 'assignfeedback_proctorai', '', (object) [
            'status' => $httpstatus,
            'code' => $apicode,
            'message' => $apimessage,
        ]);
    }

    /**
     * Whether retrying the same request later may succeed (server or network trouble).
     *
     * @return bool
     */
    public function is_transient(): bool {
        return $this->httpstatus === 0 || $this->httpstatus >= 500;
    }
}
