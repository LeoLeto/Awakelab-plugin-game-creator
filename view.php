<?php
require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = optional_param('id', 0, PARAM_INT); // course_module id.
$a  = optional_param('a', 0, PARAM_INT);  // awakegame instance id.

if ($id) {
    $cm         = get_coursemodule_from_id('awakegame', $id, 0, false, MUST_EXIST);
    $course     = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $awakegame  = $DB->get_record('awakegame', ['id' => $cm->instance], '*', MUST_EXIST);
} else if ($a) {
    $awakegame = $DB->get_record('awakegame', ['id' => $a], '*', MUST_EXIST);
    $course    = $DB->get_record('course', ['id' => $awakegame->course], '*', MUST_EXIST);
    $cm        = get_coursemodule_from_instance('awakegame', $awakegame->id, $course->id, false, MUST_EXIST);
} else {
    throw new moodle_exception('missingparameter');
}

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/awakegame:view', $context);

$event = \mod_awakegame\event\course_module_viewed::create([
    'objectid' => $awakegame->id,
    'context'  => $context,
]);
$event->add_record_snapshot('course_modules', $cm);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('awakegame', $awakegame);
$event->trigger();

$PAGE->set_url('/mod/awakegame/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($awakegame->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($awakegame->name));

if (!empty($awakegame->intro)) {
    echo $OUTPUT->box(format_module_intro('awakegame', $awakegame, $cm->id), 'generalbox mod_introbox', 'awakegameintro');
}

$fs = get_file_storage();
$indexfile = $fs->get_file($context->id, 'mod_awakegame', 'content', 0, '/', 'index.html');

if (!$indexfile) {
    echo $OUTPUT->notification(get_string('noindexfile', 'mod_awakegame'), 'error');
} else {
    $url = moodle_url::make_pluginfile_url($context->id, 'mod_awakegame', 'content', 0, '/', 'index.html');
    $url->param('v', $awakegame->revision);

    echo html_writer::tag('iframe', '', [
        'src'             => $url->out(false),
        'style'           => 'width: 100%; min-height: 600px; border: 0;',
        'allowfullscreen' => 'allowfullscreen',
        'title'           => format_string($awakegame->name),
    ]);

    if ((int) $awakegame->grade !== 0) {
        $ajaxurl = new moodle_url('/mod/awakegame/ajax_grade.php');
        $cmid = (int) $cm->id;
        $js = <<<JS
(function() {
    window.addEventListener('message', function(event) {
        if (event.origin !== window.location.origin) {
            return;
        }
        if (!event.data || event.data.type !== 'awakegame-score') {
            return;
        }
        var score = Number(event.data.score);
        var maxscore = Number(event.data.maxscore);
        if (!isFinite(score) || !isFinite(maxscore) || maxscore <= 0) {
            return;
        }
        var body = 'id={$cmid}' +
            '&score=' + encodeURIComponent(score) +
            '&maxscore=' + encodeURIComponent(maxscore) +
            '&sesskey=' + encodeURIComponent(M.cfg.sesskey);
        fetch('{$ajaxurl->out(false)}', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body,
            credentials: 'same-origin'
        });
    });
})();
JS;
        $PAGE->requires->js_init_code($js);
    }

    if ($indexfile && has_capability('mod/awakegame:addinstance', $context)) {
        $saveurl = new moodle_url('/mod/awakegame/library_save.php');
        $savebutton = html_writer::start_tag('form', ['method' => 'post', 'action' => $saveurl->out(false), 'style' => 'margin-top:10px;']);
        $savebutton .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $savebutton .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
        $savebutton .= html_writer::tag('button', get_string('savetolibrary', 'mod_awakegame'),
            ['type' => 'submit', 'class' => 'btn btn-secondary']);
        $savebutton .= html_writer::end_tag('form');
        echo $savebutton;
    }
}

echo $OUTPUT->footer();
