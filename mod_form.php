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

        // En vez de cargar de golpe TODOS los juegos publicados en un <select>
        // (no escala si el catálogo crece), se abre una ventana emergente con
        // buscador (marketplace_picker.php) que avisa aquí con postMessage al
        // elegir uno. El campo real que viaja con el formulario sigue siendo
        // "marketplacegame" (oculto), igual que antes.
        global $COURSE, $PAGE;

        $mform->addElement('hidden', 'marketplacegame', 0);
        $mform->setType('marketplacegame', PARAM_INT);
        $mform->hideIf('marketplacegame', 'contentsource', 'eq', 'upload');
        $mform->hideIf('marketplacegame', 'contentsource', 'eq', 'ai');

        $mform->addElement('static', 'marketplacegamechosen', get_string('marketplacegame', 'mod_awakegame'),
            html_writer::tag('span', get_string('marketplacenonechosen', 'mod_awakegame'), ['id' => 'awakegame-marketplace-chosen']));
        $mform->addHelpButton('marketplacegamechosen', 'marketplacegame', 'mod_awakegame');
        $mform->hideIf('marketplacegamechosen', 'contentsource', 'eq', 'upload');
        $mform->hideIf('marketplacegamechosen', 'contentsource', 'eq', 'ai');

        $mform->addElement('button', 'marketplacegamepicker', get_string('marketplacegamepickerbutton', 'mod_awakegame'));
        $mform->hideIf('marketplacegamepicker', 'contentsource', 'eq', 'upload');
        $mform->hideIf('marketplacegamepicker', 'contentsource', 'eq', 'ai');

        $pickerurl = new moodle_url('/mod/awakegame/marketplace_picker.php', ['course' => $COURSE->id]);
        $js = <<<JS
(function() {
    var openbutton = document.getElementById('id_marketplacegamepicker');
    if (openbutton) {
        openbutton.addEventListener('click', function() {
            window.open('{$pickerurl->out(false)}', 'awakegamemarketplacepicker', 'width=700,height=600');
        });
    }
    window.addEventListener('message', function(event) {
        if (event.origin !== window.location.origin) {
            return;
        }
        if (!event.data || event.data.type !== 'awakegame-marketplace-pick') {
            return;
        }
        var hidden = document.getElementById('id_marketplacegame');
        var label = document.getElementById('awakegame-marketplace-chosen');
        if (hidden) {
            hidden.value = event.data.id;
        }
        if (label) {
            label.textContent = event.data.title;
        }
    });
})();
JS;
        $PAGE->requires->js_init_code($js);

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
