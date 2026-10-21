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
 * The Proctor AI panel in the grader: "Use AI grade" and status polling.
 *
 * @module     assignfeedback_proctorai/grader_panel
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {getString} from 'core/str';

/** @type {number} Milliseconds between status checks while a job runs. */
const POLL_INTERVAL = 10000;

/** @type {string[]} Statuses that mean the job is still going. */
const ACTIVE = ['queued', 'uploading', 'grading'];

/**
 * Put the AI grade into the grader form's grade field.
 *
 * @param {HTMLElement} panel The panel element.
 * @param {HTMLElement} button The button that was pressed.
 */
const useGrade = (panel, button) => {
    const form = panel.closest('form');
    const input = form ? form.querySelector('input[name="grade"]') : null;
    if (!input) {
        return;
    }
    input.value = button.dataset.grade;
    input.dispatchEvent(new Event('change', {bubbles: true}));
    input.focus();
};

/**
 * Ask the server for the latest status and update the panel; keep asking while the job runs.
 *
 * @param {HTMLElement} panel The panel element.
 * @param {number} assignid The assign instance id.
 * @param {number} userid The student.
 */
const poll = async(panel, assignid, userid) => {
    if (!document.body.contains(panel)) {
        // The grader moved on to another student.
        return;
    }
    try {
        const status = await Ajax.call([{
            methodname: 'assignfeedback_proctorai_get_status',
            args: {assignid, userid},
        }])[0];
        const region = panel.querySelector('[data-region="assignfeedback_proctorai-status"] .badge');
        if (region) {
            region.textContent = status.statuslabel;
        }
        if (ACTIVE.includes(status.status)) {
            window.setTimeout(() => poll(panel, assignid, userid), POLL_INTERVAL);
            return;
        }
        if (status.status !== panel.dataset.status) {
            panel.dataset.status = status.status;
            const message = await getString('resultready', 'assignfeedback_proctorai');
            Notification.addNotification({message, type: 'info'});
        }
    } catch (error) {
        Notification.exception(error);
    }
};

/**
 * Set up one panel.
 *
 * @param {object} options
 * @param {string} options.panelid The panel element id.
 * @param {number} options.assignid The assign instance id.
 * @param {number} options.userid The student.
 * @param {boolean} options.polling Whether a job is running now.
 */
export const init = ({panelid, assignid, userid, polling}) => {
    const panel = document.getElementById(panelid);
    if (!panel) {
        return;
    }
    panel.addEventListener('click', (e) => {
        const button = e.target.closest('[data-action="assignfeedback_proctorai-usegrade"]');
        if (button) {
            e.preventDefault();
            useGrade(panel, button);
        }
    });
    if (polling) {
        window.setTimeout(() => poll(panel, assignid, userid), POLL_INTERVAL);
    }
};
