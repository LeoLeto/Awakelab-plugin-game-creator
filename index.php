<?php
require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT); // course id.

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_course_login($course);

$PAGE->set_url('/mod/awakegame/index.php', ['id' => $id]);
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context(context_course::instance($course->id));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_awakegame'));

$modinfo = get_fast_modinfo($course);
$instances = $modinfo->get_instances_of('awakegame');

if (empty($instances)) {
    echo $OUTPUT->notification(get_string('noinstances', 'mod_awakegame'), 'info');
} else {
    $table = new html_table();
    $table->head = [get_string('name')];

    foreach ($instances as $cm) {
        if (!$cm->uservisible) {
            continue;
        }
        $link = html_writer::link(new moodle_url('/mod/awakegame/view.php', ['id' => $cm->id]), $cm->get_formatted_name());
        $table->data[] = [$link];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();
