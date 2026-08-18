<?php
define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_sesskey();

$id       = required_param('id', PARAM_INT); // course_module id.
$score    = required_param('score', PARAM_FLOAT);
$maxscore = required_param('maxscore', PARAM_FLOAT);

$cm        = get_coursemodule_from_id('awakegame', $id, 0, false, MUST_EXIST);
$course    = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$awakegame = $DB->get_record('awakegame', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/awakegame:view', $context);

header('Content-Type: application/json');

if ((int) $awakegame->grade === 0 || $maxscore <= 0) {
    echo json_encode(['ok' => false, 'error' => 'nogradingenabled']);
    die();
}

// Nunca confiar en un userid enviado por el cliente: siempre el usuario de la sesión actual.
$score = max(0.0, min((float) $score, (float) $maxscore));
$normalizedgrade = ($score / $maxscore) * abs((float) $awakegame->grade);

$existing = $DB->get_record('awakegame_grades', ['awakegameid' => $awakegame->id, 'userid' => $USER->id]);

$updated = false;

if (!$existing) {
    $record = (object) [
        'awakegameid'  => $awakegame->id,
        'userid'       => $USER->id,
        'score'        => $score,
        'maxscore'     => $maxscore,
        'grade'        => $normalizedgrade,
        'timemodified' => time(),
    ];
    $DB->insert_record('awakegame_grades', $record);
    $updated = true;
} else if ($normalizedgrade > $existing->grade) {
    // Se conserva la mejor puntuación obtenida (como en la mayoría de juegos/cuestionarios).
    $existing->score        = $score;
    $existing->maxscore     = $maxscore;
    $existing->grade        = $normalizedgrade;
    $existing->timemodified = time();
    $DB->update_record('awakegame_grades', $existing);
    $updated = true;
}

if ($updated) {
    awakegame_update_grades($awakegame, $USER->id);
}

echo json_encode(['ok' => true, 'updated' => $updated, 'grade' => $normalizedgrade]);
