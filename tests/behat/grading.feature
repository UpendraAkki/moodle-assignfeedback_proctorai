@assignfeedback @assignfeedback_proctorai
Feature: Grade submissions with Proctor AI and review the result
  In order to grade handwritten and typed work faster
  As a teacher
  I need Proctor AI results to arrive as drafts that I review before students see them

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And Proctor AI uses the fake API
    And the following "activities" exist:
      | activity | course | name     | assignsubmission_onlinetext_enabled | assignfeedback_proctorai_enabled | markingworkflow | submissiondrafts | grade |
      | assign   | C1     | Assign 1 | 1                                   | 1                                | 0               | 0                | 100   |
    And the Proctor AI marking key of "Assign 1" is:
      """
      1. Name the capital of France. [4]
      Answer: Paris
      2. Explain photosynthesis. [6]
      """
    And the following "mod_assign > submissions" exist:
      | assign   | user     | onlinetext                              |
      | Assign 1 | student1 | Paris. Plants make food from sunlight. |

  Scenario: The teacher grades all ungraded submissions and applies the result
    Given I am on the Proctor AI "gradeall" page for "Assign 1" as "teacher1"
    And I should see "1 of the 1 selected students can be graded now."
    And I should see "100" in the "Balance" "table_row"
    When I press "Grade with Proctor AI (1)"
    Then I should see "Submissions queued for Proctor AI grading: 1."
    And I should see "Waiting for AI grading"
    And I run all adhoc tasks
    And I am on the Proctor AI "status" page for "Assign 1" as "teacher1"
    And I should see "Graded by AI"
    And I should see "AI: 7 / 10 (70%)"
    And I should see "1 to check"
    And I should see "99 submissions left"
    # Students see nothing until a teacher approves the result.
    And I am on the "Assign 1" "assign activity" page logged in as "student1"
    And I should not see "A good attempt."
    And I should not see "Marks per question"
    And I log in as "teacher1"
    And I am on the Proctor AI apply page for "student1" in "Assign 1"
    And I should see "1 of the 1 selected students will get their Proctor AI grade."
    And I press "Apply Proctor AI marks (1)"
    And I should see "Proctor AI marks applied as grades: 1."
    And I am on the "Assign 1" "assign activity" page logged in as "student1"
    And I should see "70.00 / 100.00"
    And I should see "A good attempt."
    And I should see "Marks per question"

  Scenario: Running out of balance stops grading, and "retry" grades again after a top-up
    Given the fake Proctor AI balance is "0"
    And I am on the Proctor AI "gradeall" page for "Assign 1" as "teacher1"
    And I should see "The balance does not cover all of these submissions."
    And the fake Proctor AI balance is "5"
    And I am on the Proctor AI "gradeall" page for "Assign 1" as "teacher1"
    And I press "Grade with Proctor AI (1)"
    And the fake Proctor AI API answers gradings with "402"
    And I run all adhoc tasks
    And I am on the Proctor AI "status" page for "Assign 1" as "teacher1"
    And I should see "Not graded: balance ran out"
    And the fake Proctor AI API answers gradings with "200"
    When I press "Retry everything stopped by the balance"
    Then I should see "Submissions queued for Proctor AI grading: 1."
    And I run all adhoc tasks
    And I am on the Proctor AI "status" page for "Assign 1" as "teacher1"
    And I should see "Graded by AI"

  Scenario: A grading the API rejects shows the reason, and a queued grading can be cancelled
    Given the fake Proctor AI API answers gradings with "422"
    And I am on the Proctor AI "gradeall" page for "Assign 1" as "teacher1"
    And I press "Grade with Proctor AI (1)"
    And I press "Cancel"
    And I should see "The grading was cancelled."
    And I should see "Cancelled"
    And I press "Retry"
    And I should see "The submission was queued for Proctor AI grading again."
    And I run all adhoc tasks
    And I am on the Proctor AI "status" page for "Assign 1" as "teacher1"
    Then I should see "AI grading failed"
    And I should see "The answer sheet has more than 20 pages."

  Scenario: A rejected API key pauses grading for the site
    Given the fake Proctor AI API answers gradings with "401"
    And I am on the Proctor AI "gradeall" page for "Assign 1" as "teacher1"
    And I press "Grade with Proctor AI (1)"
    And I run all adhoc tasks
    When I am on the Proctor AI "status" page for "Assign 1" as "teacher1"
    Then I should see "Proctor AI grading is paused because the API key was rejected."
    And I am on the "Assign 1" "assign activity" page
    And I should see "Proctor AI grading is paused because the API key was rejected."
