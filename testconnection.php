<?php
// Página de administración que hace una petición GET real al Marketplace
// configurado (URL + clave de API de ajustes) y muestra si responde
// correctamente, para no tener que descubrir un fallo de configuración
// cuando ya lo está sufriendo un profesor al intentar publicar/importar.
require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/mod/awakegame/testconnection.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('testconnection', 'mod_awakegame'));
$PAGE->set_heading(get_string('testconnection', 'mod_awakegame'));

$baseurl = trim((string) get_config('mod_awakegame', 'marketplaceurl'));
$apikey  = trim((string) get_config('mod_awakegame', 'marketplaceapikey'));

$ok = false;
$detail = '';

if ($baseurl === '' || $apikey === '') {
    $detail = get_string('nomarketplaceconfig', 'mod_awakegame');
} else {
    try {
        $result = awakegame_marketplace_request('GET');
        $ok = true;
        $detail = get_string('testconnectionok', 'mod_awakegame', count($result['games'] ?? []));
    } catch (\Throwable $e) {
        $detail = $e->getMessage();
    }
}

$backurl = new moodle_url('/admin/settings.php', ['section' => 'modsettingawakegame']);

echo $OUTPUT->header();
if ($ok) {
    echo $OUTPUT->notification($detail, \core\output\notification::NOTIFY_SUCCESS);
} else {
    echo $OUTPUT->notification(get_string('testconnectionfail', 'mod_awakegame', $detail), \core\output\notification::NOTIFY_ERROR);
}
echo $OUTPUT->single_button($backurl, get_string('back'), 'get');
echo $OUTPUT->footer();
