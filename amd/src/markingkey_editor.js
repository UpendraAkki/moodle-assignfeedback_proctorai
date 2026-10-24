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
 * A structured editor for the marking key that reads and writes the plain-text format.
 *
 * The textarea stays the source of truth (it is what the form saves and the server validates).
 * The editor shows one card per question and writes the text back on every change, and shows
 * the total marks next to the assignment's maximum grade.
 *
 * @module     assignfeedback_proctorai/markingkey_editor
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import Notification from 'core/notification';
import {getString} from 'core/str';

/** @type {RegExp} The same question line rule as classes/local/marking_key.php. */
const QUESTION_LINE = /^\s*(\d{1,3})([a-z])?[.)](?:\s+|$)(.*?)\s*(?:\[\s*(\d+(?:\.\d+)?)\s*(?:marks?)?\s*\])?\s*$/i;

/**
 * Parse the text into questions. Returns null when a line cannot be placed, so the editor
 * leaves that key to the text box rather than lose anything.
 *
 * @param {string} text The marking key.
 * @returns {Array|null} Questions: {prompt, marks, answer, options, subs: [{prompt, marks, answer}]}.
 */
export const parse = (text) => {
    const questions = [];
    const bynumber = {};
    let current = null;
    let field = null;
    for (const raw of text.replace(/\r\n?/g, '\n').split('\n')) {
        const line = raw.trim();
        if (line === '') {
            continue;
        }
        const match = line.match(QUESTION_LINE);
        if (match) {
            const number = String(parseInt(match[1], 10));
            const marks = match[4] === undefined ? '' : match[4];
            if (!bynumber[number]) {
                bynumber[number] = {prompt: '', marks: '', answer: '', options: '', subs: []};
                questions.push(bynumber[number]);
            }
            if (match[2]) {
                current = {prompt: match[3], marks, answer: ''};
                bynumber[number].subs.push(current);
            } else {
                current = bynumber[number];
                current.prompt = match[3];
                current.marks = marks;
            }
            field = 'prompt';
            continue;
        }
        if (!current) {
            return null;
        }
        const answer = line.match(/^answer\s*:\s*(.*)$/i);
        if (answer) {
            current.answer = answer[1];
            field = 'answer';
            continue;
        }
        const options = line.match(/^options\s*:\s*(.*)$/i);
        if (options && current.subs) {
            current.options = options[1];
            field = null;
            continue;
        }
        if (!field) {
            return null;
        }
        current[field] = (current[field] ? current[field] + '\n' : '') + line;
    }
    return questions;
};

/**
 * Write questions back as text, numbering them 1, 2, 3… and sub-questions a, b, c….
 *
 * @param {Array} questions As parse() returns.
 * @returns {string}
 */
export const serialise = (questions) => {
    const lines = [];
    const marks = (value) => (String(value).trim() === '' ? '' : ` [${String(value).trim()}]`);
    const block = (head, prompt, value) => {
        const [first, ...rest] = String(prompt).split('\n');
        lines.push(`${head} ${first.trim()}${marks(value)}`.replace(/\s+$/, ''));
        rest.forEach((more) => lines.push(more));
    };
    questions.forEach((question, i) => {
        const number = i + 1;
        block(`${number}.`, question.prompt, question.subs.length ? '' : question.marks);
        if (!question.subs.length && question.answer.trim() !== '') {
            lines.push(`Answer: ${question.answer.trim()}`);
        }
        if (!question.subs.length && question.options.trim() !== '') {
            lines.push(`Options: ${question.options.trim()}`);
        }
        question.subs.forEach((sub, j) => {
            block(`${number}${String.fromCharCode(97 + j)}.`, sub.prompt, sub.marks);
            if (sub.answer.trim() !== '') {
                lines.push(`Answer: ${sub.answer.trim()}`);
            }
        });
    });
    return lines.join('\n');
};

/**
 * Total marks in the key: each question's marks, or its sub-questions' marks.
 *
 * @param {Array} questions As parse() returns.
 * @returns {number}
 */
export const total = (questions) => questions.reduce((sum, question) => {
    const parts = question.subs.length ? question.subs : [question];
    return sum + parts.reduce((partsum, part) => partsum + (parseFloat(part.marks) || 0), 0);
}, 0);

/**
 * The template context for the cards.
 *
 * @param {Array} questions As parse() returns.
 * @returns {object}
 */
const context = (questions) => ({
    questions: questions.map((question, i) => ({
        index: i,
        number: i + 1,
        prompt: question.prompt,
        marks: question.marks,
        answer: question.answer,
        options: question.options,
        hassubs: question.subs.length > 0,
        subs: question.subs.map((sub, j) => ({
            qindex: i,
            index: j,
            label: `${i + 1}${String.fromCharCode(97 + j)}`,
            prompt: sub.prompt,
            marks: sub.marks,
            answer: sub.answer,
        })),
    })),
});

/**
 * Set up the editor for one marking key textarea.
 *
 * @param {object} options
 * @param {string} options.textareaid The textarea's id.
 * @param {string} options.gradefield The name of the assignment's maximum grade input.
 */
export const init = async({textareaid, gradefield}) => {
    const textarea = document.getElementById(textareaid);
    if (!textarea) {
        return;
    }
    const form = textarea.closest('form');
    const wrapper = document.createElement('div');
    wrapper.className = 'assignfeedback_proctorai_keyeditor mb-2';
    textarea.parentNode.insertBefore(wrapper, textarea);

    let questions = parse(textarea.value) || [];
    let structured = parse(textarea.value) !== null;

    const gradeInput = () => (form ? form.querySelector(`[name="${gradefield}"]`) : null);
    const [totallabel, cantshow] = await Promise.all([
        getString('keyeditortotal', 'assignfeedback_proctorai'),
        getString('keyeditorcantshow', 'assignfeedback_proctorai'),
    ]);

    const showTotal = (count) => {
        const region = wrapper.querySelector('[data-region="keyeditor-total"]');
        if (!region) {
            return;
        }
        const input = gradeInput();
        const maxgrade = input ? parseFloat(input.value) : NaN;
        region.textContent = Number.isNaN(maxgrade)
            ? `${totallabel} ${count}`
            : `${totallabel} ${count} / ${maxgrade}`;
        region.classList.toggle('text-warning', !Number.isNaN(maxgrade) && count > 0 && count !== maxgrade);
    };

    const write = () => {
        textarea.value = serialise(questions);
        textarea.dispatchEvent(new Event('change', {bubbles: true}));
        showTotal(total(questions));
    };

    const render = async() => {
        try {
            const {html, js} = await Templates.renderForPromise('assignfeedback_proctorai/markingkey_editor', {
                ...context(questions),
                structured,
                cantshow: structured ? '' : cantshow,
            });
            Templates.replaceNodeContents(wrapper, html, js);
            textarea.classList.toggle('d-none', structured);
            const parsed = structured ? questions : parse(textarea.value);
            showTotal(parsed ? total(parsed) : 0);
        } catch (error) {
            Notification.exception(error);
        }
    };

    wrapper.addEventListener('input', (e) => {
        const input = e.target.closest('[data-field]');
        if (!input) {
            return;
        }
        const question = questions[input.dataset.question];
        const target = input.dataset.sub === undefined ? question : question.subs[input.dataset.sub];
        target[input.dataset.field] = input.value;
        write();
    });

    wrapper.addEventListener('click', (e) => {
        const button = e.target.closest('[data-action]');
        if (!button) {
            return;
        }
        e.preventDefault();
        const qi = parseInt(button.dataset.question, 10);
        switch (button.dataset.action) {
            case 'addquestion':
                questions.push({prompt: '', marks: '1', answer: '', options: '', subs: []});
                break;
            case 'removequestion':
                questions.splice(qi, 1);
                break;
            case 'addsub':
                questions[qi].subs.push({prompt: '', marks: '1', answer: ''});
                break;
            case 'removesub':
                questions[qi].subs.splice(parseInt(button.dataset.sub, 10), 1);
                break;
            case 'astext':
                structured = false;
                render();
                return;
            case 'asstructured': {
                const parsed = parse(textarea.value);
                if (parsed === null) {
                    return;
                }
                questions = parsed;
                structured = true;
                render();
                return;
            }
            default:
                return;
        }
        write();
        render();
    });

    textarea.addEventListener('input', () => {
        const parsed = parse(textarea.value);
        showTotal(parsed ? total(parsed) : 0);
    });
    const input = gradeInput();
    if (input) {
        input.addEventListener('input', () => showTotal(total(structured ? questions : parse(textarea.value) || [])));
    }

    render();
};
