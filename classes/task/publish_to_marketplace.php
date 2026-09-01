<?php
namespace mod_awakegame\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Publica o actualiza el juego de una actividad en el Marketplace, fuera de
 * la petición web original — igual que generate_content.php, para no
 * bloquear nunca el guardado del formulario con una llamada de red externa.
 *
 * A diferencia de generate_content.php, aquí SÍ se relanza la excepción si
 * falla: Moodle reprograma automáticamente las tareas ad-hoc fallidas con
 * espera creciente (faildelay), así que no hace falta ningún reintento
 * manual para conseguir "si falla, se reintenta después".
 */
class publish_to_marketplace extends \core\task\adhoc_task {

    public function execute() {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/awakegame/lib.php');

        $data = (object) $this->get_custom_data();

        $awakegame = $DB->get_record('awakegame', ['id' => $data->instanceid]);
        if (!$awakegame) {
            return; // La actividad se borró mientras la tarea esperaba en la cola.
        }

        try {
            if (($data->mode ?? '') === 'update') {
                awakegame_marketplace_update($awakegame);
            } else {
                awakegame_marketplace_publish($awakegame);
            }
        } catch (\Throwable $e) {
            $DB->update_record('awakegame', (object) [
                'id'                     => $data->instanceid,
                'marketplacestatus'      => 'error',
                'marketplacelasterror'   => $e->getMessage(),
                'marketplacelastattempt' => time(),
            ]);
            awakegame_debug_log("marketplace instanceid={$data->instanceid} modo=" . ($data->mode ?? 'publish') .
                ' FALLÓ (se reintentará): ' . $e->getMessage());
            throw $e;
        }
    }
}
