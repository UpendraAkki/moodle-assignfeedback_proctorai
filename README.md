# Proctor AI grading for Moodle assignments

`assignfeedback_proctorai` is an Assignment feedback plugin that grades students' handwritten work (photos or PDFs) and typed online-text answers with [Proctor AI](https://agent.proctorai.io), against a marking key the teacher writes. **The AI result is always a draft:** the teacher reviews it, can change anything, and decides when students see it.

> A paid Proctor AI account is required. Each graded submission is charged to the account's prepaid balance. A `pai_test_` test key returns a fixed result and is never charged.

- Moodle 4.5 LTS to 5.3, PHP 8.1 or later
- Status: beta (0.1.1)
- Licence: GNU GPL v3 or later

> **Keep PHP patched.** On 21 October 2026 PHP fixed two critical remote-code-execution flaws ([CVE-2026-109618 and CVE-2026-109619](https://www.php.net/archive/2026.php)). Run at least PHP 8.2.35, 8.3.36, 8.4.27 or 8.5.12. PHP 8.1 has no fixed release, only source patches, so a site on 8.1 should move to 8.2 or later. This affects your whole Moodle site, not just this plugin.

## Installation

1. Copy this repository to `mod/assign/feedback/proctorai` in your Moodle code (on Moodle 5.1 and later, `public/mod/assign/feedback/proctorai`), or install the ZIP from *Site administration > Plugins > Install plugins*.
2. Visit *Site administration > Notifications* to finish the installation.
3. Go to *Site administration > Plugins > Activity modules > Assignment > Feedback plugins > Proctor AI grading* and:
   - paste your **API key** (create one in your Proctor AI account under *Settings > API*);
   - leave **API URL** as `https://agent.proctorai.io/api/v1` unless Proctor AI support tells you otherwise;
   - read and tick **Allow sending student work to Proctor AI**. Nothing is sent until you do.

   The *Connection* line at the top of the page shows whether the key works and how many submissions the balance covers.
4. Make sure cron runs. Grading happens in Moodle's background tasks (`\assignfeedback_proctorai\task\grade_submission`).

## Using it

In an assignment's settings, enable **Proctor AI grading** under *Feedback types* and write a **marking key**:

```
1. Name the capital of France. [2]
Answer: Paris
2. Which number is prime? [1]
Options: 4 | 7 | 9
3. Photosynthesis
3a. Name the gas plants take in. [1]
Answer: Carbon dioxide
3b. Name the gas plants give out. [1]
```

One question per numbered line with its marks in square brackets; `Answer:` and `Options:` lines are optional; sub-questions are numbered `3a`, `3b`, and each needs marks. If the key has a mistake, the settings page shows which line, and nothing can be graded until it is fixed.

Then, on the *Submissions* page:

- select students and choose **Grade with Proctor AI** from the bulk actions, or use **Grade all ungraded with Proctor AI** from the actions menu. A confirmation page shows how many submissions will be graded, the cost and your balance before anything is sent;
- or turn on **Grade automatically on submission** in the assignment settings.

When a result arrives, the grader shows the AI total, the questions the AI wants you to check (highlighted), what it read from the student's answer, its reasoning and confidence, and a **Use AI grade** button. The *Proctor AI feedback for the student* box holds the AI's comments, which you can edit.

### What students see

- **With marking workflow:** the AI grade is pre-filled as a draft and the student is moved to *Ready for review*. This only happens for point grades, without a rubric or marking guide, where no teacher has graded yet. The gradebook stays empty and students see nothing until you release the grade.
- **Without marking workflow:** the AI result is only a suggestion. Students see nothing until you save the grader form (which approves the feedback text). The grade is whatever you enter.

The marking key is never shown to students, including through the mobile app and web services.

### Errors

| What happened | What the plugin does |
|---|---|
| Proctor AI rejects the API key | Pauses AI grading site-wide and notifies administrators. Saving a new key resumes the queued gradings. If the same key works again, opening the plugin settings page checks it and resumes. |
| The balance runs out | Stops, marks the submissions "balance ran out" and notifies administrators and the teacher (at most every 6 hours). |
| A page is unreadable, or there are more than 20 pages | Marks that submission as failed and shows Proctor AI's reason to the teacher. |
| Proctor AI is busy or unreachable | Retries later with backoff, up to the *Attempts per submission* setting. Retries are never charged twice. |
| The student changes the submission after it was queued | That grading is dropped; with automatic grading, the new version is queued. |

## Privacy

When an administrator ticks the consent box, the plugin sends Proctor AI, for each submission it grades:

- the submitted image and PDF files (renamed `page-1`, `page-2` and so on) and any online text;
- the assignment name, the marking key, the level and the instructions.

**Student names, emails and IDs are not sent.** Proctor AI deletes uploaded files within 72 hours. The AI result is stored in Moodle (`assignfeedback_proctorai`), linked to the student's grade; the request queue (`assignfeedback_proctorai_job`) is cleaned up after 90 days. The plugin implements Moodle's Privacy API, including export and deletion, and declares Proctor AI as an external location.

Check that sending student work to Proctor AI agrees with your institution's data protection policy before giving consent.

## Capabilities

- `assignfeedback/proctorai:requestgrading`: send submissions to Proctor AI (editing teachers and managers by default). Carries the personal-data risk flag.
- `assignfeedback/proctorai:viewdetails`: see the per-question AI result in the grader (all teachers by default).

## Development

The plugin talks to the API documented at <https://agent.proctorai.io/developers> (`GET /account`, `POST /uploads`, then a `PUT` per file, then `POST /grade` with an `Idempotency-Key`). All HTTP goes through Moodle's `\curl`, so proxy settings and the blocked-hosts list apply.

Run the tests inside a Moodle checkout:

```
vendor/bin/phpunit --testsuite assignfeedback_proctorai_testsuite
```

HTTP is mocked: `\curl::mock_response()` for successful calls, and `tests/fake_client.php` (registered through `\core\di`) for error statuses and headers that the curl mock cannot fake. CI runs [moodle-plugin-ci](https://github.com/moodlehq/moodle-plugin-ci) on Moodle 4.5, 5.0 and 5.3.

After changing `amd/src`, rebuild with `grunt amd` from the Moodle root.

### Staging

To test against a Proctor AI staging server, set **API URL** to the staging address and use a `pai_test_` key. A test key is never charged and returns fixed sample marks.

If the staging server sits behind an access gate that needs a request header (for example Vercel Deployment Protection), add the header in `config.php`. There is deliberately no admin setting for this:

```php
$CFG->assignfeedback_proctorai_extraheaders = [
    'x-vercel-protection-bypass' => '<secret from Vercel>',
];
```

These headers are sent only to the API URL, never to the presigned upload URLs, and they cannot replace the plugin's own `Authorization`, `Content-Type`, `Accept` or `Idempotency-Key` headers. Treat the value as a secret: keep it out of version control and remove it on production sites.

## Licence

Copyright 2026 Proctor AI. This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. See [LICENSE](LICENSE).
