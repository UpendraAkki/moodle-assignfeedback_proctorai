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

use assignfeedback_proctorai\tests\grading_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../grading_testcase.php');
require_once($CFG->libdir . '/adminlib.php');

/**
 * Tests for the site-wide state and the settings page status line.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignfeedback_proctorai\local\account
 * @covers     \assignfeedback_proctorai\admin\setting_status
 */
final class account_test extends grading_testcase {
    /**
     * A GET /account body.
     *
     * @return array
     */
    protected function account_response(): array {
        return [
            'balance' => 42,
            'pricePerSheet' => 1,
            'sheetsRemaining' => 42,
            'maxPagesPerSheet' => 20,
            'acceptedTypes' => ['image/jpeg', 'application/pdf'],
            'maxImageBytes' => 10485760,
            'maxPdfBytes' => 31457280,
        ];
    }

    /**
     * Building the admin tree, which Moodle does on every admin settings page, does not call the API.
     */
    public function test_admin_tree_does_not_call_api(): void {
        $this->setAdminUser();
        $client = $this->fake_client()->respond(200, $this->account_response());

        $root = admin_get_root(true, true);
        $this->assertCount(0, $client->requests);

        $setting = $root->locate('assignfeedback_proctorai')->settings->assignfeedback_proctoraistatusheading;
        $html = $setting->output_html(null);
        $this->assertCount(1, $client->requests);
        $this->assertStringContainsString('42', $html);
    }

    /**
     * Paused, and the key works again: opening the settings page resumes and requeues.
     */
    public function test_status_resumes_when_key_works_again(): void {
        $assign = $this->create_assign();
        $this->submit_text($assign);
        $job = job_manager::queue($assign, $this->student->id, job_manager::ORIGIN_MANUAL, $this->teacher->id);
        $client = $this->fake_client()->respond(401, ['error' => ['code' => 'invalid_api_key', 'message' => 'Revoked']]);
        $sink = $this->redirectMessages();
        $this->run_job($job->id);
        $this->assertTrue(account::is_paused());
        $sink->clear();

        // Saving the form with the same key does not call settings_updated(), so the status line must check.
        $client->respond(200, $this->account_response());
        $html = account::status_html();

        $this->assertFalse(account::is_paused());
        $this->assertStringContainsString('42', $html);
        $this->assertSame(job_manager::STATUS_QUEUED, $this->job_status($job->id));
        $this->assertCount(0, $sink->get_messages());
        $sink->close();
    }

    /**
     * Paused, and the key is still rejected: the page says so and sends no second notice.
     */
    public function test_status_stays_paused_when_key_still_rejected(): void {
        $this->setAdminUser();
        $client = $this->fake_client()->respond(401, ['error' => ['code' => 'invalid_api_key', 'message' => 'Revoked']]);
        $sink = $this->redirectMessages();
        account::pause('Revoked');
        $this->assertCount(1, $sink->get_messages());

        $html = account::status_html();

        $this->assertTrue(account::is_paused());
        $this->assertStringContainsString(get_string('statuspaused', 'assignfeedback_proctorai', 'Revoked'), $html);
        $this->assertCount(1, $client->requests);
        $this->assertCount(1, $sink->get_messages());
        $sink->close();
    }

    /**
     * The status of a job.
     *
     * @param int $jobid The job.
     * @return string
     */
    protected function job_status(int $jobid): string {
        global $DB;
        return $DB->get_field('assignfeedback_proctorai_job', 'status', ['id' => $jobid]);
    }
}
