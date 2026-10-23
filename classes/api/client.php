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

use assignfeedback_proctorai\local\account;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * Thin client for the Proctor AI REST API (https://agent.proctorai.io/developers).
 *
 * Every request goes through Moodle's \curl, so the site's proxy settings and
 * blocked-host list apply. The key and URL are read from the plugin config on
 * each request, so one instance can live in the DI container.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client {
    /** @var int Seconds to wait for POST /grade. The API can take up to 300 s. */
    const GRADE_TIMEOUT = 330;

    /** @var int Seconds to wait for the small JSON calls. */
    const DEFAULT_TIMEOUT = 30;

    /** @var int Seconds to wait for one file PUT. */
    const UPLOAD_TIMEOUT = 120;

    /**
     * Get the client from the DI container, so tests can replace it.
     *
     * @return self
     */
    public static function instance(): self {
        return \core\di::get(self::class);
    }

    /**
     * GET /account: balance, price per sheet and upload limits.
     *
     * @param int $timeout Seconds to wait.
     * @return array The decoded response.
     * @throws api_exception
     */
    public function get_account(int $timeout = self::DEFAULT_TIMEOUT): array {
        return $this->call('GET', '/account', null, [], $timeout)['body'];
    }

    /**
     * POST /uploads: reserve one presigned PUT URL per file.
     *
     * @param array $files A list of ['contentType' => string, 'size' => int, 'name' => string].
     * @return array The decoded response, with 'uploads' in the same order as $files.
     * @throws api_exception
     */
    public function create_uploads(array $files): array {
        $body = $this->call('POST', '/uploads', ['files' => array_values($files)])['body'];
        if (empty($body['uploads']) || !is_array($body['uploads']) || count($body['uploads']) !== count($files)) {
            throw new api_exception(502, 'bad_response', 'POST /uploads returned the wrong number of uploads.');
        }
        return $body;
    }

    /**
     * PUT one file's bytes to the presigned URL that POST /uploads returned.
     *
     * The Authorization header is never sent here: the URL carries its own signature.
     *
     * @param array $upload One entry of the 'uploads' list.
     * @param \stored_file $file The file to send.
     * @throws api_exception
     */
    public function put_file(array $upload, \stored_file $file): void {
        $headers = [];
        foreach (($upload['headers'] ?? []) as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $response = $this->send('PUT', (string) $upload['url'], $file->get_content(), $headers, self::UPLOAD_TIMEOUT);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            // Storage errors are XML, not the API's JSON, so they are mapped here.
            $status = $response['status'] ?: 0;
            $code = $status === 0 ? 'network_error' : 'upload_failed';
            throw new api_exception(
                $status >= 500 || $status === 0 ? $status : 422,
                $code,
                $response['error'] ?: 'The file upload was rejected (HTTP ' . $status . ').'
            );
        }
    }

    /**
     * POST /grade: grade one answer sheet. Synchronous; can take up to 300 s.
     *
     * @param array $request The request body. Must never contain studentName.
     * @param string $idempotencykey A UUID that stays the same across retries of this job.
     * @return array ['body' => array, 'replayed' => bool].
     * @throws api_exception
     */
    public function grade(array $request, string $idempotencykey): array {
        unset($request['studentName']);
        $response = $this->call('POST', '/grade', $request, ['Idempotency-Key: ' . $idempotencykey], self::GRADE_TIMEOUT);
        return [
            'body' => $response['body'],
            'replayed' => strtolower((string) ($response['headers']['idempotent-replayed'] ?? '')) === 'true',
        ];
    }

    /**
     * Extra headers for API requests, from config.php only. For staging servers behind an access gate.
     *
     * Set in config.php as `$CFG->assignfeedback_proctorai_extraheaders = ['Name' => 'value'];`. There is
     * deliberately no admin setting. The headers go to the API URL only, never to the presigned upload
     * URLs, and they cannot replace the headers this client sets itself.
     *
     * @return string[] Headers as "Name: value" strings.
     */
    public static function extra_headers(): array {
        global $CFG;
        $configured = $CFG->assignfeedback_proctorai_extraheaders ?? [];
        if (!is_array($configured)) {
            return [];
        }
        $reserved = ['authorization', 'accept', 'content-type', 'content-length', 'idempotency-key', 'host'];
        $headers = [];
        foreach ($configured as $name => $value) {
            $name = trim((string) $name);
            $value = trim((string) $value);
            // Header names are tokens; values must not smuggle in a second header.
            // The RFC 9110 token characters; \x60 is the backtick.
            if (!preg_match('/^[A-Za-z0-9!#$%&\'*+.^_\x60|~-]+$/', $name) || preg_match('/[\r\n\0]/', $value)) {
                continue;
            }
            if (in_array(strtolower($name), $reserved, true)) {
                continue;
            }
            $headers[] = $name . ': ' . $value;
        }
        return $headers;
    }

    /**
     * Make one authenticated JSON request and map errors.
     *
     * @param string $method GET or POST.
     * @param string $path The path below the API URL, such as /account.
     * @param array|null $body The JSON body, or null.
     * @param array $headers Extra headers as "Name: value" strings.
     * @param int $timeout Seconds to wait.
     * @return array ['status' => int, 'headers' => array, 'body' => array].
     * @throws api_exception
     */
    protected function call(
        string $method,
        string $path,
        ?array $body,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT
    ): array {
        $apikey = trim((string) get_config('assignfeedback_proctorai', 'apikey'));
        if ($apikey === '') {
            throw new api_exception(401, 'invalid_api_key', 'No API key is set.');
        }
        $headers = array_merge($headers, self::extra_headers());
        $headers[] = 'Authorization: Bearer ' . $apikey;
        $headers[] = 'Accept: application/json';
        $payload = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $response = $this->send($method, account::api_url() . $path, $payload, $headers, $timeout);
        return self::parse_response($response);
    }

    /**
     * Turn a raw response into decoded JSON, or throw the matching api_exception.
     *
     * @param array $response ['status' => int, 'headers' => array, 'body' => string, 'error' => string].
     * @return array ['status' => int, 'headers' => array, 'body' => array].
     * @throws api_exception
     */
    public static function parse_response(array $response): array {
        $status = (int) $response['status'];
        $headers = $response['headers'] ?? [];
        if ($status === 0) {
            throw new api_exception(0, 'network_error', $response['error'] ?? 'No response from Proctor AI.');
        }
        $decoded = json_decode((string) $response['body'], true);
        if ($status >= 200 && $status < 300) {
            if (!is_array($decoded)) {
                // A 2xx that is not JSON is a proxy or gateway problem; worth retrying.
                throw new api_exception(502, 'bad_response', 'Proctor AI returned a response that is not JSON.');
            }
            return ['status' => $status, 'headers' => $headers, 'body' => $decoded];
        }
        $error = is_array($decoded) && isset($decoded['error']) && is_array($decoded['error']) ? $decoded['error'] : [];
        $code = (string) ($error['code'] ?? self::default_code($status));
        $message = (string) ($error['message'] ?? ('HTTP ' . $status));
        $retryafter = null;
        if (isset($headers['retry-after']) && is_numeric($headers['retry-after'])) {
            $retryafter = max(1, (int) $headers['retry-after']);
        }
        throw new api_exception($status, $code, $message, $retryafter, $error);
    }

    /**
     * The error code to assume when the body has none (an HTML error page from a proxy, say).
     *
     * @param int $status HTTP status.
     * @return string
     */
    protected static function default_code(int $status): string {
        return match (true) {
            $status === 401 => 'invalid_api_key',
            $status === 402 => 'insufficient_balance',
            $status === 409 => 'idempotency_in_progress',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'unavailable',
            default => 'invalid_request',
        };
    }

    /**
     * Send one HTTP request with Moodle's curl. Tests override this to script responses.
     *
     * @param string $method GET, POST or PUT.
     * @param string $url The full URL.
     * @param string|null $payload The raw body.
     * @param array $headers Headers as "Name: value" strings.
     * @param int $timeout Seconds to wait.
     * @return array ['status' => int, 'headers' => array (lower-case names), 'body' => string, 'error' => string].
     */
    protected function send(string $method, string $url, ?string $payload, array $headers, int $timeout): array {
        $curl = new \curl();
        $curl->setHeader($headers);
        $options = [
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_CONNECTTIMEOUT' => 20,
            'CURLOPT_RETURNTRANSFER' => true,
        ];
        $body = match ($method) {
            'GET' => $curl->get($url, [], $options),
            'POST' => $curl->post($url, (string) $payload, $options),
            'PUT' => $curl->put($url, (string) $payload, $options),
        };
        $info = $curl->get_info();
        $error = '';
        if ($curl->get_errno()) {
            $error = (string) $curl->error;
        }
        $status = $curl->get_errno() ? 0 : (int) ($info['http_code'] ?? 0);
        if ($status === 0 && $error === '') {
            // A host blocked by the site's curl security settings returns the reason as the body.
            $error = is_string($body) ? $body : 'Request failed.';
        }
        $responseheaders = [];
        foreach ((array) $curl->getResponse() as $name => $value) {
            $responseheaders[strtolower((string) $name)] = is_array($value) ? end($value) : $value;
        }
        return [
            'status' => $status,
            'headers' => $responseheaders,
            'body' => is_string($body) ? $body : '',
            'error' => $error,
        ];
    }
}
