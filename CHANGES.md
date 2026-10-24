# Changes

## 0.2.0 (2026-10-23), beta

New:

- **Marking-key editor.** The marking key can be edited question by question, with fields for the question, marks, expected answer, options and parts. It reads and writes the same text, and shows the total marks against the assignment's maximum grade.
- **Marks per question in the grader.** A teacher can change any question's mark; the total and the grade field update as they type. Once a teacher approves (or, with marking workflow, releases) the result, students see the marks per question with the feedback.
- **Apply Proctor AI marks.** Without marking workflow, a bulk action writes the AI grade for the selected students after a confirmation page. Grades a teacher gave are left alone.
- **Status page.** Shows the current balance. Each student has **Retry** and **Cancel**, and **Retry everything stopped by the balance** re-queues after a top-up. Graders see a banner on the assignment page when the balance is low or gone, or grading is paused.
- **Group submissions.** Graded once per group (one charge); the result goes to every member.
- **Upload method setting.** *Direct* (the default) uses presigned uploads. *Inline* sends photos inside the grading request, up to 4 MB, for sites that block the upload storage.
- **Staging headers.** `$CFG->assignfeedback_proctorai_extraheaders` (config.php only) adds headers for a staging server behind an access gate.
- Behat features against a fake API served by the test site, plugin icon, screenshots, and a plugins-directory checklist in the README.

Fixed:

- Submissions made while grading was paused are now graded after the resume, instead of being skipped.
- The "grading finished" notice is sent once per batch. Before, a batch that hit a rejected key sent it twice.
- After running out of balance, the balance is checked again after 5 minutes and whenever the settings or status page is opened, so grading resumes soon after a top-up.

## 0.1.1 (2026-10-22), beta

Fixes found by end-to-end testing on Moodle 4.5 and 5.0 (see `docs/e2e-report.md`).

- The cost confirmation page and the status page no longer fail with "Class assign_header not found".
- The connection status on the settings page is only checked when that page is shown. Before, every admin settings page on the site called Proctor AI, and was slow when Proctor AI was slow.
- After a rejected key paused grading, opening the settings page checks the key again and resumes if it works, so a key that works again no longer needs to be changed and changed back.
- The grader no longer says "result is ready" when the grading failed; it says grading stopped.
- Clearer wording for counts ("Pages: 1", "Grade with Proctor AI (1)").

## 0.1.0 (2026-10-21), beta

First release.

- Assignment feedback plugin that grades photo, PDF and online-text submissions with Proctor AI against a per-assignment marking key.
- The teacher reviews first. With marking workflow on, a points grade is pre-filled as a draft and the student is set to "Ready for review"; the gradebook stays empty until release. With marking workflow off, the result is a suggestion until the teacher saves the grader form.
- Grader panel with the AI total, flagged questions, a per-question table and a "Use AI grade" button. The AI's feedback for the student is editable.
- "Grade with Proctor AI" bulk action and "Grade all ungraded" action, with a cost confirmation page, plus a status page.
- Optional automatic grading on submission, with a per-student cap and a 10-minute quiet period for sites without the submit button.
- Retries reuse an idempotency key, so a submission is never charged twice. A rejected key pauses the plugin site-wide; running out of balance stops grading and notifies admins.
- Nothing is sent to Proctor AI until an administrator gives consent in the plugin settings.
- Privacy API, backup and restore of results, PHPUnit tests.

Not yet supported: group submissions, quick grading, per-question mark editing, automatic application of results for rubric or marking-guide assignments.
