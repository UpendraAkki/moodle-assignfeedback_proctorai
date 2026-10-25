# Moodle plugins directory: listing text

Copy these into the plugin's page at <https://moodle.org/plugins> when you register it. Upload `logo-512.png` (in this folder) as the logo, and the five images in `pix/screenshots` as screenshots.

## Plugin name

Proctor AI grading

## Short description (one line)

AI marking of handwritten (photo or PDF) and typed assignment answers against your marking key. Teachers review every mark before students see it.

## Description

Proctor AI grading is an Assignment feedback plugin. It reads students' handwritten answers, from photos or a PDF scan, and their typed online text, and marks them against a marking key the teacher writes. It shows what it read, why it gave each mark and how sure it is, and highlights the answers a teacher should check.

**The teacher always decides.** The AI's result is a draft. With marking workflow, the grade is pre-filled and the student is set to "Ready for review"; the gradebook stays empty until release. Without it, the result is a suggestion until the teacher saves or applies it. Grades a teacher gave are never overwritten.

Features:

- Marking key editor, question by question, with parts, expected answers and multiple-choice options.
- Grade selected students, everyone ungraded, or automatically on submission. A confirmation page shows the cost and balance first.
- Grader panel with the AI total, per-question marks you can change, the AI's reading of the answer, its reasoning and confidence.
- Status page with retry and cancel, balance warnings, and notifications when a batch finishes or the balance runs low.
- Group submissions graded once per group.
- Retries never charge twice. Files the AI can't read are named, so the teacher checks them by hand.
- Privacy API, backup and restore, Moodle 4.5 to 5.3.

**Requirements:** a paid Proctor AI account with a prepaid balance (each graded submission is charged). Sign up at <https://agent.proctorai.io>. A free test key, which returns sample marks and is never charged, is available for trying the plugin.

**Privacy:** nothing is sent until an administrator gives consent in the plugin settings. Student names, emails and IDs are never sent. Uploaded pages are deleted by Proctor AI as soon as they are graded, and within 72 hours at most.

## Links

| Field | Value |
|---|---|
| Source control URL | https://github.com/UpendraAkki/moodle-assignfeedback_proctorai |
| Bug tracker URL | https://github.com/UpendraAkki/moodle-assignfeedback_proctorai/issues |
| Documentation URL | https://github.com/UpendraAkki/moodle-assignfeedback_proctorai/blob/main/docs/README.md |
| Website | https://agent.proctorai.io |
| Privacy policy | https://www.proctorai.io/privacy |
| Terms | https://www.proctorai.io/terms |

## Supported Moodle versions

4.5, 5.0, 5.1, 5.2, 5.3

## Note for the plugins team (approval issue)

> This plugin needs an account with the Proctor AI service. For your review, here is a test key that returns sample marks and is never charged: `pai_test_…` (paste the real key here, in the approval issue only — never commit it). Leave the API URL at its default. Tick "Allow sending student work to Proctor AI" in the plugin settings, then follow the [teacher guide](../teacher-guide.md).
