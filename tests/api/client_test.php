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

use assignfeedback_proctorai\tests\fake_client;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fake_client.php');

/**
 * Tests for the API client: requests, and how every error status is mapped.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignfeedback_proctorai\api\client
 * @covers     \assignfeedback_proctorai\api\api_exception
 */
final class client_test extends \advanced_testcase {
    /**
     * Set a key and URL.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('apikey', 'pai_test_abc', 'assignfeedback_proctorai');
        set_config('apiurl', 'https://api.example.test/api/v1/', 'assignfeedback_proctorai');
        set_config('consent', 1, 'assignfeedback_proctorai');
    }

    /**
     * GET /account through Moodle's curl, mocked with \curl::mock_response().
     */
    public function test_get_account_with_curl_mock(): void {
        \curl::mock_response(json_encode([
            'balance' => 12, 'pricePerSheet' => 1, 'sheetsRemaining' => 12, 'maxPagesPerSheet' => 20,
            'acceptedTypes' => ['image/jpeg', 'application/pdf'], 'maxImageBytes' => 10485760, 'maxPdfBytes' => 31457280,
        ]));
        $account = (new client())->get_account();
        $this->assertSame(12, $account['sheetsRemaining']);
        $this->assertSame(['image/jpeg', 'application/pdf'], $account['acceptedTypes']);
    }

    /**
     * The grade call sends the key, the idempotency key and no student name.
     */
    public function test_grade_request_headers_and_body(): void {
        $client = new fake_client();
        $client->respond(200, ['id' => 'grd_1', 'marks' => ['earned' => 1, 'max' => 1, 'percentage' => 100]],
            ['idempotent-replayed' => 'true']);

        $result = $client->grade(['title' => 'T', 'studentName' => 'Sam', 'questions' => []], 'key-123');

        $this->assertTrue($result['replayed']);
        $this->assertSame('grd_1', $result['body']['id']);
        $request = $client->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api.example.test/api/v1/grade', $request['url']);
        $this->assertSame(client::GRADE_TIMEOUT, $request['timeout']);
        $headers = $client->request_headers(0);
        $this->assertSame('Bearer pai_test_abc', $headers['authorization']);
        $this->assertSame('key-123', $headers['idempotency-key']);
        $this->assertArrayNotHasKey('studentName', json_decode($request['payload'], true));
    }

    /**
     * File PUTs go to the presigned URL with its headers and without the API key.
     */
    public function test_put_file_has_no_api_key(): void {
        $this->setAdminUser();
        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id, 'component' => 'assignfeedback_proctorai',
            'filearea' => 'test', 'itemid' => 0, 'filepath' => '/', 'filename' => 'a.jpg',
        ], 'bytes');
        $client = new fake_client();
        $client->respond(200, '');
        $client->put_file(['url' => 'https://r2.example.test/upl?sig=1', 'headers' => ['Content-Type' => 'image/jpeg']], $file);

        $this->assertSame('PUT', $client->requests[0]['method']);
        $this->assertSame('bytes', $client->requests[0]['payload']);
        $headers = $client->request_headers(0);
        $this->assertSame('image/jpeg', $headers['content-type']);
        $this->assertArrayNotHasKey('authorization', $headers);
    }

    /**
     * Every documented error maps to an api_exception with the right code, retry hint and transience.
     *
     * @dataProvider error_responses
     * @param int $status HTTP status.
     * @param string $body Response body.
     * @param array $headers Response headers.
     * @param string $code Expected API code.
     * @param int|null $retryafter Expected Retry-After.
     * @param bool $transient Expected transience.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('error_responses')]
    public function test_error_mapping(int $status, string $body, array $headers, string $code, ?int $retryafter,
            bool $transient): void {
        try {
            client::parse_response(['status' => $status, 'headers' => $headers, 'body' => $body, 'error' => 'timeout']);
            $this->fail('No exception');
        } catch (api_exception $e) {
            $this->assertSame($status === 200 ? 502 : $status, $e->httpstatus);
            $this->assertSame($code, $e->apicode);
            $this->assertSame($retryafter, $e->retryafter);
            $this->assertSame($transient, $e->is_transient());
        }
    }

    /**
     * Error responses.
     *
     * @return array
     */
    public static function error_responses(): array {
        $err = fn(string $code, array $extra = []) => json_encode(['error' => ['code' => $code, 'message' => 'm'] + $extra]);
        return [
            '400' => [400, $err('invalid_request'), [], 'invalid_request', null, false],
            '401' => [401, $err('invalid_api_key'), [], 'invalid_api_key', null, false],
            '402' => [402, $err('insufficient_balance', ['balance' => 0]), [], 'insufficient_balance', null, false],
            '409' => [409, $err('idempotency_in_progress'), ['retry-after' => '20'], 'idempotency_in_progress', 20, false],
            '422 unreadable' => [422, $err('unreadable_page'), [], 'unreadable_page', null, false],
            '422 pages' => [422, $err('too_many_pages'), [], 'too_many_pages', null, false],
            '422 upload' => [422, $err('upload_not_found'), [], 'upload_not_found', null, false],
            '422 mismatch' => [422, $err('idempotency_key_mismatch'), [], 'idempotency_key_mismatch', null, false],
            '429' => [429, $err('rate_limited'), ['retry-after' => '45'], 'rate_limited', 45, false],
            '502' => [502, $err('grading_failed'), [], 'grading_failed', null, true],
            '503' => [503, $err('unavailable'), [], 'unavailable', null, true],
            '504 html' => [504, '<html>Gateway timeout</html>', [], 'unavailable', null, true],
            'network' => [0, '', [], 'network_error', null, true],
            '200 not json' => [200, '<html></html>', [], 'bad_response', null, true],
        ];
    }

    /**
     * The details of a 402 (the balance) are kept.
     */
    public function test_error_details_kept(): void {
        try {
            client::parse_response(['status' => 402, 'headers' => [],
                'body' => json_encode(['error' => ['code' => 'insufficient_balance', 'message' => 'No', 'balance' => 0.5]])]);
            $this->fail('No exception');
        } catch (api_exception $e) {
            $this->assertSame(0.5, $e->details['balance']);
            $this->assertSame('No', $e->apimessage);
        }
    }
}
