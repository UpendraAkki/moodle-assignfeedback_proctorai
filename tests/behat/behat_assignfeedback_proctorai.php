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
 * Behat steps for the Proctor AI feedback plugin.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\PyStringNode;
use Behat\Mink\Exception\ExpectationException;

/**
 * Behat steps for the Proctor AI feedback plugin.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_assignfeedback_proctorai extends behat_base {
    /**
     * Run this plugin's queued grading tasks now, through the task class, as cron would.
     *
     * Core's "I run all adhoc tasks" sometimes left these tasks unrun, which made scenarios flaky.
     * This runs exactly this plugin's due tasks and fails with the job details if one is still pending.
     *
     * @Given /^I run the Proctor AI grading tasks$/
     */
    public function i_run_the_proctor_ai_grading_tasks(): void {
        global $DB;
        \core\cron::setup_user();
        $errors = [];
        foreach (\core\task\manager::get_adhoc_tasks(\assignfeedback_proctorai\task\grade_submission::class) as $task) {
            if ($task->get_next_run_time() > time()) {
                continue;
            }
            try {
                $task->execute();
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
            $DB->delete_records('task_adhoc', ['id' => $task->get_id()]);
        }
        $pending = $DB->get_records_select(
            'assignfeedback_proctorai_job',
            "status IN ('queued', 'uploading', 'grading') AND timeready <= :now",
            ['now' => time()],
            '',
            'id, status, errorcode, errormessage, timeready'
        );
        if ($pending) {
            throw new ExpectationException(
                'Proctor AI jobs still pending: ' . json_encode(array_values($pending)) . ' Errors: ' . json_encode($errors),
                $this->getSession()
            );
        }
    }

    /**
     * Point the plugin at the fake API served by this test site, with a test key and consent.
     *
     * @Given /^Proctor AI uses the fake API$/
     */
    public function proctor_ai_uses_the_fake_api(): void {
        $url = new moodle_url('/mod/assign/feedback/proctorai/tests/fixtures/fakeapi.php');
        set_config('apiurl', $url->out(false) . '?route=', 'assignfeedback_proctorai');
        set_config('apikey', 'pai_test_behat0000000000000000000000000000', 'assignfeedback_proctorai');
        set_config('consent', 1, 'assignfeedback_proctorai');
        set_config('fakeapi_balance', 100, 'assignfeedback_proctorai');
        set_config('fakeapi_next', '', 'assignfeedback_proctorai');
        // The fake API is on this site's own host, which Moodle's curl blocks by default.
        set_config('curlsecurityblockedhosts', '');
        set_config('curlsecurityallowedport', '');
        \cache::make('assignfeedback_proctorai', 'account')->purge();
    }

    /**
     * Set the fake API's balance.
     *
     * @Given /^the fake Proctor AI balance is "(?P<balance>\d+)"$/
     * @param string $balance Sheets.
     */
    public function the_fake_proctor_ai_balance_is(string $balance): void {
        set_config('fakeapi_balance', (int) $balance, 'assignfeedback_proctorai');
        \cache::make('assignfeedback_proctorai', 'account')->purge();
    }

    /**
     * Make the fake API answer gradings with an error (401, 402 or 422), or normally ("200").
     *
     * @Given /^the fake Proctor AI API answers gradings with "(?P<status>\d+)"$/
     * @param string $status HTTP status.
     */
    public function the_fake_proctor_ai_api_answers_gradings_with(string $status): void {
        set_config('fakeapi_next', $status === '200' ? '' : $status, 'assignfeedback_proctorai');
    }

    /**
     * Set an assignment's marking key, as saving the assignment settings would.
     *
     * @Given /^the Proctor AI marking key of "(?P<name>[^"]*)" is:$/
     * @param string $name The assignment name.
     * @param PyStringNode $key The marking key.
     */
    public function the_proctor_ai_marking_key_of_is(string $name, PyStringNode $key): void {
        $plugin = $this->get_assign($name)->get_feedback_plugin_by_type('proctorai');
        $plugin->save_settings((object) ['assignfeedback_proctorai_markingkey' => $key->getRaw()]);
    }

    /**
     * Log in and open one of the plugin's pages for an assignment.
     *
     * @Given /^I am on the Proctor AI "(?P<page>[a-z]+)" page for "(?P<name>[^"]*)" as "(?P<user>[^"]*)"$/
     * @param string $page gradeall, status, applyconfirm.
     * @param string $name The assignment name.
     * @param string $username The user.
     */
    public function i_am_on_the_proctor_ai_page_for_as(string $page, string $name, string $username): void {
        $this->execute('behat_auth::i_log_in_as', [$username]);
        $this->visit_plugin_page($name, $page);
    }

    /**
     * Open the "Apply Proctor AI marks" confirmation for some students.
     *
     * @Given /^I am on the Proctor AI apply page for "(?P<users>[^"]*)" in "(?P<name>[^"]*)"$/
     * @param string $usernames Comma-separated usernames.
     * @param string $name The assignment name.
     */
    public function i_am_on_the_proctor_ai_apply_page_for_in(string $usernames, string $name): void {
        global $DB;
        $ids = [];
        foreach (array_map('trim', explode(',', $usernames)) as $username) {
            $ids[] = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        }
        $this->visit_plugin_page($name, 'applyconfirm', ['selectedusers' => implode(',', $ids)]);
    }

    /**
     * Open the grader for one student.
     *
     * @Given /^I am on the grader for "(?P<user>[^"]*)" in "(?P<name>[^"]*)"$/
     * @param string $username The student.
     * @param string $name The assignment name.
     */
    public function i_am_on_the_grader_for_in(string $username, string $name): void {
        global $DB;
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $assign = $this->get_assign($name);
        $this->execute('behat_general::i_visit', [
            new moodle_url('/mod/assign/view.php', [
                'id' => $assign->get_course_module()->id,
                'action' => 'grader',
                'userid' => $userid,
            ]),
        ]);
    }

    /**
     * Save a screenshot for the plugins directory when PROCTORAI_SCREENSHOT_DIR is set (CI does).
     *
     * @Given /^I save a Proctor AI screenshot named "(?P<filename>[a-z0-9-]+)"$/
     * @param string $filename File name without extension.
     */
    public function i_save_a_proctor_ai_screenshot_named(string $filename): void {
        $dir = getenv('PROCTORAI_SCREENSHOT_DIR');
        if (!$dir || !$this->running_javascript()) {
            return;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->getSession()->resizeWindow(1366, 900);
        file_put_contents($dir . '/' . $filename . '.png', $this->getSession()->getScreenshot());
    }

    /**
     * Load an assignment by name.
     *
     * @param string $name The assignment name.
     * @return assign
     */
    protected function get_assign(string $name): assign {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $instance = $DB->get_record('assign', ['name' => $name], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $instance->id, 0, false, MUST_EXIST);
        return new assign(context_module::instance($cm->id), $cm, null);
    }

    /**
     * Visit one of the plugin's pages.
     *
     * @param string $name The assignment name.
     * @param string $page The plugin action.
     * @param array $params Extra parameters.
     */
    protected function visit_plugin_page(string $name, string $page, array $params = []): void {
        $assign = $this->get_assign($name);
        $this->execute('behat_general::i_visit', [
            new moodle_url('/mod/assign/view.php', [
                'id' => $assign->get_course_module()->id,
                'action' => 'viewpluginpage',
                'plugin' => 'proctorai',
                'pluginsubtype' => 'assignfeedback',
                'pluginaction' => $page,
            ] + $params),
        ]);
    }
}
