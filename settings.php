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

    $settings->add(new admin_setting_heading(
        'mod_awakegame/marketplaceheading',
        get_string('marketplaceheading', 'mod_awakegame'),
        get_string('marketplaceheading_desc', 'mod_awakegame')
    ));

    $settings->add(new admin_setting_configtext(
        'mod_awakegame/marketplaceurl',
        get_string('marketplaceurl', 'mod_awakegame'),
        get_string('marketplaceurl_desc', 'mod_awakegame'),
        'http://localhost:8000/api/games.php',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_awakegame/marketplaceapikey',
        get_string('marketplaceapikey', 'mod_awakegame'),
        get_string('marketplaceapikey_desc', 'mod_awakegame'),
        ''
    ));
}
