# End-to-end test report: assignfeedback_proctorai on a running Moodle

Date: 2026-10-22. Plugin version 0.1.0 (`main` at 324fcc7) plus the fixes on this branch.

CI already covers phpcs, phpdoc and PHPUnit. This report covers what CI cannot show: the plugin working in a real site, through the browser, cron and the web services, against a mock of the Proctor AI API. The real API at agent.proctorai.io was never called.

## Summary

- 35 checks on Moodle 4.5, and 10 of them repeated on Moodle 5.0. 31 passed as written; 4 failed and were fixed.
- 4 bugs found and fixed on this branch, each with a PHPUnit test. 3 small wording fixes.
- 5 observations that are not fixed here (design questions or core Moodle behaviour).

| # | Bug | Severity | Fixed in |
|---|---|---|---|
| 1 | The cost confirmation page (bulk action and "Grade all ungraded") and the status page crashed with `Class "assign_header" not found`. Nothing could be queued from the UI. | High | `locallib.php` |
| 2 | Every admin settings page on the site called `GET /account` while Moodle built the admin tree. With a slow or hanging API each admin page took about 10.5 s instead of under 1 s. | Medium | `settings.php`, `classes/admin/setting_status.php` |
| 3 | After a 401 pause, an admin could not resume with the same key: Moodle only runs the settings callback when a value changes, so saving the page did nothing. | Medium | `classes/local/account.php` |
| 4 | When a job failed while the teacher had the grader open, the panel said "The Proctor AI result is ready". | Low | `amd/src/grader_panel.js`, lang |

## Setup

| Item | Value |
|---|---|
| OS | Ubuntu 24.04 (cloud container, no Docker daemon) |
| PHP | 8.3.6 with pgsql, intl, zip, gd, curl, mbstring, xml, soap, sodium; `max_input_vars=5000` |
| Database | PostgreSQL 17 (PGDG packages) |
| Moodle | 4.5.15+ (Build 20261021, `MOODLE_405_STABLE`) and 5.0.11 (`MOODLE_500_STABLE`) |
| Web server | `php -S` with 4 workers, one per site |
| Browser | Headless Chromium through Playwright 1.56 |
| Plugin | This repository copied to `mod/assign/feedback/proctorai`; installed by `admin/cli/install_database.php` (4.5) and `admin/cli/install.php` (5.0) with no errors |

**Mock API.** A small Node server outside the plugin (`/tmp/mockapi/server.js`, port 8099) implements the contract in `classes/api/client.php`:

- `GET /account` returns `{balance, pricePerSheet: 1, sheetsRemaining, maxPagesPerSheet: 20, acceptedTypes, maxImageBytes, maxPdfBytes}` and rejects keys that do not start with `pai_test_` or `pai_live_`.
- `POST /uploads` returns one `{uploadId: "upl_<24hex>", method: "PUT", url, headers: {"Content-Type"}, expiresAt}` per file, in order, with the PUT URL pointing back at the mock.
- `PUT /put/<uploadId>` stores the size and SHA-1 of the bytes and counts PDF pages.
- `POST /grade` returns the result from `grade_response()` in `tests/grading_testcase.php`, with `pageCount` from the uploads, and charges 1 from the balance. It checks that every upload was PUT, and it stores responses by `Idempotency-Key`: the same key with the same body is replayed with `Idempotent-Replayed: true`, a different body is refused.
- Every request is logged as one JSON line: method, path, headers, body (for a PUT: size, SHA-1 and first bytes).
- A control endpoint queues the next `/grade` response: 401, 402 (`error.balance: 0`), 422 `too_many_pages`, 429 with `Retry-After`, 502 (HTML body), or a forced replay. A 401 can also be queued for `/account`.

**Site settings.** On these test sites only: `curlsecurityblockedhosts` cleared and port 8099 added to `curlsecurityallowedport`. Plugin settings: `apiurl = http://localhost:8099/api/v1`, `apikey = pai_test_0123456789abcdef`, consent ticked in the UI.

**Test data** (created with the core data generators): course "E2E course", `teacher1` (editing teacher), `student1` and `student2`, and five assignments, each with file and online text submissions, grade out of 100, this plugin enabled and the marking key `KEY` from `tests/grading_testcase.php` (level "Grade 8", instructions "Be kind."):

| Assignment | Marking workflow | Drafts | Auto-grade |
|---|---|---|---|
| Workflow ON | on | on | off |
| Workflow OFF | off | on | off |
| Error paths | on | on | off |
| Auto-grade, drafts on | on | on | on |
| Auto-grade, drafts off | on | off | on |

Students submitted through `assign::save_submission()` and `assign::submit_for_grading()`, so the real `assessable_uploaded` and `assessable_submitted` events fired. Files: two JPG photos and a real 3-page PDF. Jobs were run with `php admin/cli/adhoc_task.php --execute`.

## Results on Moodle 4.5

### Admin settings

| Check | Result | Evidence |
|---|---|---|
| Status line with a key but no consent | Pass | "Tick "Allow sending student work to Proctor AI" below to start grading. Nothing is sent until you do." No request reached the mock. [screenshot](e2e/45-settings-noconsent.png) |
| Tick consent and save; status line | Pass | "Connected to http://localhost:8099/api/v1. Balance 42 (42 submissions at 1 each). Checked 22/10/26, 18:33." [screenshot](e2e/45-settings-ok.png) |
| Status line does not slow down other admin pages | **Fail, fixed (bug 2)** | Before: with the API URL pointing at a server that accepts connections but never answers, *Theme settings* took 10,736 ms, *Site policies* 10,406 ms and admin search 10,655 ms; with a working API, opening *Theme settings* sent `GET /account` to the mock. After the fix: 723 ms, 363 ms and 544 ms, and the plugin's own page still shows the status. |
| Status line while paused | Pass | "Paused: Proctor AI rejected the API key (The API key was revoked.). Save a valid key to resume." [screenshot](e2e/45-settings-paused.png) |
| Resume when the same key works again | **Fail, fixed (bug 3)** | Before: with the plugin paused and the mock accepting the key again, *Save changes* without editing left the site paused. After the fix: opening the page re-checks the key and resumes. [screenshot](e2e/45-settings-resumed.png). With the mock still returning 401 the page stays paused and no second "paused" notice is sent (count stayed 1). |

### Marking workflow ON

| Check | Result | Evidence |
|---|---|---|
| "Grade with Proctor AI" appears in the bulk actions | Pass | Under *More* in the sticky footer after selecting a student. [screenshot](e2e/45-wfon-s1-moremenu.png) |
| Cost confirmation page renders | **Fail, fixed (bug 1)** | Before: `Exception - Class "assign_header" not found` at `locallib.php:614` for both the bulk action and *Grade all ungraded*. [screenshot](e2e/45-bug1-before-confirm.png). The class moved to `\mod_assign\output\assign_header`. After the fix: "1 of the 1 selected students can be graded now. Cost per submission 1, Total cost 1, Balance 42 (42 submissions left)". [screenshot](e2e/45-wfon-s1-confirm.png) |
| "Grade all ungraded" with one student already queued | Pass | "1 of the 2 selected students can be graded now. Already queued: 1", cost 1, balance 42. [menu](e2e/45-wfon-all-actionsmenu.png), [confirmation](e2e/45-wfon-all-confirm.png) |
| Queueing goes to the status page | Pass | Both students "Waiting for AI grading", 42 submissions left. [screenshot](e2e/45-wfon-all-status.png) |
| After cron: grade pre-filled, "Ready for review", gradebook empty | Pass | `assign_grades.grade = 70.00` and `workflowstate = readyforreview` for both students; `grade_grades.finalgrade` NULL for both. |
| Grader panel | Pass | "AI total: 7 / 10 (70%) · 2 pages" (3 pages for the PDF student), "1 to check", question 2 highlighted (`tr.table-warning`, "Check this: Handwriting unclear"), per-question table with what was read, reasoning and confidence. [screenshot](e2e/45-wfon-s1-panel.png) |
| "Use AI grade" fills the grade field | Pass | Grade field cleared, button clicked: field value `70`. |
| Student sees nothing until release | Pass | Student view: no *Feedback* section, no grade, no AI text; user report shows "( Empty )". [student](e2e/45-wfon-student1-before.png), [gradebook](e2e/45-wfon-student1-before-gradebook.png) |
| Release | Pass | Teacher edited the AI text, set *Released* and saved: gradebook 70.00; student sees grade 70.00 and the edited text, not the original AI text. [screenshot](e2e/45-wfon-student1-after.png) |
| Grader panel updates while a job runs | **Fail, fixed (bug 4)** | Polling changed the badge from "Waiting for AI grading" to "Graded by AI" and showed "The Proctor AI result is ready" (pass). When the job failed (422) the badge said "AI grading failed" but the message still said "The Proctor AI result is ready". [before](e2e/45-grader-poll-failed.png). After the fix: "Proctor AI grading stopped (AI grading failed). Reload this student to see why." [after](e2e/45-grader-poll-failed-fixed.png) |

### Marking workflow OFF

| Check | Result | Evidence |
|---|---|---|
| Online text only: no upload | Pass | Mock log: only `POST /grade`, with `answerSheetText`, no `files`. |
| Result is only a suggestion | Pass | After cron `assign_grades.grade = -1`, gradebook empty, panel shows "Use AI grade (70) / 100" without the "pre-filled" line. |
| Student sees nothing until the teacher saves | Pass | Student view: "Not graded", no *Feedback*. [screenshot](e2e/45-wfoff-student1-before.png) |
| After saving the grader form | Pass | Teacher entered 65 and edited the text: student sees 65.00 and "Nice answers. Teacher edited this."; the result row has `approved = 1`. [screenshot](e2e/45-wfoff-student1-after.png) |

### Error paths (mock switches)

| Check | Result | Evidence |
|---|---|---|
| 401 pauses site-wide and notifies admins | Pass | Job `failed` / `invalid_api_key`; config `paused` set; notification "Proctor AI grading is paused" to the admin; confirmation page blocked. |
| Saving a new key requeues | Pass | New key typed into the settings page and saved: the job went back to `queued` with a new Idempotency-Key (`e1b4e0fe…` to `6dc9f043…`); cron: `done`. |
| 402 stops and notifies once | Pass | Job `nobalance` / `insufficient_balance`; "Proctor AI balance has run out" to the admin and the teacher. A second job then stopped as `nobalance` from the cached balance, with no request to the mock and no new notice (2 notices in total). |
| 422 fails with the message shown to the teacher | Pass | Job `failed` / `too_many_pages`. "This answer sheet has 25 pages; the limit is 20." in the grader panel, the status page and the grading table. [status page](e2e/45-status-errors.png), [panel](e2e/45-err422-panel.png) |
| 429 waits for Retry-After | Pass | `Retry-After: 120`: the task's next run moved from 17:39:34 to 17:41:34, `attempts` stayed 0, no fail delay. Run again: `done` with the same key. |
| 502 retries with the same key and body | Pass | Moodle marked the task failed (fail delay 60 s) and retried. Mock log: 1 `POST /uploads`, 1 `PUT`, then 2 `POST /grade` with the same `Idempotency-Key: a5873cfa-…` and identical JSON bodies. Job `done`, `attempts = 2`. |
| `Idempotent-Replayed: true` | Pass | Job `done`, result stored normally. |

### Privacy

| Check | Result | Evidence |
|---|---|---|
| No `studentName` in any request | Pass | Every `/grade` body in the mock log has only `title`, `questions`, `level`, `instructions`, `files` and/or `answerSheetText`. |
| Uploads named `page-N` | Pass | `POST /uploads` bodies: `{"contentType":"image/jpeg","size":27571,"name":"page-1"}`, `…"page-2"`; the PDF upload is `page-1`. The original names (`page1.jpg`, `answers3.pdf`) never appear. |
| PUT sends no API key and the right bytes | Pass | PUT requests have no `Authorization` header, `Content-Type` as given by `/uploads`, and the SHA-1 of the bytes equals the stored file's content hash. |
| Marking key not returned by `mod_assign_get_assignments` | Pass | Called over REST (`webservice/rest/server.php`, mobile service) with a student token and with a teacher token: no `proctorai` config at all, and no text of the key ("Paris", "capital of France"). |
| Unreleased AI result not in `mod_assign_get_submission_status` | Pass | For student 2 (Ready for review, not released) the response has no AI text and no grade. |
| `assignfeedback_proctorai_get_status` not usable by students | Pass | Not in the mobile service; through AJAX it requires `assignfeedback/proctorai:viewdetails`. |

### Status page

| Check | Result | Evidence |
|---|---|---|
| Status page renders | Pass (after bug 1) | Counts per status, one row per student with status, error text, AI marks, "1 to check" badge and last change; *Grade all ungraded* and *Back* buttons. [screenshot](e2e/45-status-errors.png) |

### Automatic grading

| Check | Result | Evidence |
|---|---|---|
| Drafts on: queued when submitted | Pass | Saving a draft queued nothing. *Submit for grading* queued a job ready at once; cron: grade 70, Ready for review. |
| Drafts off: 10-minute debounce | Pass | First save at 17:41:09: job ready at 17:51:09. Second save at 17:41:12 (2 files): same job, ready at 17:51:13. Running the task early only rescheduled it. With `timeready` moved into the past in the database the job ran and sent both files. |

## Results on Moodle 5.0

Repeated on 5.0.11 with the fixed plugin:

| Check | Result | Evidence |
|---|---|---|
| Install | Pass | `admin/cli/install.php`, plugin installed with no errors. |
| Settings: no consent, then connected | Pass | [screenshot](e2e/50-settings-ok.png) |
| Bulk action and *Grade all ungraded* confirmation | Pass | "1 of the 2 selected students can be graded now. Already queued: 1". [screenshot](e2e/50-wfon-all-confirm.png) |
| Workflow ON after cron | Pass | Both grades 70.00, `readyforreview`, gradebook empty. |
| Grader panel and "Use AI grade" | Pass | "AI total: 7 / 10 (70%) · Pages: 2", 1 flagged row, field set to 70. [screenshot](e2e/50-wfon-s1-panel.png) |
| Student before and after release | Pass | Nothing before; grade 70 and edited text after. [screenshot](e2e/50-wfon-student1-after.png) |
| Status page | Pass | [screenshot](e2e/50-status-wfon.png) |
| Workflow OFF | Pass | Nothing before saving; 65.00 and the edited text after. [screenshot](e2e/50-wfoff-student1-after.png) |
| 502 retry | Pass | 2 `/grade` calls, same key, same body, no `studentName`. |
| Marking key over REST as a student | Pass | No `proctorai` config, no key text. |

## Fixes on this branch

1. **Confirmation and status pages crash** (`locallib.php`). `render_page()` required `mod/assign/renderable.php` and used the global class name `assign_header`, which Moodle 4.5 no longer defines there. It now uses `\mod_assign\output\assign_header`. Test: `locallib_test::test_confirm_and_status_pages_render`.
2. **Admin pages call the API** (`settings.php`, new `classes/admin/setting_status.php`). The status line was computed in `settings.php`, which runs on every admin settings page because Moodle builds the whole admin tree. It is now a heading subclass that computes the status in `output_html()`, so only a page that shows it pays for it. Test: `account_test::test_admin_tree_does_not_call_api`.
3. **Cannot resume with the same key** (`classes/local/account.php`). When paused, the status line now re-checks the key with `GET /account`; if it works, the plugin resumes and requeues what the pause stopped (new `account::resume()`, also used by `settings_updated()`); if not, it stays paused and sends no new notice. Tests: `account_test::test_status_resumes_when_key_works_again`, `account_test::test_status_stays_paused_when_key_still_rejected`.
4. **Wrong message after a failed job** (`amd/src/grader_panel.js`, new string `resultstopped`). Only `done` says the result is ready; any other end state says grading stopped and why. `amd/build` was rebuilt with `grunt amd` from Moodle 4.5; rebuilding the old source first reproduced the committed build byte for byte.

Wording (lang only): "1 pages" is now "Pages: 1"; the confirm button "Grade 1 submissions" is now "Grade with Proctor AI (1)"; "1 submissions were queued" is now "Submissions queued for Proctor AI grading: 1."; the status table header no longer ends in a colon.

## Observations, not fixed

1. **Students see "Marking completed".** Moodle 4.5 labels the `readyforreview` state "Marking completed" on the student's own page. Students see no grade or feedback, but they can tell that marking happened. This is core behaviour for any Ready-for-review grade.
2. **Auto-grade submissions during a pause are dropped.** While paused, `observer::handle()` returns early and `job_manager::queue()` refuses, so submissions made during a pause are not graded when the key is fixed. Teachers can use *Grade all ungraded*. A fix would queue them as paused jobs so `resume()` picks them up; that changes the queue rules, so it is left for a decision.
3. **Batch notice on a pause.** A batch that hits a 401 sends the teacher "Proctor AI grading finished" with 1 failed, then sends it again when the requeued job finishes.
4. **Out-of-balance is remembered until the next refresh.** After a 402 the cached account says 0 left. Jobs stop without calling the API (good) until the cache is refreshed by the hourly task, the confirmation page or the settings page (after 5 minutes), even if the account was topped up sooner.
5. **Moodle rewrites uploaded JPGs.** Moodle 4.5's EXIF remover re-saves photos on upload, so the bytes sent are Moodle's stored file (27,571 bytes), not the original photo (16,687 bytes). The plugin sends exactly what Moodle stores, which is correct.

## PHPUnit and lint

Run on the Moodle 4.5 site (PHPUnit 9.6.34, PostgreSQL 17):

- `vendor/bin/phpunit --testsuite assignfeedback_proctorai_testsuite`: **OK (70 tests, 308 assertions)**. 65 tests before plus 5 new ones.
- Each new test was also run against the original `locallib.php`, `settings.php` and `account.php`: all 5 fail there (2 errors, 3 failures), so they catch the bugs.
- `phpcs --standard=moodle` (moodle-cs 3.7): no errors or warnings. Under `moodle-extra` the new code adds nothing; the only warnings are the existing "visibility on constants" ones.
- `local_moodlecheck` (the phpdoc check): no problems.
- `grunt amd` (ESLint and Rollup) from the Moodle 4.5 root: no warnings. Rebuilding the original source reproduced the committed `amd/build` byte for byte before the new build was made.
- The version bump (0.1.1, 2026102200) upgrades cleanly on both sites.

Bug 4 has no automated test: Moodle has no JavaScript unit tests for plugins, and a Behat test for a background job finishing while the grader is open would be slow and fragile. It was checked in the browser instead (see above).

## How to repeat

1. Copy the plugin to `mod/assign/feedback/proctorai`, install, and on a test site only clear `curlsecurityblockedhosts` and allow the mock's port in `curlsecurityallowedport`.
2. Run a mock that follows the contract above on that port, set *API URL* to it, use a `pai_test_` key and tick consent.
3. Create assignments as in the table above, submit as students, queue from the *Submissions* page, and run `php admin/cli/adhoc_task.php --execute`.
4. To skip the 10-minute wait: `UPDATE mdl_assignfeedback_proctorai_job SET timeready = <now - 5>` and the same for `nextruntime` on the task in `mdl_task_adhoc`.
