<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'mod_awakegame/aiheading',
        get_string('aiheading', 'mod_awakegame'),
        get_string('aiheading_desc', 'mod_awakegame')
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_awakegame/anthropicapikey',
        get_string('anthropicapikey', 'mod_awakegame'),
        get_string('anthropicapikey_desc', 'mod_awakegame'),
        ''
    ));
}
