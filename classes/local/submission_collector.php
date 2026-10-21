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
 * Gathers what a student submitted: photo and PDF files, and online text.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submission_collector {
    /** @var int Most files one POST /uploads call takes. */
    const MAX_FILES = 20;

    /** @var int Longest online text sent, in characters. */
    const MAX_TEXT = 20000;

    /**
     * Collect a submission.
     *
     * @param \assign $assign The assignment.
     * @param \stdClass $submission The assign_submission record.
     * @return \stdClass With files (stored_file[]), text (string), skipped (string[] file names) and hash (string).
     */
    public static function collect(\assign $assign, \stdClass $submission): \stdClass {
        global $DB;

        $user = $DB->get_record('user', ['id' => $submission->userid]) ?: (object) ['id' => $submission->userid];
        $accepted = account::accepted_types();
        $files = [];
        $skipped = [];
        $texts = [];

        foreach ($assign->get_submission_plugins() as $plugin) {
            if (!$plugin->is_enabled() || !$plugin->is_visible() || $plugin->is_empty($submission)) {
                continue;
            }
            foreach (array_keys($plugin->get_editor_fields()) as $field) {
                $html = (string) $plugin->get_editor_text($field, $submission->id);
                $text = trim(html_to_text($html, 0, false));
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
            foreach ($plugin->get_files($submission, $user) as $file) {
                // Online text returns its own HTML export as a plain array; only stored files are pages.
                if (!$file instanceof \stored_file || $file->is_directory()) {
                    continue;
                }
                $mimetype = self::mimetype($file);
                if (!in_array($mimetype, $accepted, true) || $file->get_filesize() > account::max_bytes($mimetype)
                        || $file->get_filesize() <= 0) {
                    $skipped[] = $file->get_filename();
                    continue;
                }
                $files[$file->get_contenthash() . '/' . $file->get_pathnamehash()] = $file;
            }
        }

        // Sort for a stable order (and a stable hash): by path, then name.
        uasort($files, function(\stored_file $a, \stored_file $b): int {
            return strnatcasecmp($a->get_filepath() . $a->get_filename(), $b->get_filepath() . $b->get_filename());
        });
        $files = array_values($files);

        $text = \core_text::substr(implode("\n\n", $texts), 0, self::MAX_TEXT);

        $parts = array_map(fn(\stored_file $f) => $f->get_contenthash(), $files);
        $parts[] = sha1($text);

        return (object) [
            'files' => $files,
            'text' => $text,
            'skipped' => $skipped,
            'hash' => sha1(implode('|', $parts)),
        ];
    }

    /**
     * The MIME type the API should see, normalised from the stored one.
     *
     * @param \stored_file $file The file.
     * @return string
     */
    public static function mimetype(\stored_file $file): string {
        $mimetype = strtolower((string) $file->get_mimetype());
        if ($mimetype === 'image/jpg' || $mimetype === 'image/pjpeg') {
            return 'image/jpeg';
        }
        if ($mimetype === '' || $mimetype === 'document/unknown' || $mimetype === 'application/octet-stream') {
            // Phones sometimes upload HEIC photos that Moodle does not recognise.
            $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
            return match ($extension) {
                'heic' => 'image/heic',
                'heif' => 'image/heif',
                'webp' => 'image/webp',
                default => $mimetype,
            };
        }
        return $mimetype;
    }
}
