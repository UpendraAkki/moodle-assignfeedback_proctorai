# Changes

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
