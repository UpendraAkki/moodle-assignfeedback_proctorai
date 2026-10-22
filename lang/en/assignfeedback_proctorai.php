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

/**
 * English strings for the Proctor AI feedback plugin.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['aitotal'] = 'AI total:';
$string['aitotalcolumn'] = 'AI total';
$string['alreadyqueued'] = 'This submission is already queued for AI grading.';
$string['apierror'] = 'Proctor AI error {$a->status} {$a->code}: {$a->message}';
$string['apikey'] = 'API key';
$string['apikey_desc'] = 'A Proctor AI API key (starts with pai_live_, or pai_test_ for a test key that returns a fixed result and is never charged). Create one in your Proctor AI account under Settings, API. Every grading is charged to this key\'s account.';
$string['apiurl'] = 'API URL';
$string['apiurl_desc'] = 'Leave as the default unless Proctor AI support gives you a different address, for example a staging server.';
$string['approved'] = 'Approved';
$string['assignfeedback/proctorai:requestgrading'] = 'Send submissions to Proctor AI for grading';
$string['assignfeedback/proctorai:viewdetails'] = 'See Proctor AI results per question';
$string['autograde'] = 'Grade automatically on submission';
$string['autograde_help'] = 'If enabled, each submission is sent to Proctor AI when the student submits it (or, without the "submit" button, 10 minutes after their last upload). The result is still a draft for you to review. Each automatic grading is charged.';
$string['autogradecap'] = 'Automatic gradings per student';
$string['autogradecap_desc'] = 'The most times one student\'s submissions to one assignment are graded automatically, so repeated resubmissions cannot use up the balance. 0 means no limit. Gradings a teacher requests are not counted.';
$string['balance'] = 'Balance';
$string['batchgrade'] = 'Grade with Proctor AI';
$string['batchgradeconfirm'] = 'Send the selected submissions to Proctor AI for grading? You will see the cost before anything is sent.';
$string['cachedef_account'] = 'Proctor AI account balance and limits';
$string['confidence'] = 'Confidence: {$a}';
$string['confirmbutton'] = 'Grade with Proctor AI ({$a})';
$string['confirmintro'] = '{$a->count} of the {$a->requested} selected students can be graded now.';
$string['confirmprivacy'] = 'The submitted files and text, the assignment name and its marking key are sent to Proctor AI. Student names are not sent. The results are drafts: students see nothing until you review and release them.';
$string['confirmtitle'] = 'Grade with Proctor AI';
$string['connectionstatus'] = 'Connection';
$string['consent'] = 'Allow sending student work to Proctor AI';
$string['consent_desc'] = 'Required. When ticked, students\' submitted files and online text, assignment names and marking keys are sent to Proctor AI (agent.proctorai.io) to be graded. Student names are not sent. Uploads are deleted by Proctor AI within 72 hours. Nothing is sent while this is unticked. Check that this agrees with your institution\'s data protection policy.';
$string['costpersheet'] = 'Cost per submission';
$string['costtotal'] = 'Total cost';
$string['default'] = 'Enabled by default';
$string['default_help'] = 'If set, Proctor AI grading is enabled by default for all new assignments.';
$string['details'] = 'Details';
$string['draft'] = 'Draft';
$string['draftnote'] = 'Draft: students see none of this until you save this form (or release the grade, with marking workflow).';
$string['enabled'] = 'Proctor AI grading';
$string['enabled_help'] = 'If enabled, submissions can be graded by Proctor AI against a marking key. The AI result is a draft that the teacher reviews before students see anything. A paid Proctor AI account is required.';
$string['feedbackforstudent'] = 'Proctor AI feedback for the student';
$string['feedbackforstudent_help'] = 'Written by the AI from its result. Edit it as you like. The student sees it once you save this form, or, with marking workflow, once the grade is released.';
$string['gradeall'] = 'Grade all ungraded with Proctor AI';
$string['improvements'] = 'To improve';
$string['instructions'] = 'Instructions for the AI grader';
$string['instructions_help'] = 'Optional guidance for the AI, for example "Accept answers in either British or American spelling" or "Award method marks for correct working". Up to 2000 characters.';
$string['insufficient'] = 'The balance does not cover all of these submissions. Grading will stop when it runs out; buy more gradings in your Proctor AI account.';
$string['lastchange'] = 'Last change';
$string['level'] = 'Level';
$string['level_help'] = 'Optional: the level of the students, for example "Grade 8" or "A-level". It helps the AI judge the expected depth of answers.';
$string['lowbalance'] = 'Low balance warning';
$string['lowbalance_desc'] = 'Notify site administrators when fewer than this many submissions can be graded with the remaining balance. 0 turns the warning off.';
$string['markingkey'] = 'Marking key';
$string['markingkey_help'] = 'One question per numbered line, with its marks in square brackets. Add the expected answer on an "Answer:" line and multiple-choice options on an "Options:" line separated by |. Number sub-questions 3a, 3b and so on; each needs its own marks. Other lines continue the line above.

    1. Name the capital of France. [2]
    Answer: Paris
    2. Which number is prime? [1]
    Options: 4 | 7 | 9
    3. Photosynthesis
    3a. Name the gas plants take in. [1]
    Answer: Carbon dioxide
    3b. Name the gas plants give out. [1]

The marking key is sent to Proctor AI but never shown to students.';
$string['markingkeyanswertoolong'] = 'The answer is longer than {$a} characters.';
$string['markingkeybadmarks'] = 'Marks must be more than 0 and at most {$a}.';
$string['markingkeyduplicate'] = 'Question {$a} appears twice.';
$string['markingkeyempty'] = 'The marking key has no questions.';
$string['markingkeyemptyprompt'] = 'The sub-question has no text.';
$string['markingkeyerror'] = 'The marking key has an error. Fix it in the assignment settings.';
$string['markingkeyerrorsaved'] = 'The saved marking key has an error, so nothing can be graded until it is fixed: {$a}';
$string['markingkeyinvalid'] = '{$a}';
$string['markingkeyline'] = 'Line {$a->line}: {$a->message}';
$string['markingkeymissing'] = 'Add a marking key in the assignment settings first.';
$string['markingkeynoquestion'] = 'This line is not part of a question. Start questions with a number, such as "1." or "2a.".';
$string['markingkeyoptioncount'] = 'Give between {$a->min} and {$a->max} options, separated by |.';
$string['markingkeyoptiontoolong'] = 'An option is longer than {$a} characters.';
$string['markingkeyprompttoolong'] = 'The question is longer than {$a} characters.';
$string['markingkeystemanswer'] = 'A question with sub-questions cannot have its own answer; put the answers on the sub-questions.';
$string['markingkeysubmarks'] = 'Sub-question {$a} needs marks in square brackets, such as [2].';
$string['markingkeysuboptions'] = 'Options can only be given for a question without sub-questions.';
$string['markingkeytoolong'] = 'The marking key is too long.';
$string['markingkeytoomany'] = 'The marking key has more than {$a} questions.';
$string['markingkeytoomanysubs'] = 'Question {$a->n} has more than {$a->max} sub-questions.';
$string['marks'] = 'Marks';
$string['maxattempts'] = 'Attempts per submission';
$string['maxattempts_desc'] = 'How many times one grading is tried when Proctor AI is unreachable or fails. Retries reuse the same request, so a submission is never charged twice.';
$string['messageprovider:batchcomplete'] = 'Proctor AI grading of a batch has finished';
$string['messageprovider:lowbalance'] = 'Proctor AI balance is low';
$string['messageprovider:nobalance'] = 'Proctor AI balance has run out';
$string['messageprovider:paused'] = 'Proctor AI grading paused because the API key was rejected';
$string['msgbatchbody'] = 'Proctor AI has finished grading "{$a->name}": {$a->done} graded, {$a->failed} not graded. The results are drafts for you to review: {$a->url}';
$string['msgbatchsubject'] = 'Proctor AI grading finished: {$a->name}';
$string['msglowbalancebody'] = 'The Proctor AI account used by this site can grade {$a} more submissions. Buy more gradings in your Proctor AI account to avoid interruptions.';
$string['msglowbalancesubject'] = 'Proctor AI balance is low';
$string['msgnobalancebody'] = 'Proctor AI grading has stopped because the account balance has run out. Buy more gradings in your Proctor AI account, then grade the remaining submissions again.';
$string['msgnobalancesubject'] = 'Proctor AI balance has run out';
$string['msgpausedbody'] = 'Proctor AI rejected the API key ({$a->reason}), so AI grading is paused on this site. Enter a valid key at {$a->url}; queued gradings resume when you save.';
$string['msgpausedsubject'] = 'Proctor AI grading is paused';
$string['needsreview'] = 'Check this';
$string['nobalance'] = 'The Proctor AI balance has run out.';
$string['nojobs'] = 'Nothing has been sent to Proctor AI for this assignment yet.';
$string['notconfigured'] = 'Proctor AI is not set up on this site yet: an administrator must enter an API key and allow sending student work.';
$string['notenabled'] = 'Proctor AI grading is not enabled for this assignment.';
$string['nothingtograde'] = 'The submission has no file or text that Proctor AI can read.';
$string['pagesn'] = 'Pages: {$a}';
$string['paused'] = 'Proctor AI grading is paused because the API key was rejected. An administrator must update it.';
$string['percentonlynote'] = 'This assignment uses a scale or an advanced grading method, so the AI result is shown as a percentage only.';
$string['perquestion'] = 'Marks per question';
$string['pluginname'] = 'Proctor AI grading';
$string['prefillednote'] = 'Pre-filled as a draft grade; the gradebook stays empty until you release it.';
$string['privacy:metadata:approvedby'] = 'The teacher who approved the AI feedback.';
$string['privacy:metadata:assignmentid'] = 'The assignment.';
$string['privacy:metadata:earned'] = 'The marks the AI awarded.';
$string['privacy:metadata:errormessage'] = 'Why the grading request failed, if it did.';
$string['privacy:metadata:feedbacktext'] = 'The feedback for the student, written by the AI and edited by the teacher.';
$string['privacy:metadata:grade'] = 'The grade the result belongs to.';
$string['privacy:metadata:jobtable'] = 'Requests to grade a submission with Proctor AI.';
$string['privacy:metadata:jobuserid'] = 'The student whose submission is graded.';
$string['privacy:metadata:maxmarks'] = 'The marks available.';
$string['privacy:metadata:proctorai'] = 'Submissions are sent to Proctor AI, an external AI grading service, to be graded. Uploaded files are deleted by Proctor AI within 72 hours.';
$string['privacy:metadata:proctorai:assignmentname'] = 'The assignment name.';
$string['privacy:metadata:proctorai:markingkey'] = 'The assignment\'s marking key: questions, marks and expected answers.';
$string['privacy:metadata:proctorai:onlinetext'] = 'The text the student typed into the submission.';
$string['privacy:metadata:proctorai:submissionfiles'] = 'The photos and PDF files the student submitted.';
$string['privacy:metadata:requestedby'] = 'The teacher who asked for the grading.';
$string['privacy:metadata:requestjson'] = 'A copy of the grading request, including the student\'s typed answers, kept until the request can no longer be retried.';
$string['privacy:metadata:resultjson'] = 'The AI result per question, including what it read from the student\'s answer.';
$string['privacy:metadata:resulttable'] = 'The Proctor AI result for each graded submission.';
$string['privacy:metadata:status'] = 'The state of the grading request.';
$string['privacy:metadata:submission'] = 'The submission being graded.';
$string['privacy:metadata:suggestedgrade'] = 'The AI result on the assignment\'s grade scale.';
$string['privacy:metadata:timecreated'] = 'When the request was made.';
$string['privacy:metadata:timemodified'] = 'When the result was last changed.';
$string['privacy:path'] = 'Proctor AI result';
$string['question'] = 'Question';
$string['questionn'] = 'Question {$a}';
$string['queuedn'] = 'Submissions queued for Proctor AI grading: {$a}.';
$string['reasoning'] = 'Why:';
$string['regradeonresubmit'] = 'Grade again when resubmitted';
$string['regradeonresubmit_desc'] = 'With automatic grading, grade a submission again if the student changes it after it was graded.';
$string['resultready'] = 'The Proctor AI result is ready. Reload this student to see it.';
$string['resultstopped'] = 'Proctor AI grading stopped ({$a}). Reload this student to see why.';
$string['sheetsn'] = '{$a} submissions left';
$string['skip_alreadyqueued'] = 'Already queued';
$string['skip_nothingtograde'] = 'Nothing Proctor AI can read';
$string['skip_notsubmitted'] = 'Not submitted';
$string['skip_toomanyfiles'] = 'More than 20 files';
$string['status'] = 'Status';
$string['status_cancelled'] = 'Cancelled';
$string['status_done'] = 'Graded by AI';
$string['status_failed'] = 'AI grading failed';
$string['status_grading'] = 'AI grading in progress';
$string['status_nobalance'] = 'Not graded: balance ran out';
$string['status_none'] = 'Not sent to AI';
$string['status_queued'] = 'Waiting for AI grading';
$string['status_stale'] = 'Changed after it was queued';
$string['status_uploading'] = 'Uploading to AI';
$string['statuserror'] = 'Could not reach Proctor AI: {$a}';
$string['statusnoconsent'] = 'Tick "Allow sending student work to Proctor AI" below to start grading. Nothing is sent until you do.';
$string['statusnokey'] = 'Not connected. Enter your Proctor AI API key below.';
$string['statusok'] = 'Connected to {$a->url}. Balance {$a->balance} ({$a->sheets} submissions at {$a->price} each). Checked {$a->checked}.';
$string['statuspage'] = 'Proctor AI status';
$string['statuspaused'] = 'Paused: Proctor AI rejected the API key ({$a}). Save a valid key to resume.';
$string['strengths'] = 'Strengths';
$string['student'] = 'Student';
$string['studentanswer'] = 'Read as:';
$string['summaryai'] = 'AI: {$a->earned} / {$a->max} ({$a->percentage}%)';
$string['summaryreview'] = '{$a} to check';
$string['taskcleanup'] = 'Clean up Proctor AI grading jobs';
$string['taskgradesubmission'] = 'Grade a submission with Proctor AI';
$string['taskrefreshbalance'] = 'Refresh the Proctor AI balance';
$string['teamnotsupported'] = 'Proctor AI grading does not support group submissions yet.';
$string['toomanyattempts'] = 'Proctor AI could not grade this submission after several attempts. Try again later.';
$string['toomanyfiles'] = 'The submission has more than 20 files. Combine the pages into one PDF and resubmit.';
$string['usegrade'] = 'Use AI grade ({$a})';
