<?php
/**
 * Ventana emergente para elegir un juego del Marketplace desde el formulario
 * de la actividad (mod_form.php), sin cargar de golpe un <select> con todos
 * los juegos publicados (no escala si el catálogo crece mucho). Al elegir
 * uno, se le avisa a la ventana que la abrió con postMessage y se cierra
 * sola — mismo concepto que el selector de contenido del conector LTI, aquí
 * integrado directamente en el propio formulario del plugin.
 *
 * Estilo visual calcado del propio catálogo del Marketplace (Marketplace/
 * public/catalog.php y src/layout.php), en vez del tema de Moodle: al fin y
 * al cabo esta ventana ES una vista del Marketplace, no una pantalla más de
 * Moodle, así que se renderiza HTML propio en vez de usar $OUTPUT->header().
 */
require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$courseid = required_param('course', PARAM_INT);
$search = optional_param('q', '', PARAM_TEXT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);
$context = context_course::instance($courseid);
require_capability('mod/awakegame:addinstance', $context);

$games = awakegame_marketplace_list_games($search);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title><?php echo get_string('marketplacepickertitle', 'mod_awakegame'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --cian-claro: #D9FBFF; --cian: #19F7F1; --cian-fuerte: #0FCED3; --cian-oscuro: #0B93AA;
    --azul-claro: #F0F3FC; --azul-claro2: #E2E6F2; --azul-medio2: #34547A;
    --azul-oscuro: #01264C; --azul-oscuro2: #011932;
    --bg: #F7F9FD; --superficie: #FFFFFF; --borde: #DEE4F0;
    --texto: var(--azul-oscuro2); --texto-suave: var(--azul-medio2);
    --sombra-suave: 0 2px 10px rgba(52, 84, 122, 0.08); --radio: 14px;
}
* { box-sizing: border-box; }
body { margin: 0; font-family: 'Poppins', sans-serif; background: var(--bg); color: var(--texto); padding: 24px; }
h1 { font-size: 20px; font-weight: 700; margin: 0 0 4px; color: var(--azul-oscuro2); }
p.lead { color: var(--texto-suave); font-size: 13.5px; margin: 0 0 18px; }
form.search { display: flex; gap: 10px; margin-bottom: 20px; }
form.search input { flex: 1; padding: 10px 14px; border-radius: 9px; border: 1px solid var(--borde); background: var(--azul-claro); color: var(--texto); font-family: inherit; font-size: 14px; }
form.search input:focus { outline: none; border-color: var(--cian-fuerte); background: #fff; }
button, .btn { display: inline-flex; align-items: center; gap: 6px; background: linear-gradient(135deg, var(--cian) 0%, var(--cian-fuerte) 100%); color: var(--azul-oscuro2); border: none; padding: 9px 18px; border-radius: 9px; font-family: inherit; font-weight: 600; font-size: 13.5px; cursor: pointer; }
button:hover { filter: brightness(1.05); }
.game-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }
.game-card { background: var(--superficie); border: 1px solid var(--borde); border-radius: var(--radio); overflow: hidden; box-shadow: var(--sombra-suave); display: flex; flex-direction: column; }
.game-card .thumb { height: 72px; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, var(--cian) 0%, var(--cian-oscuro) 100%); }
.game-card .thumb svg { width: 28px; height: 28px; opacity: .9; }
.game-card .body { padding: 12px 14px 14px; display: flex; flex-direction: column; gap: 8px; flex: 1; }
.game-card h3 { font-size: 13.5px; margin: 0; line-height: 1.35; color: var(--azul-oscuro2); }
.pill { display: inline-flex; background: var(--azul-claro2); color: var(--texto-suave); border-radius: 999px; padding: 2px 10px; font-size: 11px; font-weight: 600; }
.spacer { flex: 1; }
.empty-state { text-align: center; padding: 40px 20px; color: var(--texto-suave); }
.empty-state .icon { font-size: 34px; margin-bottom: 10px; opacity: .6; }
</style>
</head>
<body>
<h1><?php echo get_string('marketplacepickertitle', 'mod_awakegame'); ?></h1>
<p class="lead">Marketplace de Awakelab</p>

<form class="search" method="get" action="<?php echo (new moodle_url('/mod/awakegame/marketplace_picker.php'))->out(false); ?>">
    <input type="hidden" name="course" value="<?php echo $courseid; ?>">
    <input type="text" name="q" value="<?php echo s($search); ?>"
        placeholder="<?php echo s(get_string('marketplacepickersearchplaceholder', 'mod_awakegame')); ?>">
    <button type="submit"><?php echo get_string('search'); ?></button>
</form>

<?php if (empty($games)) { ?>
    <div class="empty-state">
        <div class="icon">&#128218;</div>
        <p><?php echo get_string('marketplaceunavailable', 'mod_awakegame'); ?></p>
    </div>
<?php } else { ?>
    <div class="game-grid">
        <?php foreach ($games as $game) {
            $title = $game['title'] ?? '';
            $school = $game['school_name'] ?? '';
            $jsargs = json_encode(['type' => 'awakegame-marketplace-pick', 'id' => (int) $game['id'], 'title' => $title]);
        ?>
            <div class="game-card">
                <div class="thumb">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9.5 3.5a1.75 1.75 0 1 1 3.5 0V5h2.25A1.25 1.25 0 0 1 16.5 6.25V8.5h1.25a1.75 1.75 0 1 1 0 3.5H16.5v2.25a1.25 1.25 0 0 1-1.25 1.25H13v1.25a1.75 1.75 0 1 1-3.5 0V15.5H7.25A1.25 1.25 0 0 1 6 14.25V12H4.75a1.75 1.75 0 1 1 0-3.5H6V6.25A1.25 1.25 0 0 1 7.25 5H9.5V3.5Z"/>
                    </svg>
                </div>
                <div class="body">
                    <h3><?php echo s($title); ?></h3>
                    <div>
                        <span class="pill"><?php echo s($school); ?></span>
                        <span class="pill">&#9733; <?php echo isset($game['avg_rating']) && $game['avg_rating'] !== null ? round((float) $game['avg_rating'], 1) : '—'; ?></span>
                        <span class="pill">Usado <?php echo (int) ($game['times_used'] ?? 0); ?></span>
                    </div>
                    <div class="spacer"></div>
                    <button type="button" onclick="<?php echo htmlspecialchars('window.opener.postMessage(' . $jsargs . ', window.location.origin); window.close();', ENT_QUOTES); ?>">
                        <?php echo get_string('marketplacepickeruse', 'mod_awakegame'); ?>
                    </button>
                </div>
            </div>
        <?php } ?>
    </div>
<?php } ?>
</body>
</html>
