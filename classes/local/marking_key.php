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
 * Parses the teacher's plain-text marking key into the API's `questions` list.
 *
 * One question per numbered line, marks in square brackets, optional
 * "Answer:" and "Options:" lines, and lettered sub-questions:
 *
 *     1. Name the capital of France. [2]
 *     Answer: Paris
 *     2. Which is a prime? [1]
 *     Options: 4 | 7 | 9
 *     3. Photosynthesis
 *     3a. Name the gas taken in. [1]
 *     Answer: Carbon dioxide
 *     3b. Name the gas given out. [1]
 *
 * Any other line continues the prompt or answer above it. The limits match
 * the API's request validation, so a key that parses here is never rejected.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class marking_key {
    /** @var int Most questions in one key. */
    const MAX_QUESTIONS = 100;

    /** @var int Most sub-questions under one question. */
    const MAX_SUBQUESTIONS = 20;

    /** @var int Longest prompt, in characters. */
    const MAX_PROMPT = 4000;

    /** @var int Longest expected answer, in characters. */
    const MAX_ANSWER = 8000;

    /** @var int Fewest options for a multiple-choice question. */
    const MIN_OPTIONS = 2;

    /** @var int Most options for a multiple-choice question. */
    const MAX_OPTIONS = 10;

    /** @var int Longest option, in characters. */
    const MAX_OPTION = 500;

    /** @var float Most marks for one question or sub-question. */
    const MAX_MARKS = 1000;

    /** @var int Longest key text accepted at all, in characters. */
    const MAX_TEXT = 200000;

    /** @var string Matches "3.", "3)", "3a." or "3a)" at the start of a line, then the prompt and optional [marks]. */
    const QUESTION_LINE = '/^\s*(\d{1,3})([a-z])?[.)](?:\s+|$)(.*?)\s*(?:\[\s*(\d+(?:\.\d+)?)\s*(?:marks?)?\s*\])?\s*$/iu';

    /** @var array The questions, in the API's request format. */
    public readonly array $questions;

    /** @var float Sum of all marks given in the key (questions without marks count 0). */
    public readonly float $totalmarks;

    /**
     * Constructor; use parse().
     *
     * @param array $questions Questions in the API format.
     * @param float $totalmarks Sum of marks.
     */
    private function __construct(array $questions, float $totalmarks) {
        $this->questions = $questions;
        $this->totalmarks = $totalmarks;
    }

    /**
     * Parse a marking key.
     *
     * @param string $text The teacher's text.
     * @return self
     * @throws \moodle_exception With a message that names the line at fault.
     */
    public static function parse(string $text): self {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (trim($text) === '') {
            self::fail('markingkeyempty');
        }
        if (\core_text::strlen($text) > self::MAX_TEXT) {
            self::fail('markingkeytoolong');
        }

        // Each entry: number, prompt, marks, answer, options, subs (keyed by letter), line.
        $questions = [];
        // The field that free lines continue: a reference into $questions.
        $current = null;
        $currentfield = null;

        foreach (explode("\n", $text) as $index => $raw) {
            $lineno = $index + 1;
            $line = trim($raw);
            if ($line === '') {
                continue;
            }

            if (preg_match(self::QUESTION_LINE, $line, $m)) {
                $number = (string) (int) $m[1];
                $letter = isset($m[2]) && $m[2] !== '' ? \core_text::strtolower($m[2]) : null;
                $prompt = $m[3];
                $marks = isset($m[4]) && $m[4] !== '' ? (float) $m[4] : null;
                if ($marks !== null && ($marks <= 0 || $marks > self::MAX_MARKS)) {
                    self::fail('markingkeybadmarks', $lineno, self::MAX_MARKS);
                }

                if ($letter === null) {
                    if (isset($questions[$number]) && !$questions[$number]['implicit']) {
                        self::fail('markingkeyduplicate', $lineno, $number);
                    }
                    if (!isset($questions[$number])) {
                        $questions[$number] = self::new_question($number, $lineno);
                    }
                    $questions[$number]['implicit'] = false;
                    $questions[$number]['prompt'] = $prompt;
                    $questions[$number]['marks'] = $marks;
                    $questions[$number]['line'] = $lineno;
                    $current = &$questions[$number];
                } else {
                    if (!isset($questions[$number])) {
                        // "3a." with no "3." line: the parent's prompt is filled in below.
                        $questions[$number] = self::new_question($number, $lineno);
                    }
                    if (isset($questions[$number]['subs'][$letter])) {
                        self::fail('markingkeyduplicate', $lineno, $number . $letter);
                    }
                    if ($marks === null) {
                        self::fail('markingkeysubmarks', $lineno, $number . $letter);
                    }
                    $questions[$number]['subs'][$letter] = [
                        'letter' => $letter,
                        'prompt' => $prompt,
                        'marks' => $marks,
                        'answer' => null,
                        'line' => $lineno,
                    ];
                    unset($current);
                    $current = &$questions[$number]['subs'][$letter];
                }
                $currentfield = 'prompt';
                continue;
            }

            if (!isset($current)) {
                self::fail('markingkeynoquestion', $lineno);
            }

            if (preg_match('/^answer\s*:\s*(.*)$/iu', $line, $m)) {
                $current['answer'] = $m[1];
                $currentfield = 'answer';
                continue;
            }

            if (preg_match('/^options\s*:\s*(.*)$/iu', $line, $m)) {
                if (!array_key_exists('options', $current)) {
                    // Sub-questions are graded as free text; the API takes options on questions only.
                    self::fail('markingkeysuboptions', $lineno);
                }
                $options = array_values(array_filter(array_map('trim', explode('|', $m[1])), 'strlen'));
                if (count($options) < self::MIN_OPTIONS || count($options) > self::MAX_OPTIONS) {
                    self::fail('markingkeyoptioncount', $lineno, (object) ['min' => self::MIN_OPTIONS, 'max' => self::MAX_OPTIONS]);
                }
                foreach ($options as $option) {
                    if (\core_text::strlen($option) > self::MAX_OPTION) {
                        self::fail('markingkeyoptiontoolong', $lineno, self::MAX_OPTION);
                    }
                }
                $current['options'] = $options;
                $currentfield = null;
                continue;
            }

            if ($currentfield === null) {
                self::fail('markingkeynoquestion', $lineno);
            }
            $current[$currentfield] = trim(($current[$currentfield] ?? '') . "\n" . $line);
        }
        unset($current);

        if (!$questions) {
            self::fail('markingkeyempty');
        }
        if (count($questions) > self::MAX_QUESTIONS) {
            self::fail('markingkeytoomany', null, self::MAX_QUESTIONS);
        }

        $out = [];
        $total = 0.0;
        foreach ($questions as $number => $q) {
            $prompt = trim((string) $q['prompt']);
            if ($prompt === '') {
                $prompt = get_string('questionn', 'assignfeedback_proctorai', $number);
            }
            self::check_length($prompt, self::MAX_PROMPT, 'markingkeyprompttoolong', $q['line']);
            $item = ['id' => (string) $number, 'prompt' => $prompt];

            if ($q['subs']) {
                if ($q['options'] !== null) {
                    self::fail('markingkeysuboptions', $q['line']);
                }
                if (count($q['subs']) > self::MAX_SUBQUESTIONS) {
                    self::fail('markingkeytoomanysubs', $q['line'], (object) ['n' => $number, 'max' => self::MAX_SUBQUESTIONS]);
                }
                $subs = [];
                foreach ($q['subs'] as $sub) {
                    $subprompt = trim((string) $sub['prompt']);
                    if ($subprompt === '') {
                        self::fail('markingkeyemptyprompt', $sub['line']);
                    }
                    self::check_length($subprompt, self::MAX_PROMPT, 'markingkeyprompttoolong', $sub['line']);
                    $entry = [
                        'id' => $number . $sub['letter'],
                        'label' => $sub['letter'],
                        'prompt' => $subprompt,
                        'maxMarks' => $sub['marks'],
                    ];
                    if ($sub['answer'] !== null && trim($sub['answer']) !== '') {
                        self::check_length(trim($sub['answer']), self::MAX_ANSWER, 'markingkeyanswertoolong', $sub['line']);
                        $entry['expectedAnswer'] = trim($sub['answer']);
                    }
                    $subs[] = $entry;
                    $total += $sub['marks'];
                }
                $item['subQuestions'] = $subs;
                if ($q['answer'] !== null && trim($q['answer']) !== '') {
                    // An answer on the stem of a split question would be ignored by the API.
                    self::fail('markingkeystemanswer', $q['line']);
                }
            } else {
                if ($q['marks'] !== null) {
                    $item['maxMarks'] = $q['marks'];
                    $total += $q['marks'];
                }
                if ($q['answer'] !== null && trim($q['answer']) !== '') {
                    self::check_length(trim($q['answer']), self::MAX_ANSWER, 'markingkeyanswertoolong', $q['line']);
                    $item['expectedAnswer'] = trim($q['answer']);
                }
                if ($q['options'] !== null) {
                    $item['options'] = $q['options'];
                }
            }
            $out[] = $item;
        }

        return new self($out, $total);
    }

    /**
     * Check a marking key without throwing.
     *
     * @param string $text The teacher's text.
     * @return string|null The error message, or null if the key is valid.
     */
    public static function validate(string $text): ?string {
        try {
            self::parse($text);
            return null;
        } catch (\moodle_exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * A fresh question entry.
     *
     * @param string $number The question number.
     * @param int $lineno The line it was first seen on.
     * @return array
     */
    private static function new_question(string $number, int $lineno): array {
        return [
            'number' => $number,
            'prompt' => '',
            'marks' => null,
            'answer' => null,
            'options' => null,
            'subs' => [],
            'line' => $lineno,
            'implicit' => true,
        ];
    }

    /**
     * Fail if a value is too long.
     *
     * @param string $value The value.
     * @param int $max Most characters allowed.
     * @param string $stringid The error string.
     * @param int $lineno The line at fault.
     */
    private static function check_length(string $value, int $max, string $stringid, int $lineno): void {
        if (\core_text::strlen($value) > $max) {
            self::fail($stringid, $lineno, $max);
        }
    }

    /**
     * Throw a parse error.
     *
     * @param string $stringid The error string in this plugin's language file.
     * @param int|null $lineno The line at fault.
     * @param mixed $a Extra string parameter.
     * @throws \moodle_exception
     */
    private static function fail(string $stringid, ?int $lineno = null, mixed $a = null): never {
        $message = get_string($stringid, 'assignfeedback_proctorai', $a);
        if ($lineno !== null) {
            $message = get_string('markingkeyline', 'assignfeedback_proctorai', (object) [
                'line' => $lineno,
                'message' => $message,
            ]);
        }
        throw new \moodle_exception('markingkeyinvalid', 'assignfeedback_proctorai', '', $message);
    }
}
