# Teacher guide

Proctor AI reads your students' answers and suggests marks. **You always have the final say.** Nothing the AI writes reaches a student until you approve it.

Before you start, your Moodle administrator must have connected the site to Proctor AI (see the [administrator guide](admin-guide.md)). If they haven't, the assignment page tells you so.

## 1. Turn it on in an assignment

1. Create an Assignment, or open an existing one and click **Settings**.
2. Under **Submission types**, allow what your students will hand in:
   - **File submissions** for photos of handwritten work or a scanned PDF;
   - **Online text** for typed answers;
   - or both.
3. Under **Feedback types**, tick **Proctor AI grading**.
4. Fill in the **Marking key** (next section).
5. Optional:
   - **Level**: for example "Grade 8" or "A-level". It helps the AI judge how detailed an answer should be.
   - **Instructions for the AI grader**: for example "Accept British or American spelling" or "Give method marks for correct working".
   - **Grade automatically on submission**: see [step 3](#3-send-the-work-to-proctor-ai).
6. Click **Save and display**.

## 2. Write the marking key

The marking key tells the AI the questions, how many marks each is worth and, if you like, the right answer.

### The easy way: question by question

Open the **Marking key** section. You will see a form with one box per question:

- **Question**: the question as the students saw it.
- **Marks**: how many marks it is worth.
- **Expected answer** (optional): the answer, or what a good answer must include. The more you give, the more consistent the marking.
- **Multiple-choice options** (optional): the choices, separated by `|`, for example `4 | 7 | 9`.
- **Add a part**: for questions with parts (3a, 3b, …). Each part gets its own marks.

Below the questions, **Total marks** shows the marks of all questions next to the assignment's maximum grade. If the total doesn't match, the AI's mark is scaled: 7 out of 10 marks on a 100-point assignment becomes 70.

### The text way

Click **Edit as text** to type the key instead. The format is:

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

- One question per numbered line, with its marks in square brackets.
- `Answer:` and `Options:` lines are optional.
- Parts are numbered `3a`, `3b` and so on, and each needs its own marks.

If something is wrong (for example a question without marks), the settings page tells you which line. **Nothing can be graded until the key is fixed.**

> Students never see the marking key, not in Moodle, not in the mobile app.

## 3. Send the work to Proctor AI

There are three ways. All of them show the cost before anything is sent, except automatic grading (which you switch on knowingly).

**A. Some students:** on the **Submissions** page, tick the students, then choose **Grade with Proctor AI** from the bulk actions at the bottom.

**B. Everyone not yet graded:** on the **Submissions** page, open the actions menu and choose **Grade all ungraded with Proctor AI**.

**C. Automatically:** in the assignment settings, turn on **Grade automatically on submission**. Each submission is sent when the student clicks *Submit* (or, if your assignment has no submit button, 10 minutes after their last upload). Each automatic grading is charged, and the administrator can limit how many times one student's work is graded automatically.

For A and B, a **confirmation page** shows:

- how many submissions will be graded, and who is left out and why (for example "Not submitted" or "Already queued");
- the **cost per submission**, the **total cost** and the **balance**.

Click **Grade with Proctor AI (N)** to send them. Grading happens in the background and usually takes a minute or two per submission. You get a Moodle notification when the whole batch is done.

## 4. Check the AI's work

Open a student in the grader (**Submissions > Grade**). Below the grade box you'll find the **Proctor AI grading** panel:

- **AI total**: for example *7 / 10 (70%)*, and how many pages it read.
- **N to check**: questions the AI is unsure about. These rows are highlighted. Always look at them.
- For each question: the mark, **Read as** (what the AI read from the student's handwriting), **Why** (its reasoning) and its **confidence**.
- A yellow note if some of the student's files **could not be read** (for example a Word document or a photo over 10 MB). Those pages were not marked: check them yourself.

Now you can:

- **Change any question's mark.** The total and the grade box update as you type.
- Click **Use AI grade** to copy the AI's grade into the grade box.
- Edit **Proctor AI feedback for the student**. This is the comment the student will see.
- Click **Save changes**. Saving approves the result.

> Tip: the AI is most often unsure about messy handwriting, crossed-out answers and diagrams. That's exactly what "Check this" points you to.

## 5. Release the marks to students

**If your assignment uses marking workflow** (*Settings > Grade > Use marking workflow*):

- The AI's grade is filled in as a **draft**, and the student is set to **Ready for review**.
- The gradebook stays empty. Students see nothing.
- When you are happy, set the workflow state to **Released**, one student at a time or in bulk. Only then do students see the grade, the feedback and the marks per question.

**If it doesn't:**

- The AI's result is only a suggestion. The student's grade stays empty.
- Either open each student and click **Save changes**, or select students on the Submissions page and choose **Apply Proctor AI marks** from the bulk actions. A confirmation page lists who will get their grade. Grades you gave yourself are never changed.

## 6. Keep an eye on things: the status page

**Submissions page > actions menu > Proctor AI status** shows:

- the balance and how many submissions it still covers;
- every student's grading: waiting, in progress, graded, or why it stopped;
- **Retry** (try a stopped grading again) and **Cancel** (stop one that hasn't started; it isn't charged);
- **Retry everything stopped by the balance**, after your administrator tops up.

A banner on the assignment page warns graders when the balance is low or has run out.

## What the statuses mean

| Status | Meaning |
|---|---|
| Not sent to AI | Nothing has been sent for this student yet. |
| Waiting for AI grading | In the queue. Grading runs in the background. |
| Uploading to AI / AI grading in progress | Being graded right now. |
| Graded by AI | Done. The result is waiting for your review. |
| AI grading failed | Stopped. The reason is shown underneath, for example "The PDF has 25 pages; at most 20 can be graded". Nothing was charged. |
| Not graded: balance ran out | Stopped because the account ran out of balance. Use **Retry everything stopped by the balance** after a top-up. |
| Changed after it was queued | The student changed their submission before grading started. The old version was not graded. |
| Cancelled | Someone cancelled it before it started. Nothing was charged. |

## Good to know

- **Group submissions** are graded once per group (one charge), and every member gets the result.
- **Rubrics, marking guides and scales:** the AI can't fill these in. It shows its result as a percentage, as a guide while you grade.
- **Re-grading:** if a student changes their work after you approved the AI result, it is **not** re-graded automatically. You can send it again yourself; the new result is then a draft until you approve it again.
- If marks are already released through marking workflow, set the state back to **In review** before sending the work again.

More unusual cases are in [What if…?](faq.md).
