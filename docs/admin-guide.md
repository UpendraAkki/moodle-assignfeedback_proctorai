# Getting started for administrators

This guide takes about 15 minutes. You need:

- Moodle 4.5 to 5.3, with **cron running** (grading happens in Moodle's background tasks).
- An up-to-date PHP: PHP fixed two critical security flaws on 21 October 2026, so run at least 8.2.35, 8.3.36, 8.4.27 or 8.5.12 (PHP 8.1 has no fixed release; move to 8.2 or later). This protects your whole Moodle site, not only this plugin.
- Permission to install plugins on the site.
- A **Proctor AI account with a prepaid balance**. Sign up at [agent.proctorai.io](https://agent.proctorai.io). Each graded submission is paid from that balance.

## Step 1: Install the plugin

1. Download the plugin ZIP from [proctorai.io/moodle-ai-grading-plugin](https://www.proctorai.io/moodle-ai-grading-plugin).
2. In Moodle, go to *Site administration > Plugins > Install plugins* and upload the ZIP.

Prefer copying files? Unzip it so the plugin folder is at `mod/assign/feedback/proctorai` (on Moodle 5.1 and later: `public/mod/assign/feedback/proctorai`).

Then open *Site administration > Notifications* and click **Upgrade Moodle database now**.

## Step 2: Create an API key in Proctor AI

1. Sign in at [agent.proctorai.io](https://agent.proctorai.io).
2. Go to **Settings > API keys** and click **Create key**.
3. Copy the key straight away. It starts with `pai_live_`. Proctor AI shows it **only once**. If you lose it, revoke it and create a new one.

> **Want to try it first without paying?** Create a **test key** instead (it starts with `pai_test_`). A test key checks everything a real key checks, but it never charges you and returns the same sample marks every time. It is perfect for a practice course. Switch to a live key when you are ready.

Keep the key secret, like a password. Anyone with it can spend your balance.

## Step 3: Connect Moodle

Go to *Site administration > Plugins > Activity modules > Assignment > Feedback plugins > Proctor AI grading*.

1. Paste the key into **API key**.
2. Leave **API URL** as `https://agent.proctorai.io/api/v1`.
3. Read **Allow sending student work to Proctor AI** and tick it. This is your consent on behalf of the institution. Until it is ticked, **nothing** is sent. See [Privacy and data](privacy.md) before ticking.
4. Click **Save changes**.

The **Connection** box at the top of the page now shows, in green, "Connected… Balance … (N submissions at … each)". If it shows an error, see [Connection problems](#connection-problems) below.

## Step 4: Check the other settings

The defaults suit most sites.

| Setting | Default | What it does |
|---|---|---|
| Enabled by default | Off | Turns Proctor AI grading on for every new assignment. Most sites leave this off and let teachers choose. |
| Low balance warning | 20 | Administrators get a message when the balance covers fewer than this many submissions. 0 turns it off. |
| Attempts per submission | 5 | How often one grading is retried if Proctor AI cannot be reached. Retries are **never charged twice**. |
| Grade again when resubmitted | On | With automatic grading, a submission the student changes after it was graded is graded again (and charged again). |
| Automatic gradings per student | 3 | The most times one student's work in one assignment is graded *automatically*. This stops a student who resubmits many times from using up the balance. Gradings a teacher asks for are not counted. 0 means no limit. |
| Upload method | Direct | Keep **Direct**. Choose **Inline** only if your network blocks Proctor AI's file storage; Inline cannot send PDFs and limits each submission to 4 MB of photos. |

## Step 5: Who can do what

The plugin adds two permissions. You can change them under *Site administration > Users > Permissions > Define roles*.

| Permission | Given by default to | Allows |
|---|---|---|
| Send submissions to Proctor AI for grading | Teachers (editing), managers | Sending work to Proctor AI, which spends the balance. It is marked as a privacy risk because student work leaves the site. |
| See Proctor AI results per question | Teachers (editing and non-editing), managers | Seeing the AI's detailed marks, reasoning and confidence in the grader. |

Students never get either permission.

## Step 6: Tell your teachers

Send them the [Teacher guide](teacher-guide.md). It covers everything they need.

## Money: how the balance works

- Every submission Proctor AI grades costs the **Cost per submission** shown on the settings page (a group submission is charged once for the whole group).
- Before anything is sent, teachers see a confirmation page with the number of submissions, the total cost and the balance.
- You are **not** charged when: a grading fails, the file can't be read, the submission has more than 20 pages, a grading is cancelled before it starts, or a retry happens after a network problem.
- Top up in your Proctor AI account. The plugin notices the new balance within about 5 minutes, or straight away when you open the settings page or an assignment's status page.

## Messages administrators receive

| Message | When | What to do |
|---|---|---|
| Proctor AI balance is low | The balance covers fewer submissions than *Low balance warning* | Top up in Proctor AI. |
| Proctor AI balance has run out | A grading was refused for lack of balance (at most once every 6 hours) | Top up. Teachers can then click **Retry everything stopped by the balance** on the status page. |
| Proctor AI grading is paused | Proctor AI rejected the API key (for example, it was revoked) | Paste a valid key and save. Everything that was waiting is graded automatically after that. |

## Connection problems

| The Connection box says | Likely reason | Fix |
|---|---|---|
| Not connected. Enter your Proctor AI API key below. | No key saved yet | Paste the key and save. |
| Tick "Allow sending student work…" | Consent not given | Tick the box and save. |
| Paused: Proctor AI rejected the API key | Key revoked, mistyped, or from another account | Copy a fresh key from Proctor AI and save. If the same key works again, simply opening this page resumes grading. |
| Could not reach Proctor AI | Your server cannot reach agent.proctorai.io | Check your firewall or proxy allows HTTPS to `agent.proctorai.io` and to Proctor AI's file storage. If the storage is blocked and cannot be opened, switch **Upload method** to Inline. Also check *Site administration > General > Security > HTTP security*: `agent.proctorai.io` must not be in the blocked hosts list. |

## Removing the plugin

*Site administration > Plugins > Plugins overview*, find **Proctor AI grading**, click **Uninstall**. This deletes the plugin's stored AI results and its queue from Moodle. Grades already released stay in the gradebook. To stop sending work without uninstalling, untick the consent box.
