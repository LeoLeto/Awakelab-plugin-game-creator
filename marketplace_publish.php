<?php
require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT); // course_module id.

require_sesskey();

$cm        = get_coursemodule_from_id('awakegame', $id, 0, false, MUST_EXIST);
$course    = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$awakegame = $DB->get_record('awakegame', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/awakegame:addinstance', $context);

if (!empty($awakegame->marketplaceid)) {
    redirect(
        new moodle_url('/mod/awakegame/view.php', ['id' => $cm->id]),
        get_string('marketplacealreadypublished', 'mod_awakegame'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

awakegame_queue_marketplace_task($awakegame->id, 'publish');

redirect(
    new moodle_url('/mod/awakegame/view.php', ['id' => $cm->id]),
    get_string('marketplacepublishqueued', 'mod_awakegame'),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
