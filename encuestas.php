<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Encuestas Públicas
 * ============================================================
 * Archivo  : encuestas.php
 * Versión  : 2.0.0
 * ============================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$usuario = currentUser();
$ip      = getClientIp();

// ── Parámetros de entrada ─────────────────────────────────────
$slugEncuesta = trim($_GET['slug'] ?? '');
$catFiltro    = (int)($_GET['cat']  ?? 0);
$busq         = trim($_GET['q']    ?? '');
$pagina       = max(1, (int)($_GET['pag'] ?? 1));
$perPage      = 9;

// ── Helper: verificar voto del usuario/IP ────────────────────
function verificarVotoUsuario(int $encuestaId, ?int $usuarioId, string $ip): array
{
    if ($usuarioId) {
        $rows = db()->fetchAll(
            "SELECT ev.opcion_id, ev.fecha, ev.fecha_cambio
             FROM encuesta_votos ev
             WHERE ev.encuesta_id = ? AND ev.usuario_id = ?
             ORDER BY ev.opcion_id ASC",
            [$encuestaId, $usuarioId]
        );
    } else {
        $rows = db()->fetchAll(
            "SELECT ev.opcion_id, ev.fecha, ev.fecha_cambio
             FROM encuesta_votos ev
             WHERE ev.encuesta_id = ? AND ev.ip = ? AND ev.usuario_id IS NULL
             ORDER BY ev.opcion_id ASC",
            [$encuestaId, $ip]
        );
    }
    return [
        'votado'    => !empty($rows),
        'opciones'  => array_column($rows, 'opcion_id'),
        'fecha'     => $rows[0]['fecha'] ?? null,
        'cambiado'  => !empty($rows[0]['fecha_cambio']),
    ];
}

// ── Helper: obtener encuesta con opciones ────────────────────
function cargarEncuestaCompleta(int $id): ?array
{
    $e = db()->fetchOne(
        "SELECT e.*,
                u.nombre AS autor_nombre,
                u.avatar AS autor_avatar,
                c.nombre AS cat_nombre,
                c.color  AS cat_color,
                c.slug   AS cat_slug
         FROM encuestas e
         LEFT JOIN usuarios   u ON u.id = e.autor_id
         LEFT JOIN categorias c ON c.id = e.categoria_id
         WHERE e.id = ? LIMIT 1",
        [$id]
    );
    if (!$e) return null;
    $e['opciones'] = db()->fetchAll(
        "SELECT id, opcion, votos, orden
         FROM encuesta_opciones
         WHERE encuesta_id = ?
         ORDER BY orden ASC, id ASC",
        [$id]
    );
    return $e;
}

// ── Modo: detalle o listado ───────────────────────────────────
$modoDetalle   = ($slugEncuesta !== '');
$encuesta      = null;
$encuestas     = [];
$totalPaginas  = 1;
$yaVote        = false;
$misOpciones   = [];
$statsGlobales = [];
$encuestaDestacada   = null;
$categoriasDisponibles = [];
$relacionadas  = [];

if ($modoDetalle) {
    // ── Vista de encuesta individual ─────────────────────────
    $encuesta = db()->fetchOne(
        "SELECT e.*,
                u.nombre AS autor_nombre,
                u.avatar AS autor_avatar,
                c.nombre AS cat_nombre,
                c.color  AS cat_color,
                c.slug   AS cat_slug
         FROM encuestas e
         LEFT JOIN usuarios   u ON u.id = e.autor_id
         LEFT JOIN categorias c ON c.id = e.categoria_id
         WHERE e.slug = ? AND e.activa = 1 AND e.es_standalone = 1
         LIMIT 1",
        [$slugEncuesta]
    );

    if (!$encuesta) {
        header('Location: ' . APP_URL . '/encuestas.php?err=no_encontrada');
        exit;
    }

    $encuesta['opciones'] = db()->fetchAll(
        "SELECT id, opcion, votos, orden
         FROM encuesta_opciones
         WHERE encuesta_id = ?
         ORDER BY orden ASC, id ASC",
        [(int)$encuesta['id']]
    );

    $votoInfo   = verificarVotoUsuario((int)$encuesta['id'], $usuario ? (int)$usuario['id'] : null, $ip);
    $yaVote     = $votoInfo['votado'];
    $misOpciones = $votoInfo['opciones'];

    // ¿Puede cambiar voto?
    $puedeVotar = !$yaVote || (bool)$encuesta['puede_cambiar_voto'];

    // Encuesta cerrada?
    $cerrada = !$encuesta['activa']
        || (!empty($encuesta['fecha_cierre']) && $encuesta['fecha_cierre'] < date('Y-m-d H:i:s'));

    // Mostrar resultados?
    $mostrarResultados = match($encuesta['mostrar_resultados']) {
        'siempre'       => true,
        'despues_votar' => $yaVote || $cerrada,
        'nunca'         => $cerrada,
        default         => $yaVote,
    };

    // Actividad reciente (últimos 10 votos)
    $actividadReciente = db()->fetchAll(
        "SELECT ev.fecha, eo.opcion,
                COALESCE(u.nombre, 'Anónimo') AS votante
         FROM encuesta_votos ev
         INNER JOIN encuesta_opciones eo ON eo.id = ev.opcion_id
         LEFT JOIN  usuarios          u  ON u.id  = ev.usuario_id
         WHERE ev.encuesta_id = ?
         ORDER BY ev.fecha DESC
         LIMIT 10",
        [(int)$encuesta['id']]
    );

    // Distribución por día (últimos 14 días)
    $votasPorDia = db()->fetchAll(
        "SELECT DATE(fecha) AS dia, COUNT(*) AS total
         FROM encuesta_votos
         WHERE encuesta_id = ?
           AND fecha >= NOW() - INTERVAL 14 DAY
         GROUP BY DATE(fecha)
         ORDER BY dia ASC",
        [(int)$encuesta['id']]
    );

    // Encuestas relacionadas
    $relacionadas = db()->fetchAll(
        "SELECT e.id, e.pregunta, e.slug, e.total_votos, e.color,
                (SELECT COUNT(*) FROM encuesta_opciones eo WHERE eo.encuesta_id = e.id) AS num_opc
         FROM encuestas e
         WHERE e.activa = 1 AND e.es_standalone = 1
           AND e.id != ?
         ORDER BY RAND()
         LIMIT 3",
        [(int)$encuesta['id']]
    );

    $pageTitle = e($encuesta['pregunta']) . ' — Encuesta — ' . Config::get('site_nombre', APP_NAME);

} else {
    // ── Vista de listado ──────────────────────────────────────
    $where  = ['e.activa = 1', 'e.es_standalone = 1'];
    $params = [];

    if ($catFiltro > 0) {
        $where[]  = 'e.categoria_id = ?';
        $params[] = $catFiltro;
    }
    if ($busq !== '') {
        $where[]  = '(e.pregunta LIKE ? OR e.descripcion LIKE ?)';
        $params[] = "%$busq%";
        $params[] = "%$busq%";
    }

    $whereSQL      = implode(' AND ', $where);
    $totalEnc      = (int)db()->count("SELECT COUNT(*) FROM encuestas e WHERE $whereSQL", $params);
    $totalPaginas  = max(1, (int)ceil($totalEnc / $perPage));
    $pagina        = min($pagina, $totalPaginas);
    $offset        = ($pagina - 1) * $perPage;

    $encuestas = db()->fetchAll(
        "SELECT e.*,
                u.nombre AS autor_nombre,
                c.nombre AS cat_nombre,
                c.color  AS cat_color,
                (SELECT COUNT(DISTINCT eo.id)
                 FROM encuesta_opciones eo
                 WHERE eo.encuesta_id = e.id) AS num_opciones
         FROM encuestas e
         LEFT JOIN usuarios   u ON u.id = e.autor_id
         LEFT JOIN categorias c ON c.id = e.categoria_id
         WHERE $whereSQL
         ORDER BY e.fecha_creacion DESC
         LIMIT ? OFFSET ?",
        array_merge($params, [$perPage, $offset])
    );

    // Añadir estado de voto y opciones a cada encuesta
    foreach ($encuestas as &$enc) {
        $vi = verificarVotoUsuario((int)$enc['id'], $usuario ? (int)$usuario['id'] : null, $ip);
        $enc['ya_vote']    = $vi['votado'];
        $enc['mis_opc']    = $vi['opciones'];
        $enc['opciones']   = db()->fetchAll(
            "SELECT id, opcion, votos FROM encuesta_opciones
             WHERE encuesta_id = ? ORDER BY votos DESC, orden ASC LIMIT 5",
            [$enc['id']]
        );
        $enc['cerrada'] = !$enc['activa']
            || (!empty($enc['fecha_cierre']) && $enc['fecha_cierre'] < date('Y-m-d H:i:s'));
    }
    unset($enc);

    // Stats globales
    $statsGlobales = [
        'total'       => (int)db()->count("SELECT COUNT(*) FROM encuestas WHERE activa = 1 AND es_standalone = 1"),
        'total_votos' => (int)(db()->fetchColumn("SELECT COALESCE(SUM(total_votos),0) FROM encuestas WHERE es_standalone = 1") ?? 0),
        'cerradas'    => (int)db()->count("SELECT COUNT(*) FROM encuestas WHERE activa = 0 AND es_standalone = 1"),
        'categorias'  => (int)db()->count("SELECT COUNT(DISTINCT categoria_id) FROM encuestas WHERE activa = 1 AND categoria_id IS NOT NULL"),
    ];

    // Encuesta destacada (más votos)
    $destRow = db()->fetchOne(
        "SELECT e.*, c.color AS cat_color, c.nombre AS cat_nombre
         FROM encuestas e
         LEFT JOIN categorias c ON c.id = e.categoria_id
         WHERE e.activa = 1 AND e.es_standalone = 1
         ORDER BY e.total_votos DESC LIMIT 1"
    );
    if ($destRow) {
        $destRow['opciones'] = db()->fetchAll(
            "SELECT id, opcion, votos FROM encuesta_opciones WHERE encuesta_id = ? ORDER BY votos DESC",
            [$destRow['id']]
        );
        $viDest = verificarVotoUsuario((int)$destRow['id'], $usuario ? (int)$usuario['id'] : null, $ip);
        $destRow['ya_vote']  = $viDest['votado'];
        $destRow['mis_opc'] = $viDest['opciones'];
        $encuestaDestacada = $destRow;
    }

    // Categorías con encuestas
    $categoriasDisponibles = db()->fetchAll(
        "SELECT c.id, c.nombre, c.color, c.slug, COUNT(e.id) AS total
         FROM categorias c
         INNER JOIN encuestas e ON e.categoria_id = c.id AND e.activa = 1 AND e.es_standalone = 1
         GROUP BY c.id
         ORDER BY total DESC
         LIMIT 10"
    );

    $pageTitle = 'Encuestas — ' . Config::get('site_nombre', APP_NAME);
}

// Sidebar
$anuncioSidebar = getAnuncios('sidebar', $catFiltro ?: null, 1);
$bodyClass      = 'page-encuestas';
require_once __DIR__ . '/includes/header.php';
?>

<!–– ========================================================
    ESTILOS ESPECÍFICOS DE ENCUESTAS
    ======================================================== -->
<style>
/* ── Variables de encuesta ─────────────────────────────── */
:root {
    --poll-primary:    #e63946;
    --poll-bg:         var(--bg-surface);
    --poll-border:     var(--border-color);
    --poll-radius:     var(--border-radius-xl);
    --poll-anim:       .4s cubic-bezier(.34,1.56,.64,1);
}

/* ── Hero banner de la sección ────────────────────────── */
.polls-hero {
    background: linear-gradient(135deg, var(--primary) 0%, #7c3aed 100%);
    color: #fff;
    padding: 52px 0 36px;
    margin-bottom: 0;
    position: relative;
    overflow: hidden;
}
.polls-hero::before {
    content: '📊';
    position: absolute;
    right: -20px;
    top: -20px;
    font-size: 180px;
    opacity: .07;
    transform: rotate(-15deg);
}
.polls-hero__title {
    font-family: var(--font-serif);
    font-size: clamp(1.6rem, 4vw, 2.4rem);
    font-weight: 800;
    line-height: 1.1;
    margin-bottom: 8px;
}
.polls-hero__sub {
    font-size: .95rem;
    opacity: .85;
}

/* ── Stats bar ─────────────────────────────────────────── */
.polls-stats-bar {
    background: var(--bg-surface);
    border-bottom: 1px solid var(--border-color);
    padding: 14px 0;
    margin-bottom: 32px;
    box-shadow: var(--shadow-sm);
}
.polls-stats-inner {
    display: flex;
    align-items: center;
    gap: 32px;
    flex-wrap: wrap;
}
.poll-stat-item {
    display: flex;
    align-items: center;
    gap: 8px;
}
.poll-stat-icon {
    width: 34px; height: 34px;
    border-radius: var(--border-radius);
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem;
}
.poll-stat-val  { font-size: 1.15rem; font-weight: 900; color: var(--text-primary); }
.poll-stat-lbl  { font-size: .7rem;   color: var(--text-muted); font-weight: 500; }

/* ── Layout principal ──────────────────────────────────── */
.polls-layout {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 28px;
    align-items: start;
}
@media (max-width: 1024px) { .polls-layout { grid-template-columns: 1fr; } }

/* ── Filtros ───────────────────────────────────────────── */
.polls-filters {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 24px;
    padding: 14px 18px;
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-xl);
    box-shadow: var(--shadow-sm);
}
.polls-search {
    position: relative;
    flex: 1;
    min-width: 200px;
}
.polls-search i {
    position: absolute;
    left: 10px; top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: .85rem;
    pointer-events: none;
}
.polls-search input {
    width: 100%;
    padding: 9px 12px 9px 34px;
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    background: var(--bg-body);
    color: var(--text-primary);
    font-size: .83rem;
    transition: border-color .2s;
}
.polls-search input:focus { outline: none; border-color: var(--primary); }
.polls-filter-sel {
    padding: 9px 12px;
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    background: var(--bg-body);
    color: var(--text-primary);
    font-size: .82rem;
    cursor: pointer;
}

/* ── Grid de tarjetas ──────────────────────────────────── */
.polls-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}

/* ── Tarjeta individual ────────────────────────────────── */
.poll-card {
    background: var(--bg-surface);
    border-radius: var(--poll-radius);
    border: 1px solid var(--poll-border);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    transition: all .25s ease;
    display: flex;
    flex-direction: column;
    position: relative;
}
.poll-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}
.poll-card__accent {
    height: 4px;
    background: var(--accent-color, var(--primary));
}
.poll-card__body { padding: 20px; flex: 1; }
.poll-card__header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
}
.poll-card__cat {
    font-size: .65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    padding: 3px 8px;
    border-radius: var(--border-radius-full);
    text-decoration: none;
}
.poll-card__status {
    margin-left: auto;
    font-size: .65rem;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: var(--border-radius-full);
}
.status-activa   { background: rgba(34,197,94,.12); color: var(--success); }
.status-cerrada  { background: rgba(107,114,128,.12); color: var(--text-muted); }
.status-votada   { background: rgba(59,130,246,.12); color: var(--info); }

.poll-card__question {
    font-family: var(--font-serif);
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1.35;
    margin-bottom: 16px;
    text-decoration: none;
    display: block;
}
.poll-card__question:hover { color: var(--primary); }

/* ── Barra de opción ───────────────────────────────────── */
.poll-option-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    cursor: pointer;
    padding: 8px 10px;
    border-radius: var(--border-radius);
    border: 1.5px solid var(--border-color);
    transition: all .2s ease;
    position: relative;
    overflow: hidden;
    background: var(--bg-body);
}
.poll-option-row:hover { border-color: var(--primary); }
.poll-option-row.selected {
    border-color: var(--accent-color, var(--primary));
    background: color-mix(in srgb, var(--accent-color, var(--primary)) 8%, transparent);
}
.poll-option-row.winner {
    border-color: var(--success);
    background: rgba(34,197,94,.06);
}
.poll-option-row input[type="radio"],
.poll-option-row input[type="checkbox"] {
    accent-color: var(--accent-color, var(--primary));
    width: 16px; height: 16px; flex-shrink: 0;
}
.poll-option-bg {
    position: absolute;
    left: 0; top: 0;
    height: 100%;
    background: color-mix(in srgb, var(--accent-color, var(--primary)) 10%, transparent);
    border-radius: inherit;
    transition: width .6s cubic-bezier(.25,.46,.45,.94);
    z-index: 0;
}
.poll-option-text {
    flex: 1;
    font-size: .84rem;
    font-weight: 500;
    color: var(--text-primary);
    position: relative;
    z-index: 1;
}
.poll-option-pct {
    font-size: .75rem;
    font-weight: 800;
    color: var(--accent-color, var(--primary));
    position: relative;
    z-index: 1;
    min-width: 36px;
    text-align: right;
}
.poll-option-votes {
    font-size: .68rem;
    color: var(--text-muted);
    position: relative;
    z-index: 1;
}

/* ── Footer de la tarjeta ──────────────────────────────── */
.poll-card__footer {
    padding: 12px 20px;
    border-top: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.poll-meta {
    display: flex;
    align-items: center;
    gap: 10px;
}
.poll-meta-item {
    font-size: .72rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 3px;
}

/* ── Botones de acción ─────────────────────────────────── */
.btn-vote {
    padding: 8px 18px;
    border-radius: var(--border-radius-full);
    font-size: .78rem;
    font-weight: 700;
    cursor: pointer;
    border: none;
    background: var(--accent-color, var(--primary));
    color: #fff;
    transition: all .2s ease;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.btn-vote:hover { opacity: .88; transform: scale(1.02); }
.btn-vote:disabled { opacity: .5; cursor: not-allowed; transform: none; }
.btn-change-vote {
    padding: 6px 14px;
    border-radius: var(--border-radius-full);
    font-size: .72rem;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid var(--border-color);
    background: var(--bg-surface-2);
    color: var(--text-muted);
    transition: all .2s;
}
.btn-change-vote:hover { border-color: var(--primary); color: var(--primary); }

/* ── Página de detalle ─────────────────────────────────── */
.poll-detail-wrap {
    background: var(--bg-surface);
    border-radius: var(--border-radius-xl);
    border: 1px solid var(--border-color);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    margin-bottom: 28px;
}
.poll-detail-header {
    padding: 28px 32px 20px;
    background: linear-gradient(135deg, var(--detail-color, var(--primary)) 0%, var(--detail-color2, #7c3aed) 100%);
    color: #fff;
    position: relative;
    overflow: hidden;
}
.poll-detail-header::after {
    content: '📊';
    position: absolute;
    right: 20px; bottom: -10px;
    font-size: 80px;
    opacity: .12;
}
.poll-detail-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    background: rgba(255,255,255,.2);
    border-radius: var(--border-radius-full);
    font-size: .7rem;
    font-weight: 700;
    margin-bottom: 12px;
}
.poll-detail-question {
    font-family: var(--font-serif);
    font-size: clamp(1.3rem, 3vw, 1.8rem);
    font-weight: 800;
    line-height: 1.25;
    margin-bottom: 10px;
}
.poll-detail-desc {
    font-size: .88rem;
    opacity: .85;
    line-height: 1.5;
    max-width: 600px;
}
.poll-detail-meta {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-top: 16px;
    padding-top: 14px;
    border-top: 1px solid rgba(255,255,255,.15);
}
.poll-detail-meta-item {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: .78rem;
    opacity: .85;
}

.poll-detail-body { padding: 28px 32px; }

.poll-form { max-width: 560px; }

/* Opción grande en detalle */
.poll-opt-detail {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    border-radius: var(--border-radius-lg);
    border: 2px solid var(--border-color);
    margin-bottom: 10px;
    cursor: pointer;
    transition: all .2s ease;
    position: relative;
    overflow: hidden;
    background: var(--bg-body);
}
.poll-opt-detail:hover:not(.disabled-opt) {
    border-color: var(--accent-color, var(--primary));
    background: color-mix(in srgb, var(--accent-color, var(--primary)) 5%, transparent);
}
.poll-opt-detail.selected-opt {
    border-color: var(--accent-color, var(--primary));
    background: color-mix(in srgb, var(--accent-color, var(--primary)) 8%, transparent);
}
.poll-opt-detail.winner-opt {
    border-color: var(--success);
    background: rgba(34,197,94,.05);
}
.poll-opt-detail.disabled-opt { cursor: default; }

.poll-opt-bg {
    position: absolute;
    left: 0; top: 0; height: 100%;
    background: color-mix(in srgb, var(--accent-color, var(--primary)) 12%, transparent);
    z-index: 0;
    border-radius: inherit;
    transition: width .8s cubic-bezier(.25,.46,.45,.94);
}
.poll-opt-detail .opt-check {
    width: 20px; height: 20px;
    flex-shrink: 0;
    accent-color: var(--accent-color, var(--primary));
    position: relative; z-index: 1;
}
.poll-opt-label {
    flex: 1;
    font-size: .9rem;
    font-weight: 500;
    color: var(--text-primary);
    position: relative; z-index: 1;
}
.poll-opt-bar {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    min-width: 60px;
    position: relative; z-index: 1;
}
.poll-opt-pct {
    font-size: .88rem;
    font-weight: 800;
    color: var(--accent-color, var(--primary));
}
.poll-opt-cnt {
    font-size: .65rem;
    color: var(--text-muted);
}

/* ── Mensaje de estado ─────────────────────────────────── */
.poll-voted-msg {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: rgba(34,197,94,.1);
    border: 1px solid rgba(34,197,94,.25);
    border-radius: var(--border-radius-lg);
    font-size: .84rem;
    font-weight: 600;
    color: var(--success);
    margin-bottom: 16px;
}
.poll-closed-msg {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: rgba(107,114,128,.1);
    border: 1px solid rgba(107,114,128,.2);
    border-radius: var(--border-radius-lg);
    font-size: .84rem;
    font-weight: 500;
    color: var(--text-muted);
    margin-bottom: 16px;
}

/* ── Actividad reciente ────────────────────────────────── */
.activity-feed { margin-top: 24px; }
.activity-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border-color);
}
.activity-item:last-child { border-bottom: none; }
.activity-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--primary);
    flex-shrink: 0;
    margin-top: 5px;
}

/* ── Widget de encuesta ────────────────────────────────── */
.widget-poll-card {
    background: var(--bg-surface);
    border-radius: var(--border-radius-xl);
    border: 1px solid var(--border-color);
    overflow: hidden;
    margin-bottom: 20px;
    box-shadow: var(--shadow-sm);
}
.widget-poll-header {
    padding: 16px 18px 12px;
    background: linear-gradient(135deg, var(--primary), #7c3aed);
    color: #fff;
}
.widget-poll-title {
    font-family: var(--font-serif);
    font-size: .95rem;
    font-weight: 700;
    line-height: 1.3;
}
.widget-poll-body { padding: 16px 18px; }

/* ── Paginación ────────────────────────────────────────── */
.polls-pagination {
    display: flex;
    justify-content: center;
    gap: 6px;
    margin-top: 32px;
    flex-wrap: wrap;
}
.polls-pagination a,
.polls-pagination span {
    padding: 7px 12px;
    border-radius: var(--border-radius);
    border: 1px solid var(--border-color);
    font-size: .82rem;
    font-weight: 600;
    text-decoration: none;
    color: var(--text-secondary);
    background: var(--bg-surface);
    transition: all .2s;
}
.polls-pagination a:hover   { border-color: var(--primary); color: var(--primary); }
.polls-pagination .current  { background: var(--primary); color: #fff; border-color: var(--primary); }
.polls-pagination .disabled { opacity: .4; pointer-events: none; }

/* ── Sidebar ───────────────────────────────────────────── */
.poll-sidebar { display: flex; flex-direction: column; gap: 20px; }
.poll-sidebar-widget {
    background: var(--bg-surface);
    border-radius: var(--border-radius-xl);
    border: 1px solid var(--border-color);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}
.poll-sidebar-widget__header {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border-color);
    font-size: .83rem;
    font-weight: 700;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 7px;
}
.poll-sidebar-widget__body { padding: 14px 18px; }

/* ── Encuesta destacada ────────────────────────────────── */
.featured-poll {
    background: var(--bg-surface);
    border-radius: var(--border-radius-xl);
    border: 2px solid var(--primary);
    overflow: hidden;
    box-shadow: var(--shadow-md);
    margin-bottom: 28px;
}
.featured-poll__header {
    background: linear-gradient(135deg, var(--primary), #c1121f);
    padding: 18px 22px;
    color: #fff;
}
.featured-poll__label {
    font-size: .65rem;
    text-transform: uppercase;
    letter-spacing: .1em;
    font-weight: 700;
    opacity: .8;
    margin-bottom: 6px;
}
.featured-poll__question {
    font-family: var(--font-serif);
    font-size: 1.1rem;
    font-weight: 800;
    line-height: 1.3;
}
.featured-poll__body { padding: 20px 22px; }

/* ── Toast ─────────────────────────────────────────────── */
.poll-toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    padding: 12px 18px;
    border-radius: var(--border-radius-lg);
    font-size: .85rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
    z-index: 9999;
    box-shadow: var(--shadow-xl);
    animation: toastIn .3s ease;
    max-width: 320px;
}
.poll-toast.success { background: var(--success); color: #fff; }
.poll-toast.error   { background: var(--danger);  color: #fff; }
.poll-toast.info    { background: var(--info);    color: #fff; }
@keyframes toastIn { from { opacity:0; transform: translateY(10px); } to { opacity:1; transform: translateY(0); } }

/* ── Loading spinner ───────────────────────────────────── */
.poll-loading {
    display: inline-block;
    width: 16px; height: 16px;
    border: 2px solid rgba(255,255,255,.4);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Responsive ────────────────────────────────────────── */
@media (max-width: 768px) {
    .polls-grid { grid-template-columns: 1fr; }
    .polls-hero  { padding: 32px 0 24px; }
    .poll-detail-header { padding: 20px; }
    .poll-detail-body   { padding: 20px; }
    .polls-stats-inner  { gap: 18px; }
    .featured-poll__body { padding: 14px 16px; }
}
</style>

<?php if ($modoDetalle && $encuesta): ?>
<!-- ============================================================
     VISTA DETALLE DE ENCUESTA INDIVIDUAL
     ============================================================ -->

<!-- Breadcrumb -->
<div style="background:var(--bg-surface);border-bottom:1px solid var(--border-color);padding:10px 0;margin-bottom:0">
    <div class="container-fluid px-3 px-lg-4">
        <nav style="font-size:.78rem;color:var(--text-muted)">
            <a href="<?= APP_URL ?>/index.php" style="color:var(--text-muted);text-decoration:none">Inicio</a>
            <span style="margin:0 6px">›</span>
            <a href="<?= APP_URL ?>/encuestas.php" style="color:var(--text-muted);text-decoration:none">Encuestas</a>
            <span style="margin:0 6px">›</span>
            <span style="color:var(--text-primary)"><?= e(truncateChars($encuesta['pregunta'], 50)) ?></span>
        </nav>
    </div>
</div>

<div class="container-fluid px-3 px-lg-4" style="padding-top:28px;padding-bottom:60px">
    <div class="polls-layout">

        <!-- ── Columna principal ─────────────────────────────── -->
        <div>
            <!-- Card principal de la encuesta -->
            <div class="poll-detail-wrap"
                 id="pollDetailCard"
                 data-encuesta-id="<?= (int)$encuesta['id'] ?>"
                 data-tipo="<?= e($encuesta['tipo']) ?>"
                 data-puede-cambiar="<?= $encuesta['puede_cambiar_voto'] ? '1' : '0' ?>"
                 style="--detail-color: <?= e($encuesta['color'] ?? '#e63946') ?>;
                        --detail-color2: <?= adjustColor($encuesta['color'] ?? '#e63946') ?>;
                        --accent-color: <?= e($encuesta['color'] ?? '#e63946') ?>">

                <!-- Header con gradiente -->
                <div class="poll-detail-header">
                    <div class="poll-detail-badge">
                        <i class="bi bi-bar-chart-fill"></i>
                        <?= $encuesta['tipo'] === 'multiple' ? 'Múltiple respuesta' : 'Una sola respuesta' ?>
                        <?php if ($cerrada): ?>
                        · <i class="bi bi-lock-fill"></i> Cerrada
                        <?php endif; ?>
                    </div>

                    <h1 class="poll-detail-question">
                        <?= e($encuesta['pregunta']) ?>
                    </h1>

                    <?php if (!empty($encuesta['descripcion'])): ?>
                    <p class="poll-detail-desc"><?= e($encuesta['descripcion']) ?></p>
                    <?php endif; ?>

                    <div class="poll-detail-meta">
                        <div class="poll-detail-meta-item">
                            <i class="bi bi-people-fill"></i>
                            <?= formatNumber((int)$encuesta['total_votos']) ?> votos
                        </div>
                        <?php if (!empty($encuesta['cat_nombre'])): ?>
                        <div class="poll-detail-meta-item">
                            <i class="bi bi-grid-fill"></i>
                            <?= e($encuesta['cat_nombre']) ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($encuesta['fecha_creacion'])): ?>
                        <div class="poll-detail-meta-item">
                            <i class="bi bi-calendar3"></i>
                            <?= formatDate($encuesta['fecha_creacion'], 'short') ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($encuesta['fecha_cierre'])): ?>
                        <div class="poll-detail-meta-item">
                            <i class="bi bi-clock"></i>
                            Cierra: <?= formatDate($encuesta['fecha_cierre'], 'short') ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($encuesta['puede_cambiar_voto']): ?>
                        <div class="poll-detail-meta-item">
                            <i class="bi bi-arrow-repeat"></i>
                            Puedes cambiar tu voto
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Body con formulario de votación -->
                <div class="poll-detail-body">
                    <div id="pollMessages"></div>

                    <?php if ($yaVote && !$cerrada && $encuesta['puede_cambiar_voto']): ?>
                    <div class="poll-voted-msg" id="votedMsg">
                        <i class="bi bi-check-circle-fill"></i>
                        Ya votaste en esta encuesta.
                        <button onclick="habilitarCambioVoto()"
                                class="btn-change-vote" style="margin-left:auto">
                            <i class="bi bi-pencil-fill"></i> Cambiar voto
                        </button>
                    </div>
                    <?php elseif ($cerrada): ?>
                    <div class="poll-closed-msg">
                        <i class="bi bi-lock-fill"></i>
                        Esta encuesta está cerrada. Los resultados son definitivos.
                    </div>
                    <?php endif; ?>

                    <!-- Formulario de votación -->
                    <form id="pollDetailForm" class="poll-form"
                          onsubmit="submitVoto(event, <?= (int)$encuesta['id'] ?>)"
                          <?= ($yaVote && !$encuesta['puede_cambiar_voto']) || $cerrada ? 'style="pointer-events:none"' : '' ?>>

                        <div id="pollOptionsContainer">
                        <?php
                        $totalVotos = (int)$encuesta['total_votos'];
                        $opMax      = !empty($encuesta['opciones']) ? max(array_column($encuesta['opciones'], 'votos')) : 0;
                        foreach ($encuesta['opciones'] as $opcion):
                            $votos     = (int)$opcion['votos'];
                            $pct       = $totalVotos > 0 ? round(($votos / $totalVotos) * 100, 1) : 0;
                            $esGanadora = $votos > 0 && $votos === $opMax && $totalVotos > 0;
                            $esSeleccionada = in_array((int)$opcion['id'], $misOpciones);
                            $inputType = $encuesta['tipo'] === 'multiple' ? 'checkbox' : 'radio';
                            $claseExtra = $esGanadora ? ' winner-opt' : '';
                            $claseExtra .= $esSeleccionada ? ' selected-opt' : '';
                            $claseExtra .= ($yaVote && !$encuesta['puede_cambiar_voto']) || $cerrada ? ' disabled-opt' : '';
                        ?>
                        <label class="poll-opt-detail<?= $claseExtra ?>"
                               data-opcion-id="<?= (int)$opcion['id'] ?>"
                               onclick="selectOpcion(this, <?= $encuesta['tipo'] === 'multiple' ? 'true' : 'false' ?>)">
                            <div class="poll-opt-bg" style="width:<?= $mostrarResultados ? $pct . '%' : '0%' ?>"
                                 data-pct="<?= $pct ?>"></div>
                            <input type="<?= $inputType ?>"
                                   name="opcion<?= $encuesta['tipo'] === 'multiple' ? '[]' : '' ?>"
                                   value="<?= (int)$opcion['id'] ?>"
                                   class="opt-check"
                                   <?= $esSeleccionada ? 'checked' : '' ?>
                                   <?= ($yaVote && !$encuesta['puede_cambiar_voto']) || $cerrada ? 'disabled' : '' ?>>
                            <span class="poll-opt-label">
                                <?= e($opcion['opcion']) ?>
                                <?php if ($esGanadora && $mostrarResultados): ?>
                                <i class="bi bi-trophy-fill" style="color:var(--warning);font-size:.75rem;margin-left:5px"></i>
                                <?php endif; ?>
                            </span>
                            <?php if ($mostrarResultados): ?>
                            <div class="poll-opt-bar">
                                <span class="poll-opt-pct"><?= $pct ?>%</span>
                                <span class="poll-opt-cnt"><?= formatNumber($votos) ?> votos</span>
                            </div>
                            <?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                        </div>

                        <?php if ((!$yaVote || $encuesta['puede_cambiar_voto']) && !$cerrada): ?>
                        <div style="margin-top:18px;display:flex;align-items:center;gap:10px;flex-wrap:wrap"
                             id="voteActions">
                            <button type="submit"
                                    class="btn-vote"
                                    id="submitBtn"
                                    style="--accent-color:<?= e($encuesta['color'] ?? 'var(--primary)') ?>"
                                    <?= $yaVote ? 'disabled' : '' ?>>
                                <i class="bi bi-check2-circle"></i>
                                <?= $yaVote ? 'Cambiar mi voto' : 'Votar ahora' ?>
                            </button>
                            <?php if ($yaVote): ?>
                            <button type="button"
                                    class="btn-change-vote"
                                    onclick="cancelarCambio()">
                                Cancelar
                            </button>
                            <?php endif; ?>
                            <?php if (!$usuario): ?>
                            <span style="font-size:.75rem;color:var(--text-muted)">
                                <i class="bi bi-info-circle"></i>
                                Votando como anónimo.
                                <a href="<?= APP_URL ?>/login.php" style="color:var(--primary)">Inicia sesión</a>
                                para identificar tu voto.
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </form>

                    <!-- Total de votos -->
                    <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border-color);
                                display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
                        <span style="font-size:.82rem;color:var(--text-muted);display:flex;align-items:center;gap:6px">
                            <i class="bi bi-people-fill" style="color:var(--primary)"></i>
                            <strong id="totalVotesLabel"><?= formatNumber((int)$encuesta['total_votos']) ?></strong>
                            voto<?= $encuesta['total_votos'] !== 1 ? 's' : '' ?> en total
                        </span>

                        <!-- Compartir -->
                        <div style="display:flex;align-items:center;gap:6px">
                            <span style="font-size:.75rem;color:var(--text-muted)">Compartir:</span>
                            <?php
                            $urlEnc   = urlencode(APP_URL . '/encuestas.php?slug=' . ($encuesta['slug'] ?? ''));
                            $textEnc  = urlencode('Participa en esta encuesta: ' . $encuesta['pregunta']);
                            ?>
                            <a href="https://wa.me/?text=<?= $textEnc ?>%20<?= $urlEnc ?>"
                               target="_blank" rel="noopener"
                               style="width:30px;height:30px;border-radius:50%;background:#25d366;
                                      color:#fff;display:flex;align-items:center;justify-content:center;
                                      font-size:.85rem;text-decoration:none">
                                <i class="bi bi-whatsapp"></i>
                            </a>
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= $urlEnc ?>"
                               target="_blank" rel="noopener"
                               style="width:30px;height:30px;border-radius:50%;background:#1877f2;
                                      color:#fff;display:flex;align-items:center;justify-content:center;
                                      font-size:.85rem;text-decoration:none">
                                <i class="bi bi-facebook"></i>
                            </a>
                            <a href="https://twitter.com/intent/tweet?text=<?= $textEnc ?>&url=<?= $urlEnc ?>"
                               target="_blank" rel="noopener"
                               style="width:30px;height:30px;border-radius:50%;background:#000;
                                      color:#fff;display:flex;align-items:center;justify-content:center;
                                      font-size:.85rem;text-decoration:none">
                                <i class="bi bi-twitter-x"></i>
                            </a>
                            <button onclick="navigator.clipboard.writeText('<?= APP_URL ?>/encuestas.php?slug=<?= e($encuesta['slug']) ?>').then(()=>showPollToast('¡Enlace copiado!','success'))"
                                    style="width:30px;height:30px;border-radius:50%;
                                           background:var(--bg-surface-2);border:1px solid var(--border-color);
                                           color:var(--text-muted);display:flex;align-items:center;
                                           justify-content:center;font-size:.85rem;cursor:pointer">
                                <i class="bi bi-link-45deg"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actividad reciente -->
            <?php if (!empty($actividadReciente)): ?>
            <div style="background:var(--bg-surface);border-radius:var(--border-radius-xl);
                        border:1px solid var(--border-color);padding:20px 24px;
                        box-shadow:var(--shadow-sm);margin-bottom:24px">
                <h3 style="font-size:.9rem;font-weight:700;color:var(--text-primary);
                            margin-bottom:16px;display:flex;align-items:center;gap:7px">
                    <i class="bi bi-activity" style="color:var(--primary)"></i>
                    Actividad reciente
                </h3>
                <div class="activity-feed">
                    <?php foreach ($actividadReciente as $act): ?>
                    <div class="activity-item">
                        <div class="activity-dot"
                             style="background:<?= e($encuesta['color'] ?? 'var(--primary)') ?>"></div>
                        <div>
                            <div style="font-size:.82rem;color:var(--text-primary)">
                                <strong><?= e($act['votante']) ?></strong>
                                votó por
                                <em style="color:var(--primary)">"<?= e(truncateChars($act['opcion'], 40)) ?>"</em>
                            </div>
                            <div style="font-size:.7rem;color:var(--text-muted)">
                                <?= timeAgo($act['fecha']) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Encuestas relacionadas -->
            <?php if (!empty($relacionadas)): ?>
            <div style="margin-bottom:28px">
                <h3 style="font-family:var(--font-serif);font-size:1.1rem;font-weight:700;
                            color:var(--text-primary);margin-bottom:16px;
                            display:flex;align-items:center;gap:8px">
                    <i class="bi bi-bar-chart-steps" style="color:var(--primary)"></i>
                    Más encuestas
                </h3>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px">
                    <?php foreach ($relacionadas as $rel): ?>
                    <a href="<?= APP_URL ?>/encuestas.php?slug=<?= e($rel['slug']) ?>"
                       style="display:flex;align-items:flex-start;gap:12px;padding:14px;
                              background:var(--bg-surface);border:1px solid var(--border-color);
                              border-radius:var(--border-radius-lg);text-decoration:none;
                              transition:all .2s;color:inherit"
                       onmouseover="this.style.borderColor='var(--primary)'"
                       onmouseout="this.style.borderColor='var(--border-color)'">
                        <div style="width:36px;height:36px;border-radius:var(--border-radius);
                                    background:<?= e($rel['color'] ?? 'var(--primary)') ?>22;
                                    color:<?= e($rel['color'] ?? 'var(--primary)') ?>;
                                    display:flex;align-items:center;justify-content:center;
                                    font-size:1rem;flex-shrink:0">
                            <i class="bi bi-bar-chart-fill"></i>
                        </div>
                        <div>
                            <div style="font-size:.82rem;font-weight:600;color:var(--text-primary);
                                        line-height:1.3;margin-bottom:4px">
                                <?= e(truncateChars($rel['pregunta'], 70)) ?>
                            </div>
                            <div style="font-size:.7rem;color:var(--text-muted)">
                                <?= formatNumber((int)$rel['total_votos']) ?> votos
                                · <?= (int)$rel['num_opc'] ?> opciones
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div><!-- /columna principal -->

        <!-- ── Sidebar ────────────────────────────────────────── -->
        <aside class="poll-sidebar">

            <!-- Resumen estadístico -->
            <div class="poll-sidebar-widget">
                <div class="poll-sidebar-widget__header">
                    <i class="bi bi-pie-chart-fill" style="color:var(--primary)"></i>
                    Resumen de resultados
                </div>
                <div class="poll-sidebar-widget__body">
                    <?php
                    $totalVotos = (int)$encuesta['total_votos'];
                    $opMax2 = !empty($encuesta['opciones']) ? max(array_column($encuesta['opciones'], 'votos')) : 0;
                    foreach ($encuesta['opciones'] as $op):
                        $pct2 = $totalVotos > 0 ? round(($op['votos'] / $totalVotos) * 100, 1) : 0;
                        $esLider = ((int)$op['votos'] === $opMax2 && $totalVotos > 0);
                    ?>
                    <div style="margin-bottom:12px">
                        <div style="display:flex;justify-content:space-between;align-items:center;
                                    margin-bottom:4px">
                            <span style="font-size:.78rem;color:var(--text-secondary);font-weight:<?= $esLider ? '700' : '500' ?>">
                                <?= $esLider ? '🏆 ' : '' ?><?= e(truncateChars($op['opcion'], 30)) ?>
                            </span>
                            <span style="font-size:.75rem;font-weight:800;
                                         color:<?= e($encuesta['color'] ?? 'var(--primary)') ?>">
                                <?= $pct2 ?>%
                            </span>
                        </div>
                        <div style="height:7px;background:var(--bg-surface-3);border-radius:4px;overflow:hidden">
                            <div style="height:100%;width:<?= $pct2 ?>%;
                                         background:<?= e($encuesta['color'] ?? 'var(--primary)') ?>;
                                         border-radius:4px;
                                         transition:width .8s ease"></div>
                        </div>
                        <div style="font-size:.68rem;color:var(--text-muted);margin-top:2px">
                            <?= formatNumber((int)$op['votos']) ?> voto<?= $op['votos'] !== 1 ? 's' : '' ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div style="padding-top:12px;border-top:1px solid var(--border-color);
                                text-align:center;font-size:.8rem;color:var(--text-muted)">
                        <i class="bi bi-people-fill"></i>
                        <strong><?= formatNumber($totalVotos) ?></strong> votos en total
                    </div>
                </div>
            </div>

            <!-- Enlace a todas las encuestas -->
            <div class="poll-sidebar-widget">
                <div class="poll-sidebar-widget__body" style="text-align:center">
                    <i class="bi bi-collection-fill" style="font-size:2rem;color:var(--primary);display:block;margin-bottom:8px"></i>
                    <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:12px">
                        ¿Quieres participar en más encuestas?
                    </p>
                    <a href="<?= APP_URL ?>/encuestas.php"
                       style="display:inline-flex;align-items:center;gap:6px;
                              padding:9px 18px;background:var(--primary);color:#fff;
                              border-radius:var(--border-radius-full);font-size:.82rem;
                              font-weight:700;text-decoration:none;transition:opacity .2s"
                       onmouseover="this.style.opacity='.85'"
                       onmouseout="this.style.opacity='1'">
                        <i class="bi bi-bar-chart-fill"></i>
                        Ver todas las encuestas
                    </a>
                </div>
            </div>

            <!-- Anuncio sidebar -->
            <?php if (!empty($anuncioSidebar)): ?>
            <div class="poll-sidebar-widget">
                <div class="poll-sidebar-widget__body">
                    <?= renderAnuncio($anuncioSidebar[0]) ?>
                </div>
            </div>
            <?php endif; ?>
        </aside>

    </div><!-- /polls-layout -->
</div><!-- /container -->

<?php else: ?>
<!-- ============================================================
     VISTA DE LISTADO DE ENCUESTAS
     ============================================================ -->

<!-- Hero -->
<div class="polls-hero">
    <div class="container-fluid px-3 px-lg-4">
        <h1 class="polls-hero__title">
            <i class="bi bi-bar-chart-fill" style="opacity:.85;margin-right:10px"></i>
            Encuestas de opinión
        </h1>
        <p class="polls-hero__sub">
            Tu opinión importa. Vota y conoce lo que piensa la comunidad de
            <?= e(Config::get('site_nombre', APP_NAME)) ?>.
        </p>
    </div>
</div>

<!-- Stats bar -->
<div class="polls-stats-bar">
    <div class="container-fluid px-3 px-lg-4">
        <div class="polls-stats-inner">
            <div class="poll-stat-item">
                <div class="poll-stat-icon" style="background:rgba(230,57,70,.1);color:var(--primary)">
                    <i class="bi bi-bar-chart-fill"></i>
                </div>
                <div>
                    <div class="poll-stat-val"><?= formatNumber($statsGlobales['total']) ?></div>
                    <div class="poll-stat-lbl">Encuestas activas</div>
                </div>
            </div>
            <div class="poll-stat-item">
                <div class="poll-stat-icon" style="background:rgba(34,197,94,.1);color:var(--success)">
                    <i class="bi bi-people-fill"></i>
                </div>
                <div>
                    <div class="poll-stat-val"><?= formatNumber($statsGlobales['total_votos']) ?></div>
                    <div class="poll-stat-lbl">Votos totales</div>
                </div>
            </div>
            <div class="poll-stat-item">
                <div class="poll-stat-icon" style="background:rgba(107,114,128,.1);color:var(--text-muted)">
                    <i class="bi bi-archive-fill"></i>
                </div>
                <div>
                    <div class="poll-stat-val"><?= formatNumber($statsGlobales['cerradas']) ?></div>
                    <div class="poll-stat-lbl">Cerradas</div>
                </div>
            </div>
            <div class="poll-stat-item">
                <div class="poll-stat-icon" style="background:rgba(59,130,246,.1);color:var(--info)">
                    <i class="bi bi-grid-fill"></i>
                </div>
                <div>
                    <div class="poll-stat-val"><?= formatNumber($statsGlobales['categorias']) ?></div>
                    <div class="poll-stat-lbl">Categorías</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid px-3 px-lg-4" style="padding-top:8px;padding-bottom:60px">
    <div class="polls-layout">

        <!-- ── Contenido principal ───────────────────────────── -->
        <div>

            <!-- Encuesta destacada -->
            <?php if ($encuestaDestacada && empty($busq) && $catFiltro === 0 && $pagina === 1): ?>
            <div class="featured-poll"
                 data-encuesta-id="<?= (int)$encuestaDestacada['id'] ?>"
                 data-tipo="<?= e($encuestaDestacada['tipo']) ?>"
                 data-puede-cambiar="<?= $encuestaDestacada['puede_cambiar_voto'] ? '1' : '0' ?>"
                 style="--accent-color:<?= e($encuestaDestacada['color'] ?? '#e63946') ?>">
                <div class="featured-poll__header">
                    <div class="featured-poll__label">
                        <i class="bi bi-star-fill"></i> Encuesta destacada
                    </div>
                    <div class="featured-poll__question">
                        <?= e($encuestaDestacada['pregunta']) ?>
                    </div>
                    <?php if (!empty($encuestaDestacada['cat_nombre'])): ?>
                    <span style="font-size:.7rem;background:rgba(255,255,255,.2);
                                 padding:3px 9px;border-radius:var(--border-radius-full);
                                 margin-top:8px;display:inline-block">
                        <?= e($encuestaDestacada['cat_nombre']) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="featured-poll__body">
                    <?php if ($encuestaDestacada['ya_vote']): ?>
                    <div class="poll-voted-msg" style="margin-bottom:14px">
                        <i class="bi bi-check-circle-fill"></i>
                        Ya participaste en esta encuesta.
                        <?php if ($encuestaDestacada['puede_cambiar_voto']): ?>
                        <a href="<?= APP_URL ?>/encuestas.php?slug=<?= e($encuestaDestacada['slug']) ?>"
                           style="margin-left:auto;font-size:.75rem;color:var(--primary)">
                            Cambiar voto →
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php
                    $tvDest = (int)$encuestaDestacada['total_votos'];
                    foreach (array_slice($encuestaDestacada['opciones'], 0, 4) as $opDest):
                        $pctDest = $tvDest > 0 ? round(($opDest['votos'] / $tvDest) * 100, 1) : 0;
                        $esSelDest = in_array((int)$opDest['id'], $encuestaDestacada['mis_opc'] ?? []);
                    ?>
                    <label class="poll-option-row <?= $esSelDest ? 'selected' : '' ?>"
                           data-opcion-id="<?= (int)$opDest['id'] ?>"
                           onclick="selectInline(this, <?= (int)$encuestaDestacada['id'] ?>, <?= $encuestaDestacada['tipo'] === 'multiple' ? 'true' : 'false' ?>)">
                        <div class="poll-option-bg" style="width:<?= $encuestaDestacada['ya_vote'] ? $pctDest.'%' : '0%' ?>"></div>
                        <input type="<?= $encuestaDestacada['tipo'] === 'multiple' ? 'checkbox' : 'radio' ?>"
                               name="feat_opcion"
                               value="<?= (int)$opDest['id'] ?>"
                               class="opt-check"
                               <?= $esSelDest ? 'checked' : '' ?>
                               style="accent-color:<?= e($encuestaDestacada['color'] ?? 'var(--primary)') ?>">
                        <span class="poll-option-text"><?= e($opDest['opcion']) ?></span>
                        <?php if ($encuestaDestacada['ya_vote']): ?>
                        <span class="poll-option-pct"
                              style="color:<?= e($encuestaDestacada['color'] ?? 'var(--primary)') ?>">
                            <?= $pctDest ?>%
                        </span>
                        <span class="poll-option-votes"><?= formatNumber((int)$opDest['votos']) ?></span>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>

                    <div style="display:flex;align-items:center;justify-content:space-between;
                                flex-wrap:wrap;gap:10px;margin-top:14px">
                        <?php if (!$encuestaDestacada['ya_vote']): ?>
                        <button onclick="submitInlineVoto(<?= (int)$encuestaDestacada['id'] ?>, this)"
                                class="btn-vote"
                                style="--accent-color:<?= e($encuestaDestacada['color'] ?? 'var(--primary)') ?>">
                            <i class="bi bi-check2-circle"></i> Votar
                        </button>
                        <?php else: ?>
                        <span style="font-size:.78rem;color:var(--text-muted)">
                            <?= formatNumber($tvDest) ?> votos en total
                        </span>
                        <?php endif; ?>
                        <a href="<?= APP_URL ?>/encuestas.php?slug=<?= e($encuestaDestacada['slug']) ?>"
                           style="font-size:.78rem;color:var(--primary);font-weight:600;text-decoration:none">
                            Ver encuesta completa →
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filtros -->
            <form method="get" action="<?= APP_URL ?>/encuestas.php" class="polls-filters">
                <div class="polls-search">
                    <i class="bi bi-search"></i>
                    <input type="text" name="q"
                           value="<?= e($busq) ?>"
                           placeholder="Buscar encuesta...">
                </div>
                <?php if (!empty($categoriasDisponibles)): ?>
                <select name="cat" class="polls-filter-sel" onchange="this.form.submit()">
                    <option value="0">Todas las categorías</option>
                    <?php foreach ($categoriasDisponibles as $catD): ?>
                    <option value="<?= (int)$catD['id'] ?>"
                            <?= $catFiltro === (int)$catD['id'] ? 'selected' : '' ?>>
                        <?= e($catD['nombre']) ?> (<?= (int)$catD['total'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <button type="submit" class="btn-vote" style="--accent-color:var(--primary)">
                    <i class="bi bi-funnel-fill"></i> Filtrar
                </button>
                <?php if ($busq || $catFiltro): ?>
                <a href="<?= APP_URL ?>/encuestas.php"
                   style="padding:9px 14px;border-radius:var(--border-radius-full);
                          border:1px solid var(--border-color);font-size:.78rem;
                          font-weight:600;color:var(--text-muted);text-decoration:none;
                          background:var(--bg-surface-2)">
                    <i class="bi bi-x-circle"></i> Limpiar
                </a>
                <?php endif; ?>
            </form>

            <!-- Grid de encuestas -->
            <?php if (empty($encuestas)): ?>
            <div style="text-align:center;padding:60px 20px;background:var(--bg-surface);
                        border-radius:var(--border-radius-xl);border:2px dashed var(--border-color)">
                <i class="bi bi-bar-chart" style="font-size:2.5rem;color:var(--text-muted);display:block;margin-bottom:12px;opacity:.4"></i>
                <h3 style="font-family:var(--font-serif);font-size:1.1rem;color:var(--text-muted);margin-bottom:6px">
                    No hay encuestas disponibles
                </h3>
                <p style="font-size:.83rem;color:var(--text-muted)">
                    Vuelve pronto o prueba con otros filtros.
                </p>
            </div>
            <?php else: ?>
            <div class="polls-grid" id="pollsGrid">
                <?php foreach ($encuestas as $enc):
                    $tvEnc = (int)$enc['total_votos'];
                    $opMaxEnc = !empty($enc['opciones']) ? max(array_column($enc['opciones'], 'votos')) : 0;
                ?>
                <div class="poll-card"
                     data-encuesta-id="<?= (int)$enc['id'] ?>"
                     data-tipo="<?= e($enc['tipo']) ?>"
                     data-puede-cambiar="<?= $enc['puede_cambiar_voto'] ? '1' : '0' ?>"
                     style="--accent-color:<?= e($enc['color'] ?? '#e63946') ?>">
                    <div class="poll-card__accent"
                         style="background:<?= e($enc['color'] ?? '#e63946') ?>"></div>
                    <div class="poll-card__body">
                        <div class="poll-card__header">
                            <?php if (!empty($enc['cat_nombre'])): ?>
                            <span class="poll-card__cat"
                                  style="background:<?= e($enc['cat_color'] ?? 'var(--primary)') ?>22;
                                         color:<?= e($enc['cat_color'] ?? 'var(--primary)') ?>">
                                <?= e($enc['cat_nombre']) ?>
                            </span>
                            <?php endif; ?>
                            <span class="poll-card__status <?= $enc['ya_vote'] ? 'status-votada' : ($enc['cerrada'] ? 'status-cerrada' : 'status-activa') ?>">
                                <?= $enc['ya_vote'] ? '✓ Votada' : ($enc['cerrada'] ? 'Cerrada' : '● Activa') ?>
                            </span>
                        </div>

                        <a href="<?= APP_URL ?>/encuestas.php?slug=<?= e($enc['slug']) ?>"
                           class="poll-card__question">
                            <?= e($enc['pregunta']) ?>
                        </a>

                        <!-- Opciones con barras -->
                        <div id="opts-<?= (int)$enc['id'] ?>">
                        <?php foreach (array_slice($enc['opciones'], 0, 4) as $opE):
                            $pctE = $tvEnc > 0 ? round(($opE['votos'] / $tvEnc) * 100, 1) : 0;
                            $selE = in_array((int)$opE['id'], $enc['mis_opc'] ?? []);
                            $ganE = (int)$opE['votos'] === $opMaxEnc && $tvEnc > 0;
                        ?>
                        <label class="poll-option-row <?= $selE ? 'selected' : '' ?> <?= $ganE && $enc['ya_vote'] ? 'winner' : '' ?>"
                               data-opcion-id="<?= (int)$opE['id'] ?>"
                               onclick="selectInline(this, <?= (int)$enc['id'] ?>, <?= $enc['tipo'] === 'multiple' ? 'true' : 'false' ?>)">
                            <div class="poll-option-bg"
                                 style="width:<?= ($enc['ya_vote'] || $enc['cerrada']) ? $pctE.'%' : '0%' ?>"></div>
                            <input type="<?= $enc['tipo'] === 'multiple' ? 'checkbox' : 'radio' ?>"
                                   name="enc_<?= (int)$enc['id'] ?>"
                                   value="<?= (int)$opE['id'] ?>"
                                   <?= $selE ? 'checked' : '' ?>
                                   style="accent-color:<?= e($enc['color'] ?? 'var(--primary)') ?>">
                            <span class="poll-option-text"><?= e(truncateChars($opE['opcion'], 45)) ?></span>
                            <?php if ($enc['ya_vote'] || $enc['cerrada']): ?>
                            <span class="poll-option-pct"
                                  style="color:<?= e($enc['color'] ?? 'var(--primary)') ?>">
                                <?= $pctE ?>%
                            </span>
                            <?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                        <?php if (count($enc['opciones']) > 4): ?>
                        <a href="<?= APP_URL ?>/encuestas.php?slug=<?= e($enc['slug']) ?>"
                           style="font-size:.72rem;color:var(--text-muted);display:block;
                                  text-align:center;margin-top:4px">
                            +<?= count($enc['opciones']) - 4 ?> opciones más...
                        </a>
                        <?php endif; ?>
                        </div>
                    </div>

                    <div class="poll-card__footer">
                        <div class="poll-meta">
                            <span class="poll-meta-item">
                                <i class="bi bi-people-fill"></i>
                                <?= formatNumber($tvEnc) ?>
                            </span>
                            <span class="poll-meta-item">
                                <i class="bi bi-list-ul"></i>
                                <?= (int)$enc['num_opciones'] ?> opc.
                            </span>
                            <?php if ($enc['tipo'] === 'multiple'): ?>
                            <span class="poll-meta-item">
                                <i class="bi bi-check2-square"></i>
                                Múltiple
                            </span>
                            <?php endif; ?>
                        </div>

                        <?php if (!$enc['cerrada']): ?>
                            <?php if (!$enc['ya_vote']): ?>
                            <button onclick="submitInlineVoto(<?= (int)$enc['id'] ?>, this)"
                                    class="btn-vote"
                                    style="font-size:.72rem;padding:6px 14px;
                                           --accent-color:<?= e($enc['color'] ?? 'var(--primary)') ?>">
                                <i class="bi bi-check2"></i> Votar
                            </button>
                            <?php elseif ($enc['puede_cambiar_voto']): ?>
                            <button onclick="cambiarVotoInline(<?= (int)$enc['id'] ?>, this)"
                                    class="btn-change-vote"
                                    title="Cambiar mi voto">
                                <i class="bi bi-arrow-repeat"></i> Cambiar
                            </button>
                            <?php else: ?>
                            <a href="<?= APP_URL ?>/encuestas.php?slug=<?= e($enc['slug']) ?>"
                               class="btn-change-vote">
                                <i class="bi bi-eye"></i> Ver
                            </a>
                            <?php endif; ?>
                        <?php else: ?>
                        <a href="<?= APP_URL ?>/encuestas.php?slug=<?= e($enc['slug']) ?>"
                           style="font-size:.72rem;color:var(--text-muted);text-decoration:none">
                            Ver resultados →
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Paginación -->
            <?php if ($totalPaginas > 1): ?>
            <nav class="polls-pagination" aria-label="Paginación">
                <a href="?pag=<?= $pagina - 1 ?>&q=<?= urlencode($busq) ?>&cat=<?= $catFiltro ?>"
                   class="<?= $pagina <= 1 ? 'disabled' : '' ?>">‹ Ant</a>
                <?php for ($p = max(1, $pagina - 3); $p <= min($totalPaginas, $pagina + 3); $p++): ?>
                <a href="?pag=<?= $p ?>&q=<?= urlencode($busq) ?>&cat=<?= $catFiltro ?>"
                   class="<?= $p === $pagina ? 'current' : '' ?>">
                    <?= $p ?>
                </a>
                <?php endfor; ?>
                <a href="?pag=<?= $pagina + 1 ?>&q=<?= urlencode($busq) ?>&cat=<?= $catFiltro ?>"
                   class="<?= $pagina >= $totalPaginas ? 'disabled' : '' ?>">Sig ›</a>
            </nav>
            <?php endif; ?>
            <?php endif; ?>

        </div><!-- /contenido principal -->

        <!-- ── Sidebar ────────────────────────────────────────── -->
        <aside class="poll-sidebar">

            <!-- Categorías -->
            <?php if (!empty($categoriasDisponibles)): ?>
            <div class="poll-sidebar-widget">
                <div class="poll-sidebar-widget__header">
                    <i class="bi bi-grid-fill" style="color:var(--primary)"></i>
                    Categorías
                </div>
                <div class="poll-sidebar-widget__body" style="padding:8px 14px">
                    <a href="<?= APP_URL ?>/encuestas.php"
                       style="display:flex;align-items:center;justify-content:space-between;
                              padding:8px 4px;border-bottom:1px solid var(--border-color);
                              text-decoration:none;font-size:.82rem;
                              color:<?= $catFiltro === 0 ? 'var(--primary)' : 'var(--text-secondary)' ?>;
                              font-weight:<?= $catFiltro === 0 ? '700' : '500' ?>">
                        <span>Todas</span>
                        <span style="font-size:.7rem;background:var(--bg-surface-3);
                                     padding:2px 7px;border-radius:10px">
                            <?= formatNumber($statsGlobales['total']) ?>
                        </span>
                    </a>
                    <?php foreach ($categoriasDisponibles as $catD): ?>
                    <a href="<?= APP_URL ?>/encuestas.php?cat=<?= (int)$catD['id'] ?>"
                       style="display:flex;align-items:center;justify-content:space-between;
                              padding:8px 4px;border-bottom:1px solid var(--border-color);
                              text-decoration:none;font-size:.82rem;
                              color:<?= $catFiltro === (int)$catD['id'] ? 'var(--primary)' : 'var(--text-secondary)' ?>;
                              font-weight:<?= $catFiltro === (int)$catD['id'] ? '700' : '500' ?>">
                        <div style="display:flex;align-items:center;gap:7px">
                            <span style="width:8px;height:8px;border-radius:50%;
                                         background:<?= e($catD['color']) ?>;flex-shrink:0"></span>
                            <?= e($catD['nombre']) ?>
                        </div>
                        <span style="font-size:.7rem;background:var(--bg-surface-3);
                                     padding:2px 7px;border-radius:10px;color:var(--text-muted)">
                            <?= (int)$catD['total'] ?>
                        </span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- CTA participación -->
            <div class="poll-sidebar-widget">
                <div class="poll-sidebar-widget__header">
                    <i class="bi bi-lightbulb-fill" style="color:var(--warning)"></i>
                    ¿Sabías que...?
                </div>
                <div class="poll-sidebar-widget__body">
                    <p style="font-size:.82rem;color:var(--text-muted);line-height:1.5;margin-bottom:12px">
                        En nuestras encuestas puedes votar tanto como usuario registrado como anónimo.
                        ¡Además, si cambias de opinión, puedes actualizar tu voto!
                    </p>
                    <?php if (!$usuario): ?>
                    <a href="<?= APP_URL ?>/login.php"
                       style="display:block;text-align:center;padding:9px;
                              background:var(--primary);color:#fff;
                              border-radius:var(--border-radius-full);
                              font-size:.8rem;font-weight:700;text-decoration:none">
                        <i class="bi bi-person-check-fill"></i> Iniciar sesión
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Anuncio -->
            <?php if (!empty($anuncioSidebar)): ?>
            <div class="poll-sidebar-widget">
                <div class="poll-sidebar-widget__body">
                    <?= renderAnuncio($anuncioSidebar[0]) ?>
                </div>
            </div>
            <?php endif; ?>

        </aside>

    </div><!-- /polls-layout -->
</div><!-- /container -->

<?php endif; // modoDetalle ?>

<!-- Toast container -->
<div id="pollToastContainer"></div>

<!-- ============================================================
     JAVASCRIPT — LÓGICA DE VOTACIÓN
     ============================================================ -->
<script>
(function () {
'use strict';

const APP_URL   = '<?= APP_URL ?>';
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content
                || '<?= csrfToken() ?>';

// ── Mostrar toast ────────────────────────────────────────────
function showPollToast(msg, type = 'success', dur = 3500) {
    const icons = { success: 'check-circle-fill', error: 'exclamation-circle-fill', info: 'info-circle-fill' };
    const tc = document.getElementById('pollToastContainer');
    if (!tc) return;
    const t = document.createElement('div');
    t.className = 'poll-toast ' + type;
    t.innerHTML = `<i class="bi bi-${icons[type] || icons.info}"></i><span>${msg}</span>`;
    tc.appendChild(t);
    setTimeout(() => {
        t.style.opacity = '0';
        t.style.transform = 'translateY(10px)';
        t.style.transition = 'all .3s';
        setTimeout(() => t.remove(), 300);
    }, dur);
}
window.showPollToast = showPollToast;

// ── Seleccionar opción en vista de detalle ────────────────────
window.selectOpcion = function (label, esMultiple) {
    const form = document.getElementById('pollDetailForm');
    if (!form) return;
    const submitBtn = document.getElementById('submitBtn');

    if (!esMultiple) {
        // Radio: deseleccionar todas
        form.querySelectorAll('.poll-opt-detail').forEach(l => {
            l.classList.remove('selected-opt');
            const inp = l.querySelector('input');
            if (inp) inp.checked = false;
        });
    }
    label.classList.toggle('selected-opt');
    const inp = label.querySelector('input');
    if (inp) inp.checked = label.classList.contains('selected-opt');

    // Habilitar botón
    const haySeleccion = form.querySelectorAll('input:checked').length > 0;
    if (submitBtn) submitBtn.disabled = !haySeleccion;
};

// ── Submit voto en vista detalle ─────────────────────────────
window.submitVoto = async function (event, encuestaId) {
    event.preventDefault();
    const form = document.getElementById('pollDetailForm');
    if (!form) return;

    const checked = Array.from(form.querySelectorAll('input:checked'));
    if (checked.length === 0) {
        showPollToast('Por favor selecciona una opción', 'error');
        return;
    }

    const submitBtn = document.getElementById('submitBtn');
    const originalHTML = submitBtn?.innerHTML;
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="poll-loading"></span> Enviando...';
    }

    try {
        const opcionIds = checked.map(i => parseInt(i.value));
        const esMultiple = opcionIds.length > 1;

        let resp, data;

        if (esMultiple) {
            // Votar en múltiples opciones
            for (const opId of opcionIds) {
                resp = await fetch(APP_URL + '/ajax/handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ action: 'vote_poll', encuesta_id: encuestaId, opcion_id: opId }),
                });
            }
            // Obtener resultados finales
            resp = await fetch(APP_URL + '/ajax/handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action: 'get_poll_results', encuesta_id: encuestaId }),
            });
            data = await resp.json();
        } else {
            resp = await fetch(APP_URL + '/ajax/handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action: 'vote_poll', encuesta_id: encuestaId, opcion_id: opcionIds[0] }),
            });
            data = await resp.json();
        }

        if (data.success || data.opciones) {
            showPollToast('¡Voto registrado correctamente!', 'success');
            // Actualizar UI con resultados
            actualizarResultadosDetalle(data.opciones || data.data?.opciones, data.total_votos || data.data?.total_votos);
            // Ocultar botón de voto, mostrar mensaje
            const actions = document.getElementById('voteActions');
            if (actions) actions.innerHTML = `
                <div class="poll-voted-msg">
                    <i class="bi bi-check-circle-fill"></i>
                    ¡Voto registrado!
                    <?php if ($encuesta && $encuesta['puede_cambiar_voto']): ?>
                    <button onclick="habilitarCambioVoto()" class="btn-change-vote" style="margin-left:auto">
                        <i class="bi bi-pencil-fill"></i> Cambiar voto
                    </button>
                    <?php endif; ?>
                </div>`;
        } else {
            showPollToast(data.message || 'Error al registrar el voto', 'error');
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalHTML; }
        }
    } catch (err) {
        console.error(err);
        showPollToast('Error de conexión. Intenta de nuevo.', 'error');
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalHTML; }
    }
};

// ── Actualizar resultados en vista detalle ────────────────────
function actualizarResultadosDetalle(opciones, totalVotos) {
    if (!opciones) return;
    const tv = parseInt(totalVotos) || 0;
    const totalLabel = document.getElementById('totalVotesLabel');
    if (totalLabel) totalLabel.textContent = tv.toLocaleString();

    opciones.forEach(op => {
        const pct = tv > 0 ? Math.round((op.votos / tv) * 100 * 10) / 10 : 0;
        const label = document.querySelector(`.poll-opt-detail[data-opcion-id="${op.id}"]`);
        if (!label) return;
        // Actualizar/crear barra de fondo
        let bg = label.querySelector('.poll-opt-bg');
        if (!bg) {
            bg = document.createElement('div');
            bg.className = 'poll-opt-bg';
            label.prepend(bg);
        }
        bg.style.width = pct + '%';
        // Actualizar porcentaje
        let barDiv = label.querySelector('.poll-opt-bar');
        if (!barDiv) {
            barDiv = document.createElement('div');
            barDiv.className = 'poll-opt-bar';
            barDiv.innerHTML = `<span class="poll-opt-pct">${pct}%</span><span class="poll-opt-cnt">${op.votos} votos</span>`;
            label.appendChild(barDiv);
        } else {
            const pctEl = barDiv.querySelector('.poll-opt-pct');
            const cntEl = barDiv.querySelector('.poll-opt-cnt');
            if (pctEl) pctEl.textContent = pct + '%';
            if (cntEl) cntEl.textContent = op.votos.toLocaleString() + ' votos';
        }
    });
}

// ── Habilitar cambio de voto ──────────────────────────────────
window.habilitarCambioVoto = function () {
    const form = document.getElementById('pollDetailForm');
    if (!form) return;
    // Habilitar inputs
    form.querySelectorAll('input').forEach(i => { i.disabled = false; });
    form.querySelectorAll('.poll-opt-detail').forEach(l => {
        l.classList.remove('disabled-opt');
        l.style.cursor = 'pointer';
    });
    // Mostrar botones
    const actions = document.getElementById('voteActions');
    if (actions) actions.innerHTML = `
        <button type="submit" class="btn-vote" id="submitBtn"
                style="--accent-color:<?= e($encuesta['color'] ?? 'var(--primary)') ?>">
            <i class="bi bi-arrow-repeat"></i> Confirmar cambio
        </button>
        <button type="button" class="btn-change-vote" onclick="cancelarCambio()">Cancelar</button>
    `;
    // Ocultar mensaje de "ya votaste"
    const msg = document.getElementById('votedMsg');
    if (msg) msg.style.display = 'none';
    showPollToast('Selecciona una nueva opción y confirma tu voto', 'info');
};

window.cancelarCambio = function () { location.reload(); };

// ── Seleccionar en tarjetas del listado ───────────────────────
window.selectInline = function (label, encuestaId, esMultiple) {
    const card = label.closest('[data-encuesta-id]');
    if (!card) return;
    const yaVoto = card.querySelector('.status-votada');
    const puedeC = card.dataset.puedeC === '1' || card.dataset.puedeCambiar === '1';
    if (yaVoto && !puedeC) return;

    if (!esMultiple) {
        card.querySelectorAll('.poll-option-row').forEach(r => {
            r.classList.remove('selected');
            const inp = r.querySelector('input');
            if (inp) inp.checked = false;
        });
    }
    label.classList.toggle('selected');
    const inp = label.querySelector('input');
    if (inp) inp.checked = label.classList.contains('selected');
};

// ── Votar desde tarjeta del listado ──────────────────────────
window.submitInlineVoto = async function (encuestaId, btn) {
    const card = document.querySelector(`[data-encuesta-id="${encuestaId}"]`);
    if (!card) return;

    const checked = Array.from(card.querySelectorAll('input:checked'));
    if (checked.length === 0) {
        showPollToast('Selecciona una opción primero', 'error');
        return;
    }

    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="poll-loading"></span>';

    try {
        const opcionIds = checked.map(i => parseInt(i.value));
        let data;

        for (const opId of opcionIds) {
            const res = await fetch(APP_URL + '/ajax/handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action: 'vote_poll', encuesta_id: encuestaId, opcion_id: opId }),
            });
            data = await res.json();
        }

        if (data.success) {
            showPollToast('¡Voto registrado!', 'success');
            // Actualizar barras de la tarjeta
            const opciones = data.opciones || [];
            const totalVotos = data.total_votos || 0;
            opciones.forEach(op => {
                const pct = totalVotos > 0 ? Math.round((op.votos / totalVotos) * 100 * 10) / 10 : 0;
                const row = card.querySelector(`[data-opcion-id="${op.id}"]`);
                if (!row) return;
                row.classList.add(pct === Math.max(...opciones.map(o => parseFloat((totalVotos > 0 ? (o.votos/totalVotos)*100 : 0).toFixed(1)))) ? 'winner' : '');
                let bg = row.querySelector('.poll-option-bg');
                if (!bg) { bg = document.createElement('div'); bg.className = 'poll-option-bg'; row.prepend(bg); }
                bg.style.width = pct + '%';
                let pctEl = row.querySelector('.poll-option-pct');
                if (!pctEl) {
                    pctEl = document.createElement('span');
                    pctEl.className = 'poll-option-pct';
                    pctEl.style.cssText = `color:${card.style.getPropertyValue('--accent-color')||'var(--primary)'}`;
                    row.appendChild(pctEl);
                }
                pctEl.textContent = pct + '%';
            });
            // Cambiar estado del botón
            btn.innerHTML = '✓ Votado';
            btn.className = 'btn-change-vote';
            btn.disabled = false;
            // Actualizar badge de estado
            const statusEl = card.querySelector('.poll-card__status');
            if (statusEl) { statusEl.textContent = '✓ Votada'; statusEl.className = 'poll-card__status status-votada'; }
        } else {
            showPollToast(data.message || 'Error al votar', 'error');
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
    } catch (err) {
        console.error(err);
        showPollToast('Error de conexión', 'error');
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    }
};

// ── Cambiar voto desde tarjeta del listado ────────────────────
window.cambiarVotoInline = async function (encuestaId, btn) {
    const card = document.querySelector(`[data-encuesta-id="${encuestaId}"]`);
    if (!card) return;

    // Habilitar selección
    card.querySelectorAll('input').forEach(i => { i.disabled = false; i.checked = false; });
    card.querySelectorAll('.poll-option-row').forEach(r => r.classList.remove('selected', 'winner'));

    // Cambiar botón
    btn.innerHTML = '<i class="bi bi-check2"></i> Confirmar';
    btn.className = 'btn-vote';
    btn.style.setProperty('--accent-color', card.style.getPropertyValue('--accent-color') || 'var(--primary)');
    btn.onclick = function () { submitCambioVoto(encuestaId, btn); };

    showPollToast('Selecciona tu nueva opción', 'info');
};

window.submitCambioVoto = async function (encuestaId, btn) {
    const card = document.querySelector(`[data-encuesta-id="${encuestaId}"]`);
    if (!card) return;
    const checked = Array.from(card.querySelectorAll('input:checked'));
    if (checked.length === 0) { showPollToast('Selecciona una opción', 'error'); return; }

    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="poll-loading"></span>';

    try {
        const opcionIds = checked.map(i => parseInt(i.value));
        let data;

        // Primero eliminar votos anteriores
        const delRes = await fetch(APP_URL + '/ajax/handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: 'change_vote', encuesta_id: encuestaId, opcion_ids: opcionIds }),
        });
        data = await delRes.json();

        if (data.success) {
            showPollToast('¡Voto actualizado!', 'success');
            location.reload();
        } else {
            showPollToast(data.message || 'Error al cambiar voto', 'error');
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
    } catch (err) {
        showPollToast('Error de conexión', 'error');
        btn.disabled = false;
        btn.innerHTML = originalHTML;
    }
};

// ── Animar barras al cargar ───────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    setTimeout(function () {
        document.querySelectorAll('.poll-opt-bg, .poll-option-bg').forEach(function (el) {
            const pct = el.dataset.pct || el.style.width;
            el.style.width = '0%';
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    el.style.transition = 'width .8s cubic-bezier(.25,.46,.45,.94)';
                    el.style.width = pct;
                });
            });
        });
    }, 100);
});

})(); // IIFE
</script>

<?php
// Helper: ajustar tono del color para gradiente
function adjustColor(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    $r = min(255, (int)hexdec(substr($hex,0,2)) + 40);
    $g = min(255, (int)hexdec(substr($hex,2,2)) + 20);
    $b = min(255, (int)hexdec(substr($hex,4,2)) + 80);
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}
require_once __DIR__ . '/includes/footer.php';
?>