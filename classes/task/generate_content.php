<?php
namespace mod_awakegame\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Genera o mejora el contenido de una actividad con IA fuera de la petición
 * web original: la llamada a Claude puede tardar varios minutos (más aún con
 * los reintentos y las varias comprobaciones de tema encadenadas de
 * awakegame_generate_with_topic_check()), muy por encima de lo que nginx/PHP
 * están dispuestos a esperar en una petición normal (ver el 504 Gateway
 * Time-out que esto reemplaza). Al ejecutarse vía cron, ese límite no aplica.
 */
class generate_content extends \core\task\adhoc_task {

    public function execute() {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/awakegame/lib.php');

        $data = (object) $this->get_custom_data();

        $awakegame = $DB->get_record('awakegame', ['id' => $data->instanceid]);
        if (!$awakegame) {
            return; // La actividad se borró mientras la tarea esperaba en la cola.
        }

        $cm = get_coursemodule_from_instance('awakegame', $awakegame->id, $awakegame->course, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        $context = \context_module::instance($cm->id);

        try {
            if (($data->mode ?? '') === 'improve') {
                awakegame_apply_improvement($context, $data->instanceid, $data->prompt, $data->improvement);
            } else {
                awakegame_generate_from_prompt(
                    $context,
                    $data->instanceid,
                    $data->prompt,
                    $data->sectionnum ?? null,
                    $data->courseid ?? null
                );
            }
        } catch (\Throwable $e) {
            // No se relanza: si se relanzara, el gestor de tareas de Moodle
            // reprogramaría esta misma tarea (que ya agotó sus propios
            // reintentos dentro de awakegame_anthropic_request) una y otra
            // vez, gastando llamadas a la API sin ninguna posibilidad real de
            // éxito distinta. Queda registrado en el log propio del plugin
            // para poder diagnosticarlo.
            awakegame_debug_log("tarea instanceid={$data->instanceid} modo=" . ($data->mode ?? 'generate') .
                ' FALLÓ: ' . $e->getMessage());
        }

        $DB->set_field('awakegame', 'aipendingreview', 0, ['id' => $data->instanceid]);
    }
}
