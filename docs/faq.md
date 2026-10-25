# What if…? Questions and unusual cases

Short answers to the situations people ask about most. Each answer says what the plugin does and what, if anything, you need to do.

## Marking and accuracy

**Can the AI give a student the wrong mark?**
Yes, sometimes, which is why a teacher always reviews it first. The AI marks the questions it is unsure about with **Check this**, and shows what it read and why it gave each mark, so you can spot mistakes quickly. You can change any mark before anyone sees it.

**What if the handwriting is very hard to read?**
The AI says so: the question is flagged **Check this**, with a reason such as "Handwriting is unclear", and its confidence is low. If a whole page can't be read at all, the grading stops with the reason, and nothing is charged.

**What if a photo is blurry, sideways or upside down?**
Sideways and upside-down photos are usually fine. Very blurry or dark photos lead to low confidence and "Check this" flags. Ask the student to resubmit a clearer photo if needed (see the [photo tips](student-guide.md#tips-for-photos-the-ai-can-read-well)).

**The student answered the questions in a different order.**
That's fine. The AI finds each answer by the question numbers the student wrote. Ask students to number their answers clearly.

**The student answered more questions than needed ("answer any three of five").**
Write that in **Instructions for the AI grader**, for example "Students answer any three of questions 1 to 5; count the best three". Check the total when you review, since this is guidance for the AI rather than a fixed rule.

**Does it understand diagrams, maths and other languages?**
It reads handwriting, numbers, formulas and simple diagrams. Drawings that need judgement (a labelled biology diagram, a graph's shape) are more often flagged "Check this". Give an **Expected answer** in the marking key wherever you can; it makes the marking more consistent.

**Can it mark rubrics, marking guides or scales (Pass/Merit/Distinction)?**
Not directly. For those assignments the AI's result is shown as a percentage, as a guide while you fill in the rubric yourself.

## Submissions

**A student uploaded a Word document (or another file the AI can't read).**
That file is left out and the rest is graded. The grader and the status page show a yellow note naming the file and the reason, so you know to mark that part yourself. Readable files are JPG, PNG, WebP, HEIC and PDF, plus typed online text.

**A photo is larger than 10 MB, or a PDF larger than 30 MB.**
The same: that file is left out and you are told. Phones rarely produce photos over 10 MB; a scanner on its highest setting can.

**More than 20 pages.**
The submission can't be graded as one sheet. It is left out of the batch ("More than 20 files") or stops with "at most 20 pages", and nothing is charged. Ask the student to remove extra pages, or mark it yourself.

**A student submits only typed text, or photos and typed text together.**
Both work. Photos and typed text are graded together as one answer sheet.

**A student changes their submission while it is waiting to be graded.**
The old version is not graded (status "Changed after it was queued") and nothing is charged. With automatic grading, the new version is queued.

**A student changes their submission after it was graded.**
- If you haven't approved the result yet and automatic grading is on, the new version is graded again (and charged again). The administrator can limit how often this happens per student, or turn it off.
- If you **have** approved or released the result, it is **not** graded again automatically, so the student keeps seeing what you approved. You can send it again yourself; the new result is a draft until you approve it.

**The assignment allows several attempts.**
Each attempt is graded separately. The AI result belongs to the attempt it graded.

**Group submissions.**
The group's submission is graded once and charged once. Every member gets the same result. On the confirmation page, other members of the same group are listed as "Same group as another selected student".

## Grades and what students see

**Will the AI overwrite a grade I already gave?**
Never. If a teacher has graded the attempt, the AI's result is shown as a suggestion only.

**Can students see the AI result before I check it?**
No.
- With **marking workflow**: the grade is a draft and the gradebook stays empty until you release it.
- Without it: students see nothing until you save the grader form or use **Apply Proctor AI marks**.

**I released marks, then want the AI to look again.**
Set the marking workflow state back to **In review** first. While a grade is released, the plugin won't send that student's work again, so a new, unchecked result can't appear.

**Can students see the marking key?**
No, not on the website, in the mobile app or through web services.

## Money and the balance

**When is the balance charged?**
Once per submission that Proctor AI actually grades (once per group for group submissions). The confirmation page shows the cost first.

**When is it not charged?**
When grading fails, a page can't be read, there are more than 20 pages, the job is cancelled before it starts, or the plugin retries after a network problem. A retry reuses the same request, so Proctor AI recognises it and never charges twice.

**The balance ran out in the middle of a batch.**
The remaining submissions stop with "Not graded: balance ran out". Administrators and the teacher are notified. After a top-up, click **Retry everything stopped by the balance** on the status page. The plugin notices the new balance within about 5 minutes.

**Can a student make us spend a lot by resubmitting again and again?**
Not by much. Automatic gradings per student are capped (3 by default), and teacher-requested gradings don't count towards that cap.

## Problems

**Proctor AI is down or slow.**
The plugin waits and tries again later, up to the number of attempts the administrator set (5 by default). Retries are never charged twice. If it still fails, the status page shows "AI grading failed" with the reason, and **Retry** sends it again later, still without a second charge.

**"Proctor AI grading is paused because the API key was rejected."**
The key was revoked, mistyped, or belongs to another account. An administrator must save a valid key. Everything that was waiting is then graded automatically; nothing is lost.

**"Proctor AI is not set up on this site yet."**
The administrator hasn't entered a key or ticked the consent box. See the [administrator guide](admin-guide.md).

**"The marking key has an error."**
Open the assignment settings: the marking key section shows which line is wrong. Fix it and save.

**Nothing happens after I click Grade.**
Grading runs in Moodle's background tasks (cron). If the status stays "Waiting for AI grading" for more than 10 minutes, ask your administrator to check that cron is running.

**I cancelled a grading by mistake.**
Click **Retry** on the status page.

## Backup, copying and deleting

**Course backup and restore.**
Results are included in user-data backups and restored with the course. Waiting gradings are not copied.

**Deleting a student's data (GDPR requests).**
Moodle's privacy tools export and delete this plugin's data with the rest of the student's assignment data. See [Privacy and data](privacy.md).
