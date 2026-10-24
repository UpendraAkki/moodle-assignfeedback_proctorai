@assignfeedback @assignfeedback_proctorai @javascript
Feature: Edit the marking key question by question and adjust Proctor AI marks in the grader
  In order to set up and review AI grading without learning a text format
  As a teacher
  I need a structured marking-key editor and editable marks per question

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

  Scenario: Add a question in the structured editor and see the total against the maximum grade
    Given I am on the "Assign 1" "assign activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    And I should see "Total marks: 10 / 100"
    When I press "Add a question"
    And I set the field with xpath "//textarea[@id='keyeditor_q2_prompt']" to "Name a prime number."
    And I set the field with xpath "//input[@id='keyeditor_q2_marks']" to "2"
    And I set the field with xpath "//input[@id='keyeditor_q2_options']" to "4 | 7 | 9"
    Then I should see "Total marks: 12 / 100"
    And I save a Proctor AI screenshot named "markingkey-editor"
    And I press "Edit as text"
    And the field "assignfeedback_proctorai_markingkey" matches multiline:
      """
      1. Name the capital of France. [4]
      Answer: Paris
      2. Explain photosynthesis. [6]
      3. Name a prime number. [2]
      Options: 4 | 7 | 9
      """
    And I press "Save and display"
    And I am on the "Assign 1" "assign activity editing" page
    And I expand all fieldsets
    And I should see "Total marks: 12 / 100"

  Scenario: Change a mark in the grader; the total and grade follow, and the student sees the marks once saved
    Given the following "mod_assign > submissions" exist:
      | assign   | user     | onlinetext                              |
      | Assign 1 | student1 | Paris. Plants make food from sunlight. |
    And I am on the Proctor AI "gradeall" page for "Assign 1" as "teacher1"
    And I save a Proctor AI screenshot named "confirm-page"
    And I press "Grade with Proctor AI (1)"
    And I run the Proctor AI grading tasks
    And I am on the Proctor AI "status" page for "Assign 1" as "teacher1"
    And I save a Proctor AI screenshot named "status-page"
    When I am on the grader for "student1" in "Assign 1"
    And I should see "AI total:"
    And I should see "Sample review reason"
    And I save a Proctor AI screenshot named "grader-panel"
    And I set the field "Mark for question 2" to "5"
    Then I should see "9" in the "[data-region='assignfeedback_proctorai-total']" "css_element"
    And the field with xpath "//input[@name='grade']" matches value "90"
    And I press "Save changes"
    And I should see "The changes to the grade and feedback were saved"
    And I am on the "Assign 1" "assign activity" page logged in as "student1"
    And I should see "Marks per question"
    And I should see "5 / 6"
    And I should see "A good attempt."
    And I save a Proctor AI screenshot named "student-view"

  Scenario: The admin settings page shows the connection and balance from the API
    Given I log in as "admin"
    When I navigate to "Plugins > Activity modules > Assignment > Feedback plugins > Proctor AI grading" in site administration
    Then I should see "Connected to"
    And I should see "100 submissions at 1 each"
    And I save a Proctor AI screenshot named "settings"
