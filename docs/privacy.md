# Privacy and data

This page is for administrators and data protection officers. It explains exactly what leaves your Moodle site, what doesn't, and how long anything is kept.

## Nothing is sent without consent

The plugin sends nothing to Proctor AI until a site administrator ticks **Allow sending student work to Proctor AI** in the plugin settings. Unticking it stops all sending at once. Only users with the *Send submissions to Proctor AI for grading* permission (editing teachers and managers by default) can send work, and that permission is marked as a personal-data risk.

## What is sent, for each graded submission

| Sent | Not sent |
|---|---|
| The submitted photos and PDF files, renamed `page-1`, `page-2`, … (original file names stay in Moodle) | Student names, usernames, emails, ID numbers |
| The typed online text | Grades, other students' work, course names |
| The assignment name | The teacher's identity |
| The marking key, level and instructions | Anything from other Moodle plugins |

One caveat: if a student writes their own name on the page, it is visible in the photo. The plugin discards any name the AI reads off the page; it is not stored in Moodle.

## Where it goes and how long it is kept

- Everything goes over **HTTPS** to `agent.proctorai.io`. The plugin refuses to send the API key over an unencrypted connection, and never follows redirects to other servers.
- Files are uploaded to Proctor AI's **private** storage and **deleted as soon as the submission is graded**, and after 72 hours at the latest.
- To make sure a retried request is never charged twice, Proctor AI keeps the grading result, **encrypted**, for 72 hours, then deletes it.
- Proctor AI keeps billing records (which API key made a request, when, how many pages, the cost). They contain no student content.

## What is stored in Moodle

| Table | Contents | Kept |
|---|---|---|
| `assignfeedback_proctorai` | The AI result for each graded attempt: marks per question, what the AI read, its reasoning, and the feedback (as edited by the teacher) | As long as the grade exists |
| `assignfeedback_proctorai_job` | The grading queue: status, errors, files that couldn't be sent, and a copy of the request while it may still be retried | The request copy is removed when grading ends (or after 3 days); the queue entry after 90 days |

The API key is stored as a password setting. It is not shown back in the page, not included in backups and not exported by the privacy tools.

## Moodle's privacy tools

The plugin implements Moodle's Privacy API:

- **Metadata**: both tables and Proctor AI (as an external location) are declared, with every field.
- **Export**: a student's data export includes their AI results and their grading requests.
- **Delete**: deleting a student's data, or a whole assignment's, deletes the AI results and the queue entries.

Files already deleted by Proctor AI (normally within minutes, at most 72 hours) can't be exported or deleted from there. Nothing else about the student is held by Proctor AI.

## Before you tick the consent box

Check that sending student work to an external AI service fits your institution's data protection policy and, where required, that students and parents have been told. Read Proctor AI's [privacy policy](https://www.proctorai.io/privacy) and [terms](https://www.proctorai.io/terms).
