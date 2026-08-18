<?php
namespace mod_awakegame\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * El plugin no almacena ningún dato personal de usuarios (solo el paquete
 * del juego subido por el profesor), por lo que implementa null_provider.
 */
class provider implements \core_privacy\local\metadata\null_provider {
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
