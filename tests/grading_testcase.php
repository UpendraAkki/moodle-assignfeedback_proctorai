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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');
require_once(__DIR__ . '/fake_client.php');

/**
 * Shared set-up for tests that grade submissions: site config, assignments, submissions and API responses.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class grading_testcase extends \advanced_testcase {
    use \mod_assign_test_generator;

    /** @var string A small marking key worth 10 marks. */
    const KEY = "1. Name the capital of France. [4]\nAnswer: Paris\n2. Explain photosynthesis. [6]";

    /** @var \stdClass */
    protected $course;

    /** @var \stdClass */
    protected $teacher;

    /** @var \stdClass */
    protected $student;

    /**
     * Configure the plugin as an admin would, with consent given.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('apikey', 'pai_test_0123456789abcdef', 'assignfeedback_proctorai');
        set_config('apiurl', 'https://api.example.test/api/v1', 'assignfeedback_proctorai');
        set_config('consent', 1, 'assignfeedback_proctorai');
        set_config('maxattempts', 3, 'assignfeedback_proctorai');
        set_config('autogradecap', 3, 'assignfeedback_proctorai');
        set_config('regradeonresubmit', 1, 'assignfeedback_proctorai');
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Create an assignment with online text and file submissions and this plugin enabled.
     *
     * @param array $params Extra assignment settings.
     * @return \mod_assign_testable_assign
     */
    protected function create_assign(array $params = []): \mod_assign_testable_assign {
        return $this->create_instance($this->course, $params + [
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 20,
            'assignsubmission_file_maxsizebytes' => 1024 * 1024,
            'assignfeedback_proctorai_enabled' => 1,
            'assignfeedback_proctorai_markingkey' => self::KEY,
            'assignfeedback_proctorai_level' => 'Grade 8',
            'assignfeedback_proctorai_autograde' => 0,
            'grade' => 100,
        ]);
    }

    /**
     * Use a scripted client for every API call.
     *
     * @return fake_client
     */
    protected function fake_client(): fake_client {
        $client = new fake_client();
        \core\di::set(client::class, $client);
        return $client;
    }

    /**
     * Submit online text as the student.
     *
     * @param \assign $assign The assignment.
     * @param string $text The answer text.
     */
    protected function submit_text(\assign $assign, string $text = 'Paris. Plants make food from light.'): void {
        $sink = $this->redirectMessages();
        $this->add_submission($this->student, $assign, $text);
        if ($assign->get_instance()->submissiondrafts) {
            $this->submit_for_grading($this->student, $assign);
        }
        $sink->close();
        $this->setAdminUser();
    }

    /**
     * Add an image file to the student's submission.
     *
     * @param \assign $assign The assignment.
     * @param string $filename The file name.
     */
    protected function add_file(\assign $assign, string $filename = 'page1.jpg'): void {
        $submission = $assign->get_user_submission($this->student->id, true);
        get_file_storage()->create_file_from_string([
            'contextid' => $assign->get_context()->id,
            'component' => 'assignsubmission_file',
            'filearea' => 'submission_files',
            'itemid' => $submission->id,
            'filepath' => '/',
            'filename' => $filename,
        ], "\xFF\xD8\xFF\xE0 fake jpeg bytes");
    }

    /**
     * A successful POST /grade body: 7 of 10, one question flagged.
     *
     * @return array
     */
    protected function grade_response(): array {
        return [
            'id' => 'grd_0123456789abcdef01234567',
            'studentName' => 'Read Off The Sheet',
            'rollNumber' => '42',
            'marks' => ['earned' => 7, 'max' => 10, 'percentage' => 70],
            'questions' => [
                [
                    'questionId' => '1', 'label' => '1', 'earned' => 4, 'max' => 4, 'status' => 'answered',
                    'feedback' => 'Correct.', 'reasoning' => 'Matches.', 'studentAnswer' => 'Paris', 'pages' => [1],
                    'confidence' => 0.95, 'needsReview' => false, 'reviewReason' => null,
                ],
                [
                    'questionId' => '2', 'label' => '2', 'earned' => 3, 'max' => 6, 'status' => 'answered',
                    'feedback' => 'Partly right.', 'reasoning' => 'Missing the role of light.', 'studentAnswer' => 'Plants...',
                    'pages' => [1], 'confidence' => 0.4, 'needsReview' => true, 'reviewReason' => 'Handwriting unclear',
                ],
            ],
            'strengths' => ['Clear first answer'],
            'improvements' => ['Explain the role of light'],
            'overallFeedback' => 'A good start.',
            'pageCount' => 1,
            'billing' => ['gradingsCharged' => 1, 'balance' => 41],
        ];
    }

    /**
     * Run one job now, as the grade_submission task would.
     *
     * @param int $jobid The job.
     */
    protected function run_job(int $jobid): void {
        \assignfeedback_proctorai\local\job_manager::run($jobid);
    }
}
