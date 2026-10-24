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

use assignfeedback_proctorai\api\api_exception;
use assignfeedback_proctorai\api\client;

/**
 * Site-wide state: configuration, consent, pause, and the cached account balance.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class account {
    /** @var string The production API. */
    const DEFAULT_API_URL = 'https://agent.proctorai.io/api/v1';

    /** @var int Seconds the cached account response is trusted. */
    const CACHE_TTL = 300;

    /** @var int Seconds between "out of balance" notices. */
    const NOBALANCE_NOTIFY_INTERVAL = 6 * HOURSECS;

    /** @var int Seconds between "low balance" notices. */
    const LOWBALANCE_NOTIFY_INTERVAL = DAYSECS;

    /** @var string[] File types the API accepts if GET /account does not say. */
    const DEFAULT_ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/pdf'];

    /** @var int Largest image the API accepts if GET /account does not say. */
    const DEFAULT_MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    /** @var int Largest PDF the API accepts if GET /account does not say. */
    const DEFAULT_MAX_PDF_BYTES = 30 * 1024 * 1024;

    /**
     * The API base URL, without a trailing slash.
     *
     * @return string
     */
    public static function api_url(): string {
        $url = trim((string) get_config('assignfeedback_proctorai', 'apiurl'));
        return rtrim($url !== '' ? $url : self::DEFAULT_API_URL, '/');
    }

    /**
     * Whether an API key is set and the admin has agreed to send data to Proctor AI.
     *
     * Nothing is ever sent to Proctor AI unless this is true.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return trim((string) get_config('assignfeedback_proctorai', 'apikey')) !== ''
            && !empty(get_config('assignfeedback_proctorai', 'consent'));
    }

    /**
     * Whether the plugin paused itself after the API rejected the key.
     *
     * @return bool
     */
    public static function is_paused(): bool {
        return !empty(get_config('assignfeedback_proctorai', 'paused'));
    }

    /**
     * Configured, consented and not paused: jobs may run.
     *
     * @return bool
     */
    public static function is_ready(): bool {
        return self::is_configured() && !self::is_paused();
    }

    /**
     * Pause the plugin site-wide and tell the admins.
     *
     * @param string $reason The API's message.
     */
    public static function pause(string $reason): void {
        if (self::is_paused()) {
            return;
        }
        set_config('paused', time(), 'assignfeedback_proctorai');
        set_config('pausedreason', shorten_text($reason, 500), 'assignfeedback_proctorai');
        self::clear_cache();
        $url = new \moodle_url('/admin/settings.php', ['section' => 'assignfeedback_proctorai']);
        foreach (get_admins() as $admin) {
            notifier::send(
                'paused',
                $admin,
                get_string('msgpausedsubject', 'assignfeedback_proctorai'),
                get_string('msgpausedbody', 'assignfeedback_proctorai', (object) [
                    'reason' => $reason,
                    'url' => $url->out(false),
                ]),
                $url
            );
        }
    }

    /**
     * Admin-setting callback: the key, URL or consent changed, so unpause and retry what the pause stopped.
     *
     * @param string $fullname The setting's full name (unused).
     */
    public static function settings_updated(string $fullname = ''): void {
        self::clear_cache();
        self::resume();
    }

    /**
     * Unpause, and requeue the jobs that the pause or missing settings stopped.
     */
    public static function resume(): void {
        global $DB;
        unset_config('paused', 'assignfeedback_proctorai');
        unset_config('pausedreason', 'assignfeedback_proctorai');
        if (!self::is_configured()) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal(job_manager::PAUSE_ERRORS, SQL_PARAMS_NAMED);
        $params['failed'] = job_manager::STATUS_FAILED;
        $jobs = $DB->get_records_select('assignfeedback_proctorai_job', "status = :failed AND errorcode $insql", $params);
        foreach ($jobs as $job) {
            job_manager::requeue($job, true);
        }
    }

    /**
     * The cached GET /account response, refreshed when stale.
     *
     * @param bool $fetch Call the API if the cache is empty or stale. False only reads the cache.
     * @param int $timeout Seconds to wait if the API is called.
     * @return array|null The account, or null when unknown or not configured.
     */
    public static function get(bool $fetch = true, int $timeout = client::DEFAULT_TIMEOUT): ?array {
        if (!self::is_configured()) {
            return null;
        }
        $cache = \cache::make('assignfeedback_proctorai', 'account');
        $cached = $cache->get('account');
        if ($cached && (!$fetch || $cached['fetchedat'] > time() - self::CACHE_TTL)) {
            return $cached;
        }
        if (!$fetch || self::is_paused()) {
            return $cached ?: null;
        }
        return self::refresh($timeout);
    }

    /**
     * Fetch GET /account now and cache it.
     *
     * @param int $timeout Seconds to wait.
     * @return array The account.
     * @throws api_exception
     */
    public static function refresh(int $timeout = client::DEFAULT_TIMEOUT): array {
        try {
            $account = client::instance()->get_account($timeout);
        } catch (api_exception $e) {
            if ($e->httpstatus === 401) {
                self::pause($e->apimessage);
            }
            throw $e;
        }
        $account['fetchedat'] = time();
        \cache::make('assignfeedback_proctorai', 'account')->set('account', $account);
        return $account;
    }

    /**
     * Record a balance the API reported (after a grading or a 402) without another call.
     *
     * The entry is stamped as fetched now, so it counts as fresh for CACHE_TTL and is then
     * checked again: a zero balance after a 402 does not outlive a top-up by more than that.
     *
     * @param float $balance The new balance, in gradings.
     * @param float|null $price The price per sheet, if the response gave one.
     */
    public static function record_balance(float $balance, ?float $price = null): void {
        $cache = \cache::make('assignfeedback_proctorai', 'account');
        $account = $cache->get('account') ?: [];
        $price = $price ?? (float) ($account['pricePerSheet'] ?? 1);
        $account['balance'] = $balance;
        $account['pricePerSheet'] = $price;
        $account['sheetsRemaining'] = $price > 0 ? (int) floor(($balance + 1e-6) / $price) : 0;
        $account['fetchedat'] = time();
        $cache->set('account', $account);
    }

    /**
     * Forget the cached balance and fetch it again, for pages that show it.
     *
     * @param int $timeout Seconds to wait.
     * @return array|null The account, or null if it could not be fetched or the plugin is not configured.
     */
    public static function refresh_for_display(int $timeout = 10): ?array {
        if (!self::is_configured()) {
            return null;
        }
        self::clear_cache();
        if (self::is_paused()) {
            return null;
        }
        try {
            return self::refresh($timeout);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Forget the cached account.
     */
    public static function clear_cache(): void {
        \cache::make('assignfeedback_proctorai', 'account')->delete('account');
    }

    /**
     * Whether the cached account says there is not enough balance for one sheet.
     *
     * A fresh "out of balance" entry is trusted, so a batch that hits a 402 stops without
     * calling the API for every remaining job. Once it is older than CACHE_TTL the balance
     * is fetched again, so grading resumes soon after the account is topped up.
     *
     * @return bool
     */
    public static function known_out_of_balance(): bool {
        $account = self::get(false);
        if ($account === null || !isset($account['sheetsRemaining']) || (int) $account['sheetsRemaining'] >= 1) {
            return false;
        }
        if ((int) ($account['fetchedat'] ?? 0) > time() - self::CACHE_TTL || self::is_paused()) {
            return true;
        }
        try {
            $account = self::refresh();
        } catch (\Throwable $e) {
            // Cannot tell; let the grading call find out (a 402 costs nothing).
            return false;
        }
        return (int) ($account['sheetsRemaining'] ?? 0) < 1;
    }

    /**
     * MIME types the API accepts.
     *
     * @return string[]
     */
    public static function accepted_types(): array {
        $account = self::get(false);
        $types = $account['acceptedTypes'] ?? null;
        return is_array($types) && $types ? array_map('strtolower', $types) : self::DEFAULT_ACCEPTED_TYPES;
    }

    /**
     * Largest accepted file of this type, in bytes.
     *
     * @param string $mimetype The file's MIME type.
     * @return int
     */
    public static function max_bytes(string $mimetype): int {
        $account = self::get(false);
        if ($mimetype === 'application/pdf') {
            return (int) ($account['maxPdfBytes'] ?? self::DEFAULT_MAX_PDF_BYTES);
        }
        return (int) ($account['maxImageBytes'] ?? self::DEFAULT_MAX_IMAGE_BYTES);
    }

    /**
     * Tell admins and the teacher (at most once every 6 hours) that the balance has run out.
     *
     * @param int $teacherid The teacher whose job hit the limit, or 0.
     */
    public static function notify_nobalance(int $teacherid = 0): void {
        $last = (int) get_config('assignfeedback_proctorai', 'lastnobalancenotice');
        if ($last > time() - self::NOBALANCE_NOTIFY_INTERVAL) {
            return;
        }
        set_config('lastnobalancenotice', time(), 'assignfeedback_proctorai');
        $recipients = get_admins();
        if ($teacherid && !isset($recipients[$teacherid]) && ($teacher = \core_user::get_user($teacherid))) {
            $recipients[$teacherid] = $teacher;
        }
        foreach ($recipients as $user) {
            notifier::send(
                'nobalance',
                $user,
                get_string('msgnobalancesubject', 'assignfeedback_proctorai'),
                get_string('msgnobalancebody', 'assignfeedback_proctorai')
            );
        }
    }

    /**
     * Tell admins (at most once a day) when the balance falls below the threshold.
     *
     * @param array $account The account response.
     */
    public static function check_low_balance(array $account): void {
        $threshold = (int) get_config('assignfeedback_proctorai', 'lowbalance');
        $sheets = (int) ($account['sheetsRemaining'] ?? 0);
        if ($threshold <= 0 || $sheets >= $threshold) {
            return;
        }
        $last = (int) get_config('assignfeedback_proctorai', 'lastlowbalancenotice');
        if ($last > time() - self::LOWBALANCE_NOTIFY_INTERVAL) {
            return;
        }
        set_config('lastlowbalancenotice', time(), 'assignfeedback_proctorai');
        foreach (get_admins() as $admin) {
            notifier::send(
                'lowbalance',
                $admin,
                get_string('msglowbalancesubject', 'assignfeedback_proctorai'),
                get_string('msglowbalancebody', 'assignfeedback_proctorai', $sheets)
            );
        }
    }

    /**
     * The status line for the admin settings page: connection and balance.
     *
     * @return string HTML.
     */
    public static function status_html(): string {
        global $OUTPUT;
        if (trim((string) get_config('assignfeedback_proctorai', 'apikey')) === '') {
            return $OUTPUT->notification(get_string('statusnokey', 'assignfeedback_proctorai'), 'info', false);
        }
        if (empty(get_config('assignfeedback_proctorai', 'consent'))) {
            return $OUTPUT->notification(get_string('statusnoconsent', 'assignfeedback_proctorai'), 'warning', false);
        }
        if (self::is_paused()) {
            // Admin settings only call back when a value changes, so saving the same key cannot resume.
            // Check the key again here instead: it may work now (re-enabled, or a passing error).
            try {
                self::refresh(10);
            } catch (\Throwable $e) {
                return $OUTPUT->notification(get_string(
                    'statuspaused',
                    'assignfeedback_proctorai',
                    s((string) get_config('assignfeedback_proctorai', 'pausedreason'))
                ), 'error', false);
            }
            self::resume();
        }
        try {
            $account = self::get(true, 10);
        } catch (\Throwable $e) {
            $message = $e instanceof api_exception ? $e->apimessage : $e->getMessage();
            return $OUTPUT->notification(get_string('statuserror', 'assignfeedback_proctorai', s($message)), 'error', false);
        }
        return $OUTPUT->notification(get_string('statusok', 'assignfeedback_proctorai', (object) [
            'url' => s(self::api_url()),
            'balance' => format_float((float) ($account['balance'] ?? 0), 2, true, true),
            'sheets' => (int) ($account['sheetsRemaining'] ?? 0),
            'price' => format_float((float) ($account['pricePerSheet'] ?? 0), 2, true, true),
            'checked' => userdate((int) $account['fetchedat'], get_string('strftimedatetimeshort', 'core_langconfig')),
        ]), 'success', false);
    }
}
