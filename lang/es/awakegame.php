<?php
defined('MOODLE_INTERNAL') || die();

$string['modulename']            = 'Juego Awakelab';
$string['modulenameplural']      = 'Juegos Awakelab';
$string['modulename_help']       = 'Sube un paquete de juego autocontenido en HTML/CSS/JS (como archivo .zip) y juégalo directamente dentro del curso, integrado en la página.';
$string['pluginname']            = 'Juego Awakelab';
$string['pluginadministration']  = 'Administración de Juego Awakelab';
$string['awakegamename']         = 'Nombre de la actividad';
$string['contentheader']         = 'Paquete del juego';
$string['packagefile']           = 'Paquete del juego (.zip)';
$string['packagefile_help']      = 'Sube un archivo ZIP con tu juego. Debe contener un archivo index.html en la raíz del ZIP, además de cualquier archivo CSS/JS/recurso que necesite. Al subir un ZIP nuevo se reemplaza el anterior.';
$string['noindexfile']           = 'No se encontró un archivo index.html para este juego. Si subiste un ZIP, asegúrate de que index.html esté en la raíz (no dentro de una subcarpeta). Si lo generaste con IA, revisa que el prompt no esté vacío y que la clave de la API esté configurada.';
$string['noinstances']           = 'Todavía no hay actividades de Juego Awakelab en este curso.';
$string['awakegame:addinstance'] = 'Añadir una nueva actividad de Juego Awakelab';
$string['awakegame:view']        = 'Jugar una actividad de Juego Awakelab';
$string['privacy:metadata']      = 'El plugin Juego Awakelab no almacena ningún dato personal.';

$string['contentsource']         = 'Origen del contenido';
$string['contentsource_help']    = 'Elige si quieres subir tú mismo un archivo .zip con el juego, o describir el juego que quieres y que se genere automáticamente con inteligencia artificial.';
$string['contentsource_upload']  = 'Subir archivo (.zip)';
$string['contentsource_ai']      = 'Generar con IA (a partir de un prompt)';
$string['aiprompt']              = 'Descripción del juego (prompt)';
$string['aiprompt_help']         = 'Describe con el mayor detalle posible el juego que quieres: mecánica, objetivo, número de niveles/preguntas, apariencia, etc. La IA generará un juego HTML jugable a partir de esta descripción. Si no indicas de qué tema/materia debe tratar el juego (por ejemplo, solo describes "un juego de preguntas" sin decir sobre qué), la IA usará automáticamente el nombre y la descripción de la sección del curso donde estás creando la actividad para deducir el tema.';
$string['airegenerate']          = 'Regenerar desde cero al guardar';
$string['airegenerate_help']     = 'Actívalo si quieres descartar el juego actual (incluidas todas las mejoras que le hayas aplicado) y generarlo de nuevo desde el prompt original. Úsalo, por ejemplo, si el plugin se actualizó y quieres que tu juego aproveche las novedades desde cero. Para pedir cambios puntuales sin perder lo que ya tienes, usa mejor el campo "Mejoras" de abajo.';
$string['aiimprovement']         = 'Mejoras / cambios sobre el juego actual';
$string['aiimprovement_help']    = 'Describe aquí un cambio o mejora concreta que quieras pedir sobre el juego TAL Y COMO ESTÁ AHORA (por ejemplo: "añade un temporizador de 60 segundos", "cambia el color de fondo a azul", "añade 5 preguntas más sobre el sistema solar"). Al guardar, la IA parte del juego actual y le aplica solo ese cambio, sin rehacerlo desde el prompt original. Después de aplicarse, este campo se vacía automáticamente para que puedas pedir la siguiente mejora más adelante.';
$string['aipromptlocked']        = 'Este es el prompt original con el que se creó el juego. No se puede modificar para evitar que el juego se regenere por accidente. Para pedir cambios, usa el campo "Mejoras" de abajo (o marca "Regenerar desde cero" si quieres empezar de nuevo con este mismo prompt).';
$string['aiheading']             = 'Generación con IA';
$string['aiheading_desc']        = 'Configuración necesaria para que los profesores puedan generar juegos describiéndolos con un prompt, usando la API de Claude (Anthropic).';
$string['anthropicapikey']       = 'Clave de API de Anthropic (Claude)';
$string['anthropicapikey_desc']  = 'Clave de API de Anthropic usada para generar juegos a partir de un prompt. Se guarda solo en el servidor y nunca se muestra a los usuarios. Puedes crear una en <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a>. Si se deja vacía, la opción "Generar con IA" no funcionará.';
$string['noapikey']              = 'No se ha configurado la clave de la API de Anthropic. Un administrador debe añadirla en Administración del sitio → Complementos → Módulos de actividad → Juego Awakelab.';
$string['aigenerationfailed']    = 'No se pudo generar el juego con IA: {$a}';
$string['aigenerationrefused']   = 'La IA rechazó generar este juego (puede que el prompt toque contenido no permitido). Prueba a reformular la descripción.';
$string['aigenerationempty']     = 'La IA no devolvió contenido para el juego. Inténtalo de nuevo o reformula el prompt.';
$string['aitopicunverified']     = 'No se ha podido confirmar automáticamente que el juego generado trate sobre el tema esperado ("{$a}"). Revisa el contenido y, si no es correcto, marca "Regenerar desde cero" y guarda de nuevo.';

$string['gradenotice']           = '<strong>Aviso:</strong> la puntuación la reporta el propio juego, que se ejecuta como JavaScript en el navegador del alumno. No hay validación del lado del servidor, así que un alumno con conocimientos técnicos podría manipular la puntuación reportada. Úsalo para práctica/gamificación, no como única fuente de una nota que deba ser a prueba de manipulación.';

$string['contentsource_library'] = 'Elegir de la biblioteca';
$string['libraryentry']          = 'Juego de la biblioteca';
$string['libraryentry_help']     = 'Elige un juego ya guardado por ti o por otro profesor del centro. Se copiará tal cual como contenido de esta actividad (no se genera nada nuevo con IA, así que es instantáneo y no tiene coste).';
$string['librarychoose']         = 'Selecciona un juego...';
$string['libraryusedcount']      = 'usado {$a} veces';
$string['savetolibrary']         = 'Guardar este juego en la biblioteca';
$string['savedtolibrary']        = 'Juego guardado en la biblioteca. Ya está disponible para cualquier profesor del centro.';
