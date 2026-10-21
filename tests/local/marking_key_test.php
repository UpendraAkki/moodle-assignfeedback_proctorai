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
 * Tests for the marking key parser, including the API's limits.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignfeedback_proctorai\local\marking_key
 */
final class marking_key_test extends \advanced_testcase {
    /**
     * A key with answers, options and sub-questions becomes the API format.
     */
    public function test_parse_full_key(): void {
        $key = marking_key::parse(implode("\n", [
            '1. Name the capital of France. [2]',
            'Answer: Paris',
            '2) Which number is prime? [1 mark]',
            'Options: 4 | 7 | 9',
            '3. Photosynthesis',
            'Answer the parts below.',
            '3a. Name the gas taken in. [1]',
            'Answer: Carbon dioxide',
            'or CO2',
            '3B. Name the gas given out. [1.5]',
        ]));

        $this->assertCount(3, $key->questions);
        $this->assertEquals(5.5, $key->totalmarks);

        $this->assertSame(['id' => '1', 'prompt' => 'Name the capital of France.', 'maxMarks' => 2.0,
            'expectedAnswer' => 'Paris'], $key->questions[0]);
        $this->assertSame(['4', '7', '9'], $key->questions[1]['options']);
        $this->assertEquals(1, $key->questions[1]['maxMarks']);

        $q3 = $key->questions[2];
        $this->assertSame("Photosynthesis\nAnswer the parts below.", $q3['prompt']);
        $this->assertArrayNotHasKey('maxMarks', $q3);
        $this->assertCount(2, $q3['subQuestions']);
        $this->assertSame('3a', $q3['subQuestions'][0]['id']);
        $this->assertSame('a', $q3['subQuestions'][0]['label']);
        $this->assertSame("Carbon dioxide\nor CO2", $q3['subQuestions'][0]['expectedAnswer']);
        $this->assertSame('3b', $q3['subQuestions'][1]['id']);
        $this->assertEquals(1.5, $q3['subQuestions'][1]['maxMarks']);
    }

    /**
     * Sub-questions without a stem line get a generated prompt.
     */
    public function test_parse_subquestions_without_stem(): void {
        $key = marking_key::parse("4a. First part [1]\n4b. Second part [2]");
        $this->assertSame('Question 4', $key->questions[0]['prompt']);
        $this->assertCount(2, $key->questions[0]['subQuestions']);
    }

    /**
     * Exactly at the limits still parses.
     */
    public function test_limits_accepted(): void {
        $lines = [];
        for ($i = 1; $i <= marking_key::MAX_QUESTIONS; $i++) {
            $lines[] = "$i. Q$i [" . marking_key::MAX_MARKS . ']';
        }
        $this->assertCount(100, marking_key::parse(implode("\n", $lines))->questions);

        $prompt = str_repeat('x', marking_key::MAX_PROMPT);
        $answer = str_repeat('y', marking_key::MAX_ANSWER);
        $key = marking_key::parse("1. $prompt [1]\nAnswer: $answer");
        $this->assertSame($answer, $key->questions[0]['expectedAnswer']);

        $options = implode(' | ', range(1, marking_key::MAX_OPTIONS));
        $this->assertCount(10, marking_key::parse("1. Pick [1]\nOptions: $options")->questions[0]['options']);

        $subs = [];
        foreach (range('a', 't') as $letter) {
            $subs[] = "1$letter. Part [1]";
        }
        $this->assertCount(20, marking_key::parse(implode("\n", $subs))->questions[0]['subQuestions']);
    }

    /**
     * Keys that the API would reject.
     *
     * @dataProvider invalid_keys
     * @param string $text The key.
     * @param string $expected Part of the error message.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_keys')]
    public function test_invalid_keys(string $text, string $expected): void {
        $error = marking_key::validate($text);
        $this->assertNotNull($error);
        $this->assertStringContainsString($expected, $error);
    }

    /**
     * Invalid keys and the error each gives.
     *
     * @return array
     */
    public static function invalid_keys(): array {
        $toomany = [];
        for ($i = 1; $i <= marking_key::MAX_QUESTIONS + 1; $i++) {
            $toomany[] = "$i. Q [1]";
        }
        $subs = [];
        foreach (range('a', 'u') as $letter) {
            $subs[] = "1$letter. Part [1]";
        }
        return [
            'empty' => ['   ', 'no questions'],
            'text before any question' => ["Read carefully\n1. Q [1]", 'Line 1'],
            'zero marks' => ['1. Q [0]', 'more than 0'],
            'too many marks' => ['1. Q [1001]', 'at most 1000'],
            'duplicate' => ["1. Q [1]\n1. Again [1]", 'appears twice'],
            'sub without marks' => ['2a. Part', 'needs marks'],
            'one option' => ["1. Q [1]\nOptions: only", 'between 2 and 10'],
            'eleven options' => ["1. Q [1]\nOptions: " . implode('|', range(1, 11)), 'between 2 and 10'],
            'long option' => ["1. Q [1]\nOptions: a | " . str_repeat('b', 501), '500 characters'],
            'options on sub' => ["1a. Part [1]\nOptions: a | b", 'without sub-questions'],
            'long prompt' => ['1. ' . str_repeat('x', 4001) . ' [1]', '4000 characters'],
            'long answer' => ["1. Q [1]\nAnswer: " . str_repeat('y', 8001), '8000 characters'],
            'too many questions' => [implode("\n", $toomany), 'more than 100 questions'],
            'too many sub-questions' => [implode("\n", $subs), 'more than 20 sub-questions'],
            'answer on stem' => ["1. Stem\nAnswer: x\n1a. Part [1]", 'cannot have its own answer'],
        ];
    }

    /**
     * The error names the line at fault.
     */
    public function test_error_names_line(): void {
        $this->assertSame('Line 3: Question 1 appears twice.', marking_key::validate("1. Q [1]\n\n1. Again [1]"));
    }
}
