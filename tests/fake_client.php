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

namespace assignfeedback_proctorai\tests;

use assignfeedback_proctorai\api\client;

/**
 * A client with scripted HTTP responses, for status codes and headers that \curl::mock_response() cannot fake.
 *
 * Everything above the transport (JSON encoding, headers, error mapping) is the real client.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_client extends client {
    /** @var array Responses still to give, first in first out. */
    public array $responses = [];

    /** @var array Every request sent: method, url, payload, headers. */
    public array $requests = [];

    /**
     * Script the next response.
     *
     * @param int $status HTTP status, or 0 for a network error.
     * @param array|string $body The body; arrays are JSON-encoded.
     * @param array $headers Response headers, lower-case names.
     * @return self
     */
    public function respond(int $status, array|string $body = [], array $headers = []): self {
        $this->responses[] = [
            'status' => $status,
            'headers' => $headers,
            'body' => is_array($body) ? json_encode($body) : $body,
            'error' => $status === 0 ? 'Connection timed out' : '',
        ];
        return $this;
    }

    /**
     * The headers of a sent request as name => value.
     *
     * @param int $index Which request.
     * @return array
     */
    public function request_headers(int $index): array {
        $headers = [];
        foreach ($this->requests[$index]['headers'] as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return $headers;
    }

    /**
     * Record the request and return the next scripted response.
     *
     * @param string $method HTTP method.
     * @param string $url URL.
     * @param string|null $payload Body.
     * @param array $headers Headers.
     * @param int $timeout Timeout.
     * @return array
     */
    protected function send(string $method, string $url, ?string $payload, array $headers, int $timeout): array {
        $this->requests[] = compact('method', 'url', 'payload', 'headers', 'timeout');
        if (!$this->responses) {
            throw new \coding_exception('fake_client: no response scripted for ' . $method . ' ' . $url);
        }
        return array_shift($this->responses);
    }
}
