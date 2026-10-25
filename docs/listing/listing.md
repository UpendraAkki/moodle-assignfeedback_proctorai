# Moodle plugins directory listing

Everything to paste into the plugin's page at <https://moodle.org/plugins>. Upload `logo-512.png` (in this folder) as the logo and the six images in `pix/screenshots` as screenshots, in the order given below.

## Plugin name

Proctor AI grading

## Short description

AI grading for Moodle assignments. Marks handwritten answers (photos or scanned PDFs) and typed answers against your marking key, then lets the teacher check every mark before students see it.

## Description

Proctor AI grading adds AI marking to the standard Moodle Assignment activity. Students hand in their work as usual: photos of handwritten pages, a scanned PDF, or typed online text. The plugin reads the answers, marks each question against the teacher's marking key, and puts the result in the grader as a draft. The teacher reviews it, changes any mark, and releases the grades when ready.

It suits teachers who mark written homework, worksheets, tests and exam papers, and schools that want faster grading without giving up control of the final mark.

### How it works

1. The site administrator connects Moodle to a Proctor AI account and gives consent to send student work.
2. The teacher turns on "Proctor AI grading" in an assignment and writes the marking key: each question, its marks and, if they like, the expected answer.
3. Students submit photos, a PDF or typed text.
4. The teacher sends the submissions for grading, or lets them go automatically when students submit. The cost and the balance are shown first.
5. For each question the AI gives a mark, shows what it read from the handwriting, explains why, and says how confident it is. Answers it is unsure about are highlighted for the teacher to check.
6. The teacher reviews and edits the marks and feedback in the usual grader.
7. Students see their grade, the feedback and their marks per question only when the teacher releases them.

### Features

- Reads handwriting from photos (JPG, PNG, HEIC, WebP) and scanned PDFs up to 20 pages, as well as typed online text.
- Marking key editor: write it question by question, with parts (3a, 3b), expected answers and multiple choice options, or type it as plain text.
- Grader panel: the AI total, the mark for each question, what the AI read, its reasoning and its confidence. Change any mark and the total updates as you type.
- Teacher review first: with marking workflow the grade is a draft until released; without it, the result is a suggestion until the teacher saves or applies it. A grade a teacher gave is never overwritten.
- Grade selected students, all ungraded students, or automatically on submission, with a cost confirmation page.
- Status page with retry and cancel, low balance warnings, and a notice when a batch of grading has finished.
- Group submissions are graded and charged once for the whole group.
- Files the AI cannot read, such as a Word document, are named for the teacher so nothing is marked as zero by mistake.
- Retries after a network problem are never charged twice.
- Works with the Moodle mobile app: students never see the marking key.
- Privacy API, backup and restore, PHPUnit and Behat tests. Moodle 4.5 to 5.3.

### Requirements

- A Proctor AI account with a prepaid balance. Each graded submission is charged; the price is shown before anything is sent. Sign up at <https://agent.proctorai.io>.
- To try the plugin for free, create a test key in your Proctor AI account. It returns sample marks and is never charged.
- Moodle cron must be running.

### Privacy

Nothing is sent until a site administrator ticks the consent box in the plugin settings. Student names, emails and ID numbers are never sent, only the submitted pages, the typed text, the assignment name and the marking key. Proctor AI deletes uploaded pages as soon as they are graded, and within 72 hours at most. Read the [privacy guide](https://github.com/UpendraAkki/moodle-assignfeedback_proctorai/blob/main/docs/privacy.md), the [privacy policy](https://www.proctorai.io/privacy) and the [terms](https://www.proctorai.io/terms).

### Guides

- [Administrator guide](https://github.com/UpendraAkki/moodle-assignfeedback_proctorai/blob/main/docs/admin-guide.md)
- [Teacher guide](https://github.com/UpendraAkki/moodle-assignfeedback_proctorai/blob/main/docs/teacher-guide.md)
- [What students see](https://github.com/UpendraAkki/moodle-assignfeedback_proctorai/blob/main/docs/student-guide.md)
- [Questions and unusual cases](https://github.com/UpendraAkki/moodle-assignfeedback_proctorai/blob/main/docs/faq.md)

## Links

| Field | Value |
|---|---|
| Source control URL | https://github.com/UpendraAkki/moodle-assignfeedback_proctorai |
| Bug tracker URL | https://github.com/UpendraAkki/moodle-assignfeedback_proctorai/issues |
| Documentation URL | https://github.com/UpendraAkki/moodle-assignfeedback_proctorai/blob/main/docs/README.md |
| Website | https://agent.proctorai.io |

## Plugin type and versions

- Type: Assignment feedback (`assignfeedback_proctorai`)
- Supported Moodle versions: 4.5, 5.0, 5.1, 5.2, 5.3

## Search terms

Use these where the form asks for tags or keywords, and they already appear in the text above: AI grading, automatic grading, auto grading, AI marking, handwriting recognition, handwritten answers, OCR, assignment grading, exam grading, marking key, answer key, rubric alternative, feedback, teacher review, grading assistant.

## Screenshots, in this order

1. `grader-panel.png`: The AI result in the grader, with the questions to check highlighted.
2. `markingkey-editor.png`: Writing the marking key question by question.
3. `confirm-page.png`: The cost and balance are shown before anything is sent.
4. `status-page.png`: Every student's grading, with retry and cancel.
5. `student-view.png`: What a student sees after release: grade, feedback and marks per question.
6. `settings.png`: The administrator's settings, with the connection and balance.

## Note for the plugins team (approval issue)

This plugin needs an account with the Proctor AI service. For your review, here is a test key that returns sample marks and is never charged: (paste the test key here, in the approval issue only, never in the repository). Leave the API URL at its default, tick "Allow sending student work to Proctor AI" in the plugin settings, then follow the teacher guide.
