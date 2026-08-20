<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_awakegame_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026072801) {
        $table = new xmldb_table('awakegame');

        $field = new xmldb_field('contentsource', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'upload', 'introformat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('aiprompt', XMLDB_TYPE_TEXT, null, null, null, null, null, 'contentsource');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072801, 'awakegame');
    }

    if ($oldversion < 2026072802) {
        $table = new xmldb_table('awakegame');

        $field = new xmldb_field('grade', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '100', 'aiprompt');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $gradestable = new xmldb_table('awakegame_grades');
        if (!$dbman->table_exists($gradestable)) {
            $gradestable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $gradestable->add_field('awakegameid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $gradestable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $gradestable->add_field('score', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0');
            $gradestable->add_field('maxscore', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0');
            $gradestable->add_field('grade', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0');
            $gradestable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $gradestable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $gradestable->add_key('awakegameid', XMLDB_KEY_FOREIGN, ['awakegameid'], 'awakegame', ['id']);
            $gradestable->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            $gradestable->add_index('awakegameid-userid', XMLDB_INDEX_UNIQUE, ['awakegameid', 'userid']);

            $dbman->create_table($gradestable);
        }

        upgrade_mod_savepoint(true, 2026072802, 'awakegame');
    }

    if ($oldversion < 2026072803) {
        $table = new xmldb_table('awakegame');

        $field = new xmldb_field('aiimprovement', XMLDB_TYPE_TEXT, null, null, null, null, null, 'aiprompt');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072803, 'awakegame');
    }

    if ($oldversion < 2026072804) {
        $table = new xmldb_table('awakegame');

        $field = new xmldb_field('aipendingreview', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'grade');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072804, 'awakegame');
    }

    if ($oldversion < 2026072805) {
        $librarytable = new xmldb_table('awakegame_library');
        if (!$dbman->table_exists($librarytable)) {
            $librarytable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $librarytable->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $librarytable->add_field('aiprompt', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $librarytable->add_field('sourcecourseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $librarytable->add_field('savedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $librarytable->add_field('timesused', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $librarytable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $librarytable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $librarytable->add_key('savedby', XMLDB_KEY_FOREIGN, ['savedby'], 'user', ['id']);

            $dbman->create_table($librarytable);
        }

        upgrade_mod_savepoint(true, 2026072805, 'awakegame');
    }

    if ($oldversion < 2026081900) {
        $table = new xmldb_table('awakegame');

        $field = new xmldb_field('marketplaceshare', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'aipendingreview');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('marketplaceid', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'marketplaceshare');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('marketplacestatus', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '', 'marketplaceid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026081900, 'awakegame');
    }

    if ($oldversion < 2026082000) {
        // La biblioteca interna del sitio se sustituye por el Marketplace: se
        // elimina la tabla y todo lo que hubiera guardado en ella (decisión
        // explícita, no se conserva en ningún sitio tras este paso).
        $librarytable = new xmldb_table('awakegame_library');
        if ($dbman->table_exists($librarytable)) {
            $dbman->drop_table($librarytable);
        }

        upgrade_mod_savepoint(true, 2026082000, 'awakegame');
    }

    return true;
}
