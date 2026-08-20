<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/awakegame/lib.php');

class mod_awakegame_mod_form extends moodleform_mod {

    public function definition() {
        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('awakegamename', 'mod_awakegame'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        $mform->addElement('header', 'contentheader', get_string('contentheader', 'mod_awakegame'));
        $mform->setExpanded('contentheader');

        $sourceoptions = [
            'ai'          => get_string('contentsource_ai', 'mod_awakegame'),
            'upload'      => get_string('contentsource_upload', 'mod_awakegame'),
            'marketplace' => get_string('contentsource_marketplace', 'mod_awakegame'),
        ];
        $mform->addElement('select', 'contentsource', get_string('contentsource', 'mod_awakegame'), $sourceoptions);
        $mform->setType('contentsource', PARAM_ALPHA);
        $mform->setDefault('contentsource', 'ai');
        $mform->addHelpButton('contentsource', 'contentsource', 'mod_awakegame');

        $mform->addElement('filemanager', 'packagefile', get_string('packagefile', 'mod_awakegame'), null, [
            'subdirs'        => 0,
            'maxfiles'       => 1,
            'accepted_types' => ['.zip'],
        ]);
        $mform->addHelpButton('packagefile', 'packagefile', 'mod_awakegame');
        $mform->hideIf('packagefile', 'contentsource', 'eq', 'ai');
        $mform->hideIf('packagefile', 'contentsource', 'eq', 'marketplace');

        // El listado se pide en directo al Marketplace; si no responde (apagado,
        // sin configurar, caído en ese momento) awakegame_marketplace_list_games()
        // devuelve una lista vacía y aquí se avisa, en vez de romper el formulario.
        $marketplacegames = awakegame_marketplace_list_games();
        $marketplaceoptions = ['0' => get_string('marketplacechoose', 'mod_awakegame')];
        foreach ($marketplacegames as $game) {
            $label = $game['title'] . ' (' . $game['school_name'] . ')';
            $marketplaceoptions[$game['id']] = $label;
        }
        $mform->addElement('select', 'marketplacegame', get_string('marketplacegame', 'mod_awakegame'), $marketplaceoptions);
        $mform->setType('marketplacegame', PARAM_INT);
        $mform->addHelpButton('marketplacegame', 'marketplacegame', 'mod_awakegame');
        $mform->hideIf('marketplacegame', 'contentsource', 'eq', 'upload');
        $mform->hideIf('marketplacegame', 'contentsource', 'eq', 'ai');
        if (empty($marketplacegames)) {
            $mform->addElement('static', 'marketplaceunavailable', '', get_string('marketplaceunavailable', 'mod_awakegame'));
            $mform->hideIf('marketplaceunavailable', 'contentsource', 'eq', 'upload');
            $mform->hideIf('marketplaceunavailable', 'contentsource', 'eq', 'ai');
        }

        $mform->addElement('textarea', 'marketplaceadapt', get_string('marketplaceadapt', 'mod_awakegame'),
            ['rows' => 4, 'cols' => 60]);
        $mform->setType('marketplaceadapt', PARAM_TEXT);
        $mform->addHelpButton('marketplaceadapt', 'marketplaceadapt', 'mod_awakegame');
        $mform->hideIf('marketplaceadapt', 'contentsource', 'eq', 'upload');
        $mform->hideIf('marketplaceadapt', 'contentsource', 'eq', 'ai');

        $mform->addElement('textarea', 'aiprompt', get_string('aiprompt', 'mod_awakegame'), ['rows' => 6, 'cols' => 60]);
        $mform->setType('aiprompt', PARAM_TEXT);
        $mform->addHelpButton('aiprompt', 'aiprompt', 'mod_awakegame');
        $mform->hideIf('aiprompt', 'contentsource', 'eq', 'upload');
        $mform->hideIf('aiprompt', 'contentsource', 'eq', 'marketplace');

        // El prompt original solo se puede escribir al crear la actividad (o si se
        // cambia a modo IA una actividad que nunca tuvo prompt). Una vez existe un
        // prompt guardado, queda bloqueado: los cambios posteriores se piden en el
        // campo "Mejoras" de abajo, que parte del juego actual en vez de rehacerlo.
        $lockprompt = !empty($this->current->instance) && trim((string) ($this->current->aiprompt ?? '')) !== '';

        if ($lockprompt) {
            $mform->hardFreeze('aiprompt');
            $mform->addElement('static', 'aipromptlockednotice', '', get_string('aipromptlocked', 'mod_awakegame'));

            $mform->addElement('textarea', 'aiimprovement', get_string('aiimprovement', 'mod_awakegame'),
                ['rows' => 4, 'cols' => 60]);
            $mform->setType('aiimprovement', PARAM_TEXT);
            $mform->addHelpButton('aiimprovement', 'aiimprovement', 'mod_awakegame');
            $mform->hideIf('aiimprovement', 'contentsource', 'eq', 'upload');
            $mform->hideIf('aiimprovement', 'contentsource', 'eq', 'marketplace');
        }

        $mform->addElement('checkbox', 'airegenerate', get_string('airegenerate', 'mod_awakegame'));
        $mform->addHelpButton('airegenerate', 'airegenerate', 'mod_awakegame');
        $mform->setDefault('airegenerate', 0);
        $mform->hideIf('airegenerate', 'contentsource', 'eq', 'upload');
        $mform->hideIf('airegenerate', 'contentsource', 'eq', 'marketplace');

        $mform->addElement('header', 'marketplaceheader', get_string('marketplaceshareheader', 'mod_awakegame'));

        $mform->addElement('checkbox', 'marketplaceshare', get_string('marketplaceshare', 'mod_awakegame'));
        $mform->addHelpButton('marketplaceshare', 'marketplaceshare', 'mod_awakegame');
        $mform->setDefault('marketplaceshare', 0);

        $marketplacestatus = $this->current->marketplacestatus ?? '';
        if ($marketplacestatus !== '') {
            $mform->addElement('static', 'marketplacestatusnotice', '',
                get_string('marketplacestatus_' . $marketplacestatus, 'mod_awakegame'));
        }

        $this->standard_grading_coursemodule_elements();
        $mform->setDefault('grade', 100);
        $mform->addElement('static', 'awakegamegradenotice', '', get_string('gradenotice', 'mod_awakegame'));

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $source = $data['contentsource'] ?? 'upload';

        if ($source === 'ai') {
            $lockprompt = !empty($this->current->instance) && trim((string) ($this->current->aiprompt ?? '')) !== '';

            // Si el prompt está bloqueado (hardFreeze), puede no viajar en los
            // datos enviados por el navegador; no lo exijamos en ese caso,
            // update_instance() ya se encarga de conservar el valor guardado.
            if (!$lockprompt && trim($data['aiprompt'] ?? '') === '') {
                $errors['aiprompt'] = get_string('required');
            }
        } else if ($source === 'marketplace') {
            if (empty($data['marketplacegame'])) {
                $errors['marketplacegame'] = get_string('required');
            }
        }

        return $errors;
    }

    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);

        if (!empty($this->current->instance)) {
            $draftitemid = file_get_submitted_draft_itemid('packagefile');
            file_prepare_draft_area($draftitemid, $this->context->id, 'mod_awakegame', 'package', 0, [
                'subdirs'  => 0,
                'maxfiles' => 1,
            ]);
            $defaultvalues['packagefile'] = $draftitemid;
        }
    }
}
