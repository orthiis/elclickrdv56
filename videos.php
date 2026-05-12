<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Página de Videos
 * ============================================================
 * Archivo : videos.php
 * Tablas  : videos, categorias, usuarios
 * Versión : 2.0.0
 * ============================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// ── Parámetros ────────────────────────────────────────────────
$tipo       = trim($_GET['tipo']      ?? '');   // youtube|mp4|vimeo|embed|''
$filtCat    = (int)($_GET['cat']      ?? 0);
$busqueda   = trim($_GET['q']         ?? '');
$ordenar    = trim($_GET['orden']     ?? 'recientes');
$vidId      = (int)($_GET['v']        ?? 0);
$pagActual  = max(1, (int)($_GET['pag'] ?? 1));
$porPagina  = 16;
$offset     = ($pagActual - 1) * $porPagina;

if (!in_array($tipo, ['youtube','mp4','vimeo','embed',''])) $tipo = '';

// ── WHERE builder ─────────────────────────────────────────────
$where  = ["v.activo = 1", "(v.fecha_publicacion IS NULL OR v.fecha_publicacion <= NOW())"];
$params = [];

if (!empty($tipo)) {
    $where[]  = 'v.tipo = ?';
    $params[] = $tipo;
}
if ($filtCat > 0) {
    $where[]  = 'v.categoria_id = ?';
    $params[] = $filtCat;
}
if (!empty($busqueda)) {
    $where[]  = '(v.titulo LIKE ? OR v.descripcion LIKE ?)';
    $b = '%'.$busqueda.'%';
    $params[] = $b; $params[] = $b;
}
$whereStr = implode(' AND ', $where);

// ── Orden ─────────────────────────────────────────────────────
$orderBy = match($ordenar) {
    'populares'  => 'v.vistas DESC, v.fecha_creacion DESC',
    'destacados' => 'v.destacado DESC, v.fecha_creacion DESC',
    'alfabetico' => 'v.titulo ASC',
    'duracion'   => 'v.duracion DESC',
    default      => 'v.fecha_creacion DESC',
};

// ── Video activo (para el player principal) ───────────────────
$videoActivo = null;
if ($vidId > 0) {
    $videoActivo = db()->fetchOne(
        "SELECT v.*, c.nombre AS cat_nombre, c.color AS cat_color, c.slug AS cat_slug,
                u.nombre AS autor_nombre
         FROM videos v
         LEFT JOIN categorias c ON c.id = v.categoria_id
         LEFT JOIN usuarios   u ON u.id = v.autor_id
         WHERE v.id = ? AND v.activo = 1 LIMIT 1",
        [$vidId]
    );
    if ($videoActivo) {
        db()->execute("UPDATE videos SET vistas = vistas + 1 WHERE id = ?", [$vidId]);
    }
}
if (!$videoActivo) {
    $videoActivo = db()->fetchOne(
        "SELECT v.*, c.nombre AS cat_nombre, c.color AS cat_color, c.slug AS cat_slug,
                u.nombre AS autor_nombre
         FROM videos v
         LEFT JOIN categorias c ON c.id = v.categoria_id
         LEFT JOIN usuarios   u ON u.id = v.autor_id
         WHERE v.activo = 1 AND (v.fecha_publicacion IS NULL OR v.fecha_publicacion <= NOW())
         ORDER BY v.destacado DESC, v.vistas DESC, v.fecha_creacion DESC LIMIT 1",
        []
    );
}

// ── Total + videos ─────────────────────────────────────────────
$total      = (int)db()->fetchColumn("SELECT COUNT(*) FROM videos v WHERE $whereStr", $params);
$totalPags  = (int)ceil($total / $porPagina);

$videos = db()->fetchAll(
    "SELECT v.id, v.titulo, v.slug, v.descripcion, v.tipo, v.url,
            v.thumbnail, v.duracion, v.vistas, v.destacado, v.autoplay,
            v.fecha_publicacion, v.fecha_creacion,
            c.nombre AS cat_nombre, c.color AS cat_color,
            u.nombre AS autor_nombre
     FROM videos v
     LEFT JOIN categorias c ON c.id = v.categoria_id
     LEFT JOIN usuarios   u ON u.id = v.autor_id
     WHERE $whereStr
     ORDER BY $orderBy
     LIMIT ? OFFSET ?",
    array_merge($params, [$porPagina, $offset])
);

// ── Videos por categoría (para la sección de categorías) ──────
$videosPorCat = db()->fetchAll(
    "SELECT c.id, c.nombre, c.color, c.slug,
            COUNT(v.id) AS total,
            MAX(v.fecha_creacion) AS ultimo
     FROM categorias c
     INNER JOIN videos v ON v.categoria_id = c.id AND v.activo = 1
     GROUP BY c.id
     ORDER BY total DESC",
    []
);

// ── Stats rápidas ─────────────────────────────────────────────
$statsYT    = (int)db()->fetchColumn("SELECT COUNT(*) FROM videos WHERE activo=1 AND tipo='youtube'");
$statsMp4   = (int)db()->fetchColumn("SELECT COUNT(*) FROM videos WHERE activo=1 AND tipo='mp4'");
$statsTotal = (int)db()->fetchColumn("SELECT COUNT(*) FROM videos WHERE activo=1");
$statsVistas= (int)db()->fetchColumn("SELECT COALESCE(SUM(vistas),0) FROM videos WHERE activo=1");

// ── Categorías con videos para filtros ────────────────────────
$categorias = db()->fetchAll(
    "SELECT DISTINCT c.id, c.nombre, c.color
     FROM categorias c
     INNER JOIN videos v ON v.categoria_id = c.id AND v.activo = 1
     ORDER BY c.nombre",
    []
);

// ── Videos relacionados (misma categoría que el activo) ───────
$relacionados = [];
if ($videoActivo && $videoActivo['categoria_id']) {
    $relacionados = db()->fetchAll(
        "SELECT v.id, v.titulo, v.tipo, v.url, v.thumbnail, v.duracion, v.vistas
         FROM videos v
         WHERE v.activo = 1 AND v.categoria_id = ? AND v.id != ?
           AND (v.fecha_publicacion IS NULL OR v.fecha_publicacion <= NOW())
         ORDER BY v.fecha_creacion DESC LIMIT 8",
        [(int)$videoActivo['categoria_id'], (int)$videoActivo['id']]
    );
}

// ── Funciones helper ──────────────────────────────────────────
function getEmbed(array $v): string {
    return match($v['tipo']) {
        'youtube' => 'https://www.youtube.com/embed/' . extractYoutubeId($v['url'])
                     . '?rel=0&modestbranding=1&enablejsapi=1',
        'vimeo'   => 'https://player.vimeo.com/video/' . extractVimeoId($v['url']),
        'mp4'     => str_starts_with($v['url'],'http') ? $v['url'] : APP_URL.'/'.$v['url'],
        default   => $v['url'],
    };
}
function getThumb(array $v): string {
    if (!empty($v['thumbnail'])) {
        return str_starts_with($v['thumbnail'],'http')
            ? $v['thumbnail']
            : APP_URL . '/' . $v['thumbnail'];
    }
    if ($v['tipo'] === 'youtube') {
        return 'https://img.youtube.com/vi/' . extractYoutubeId($v['url']) . '/mqdefault.jpg';
    }
    return APP_URL . '/assets/images/default-news.jpg';
}
function fmtVidDur(int $s): string {
    if ($s<=0) return '';
    $h=intdiv($s,3600); $m=intdiv($s%3600,60); $sec=$s%60;
    return $h>0 ? sprintf('%d:%02d:%02d',$h,$m,$sec) : sprintf('%d:%02d',$m,$sec);
}
function vidPagUrl(int $pag): string {
    global $tipo,$filtCat,$busqueda,$ordenar;
    $p=array_filter(['tipo'=>$tipo,'cat'=>$filtCat?:'','q'=>$busqueda,'orden'=>$ordenar!=='recientes'?$ordenar:'']);
    return APP_URL.'/videos.php?'.http_build_query(array_merge($p,['pag'=>$pag]));
}
function tipoLabel(string $t): string {
    return match($t) { 'youtube'=>'YouTube','mp4'=>'MP4','vimeo'=>'Vimeo','embed'=>'Embed', default=>ucfirst($t) };
}
function tipoBadgeClass(string $t): string {
    return match($t) { 'youtube'=>'yt','mp4'=>'mp4','vimeo'=>'vim','embed'=>'emb', default=>'emb' };
}

$pageTitle = $busqueda
    ? "Videos: \"$busqueda\" — " . Config::get('site_nombre', APP_NAME)
    : "Videos — " . Config::get('site_nombre', APP_NAME);
$metaDesc  = "Explora todos los videos de " . Config::get('site_nombre', APP_NAME)
           . ". Noticias en video, entrevistas, YouTube y más.";

// ── Anuncios ──────────────────────────────────────────────────
$adHeader  = getAnuncios('header',          null, 1);
$adSidebar = getAnuncios('sidebar',         null, 2);
$adMiddle  = getAnuncios('entre_noticias',  null, 1);

$bodyClass = 'page-videos';


require_once __DIR__ . '/includes/header.php';
?>

<!-- ════════════════════════════════════════════════════════════
     ESTILOS ESPECÍFICOS DE VIDEOS.PHP
════════════════════════════════════════════════════════════ -->
<style>
/* ── Variables video ────────────────────────────────────────── */
:root { --vid-color: #e63946; --vid-yt: #cc0000; --vid-mp4: #0d6efd; --vid-vim: #1ab7ea; }

/* ── Hero section ───────────────────────────────────────────── */
.videos-hero {
    background: #0a0a0f;
    padding: 0;
    margin-bottom: 40px;
    position: relative;
    overflow: hidden;
}
.videos-hero::before {
    content: '';
    position: absolute; inset: 0;
    background: radial-gradient(ellipse at 20% 50%, rgba(230,57,70,.12) 0%, transparent 60%),
                radial-gradient(ellipse at 80% 50%, rgba(13,110,253,.08) 0%, transparent 60%);
    pointer-events: none;
}
.hero-player-layout { display: grid; grid-template-columns: 1fr 340px; min-height: 460px; position: relative; z-index: 1; max-width: 1400px; margin: 0 auto; }
.hero-player-main { padding: 24px; display: flex; flex-direction: column; }
.main-player-wrap { position: relative; aspect-ratio: 16/9; background: #000; border-radius: var(--border-radius-xl); overflow: hidden; flex: 1; max-height: 380px; }
.main-player-wrap iframe,
.main-player-wrap video { width: 100%; height: 100%; border: none; }
.main-player-info { padding: 14px 0 0; }
.main-vid-title { font-family: var(--font-serif); font-size: 1.15rem; font-weight: 800; color: #fff; line-height: 1.25; margin-bottom: 6px; }
.main-vid-meta { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.main-vid-meta-item { display: flex; align-items: center; gap: 4px; font-size: .75rem; color: rgba(255,255,255,.5); }
.main-vid-meta-item strong { color: rgba(255,255,255,.8); }

/* ── Playlist lateral ───────────────────────────────────────── */
.hero-playlist { display: flex; flex-direction: column; background: rgba(0,0,0,.4); border-left: 1px solid rgba(255,255,255,.05); }
.playlist-header { padding: 14px 16px; border-bottom: 1px solid rgba(255,255,255,.06); font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: rgba(255,255,255,.4); }
.playlist-scroll { flex: 1; overflow-y: auto; }
.playlist-scroll::-webkit-scrollbar { width: 3px; }
.playlist-scroll::-webkit-scrollbar-thumb { background: rgba(230,57,70,.4); }
.playlist-item {
    display: flex; gap: 10px; padding: 10px 14px;
    cursor: pointer; transition: all .2s;
    border-bottom: 1px solid rgba(255,255,255,.04);
    position: relative;
}
.playlist-item:hover { background: rgba(255,255,255,.05); }
.playlist-item.active { background: rgba(230,57,70,.12); border-left: 3px solid var(--vid-color); }
.playlist-thumb-wrap { position: relative; width: 72px; height: 50px; flex-shrink: 0; border-radius: 6px; overflow: hidden; }
.playlist-thumb-wrap img { width: 100%; height: 100%; object-fit: cover; }
.playlist-dur { position: absolute; bottom: 2px; right: 3px; background: rgba(0,0,0,.8); color: #fff; font-size: .58rem; padding: 1px 4px; border-radius: 3px; }
.playlist-info { flex: 1; min-width: 0; }
.playlist-title { font-size: .78rem; font-weight: 600; color: rgba(255,255,255,.85); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.3; }
.playlist-sub { font-size: .67rem; color: rgba(255,255,255,.35); margin-top: 3px; }

/* ── Tipo badges ────────────────────────────────────────────── */
.v-badge { display: inline-flex; align-items: center; gap: 3px; padding: 2px 7px; border-radius: 4px; font-size: .62rem; font-weight: 700; text-transform: uppercase; }
.v-badge.yt  { background: rgba(204,0,0,.15);    color: var(--vid-yt); }
.v-badge.mp4 { background: rgba(13,110,253,.15); color: var(--vid-mp4); }
.v-badge.vim { background: rgba(26,183,234,.15); color: var(--vid-vim); }
.v-badge.emb { background: rgba(107,114,128,.15);color: var(--text-muted); }

/* ── Tabs de tipo ───────────────────────────────────────────── */
.vid-type-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 22px; }
.vid-type-tab {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: var(--border-radius-full);
    font-size: .8rem; font-weight: 600;
    border: 1px solid var(--border-color);
    text-decoration: none; color: var(--text-muted);
    transition: all .2s; background: var(--bg-body);
}
.vid-type-tab:hover { border-color: var(--vid-color); color: var(--vid-color); }
.vid-type-tab.active { background: var(--vid-color); border-color: var(--vid-color); color: #fff; }
.vid-type-tab.yt.active  { background: var(--vid-yt);  border-color: var(--vid-yt); }
.vid-type-tab.mp4.active { background: var(--vid-mp4); border-color: var(--vid-mp4); }
.vid-type-tab.vim.active { background: var(--vid-vim); border-color: var(--vid-vim); }
.tab-count { font-size: .65rem; background: rgba(255,255,255,.25); padding: 1px 5px; border-radius: 10px; }

/* ── Stats bar ──────────────────────────────────────────────── */
.vid-stats-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 1px; background: var(--border-color); border: 1px solid var(--border-color); border-radius: var(--border-radius-xl); overflow: hidden; margin-bottom: 26px; }
.vid-stat { background: var(--bg-surface); padding: 14px 16px; text-align: center; }
.vid-stat-num { font-size: 1.3rem; font-weight: 900; color: var(--vid-color); }
.vid-stat-lbl { font-size: .7rem; color: var(--text-muted); margin-top: 2px; }

/* ── Filtros ────────────────────────────────────────────────── */
.vid-filters { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--border-radius-xl); padding: 14px 18px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 22px; }
.vid-search { position: relative; flex: 1; min-width: 180px; }
.vid-search i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none; }
.vid-search input { width: 100%; padding: 9px 12px 9px 34px; border: 1px solid var(--border-color); border-radius: var(--border-radius); background: var(--bg-body); color: var(--text-primary); font-size: .83rem; }
.vid-search input:focus { outline: none; border-color: var(--vid-color); }
.vid-sel { padding: 9px 12px; border: 1px solid var(--border-color); border-radius: var(--border-radius); background: var(--bg-body); color: var(--text-primary); font-size: .82rem; cursor: pointer; }

/* ── Grid de videos ─────────────────────────────────────────── */
.videos-layout { display: grid; grid-template-columns: 1fr 280px; gap: 28px; max-width: 1400px; margin: 0 auto; padding: 0 24px 60px; }
.videos-main {}
.videos-sidebar {}
.videos-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(270px, 1fr)); gap: 18px; }
.video-card {
    background: var(--bg-surface); border: 1px solid var(--border-color);
    border-radius: var(--border-radius-xl); overflow: hidden;
    cursor: pointer; transition: all .25s;
}
.video-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); border-color: rgba(230,57,70,.25); }
.video-card.playing { border-color: var(--vid-color); box-shadow: 0 0 0 2px rgba(230,57,70,.2); }
.video-thumb-wrap { position: relative; aspect-ratio: 16/9; overflow: hidden; background: #000; }
.video-thumb-wrap img { width: 100%; height: 100%; object-fit: cover; transition: transform .3s; }
.video-card:hover .video-thumb-wrap img { transform: scale(1.05); }
.video-play-overlay {
    position: absolute; inset: 0;
    background: rgba(0,0,0,.4);
    display: flex; align-items: center; justify-content: center;
    opacity: 0; transition: opacity .25s;
}
.video-card:hover .video-play-overlay { opacity: 1; }
.video-play-btn {
    width: 52px; height: 52px; border-radius: 50%;
    background: rgba(230,57,70,.9); color: #fff; border: none;
    font-size: 1.3rem; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 20px rgba(230,57,70,.5); transition: all .2s;
}
.video-play-btn:hover { transform: scale(1.1); }
.vid-dur-badge { position: absolute; bottom: 6px; right: 6px; background: rgba(0,0,0,.8); color: #fff; font-size: .62rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; }
.vid-type-corner { position: absolute; top: 6px; left: 6px; }
.video-body { padding: 12px 14px; }
.video-title { font-weight: 700; color: var(--text-primary); font-size: .85rem; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; margin-bottom: 6px; }
.video-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.vid-cat { font-size: .7rem; font-weight: 600; }
.vid-stat-sm { font-size: .7rem; color: var(--text-muted); display: flex; align-items: center; gap: 3px; }
.vid-date { font-size: .68rem; color: var(--text-muted); margin-left: auto; }

/* ── Sección por categorías ─────────────────────────────────── */
.cat-section { margin-bottom: 36px; }
.cat-section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 2px solid var(--border-color); }
.cat-section-title { display: flex; align-items: center; gap: 8px; font-family: var(--font-serif); font-size: 1rem; font-weight: 700; color: var(--text-primary); }
.cat-section-link { font-size: .78rem; font-weight: 600; color: var(--text-muted); text-decoration: none; display: flex; align-items: center; gap: 4px; }
.cat-section-link:hover { color: var(--vid-color); }
.cat-videos-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; }

/* ── Sidebar ────────────────────────────────────────────────── */
.vid-sidebar-card { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--border-radius-xl); overflow: hidden; margin-bottom: 20px; }
.vid-sidebar-header { padding: 12px 14px; border-bottom: 1px solid var(--border-color); font-size: .78rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 6px; }
.vid-sidebar-body { padding: 12px 14px; }
.related-item { display: flex; gap: 8px; padding: 8px 0; border-bottom: 1px solid var(--border-color); cursor: pointer; transition: all .2s; }
.related-item:last-child { border-bottom: none; }
.related-item:hover { opacity: .8; }
.related-thumb { width: 72px; height: 50px; border-radius: var(--border-radius-sm); object-fit: cover; flex-shrink: 0; }
.related-title { font-size: .78rem; font-weight: 600; color: var(--text-primary); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.3; }
.related-meta { font-size: .67rem; color: var(--text-muted); margin-top: 3px; }

/* ── Paginación ─────────────────────────────────────────────── */
.vid-pag { display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 28px; flex-wrap: wrap; }
.vid-pag-btn { display: flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; padding: 0 8px; border: 1px solid var(--border-color); border-radius: var(--border-radius); font-size: .83rem; font-weight: 600; text-decoration: none; color: var(--text-secondary); transition: all .2s; }
.vid-pag-btn:hover { border-color: var(--vid-color); color: var(--vid-color); }
.vid-pag-btn.active { background: var(--vid-color); border-color: var(--vid-color); color: #fff; }
.vid-pag-btn.disabled { opacity: .4; pointer-events: none; }

/* ── Responsive ─────────────────────────────────────────────── */
@media (max-width: 1100px) { .videos-layout { grid-template-columns: 1fr; } .videos-sidebar { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; } .cat-videos-row { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 768px) { .hero-player-layout { grid-template-columns: 1fr; } .hero-playlist { display: none; } .vid-stats-row { grid-template-columns: repeat(2,1fr); } .videos-layout { padding: 0 14px 40px; } .cat-videos-row { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 480px) { .videos-grid { grid-template-columns: 1fr; } .cat-videos-row { grid-template-columns: 1fr; } }

/* ── Modal video fullscreen ─────────────────────────────────── */
.vid-modal { position: fixed; inset: 0; background: rgba(0,0,0,.92); z-index: 1200; display: none; align-items: center; justify-content: center; padding: 20px; }
.vid-modal.open { display: flex; flex-direction: column; }
.vid-modal-close { position: absolute; top: 16px; right: 16px; width: 40px; height: 40px; background: rgba(255,255,255,.15); border: none; color: #fff; font-size: 1.1rem; cursor: pointer; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
.vid-modal-player { width: 100%; max-width: 900px; aspect-ratio: 16/9; }
.vid-modal-player iframe, .vid-modal-player video { width: 100%; height: 100%; border: none; border-radius: var(--border-radius-xl); }
.vid-modal-info { color: #fff; text-align: center; margin-top: 14px; max-width: 900px; width: 100%; }
.vid-modal-title { font-family: var(--font-serif); font-size: 1.1rem; font-weight: 700; }
.vid-modal-meta { font-size: .8rem; color: rgba(255,255,255,.5); margin-top: 4px; }
</style>

<!-- ════════════════════════════════════════════════════════════
     HERO — PLAYER PRINCIPAL
════════════════════════════════════════════════════════════ -->
<?php if ($videoActivo): ?>
<section class="videos-hero" aria-label="Player de video">
    <div class="hero-player-layout">

        <!-- Player -->
        <div class="hero-player-main">
            <div class="main-player-wrap" id="mainPlayerWrap">
                <?php if ($videoActivo['tipo'] === 'mp4'): ?>
                <video id="mainVideoEl"
                       src="<?= e(getEmbed($videoActivo)) ?>"
                       controls
                       <?= $videoActivo['autoplay'] ? 'autoplay muted' : '' ?>
                       poster="<?= e(getThumb($videoActivo)) ?>"
                       preload="metadata">
                    Tu navegador no soporta video HTML5.
                </video>
                <?php else: ?>
                <iframe id="mainVideoEl"
                        src="<?= e(getEmbed($videoActivo)) ?><?= $videoActivo['autoplay'] ? '&autoplay=1' : '' ?>"
                        title="<?= e($videoActivo['titulo']) ?>"
                        frameborder="0"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen
                        loading="lazy">
                </iframe>
                <?php endif; ?>
            </div>

            <div class="main-player-info">
                <h1 class="main-vid-title" id="mainVidTitle">
                    <?= e($videoActivo['titulo']) ?>
                </h1>
                <div class="main-vid-meta">
                    <span class="v-badge <?= tipoBadgeClass($videoActivo['tipo']) ?>">
                        <?= tipoLabel($videoActivo['tipo']) ?>
                    </span>
                    <?php if (!empty($videoActivo['cat_nombre'])): ?>
                    <span style="font-size:.75rem;font-weight:600;color:<?= e($videoActivo['cat_color']) ?>">
                        <?= e($videoActivo['cat_nombre']) ?>
                    </span>
                    <?php endif; ?>
                    <div class="main-vid-meta-item">
                        <i class="bi bi-eye"></i>
                        <strong><?= number_format((int)$videoActivo['vistas']) ?></strong>
                    </div>
                    <?php if ($videoActivo['duracion'] > 0): ?>
                    <div class="main-vid-meta-item">
                        <i class="bi bi-clock"></i>
                        <strong><?= fmtVidDur((int)$videoActivo['duracion']) ?></strong>
                    </div>
                    <?php endif; ?>
                    <div class="main-vid-meta-item" style="margin-left:auto">
                        <i class="bi bi-calendar3"></i>
                        <?= !empty($videoActivo['fecha_publicacion'])
                            ? date('d/m/Y', strtotime($videoActivo['fecha_publicacion']))
                            : date('d/m/Y', strtotime($videoActivo['fecha_creacion'])) ?>
                    </div>
                    <button onclick="shareVideo('<?= e(APP_URL.'/videos.php?v='.(int)$videoActivo['id']) ?>','<?= e(addslashes($videoActivo['titulo'])) ?>')"
                            style="padding:5px 12px;border-radius:var(--border-radius-full);border:1px solid rgba(255,255,255,.2);background:transparent;color:rgba(255,255,255,.7);font-size:.75rem;cursor:pointer;display:flex;align-items:center;gap:5px">
                        <i class="bi bi-share-fill"></i> Compartir
                    </button>
                </div>
                <?php if (!empty($videoActivo['descripcion'])): ?>
                <div style="margin-top:8px;font-size:.82rem;color:rgba(255,255,255,.5);line-height:1.6">
                    <?= e(truncateChars($videoActivo['descripcion'], 200)) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Playlist -->
        <div class="hero-playlist">
            <div class="playlist-header">
                <i class="bi bi-collection-play" style="color:var(--vid-color)"></i>
                Más videos
            </div>
            <div class="playlist-scroll" id="heroPlaylist">
                <?php
                $playlistVids = db()->fetchAll(
                    "SELECT v.id, v.titulo, v.tipo, v.url, v.thumbnail, v.duracion, v.vistas
                     FROM videos v
                     WHERE v.activo = 1
                       AND (v.fecha_publicacion IS NULL OR v.fecha_publicacion <= NOW())
                     ORDER BY v.destacado DESC, v.vistas DESC, v.fecha_creacion DESC
                     LIMIT 12",
                    []
                );
                foreach ($playlistVids as $pv):
                    $pThumb = getThumb($pv);
                    $isAct  = (int)$pv['id'] === (int)$videoActivo['id'];
                ?>
                <div class="playlist-item <?= $isAct?'active':'' ?>"
                     data-id="<?= (int)$pv['id'] ?>"
                     data-tipo="<?= e($pv['tipo']) ?>"
                     data-embed="<?= e(getEmbed($pv)) ?>"
                     data-titulo="<?= e($pv['titulo']) ?>"
                     data-vistas="<?= (int)$pv['vistas'] ?>"
                     onclick="playFromPlaylist(this)">
                    <div class="playlist-thumb-wrap">
                        <img src="<?= e($pThumb) ?>" alt="<?= e($pv['titulo']) ?>"
                             loading="lazy"
                             onerror="this.src='<?= APP_URL ?>/assets/images/default-news.jpg'">
                        <?php if ($pv['duracion'] > 0): ?>
                        <span class="playlist-dur"><?= fmtVidDur((int)$pv['duracion']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="playlist-info">
                        <div class="playlist-title"><?= e($pv['titulo']) ?></div>
                        <div class="playlist-sub">
                            <span class="v-badge <?= tipoBadgeClass($pv['tipo']) ?>" style="margin-right:4px"><?= tipoLabel($pv['tipo']) ?></span>
                            <?= number_format((int)$pv['vistas']) ?> vistas
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</section>
<?php endif; ?>

<!-- Stats -->
<div class="container-fluid px-3 px-lg-4 mt-4">
    <div class="vid-stats-row">
        <div class="vid-stat">
            <div class="vid-stat-num"><?= number_format($statsTotal) ?></div>
            <div class="vid-stat-lbl">Total Videos</div>
        </div>
        <div class="vid-stat">
            <div class="vid-stat-num" style="color:var(--vid-yt)"><?= number_format($statsYT) ?></div>
            <div class="vid-stat-lbl">YouTube</div>
        </div>
        <div class="vid-stat">
            <div class="vid-stat-num" style="color:var(--vid-mp4)"><?= number_format($statsMp4) ?></div>
            <div class="vid-stat-lbl">MP4/Embed</div>
        </div>
        <div class="vid-stat">
            <div class="vid-stat-num"><?= number_format($statsVistas) ?></div>
            <div class="vid-stat-lbl">Total Vistas</div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     LAYOUT PRINCIPAL
════════════════════════════════════════════════════════════ -->
<div class="videos-layout">

    <div class="videos-main">

        <!-- Tabs por tipo -->
        <div class="vid-type-tabs">
            <?php
            $tiposConfig = [
                '' => ['label'=>'Todos','icon'=>'bi-collection-play','cls'=>''],
                'youtube' => ['label'=>'YouTube','icon'=>'bi-youtube','cls'=>'yt'],
                'mp4'     => ['label'=>'MP4','icon'=>'bi-file-play','cls'=>'mp4'],
                'vimeo'   => ['label'=>'Vimeo','icon'=>'bi-vimeo','cls'=>'vim'],
                'embed'   => ['label'=>'Embed','icon'=>'bi-code-slash','cls'=>'emb'],
            ];
            foreach ($tiposConfig as $tk => $tv):
                $count = (int)db()->fetchColumn(
                    "SELECT COUNT(*) FROM videos WHERE activo=1" . ($tk ? " AND tipo='$tk'" : ''),
                    []
                );
                if ($count === 0 && $tk !== '') continue;
            ?>
            <a href="<?= APP_URL ?>/videos.php<?= $tk ? '?tipo='.$tk : '' ?>"
               class="vid-type-tab <?= $tv['cls'] ?> <?= $tipo===$tk?'active':'' ?>">
                <i class="bi <?= $tv['icon'] ?>"></i>
                <?= $tv['label'] ?>
                <span class="tab-count"><?= $count ?></span>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Filtros -->
        <form method="GET" class="vid-filters">
            <?php if ($tipo): ?>
            <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
            <?php endif; ?>
            <div class="vid-search">
                <i class="bi bi-search"></i>
                <input type="text" name="q" placeholder="Buscar videos..."
                       value="<?= e($busqueda) ?>">
            </div>
            <select name="cat" class="vid-sel" onchange="this.form.submit()">
                <option value="">Todas las categorías</option>
                <?php foreach ($categorias as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $filtCat===$c['id']?'selected':''?>>
                    <?= e($c['nombre']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="orden" class="vid-sel" onchange="this.form.submit()">
                <option value="recientes"  <?= $ordenar==='recientes' ?'selected':''?>>Más recientes</option>
                <option value="populares"  <?= $ordenar==='populares' ?'selected':''?>>Más vistos</option>
                <option value="destacados" <?= $ordenar==='destacados'?'selected':''?>>Destacados</option>
                <option value="duracion"   <?= $ordenar==='duracion'  ?'selected':''?>>Más largos</option>
                <option value="alfabetico" <?= $ordenar==='alfabetico'?'selected':''?>>A-Z</option>
            </select>
            <button type="submit" style="padding:9px 14px;border-radius:var(--border-radius);background:var(--vid-color);color:#fff;border:none;cursor:pointer;font-size:.82rem;font-weight:600;display:flex;align-items:center;gap:5px">
                <i class="bi bi-funnel-fill"></i> Filtrar
            </button>
            <?php if ($busqueda || $filtCat): ?>
            <a href="<?= APP_URL ?>/videos.php<?= $tipo?'?tipo='.$tipo:'' ?>" style="padding:9px 10px;border:1px solid var(--border-color);border-radius:var(--border-radius);font-size:.82rem;text-decoration:none;color:var(--text-muted);background:var(--bg-body)">
                <i class="bi bi-x-lg"></i>
            </a>
            <?php endif; ?>
            <!-- Vista -->
            <div style="margin-left:auto;display:flex;border:1px solid var(--border-color);border-radius:var(--border-radius);overflow:hidden">
                <button type="button" onclick="setVidView('grid')" id="btnVidGrid"
                        style="padding:8px 10px;border:none;background:var(--vid-color);color:#fff;cursor:pointer">
                    <i class="bi bi-grid-3x3-gap"></i>
                </button>
                <button type="button" onclick="setVidView('list')" id="btnVidList"
                        style="padding:8px 10px;border:none;background:var(--bg-body);color:var(--text-muted);cursor:pointer">
                    <i class="bi bi-list-ul"></i>
                </button>
            </div>
        </form>

        <!-- Título de resultados -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
            <h2 style="font-family:var(--font-serif);font-size:1.05rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px">
                <i class="bi bi-play-circle-fill" style="color:var(--vid-color)"></i>
                <?php if ($busqueda): ?>
                    Resultados para: "<?= e($busqueda) ?>"
                <?php elseif ($tipo): ?>
                    Videos <?= tipoLabel($tipo) ?>
                <?php elseif ($filtCat > 0): ?>
                    Videos en categoría
                <?php else: ?>
                    Todos los Videos
                <?php endif; ?>
            </h2>
            <span style="font-size:.8rem;color:var(--text-muted)"><?= number_format($total) ?> video<?= $total!=1?'s':'' ?></span>
        </div>

        <!-- ── Vista por categorías (cuando no hay filtro) ───── -->
        <?php if (!$busqueda && !$filtCat && !$tipo && $pagActual === 1 && !empty($videosPorCat)): ?>

        <?php foreach ($videosPorCat as $catGroup):
            $catVids = db()->fetchAll(
                "SELECT v.id, v.titulo, v.tipo, v.url, v.thumbnail, v.duracion, v.vistas, v.fecha_creacion
                 FROM videos v
                 WHERE v.activo = 1 AND v.categoria_id = ?
                   AND (v.fecha_publicacion IS NULL OR v.fecha_publicacion <= NOW())
                 ORDER BY v.destacado DESC, v.fecha_creacion DESC LIMIT 4",
                [(int)$catGroup['id']]
            );
            if (empty($catVids)) continue;
        ?>
        <div class="cat-section">
            <div class="cat-section-header">
                <div class="cat-section-title">
                    <span style="width:10px;height:10px;border-radius:50%;background:<?= e($catGroup['color']) ?>;display:inline-block;flex-shrink:0"></span>
                    <?= e($catGroup['nombre']) ?>
                    <span style="font-size:.72rem;font-weight:400;color:var(--text-muted)">(<?= $catGroup['total'] ?> videos)</span>
                </div>
                <a href="<?= APP_URL ?>/videos.php?cat=<?= $catGroup['id'] ?>" class="cat-section-link">
                    Ver todos <i class="bi bi-arrow-right"></i>
                </a>
            </div>
            <div class="cat-videos-row">
                <?php foreach ($catVids as $cv):
                    $cvThumb = getThumb($cv);
                ?>
                <div class="video-card"
                     data-id="<?= (int)$cv['id'] ?>"
                     data-tipo="<?= e($cv['tipo']) ?>"
                     data-embed="<?= e(getEmbed($cv)) ?>"
                     data-titulo="<?= e($cv['titulo']) ?>"
                     onclick="openVideoModal(this)">
                    <div class="video-thumb-wrap">
                        <img src="<?= e($cvThumb) ?>" alt="<?= e($cv['titulo']) ?>"
                             loading="lazy"
                             onerror="this.src='<?= APP_URL ?>/assets/images/default-news.jpg'">
                        <div class="video-play-overlay">
                            <button class="video-play-btn" aria-label="Reproducir">
                                <i class="bi bi-play-fill"></i>
                            </button>
                        </div>
                        <?php if ($cv['duracion']>0): ?>
                        <span class="vid-dur-badge"><?= fmtVidDur((int)$cv['duracion']) ?></span>
                        <?php endif; ?>
                        <div class="vid-type-corner">
                            <span class="v-badge <?= tipoBadgeClass($cv['tipo']) ?>"><?= tipoLabel($cv['tipo']) ?></span>
                        </div>
                    </div>
                    <div class="video-body">
                        <div class="video-title"><?= e($cv['titulo']) ?></div>
                        <div class="video-meta">
                            <div class="vid-stat-sm"><i class="bi bi-eye"></i><?= number_format((int)$cv['vistas']) ?></div>
                            <span class="vid-date">
                                <?= !empty($cv['fecha_creacion']) ? date('d/m/Y',strtotime($cv['fecha_creacion'])) : '' ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- ── Vista de búsqueda / filtrado ─────────────────── -->
        <?php else: ?>

        <?php if (empty($videos)): ?>
        <div style="padding:60px 20px;text-align:center;background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--border-radius-xl)">
            <i class="bi bi-camera-video-off" style="font-size:3rem;color:var(--text-muted)"></i>
            <div style="font-size:1rem;font-weight:700;color:var(--text-primary);margin:12px 0 8px">No hay videos</div>
            <div style="font-size:.85rem;color:var(--text-muted)">
                <?= $busqueda ? 'Sin resultados para tu búsqueda.' : 'Próximamente nuevos videos.' ?>
            </div>
        </div>
        <?php else: ?>
        <div class="videos-grid" id="videosContainer">
            <?php foreach ($videos as $vid):
                $vThumb = getThumb($vid);
            ?>
            <div class="video-card"
                 id="vcard-<?= (int)$vid['id'] ?>"
                 data-id="<?= (int)$vid['id'] ?>"
                 data-tipo="<?= e($vid['tipo']) ?>"
                 data-embed="<?= e(getEmbed($vid)) ?>"
                 data-titulo="<?= e($vid['titulo']) ?>"
                 onclick="openVideoModal(this)">
                <div class="video-thumb-wrap">
                    <img src="<?= e($vThumb) ?>"
                         alt="<?= e($vid['titulo']) ?>"
                         loading="lazy"
                         onerror="this.src='<?= APP_URL ?>/assets/images/default-news.jpg'">
                    <div class="video-play-overlay">
                        <button class="video-play-btn" aria-label="Reproducir">
                            <i class="bi bi-play-fill"></i>
                        </button>
                    </div>
                    <?php if ($vid['duracion']>0): ?>
                    <span class="vid-dur-badge"><?= fmtVidDur((int)$vid['duracion']) ?></span>
                    <?php endif; ?>
                    <?php if ($vid['destacado']): ?>
                    <div style="position:absolute;top:6px;right:6px;background:rgba(245,158,11,.9);color:#fff;font-size:.6rem;font-weight:700;padding:2px 6px;border-radius:4px">⭐</div>
                    <?php endif; ?>
                    <div class="vid-type-corner">
                        <span class="v-badge <?= tipoBadgeClass($vid['tipo']) ?>"><?= tipoLabel($vid['tipo']) ?></span>
                    </div>
                </div>
                <div class="video-body">
                    <div class="video-title"><?= e($vid['titulo']) ?></div>
                    <div class="video-meta">
                        <?php if (!empty($vid['cat_nombre'])): ?>
                        <span class="vid-cat" style="color:<?= e($vid['cat_color']) ?>"><?= e($vid['cat_nombre']) ?></span>
                        <?php endif; ?>
                        <div class="vid-stat-sm"><i class="bi bi-eye"></i><?= number_format((int)$vid['vistas']) ?></div>
                        <span class="vid-date">
                            <?= !empty($vid['fecha_publicacion'])
                                ? date('d/m/Y',strtotime($vid['fecha_publicacion']))
                                : date('d/m/Y',strtotime($vid['fecha_creacion'])) ?>
                        </span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Anuncio entre videos -->
            <?php if (!empty($adMiddle) && Config::bool('ads_entre_noticias')): ?>
            <div style="margin:24px 0;text-align:center;
                        padding:16px;border:2px dashed var(--border-color);
                        border-radius:var(--border-radius-lg);
                        background:var(--bg-surface-2)">
                <?= renderAnuncio($adMiddle[0]) ?>
            </div>
            <?php endif; ?>

        <!-- Paginación -->
        <?php if ($totalPags > 1): ?>
        <div class="vid-pag">
            <a href="<?= vidPagUrl($pagActual-1) ?>" class="vid-pag-btn <?= $pagActual<=1?'disabled':'' ?>">&laquo;</a>
            <?php for ($p=max(1,$pagActual-3); $p<=min($totalPags,$pagActual+3); $p++): ?>
            <a href="<?= vidPagUrl($p) ?>" class="vid-pag-btn <?= $p===$pagActual?'active':'' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a href="<?= vidPagUrl($pagActual+1) ?>" class="vid-pag-btn <?= $pagActual>=$totalPags?'disabled':'' ?>">&raquo;</a>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php endif; // vista categorias vs filtro ?>

    </div><!-- /videos-main -->

    <!-- ── SIDEBAR ──────────────────────────────────────────── -->
    <aside class="videos-sidebar">
        
        <!-- Widget: Anuncio Sidebar -->
        <?php if (!empty($adSidebar) && Config::bool('ads_sidebar')): ?>
        <div class="vid-sidebar-card" style="padding:10px;text-align:center">
            <?= renderAnuncio($adSidebar[0]) ?>
        </div>
        <?php endif; ?>

        <!-- Videos relacionados -->
        <?php if (!empty($relacionados)): ?>
        <div class="vid-sidebar-card">
            <div class="vid-sidebar-header">
                <i class="bi bi-collection-play" style="color:var(--vid-color)"></i>
                Videos relacionados
            </div>
            <div class="vid-sidebar-body" style="padding:6px 10px">
                <?php foreach ($relacionados as $rel):
                    $rThumb = getThumb($rel);
                ?>
                <div class="related-item"
                     data-id="<?= (int)$rel['id'] ?>"
                     data-tipo="<?= e($rel['tipo']) ?>"
                     data-embed="<?= e(getEmbed($rel)) ?>"
                     data-titulo="<?= e($rel['titulo']) ?>"
                     onclick="openVideoModal(this)">
                    <img class="related-thumb"
                         src="<?= e($rThumb) ?>"
                         alt="<?= e($rel['titulo']) ?>"
                         loading="lazy"
                         onerror="this.src='<?= APP_URL ?>/assets/images/default-news.jpg'">
                    <div>
                        <div class="related-title"><?= e($rel['titulo']) ?></div>
                        <div class="related-meta">
                            <span class="v-badge <?= tipoBadgeClass($rel['tipo']) ?>"><?= tipoLabel($rel['tipo']) ?></span>
                            <?= number_format((int)$rel['vistas']) ?> vistas
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Categorías con videos -->
        <?php if (!empty($videosPorCat)): ?>
        <div class="vid-sidebar-card">
            <div class="vid-sidebar-header">
                <i class="bi bi-folder" style="color:var(--vid-color)"></i>
                Categorías
            </div>
            <div class="vid-sidebar-body" style="padding:8px 12px">
                <?php foreach ($videosPorCat as $vc): ?>
                <a href="<?= APP_URL ?>/videos.php?cat=<?= $vc['id'] ?>"
                   style="display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border-color);text-decoration:none;transition:all .2s"
                   onmouseover="this.style.paddingLeft='5px'"
                   onmouseout="this.style.paddingLeft='0'">
                    <div style="display:flex;align-items:center;gap:7px">
                        <span style="width:8px;height:8px;border-radius:50%;background:<?= e($vc['color']) ?>;flex-shrink:0"></span>
                        <span style="font-size:.82rem;color:var(--text-secondary)"><?= e($vc['nombre']) ?></span>
                    </div>
                    <span style="font-size:.72rem;font-weight:700;color:var(--vid-color);background:rgba(230,57,70,.08);padding:2px 7px;border-radius:10px">
                        <?= $vc['total'] ?>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Podcasts link -->
        <div class="vid-sidebar-card">
            <div class="vid-sidebar-body" style="text-align:center;padding:20px 16px">
                <i class="bi bi-mic-fill" style="font-size:2rem;color:#7c3aed;margin-bottom:10px;display:block"></i>
                <div style="font-size:.88rem;font-weight:700;color:var(--text-primary);margin-bottom:6px">¿Prefieres audio?</div>
                <div style="font-size:.78rem;color:var(--text-muted);margin-bottom:14px;line-height:1.5">Escucha nuestros podcasts cuando quieras.</div>
                <a href="<?= APP_URL ?>/podcasts.php"
                   style="display:flex;align-items:center;justify-content:center;gap:6px;padding:10px;border-radius:var(--border-radius-lg);background:#7c3aed;color:#fff;font-size:.82rem;font-weight:700;text-decoration:none">
                    <i class="bi bi-headphones"></i> Ir a Podcasts
                </a>
            </div>
        </div>

    </aside>
</div>

<!-- Modal de video fullscreen -->
<div class="vid-modal" id="vidModal" onclick="closeVidModal(event)">
    <button class="vid-modal-close" onclick="closeVidModal()"><i class="bi bi-x-lg"></i></button>
    <div class="vid-modal-player" id="vidModalPlayer"></div>
    <div class="vid-modal-info">
        <div class="vid-modal-title" id="vidModalTitle"></div>
        <div class="vid-modal-meta" id="vidModalMeta"></div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<!-- ════════════════════════════════════════════════════════════
     JAVASCRIPT VIDEOS.PHP
════════════════════════════════════════════════════════════ -->
<script>
/* ── Playlist del hero ──────────────────────────────────────── */
function playFromPlaylist(el) {
    if (!el) return;
    const tipo  = el.dataset.tipo;
    const embed = el.dataset.embed;
    const titulo= el.dataset.titulo;
    const id    = el.dataset.id;
    const wrap  = document.getElementById('mainPlayerWrap');
    const titleEl = document.getElementById('mainVidTitle');

    if (!wrap) return;

    let html = '';
    if (tipo === 'mp4') {
        html = `<video src="${embed}" controls autoplay style="width:100%;height:100%"></video>`;
    } else {
        html = `<iframe src="${embed}&autoplay=1" title="${titulo}" frameborder="0"
                allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture"
                allowfullscreen style="width:100%;height:100%"></iframe>`;
    }
    wrap.innerHTML = html;
    if (titleEl) titleEl.textContent = titulo;

    // Marcar activo
    document.querySelectorAll('.playlist-item').forEach(i => i.classList.remove('active'));
    el.classList.add('active');

    // Actualizar URL
    if (id) history.replaceState(null,'','<?= APP_URL ?>/videos.php?v=' + id);

    // Registrar vista
    fetch('<?= APP_URL ?>/ajax/handler.php', {
        method:'POST',
        headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body: JSON.stringify({action:'track_video_view', video_id: parseInt(id)})
    }).catch(()=>{});
}

/* ── Modal de video ─────────────────────────────────────────── */
function openVideoModal(el) {
    const tipo  = el.dataset.tipo;
    const embed = el.dataset.embed;
    const titulo= el.dataset.titulo;
    const id    = el.dataset.id;
    const modal  = document.getElementById('vidModal');
    const player = document.getElementById('vidModalPlayer');
    const title  = document.getElementById('vidModalTitle');

    if (!modal || !player) return;

    let html = '';
    if (tipo === 'mp4') {
        html = `<video src="${embed}" controls autoplay style="width:100%;height:100%;border-radius:16px"></video>`;
    } else {
        html = `<iframe src="${embed}&autoplay=1" title="${titulo}" frameborder="0"
                allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture"
                allowfullscreen style="width:100%;height:100%;border-radius:16px"></iframe>`;
    }

    player.innerHTML = html;
    if (title) title.textContent = titulo;
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';

    if (id) {
        fetch('<?= APP_URL ?>/ajax/handler.php', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({action:'track_video_view', video_id: parseInt(id)})
        }).catch(()=>{});
    }
}

function closeVidModal(e) {
    if (e && e.target !== document.getElementById('vidModal') && !e.target.closest('.vid-modal-close')) return;
    const modal = document.getElementById('vidModal');
    const player = document.getElementById('vidModalPlayer');
    if (player) player.innerHTML = '';
    if (modal) modal.classList.remove('open');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeVidModal({ target: document.getElementById('vidModal') });
});

/* ── Vista grid / lista ─────────────────────────────────────── */
function setVidView(v) {
    const cont = document.getElementById('videosContainer');
    const btnG = document.getElementById('btnVidGrid');
    const btnL = document.getElementById('btnVidList');
    if (!cont) return;
    if (v === 'list') {
        cont.style.gridTemplateColumns = '1fr';
        cont.querySelectorAll('.video-card').forEach(c => {
            c.style.display='flex'; c.style.flexDirection='row';
        });
        cont.querySelectorAll('.video-thumb-wrap').forEach(w => {
            w.style.width='160px'; w.style.aspectRatio='16/9'; w.style.flexShrink='0';
        });
        if (btnL) { btnL.style.background='var(--vid-color)'; btnL.style.color='#fff'; }
        if (btnG) { btnG.style.background='var(--bg-body)'; btnG.style.color='var(--text-muted)'; }
    } else {
        cont.style.gridTemplateColumns = '';
        cont.querySelectorAll('.video-card').forEach(c => { c.style.display=''; c.style.flexDirection=''; });
        cont.querySelectorAll('.video-thumb-wrap').forEach(w => { w.style.width=''; w.style.aspectRatio=''; });
        if (btnG) { btnG.style.background='var(--vid-color)'; btnG.style.color='#fff'; }
        if (btnL) { btnL.style.background='var(--bg-body)'; btnL.style.color='var(--text-muted)'; }
    }
}

/* ── Compartir ──────────────────────────────────────────────── */
function shareVideo(url, titulo) {
    if (navigator.share) {
        navigator.share({ title: titulo, url }).catch(()=>{});
    } else {
        navigator.clipboard.writeText(url).then(()=>{
            let t = document.createElement('div');
            t.style.cssText='position:fixed;bottom:20px;right:20px;background:#1a1a2e;color:#fff;padding:12px 18px;border-radius:10px;z-index:9999;font-size:.85rem;border-left:4px solid #e63946';
            t.textContent='URL copiada al portapapeles';
            document.body.appendChild(t);
            setTimeout(()=>t.remove(),3000);
        }).catch(()=>{});
    }
}
</script>