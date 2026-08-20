<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Escribe una línea con marca de tiempo en un log propio del plugin
 * (dataroot/awakegame_debug.log), para poder ver EXACTAMENTE qué pasó en una
 * generación real concreta (qué PDF se detectó, qué etiqueta de tema se usó
 * en cada intento, si se aceptó por palabra clave o por el juez, etc.) sin
 * tener que adivinarlo ni volver a reproducirlo después — reproducirlo de
 * nuevo no vale como prueba porque cada llamada a la IA es independiente y
 * puede salir distinto la próxima vez.
 */
function awakegame_debug_log(string $line): void {
    global $CFG;

    try {
        $path = $CFG->dataroot . '/awakegame_debug.log';
        file_put_contents($path, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
    } catch (\Throwable $e) {
        // Un fallo escribiendo el log nunca debe romper la generación real.
    }
}

/**
 * @param string $feature FEATURE_xx constant
 * @return mixed
 */
function awakegame_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return false;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_MOD_PURPOSE:
            return defined('MOD_PURPOSE_CONTENT') ? MOD_PURPOSE_CONTENT : null;
        default:
            return null;
    }
}

function awakegame_add_instance($data, $mform = null) {
    global $DB;

    $data->timecreated  = time();
    $data->timemodified = time();
    $data->revision     = time();
    $data->id = $DB->insert_record('awakegame', $data);

    $context = context_module::instance($data->coursemodule);

    awakegame_process_content($context, $data);
    awakegame_grade_item_update($data);

    return $data->id;
}

function awakegame_update_instance($data, $mform = null) {
    global $DB;

    $old = $DB->get_record('awakegame', ['id' => $data->instance], 'aiprompt, contentsource, marketplaceid');

    // El prompt original queda bloqueado en el formulario una vez existe (ver
    // mod_form.php). Un campo bloqueado ("hardFreeze") no siempre viaja de
    // vuelta en los datos enviados por el navegador, así que aquí nos
    // aseguramos de no perder nunca el prompt original ya guardado, pase lo
    // que pase con lo que (o no) haya enviado el formulario.
    if ($old && $old->contentsource === 'ai' && trim((string) ($old->aiprompt ?? '')) !== '') {
        if (trim((string) ($data->aiprompt ?? '')) === '') {
            $data->aiprompt = $old->aiprompt;
        }
    }

    $data->timemodified = time();
    $data->id = $data->instance;
    $DB->update_record('awakegame', $data);

    $context = context_module::instance($data->coursemodule);

    awakegame_process_content($context, $data, $old);
    awakegame_grade_item_update($data);

    return true;
}

function awakegame_delete_instance($id) {
    global $DB, $CFG;

    if (!$awakegame = $DB->get_record('awakegame', ['id' => $id])) {
        return false;
    }

    $DB->delete_records('awakegame_grades', ['awakegameid' => $id]);
    $DB->delete_records('awakegame', ['id' => $id]);

    require_once($CFG->libdir . '/gradelib.php');
    grade_update('mod/awakegame', $awakegame->course, 'mod', 'awakegame', $awakegame->id, 0, null, ['deleted' => true]);

    return true;
}

/**
 * Crea o actualiza el item de calificación de esta actividad en el libro de
 * calificaciones de Moodle. $awakegame->grade determina el tipo:
 *   > 0  puntos, con ese máximo.
 *   < 0  escala, con id = -grade.
 *   = 0  sin calificar (se elimina el item si existía).
 */
function awakegame_grade_item_update($awakegame, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $item = ['itemname' => clean_param($awakegame->name, PARAM_NOTAGS)];

    if ($awakegame->grade > 0) {
        $item['gradetype'] = GRADE_TYPE_VALUE;
        $item['grademax']  = $awakegame->grade;
        $item['grademin']  = 0;
    } else if ($awakegame->grade < 0) {
        $item['gradetype'] = GRADE_TYPE_SCALE;
        $item['scaleid']   = -$awakegame->grade;
    } else {
        $item['gradetype'] = GRADE_TYPE_NONE;
    }

    if ($grades === 'reset') {
        $item['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/awakegame', $awakegame->course, 'mod', 'awakegame', $awakegame->id, 0, $grades, $item);
}

/**
 * Empuja hacia el libro de calificaciones las notas almacenadas en
 * awakegame_grades (todas, o solo las de un usuario si se indica $userid).
 */
function awakegame_update_grades($awakegame, $userid = 0) {
    global $DB;

    if ($awakegame->grade == 0) {
        awakegame_grade_item_update($awakegame);
        return;
    }

    $params = ['awakegameid' => $awakegame->id];
    if ($userid) {
        $params['userid'] = $userid;
    }

    $records = $DB->get_records('awakegame_grades', $params);
    if (empty($records)) {
        return;
    }

    $grades = [];
    foreach ($records as $record) {
        $grades[$record->userid] = (object) [
            'userid'   => $record->userid,
            'rawgrade' => $record->grade,
        ];
    }

    awakegame_grade_item_update($awakegame, $grades);
}

/**
 * Según el "contentsource" elegido en el formulario, procesa el paquete .zip
 * subido, genera el juego desde cero con IA, o le aplica una mejora
 * incremental al juego ya existente (sin rehacerlo desde el prompt original).
 *
 * @param context_module $context
 * @param stdClass $data datos del formulario (ya con ->id asignado)
 * @param stdClass|null $old registro anterior (aiprompt, contentsource), solo en update_instance
 */
function awakegame_process_content(context_module $context, $data, $old = null) {
    $source = $data->contentsource ?? 'upload';

    if ($source === 'ai') {
        $prompt = trim($data->aiprompt ?? '');
        if ($prompt === '') {
            return;
        }

        $fs = get_file_storage();
        $hascontent = $fs->file_exists($context->id, 'mod_awakegame', 'content', 0, '/', 'index.html');

        $oldsource = $old->contentsource ?? '';
        $isnewaiinstance = ($oldsource !== 'ai');
        $forceregenerate = !empty($data->airegenerate);
        $improvement = trim($data->aiimprovement ?? '');

        if ($isnewaiinstance || $forceregenerate || !$hascontent) {
            // Creación nueva, o "forzar regeneración": se genera desde cero
            // a partir del prompt original, descartando el juego actual. Se
            // pasan section/course de $data (ya disponibles en el formulario)
            // en vez de dejar que se derive del cmid: al CREAR la actividad,
            // course_modules.section de esta misma actividad todavía no está
            // resuelto (ver awakegame_get_section_row()), así que derivarlo
            // del cmid en ese momento siempre fallaría.
            awakegame_queue_content_generation($data->id, [
                'mode'       => 'generate',
                'instanceid' => $data->id,
                'prompt'     => $prompt,
                'sectionnum' => $data->section ?? null,
                'courseid'   => $data->course ?? null,
            ]);
        } else if ($improvement !== '') {
            // Mejora incremental: se parte del juego YA EXISTENTE y solo se
            // le aplica el cambio pedido, sin rehacer el resto.
            awakegame_queue_content_generation($data->id, [
                'mode'        => 'improve',
                'instanceid'  => $data->id,
                'prompt'      => $prompt,
                'improvement' => $improvement,
            ]);
        }
        // Si no hay mejora ni se fuerza la regeneración, se deja el juego
        // actual tal cual (el prompt original, por sí solo, no regenera nada).
    } else if ($source === 'marketplace') {
        $marketplacegameid = trim((string) ($data->marketplacegame ?? ''));
        $adapt = trim($data->marketplaceadapt ?? '');
        if ($marketplacegameid !== '' && $marketplacegameid !== '0') {
            if ($adapt !== '') {
                // Con instrucciones de adaptación hace falta IA: se procesa en
                // segundo plano, igual que la generación desde cero (ver
                // classes/task/generate_content.php), para no bloquear el
                // guardado del formulario con una llamada externa.
                awakegame_queue_content_generation($data->id, [
                    'mode'              => 'adaptmarketplace',
                    'instanceid'        => $data->id,
                    'marketplacegameid' => $marketplacegameid,
                    'adapt'             => $adapt,
                    'sectionnum'        => $data->section ?? null,
                    'courseid'          => $data->course ?? null,
                ]);
            } else {
                awakegame_use_marketplace_entry($context, $data->id, $marketplacegameid);
            }
        }
    } else if (!empty($data->packagefile)) {
        file_save_draft_area_files($data->packagefile, $context->id, 'mod_awakegame', 'package', 0,
            ['subdirs' => 0, 'maxfiles' => 1]);
        awakegame_extract_package($context, $data->id);
    }

    // Compartir en el Marketplace: solo se publica automáticamente la PRIMERA
    // vez que se marca la casilla (todavía no tiene marketplaceid). Si ya
    // estaba publicado, guardar el formulario de nuevo NO lo actualiza — eso
    // es siempre una acción manual del botón "Actualizar en el Marketplace"
    // (ver view.php / marketplace_update.php), tal y como se pidió.
    $oldmarketplaceid = trim((string) ($old->marketplaceid ?? ''));
    if (!empty($data->marketplaceshare) && $oldmarketplaceid === '') {
        awakegame_queue_marketplace_task($data->id, 'publish');
    }
}

/**
 * Extrae el .zip subido en el área 'package' hacia el área 'content',
 * que es desde donde se sirve el juego (index.html y sus recursos).
 */
function awakegame_extract_package(context_module $context, $instanceid) {
    global $DB;

    $fs = get_file_storage();

    $files = $fs->get_area_files($context->id, 'mod_awakegame', 'package', 0, 'itemid', false);
    if (empty($files)) {
        return false;
    }
    $zipfile = reset($files);

    $fs->delete_area_files($context->id, 'mod_awakegame', 'content', 0);

    $packer = get_file_packer('application/zip');
    $zipfile->extract_to_storage($packer, $context->id, 'mod_awakegame', 'content', 0, '/');

    awakegame_flatten_single_root_folder($context, 0);

    $DB->set_field('awakegame', 'revision', time(), ['id' => $instanceid]);

    return true;
}

/**
 * Si el .zip subido contenía una única carpeta contenedora (por ejemplo
 * "MiJuego/index.html" en vez de "index.html"), la aplana moviendo su
 * contenido a la raíz del área de archivos. Es el caso típico cuando se
 * comprime una carpeta completa desde el explorador de Windows.
 */
function awakegame_flatten_single_root_folder(context_module $context, $itemid) {
    $fs = get_file_storage();

    if ($fs->get_file($context->id, 'mod_awakegame', 'content', $itemid, '/', 'index.html')) {
        return; // Ya está en la raíz, nada que hacer.
    }

    $files = $fs->get_area_files($context->id, 'mod_awakegame', 'content', $itemid, 'filepath', false);

    $topfolders = [];
    foreach ($files as $file) {
        $relativepath = trim($file->get_filepath(), '/');
        if ($relativepath === '') {
            return; // Ya hay archivos en la raíz, no forzar nada.
        }
        $segments = explode('/', $relativepath);
        $topfolders[$segments[0]] = true;
    }

    if (count($topfolders) !== 1) {
        return; // Estructura ambigua, no adivinar.
    }

    $prefix = '/' . array_key_first($topfolders) . '/';
    $prefixlen = strlen($prefix);

    foreach ($files as $file) {
        $oldpath = $file->get_filepath();
        if (strpos($oldpath, $prefix) !== 0) {
            continue;
        }

        $newpath = '/' . substr($oldpath, $prefixlen);

        if (!$fs->file_exists($context->id, 'mod_awakegame', 'content', $itemid, $newpath, $file->get_filename())) {
            $fs->create_file_from_storedfile([
                'contextid' => $context->id,
                'component' => 'mod_awakegame',
                'filearea'  => 'content',
                'itemid'    => $itemid,
                'filepath'  => $newpath,
                'filename'  => $file->get_filename(),
            ], $file);
        }

        $file->delete();
    }
}

/**
 * Marca la actividad como "generación de IA pendiente" (para que view.php
 * muestre un aviso de "generando..." en vez del juego a medio actualizar) y
 * encola una tarea ad-hoc de Moodle que hace la llamada real a Claude.
 *
 * La generación (y sobre todo la comprobación de tema, que puede encadenar
 * varias llamadas a la IA con sus propios reintentos) puede tardar varios
 * minutos: se ejecuta vía cron, fuera de la petición HTTP que guarda el
 * formulario, para no depender de los timeouts de nginx/PHP-FPM de la
 * petición web (esto es lo que antes causaba un 504 Gateway Time-out).
 *
 * @param int $instanceid
 * @param array $customdata datos para \mod_awakegame\task\generate_content:
 *        'mode' ('generate'|'improve'), 'instanceid', 'prompt', y según el
 *        modo 'sectionnum'/'courseid' (generate) o 'improvement' (improve).
 */
function awakegame_queue_content_generation(int $instanceid, array $customdata): void {
    global $DB;

    $DB->set_field('awakegame', 'aipendingreview', 1, ['id' => $instanceid]);

    $task = new \mod_awakegame\task\generate_content();
    $task->set_custom_data($customdata);
    \core\task\manager::queue_adhoc_task($task);
}

/**
 * Genera el juego desde cero (un único index.html autocontenido) llamando a
 * la API de Claude con el prompt original del profesor, y lo guarda como el
 * contenido de la actividad (área 'content'), reemplazando lo que hubiera.
 *
 * $sectionnum/$courseid son el número de sección y el curso, cuando ya se
 * conocen de antemano (por ejemplo, los datos del formulario en
 * add_instance): permiten resolver la sección de forma fiable incluso
 * mientras se está CREANDO la actividad, momento en el que el campo
 * course_modules.section de esta misma actividad todavía no está puesto (ver
 * el aviso en awakegame_get_section_row()). Si no se pasan, se cae en la
 * resolución por cmid de toda la vida (válida para una actividad que ya
 * existe, como al aplicar una mejora).
 */
function awakegame_generate_from_prompt(
    context_module $context,
    $instanceid,
    $prompt,
    $sectionnum = null,
    $courseid = null
) {
    global $DB;

    if ($sectionnum !== null && $courseid !== null) {
        $section = awakegame_get_section_row_by_number($courseid, $sectionnum);
    } else {
        $section = awakegame_get_section_row($context->instanceid);
    }

    $basesectioncontext = $section ? awakegame_build_section_context_text($section, $context->instanceid) : '';

    // La etiqueta de tema, cuando no hay PDF, es directamente el nombre real
    // de la sección (un dato exacto de la base de datos, escrito por el
    // propio profesor) — no algo que haya que adivinar con expresiones
    // regulares sobre texto libre generado por la IA.
    $fallbacktopiclabel = ($section && trim((string) $section->name) !== '') ? format_string($section->name) : '';

    $pdf = $section ? awakegame_find_section_pdf($context->instanceid, $section) : null;

    awakegame_debug_log("instanceid={$instanceid} INICIO pdf=" .
        ($pdf ? $pdf->get_filename() : 'ninguno') . " fallback=[{$fallbacktopiclabel}] prompt=[{$prompt}]");

    $result = awakegame_generate_with_topic_check($prompt, $basesectioncontext, $fallbacktopiclabel, $pdf);
    $html = awakegame_inject_score_bridge($result['html'], $instanceid);

    awakegame_save_content($context, $html);

    $DB->set_field('awakegame', 'revision', time(), ['id' => $instanceid]);

    awakegame_debug_log("instanceid={$instanceid} FIN verified=" . ($result['verified'] ? 'si' : 'no') .
        " topiclabel=[{$result['topiclabel']}] intentos=" . ($result['attempts'] ?? '?'));

    // Aviso visible para el profesor cuando los intentos automáticos se han
    // agotado sin poder confirmar que el juego trata del tema esperado. Sin
    // este aviso, un profesor sin conocimientos técnicos (y sin nadie
    // revisando el código a mano) no tendría ninguna forma de saber que el
    // resultado guardado no se pudo verificar, y se quedaría con un juego
    // potencialmente fuera de tema sin enterarse.
    if (!$result['verified'] && $result['topiclabel'] !== '') {
        \core\notification::add(
            get_string('aitopicunverified', 'mod_awakegame', $result['topiclabel']),
            \core\output\notification::NOTIFY_WARNING
        );
    }
}

/**
 * Genera el juego y comprueba automáticamente si el resultado menciona el
 * tema esperado (sacado del PDF o de la sección). Si no lo menciona, vuelve
 * a intentarlo (hasta 5 intentos en total) avisando explícitamente del fallo
 * del intento anterior, en vez de depender de que un humano lo detecte y
 * pida "Regenerar desde cero" a mano.
 *
 * Importante: si hay un PDF, se vuelve a leer y resumir EN CADA intento (no
 * solo en el primero). Leer un PDF con IA no es 100% infalible — puede fallar
 * una vez de forma puntual y devolver un tema equivocado — así que si nos
 * quedáramos con la primera lectura para siempre, un fallo puntual de esa
 * única lectura "envenenaría" todos los intentos sin ninguna posibilidad de
 * corregirse solo. Al releer el PDF de forma independiente en cada intento,
 * una lectura mala en el intento 1 tiene más oportunidades de corregirse en
 * el 2 o el 3, en vez de arrastrar el mismo error los tres intentos.
 *
 * @return array{html: string, verified: bool, topiclabel: string} 'verified'
 *         indica si se pudo confirmar (por palabra clave o por el juez) que
 *         el resultado trata del tema esperado; 'topiclabel' es el tema con
 *         el que se comparó en el ÚLTIMO intento, útil para avisar al
 *         profesor si al final no se pudo verificar.
 */
function awakegame_generate_with_topic_check(
    string $prompt,
    string $basesectioncontext,
    string $fallbacktopiclabel,
    ?\stored_file $pdf = null
): array {
    $maxattempts = 5;
    $html = '';

    for ($attempt = 1; $attempt <= $maxattempts; $attempt++) {
        $sectioncontext = $basesectioncontext;
        $topiclabel = $fallbacktopiclabel;
        $pdfsummary = '';
        // Si no hay PDF en la sección, el nombre de la sección (el fallback)
        // es la única fuente disponible y es válido usarlo tal cual. Si SÍ
        // hay un PDF, solo nos fiamos del resultado si de verdad hemos
        // conseguido sacar de él una etiqueta de tema real esta vez.
        $topicfromrealsource = !$pdf;

        if ($pdf) {
            $pdfsummary = awakegame_summarize_pdf($pdf);
            $pdftopiclabel = awakegame_extract_pdf_topic_label($pdfsummary);

            if ($pdftopiclabel !== '') {
                $sectioncontext = trim($sectioncontext .
                    "\n\nContenido real del PDF de esta sección (\"" . $pdf->get_filename() . "\"), " .
                    "extraído automáticamente — esta es la fuente de verdad principal del tema:\n" .
                    $pdfsummary);
                $topiclabel = $pdftopiclabel;
                $topicfromrealsource = true;
            }
            // Si no se pudo sacar una etiqueta de tema del PDF (fallo puntual
            // leyéndolo, o la IA no siguió el formato esperado), se deja
            // $topicfromrealsource en false: este intento se descarta y se
            // reintenta con una lectura nueva, en vez de fiarse del nombre
            // genérico de la sección como si fuera un tema real (ver más abajo).
        }

        $attemptprompt = $prompt;

        if ($attempt > 1) {
            $attemptprompt .= "\n\n(Aviso automático del sistema: tu intento anterior generó un juego " .
                "que NO trataba sobre el tema real que se te indicó. Este juego DEBE tratar sobre: \"" .
                $topiclabel . "\". Revisa el \"Contexto de la sección del curso\" de abajo y asegúrate " .
                "de que las preguntas/palabras/elementos del juego mencionen explícitamente términos " .
                "de ese tema real, no de otro tema genérico o relacionado pero distinto.)";
        }

        $html = awakegame_call_anthropic($attemptprompt, $sectioncontext);
        $tituloobtenido = '';
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $mtitulo)) {
            $tituloobtenido = trim(strip_tags($mtitulo[1]));
        }

        if (!$topicfromrealsource) {
            // Hay un PDF en la sección, pero esta lectura en concreto no ha
            // conseguido sacar de él una etiqueta de tema fiable (fallo
            // puntual leyéndolo, o la IA no siguió el formato esperado). NO
            // usamos el nombre genérico de la sección ("Tema 10", "Unidad 3"…)
            // como criterio de verificación: su única palabra "significativa"
            // suele ser justo esa ("tema", "unidad"…), que la IA repite en el
            // título de CUALQUIER juego que genere, acertado o no — así que la
            // comprobación de palabras clave se convertiría en un cheque en
            // blanco que da por bueno cualquier resultado. Mejor reintentar de
            // verdad, con una nueva lectura independiente del PDF.
            awakegame_debug_log("  intento={$attempt} lectura del PDF NO fiable esta vez -> se reintenta");
            continue;
        }

        if ($topiclabel === '') {
            // No hay nada con lo que comparar; no se puede verificar, pero
            // tampoco hay tema esperado que incumplir, así que se acepta.
            awakegame_debug_log("  intento={$attempt} sin topiclabel -> ACEPTADO sin verificar. titulo=[{$tituloobtenido}]");
            return ['html' => $html, 'verified' => true, 'topiclabel' => '', 'attempts' => $attempt];
        }

        // Filtro barato (palabras clave, sin llamar a la IA): si ya coincide
        // claramente, se acepta directo y se ahorra la llamada al juez.
        if (awakegame_html_matches_topic($html, $topiclabel)) {
            awakegame_debug_log("  intento={$attempt} topiclabel=[{$topiclabel}] titulo=[{$tituloobtenido}] " .
                "-> ACEPTADO por palabra clave");
            return ['html' => $html, 'verified' => true, 'topiclabel' => $topiclabel, 'attempts' => $attempt];
        }

        // El filtro barato no ha encontrado la palabra exacta, pero eso no
        // significa que el tema esté mal — puede ser solo una variante (p.ej.
        // el tema dice "romana" y el juego usa "Roma"/"romanización"). Antes
        // de rechazar, se confirma con una comprobación más fina (un "juez"
        // con IA). Se le pasa también el resumen COMPLETO del PDF (no solo la
        // etiqueta corta de 2-6 palabras): así compara el juego contra el
        // contenido real y detallado del documento, igual que haría un
        // profesor leyendo ambas cosas, en vez de fiarse solo de una frase
        // resumen que podría no capturar todos los matices.
        if (awakegame_judge_topic_match($html, $topiclabel, $pdfsummary)) {
            awakegame_debug_log("  intento={$attempt} topiclabel=[{$topiclabel}] titulo=[{$tituloobtenido}] " .
                "palabra clave=no, juez=SI -> ACEPTADO por el juez");
            return ['html' => $html, 'verified' => true, 'topiclabel' => $topiclabel, 'attempts' => $attempt];
        }

        awakegame_debug_log("  intento={$attempt} topiclabel=[{$topiclabel}] titulo=[{$tituloobtenido}] " .
            "palabra clave=no, juez=no -> RECHAZADO, se reintenta");
    }

    // Se agotaron los intentos: se usa el último resultado obtenido, aunque
    // no se haya podido confirmar que refleja bien el tema esperado. Queda
    // marcado como "no verificado" para que el llamador pueda avisar al
    // profesor en vez de dejarlo pasar en silencio.
    awakegame_debug_log('  intentos agotados (' . $maxattempts . ') sin verificar -> se guarda el último ' .
        'de todos modos y se avisa al profesor');
    return ['html' => $html, 'verified' => false, 'topiclabel' => $topiclabel, 'attempts' => $maxattempts];
}

/**
 * "Juez" con IA: en una llamada aparte, barata y sin razonamiento, le
 * pregunta directamente a Claude si el contenido visible del juego trata de
 * verdad sobre el tema esperado (más allá de que aparezca una palabra suelta
 * por casualidad). Solo se llama cuando el filtro de palabras clave ya ha
 * pasado, como segunda confirmación más fiable.
 *
 * Si se dispone del resumen completo del PDF (no solo la etiqueta corta de
 * tema), se le pasa también al juez como fuente de verdad principal: así
 * compara el juego contra el contenido real y detallado del documento —los
 * términos, definiciones y datos concretos que de verdad aparecen en él— en
 * vez de fiarse solo de una frase-resumen de 2-6 palabras, que por sí sola
 * puede no bastar para detectar que el juego usa términos que no vienen del
 * documento aunque coincidan con el tema en general.
 */
function awakegame_judge_topic_match(string $html, string $topiclabel, string $pdfsummary = ''): bool {
    // Importante: NO se quita el <script> — en estos juegos, los términos y
    // preguntas concretas del tema suelen vivir en un array de datos dentro
    // del JavaScript, no en el HTML estático. Solo se quita el <style> (puro
    // CSS, sin valor para esta comprobación) para no gastar espacio en ruido.
    $texto = awakegame_extract_topic_relevant_text($html);
    $texto = mb_substr($texto, 0, 6000);

    if (trim($pdfsummary) !== '') {
        $systemprompt = "Eres un verificador estricto. Se te da el texto visible de un mini-juego " .
            "educativo y un extracto con el contenido real de la fuente (un PDF) del que debería salir " .
            "ese juego. Responde ÚNICAMENTE con la palabra SI o la palabra NO, en mayúsculas y sin nada " .
            "más (ni explicaciones, ni puntuación): ¿los términos, preguntas, pares o elementos que se " .
            "usan de verdad en el juego se corresponden con datos que aparecen realmente en el extracto " .
            "de la fuente, más allá de tratar el mismo tema en general? Responde NO si el juego trata un " .
            "tema relacionado o parecido pero usa sus propios términos/datos en vez de los del extracto, " .
            "o si trata un tema completamente distinto.";

        $usertext = "Extracto con el contenido real de la fuente:\n" . mb_substr($pdfsummary, 0, 8000) .
            "\n\nTexto visible del juego a comprobar:\n" . $texto;
    } else {
        $systemprompt = "Eres un verificador estricto. Se te da el texto visible de un mini-juego " .
            "educativo y un tema esperado. Responde ÚNICAMENTE con la palabra SI o la palabra NO, en " .
            "mayúsculas y sin nada más (ni explicaciones, ni puntuación): ¿el contenido concreto del " .
            "juego (las preguntas, palabras, pares o elementos que se usan de verdad en el juego) trata " .
            "de forma sustancial sobre ese tema, más allá de una simple mención de paso o de una " .
            "coincidencia superficial de alguna palabra?";

        $usertext = "Tema esperado: " . $topiclabel . "\n\nTexto visible del juego:\n" . $texto;
    }

    try {
        $respuesta = awakegame_anthropic_request($systemprompt, $usertext, 10, 'disabled');
        return (bool) preg_match('/^\s*s[ií]/i', trim($respuesta));
    } catch (\Throwable $e) {
        // Si el juez falla por lo que sea (red, error de la API...), no se
        // bloquea la creación de la actividad por esto: se acepta el intento.
        return true;
    }
}

/**
 * Saca la etiqueta corta de tema del resumen de un PDF, que está OBLIGADO
 * (ver el system prompt de awakegame_summarize_pdf) a empezar por una línea
 * literal "TEMA_CORTO: ...". Al ser un formato fijo impuesto por nosotros
 * (no texto libre que haya que adivinar), es una extracción fiable y no
 * depende de que la IA redacte el resumen con una frase concreta cada vez.
 * Devuelve '' si el formato no se cumplió (p. ej. fallo puntual de la IA).
 */
function awakegame_extract_pdf_topic_label(string $summary): string {
    if (preg_match('/^\s*TEMA_CORTO:\s*(.+)$/mi', $summary, $m)) {
        return trim($m[1], " \t\n\r\0\x0B.,;*\"");
    }

    return '';
}

/**
 * Comprueba (de forma barata, sin llamar a la IA) si el texto visible del
 * HTML generado contiene al menos una palabra "significativa" (4+ letras, y
 * no genérica) de la etiqueta de tema. Es una comprobación aproximada por
 * palabras clave, no una verificación semántica completa, pero es rápida y
 * detecta los casos más claros de "se fue a otro tema totalmente distinto".
 */
function awakegame_html_matches_topic(string $html, string $topiclabel): bool {
    // Palabras genéricas de andamiaje/pedagógicas que NO cuentan como
    // "palabra significativa del tema", aunque tengan 4+ letras. Son palabras
    // que describen el TIPO de actividad o de mecánica de juego, no la
    // MATERIA real de la que trata — así que aparecen en el texto de
    // CUALQUIER juego generado, sea del tema correcto o no (por ejemplo, un
    // juego generado por error sobre "la comunicación" bien podría describir
    // su propia mecánica como "clasifica los conceptos" o "une los
    // términos", coincidiendo por casualidad con una palabra de la etiqueta
    // de tema aunque el contenido real no tenga nada que ver). Confiar en una
    // sola palabra así de genérica ha causado aceptaciones incorrectas en la
    // práctica, saltándose la comprobación más fiable del juez.
    $genericas = [
        'tema', 'unidad', 'leccion', 'bloque', 'capitulo', 'modulo', 'apartado', 'seccion', 'curso',
        'clasificacion', 'clasificaciones', 'termino', 'terminos', 'definicion', 'definiciones',
        'concepto', 'conceptos', 'elemento', 'elementos', 'actividad', 'actividades', 'ejercicio',
        'ejercicios', 'juego', 'pregunta', 'preguntas', 'respuesta', 'respuestas', 'caracteristica',
        'caracteristicas', 'tipo', 'tipos', 'clase', 'clases', 'grupo', 'grupos', 'parte', 'partes',
    ];

    // Igual que en awakegame_judge_topic_match: no se quita el <script>,
    // porque ahí es donde suelen vivir los términos/preguntas reales del
    // juego (en un array de datos), no en el HTML estático.
    $texto = awakegame_normalize_text(awakegame_extract_topic_relevant_text($html));

    $palabras = preg_split('/[\s,;:\-\/]+/', awakegame_normalize_text($topiclabel));
    $palabras = array_filter($palabras, function ($palabra) use ($genericas) {
        return mb_strlen($palabra) >= 4 && !in_array($palabra, $genericas, true);
    });

    if (empty($palabras)) {
        // No queda ninguna palabra realmente específica del tema con la que
        // comparar (la etiqueta era demasiado genérica, o vacía). En vez de
        // aceptar a ciegas, se deja que decida el juez, que sí puede razonar
        // sobre el contenido real en vez de depender de una palabra suelta.
        return false;
    }

    foreach ($palabras as $palabra) {
        if (mb_stripos($texto, $palabra) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Extrae el texto "relevante para el tema" de un HTML de juego: quita el
 * <style> (puro CSS, sin valor) pero mantiene el contenido del <script>,
 * porque en estos juegos autogenerados los términos/preguntas concretas del
 * tema casi siempre viven en un array de datos dentro del JavaScript, no en
 * el HTML estático (que suele tener solo textos genéricos de interfaz como
 * "Aciertos: 0/10" o "Selecciona una opción").
 */
function awakegame_extract_topic_relevant_text(string $html): string {
    $sinestilos = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
    $texto = strip_tags($sinestilos);
    return trim(preg_replace('/\s+/', ' ', $texto));
}

/**
 * Pasa un texto a minúsculas y le quita acentos/diéresis comunes en español,
 * para comparar palabras clave de forma más robusta.
 */
function awakegame_normalize_text(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $map = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u'];
    return strtr($text, $map);
}

/**
 * Pide a Claude, en una llamada sencilla y aislada (sin las demás
 * instrucciones de generación de juego), que lea un PDF y extraiga su tema y
 * términos/conceptos clave. Devuelve el resumen en texto, o '' si falla (en
 * cuyo caso la generación del juego sigue adelante solo con el contexto de
 * sección que hubiera, sin bloquear la creación de la actividad).
 */
function awakegame_summarize_pdf(\stored_file $pdf): string {
    $systemprompt = "Eres un asistente que extrae, de un documento PDF educativo, la información " .
        "necesaria para crear después un juego o cuestionario FIEL AL CONTENIDO EXACTO del " .
        "documento. Lee el PDF completo, párrafo a párrafo, y responde EN ESPAÑOL con EXACTAMENTE " .
        "este formato, sin nada más antes ni explicaciones de ningún tipo: " .
        "la PRIMERA línea de tu respuesta debe ser literalmente \"TEMA_CORTO: \" seguido de entre 2 y " .
        "6 palabras (sin comillas, sin punto final, sin nada más en esa línea) que resuman el tema " .
        "central del documento — por ejemplo: \"TEMA_CORTO: el Imperio Romano\". Después de esa " .
        "primera línea y una línea en blanco, extrae de forma EXHAUSTIVA (no te limites a unos pocos, " .
        "cubre TODO el documento, puede ser una lista larga si el documento da para ello) los " .
        "términos, conceptos, fechas, datos, definiciones y pares término-definición que aparecen " .
        "LITERALMENTE en el propio documento. " .
        "REGLA CRÍTICA: no completes, expliques ni amplíes ningún dato con tu propio conocimiento " .
        "general sobre el tema, aunque sea correcto — extrae ÚNICAMENTE lo que el documento dice de " .
        "verdad, con sus mismas palabras o muy cerca de ellas. Si el documento no menciona algo (una " .
        "fecha, un personaje, un hecho), NO lo incluyas aunque lo sepas por otra vía; es preferible " .
        "una lista más corta pero 100% fiel al documento que una más larga con datos añadidos por ti. " .
        "Es muy importante que la primera línea empiece exactamente por \"TEMA_CORTO:\", sin ningún " .
        "asterisco, título ni texto delante.";

    try {
        $content = awakegame_build_user_content(
            'Extrae el tema y TODOS los términos/datos/conceptos clave que aparecen literalmente en ' .
            'este PDF, tal y como se te ha indicado. No añadas nada que no esté en el documento.',
            $pdf
        );

        return awakegame_anthropic_request($systemprompt, $content);
    } catch (\Throwable $e) {
        // Si falla (PDF ilegible, error de red, etc.), seguimos sin resumen:
        // la generación del juego usará solo el resto del contexto disponible.
        return '';
    }
}

/**
 * Datos básicos de la sección del curso donde vive esta actividad (course,
 * número de sección, nombre, resumen). Devuelve null si no se encuentra.
 *
 * @param int $cmid course_module id de esta actividad (context_module->instanceid)
 */
function awakegame_get_section_row($cmid) {
    global $DB;

    $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id, course, section');
    if (!$cm) {
        // No debería pasar nunca (esta función se llama sobre la propia
        // actividad que se está creando/editando, que ya tiene que existir
        // como course_module para haber llegado hasta aquí), pero si pasa,
        // se registra con detalle: es justo el tipo de dato que hace falta
        // para diagnosticar un fallo de detección de tema sin tener que
        // reproducirlo a ciegas después.
        awakegame_debug_log("get_section_row cmid={$cmid}: no se encontró el course_module (INESPERADO)");
        return null;
    }

    // OJO: al CREAR una actividad nueva (add_instance), Moodle inserta la
    // fila de course_modules ANTES de asignarle su sección real — el campo
    // "section" de esa fila vale 0 (ninguna sección real tiene id=0) durante
    // toda la llamada a nuestro add_instance(), y solo se actualiza al valor
    // correcto DESPUÉS de que add_instance() ya haya terminado. Por eso esta
    // función, usada tal cual sobre un cmid recién creado, siempre falla al
    // buscar la sección — no es un fallo intermitente de caché, es que el
    // dato todavía no existe en ese momento exacto. Ver
    // awakegame_get_section_row_by_number(), que evita este problema usando
    // el número de sección que ya viene en los datos del formulario.
    $section = $DB->get_record('course_sections', ['id' => $cm->section], 'section, name, summary');
    if (!$section) {
        awakegame_debug_log("get_section_row cmid={$cmid}: course_module.section={$cm->section} no " .
            'corresponde a ninguna fila de course_sections (esperado si esta actividad se está ' .
            'creando ahora mismo: ver awakegame_get_section_row_by_number)');
        return null;
    }

    $section->course = $cm->course;
    $section->cmid   = $cm->id;

    return $section;
}

/**
 * Igual que awakegame_get_section_row(), pero busca la sección directamente
 * por curso + número de sección en vez de por el "section" (id de fila) de
 * course_modules. Esto es lo que hay que usar al CREAR una actividad nueva:
 * en ese momento, course_modules.section todavía vale 0 (ver el aviso en
 * awakegame_get_section_row()), pero el número de sección real ya viene
 * disponible en los datos del formulario ($data->section en add_instance),
 * así que no hace falta depender del campo todavía sin resolver.
 */
function awakegame_get_section_row_by_number($courseid, $sectionnum) {
    global $DB;

    $section = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $sectionnum],
        'id, section, name, summary');
    if (!$section) {
        awakegame_debug_log("get_section_row_by_number course={$courseid} section={$sectionnum}: " .
            'no se encontró ninguna fila de course_sections (INESPERADO)');
        return null;
    }

    $section->course = $courseid;

    return $section;
}

/**
 * Recoge pistas sobre el tema de la sección del curso donde se está creando
 * el juego (nombre de la sección, su descripción, y los nombres de otras
 * actividades/recursos que ya haya en ella). Se usa para que la IA pueda
 * deducir de qué debe tratar el juego cuando el prompt del profesor no lo
 * especifica. Devuelve '' si no hay nada útil que aportar.
 */
function awakegame_get_section_context($cmid) {
    $section = awakegame_get_section_row($cmid);
    if (!$section) {
        return '';
    }

    return awakegame_build_section_context_text($section, $cmid);
}

/**
 * Construye el texto de contexto a partir de una fila de sección ya
 * obtenida (evita repetir las mismas consultas cuando el llamador también
 * necesita el nombre de la sección directamente, sin pasar por texto libre).
 */
function awakegame_build_section_context_text($section, $cmid) {
    global $CFG;
    require_once($CFG->libdir . '/weblib.php');

    $parts = [];

    if (trim((string) $section->name) !== '') {
        $parts[] = 'Nombre de la sección del curso: ' . format_string($section->name);
    }

    $summary = trim(html_to_text((string) $section->summary));
    if ($summary !== '') {
        $parts[] = 'Descripción de la sección del curso: ' . $summary;
    }

    try {
        $modinfo = get_fast_modinfo($section->course);
        $siblingnames = [];
        foreach ($modinfo->get_cms() as $othercm) {
            if ((int) $othercm->sectionnum === (int) $section->section
                    && (int) $othercm->id !== (int) $cmid && $othercm->uservisible) {
                $siblingnames[] = $othercm->get_formatted_name();
            }
        }
        if (!empty($siblingnames)) {
            $parts[] = 'Otras actividades/recursos que ya hay en esa misma sección del curso: ' .
                implode(', ', array_slice($siblingnames, 0, 15));
        }
    } catch (\Throwable $e) {
        // Si algo falla obteniendo el modinfo, seguimos solo con nombre/resumen de la sección.
    }

    return implode("\n", $parts);
}

/**
 * Busca un PDF entre los recursos (actividades "Archivo" o "Carpeta") de la
 * misma sección del curso, para poder basar el contenido del juego en el
 * material real del tema en vez de solo en su nombre. Devuelve el primer PDF
 * que encuentre (por debajo de un tamaño razonable para no disparar el coste
 * ni la memoria), o null si no hay ninguno.
 *
 * @param int $cmid course_module id de esta actividad (context_module->instanceid), para excluirla
 *        de la búsqueda si apareciera como su propio "hermano"
 * @param stdClass $section fila de sección ya resuelta (ver awakegame_get_section_row() o, sobre
 *        todo al CREAR una actividad nueva, awakegame_get_section_row_by_number())
 * @return \stored_file|null
 */
function awakegame_find_section_pdf($cmid, $section) {
    $pdf = awakegame_find_section_pdf_once($cmid, $section);
    if ($pdf) {
        return $pdf;
    }

    // No se ha encontrado nada en el primer intento. Esto puede ser porque de
    // verdad no hay ningún PDF en la sección, PERO también puede pasar porque
    // esta misma función se está llamando DURANTE la creación de la propia
    // actividad nueva: Moodle reconstruye la caché de módulos del curso
    // DESPUÉS de que termine de crear la actividad, no antes, así que en ese
    // instante concreto la caché que lee get_fast_modinfo() puede estar
    // momentáneamente desactualizada respecto a otros recursos de la sección.
    // Detectado en la práctica: una actividad nueva no encontraba un PDF que
    // ya llevaba tiempo subido en la misma sección, aunque comprobándolo
    // segundos después (con la caché ya reconstruida) sí se encontraba sin
    // problema. Para no depender de esa carrera, se fuerza una reconstrucción
    // explícita de la caché y se reintenta una única vez antes de rendirse.
    try {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        rebuild_course_cache($section->course, true);
    } catch (\Throwable $e) {
        awakegame_debug_log("find_section_pdf cmid={$cmid}: rebuild_course_cache falló (" . $e->getMessage() . ')');
        return null;
    }

    $pdf = awakegame_find_section_pdf_once($cmid, $section);
    awakegame_debug_log("find_section_pdf cmid={$cmid}: nada en el primer intento, tras reconstruir " .
        'la caché ' . ($pdf ? ('SÍ se encontró: ' . $pdf->get_filename()) : 'sigue sin encontrarse nada'));

    return $pdf;
}

/**
 * Un único paso de búsqueda de PDF en la sección (sin reintentos ni
 * reconstrucción de caché) — ver awakegame_find_section_pdf(), que es quien
 * debe llamarse normalmente.
 */
function awakegame_find_section_pdf_once($cmid, $section) {
    // Límite prudente muy por debajo del máximo de la API (32 MB), para no
    // disparar el tiempo/memoria de una petición PHP síncrona.
    $maxbytes = 15 * 1024 * 1024;

    try {
        $modinfo = get_fast_modinfo($section->course);
    } catch (\Throwable $e) {
        return null;
    }

    $fs = get_file_storage();

    foreach ($modinfo->get_cms() as $othercm) {
        if ((int) $othercm->sectionnum !== (int) $section->section
                || (int) $othercm->id === (int) $cmid || !$othercm->uservisible) {
            continue;
        }

        if (!in_array($othercm->modname, ['resource', 'folder'], true)) {
            continue;
        }

        $othercontext = context_module::instance($othercm->id);
        $files = $fs->get_area_files($othercontext->id, 'mod_' . $othercm->modname, 'content', 0, 'sortorder', false);

        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }

            $ispdf = $file->get_mimetype() === 'application/pdf'
                || strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION)) === 'pdf';

            if ($ispdf && $file->get_filesize() > 0 && $file->get_filesize() <= $maxbytes) {
                return $file;
            }
        }
    }

    return null;
}

/**
 * Aplica una mejora incremental sobre el juego YA EXISTENTE: le pasa a la IA
 * el HTML actual (sin nuestro puente de puntuación, para no confundirla) más
 * el cambio pedido, y le pide que devuelva el juego completo actualizado
 * conservando todo lo demás. Así una mejora no "reinicia" el juego desde el
 * prompt original.
 */
function awakegame_apply_improvement(context_module $context, $instanceid, $originalprompt, $improvement) {
    global $DB;

    $fs = get_file_storage();
    $currentfile = $fs->get_file($context->id, 'mod_awakegame', 'content', 0, '/', 'index.html');
    $currenthtml = $currentfile ? awakegame_strip_score_bridge($currentfile->get_content()) : '';

    if (trim($currenthtml) === '') {
        // No había nada que mejorar (raro llegar aquí); se genera desde cero.
        awakegame_generate_from_prompt($context, $instanceid, $originalprompt);
        return;
    }

    $html = awakegame_call_anthropic_improvement($originalprompt, $currenthtml, $improvement);
    $html = awakegame_inject_score_bridge($html, $instanceid);

    awakegame_save_content($context, $html);

    $DB->set_field('awakegame', 'revision', time(), ['id' => $instanceid]);
    // La mejora ya se aplicó: se vacía para que el campo quede listo para la próxima.
    $DB->set_field('awakegame', 'aiimprovement', '', ['id' => $instanceid]);
}

/**
 * Reemplaza el contenido guardado (área 'content') por el HTML indicado.
 */
function awakegame_save_content(context_module $context, string $html) {
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'mod_awakegame', 'content', 0);
    $fs->delete_area_files($context->id, 'mod_awakegame', 'package', 0);

    $fs->create_file_from_string([
        'contextid' => $context->id,
        'component' => 'mod_awakegame',
        'filearea'  => 'content',
        'itemid'    => 0,
        'filepath'  => '/',
        'filename'  => 'index.html',
    ], $html);
}

/**
 * Clona un juego publicado en el Marketplace (de otro colegio o del propio)
 * como el contenido de esta actividad.
 */
function awakegame_use_marketplace_entry(context_module $context, $instanceid, $marketplacegameid) {
    global $DB;

    $game = awakegame_marketplace_get_game($marketplacegameid);
    if (!$game) {
        return; // El juego ya no existe en el Marketplace, o no se pudo contactar; no se rompe el guardado.
    }

    awakegame_save_content($context, $game['html']);
    $DB->set_field('awakegame', 'revision', time(), ['id' => $instanceid]);
}

/**
 * Igual que awakegame_use_marketplace_entry(), pero en vez de clonar el
 * contenido tal cual, usa el juego del Marketplace ÚNICAMENTE como plantilla
 * de mecánica/aspecto y regenera el contenido real (términos, preguntas,
 * datos) a partir del PDF/contexto de la sección del curso donde se está
 * creando la actividad — igual que awakegame_generate_from_prompt(), con la
 * misma comprobación de tema y reintentos (awakegame_generate_with_topic_check()),
 * para que el resultado quede anclado al tema real en vez de que la IA se lo
 * invente a partir de instrucciones sueltas del profesor.
 *
 * $sectionnum/$courseid: ver awakegame_generate_from_prompt(), mismo motivo
 * (al CREAR la actividad, course_modules.section todavía no está resuelto).
 */
function awakegame_adapt_marketplace_entry(
    context_module $context,
    $instanceid,
    $marketplacegameid,
    $adaptinstructions,
    $sectionnum = null,
    $courseid = null
) {
    global $DB;

    $game = awakegame_marketplace_get_game($marketplacegameid);
    if (!$game) {
        return; // El juego ya no existe en el Marketplace, o no se pudo contactar; no se rompe el guardado.
    }

    $templatehtml = awakegame_strip_score_bridge($game['html']);

    if ($sectionnum !== null && $courseid !== null) {
        $section = awakegame_get_section_row_by_number($courseid, $sectionnum);
    } else {
        $section = awakegame_get_section_row($context->instanceid);
    }

    $basesectioncontext = $section ? awakegame_build_section_context_text($section, $context->instanceid) : '';
    $fallbacktopiclabel = ($section && trim((string) $section->name) !== '') ? format_string($section->name) : '';
    $pdf = $section ? awakegame_find_section_pdf($context->instanceid, $section) : null;

    $prompt = "Quiero un juego con EXACTAMENTE la misma mecánica, reglas y aspecto visual que el " .
        "siguiente juego de ejemplo, pero con el contenido (términos, preguntas, datos concretos) " .
        "sustituido por el tema real indicado en el \"Contexto de la sección del curso\" que se te da " .
        "por separado (o en el PDF que incluya). TIENES PROHIBIDO reutilizar los términos/datos " .
        "concretos del ejemplo — son solo una plantilla de mecánica y estructura, no de contenido; " .
        "el contenido tiene que salir del contexto real de la sección, nunca del ejemplo.\n\n" .
        "--- JUEGO DE EJEMPLO (cópiate su mecánica/estructura/aspecto; ignora sus términos/datos) ---\n" .
        $templatehtml . "\n--- FIN DEL EJEMPLO ---";

    if (trim($adaptinstructions) !== '') {
        $prompt .= "\n\nInstrucciones adicionales del profesor sobre esta actividad concreta:\n" . $adaptinstructions;
    }

    $result = awakegame_generate_with_topic_check($prompt, $basesectioncontext, $fallbacktopiclabel, $pdf);
    $html = awakegame_inject_score_bridge($result['html'], $instanceid);

    awakegame_save_content($context, $html);
    $DB->set_field('awakegame', 'revision', time(), ['id' => $instanceid]);

    if (!$result['verified'] && $result['topiclabel'] !== '') {
        \core\notification::add(
            get_string('aitopicunverified', 'mod_awakegame', $result['topiclabel']),
            \core\output\notification::NOTIFY_WARNING
        );
    }
}

/**
 * Cliente HTTP compartido para hablar con la API del Marketplace, autenticada
 * con la clave de API de este colegio (ajustes del plugin). A diferencia de
 * awakegame_anthropic_request(), esto no es una llamada a un modelo de IA:
 * es un JSON pequeño de ida y vuelta, así que un timeout corto es apropiado y
 * no hace falta razonamiento extendido ni reintentos internos — los
 * reintentos ante fallo los gestiona la propia cola de tareas de Moodle (ver
 * classes/task/publish_to_marketplace.php).
 *
 * @param string $method 'GET', 'POST' o 'PUT'
 * @param array $query parámetros de query string (por ejemplo ['id' => 123])
 * @param array|null $payload cuerpo JSON a enviar (POST/PUT)
 * @return array respuesta ya decodificada
 */
function awakegame_marketplace_request(string $method, array $query = [], ?array $payload = null): array {
    global $CFG;
    require_once($CFG->libdir . '/filelib.php'); // Ahí vive \curl; no siempre está cargada ya según el contexto.

    $baseurl = trim((string) get_config('mod_awakegame', 'marketplaceurl'));
    $apikey = trim((string) get_config('mod_awakegame', 'marketplaceapikey'));

    if ($baseurl === '' || $apikey === '') {
        throw new moodle_exception('nomarketplaceconfig', 'mod_awakegame');
    }

    $url = $baseurl;
    if (!empty($query)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    }

    $curl = new \curl();
    $curl->setHeader('X-API-Key: ' . $apikey);
    $curl->setHeader('Content-Type: application/json');

    $options = ['CURLOPT_TIMEOUT' => 15];

    if ($method === 'POST') {
        $response = $curl->post($url, json_encode($payload ?? []), $options);
    } else if ($method === 'PUT') {
        // Se usa post() forzando el verbo HTTP con CURLOPT_CUSTOMREQUEST en vez
        // de curl::put() (pensado en Moodle sobre todo para subir ficheros vía
        // WebDAV): así el cuerpo JSON se envía exactamente igual que en un
        // POST normal, solo cambia la línea de petición HTTP a PUT.
        $options['CURLOPT_CUSTOMREQUEST'] = 'PUT';
        $response = $curl->post($url, json_encode($payload ?? []), $options);
    } else {
        $response = $curl->get($url, [], $options);
    }

    $httpcode = $curl->info['http_code'] ?? 0;
    $decoded = json_decode((string) $response, true);

    if ($httpcode < 200 || $httpcode >= 300 || !is_array($decoded)) {
        $message = $decoded['error'] ?? ('HTTP ' . $httpcode);
        throw new moodle_exception('marketplacerequestfailed', 'mod_awakegame', '', $message);
    }

    return $decoded;
}

/**
 * Encola la publicación o actualización de un juego en el Marketplace como
 * tarea ad-hoc, para no bloquear nunca la petición que guarda el formulario
 * con una llamada de red externa (la misma razón por la que la generación
 * con IA se mueve a background: ver classes/task/generate_content.php).
 *
 * @param string $mode 'publish' (primera publicación) o 'update' (botón manual)
 */
function awakegame_queue_marketplace_task($instanceid, string $mode): void {
    global $DB;

    $task = new \mod_awakegame\task\publish_to_marketplace();
    $task->set_custom_data(['instanceid' => $instanceid, 'mode' => $mode]);
    \core\task\manager::queue_adhoc_task($task);

    $DB->set_field('awakegame', 'marketplacestatus', 'pending', ['id' => $instanceid]);
}

/**
 * Listado ligero de juegos publicados, para el selector "Usar del
 * Marketplace" de mod_form.php. Si el Marketplace no responde (apagado, sin
 * configurar, caído en ese momento), se devuelve una lista vacía en vez de
 * romper el formulario — igual de tolerante que awakegame_find_section_pdf().
 */
function awakegame_marketplace_list_games(): array {
    try {
        $result = awakegame_marketplace_request('GET');
        return $result['games'] ?? [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Detalle completo (incluye el HTML) de un juego del Marketplace, usado para
 * clonarlo dentro de una actividad (ver awakegame_use_marketplace_entry()).
 */
function awakegame_marketplace_get_game($marketplacegameid): ?array {
    try {
        $result = awakegame_marketplace_request('GET', ['id' => $marketplacegameid]);
        return $result['game'] ?? null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Publica por primera vez el juego actual de esta actividad en el
 * Marketplace. Llamado únicamente desde la tarea ad-hoc (nunca de forma
 * síncrona al guardar el formulario) — ver classes/task/publish_to_marketplace.php.
 */
function awakegame_marketplace_publish($awakegame): void {
    global $DB;

    $context = context_module::instance(
        get_coursemodule_from_instance('awakegame', $awakegame->id, $awakegame->course)->id
    );
    $fs = get_file_storage();
    $indexfile = $fs->get_file($context->id, 'mod_awakegame', 'content', 0, '/', 'index.html');

    if (!$indexfile) {
        throw new moodle_exception('noindexfile', 'mod_awakegame');
    }

    $result = awakegame_marketplace_request('POST', [], [
        'title'               => $awakegame->name,
        'prompt'              => $awakegame->aiprompt,
        'html'                => $indexfile->get_content(),
        'source_instance_id'  => $awakegame->id,
    ]);

    $DB->update_record('awakegame', (object) [
        'id'                => $awakegame->id,
        'marketplaceid'     => (string) $result['id'],
        'marketplacestatus' => 'published',
    ]);
}

/**
 * Actualiza en el Marketplace el juego ya publicado de esta actividad, con
 * su contenido actual. Llamado únicamente desde la tarea ad-hoc, disparada
 * por el botón manual "Actualizar en el Marketplace" (nunca automáticamente
 * al guardar: la actualización es siempre una acción explícita del profesor).
 */
function awakegame_marketplace_update($awakegame): void {
    global $DB;

    $context = context_module::instance(
        get_coursemodule_from_instance('awakegame', $awakegame->id, $awakegame->course)->id
    );
    $fs = get_file_storage();
    $indexfile = $fs->get_file($context->id, 'mod_awakegame', 'content', 0, '/', 'index.html');

    if (!$indexfile) {
        throw new moodle_exception('noindexfile', 'mod_awakegame');
    }

    awakegame_marketplace_request('PUT', ['id' => $awakegame->marketplaceid], [
        'title' => $awakegame->name,
        'html'  => $indexfile->get_content(),
    ]);

    $DB->set_field('awakegame', 'marketplacestatus', 'published', ['id' => $awakegame->id]);
}

/**
 * Inserta en el HTML del juego un pequeño script que define
 * window.reportAwakegameScore(score, maxscore). Se hace desde el servidor
 * (no se confía en que la IA escriba el postMessage correctamente) para que
 * la IA solo tenga que llamar a esa función cuando el juego termine.
 *
 * Va envuelto en comentarios marcadores para poder quitarlo limpiamente con
 * awakegame_strip_score_bridge() antes de reenviar el HTML actual a la IA en
 * una mejora incremental (así no ve ni duplica nuestro propio código interno).
 */
function awakegame_inject_score_bridge(string $html, int $instanceid): string {
    $bridge = "\n<!-- AWAKEGAME_PLATFORM_BRIDGE_START -->\n<script>\n" .
        "window.AWAKEGAME_INSTANCE_ID = " . (int) $instanceid . ";\n" .
        "window.reportAwakegameScore = function(score, maxscore) {\n" .
        "    try {\n" .
        "        window.parent.postMessage({\n" .
        "            type: 'awakegame-score',\n" .
        "            instanceid: window.AWAKEGAME_INSTANCE_ID,\n" .
        "            score: Number(score),\n" .
        "            maxscore: Number(maxscore)\n" .
        "        }, window.location.origin);\n" .
        "    } catch (e) { /* noop */ }\n" .
        "};\n" .
        "</script>\n<!-- AWAKEGAME_PLATFORM_BRIDGE_END -->\n";

    if (stripos($html, '</body>') !== false) {
        return preg_replace('/<\/body>/i', $bridge . '</body>', $html, 1);
    }

    return $html . $bridge;
}

/**
 * Quita el bloque insertado por awakegame_inject_score_bridge(), identificado
 * por sus comentarios marcadores.
 */
function awakegame_strip_score_bridge(string $html): string {
    return preg_replace(
        '/<!-- AWAKEGAME_PLATFORM_BRIDGE_START -->.*?<!-- AWAKEGAME_PLATFORM_BRIDGE_END -->/s',
        '',
        $html
    );
}

/**
 * Instrucciones comunes sobre cómo debe comportarse y puntuar el juego,
 * compartidas entre la generación desde cero y las mejoras incrementales.
 */
function awakegame_scoring_rules(): string {
    return "Cuando el juego termine (el jugador gane, pierda, o complete todas las preguntas/intentos " .
        "posibles), debes llamar EXACTAMENTE UNA VEZ a la función JavaScript global " .
        "window.reportAwakegameScore(puntuacionObtenida, puntuacionMaxima), pasándole dos números: " .
        "la puntuación final obtenida por el jugador y la puntuación máxima posible en ese juego. " .
        "Esa función ya está definida por la plataforma (Moodle); no la definas tú ni la " .
        "sobrescribas, limítate a llamarla una vez. " .
        "MUY IMPORTANTE sobre el cálculo de la puntuación: puntuacionObtenida debe reflejar " .
        "ÚNICAMENTE lo que el jugador acertó de verdad, comprobado por tu propio código (por " .
        "ejemplo, comparando la respuesta del jugador contra la respuesta correcta antes de sumar " .
        "puntos), y NUNCA debe ser igual a puntuacionMaxima por defecto ni por simplicidad. Lleva " .
        "un contador que solo aumente cuando compruebes explícitamente que una respuesta/acción es " .
        "correcta. Si el jugador se rinde, revela la solución, agota el tiempo, o termina sin " .
        "completar todo correctamente, puntuacionObtenida debe ser inferior a puntuacionMaxima y " .
        "reflejar solo los aciertos reales conseguidos hasta ese momento. No marques como acierto " .
        "una casilla/pregunta que fue rellenada automáticamente, revelada, o no fue respondida por " .
        "el jugador.";
}

/**
 * Llama a la API de Mensajes de Claude (Anthropic) pidiéndole un juego HTML5
 * autocontenido nuevo (un solo archivo, con CSS/JS inline) a partir del
 * prompt original del profesor, y devuelve el HTML resultante ya limpio.
 */
function awakegame_call_anthropic(string $prompt, string $sectioncontext = ''): string {
    $temarule = "REGLA #1, LA MÁS IMPORTANTE DE TODAS: el TEMA del juego (las preguntas, palabras, " .
        "elementos a clasificar, datos, etc.) tiene que salir SIEMPRE del \"Contexto de la sección del " .
        "curso\" que se te dé en el mensaje del usuario (que puede incluir un resumen del contenido " .
        "real de un PDF de esa sección), EXCEPTO si la petición del profesor menciona explícitamente " .
        "un tema o materia distinto (en ese caso, el de la petición del profesor gana). Si ese " .
        "contexto incluye un resumen de un PDF, es la fuente de verdad principal: usa esos términos y " .
        "datos concretos, con más peso que el simple nombre de la sección. " .
        "TIENES PROHIBIDO sustituir el tema real (el del PDF o la sección) por un tema genérico " .
        "inventado por ti, aunque te resulte más familiar, más \"típico\" para la mecánica de juego " .
        "pedida, o más habitual como contenido de ese número de tema/unidad en un currículo escolar " .
        "real. En concreto: el nombre o número de la sección (por ejemplo \"Tema 10\") NO es una pista " .
        "de qué suele tratar habitualmente un tema con ese número en un libro de texto genérico — es " .
        "solo una etiqueta puesta por el profesor; el ÚNICO contenido real es el que te llega en el " .
        "\"Contexto de la sección del curso\", nunca lo que \"normalmente\" toca en ese punto del curso. " .
        "Ejemplos de temas genéricos a los que NO debes recurrir por costumbre cuando ya se te ha dado " .
        "un tema real: la célula, energías renovables y no renovables, ecosistemas en general, el " .
        "sistema solar, capitales de países, tablas de multiplicar, y muy especialmente **la " .
        "comunicación y sus elementos** (emisor, receptor, mensaje, canal, código, contexto, ruido, " .
        "retroalimentación) — este último es un tema al que recurres con mucha frecuencia por defecto " .
        "aunque no tenga nada que ver con el contexto real proporcionado. Esta lista es solo orientativa " .
        "(no exhaustiva): la regla de fondo es que CUALQUIER tema que no esté literalmente respaldado " .
        "por el contexto proporcionado está prohibido, se parezca o no a los ejemplos de esta lista. " .
        "Usar uno de estos temas (u otro inventado) en vez del tema real proporcionado es un fallo " .
        "grave. " .
        "Ejemplo concreto: si el contexto de sección trata sobre volcanes y el profesor solo pide " .
        "\"un juego de unir\" o \"un juego de arrastrar y soltar\", los elementos a unir/arrastrar " .
        "tienen que ser términos y datos sobre volcanes (tipos de volcán, partes de un volcán, magma, " .
        "lava, erupciones, etc.), nunca sobre otro tema relacionado pero distinto.";

    $fidelidadrule = "REGLA #2, TAMBIÉN OBLIGATORIA: cuando el \"Contexto de la sección del curso\" " .
        "incluya términos, datos, fechas o definiciones concretos (por ejemplo, extraídos de un PDF), " .
        "todos los términos/datos/preguntas que uses en el juego DEBEN salir literalmente de ahí. " .
        "TIENES PROHIBIDO añadir, completar o \"rellenar\" con datos de tu propio conocimiento " .
        "general sobre el tema, aunque sean ciertos y parezcan relevantes — si un dato no aparece en " .
        "el contexto proporcionado, no lo incluyas. Por ejemplo, si el contexto menciona 10 datos " .
        "concretos sobre un tema, el juego debe basarse en esos mismos 10 datos (o un subconjunto de " .
        "ellos); no debes añadir un dato número 11 que sepas por tu cuenta aunque sea verídico y " .
        "relacionado. Si necesitas más elementos de los que el contexto proporciona para completar " .
        "la mecánica pedida, usa los que haya (repitiendo, agrupando o reduciendo la cantidad) en vez " .
        "de inventar contenido adicional. Esta fidelidad al material proporcionado es tan importante " .
        "como acertar el tema.";

    $systemprompt = $temarule . " " . $fidelidadrule . " " .
        "Eres un generador de mini-juegos educativos HTML5 para insertar en Moodle. " .
        "A partir de la descripción del profesor, genera UN ÚNICO archivo HTML autocontenido: " .
        "sin dependencias externas, sin CDNs, sin fuentes web, sin peticiones de red de ningún tipo. " .
        "Todo el CSS debe ir dentro de una etiqueta <style> y todo el JavaScript dentro de una " .
        "etiqueta <script>, ambas inline en el mismo archivo. El juego debe funcionar completamente " .
        "offline, dentro de un iframe, y debe ser jugable con teclado y con ratón/táctil cuando " .
        "tenga sentido. " .
        "Si no hay contexto de sección ni tema especificado por el profesor, entonces sí puedes usar " .
        "tu propio criterio para un tema genérico y educativo razonable. " .
        awakegame_scoring_rules() . " " .
        "Implementa la lógica de puntuación siguiendo exactamente lo que pida el profesor en su " .
        "descripción (por ejemplo, cuántos puntos suma cada acierto), pero la regla de \"solo contar " .
        "aciertos reales y verificados\" tiene prioridad sobre cualquier otra cosa. " .
        "Responde ÚNICAMENTE con el código HTML completo, empezando literalmente por " .
        "<!DOCTYPE html>, sin explicaciones antes o después, y sin bloques de markdown (nada de " .
        "```). No incluyas etiquetas internas o de sistema en la respuesta.";

    $usertext = '';
    if (trim($sectioncontext) !== '') {
        $usertext .= "Contexto de la sección del curso donde se insertará este juego (úsalo como " .
            "tema obligatorio del juego salvo que la petición de abajo indique explícitamente otro " .
            "tema distinto):\n" . $sectioncontext . "\n\n---\n\n";
    }
    $usertext .= "Petición del profesor sobre el juego a crear:\n" . $prompt;

    return awakegame_anthropic_request($systemprompt, $usertext);
}

/**
 * Construye el "content" del mensaje de usuario para la API de Claude,
 * adjuntando un PDF (si se proporciona) como bloque de documento ANTES del
 * texto, tal como recomienda la API. Si no hay PDF, devuelve el texto tal
 * cual (más simple/legible en los logs de la API).
 *
 * @param string $text
 * @param \stored_file|null $pdf
 * @return string|array
 */
function awakegame_build_user_content(string $text, $pdf = null) {
    if (!$pdf) {
        return $text;
    }

    return [
        [
            'type'   => 'document',
            'source' => [
                'type'       => 'base64',
                'media_type' => 'application/pdf',
                'data'       => base64_encode($pdf->get_content()),
            ],
        ],
        [
            'type' => 'text',
            'text' => $text,
        ],
    ];
}

/**
 * Llama a la API de Claude para aplicar una mejora incremental sobre un
 * juego ya existente: le pasa el HTML actual completo (sin nuestro puente de
 * puntuación) y el cambio pedido, y le pide que devuelva el juego completo
 * actualizado conservando todo lo demás igual.
 */
function awakegame_call_anthropic_improvement(string $originalprompt, string $currenthtml, string $improvement): string {
    $systemprompt = "Eres un editor de mini-juegos educativos HTML5 para Moodle. Se te va a mostrar el " .
        "HTML COMPLETO de un juego que ya existe y funciona, junto con un cambio o mejora que pide " .
        "el profesor. Tu tarea es devolver el HTML COMPLETO actualizado aplicando ÚNICAMENTE ese " .
        "cambio, preservando exactamente el resto del juego (mecánica, aspecto visual, estructura, " .
        "lógica de puntuación existente) salvo que el cambio pedido lo requiera explícitamente. NO " .
        "reescribas el juego desde cero, NO cambies cosas que no se han pedido, y NO simplifiques ni " .
        "elimines funcionalidad existente que siga siendo válida. El resultado debe seguir siendo un " .
        "único archivo HTML autocontenido: sin dependencias externas, sin CDNs, sin peticiones de " .
        "red, con todo el CSS en <style> y todo el JS en <script>, ambos inline. " .
        awakegame_scoring_rules() . " Si el cambio pedido afecta a la lógica de puntuación, ajústala " .
        "en consecuencia; si no, mantenla exactamente igual que estaba. " .
        "Responde ÚNICAMENTE con el código HTML completo actualizado, empezando literalmente por " .
        "<!DOCTYPE html>, sin explicaciones antes o después, y sin bloques de markdown (nada de " .
        "```). No incluyas etiquetas internas o de sistema en la respuesta.";

    $usermessage = "Descripción original del juego (solo para contexto; no la repitas ni la cambies " .
        "por tu cuenta):\n" . $originalprompt . "\n\n" .
        "HTML actual completo del juego:\n\n" . $currenthtml . "\n\n" .
        "Cambio o mejora que pide ahora el profesor:\n" . $improvement;

    return awakegame_anthropic_request($systemprompt, $usermessage);
}

/**
 * Petición HTTP compartida a la API de Mensajes de Claude (Anthropic).
 *
 * Se usa el cliente \curl propio de Moodle (HTTP directo) en vez del SDK
 * oficial de PHP de Anthropic para no requerir un paso de "composer install"
 * al instalar este plugin de prueba, que hasta ahora solo requiere copiar
 * la carpeta dentro de mod/.
 *
 * @param string $systemprompt
 * @param string|array $usercontent texto simple, o array de bloques de
 *        contenido (por ejemplo, [documento PDF, bloque de texto]).
 */
function awakegame_anthropic_request(
    string $systemprompt,
    $usercontent,
    int $maxtokens = 16000,
    string $thinkingtype = 'adaptive'
): string {
    global $CFG;
    require_once($CFG->libdir . '/filelib.php'); // Ahí vive \curl; no siempre está cargada ya según el contexto.

    $apikey = trim((string) get_config('mod_awakegame', 'anthropicapikey'));
    if ($apikey === '') {
        throw new moodle_exception('noapikey', 'mod_awakegame');
    }

    $payload = [
        'model'      => 'claude-opus-5',
        'max_tokens' => $maxtokens,
        'thinking'   => ['type' => $thinkingtype],
        'system'     => $systemprompt,
        'messages'   => [
            ['role' => 'user', 'content' => $usercontent],
        ],
    ];

    // Evita que un límite corto de max_execution_time del PHP del servidor
    // corte la petición mientras se espera la respuesta de la API (el
    // razonamiento "thinking", y adjuntar un PDF, añaden latencia extra).
    set_time_limit(260);

    // La API de Anthropic devuelve a veces errores puramente transitorios
    // (529 "Overloaded" cuando sus servidores están saturados, 429 si se
    // supera el límite de ritmo, o 500/502/503 de fallos puntuales de red).
    // Ninguno de estos significa que el prompt o el PDF estén mal: con solo
    // esperar un poco y reintentar suele bastar. Sin este reintento, el
    // profesor se encontraba con el error y tenía que darle a "Guardar" otra
    // vez él mismo para conseguir lo mismo.
    $maxretries = 3;
    $retrydelays = [3, 8, 15]; // segundos de espera antes de cada reintento.
    $httpcode = 0;
    $decoded = null;
    $response = null;

    for ($attempt = 1; $attempt <= $maxretries; $attempt++) {
        $curl = new \curl();
        $curl->setHeader('x-api-key: ' . $apikey);
        $curl->setHeader('anthropic-version: 2023-06-01');
        $curl->setHeader('content-type: application/json');

        $response = $curl->post('https://api.anthropic.com/v1/messages', json_encode($payload), [
            'CURLOPT_TIMEOUT' => 240,
        ]);

        $httpcode = $curl->info['http_code'] ?? 0;
        $decoded = json_decode((string) $response, true);

        if ($httpcode === 200 && is_array($decoded)) {
            break;
        }

        $istransient = in_array($httpcode, [429, 500, 502, 503, 529], true) || $httpcode === 0;
        if (!$istransient || $attempt === $maxretries) {
            $message = $decoded['error']['message'] ?? $response;
            throw new moodle_exception('aigenerationfailed', 'mod_awakegame', '', $message);
        }

        sleep($retrydelays[$attempt - 1] ?? 10);
    }

    if (($decoded['stop_reason'] ?? null) === 'refusal') {
        throw new moodle_exception('aigenerationrefused', 'mod_awakegame');
    }

    $html = '';
    foreach ($decoded['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $html .= $block['text'];
        }
    }

    $html = awakegame_clean_ai_html($html);

    if ($html === '') {
        throw new moodle_exception('aigenerationempty', 'mod_awakegame');
    }

    return $html;
}

/**
 * Quita posibles bloques de markdown (```html ... ```) que el modelo
 * pudiera añadir por error alrededor del HTML.
 */
function awakegame_clean_ai_html(string $html): string {
    $html = trim($html);
    $html = preg_replace('/^```(?:html)?\s*/i', '', $html);
    $html = preg_replace('/```\s*$/', '', $html);
    return trim($html);
}

/**
 * Sirve los archivos del juego (package/content) a través de pluginfile.php.
 */
function awakegame_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_course_login($course, true, $cm);

    if (!has_capability('mod/awakegame:view', $context)) {
        return false;
    }

    if (!in_array($filearea, ['package', 'content'], true)) {
        return false;
    }

    $itemid   = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_awakegame', $filearea, $itemid, $filepath, $filename);

    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, null, 0, $forcedownload, $options);
}
