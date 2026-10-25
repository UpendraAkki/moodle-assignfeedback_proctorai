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
 * A fake Proctor AI API for Behat, served by the Behat test site itself so tests never use the network.
 *
 * Routes (the plugin's API URL is set to this file with "?route="):
 *   GET  /account  balance from config assignfeedback_proctorai/fakeapi_balance (default 100)
 *   POST /uploads  presigned PUT URLs that point back here
 *   PUT  /put      accepts the bytes
 *   POST /grade    behaves like a pai_test_ key: full marks on rows 1, 3, 5..., half marks and
 *                  needsReview on rows 2, 4...; or an error if fakeapi_next is "401", "402" or "422"
 *
 * Only a key starting with pai_test_ is accepted. Refuses to run outside a Behat site.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing -- An API stand-in: authenticated by its API key, like the real one.
define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../../../../../config.php');

if (!defined('BEHAT_SITE_RUNNING')) {
    http_response_code(404);
    die();
}

/**
 * Send a JSON response and stop.
 *
 * @param int $status HTTP status.
 * @param array $body The body.
 */
function assignfeedback_proctorai_fakeapi_send(int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    die();
}

/**
 * The marks for one row, as a pai_test_ key gives them.
 *
 * @param int $row The 1-based row number.
 * @param float $max The row's maximum.
 * @return array [earned, needsReview]
 */
function assignfeedback_proctorai_fakeapi_row(int $row, float $max): array {
    return $row % 2 === 1 ? [$max, false] : [round($max / 2, 2), true];
}

$route = optional_param('route', '', PARAM_RAW);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($route === '/put') {
    file_get_contents('php://input');
    http_response_code(200);
    die();
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth === '' && function_exists('getallheaders')) {
    $headers = array_change_key_case(getallheaders());
    $auth = $headers['authorization'] ?? '';
}
$next = (string) get_config('assignfeedback_proctorai', 'fakeapi_next');
if (strpos($auth, 'Bearer pai_test_') !== 0 || ($next === '401' && $route === '/grade')) {
    assignfeedback_proctorai_fakeapi_send(401, ['error' => ['code' => 'invalid_api_key', 'message' => 'The API key was revoked.']]);
}

$balance = get_config('assignfeedback_proctorai', 'fakeapi_balance');
$balance = $balance === false || $balance === '' ? 100 : (int) $balance;

if ($route === '/account') {
    assignfeedback_proctorai_fakeapi_send(200, [
        'balance' => $balance,
        'pricePerSheet' => 1,
        'sheetsRemaining' => $balance,
        'maxPagesPerSheet' => 20,
        'acceptedTypes' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'application/pdf'],
        'maxImageBytes' => 10485760,
        'maxPdfBytes' => 31457280,
    ]);
}

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];

if ($route === '/uploads' && $method === 'POST') {
    $uploads = [];
    foreach ((array) ($body['files'] ?? []) as $i => $file) {
        $url = new moodle_url('/mod/assign/feedback/proctorai/tests/fixtures/fakeapi.php', ['route' => '/put', 'n' => $i]);
        $uploads[] = [
            'uploadId' => 'upl_' . str_pad(dechex($i + 1), 24, '0', STR_PAD_LEFT),
            'method' => 'PUT',
            'url' => $url->out(false),
            'headers' => ['Content-Type' => (string) ($file['contentType'] ?? 'application/octet-stream')],
            'expiresAt' => gmdate('c', time() + 900),
        ];
    }
    assignfeedback_proctorai_fakeapi_send(200, ['uploads' => $uploads, 'retainedUntil' => gmdate('c', time() + 3 * DAYSECS)]);
}

if ($route === '/grade' && $method === 'POST') {
    if ($next === '402' || $balance < 1) {
        assignfeedback_proctorai_fakeapi_send(402, ['error' => [
            'code' => 'insufficient_balance',
            'message' => 'This call costs 1 gradings and the account has 0.',
            'balance' => 0,
            'price' => 1,
        ]]);
    }
    if ($next === '422') {
        assignfeedback_proctorai_fakeapi_send(422, ['error' => [
            'code' => 'too_many_pages',
            'message' => 'The answer sheet has more than 20 pages.',
        ]]);
    }
    $rows = [];
    $earned = 0.0;
    $max = 0.0;
    $n = 0;
    // "Read as" echoes the student's own typed sentences, one per row, so screenshots look real.
    $sentences = array_values(array_filter(array_map('trim', preg_split(
        '/(?<=[.!?])\s+/',
        (string) ($body['answerSheetText'] ?? '')
    ))));
    foreach ((array) ($body['questions'] ?? []) as $question) {
        $parts = $question['subQuestions'] ?? [$question + ['label' => null]];
        foreach ($parts as $part) {
            $n++;
            $partmax = (float) ($part['maxMarks'] ?? 1);
            [$mark, $review] = assignfeedback_proctorai_fakeapi_row($n, $partmax);
            $earned += $mark;
            $max += $partmax;
            $rows[] = [
                'questionId' => (string) $question['id'],
                'subQuestionId' => isset($question['subQuestions']) ? (string) $part['id'] : null,
                'label' => $part['label'] ?? (string) $question['id'],
                'earned' => $mark,
                'max' => $partmax,
                'status' => 'answered',
                'feedback' => $review ? 'Partly right.' : 'Correct.',
                'reasoning' => $review
                    ? 'Gives part of the explanation, but some marking points are missing.'
                    : 'Matches the expected answer.',
                'studentAnswer' => $sentences[$n - 1] ?? ($part['expectedAnswer'] ?? 'Answer on page 1'),
                'pages' => [1],
                'confidence' => $review ? 0.4 : 0.95,
                'needsReview' => $review,
                'reviewReason' => $review ? 'Only some marking points are covered' : null,
            ];
        }
    }
    $remaining = $balance - 1;
    set_config('fakeapi_balance', $remaining, 'assignfeedback_proctorai');
    assignfeedback_proctorai_fakeapi_send(200, [
        'id' => 'grd_' . str_pad(dechex(time()), 24, '0', STR_PAD_LEFT),
        'studentName' => null,
        'rollNumber' => null,
        'marks' => ['earned' => $earned, 'max' => $max, 'percentage' => $max > 0 ? round($earned / $max * 100, 2) : 0],
        'questions' => $rows,
        'strengths' => ['Uses the correct key terms'],
        'improvements' => ['Explain each step of the process in more detail'],
        'overallFeedback' => 'A good attempt.',
        'pageCount' => count((array) ($body['files'] ?? $body['pages'] ?? [])),
        'billing' => ['gradingsCharged' => 1, 'balance' => $remaining],
    ]);
}

assignfeedback_proctorai_fakeapi_send(404, ['error' => ['code' => 'not_found', 'message' => 'No route ' . $route]]);
