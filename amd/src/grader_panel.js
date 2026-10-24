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
 * The Proctor AI panel in the grader: per-question marks, "Use AI grade" and status polling.
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
 * Round to two decimals, as grades are stored.
 *
 * @param {number} value The value.
 * @returns {number}
 */
const round2 = (value) => Math.round(value * 100) / 100;

/**
 * Put a value into the grader form's grade field.
 *
 * @param {HTMLElement} panel The panel element.
 * @param {number|string} value The grade.
 */
const setGrade = (panel, value) => {
    const form = panel.closest('form');
    const input = form ? form.querySelector('input[name="grade"]') : null;
    if (!input) {
        return;
    }
    input.value = value;
    input.dispatchEvent(new Event('change', {bubbles: true}));
};

/**
 * Read the per-question inputs, clamp them, and update the total, the hidden marks field and the grade.
 *
 * The total is the AI's total plus the teacher's changes, as the server computes it, so selection
 * rules such as "answer any three" in the AI's total are kept.
 *
 * @param {HTMLElement} panel The panel element.
 * @param {object} options The init options.
 */
const updateMarks = (panel, options) => {
    const marks = {};
    let delta = 0;
    panel.querySelectorAll('[data-region="assignfeedback_proctorai-mark"]').forEach((input) => {
        const max = parseFloat(input.dataset.max);
        let value = parseFloat(input.value);
        if (Number.isNaN(value)) {
            return;
        }
        value = Math.min(max, Math.max(0, value));
        marks[input.dataset.index] = value;
        delta += value - parseFloat(input.defaultValue || '0');
    });

    const form = panel.closest('form');
    const field = form ? form.querySelector(`input[name="${options.marksfield}"]`) : null;
    if (field) {
        const stored = field.value ? JSON.parse(field.value) : {};
        field.value = JSON.stringify(Object.assign(stored, marks));
    }

    const baseline = parseFloat(panel.dataset.baseline);
    const total = Math.max(0, round2(baseline + delta));
    const totalregion = panel.querySelector('[data-region="assignfeedback_proctorai-total"]');
    if (totalregion) {
        totalregion.textContent = total;
    }
    if (options.maxgrade > 0 && options.maxmarks > 0) {
        setGrade(panel, round2(Math.min(options.maxgrade, total / options.maxmarks * options.maxgrade)));
    }
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
            // Only a finished job has a result; any other end state (failed, no balance, stale) says why it stopped.
            const message = status.status === 'done'
                ? await getString('resultready', 'assignfeedback_proctorai')
                : await getString('resultstopped', 'assignfeedback_proctorai', status.statuslabel);
            Notification.addNotification({message, type: status.status === 'done' ? 'info' : 'warning'});
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
 * @param {string} options.marksfield The name of the hidden marks field.
 * @param {number} options.earned The total as saved, including earlier changes by a teacher.
 * @param {number} options.maxmarks The marks available.
 * @param {number} options.maxgrade The assignment's maximum grade, or 0 when the grade cannot be filled in.
 */
export const init = (options) => {
    const panel = document.getElementById(options.panelid);
    if (!panel) {
        return;
    }
    // The total on load already includes earlier teacher changes; edits move it from there.
    panel.dataset.baseline = options.earned;

    panel.addEventListener('click', (e) => {
        const button = e.target.closest('[data-action="assignfeedback_proctorai-usegrade"]');
        if (button) {
            e.preventDefault();
            setGrade(panel, button.dataset.grade);
        }
    });
    panel.addEventListener('input', (e) => {
        if (e.target.closest('[data-region="assignfeedback_proctorai-mark"]')) {
            updateMarks(panel, options);
        }
    });
    if (options.polling) {
        window.setTimeout(() => poll(panel, options.assignid, options.userid), POLL_INTERVAL);
    }
};
