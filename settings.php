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
 * Site administration settings for the Proctor AI feedback plugin.
 *
 * @package    assignfeedback_proctorai
 * @copyright  2026 Proctor AI <support@proctorai.io>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use assignfeedback_proctorai\local\account;

if ($ADMIN->fulltree) {
    $callback = [account::class, 'settings_updated'];

    $settings->add(new admin_setting_heading(
        'assignfeedback_proctorai/statusheading',
        get_string('connectionstatus', 'assignfeedback_proctorai'),
        account::status_html()
    ));

    $settings->add(new admin_setting_configcheckbox(
        'assignfeedback_proctorai/default',
        new lang_string('default', 'assignfeedback_proctorai'),
        new lang_string('default_help', 'assignfeedback_proctorai'),
        0
    ));

    $setting = new admin_setting_configpasswordunmask(
        'assignfeedback_proctorai/apikey',
        new lang_string('apikey', 'assignfeedback_proctorai'),
        new lang_string('apikey_desc', 'assignfeedback_proctorai'),
        ''
    );
    $setting->set_updatedcallback($callback);
    $settings->add($setting);

    $setting = new admin_setting_configtext(
        'assignfeedback_proctorai/apiurl',
        new lang_string('apiurl', 'assignfeedback_proctorai'),
        new lang_string('apiurl_desc', 'assignfeedback_proctorai'),
        account::DEFAULT_API_URL,
        PARAM_URL
    );
    $setting->set_updatedcallback($callback);
    $settings->add($setting);

    $setting = new admin_setting_configcheckbox(
        'assignfeedback_proctorai/consent',
        new lang_string('consent', 'assignfeedback_proctorai'),
        new lang_string('consent_desc', 'assignfeedback_proctorai'),
        0
    );
    $setting->set_updatedcallback($callback);
    $settings->add($setting);

    $settings->add(new admin_setting_configtext(
        'assignfeedback_proctorai/lowbalance',
        new lang_string('lowbalance', 'assignfeedback_proctorai'),
        new lang_string('lowbalance_desc', 'assignfeedback_proctorai'),
        20,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'assignfeedback_proctorai/maxattempts',
        new lang_string('maxattempts', 'assignfeedback_proctorai'),
        new lang_string('maxattempts_desc', 'assignfeedback_proctorai'),
        5,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'assignfeedback_proctorai/regradeonresubmit',
        new lang_string('regradeonresubmit', 'assignfeedback_proctorai'),
        new lang_string('regradeonresubmit_desc', 'assignfeedback_proctorai'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'assignfeedback_proctorai/autogradecap',
        new lang_string('autogradecap', 'assignfeedback_proctorai'),
        new lang_string('autogradecap_desc', 'assignfeedback_proctorai'),
        3,
        PARAM_INT
    ));
}
