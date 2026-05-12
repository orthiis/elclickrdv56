<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Página de Podcasts
 * ============================================================
 * Archivo : podcasts.php
 * Tablas  : podcasts, categorias, usuarios
 * Versión : 2.0.0
 * ============================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// ── Parámetros de URL ─────────────────────────────────────────
$busqueda   = trim($_GET['q']         ?? '');
$filtCat    = (int)($_GET['cat']      ?? 0);
$filtTemp   = (int)($_GET['temporada']?? 0);
$ordenar    = trim($_GET['orden']     ?? 'recientes');
$epId       = (int)($_GET['ep']       ?? 0);
$pagActual  = max(1, (int)($_GET['pag'] ?? 1));
$porPagina  = 12;
$offset     = ($pagActual - 1) * $porPagina;

// ── Construir WHERE ───────────────────────────────────────────
$where  = ["p.activo = 1", "(p.fecha_publicacion IS NULL OR p.fecha_publicacion <= NOW())"];
$params = [];

if (!empty($busqueda)) {
    $where[]  = '(p.titulo LIKE ? OR p.descripcion LIKE ?)';
    $b = '%' . $busqueda . '%';
    $params[] = $b; $params[] = $b;
}
if ($filtCat > 0) {
    $where[]  = 'p.categoria_id = ?';
    $params[] = $filtCat;
}
if ($filtTemp > 0) {
    $where[]  = 'p.temporada = ?';
    $params[] = $filtTemp;
}

$whereStr = implode(' AND ', $where);

// ── Ordenación ────────────────────────────────────────────────
$orderBy = match($ordenar) {
    'populares'  => 'p.reproducciones DESC, p.fecha_creacion DESC',
    'destacados' => 'p.destacado DESC, p.fecha_creacion DESC',
    'duracion'   => 'p.duracion DESC',
    default      => 'p.fecha_creacion DESC',
};

// ── Total y resultados ────────────────────────────────────────
$totalPodcasts = (int)db()->fetchColumn(
    "SELECT COUNT(*) FROM podcasts p WHERE $whereStr",
    $params
);
$totalPaginas = (int)ceil($totalPodcasts / $porPagina);

$podcasts = db()->fetchAll(
    "SELECT p.id, p.titulo, p.slug, p.descripcion, p.url_audio,
            p.thumbnail, p.duracion, p.temporada, p.episodio,
            p.reproducciones, p.destacado, p.fecha_publicacion, p.fecha_creacion,
            c.nombre AS cat_nombre, c.color AS cat_color, c.slug AS cat_slug,
            u.nombre AS autor_nombre, u.avatar AS autor_avatar
     FROM podcasts p
     LEFT JOIN categorias c ON c.id = p.categoria_id
     LEFT JOIN usuarios   u ON u.id = p.autor_id
     WHERE $whereStr
     ORDER BY $orderBy
     LIMIT ? OFFSET ?",
    array_merge($params, [$porPagina, $offset])
);

// ── Categorías con podcasts ───────────────────────────────────
$categoriasPod = db()->fetchAll(
    "SELECT c.id, c.nombre, c.color, c.slug,
            COUNT(p.id) AS total
     FROM categorias c
     INNER JOIN podcasts p ON p.categoria_id = c.id
     WHERE p.activo = 1
     GROUP BY c.id
     ORDER BY total DESC, c.nombre ASC",
    []
);

// ── Temporadas disponibles ────────────────────────────────────
$temporadas = db()->fetchAll(
    "SELECT temporada, COUNT(*) AS total
     FROM podcasts WHERE activo = 1
     GROUP BY temporada ORDER BY temporada ASC",
    []
);

// ── Episodio destacado / más reciente ─────────────────────────
$epDestacado = db()->fetchOne(
    "SELECT p.*, c.nombre AS cat_nombre, c.color AS cat_color,
             u.nombre AS autor_nombre
     FROM podcasts p
     LEFT JOIN categorias c ON c.id = p.categoria_id
     LEFT JOIN usuarios   u ON u.id = p.autor_id
     WHERE p.activo = 1 AND (p.destacado = 1 OR 1=1)
       AND (p.fecha_publicacion IS NULL OR p.fecha_publicacion <= NOW())
     ORDER BY p.destacado DESC, p.fecha_creacion DESC
     LIMIT 1",
    []
);

// Si hay ?ep= específico, cargarlo como destacado
if ($epId > 0) {
    $epEspecifico = db()->fetchOne(
        "SELECT p.*, c.nombre AS cat_nombre, c.color AS cat_color,
                 u.nombre AS autor_nombre
         FROM podcasts p
         LEFT JOIN categorias c ON c.id = p.categoria_id
         LEFT JOIN usuarios   u ON u.id = p.autor_id
         WHERE p.id = ? AND p.activo = 1 LIMIT 1",
        [$epId]
    );
    if ($epEspecifico) $epDestacado = $epEspecifico;
}

// ── Episodios de la misma temporada que el destacado ──────────
$mismaTemporada = [];
if ($epDestacado) {
    $mismaTemporada = db()->fetchAll(
        "SELECT p.id, p.titulo, p.episodio, p.duracion, p.thumbnail, p.url_audio, p.reproducciones
         FROM podcasts p
         WHERE p.activo = 1 AND p.temporada = ?
           AND (p.fecha_publicacion IS NULL OR p.fecha_publicacion <= NOW())
         ORDER BY p.episodio ASC
         LIMIT 20",
        [(int)($epDestacado['temporada'] ?? 1)]
    );
}

// ── Stats globales ────────────────────────────────────────────
$statsTotal  = (int)db()->fetchColumn("SELECT COUNT(*) FROM podcasts WHERE activo = 1");
$statsReprod = (int)db()->fetchColumn("SELECT COALESCE(SUM(reproducciones),0) FROM podcasts WHERE activo = 1");
$maxTemp     = (int)db()->fetchColumn("SELECT COALESCE(MAX(temporada),0) FROM podcasts WHERE activo = 1");
$statsHoras  = db()->fetchColumn("SELECT COALESCE(SUM(duracion),0) FROM podcasts WHERE activo=1 AND duracion > 0");
$horasTotal  = $statsHoras > 0 ? round($statsHoras/3600, 1) : 0;

// ── Anuncios ──────────────────────────────────────────────────
$adHeader  = getAnuncios('header',  null, 1);
$adSidebar = getAnuncios('sidebar', null, 2);

// ── URL base de paginación ────────────────────────────────────
function podPagUrl(int $pag, array $extra = []): string {
    global $busqueda, $filtCat, $filtTemp, $ordenar;
    $p = array_filter([
        'q'         => $busqueda,
        'cat'       => $filtCat    ?: '',
        'temporada' => $filtTemp   ?: '',
        'orden'     => $ordenar !== 'recientes' ? $ordenar : '',
    ]);
    return APP_URL . '/podcasts.php?' . http_build_query(array_merge($p, ['pag' => $pag], $extra));
}

// ── Función duración ──────────────────────────────────────────
function fmtDur(int $seg): string {
    if ($seg <= 0) return '';
    $h = intdiv($seg, 3600);
    $m = intdiv($seg % 3600, 60);
    $s = $seg % 60;
    return $h > 0 ? "{$h}h {$m}m" : "{$m}:".str_pad("$s",2,'0',STR_PAD_LEFT);
}

// Incrementar reproducciones del episodio destacado (si hay)
if ($epDestacado && $epId > 0) {
    try {
        db()->execute("UPDATE podcasts SET reproducciones = reproducciones + 1 WHERE id = ?", [$epId]);
    } catch (\Throwable $e) {}
}

$pageTitle = $busqueda
    ? "Podcasts: \"$busqueda\" — " . Config::get('site_nombre', APP_NAME)
    : "Podcasts — " . Config::get('site_nombre', APP_NAME);
$metaDesc  = "Escucha todos los podcasts de " . Config::get('site_nombre', APP_NAME)
           . ". Episodios de noticias, entrevistas y análisis en audio.";

require_once __DIR__ . '/includes/header.php';
?>

<!-- ════════════════════════════════════════════════════════════
     ESTILOS ESPECÍFICOS DE PODCASTS.PHP
════════════════════════════════════════════════════════════ -->
<style>
/* ── Variables podcast ──────────────────────────────────────── */
:root { --pod-color: #7c3aed; --pod-light: rgba(124,58,237,.1); }

/* ── Hero / Player destacado ────────────────────────────────── */
.podcasts-hero {
    background: linear-gradient(135deg,#1a0533 0%,#2d0f5e 50%,#0f0a20 100%);
    padding: 48px 0 0;
    margin-bottom: 40px;
    overflow: hidden;
    position: relative;
}
.podcasts-hero::before {
    content: '';
    position: absolute; inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.02'%3E%3Ccircle cx='30' cy='30' r='20'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    pointer-events: none;
}
.hero-inner { display: grid; grid-template-columns: 1fr 360px; gap: 40px; align-items: end; padding: 0 24px; max-width: 1400px; margin: 0 auto; position: relative; z-index: 1; }
.hero-cover-wrap { position: relative; }
.hero-cover-img {
    width: 100%; aspect-ratio: 1; max-width: 280px; margin: 0 auto;
    display: block;
    border-radius: 20px; object-fit: cover;
    box-shadow: 0 20px 60px rgba(124,58,237,.4);
    animation: heroFloat 4s ease-in-out infinite;
}
@keyframes heroFloat { 0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)} }
.hero-cover-ring {
    position: absolute; inset: -12px; border-radius: 28px;
    border: 2px solid rgba(124,58,237,.3);
    animation: heroRing 3s ease-in-out infinite;
}
@keyframes heroRing { 0%,100%{opacity:.3;transform:scale(1)}50%{opacity:.7;transform:scale(1.02)} }
.hero-info { padding-bottom: 32px; }
.hero-podcast-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; border-radius: 20px;
    background: rgba(124,58,237,.3); color: #c4b5fd;
    font-size: .72rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .08em; margin-bottom: 12px;
    border: 1px solid rgba(124,58,237,.4);
}
.hero-podcast-title {
    font-family: var(--font-serif); font-size: clamp(1.5rem,3vw,2rem);
    font-weight: 800; color: #fff; line-height: 1.2; margin-bottom: 10px;
}
.hero-podcast-desc {
    font-size: .9rem; color: rgba(255,255,255,.65); line-height: 1.65;
    margin-bottom: 16px;
}
.hero-podcast-meta {
    display: flex; align-items: center; gap: 12px;
    flex-wrap: wrap; margin-bottom: 20px;
}
.hero-meta-item {
    display: flex; align-items: center; gap: 5px;
    font-size: .78rem; color: rgba(255,255,255,.5);
}
.hero-meta-item strong { color: rgba(255,255,255,.8); }

/* ── Player bar (fixed/sticky) ──────────────────────────────── */
.pod-player-bar {
    background: #1e0d3d; border-top: 1px solid rgba(124,58,237,.3);
    padding: 14px 24px;
    display: flex; align-items: center; gap: 16px;
    flex-wrap: wrap;
}
.pod-now-playing { display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1; }
.pod-now-thumb { width: 44px; height: 44px; border-radius: 8px; object-fit: cover; flex-shrink: 0; }
.pod-now-info { min-width: 0; }
.pod-now-title { font-size: .82rem; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pod-now-sub { font-size: .7rem; color: rgba(255,255,255,.5); }
.pod-center-ctrl { display: flex; flex-direction: column; align-items: center; gap: 6px; flex: 1; }
.pod-ctrl-row { display: flex; align-items: center; gap: 8px; }
.pod-ctrl-ico { background: none; border: none; color: rgba(255,255,255,.7); font-size: .85rem; cursor: pointer; padding: 5px; border-radius: 50%; transition: all .2s; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; }
.pod-ctrl-ico:hover { color: #fff; background: rgba(255,255,255,.1); }
.pod-main-ico { width: 40px; height: 40px; background: #7c3aed; color: #fff; font-size: 1rem; border-radius: 50%; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all .2s; }
.pod-main-ico:hover { background: #6d28d9; transform: scale(1.05); }
.pod-progress-row { display: flex; align-items: center; gap: 8px; width: 100%; max-width: 500px; }
.pod-time { font-size: .7rem; color: rgba(255,255,255,.5); white-space: nowrap; min-width: 36px; }
.pod-prog-track { flex: 1; height: 4px; background: rgba(255,255,255,.15); border-radius: 2px; cursor: pointer; position: relative; }
.pod-prog-fill { height: 100%; background: linear-gradient(to right,#7c3aed,#a855f7); border-radius: 2px; width: 0%; transition: width .1s linear; }
.pod-right-ctrl { display: flex; align-items: center; gap: 8px; }

/* ── Layout principal ───────────────────────────────────────── */
.podcasts-layout { display: grid; grid-template-columns: 1fr 300px; gap: 28px; max-width: 1400px; margin: 0 auto; padding: 0 24px 60px; }
.podcasts-main {}
.podcasts-sidebar {}

/* ── Filtros ────────────────────────────────────────────────── */
.pod-filters-bar {
    background: var(--bg-surface); border: 1px solid var(--border-color);
    border-radius: var(--border-radius-xl); padding: 16px 18px;
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    margin-bottom: 22px;
}
.pod-search { position: relative; flex: 1; min-width: 200px; }
.pod-search i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none; }
.pod-search input { width: 100%; padding: 9px 12px 9px 34px; border: 1px solid var(--border-color); border-radius: var(--border-radius); background: var(--bg-body); color: var(--text-primary); font-size: .83rem; }
.pod-search input:focus { outline: none; border-color: var(--pod-color); }
.pod-filter-sel { padding: 9px 12px; border: 1px solid var(--border-color); border-radius: var(--border-radius); background: var(--bg-body); color: var(--text-primary); font-size: .82rem; cursor: pointer; }

/* ── Temporada Tabs ─────────────────────────────────────────── */
.temp-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 20px; }
.temp-tab {
    padding: 6px 14px; border-radius: var(--border-radius-full);
    font-size: .78rem; font-weight: 600;
    border: 1px solid var(--border-color);
    text-decoration: none; color: var(--text-muted);
    transition: all .2s; background: var(--bg-body);
}
.temp-tab:hover { border-color: var(--pod-color); color: var(--pod-color); }
.temp-tab.active { background: var(--pod-color); border-color: var(--pod-color); color: #fff; }

/* ── Grid de episodios ──────────────────────────────────────── */
.episodes-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 18px; }
.episode-card {
    background: var(--bg-surface); border: 1px solid var(--border-color);
    border-radius: var(--border-radius-xl); overflow: hidden;
    transition: all .25s; cursor: pointer;
}
.episode-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); border-color: rgba(124,58,237,.3); }
.episode-card.playing { border-color: var(--pod-color); box-shadow: 0 0 0 2px rgba(124,58,237,.2); }
.episode-thumb-wrap { position: relative; aspect-ratio: 1; overflow: hidden; }
.episode-thumb-wrap img { width: 100%; height: 100%; object-fit: cover; transition: transform .3s; }
.episode-card:hover .episode-thumb-wrap img { transform: scale(1.05); }
.episode-play-overlay {
    position: absolute; inset: 0;
    background: rgba(0,0,0,.45);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; transition: opacity .25s;
}
.episode-card:hover .episode-play-overlay { opacity: 1; }
.episode-play-btn {
    width: 52px; height: 52px; border-radius: 50%;
    background: rgba(124,58,237,.9); color: #fff;
    border: none; font-size: 1.3rem; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: all .2s; box-shadow: 0 4px 20px rgba(124,58,237,.5);
}
.episode-play-btn:hover { transform: scale(1.1); }
.episode-dur-badge {
    position: absolute; bottom: 8px; right: 8px;
    background: rgba(0,0,0,.75); color: #fff;
    font-size: .65rem; font-weight: 700;
    padding: 3px 7px; border-radius: 4px;
}
.episode-body { padding: 14px; }
.episode-meta {
    display: flex; align-items: center; gap: 8px;
    flex-wrap: wrap; margin-bottom: 6px;
}
.ep-num { font-size: .68rem; font-weight: 700; color: var(--pod-color); background: var(--pod-light); padding: 2px 7px; border-radius: 10px; }
.ep-cat { font-size: .68rem; font-weight: 600; }
.ep-date { font-size: .67rem; color: var(--text-muted); margin-left: auto; }
.episode-title { font-weight: 700; color: var(--text-primary); font-size: .88rem; line-height: 1.35; margin-bottom: 6px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.episode-desc { font-size: .77rem; color: var(--text-muted); line-height: 1.55; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.episode-footer { display: flex; align-items: center; justify-content: space-between; padding: 0 14px 12px; }
.ep-reprod { font-size: .7rem; color: var(--text-muted); display: flex; align-items: center; gap: 4px; }
.ep-share-btn { display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: var(--bg-surface-2); color: var(--text-muted); border: none; cursor: pointer; font-size: .8rem; transition: all .2s; }
.ep-share-btn:hover { background: var(--pod-color); color: #fff; }

/* ── Mini player row ────────────────────────────────────────── */
.episode-mini-row { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-bottom: 1px solid var(--border-color); transition: all .2s; cursor: pointer; }
.episode-mini-row:last-child { border-bottom: none; }
.episode-mini-row:hover { background: var(--bg-surface-2); }
.episode-mini-row.playing { background: var(--pod-light); border-left: 3px solid var(--pod-color); }
.mini-num { width: 24px; text-align: center; font-size: .72rem; font-weight: 700; color: var(--text-muted); flex-shrink: 0; }
.mini-thumb { width: 44px; height: 44px; border-radius: var(--border-radius-sm); object-fit: cover; flex-shrink: 0; }
.mini-info { flex: 1; min-width: 0; }
.mini-title { font-size: .82rem; font-weight: 600; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mini-sub { font-size: .7rem; color: var(--text-muted); }
.mini-dur { font-size: .7rem; color: var(--text-muted); white-space: nowrap; }
.mini-play { width: 28px; height: 28px; border-radius: 50%; background: var(--bg-surface-2); border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: .75rem; transition: all .2s; flex-shrink: 0; }
.mini-play:hover { background: var(--pod-color); color: #fff; }

/* ── Sidebar ────────────────────────────────────────────────── */
.sidebar-card { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--border-radius-xl); overflow: hidden; margin-bottom: 20px; }
.sidebar-card__header { padding: 14px 16px; border-bottom: 1px solid var(--border-color); font-size: .8rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 7px; }
.sidebar-card__body { padding: 14px 16px; }

/* ── Stats banner ───────────────────────────────────────────── */
.pod-stats-banner { display: grid; grid-template-columns: repeat(4,1fr); gap: 1px; background: var(--border-color); border: 1px solid var(--border-color); border-radius: var(--border-radius-xl); overflow: hidden; margin-bottom: 24px; }
.pod-stat-item { background: var(--bg-surface); padding: 16px; text-align: center; }
.pod-stat-num { font-size: 1.5rem; font-weight: 900; color: var(--pod-color); }
.pod-stat-lbl { font-size: .7rem; color: var(--text-muted); margin-top: 2px; }

/* ── Paginación ─────────────────────────────────────────────── */
.pod-pag { display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 32px; flex-wrap: wrap; }
.pod-pag-btn { display: flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; padding: 0 8px; border: 1px solid var(--border-color); border-radius: var(--border-radius); font-size: .83rem; font-weight: 600; text-decoration: none; color: var(--text-secondary); transition: all .2s; }
.pod-pag-btn:hover { border-color: var(--pod-color); color: var(--pod-color); }
.pod-pag-btn.active { background: var(--pod-color); border-color: var(--pod-color); color: #fff; }
.pod-pag-btn.disabled { opacity: .4; pointer-events: none; }

/* ── Vista lista vs grid ────────────────────────────────────── */
.view-list .episodes-grid { grid-template-columns: 1fr; }
.view-list .episode-card { display: flex; flex-direction: row; }
.view-list .episode-thumb-wrap { width: 120px; aspect-ratio: 1; flex-shrink: 0; }
.view-list .episode-play-overlay { opacity: 1; background: rgba(0,0,0,.3); }

/* ── Responsive ─────────────────────────────────────────────── */
@media (max-width: 1100px) { .podcasts-layout { grid-template-columns: 1fr; } .podcasts-sidebar { display: grid; grid-template-columns: repeat(2,1fr); gap: 16px; } }
@media (max-width: 768px) { .hero-inner { grid-template-columns: 1fr; } .hero-cover-img { max-width: 200px; } .pod-stats-banner { grid-template-columns: repeat(2,1fr); } .pod-player-bar { padding: 10px 14px; } .pod-center-ctrl { flex: none; width: 100%; order: 3; } .podcasts-layout { padding: 0 14px 40px; } .pod-right-ctrl { display: none; } }
@media (max-width: 480px) { .episodes-grid { grid-template-columns: 1fr; } .pod-stats-banner { grid-template-columns: 1fr 1fr; } }

/* ── Waveform animado ───────────────────────────────────────── */
.eq-anim { display: inline-flex; gap: 2px; align-items: center; height: 16px; }
.eq-bar { width: 3px; background: var(--pod-color); border-radius: 2px; animation: eqBounce 1.2s ease-in-out infinite; }
.eq-bar:nth-child(1){animation-delay:0s}
.eq-bar:nth-child(2){animation-delay:.15s}
.eq-bar:nth-child(3){animation-delay:.3s}
.eq-bar:nth-child(4){animation-delay:.45s}
@keyframes eqBounce{0%,100%{height:4px}50%{height:14px}}
</style>

<!-- ════════════════════════════════════════════════════════════
     HERO — PLAYER DESTACADO
════════════════════════════════════════════════════════════ -->
<?php if ($epDestacado): ?>
<section class="podcasts-hero" aria-labelledby="pod-hero-title">
    <div class="hero-inner">

        <!-- Portada -->
        <div class="hero-cover-wrap" style="text-align:center">
            <?php
            $heroThumb = !empty($epDestacado['thumbnail'])
                ? (str_starts_with($epDestacado['thumbnail'],'http')
                    ? $epDestacado['thumbnail']
                    : APP_URL . '/' . $epDestacado['thumbnail'])
                : APP_URL . '/assets/images/default-news.jpg';
            ?>
            <div class="hero-cover-ring"></div>
            <img class="hero-cover-img"
                 src="<?= e($heroThumb) ?>"
                 alt="<?= e($epDestacado['titulo']) ?>"
                 id="heroThumbImg"
                 onerror="this.src='<?= APP_URL ?>/assets/images/default-news.jpg'">
        </div>

        <!-- Info + player -->
        <div class="hero-info">
            <div class="hero-podcast-badge">
                <i class="bi bi-mic-fill"></i> PODCAST
                <?php if (!empty($epDestacado['cat_nombre'])): ?>
                · <span style="color:<?= e($epDestacado['cat_color']) ?>"><?= e($epDestacado['cat_nombre']) ?></span>
                <?php endif; ?>
            </div>

            <h1 class="hero-podcast-title" id="pod-hero-title" id="heroTitle">
                <?= e($epDestacado['titulo']) ?>
            </h1>

            <?php if (!empty($epDestacado['descripcion'])): ?>
            <p class="hero-podcast-desc" id="heroDesc">
                <?= e(truncateChars($epDestacado['descripcion'], 160)) ?>
            </p>
            <?php endif; ?>

            <div class="hero-podcast-meta">
                <div class="hero-meta-item">
                    <i class="bi bi-collection-play" style="color:#a855f7"></i>
                    <span>Temporada <strong><?= (int)$epDestacado['temporada'] ?></strong></span>
                </div>
                <div class="hero-meta-item">
                    <i class="bi bi-hash" style="color:#a855f7"></i>
                    <span>Episodio <strong><?= (int)$epDestacado['episodio'] ?></strong></span>
                </div>
                <?php if ($epDestacado['duracion'] > 0): ?>
                <div class="hero-meta-item">
                    <i class="bi bi-clock" style="color:#a855f7"></i>
                    <strong><?= fmtDur((int)$epDestacado['duracion']) ?></strong>
                </div>
                <?php endif; ?>
                <?php if ($epDestacado['reproducciones'] > 0): ?>
                <div class="hero-meta-item">
                    <i class="bi bi-headphones" style="color:#a855f7"></i>
                    <strong><?= number_format((int)$epDestacado['reproducciones']) ?></strong> reproduc.
                </div>
                <?php endif; ?>
            </div>

            <!-- Botón reproducir -->
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                <button class="pod-main-ico"
                        style="width:52px;height:52px;font-size:1.3rem"
                        id="heroBigPlayBtn"
                        onclick="heroTogglePlay()"
                        aria-label="Reproducir">
                    <i class="bi bi-play-fill" id="heroBigPlayIcon"></i>
                </button>
                <div>
                    <div style="font-size:.8rem;color:rgba(255,255,255,.5)" id="heroStatusTxt">
                        Haz clic para reproducir
                    </div>
                    <div style="font-size:.7rem;color:rgba(255,255,255,.35)" id="heroTimeTxt">
                        <?php if ($epDestacado['duracion'] > 0): ?>
                        Duración: <?= fmtDur((int)$epDestacado['duracion']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="#episodes-list"
                   style="margin-left:auto;padding:10px 18px;border-radius:var(--border-radius-full);
                          border:1px solid rgba(255,255,255,.25);color:rgba(255,255,255,.8);
                          font-size:.82rem;font-weight:600;text-decoration:none;
                          transition:all .2s"
                   onmouseover="this.style.background='rgba(255,255,255,.1)'"
                   onmouseout="this.style.background='transparent'">
                    <i class="bi bi-list-ul"></i> Todos los episodios
                </a>
            </div>
        </div>
    </div>

    <!-- Audio oculto del hero -->
    <audio id="heroAudio" preload="none"
           ontimeupdate="heroTimeUpdate(this)"
           onloadedmetadata="heroDurUpdate(this)"
           onplay="heroPlayState(true)"
           onpause="heroPlayState(false)"
           onended="heroPlayState(false)">
        <source src="<?= e($epDestacado['url_audio']) ?>" type="audio/mpeg">
    </audio>

    <!-- Player bar inferior del hero -->
    <div class="pod-player-bar" id="heroPlayerBar">
        <!-- Now playing -->
        <div class="pod-now-playing" style="max-width:240px">
            <img class="pod-now-thumb" id="heroNowThumb"
                 src="<?= e($heroThumb) ?>" alt="<?= e($epDestacado['titulo']) ?>"
                 onerror="this.src='<?= APP_URL ?>/assets/images/default-news.jpg'">
            <div class="pod-now-info">
                <div class="pod-now-title" id="heroNowTitle"><?= e(truncateChars($epDestacado['titulo'],40)) ?></div>
                <div class="pod-now-sub">
                    T<?= (int)$epDestacado['temporada'] ?> · E<?= (int)$epDestacado['episodio'] ?>
                    <?php if (!empty($epDestacado['autor_nombre'])): ?>
                     · <?= e($epDestacado['autor_nombre']) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Controles -->
        <div class="pod-center-ctrl">
            <div class="pod-ctrl-row">
                <button class="pod-ctrl-ico" onclick="heroSkip(-15)" title="−15s">
                    <i class="bi bi-skip-backward-fill"></i>
                </button>
                <button class="pod-main-ico" id="heroBarPlayBtn" onclick="heroTogglePlay()">
                    <i class="bi bi-play-fill" id="heroBarIcon"></i>
                </button>
                <button class="pod-ctrl-ico" onclick="heroSkip(30)" title="+30s">
                    <i class="bi bi-skip-forward-fill"></i>
                </button>
                <button class="pod-ctrl-ico pod-speed-btn" id="heroSpeedBtn"
                        onclick="heroSpeedCycle(this)">
                    <span>1×</span>
                </button>
            </div>
            <div class="pod-progress-row">
                <span class="pod-time" id="heroCurTime">0:00</span>
                <div class="pod-prog-track" id="heroProg" onclick="heroSeek(event,this)">
                    <div class="pod-prog-fill" id="heroFill"></div>
                </div>
                <span class="pod-time" id="heroDurTime">
                    <?= $epDestacado['duracion'] > 0 ? fmtDur((int)$epDestacado['duracion']) : '—' ?>
                </span>
            </div>
        </div>

        <!-- Derecha: volumen + compartir -->
        <div class="pod-right-ctrl">
            <button class="pod-ctrl-ico" id="heroVolBtn" onclick="heroToggleMute()">
                <i class="bi bi-volume-up-fill" id="heroVolIco" style="color:rgba(255,255,255,.7)"></i>
            </button>
            <input type="range" style="width:70px;accent-color:#7c3aed;cursor:pointer"
                   id="heroVolSlider" min="0" max="1" step="0.05" value="1"
                   oninput="heroSetVol(this.value)">
            <button class="pod-ctrl-ico" onclick="shareEpisode('<?= e(APP_URL.'/podcasts.php?ep='.(int)$epDestacado['id']) ?>','<?= e(addslashes($epDestacado['titulo'])) ?>')"
                    title="Compartir">
                <i class="bi bi-share-fill" style="color:rgba(255,255,255,.7)"></i>
            </button>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════
     STATS BANNER
════════════════════════════════════════════════════════════ -->
<div class="container-fluid px-3 px-lg-4 mt-4">
    <div class="pod-stats-banner">
        <div class="pod-stat-item">
            <div class="pod-stat-num"><?= number_format($statsTotal) ?></div>
            <div class="pod-stat-lbl">Episodios</div>
        </div>
        <div class="pod-stat-item">
            <div class="pod-stat-num"><?= number_format($statsReprod) ?></div>
            <div class="pod-stat-lbl">Reproducciones</div>
        </div>
        <div class="pod-stat-item">
            <div class="pod-stat-num"><?= $maxTemp ?></div>
            <div class="pod-stat-lbl">Temporadas</div>
        </div>
        <div class="pod-stat-item">
            <div class="pod-stat-num"><?= $horasTotal ?>h</div>
            <div class="pod-stat-lbl">De Contenido</div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     LAYOUT PRINCIPAL
════════════════════════════════════════════════════════════ -->
<div class="podcasts-layout" id="episodes-list">

    <!-- ── COLUMNA PRINCIPAL ─────────────────────────────────── -->
    <div class="podcasts-main">

        <!-- Filtros -->
        <form method="GET" class="pod-filters-bar">
            <div class="pod-search">
                <i class="bi bi-search"></i>
                <input type="text" name="q" placeholder="Buscar episodios..."
                       value="<?= e($busqueda) ?>">
            </div>
            <select name="cat" class="pod-filter-sel" onchange="this.form.submit()">
                <option value="">Todas las categorías</option>
                <?php foreach ($categoriasPod as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $filtCat===$c['id']?'selected':''?>>
                    <?= e($c['nombre']) ?> (<?= $c['total'] ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <select name="orden" class="pod-filter-sel" onchange="this.form.submit()">
                <option value="recientes"  <?= $ordenar==='recientes' ?'selected':''?>>Más recientes</option>
                <option value="populares"  <?= $ordenar==='populares' ?'selected':''?>>Más escuchados</option>
                <option value="destacados" <?= $ordenar==='destacados'?'selected':''?>>Destacados</option>
                <option value="duracion"   <?= $ordenar==='duracion'  ?'selected':''?>>Más largos</option>
            </select>
            <div style="display:flex;gap:6px">
                <button type="submit" style="padding:9px 14px;border-radius:var(--border-radius);background:var(--pod-color);color:#fff;border:none;cursor:pointer;font-size:.82rem;font-weight:600;display:flex;align-items:center;gap:5px">
                    <i class="bi bi-funnel-fill"></i> Filtrar
                </button>
                <?php if ($busqueda || $filtCat || $filtTemp): ?>
                <a href="<?= APP_URL ?>/podcasts.php" style="padding:9px 12px;border-radius:var(--border-radius);border:1px solid var(--border-color);font-size:.82rem;text-decoration:none;color:var(--text-muted);background:var(--bg-body)">
                    <i class="bi bi-x-lg"></i>
                </a>
                <?php endif; ?>
            </div>
            <!-- Vista grid/lista -->
            <div style="margin-left:auto;display:flex;border:1px solid var(--border-color);border-radius:var(--border-radius);overflow:hidden">
                <button type="button" onclick="setEpView('grid')" id="btnGridView"
                        style="padding:8px 10px;border:none;background:var(--pod-color);color:#fff;cursor:pointer">
                    <i class="bi bi-grid-3x3-gap"></i>
                </button>
                <button type="button" onclick="setEpView('list')" id="btnListView"
                        style="padding:8px 10px;border:none;background:var(--bg-body);color:var(--text-muted);cursor:pointer">
                    <i class="bi bi-list-ul"></i>
                </button>
            </div>
        </form>

        <!-- Tabs de temporada -->
        <?php if (count($temporadas) > 1): ?>
        <div class="temp-tabs">
            <a href="<?= APP_URL ?>/podcasts.php<?= $busqueda?'?q='.urlencode($busqueda):'' ?>"
               class="temp-tab <?= $filtTemp===0?'active':'' ?>">
                <i class="bi bi-collection-play"></i> Todas
            </a>
            <?php foreach ($temporadas as $temp): ?>
            <a href="<?= APP_URL ?>/podcasts.php?temporada=<?= $temp['temporada'] ?><?= $busqueda?'&q='.urlencode($busqueda):'' ?>"
               class="temp-tab <?= $filtTemp===$temp['temporada']?'active':'' ?>">
                Temporada <?= $temp['temporada'] ?>
                <span style="font-size:.65rem;opacity:.7">(<?= $temp['total'] ?>)</span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Título de sección -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px">
            <h2 style="font-family:var(--font-serif);font-size:1.1rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px">
                <i class="bi bi-mic-fill" style="color:var(--pod-color)"></i>
                <?php if ($busqueda): ?>
                    Resultados para: "<?= e($busqueda) ?>"
                <?php elseif ($filtTemp): ?>
                    Temporada <?= $filtTemp ?>
                <?php else: ?>
                    Todos los episodios
                <?php endif; ?>
            </h2>
            <span style="font-size:.8rem;color:var(--text-muted)">
                <?= number_format($totalPodcasts) ?> episodio<?= $totalPodcasts!=1?'s':'' ?>
            </span>
        </div>

        <!-- Grid / Lista de episodios -->
        <?php if (empty($podcasts)): ?>
        <div style="padding:60px 20px;text-align:center;background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--border-radius-xl)">
            <i class="bi bi-mic-mute" style="font-size:3rem;color:var(--text-muted)"></i>
            <div style="font-size:1rem;font-weight:700;color:var(--text-primary);margin:12px 0 8px">
                No hay episodios
            </div>
            <div style="font-size:.85rem;color:var(--text-muted)">
                <?= $busqueda ? 'Sin resultados para tu búsqueda.' : 'Próximamente nuevos episodios.' ?>
            </div>
        </div>
        <?php else: ?>
        <div class="episodes-grid" id="episodesContainer">
            <?php foreach ($podcasts as $pod):
                $thumbP = !empty($pod['thumbnail'])
                    ? (str_starts_with($pod['thumbnail'],'http') ? $pod['thumbnail'] : APP_URL.'/'.$pod['thumbnail'])
                    : APP_URL.'/assets/images/default-news.jpg';
            ?>
            <div class="episode-card"
                 id="ep-<?= (int)$pod['id'] ?>"
                 data-audio="<?= e($pod['url_audio']) ?>"
                 data-titulo="<?= e($pod['titulo']) ?>"
                 data-desc="<?= e(truncateChars($pod['descripcion']??'',160)) ?>"
                 data-cover="<?= e($thumbP) ?>"
                 data-duracion="<?= (int)$pod['duracion'] ?>"
                 data-temporada="<?= (int)$pod['temporada'] ?>"
                 data-episodio="<?= (int)$pod['episodio'] ?>"
                 data-id="<?= (int)$pod['id'] ?>">
                <div class="episode-thumb-wrap">
                    <img src="<?= e($thumbP) ?>"
                         alt="<?= e($pod['titulo']) ?>"
                         loading="lazy"
                         onerror="this.src='<?= APP_URL ?>/assets/images/default-news.jpg'">
                    <div class="episode-play-overlay">
                        <button class="episode-play-btn"
                                onclick="event.stopPropagation();playEpisode(this.closest('.episode-card'))"
                                aria-label="Reproducir">
                            <i class="bi bi-play-fill ep-play-ico"></i>
                        </button>
                    </div>
                    <?php if ($pod['duracion'] > 0): ?>
                    <div class="episode-dur-badge"><?= fmtDur((int)$pod['duracion']) ?></div>
                    <?php endif; ?>
                    <?php if ($pod['destacado']): ?>
                    <div style="position:absolute;top:8px;left:8px;background:#7c3aed;color:#fff;font-size:.62rem;font-weight:700;padding:3px 7px;border-radius:4px">
                        ⭐ DESTACADO
                    </div>
                    <?php endif; ?>
                </div>
                <div class="episode-body" onclick="playEpisode(this.closest('.episode-card'))">
                    <div class="episode-meta">
                        <span class="ep-num">T<?= (int)$pod['temporada'] ?> · E<?= (int)$pod['episodio'] ?></span>
                        <?php if (!empty($pod['cat_nombre'])): ?>
                        <span class="ep-cat" style="color:<?= e($pod['cat_color']) ?>">
                            <?= e($pod['cat_nombre']) ?>
                        </span>
                        <?php endif; ?>
                        <span class="ep-date">
                            <?= !empty($pod['fecha_publicacion'])
                                ? date('d/m/Y', strtotime($pod['fecha_publicacion']))
                                : date('d/m/Y', strtotime($pod['fecha_creacion'])) ?>
                        </span>
                    </div>
                    <div class="episode-title"><?= e($pod['titulo']) ?></div>
                    <?php if (!empty($pod['descripcion'])): ?>
                    <div class="episode-desc"><?= e($pod['descripcion']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="episode-footer">
                    <div class="ep-reprod">
                        <i class="bi bi-headphones"></i>
                        <?= number_format((int)$pod['reproducciones']) ?>
                    </div>
                    <div style="display:flex;gap:4px">
                        <button class="ep-share-btn"
                                onclick="shareEpisode('<?= e(APP_URL.'/podcasts.php?ep='.(int)$pod['id']) ?>','<?= e(addslashes($pod['titulo'])) ?>')"
                                title="Compartir">
                            <i class="bi bi-share-fill"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Anuncio entre episodios -->
        <?php if (!empty($adMiddle ?? []) && Config::bool('ads_entre_noticias')): ?>
        <div style="margin:24px 0;text-align:center;
                    padding:16px;border:2px dashed var(--border-color);
                    border-radius:var(--border-radius-lg);
                    background:var(--bg-surface-2)">
            <?= renderAnuncio($adMiddle[0]) ?>
        </div>
        <?php endif; ?>

        <!-- Paginación -->
        <?php if ($totalPaginas > 1): ?>
        <div class="pod-pag">
            <a href="<?= podPagUrl($pagActual-1) ?>" class="pod-pag-btn <?= $pagActual<=1?'disabled':'' ?>">&laquo;</a>
            <?php for ($p=max(1,$pagActual-3); $p<=min($totalPaginas,$pagActual+3); $p++): ?>
            <a href="<?= podPagUrl($p) ?>" class="pod-pag-btn <?= $p===$pagActual?'active':'' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a href="<?= podPagUrl($pagActual+1) ?>" class="pod-pag-btn <?= $pagActual>=$totalPaginas?'disabled':'' ?>">&raquo;</a>
        </div>
        <?php endif; ?>
        <?php endif; // empty podcasts ?>

    </div><!-- /main -->

    <!-- ── SIDEBAR ──────────────────────────────────────────── -->
    <aside class="podcasts-sidebar">

        <!-- Episodios de la misma temporada -->
        <?php if (!empty($mismaTemporada) && $epDestacado): ?>
        <div class="sidebar-card">
            <div class="sidebar-card__header">
                <i class="bi bi-collection-play" style="color:var(--pod-color)"></i>
                Temporada <?= (int)$epDestacado['temporada'] ?>
            </div>
            <div style="max-height:380px;overflow-y:auto">
                <?php foreach ($mismaTemporada as $ep):
                    $tMini = !empty($ep['thumbnail'])
                        ? (str_starts_with($ep['thumbnail'],'http') ? $ep['thumbnail'] : APP_URL.'/'.$ep['thumbnail'])
                        : APP_URL.'/assets/images/default-news.jpg';
                    $esActivo = ((int)$ep['id'] === (int)$epDestacado['id']);
                ?>
                <div class="episode-mini-row <?= $esActivo?'playing':'' ?>"
                     data-audio="<?= e($ep['url_audio'] ?? '') ?>"
                     data-titulo="<?= e($ep['titulo']) ?>"
                     data-cover="<?= e($tMini) ?>"
                     data-duracion="<?= (int)$ep['duracion'] ?>"
                     data-id="<?= (int)$ep['id'] ?>"
                     onclick="playFromSidebar(this)">
                    <div class="mini-num">
                        <?php if ($esActivo): ?>
                        <span class="eq-anim">
                            <span class="eq-bar"></span>
                            <span class="eq-bar"></span>
                            <span class="eq-bar"></span>
                            <span class="eq-bar"></span>
                        </span>
                        <?php else: ?>
                        <?= (int)$ep['episodio'] ?>
                        <?php endif; ?>
                    </div>
                    <img class="mini-thumb" src="<?= e($tMini) ?>" alt="<?= e($ep['titulo']) ?>"
                         loading="lazy"
                         onerror="this.src='<?= APP_URL ?>/assets/images/default-news.jpg'">
                    <div class="mini-info">
                        <div class="mini-title"><?= e($ep['titulo']) ?></div>
                        <div class="mini-sub">
                            <?= number_format((int)$ep['reproducciones']) ?> reproduc.
                        </div>
                    </div>
                    <div class="mini-dur"><?= $ep['duracion']>0?fmtDur((int)$ep['duracion']):'' ?></div>
                    <button class="mini-play" onclick="event.stopPropagation();playFromSidebar(this.closest('.episode-mini-row'))">
                        <i class="bi bi-play-fill"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Categorías -->
        <?php if (!empty($categoriasPod)): ?>
        <div class="sidebar-card">
            <div class="sidebar-card__header">
                <i class="bi bi-folder" style="color:var(--pod-color)"></i>
                Categorías
            </div>
            <div class="sidebar-card__body" style="padding:10px 14px">
                <?php foreach ($categoriasPod as $cat): ?>
                <a href="<?= APP_URL ?>/podcasts.php?cat=<?= $cat['id'] ?>"
                   style="display:flex;align-items:center;justify-content:space-between;
                          padding:8px 0;border-bottom:1px solid var(--border-color);
                          text-decoration:none;transition:all .2s"
                   onmouseover="this.style.paddingLeft='6px'"
                   onmouseout="this.style.paddingLeft='0'">
                    <div style="display:flex;align-items:center;gap:8px">
                        <span style="width:8px;height:8px;border-radius:50%;background:<?= e($cat['color']) ?>;flex-shrink:0"></span>
                        <span style="font-size:.82rem;color:var(--text-secondary);font-weight:500"><?= e($cat['nombre']) ?></span>
                    </div>
                    <span style="font-size:.72rem;font-weight:700;color:var(--pod-color);
                                 background:var(--pod-light);padding:2px 7px;border-radius:10px">
                        <?= $cat['total'] ?>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Anuncio sidebar -->
        <?php if (!empty($adSidebar)): ?>
        <div class="sidebar-card">
            <div class="sidebar-card__body">
                <?= renderAnuncio($adSidebar[0]) ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Cómo suscribirse -->
        <div class="sidebar-card">
            <div class="sidebar-card__header">
                <i class="bi bi-bell-fill" style="color:var(--pod-color)"></i>
                Suscribirse
            </div>
            <div class="sidebar-card__body">
                <p style="font-size:.8rem;color:var(--text-muted);margin-bottom:12px;line-height:1.5">
                    Recibe notificaciones de nuevos episodios directamente.
                </p>
                <a href="<?= APP_URL ?>/index.php#newsletter"
                   style="display:flex;align-items:center;justify-content:center;gap:6px;
                          padding:10px;border-radius:var(--border-radius-lg);
                          background:var(--pod-color);color:#fff;
                          font-size:.82rem;font-weight:700;text-decoration:none;
                          transition:all .2s"
                   onmouseover="this.style.background='#6d28d9'"
                   onmouseout="this.style.background='var(--pod-color)'">
                    <i class="bi bi-envelope-fill"></i> Suscribirme
                </a>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:8px">
                    <a href="#" style="display:flex;align-items:center;justify-content:center;gap:5px;padding:8px;border-radius:var(--border-radius);border:1px solid var(--border-color);font-size:.72rem;font-weight:600;color:var(--text-muted);text-decoration:none">
                        <i class="bi bi-rss-fill"></i> RSS Feed
                    </a>
                    <a href="#" style="display:flex;align-items:center;justify-content:center;gap:5px;padding:8px;border-radius:var(--border-radius);border:1px solid var(--border-color);font-size:.72rem;font-weight:600;color:var(--text-muted);text-decoration:none">
                        <i class="bi bi-spotify"></i> Spotify
                    </a>
                </div>
            </div>
        </div>

    </aside>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<!-- ════════════════════════════════════════════════════════════
     JAVASCRIPT PODCAST PAGE
════════════════════════════════════════════════════════════ -->
<script>
/* ── Audio del hero ─────────────────────────────────────────── */
const heroAud = document.getElementById('heroAudio');
let heroSpeeds = [1,1.25,1.5,1.75,2];
let heroSpIdx  = 0;
let currentPlayingId = <?= $epDestacado ? (int)$epDestacado['id'] : 0 ?>;

function heroTogglePlay() {
    if (!heroAud) return;
    heroAud.paused ? heroAud.play() : heroAud.pause();
}
function heroPlayState(playing) {
    const icons = [
        document.getElementById('heroBigPlayIcon'),
        document.getElementById('heroBarIcon')
    ];
    icons.forEach(ic => {
        if (ic) ic.className = playing ? 'bi bi-pause-fill' : 'bi bi-play-fill';
    });
    const statusTxt = document.getElementById('heroStatusTxt');
    if (statusTxt) statusTxt.textContent = playing ? 'Reproduciendo...' : 'Pausado';
    updateEqAnims(playing);
    updateCardPlayingState(currentPlayingId, playing);
}
function heroTimeUpdate(a) {
    if (!a.duration) return;
    const pct = (a.currentTime / a.duration) * 100;
    const fill = document.getElementById('heroFill');
    if (fill) fill.style.width = pct + '%';
    const cur = document.getElementById('heroCurTime');
    if (cur) cur.textContent = fmtTime(a.currentTime);
}
function heroDurUpdate(a) {
    const el = document.getElementById('heroDurTime');
    if (el && a.duration && !isNaN(a.duration)) el.textContent = fmtTime(a.duration);
    const ti = document.getElementById('heroTimeTxt');
    if (ti && a.duration) ti.textContent = 'Duración: ' + fmtTime(a.duration);
}
function heroSeek(e, bar) {
    if (!heroAud?.duration) return;
    const rect = bar.getBoundingClientRect();
    heroAud.currentTime = ((e.clientX - rect.left) / rect.width) * heroAud.duration;
}
function heroSkip(s) { if (heroAud) heroAud.currentTime += s; }
function heroSetVol(v) {
    if (heroAud) heroAud.volume = v;
    const ic = document.getElementById('heroVolIco');
    if (ic) ic.className = v == 0 ? 'bi bi-volume-mute-fill' : v < 0.4 ? 'bi bi-volume-down-fill' : 'bi bi-volume-up-fill';
}
function heroToggleMute() {
    if (!heroAud) return;
    heroAud.muted = !heroAud.muted;
    const ic = document.getElementById('heroVolIco');
    if (ic) ic.className = heroAud.muted ? 'bi bi-volume-mute-fill' : 'bi bi-volume-up-fill';
}
function heroSpeedCycle(btn) {
    heroSpIdx = (heroSpIdx + 1) % heroSpeeds.length;
    const sp  = heroSpeeds[heroSpIdx];
    if (heroAud) heroAud.playbackRate = sp;
    btn.querySelector('span').textContent = sp + '×';
}
function fmtTime(s) {
    const m = Math.floor(s/60), sec = Math.floor(s%60);
    return m + ':' + String(sec).padStart(2,'0');
}

/* ── Cambiar episodio ───────────────────────────────────────── */
function playEpisode(card) {
    if (!card || !heroAud) return;
    const audio = card.dataset.audio;
    const titulo = card.dataset.titulo;
    const cover  = card.dataset.cover;
    const dur    = parseInt(card.dataset.duracion) || 0;
    const id     = parseInt(card.dataset.id) || 0;

    // Actualizar audio
    const wasPlaying = !heroAud.paused;
    heroAud.pause();
    heroAud.src = audio;

    // Actualizar UI hero
    const heroImg = document.getElementById('heroThumbImg');
    const heroNowThumb = document.getElementById('heroNowThumb');
    const heroNowTitle = document.getElementById('heroNowTitle');
    const heroTitle = document.getElementById('pod-hero-title');
    const heroFill = document.getElementById('heroFill');
    const heroCurTime = document.getElementById('heroCurTime');

    if (heroImg) heroImg.src = cover;
    if (heroNowThumb) heroNowThumb.src = cover;
    if (heroNowTitle) heroNowTitle.textContent = titulo.substring(0,40);
    if (heroFill) heroFill.style.width = '0%';
    if (heroCurTime) heroCurTime.textContent = '0:00';

    const durEl = document.getElementById('heroDurTime');
    if (durEl) durEl.textContent = dur > 0 ? fmtTime(dur) : '—';

    // Marcar card activa
    document.querySelectorAll('.episode-card').forEach(c => c.classList.remove('playing'));
    card.classList.add('playing');
    updateEqAnims(false);
    currentPlayingId = id;

    // Actualizar URL sin recargar
    if (id) {
        history.replaceState(null, '', '<?= APP_URL ?>/podcasts.php?ep=' + id);
        // Registrar reproducción
        fetch('<?= APP_URL ?>/ajax/handler.php', {
            method: 'POST',
            headers: { 'Content-Type':'application/json', 'X-Requested-With':'XMLHttpRequest' },
            body: JSON.stringify({ action:'track_podcast_play', podcast_id: id })
        }).catch(()=>{});
    }

    if (wasPlaying || true) heroAud.play().catch(()=>{});
}

function playFromSidebar(row) {
    if (!row || !heroAud) return;
    const audio  = row.dataset.audio;
    const titulo = row.dataset.titulo;
    const cover  = row.dataset.cover;
    const dur    = parseInt(row.dataset.duracion)||0;
    const id     = parseInt(row.dataset.id)||0;

    heroAud.pause();
    heroAud.src = audio;

    const heroImg = document.getElementById('heroThumbImg');
    const nowThumb = document.getElementById('heroNowThumb');
    const nowTitle = document.getElementById('heroNowTitle');
    if (heroImg)  heroImg.src = cover;
    if (nowThumb) nowThumb.src = cover;
    if (nowTitle) nowTitle.textContent = titulo.substring(0,40);

    const fill = document.getElementById('heroFill');
    const cur  = document.getElementById('heroCurTime');
    const durEl= document.getElementById('heroDurTime');
    if (fill)  fill.style.width='0%';
    if (cur)   cur.textContent='0:00';
    if (durEl) durEl.textContent = dur>0?fmtTime(dur):'—';

    document.querySelectorAll('.episode-mini-row').forEach(r => r.classList.remove('playing'));
    row.classList.add('playing');
    currentPlayingId = id;
    heroAud.play().catch(()=>{});
}

/* ── Animaciones EQ ─────────────────────────────────────────── */
function updateEqAnims(playing) {
    document.querySelectorAll('.eq-bar').forEach(b => {
        b.style.animationPlayState = playing ? 'running' : 'paused';
    });
}

function updateCardPlayingState(id, playing) {
    document.querySelectorAll('.episode-card').forEach(c => {
        if (parseInt(c.dataset.id) === id) {
            c.classList.toggle('playing', playing);
            const ico = c.querySelector('.ep-play-ico');
            if (ico) ico.className = playing ? 'bi bi-pause-fill ep-play-ico' : 'bi bi-play-fill ep-play-ico';
        }
    });
}

/* ── Vista grid / lista ─────────────────────────────────────── */
function setEpView(v) {
    const cont  = document.getElementById('episodesContainer');
    const btnG  = document.getElementById('btnGridView');
    const btnL  = document.getElementById('btnListView');
    if (!cont) return;
    if (v === 'list') {
        cont.closest('.podcasts-main').classList.add('view-list');
        if (btnL) { btnL.style.background='var(--pod-color)'; btnL.style.color='#fff'; }
        if (btnG) { btnG.style.background='var(--bg-body)'; btnG.style.color='var(--text-muted)'; }
    } else {
        cont.closest('.podcasts-main').classList.remove('view-list');
        if (btnG) { btnG.style.background='var(--pod-color)'; btnG.style.color='#fff'; }
        if (btnL) { btnL.style.background='var(--bg-body)'; btnL.style.color='var(--text-muted)'; }
    }
}

/* ── Compartir ──────────────────────────────────────────────── */
function shareEpisode(url, titulo) {
    if (navigator.share) {
        navigator.share({ title: titulo, url: url }).catch(()=>{});
    } else {
        navigator.clipboard.writeText(url).then(() => {
            showToast('URL copiada al portapapeles', 'success', 2500);
        }).catch(()=>{});
    }
}

/* ── Toast ─────────────────────────────────────────────────── */
function showToast(msg, type='info', ms=3000) {
    let c = document.getElementById('_toastC');
    if (!c) {
        c = document.createElement('div');
        c.id = '_toastC';
        c.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px';
        document.body.appendChild(c);
    }
    const t = document.createElement('div');
    t.style.cssText = 'padding:12px 18px;border-radius:12px;background:#1e0d3d;color:#fff;font-size:.85rem;min-width:220px;display:flex;align-items:center;gap:8px;box-shadow:0 8px 32px rgba(0,0,0,.3);animation:fadeInUp .3s ease';
    const border = type==='success'?'4px solid #22c55e':type==='error'?'4px solid #ef4444':'4px solid #7c3aed';
    t.style.borderLeft = border;
    t.innerHTML = msg;
    c.appendChild(t);
    setTimeout(()=>{ t.style.opacity='0'; t.style.transition='.3s'; }, ms-300);
    setTimeout(()=>t.remove(), ms);
}

const _fadeStyle = document.createElement('style');
_fadeStyle.textContent = '@keyframes fadeInUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}';
document.head.appendChild(_fadeStyle);
</script>