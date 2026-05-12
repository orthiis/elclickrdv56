<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Admin: Dashboard
 * ============================================================
 * Archivo : admin/dashboard.php
 * Versión : 2.0.0
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$auth->requireAdmin();
$usuario = currentUser();

// ── Estadísticas generales ────────────────────────────────────
$stats = getEstadisticasAdmin();

// ── Visitas diarias (14 días) ─────────────────────────────────
$visitasChart = getVisitasDiarias(14);

// ── Noticias más vistas esta semana ──────────────────────────
$topNoticias = db()->fetchAll(
    "SELECT n.id, n.titulo, n.slug, n.vistas,
            n.fecha_publicacion, n.estado,
            n.total_compartidos, n.total_reacciones,
            c.nombre AS cat_nombre, c.color AS cat_color,
            u.nombre AS autor_nombre
     FROM noticias n
     INNER JOIN categorias c ON c.id = n.categoria_id
     INNER JOIN usuarios   u ON u.id = n.autor_id
     WHERE n.estado = 'publicado'
       AND n.fecha_publicacion >= NOW() - INTERVAL 7 DAY
     ORDER BY n.vistas DESC
     LIMIT 8",
    []
);

// ── Últimas noticias publicadas ───────────────────────────────
$ultimasNoticias = db()->fetchAll(
    "SELECT n.id, n.titulo, n.slug, n.estado,
            n.vistas, n.fecha_publicacion, n.fecha_creacion,
            n.breaking, n.destacado, n.es_premium,
            c.nombre AS cat_nombre, c.color AS cat_color,
            u.nombre AS autor_nombre
     FROM noticias n
     INNER JOIN categorias c ON c.id = n.categoria_id
     INNER JOIN usuarios   u ON u.id = n.autor_id
     ORDER BY n.fecha_creacion DESC
     LIMIT 10",
    []
);

// ── Últimos comentarios ───────────────────────────────────────
$ultimosComentarios = db()->fetchAll(
    "SELECT co.id, co.comentario, co.fecha, co.aprobado, co.likes,
            u.nombre AS usuario_nombre, u.avatar AS usuario_avatar,
            n.titulo AS noticia_titulo, n.slug AS noticia_slug
     FROM comentarios co
     INNER JOIN usuarios u ON u.id = co.usuario_id
     INNER JOIN noticias n ON n.id = co.noticia_id
     ORDER BY co.fecha DESC
     LIMIT 6",
    []
);

// ── Nuevos usuarios (últimos 7 días) ──────────────────────────
$nuevosUsuarios = db()->fetchAll(
    "SELECT id, nombre, email, rol, avatar, fecha_registro
     FROM usuarios
     WHERE fecha_registro >= NOW() - INTERVAL 7 DAY
     ORDER BY fecha_registro DESC
     LIMIT 5",
    []
);

// ── Reportes pendientes ───────────────────────────────────────
$reportesPendientes = (int)($stats['reportes_pendientes'] ?? 0);

// ── Visitas por categoría ─────────────────────────────────────
$visitasPorCat = db()->fetchAll(
    "SELECT c.nombre, c.color,
            SUM(n.vistas) AS total_vistas,
            COUNT(n.id)   AS total_noticias
     FROM categorias c
     LEFT JOIN noticias n ON n.categoria_id = c.id
         AND n.estado = 'publicado'
     WHERE c.activa = 1
     GROUP BY c.id
     ORDER BY total_vistas DESC
     LIMIT 8",
    []
);

// ── Actividad reciente admin ──────────────────────────────────
$actividadReciente = getActividadAdmin(8);

// ── Noticias por estado ───────────────────────────────────────
$noticiasEstado = db()->fetchAll(
    "SELECT estado, COUNT(*) AS total
     FROM noticias
     GROUP BY estado",
    []
);
$notiEstado = array_column($noticiasEstado, 'total', 'estado');

// ── Noticias creadas por día (últimos 30 días) ────────────────
$noticiasCreadas = db()->fetchAll(
    "SELECT DATE(fecha_creacion) AS dia, COUNT(*) AS total
     FROM noticias
     WHERE fecha_creacion >= NOW() - INTERVAL 30 DAY
     GROUP BY DATE(fecha_creacion)
     ORDER BY dia ASC",
    []
);

// ── Top autores ───────────────────────────────────────────────
$topAutores = db()->fetchAll(
    "SELECT u.id, u.nombre, u.avatar,
            COUNT(n.id)       AS total_noticias,
            SUM(n.vistas)     AS total_vistas
     FROM usuarios u
     INNER JOIN noticias n ON n.autor_id = u.id
         AND n.estado = 'publicado'
     GROUP BY u.id
     ORDER BY total_vistas DESC
     LIMIT 5",
    []
);

// ── Anuncios activos ──────────────────────────────────────────
$anunciosActivos = db()->fetchAll(
    "SELECT id, nombre, posicion, impresiones, clics,
            (CASE WHEN impresiones > 0
             THEN ROUND((clics / impresiones) * 100, 2)
             ELSE 0 END) AS ctr
     FROM anuncios
     WHERE activo = 1
     ORDER BY impresiones DESC
     LIMIT 5",
    []
);

// ── Métricas dispositivos ─────────────────────────────────────
$metricasDispositivos = db()->fetchAll(
    "SELECT dispositivo, COUNT(*) AS total
     FROM sesiones_activas
     WHERE fecha_inicio >= NOW() - INTERVAL 30 DAY
     GROUP BY dispositivo",
    []
);
$dispositivosMap = array_column($metricasDispositivos, 'total', 'dispositivo');

// ── Chart datos JSON ──────────────────────────────────────────
$chartVisitasLabels = json_encode($visitasChart['labels']);
$chartVisitasData   = json_encode($visitasChart['data']);

$chartCatLabels = json_encode(array_column($visitasPorCat, 'nombre'));
$chartCatData   = json_encode(array_map(fn($r) => (int)$r['total_vistas'], $visitasPorCat));
$chartCatColors = json_encode(array_column($visitasPorCat, 'color'));

$pageTitle = 'Dashboard — Panel Admin';

require_once __DIR__ . '/sidebar.php';
?>
<!DOCTYPE html>
<html lang="es" data-theme="<?= e(Config::get('apariencia_modo_oscuro', 'auto')) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= APP_VERSION ?>">

    <style>
        /* ── Admin Layout ────────────────────────────────────── */
        body {
            padding-bottom: 0;
            background: var(--bg-body);
        }

        .admin-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* ── Sidebar ─────────────────────────────────────────── */
        .admin-sidebar {
            width: 260px;
            background: var(--secondary-dark);
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            overflow-y: auto;
            z-index: var(--z-header);
            transition: transform var(--transition-base);
            display: flex;
            flex-direction: column;
        }

        .admin-sidebar::-webkit-scrollbar { width: 4px; }
        .admin-sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); }

        .admin-sidebar__logo {
            padding: 24px 20px 16px;
            border-bottom: 1px solid rgba(255,255,255,.07);
            flex-shrink: 0;
        }

        .admin-sidebar__logo a {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .admin-sidebar__logo-icon {
            width: 36px;
            height: 36px;
            background: var(--primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .admin-sidebar__logo-text {
            font-family: var(--font-serif);
            font-size: 1rem;
            font-weight: 800;
            color: #fff;
            line-height: 1.1;
        }

        .admin-sidebar__logo-sub {
            font-size: .65rem;
            color: rgba(255,255,255,.4);
            font-family: var(--font-sans);
            font-weight: 400;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .admin-sidebar__user {
            padding: 14px 20px;
            border-bottom: 1px solid rgba(255,255,255,.07);
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .admin-sidebar__user img {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255,255,255,.15);
        }

        .admin-sidebar__user-name {
            font-size: .82rem;
            font-weight: 600;
            color: rgba(255,255,255,.9);
            display: block;
            line-height: 1.2;
        }

        .admin-sidebar__user-role {
            font-size: .68rem;
            color: rgba(255,255,255,.4);
            text-transform: capitalize;
        }

        .admin-nav {
            flex: 1;
            padding: 12px 0;
            overflow-y: auto;
        }

        .admin-nav__section {
            padding: 14px 20px 6px;
            font-size: .62rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: rgba(255,255,255,.25);
        }

        .admin-nav__item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 20px;
            color: rgba(255,255,255,.6);
            font-size: .82rem;
            font-weight: 500;
            text-decoration: none;
            transition: all var(--transition-fast);
            position: relative;
            border-radius: 0;
        }

        .admin-nav__item:hover {
            background: rgba(255,255,255,.06);
            color: rgba(255,255,255,.9);
        }

        .admin-nav__item.active {
            background: rgba(230,57,70,.18);
            color: #fff;
            font-weight: 600;
        }

        .admin-nav__item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: var(--primary);
            border-radius: 0 3px 3px 0;
        }

        .admin-nav__item i {
            width: 18px;
            text-align: center;
            font-size: .9rem;
            flex-shrink: 0;
        }

        .admin-nav__badge {
            margin-left: auto;
            background: var(--primary);
            color: #fff;
            font-size: .6rem;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: var(--border-radius-full);
            min-width: 18px;
            text-align: center;
        }

        .admin-sidebar__footer {
            padding: 16px 20px;
            border-top: 1px solid rgba(255,255,255,.07);
            flex-shrink: 0;
        }

        /* ── Main content ────────────────────────────────────── */
        .admin-main {
            margin-left: 260px;
            flex: 1;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── Top Bar ─────────────────────────────────────────── */
        .admin-topbar {
            background: var(--bg-surface);
            border-bottom: 1px solid var(--border-color);
            padding: 0 28px;
            height: 62px;
            display: flex;
            align-items: center;
            gap: 16px;
            position: sticky;
            top: 0;
            z-index: var(--z-sticky);
            box-shadow: var(--shadow-sm);
        }

        .admin-topbar__toggle {
            display: none;
            color: var(--text-muted);
            font-size: 1.2rem;
            padding: 6px;
            border-radius: var(--border-radius-sm);
            transition: all var(--transition-fast);
        }
        .admin-topbar__toggle:hover { background: var(--bg-surface-2); }

        .admin-topbar__title {
            font-family: var(--font-serif);
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .admin-topbar__right {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .admin-topbar__btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: var(--border-radius);
            font-size: .8rem;
            font-weight: 600;
            text-decoration: none;
            transition: all var(--transition-fast);
        }

        .btn-primary-admin {
            background: var(--primary);
            color: #fff;
        }
        .btn-primary-admin:hover {
            background: var(--primary-dark);
            color: #fff;
        }

        .btn-secondary-admin {
            background: var(--bg-surface-2);
            color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }
        .btn-secondary-admin:hover {
            background: var(--bg-surface-3);
            color: var(--text-primary);
        }

        /* ── Content area ────────────────────────────────────── */
        .admin-content {
            padding: 28px;
            flex: 1;
        }

        /* ── Stat Cards ──────────────────────────────────────── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: var(--bg-surface);
            border-radius: var(--border-radius-lg);
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
            transition: all var(--transition-base);
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--card-color, var(--primary));
        }

        .stat-card__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2px;
        }

        .stat-card__icon {
            width: 34px;
            height: 34px;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .stat-card__trend {
            font-size: .65rem;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: var(--border-radius-full);
        }

        .trend-up   { background: rgba(34,197,94,.12); color: var(--success); }
        .trend-down { background: rgba(239,68,68,.12); color: var(--danger);  }
        .trend-neutral { background: var(--bg-surface-3); color: var(--text-muted); }

        .stat-card__value {
            font-size: 1.4rem;
            font-weight: 900;
            color: var(--text-primary);
            line-height: 1;
        }

        .stat-card__label {
            font-size: .72rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .stat-card__sub {
            font-size: .68rem;
            color: var(--text-muted);
            margin-top: 1px;
        }

        /* ── Dashboard Grid ──────────────────────────────────── */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .dashboard-grid-3col {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        /* ── Admin Cards ─────────────────────────────────────── */
        .admin-card {
            background: var(--bg-surface);
            border-radius: var(--border-radius-xl);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .admin-card__header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .admin-card__title {
            font-size: .875rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .admin-card__action {
            font-size: .75rem;
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: opacity var(--transition-fast);
        }
        .admin-card__action:hover { opacity: .75; color: var(--primary); }

        .admin-card__body { padding: 20px; }

        /* ── Table ───────────────────────────────────────────── */
        .admin-table {
            width: 100%;
            border-collapse: collapse;
        }

        .admin-table th {
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--text-muted);
            padding: 10px 12px;
            text-align: left;
            border-bottom: 2px solid var(--border-color);
            white-space: nowrap;
        }

        .admin-table td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            font-size: .82rem;
            color: var(--text-secondary);
            vertical-align: middle;
        }

        .admin-table tr:last-child td { border-bottom: none; }

        .admin-table tr:hover td {
            background: var(--bg-surface-2);
        }

        .table-title {
            font-weight: 600;
            color: var(--text-primary);
            max-width: 260px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .table-title a {
            color: inherit;
            text-decoration: none;
        }
        .table-title a:hover { color: var(--primary); }

        /* ── Status badges ───────────────────────────────────── */
        .badge-estado {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: var(--border-radius-full);
            font-size: .68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .estado-publicado  { background: rgba(34,197,94,.12); color: var(--success); }
        .estado-borrador   { background: rgba(245,158,11,.12); color: var(--warning); }
        .estado-programado { background: rgba(59,130,246,.12); color: var(--info);    }
        .estado-revision   { background: rgba(139,92,246,.12); color: #8b5cf6;        }

        /* ── Activity feed ───────────────────────────────────── */
        .activity-item {
            display: flex;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-color);
        }
        .activity-item:last-child { border-bottom: none; }

        .activity-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--primary);
            flex-shrink: 0;
            margin-top: 5px;
        }

        .activity-text {
            font-size: .8rem;
            color: var(--text-secondary);
            line-height: 1.4;
        }

        .activity-time {
            font-size: .7rem;
            color: var(--text-muted);
            margin-top: 2px;
            display: block;
        }

        /* ── Quick actions ───────────────────────────────────── */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            gap: 12px;
        }

        .quick-action {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            padding: 18px 12px;
            background: var(--bg-surface-2);
            border-radius: var(--border-radius-lg);
            border: 2px solid transparent;
            text-decoration: none;
            color: var(--text-secondary);
            font-size: .78rem;
            font-weight: 600;
            text-align: center;
            transition: all var(--transition-base);
            cursor: pointer;
        }

        .quick-action:hover {
            border-color: var(--primary);
            background: rgba(230,57,70,.06);
            color: var(--primary);
            transform: translateY(-2px);
        }

        .quick-action i {
            font-size: 1.5rem;
        }

        /* ── Chart container ─────────────────────────────────── */
        .chart-wrap {
            position: relative;
            height: 260px;
        }

        .chart-wrap-sm {
            position: relative;
            height: 200px;
        }

        /* ── Toggle switch ───────────────────────────────────── */
        .admin-toggle {
            position: relative;
            width: 38px;
            height: 22px;
            flex-shrink: 0;
        }

        .admin-toggle input { opacity: 0; width: 0; height: 0; }

        .admin-toggle-slider {
            position: absolute;
            inset: 0;
            background: var(--bg-surface-3);
            border-radius: var(--border-radius-full);
            cursor: pointer;
            transition: background var(--transition-fast);
        }

        .admin-toggle-slider::before {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            background: #fff;
            border-radius: 50%;
            top: 3px;
            left: 3px;
            transition: transform var(--transition-fast);
            box-shadow: var(--shadow-sm);
        }

        .admin-toggle input:checked + .admin-toggle-slider {
            background: var(--primary);
        }

        .admin-toggle input:checked + .admin-toggle-slider::before {
            transform: translateX(16px);
        }

        /* ── Overlay ─────────────────────────────────────────── */
        .admin-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            z-index: calc(var(--z-header) - 1);
            opacity: 0;
            pointer-events: none;
            transition: opacity .3s ease;
        }
        /* Se activa sólo cuando el sidebar está abierto en móvil */
        .admin-overlay.show {
            display: block;
            opacity: 1;
            pointer-events: auto;
        }

        /* ── Responsive ──────────────────────────────────────── */
        @media (max-width: 1100px) {
            .dashboard-grid { grid-template-columns: 1fr; }
            .dashboard-grid-3col { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 768px) {
            .admin-sidebar {
                transform: translateX(-100%);
            }
            .admin-sidebar.open {
                transform: translateX(0);
                box-shadow: var(--shadow-xl);
            }
            .admin-main { margin-left: 0; }
            .admin-topbar__toggle { display: flex; }
            .admin-content { padding: 16px; }
            .stats-grid { grid-template-columns: repeat(4, 1fr); gap: 10px; }
            .dashboard-grid-3col { grid-template-columns: 1fr; }
            /* ← SE ELIMINA .admin-overlay { display: block; } que causaba el bug */
            .quick-actions { grid-template-columns: repeat(3, 1fr); }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 4fr 1fr; }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">

<!-- ── SIDEBAR ───────────────────────────────────────────────── -->
<aside class="admin-sidebar" id="adminSidebar" role="navigation">

    <div class="admin-sidebar__logo">
        <a href="<?= APP_URL ?>/admin/dashboard.php">
            <div class="admin-sidebar__logo-icon">
                <i class="bi bi-newspaper"></i>
            </div>
            <div>
                <div class="admin-sidebar__logo-text">
                    <?= e(Config::get('site_nombre', APP_NAME)) ?>
                </div>
                <div class="admin-sidebar__logo-sub">Panel Admin v2</div>
            </div>
        </a>
    </div>

    <div class="admin-sidebar__user">
        <img src="<?= e(getImageUrl($usuario['avatar'] ?? '', 'avatar')) ?>"
             alt="<?= e($usuario['nombre']) ?>">
        <div>
            <span class="admin-sidebar__user-name">
                <?= e($usuario['nombre']) ?>
            </span>
            <span class="admin-sidebar__user-role">
                <?= e($usuario['rol']) ?>
            </span>
        </div>
    </div>

    <nav class="admin-nav">
        <div class="admin-nav__section">Principal</div>

        <a href="<?= APP_URL ?>/admin/dashboard.php"
           class="admin-nav__item active">
            <i class="bi bi-speedometer2"></i>
            Dashboard
        </a>

        <div class="admin-nav__section">Contenido</div>

        <a href="<?= APP_URL ?>/admin/noticias.php"
           class="admin-nav__item">
            <i class="bi bi-newspaper"></i>
            Noticias
            <?php if (!empty($notiEstado['borrador'])): ?>
            <span class="admin-nav__badge">
                <?= (int)$notiEstado['borrador'] ?>
            </span>
            <?php endif; ?>
        </a>

        <a href="<?= APP_URL ?>/admin/media.php"
           class="admin-nav__item">
            <i class="bi bi-images"></i>
            Multimedia
        </a>
        
        <a href="<?= APP_URL ?>/admin/coberturasenvivo.php"
           class="admin-nav__item <?= isActivePage('coberturasenvivo.php', $currentFile) ?>">
            <i class="bi bi-broadcast-pin"></i>
            <span>Coberturas en Vivo</span>
            <?php
            $livesActivos = (int)db()->count(
                "SELECT COUNT(*) FROM live_blog WHERE estado = 'activo'"
            );
            if ($livesActivos > 0):
            ?>
            <span class="admin-nav__badge" style="background:var(--danger)">
                <?= $livesActivos ?>
            </span>
            <?php endif; ?>
        </a>
        
        <a href="<?= APP_URL ?>/admin/encuestas.php"
           class="admin-nav__item <?= isActivePage('encuestas.php', $currentFile) ?>">
            <i class="bi bi-bar-chart-fill"></i>
            <span>Encuestas</span>
            <?php
            $encActivas = (int)db()->count("SELECT COUNT(*) FROM encuestas WHERE activa = 1");
            if ($encActivas > 0):
            ?>
            <span class="admin-nav__badge"><?= $encActivas ?></span>
            <?php endif; ?>
        </a>

        <a href="<?= APP_URL ?>/admin/comentarios.php"
           class="admin-nav__item">
            <i class="bi bi-chat-dots-fill"></i>
            Comentarios
            <?php if ($reportesPendientes > 0): ?>
            <span class="admin-nav__badge"><?= $reportesPendientes ?></span>
            <?php endif; ?>
        </a>
        
        <a href="<?= APP_URL ?>/admin/combustibles.php"
           class="admin-nav__item <?= isActivePage('combustibles.php', $currentFile) ?>">
            <i class="bi bi-fuel-pump-fill"
               style="color:<?= isActivePage('combustibles.php', $currentFile) === 'active'
                   ? 'inherit' : '#f59e0b' ?>"></i>
            <span>Precio Combustibles</span>
        </a>

        <div class="admin-nav__section">Monetización</div>

        <a href="<?= APP_URL ?>/admin/anuncios.php"
           class="admin-nav__item">
            <i class="bi bi-badge-ad-fill"></i>
            Anuncios
        </a>

        <div class="admin-nav__section">Gestión</div>

        <a href="<?= APP_URL ?>/admin/usuarios.php"
           class="admin-nav__item">
            <i class="bi bi-people-fill"></i>
            Usuarios
        </a>
        
        <!-- ── HERRAMIENTAS ──────────────────────────────── -->
        <div class="admin-nav__section">Herramientas</div>
        
        <a href="<?= APP_URL ?>/admin/generador.php"
           class="admin-nav__item <?= isActivePage('generador.php', $currentFile) ?>">
            <i class="bi bi-robot"></i>
            <span>Generador de Noticias</span>
        </a>

        <!-- ── SISTEMA ───────────────────────────────────── -->
        <div class="admin-nav__section">Sistema</div>

        <a href="<?= APP_URL ?>/admin/configuracion.php"
           class="admin-nav__item">
            <i class="bi bi-gear-fill"></i>
            Configuración
        </a>

        <a href="<?= APP_URL ?>/index.php"
           target="_blank"
           class="admin-nav__item">
            <i class="bi bi-box-arrow-up-right"></i>
            Ver sitio
        </a>
    </nav>

    <div class="admin-sidebar__footer">
        <a href="<?= APP_URL ?>/admin/logout.php"
           class="admin-nav__item"
           style="border-radius:var(--border-radius-lg);
                  color:rgba(255,255,255,.5)">
            <i class="bi bi-box-arrow-right"></i>
            Cerrar sesión
        </a>
    </div>
</aside>

<!-- Overlay móvil -->
<div class="admin-overlay" id="adminOverlay"
     onclick="closeSidebar()"></div>

<!-- ── MAIN ──────────────────────────────────────────────────── -->
<main class="admin-main">

    <!-- Top bar -->
    <div class="admin-topbar">
        <button class="admin-topbar__toggle" id="sidebarToggle"
                onclick="toggleSidebar()" aria-label="Menú">
            <i class="bi bi-list"></i>
        </button>

        <h1 class="admin-topbar__title">
            <i class="bi bi-speedometer2"
               style="color:var(--primary);margin-right:6px"></i>
            Dashboard
        </h1>

        <div class="admin-topbar__right">
            <!-- Fecha -->
            <span style="font-size:.78rem;color:var(--text-muted)
                ;display:none;d-md-block">
                <?= date('D, d M Y') ?>
            </span>

            <!-- Alertas -->
            <?php if ($reportesPendientes > 0): ?>
            <a href="<?= APP_URL ?>/admin/comentarios.php?tab=reportes"
               class="admin-topbar__btn btn-secondary-admin"
               style="color:var(--danger);border-color:var(--danger)">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= $reportesPendientes ?> reportes
            </a>
            <?php endif; ?>

            <a href="<?= APP_URL ?>/admin/noticias.php?action=nueva"
               class="admin-topbar__btn btn-primary-admin">
                <i class="bi bi-plus-lg"></i>
                Nueva noticia
            </a>

            <!-- Dark mode -->
            <button onclick="toggleDark()"
                    style="padding:8px;border-radius:var(--border-radius);
                           background:var(--bg-surface-2);
                           color:var(--text-muted);border:1px solid var(--border-color);
                           cursor:pointer;transition:all .2s ease"
                    title="Cambiar tema"
                    id="darkBtn">
                <i class="bi bi-moon-stars-fill" id="darkIcon"></i>
            </button>
        </div>
    </div>

    <!-- Content -->
    <div class="admin-content">

        <!-- ── Bienvenida ──────────────────────────────────── -->
        <div style="margin-bottom:24px">
            <h2 style="font-family:var(--font-serif);font-size:1.4rem;
                       font-weight:800;color:var(--text-primary);margin-bottom:4px">
                ¡Bienvenido, <?= e(explode(' ', $usuario['nombre'])[0]) ?>! 👋
            </h2>
            <p style="color:var(--text-muted);font-size:.875rem">
                Aquí tienes un resumen del estado de
                <?= e(Config::get('site_nombre', APP_NAME)) ?> hoy,
                <?= date('d/m/Y') ?>.
            </p>
        </div>

        <!-- ── Stats Cards ────────────────────────────────── -->
        <div class="stats-grid">

            <!-- Noticias publicadas -->
            <div class="stat-card"
                 style="--card-color:var(--primary)">
                <div class="stat-card__header">
                    <div class="stat-card__icon"
                         style="background:rgba(230,57,70,.12);
                                color:var(--primary)">
                        <i class="bi bi-newspaper"></i>
                    </div>
                    <span class="stat-card__trend trend-up">
                        <i class="bi bi-arrow-up"></i>
                        Publicadas
                    </span>
                </div>
                <div class="stat-card__value">
                    <?= formatNumber((int)($stats['noticias_publicadas'] ?? 0)) ?>
                </div>
                <div class="stat-card__label">Noticias publicadas</div>
                <div class="stat-card__sub">
                    <?= (int)($stats['noticias_borrador'] ?? 0) ?> en borrador
                </div>
            </div>

            <!-- Total visitas -->
            <div class="stat-card"
                 style="--card-color:var(--info)">
                <div class="stat-card__header">
                    <div class="stat-card__icon"
                         style="background:rgba(59,130,246,.12);
                                color:var(--info)">
                        <i class="bi bi-eye-fill"></i>
                    </div>
                    <span class="stat-card__trend trend-up">
                        <i class="bi bi-arrow-up"></i>
                        Total
                    </span>
                </div>
                <div class="stat-card__value">
                    <?= formatNumber((int)($stats['total_visitas'] ?? 0)) ?>
                </div>
                <div class="stat-card__label">Visitas totales</div>
                <div class="stat-card__sub">
                    Acumuladas históricamente
                </div>
            </div>

            <!-- Usuarios activos -->
            <div class="stat-card"
                 style="--card-color:var(--success)">
                <div class="stat-card__header">
                    <div class="stat-card__icon"
                         style="background:rgba(34,197,94,.12);
                                color:var(--success)">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <span class="stat-card__trend trend-neutral">
                        Activos
                    </span>
                </div>
                <div class="stat-card__value">
                    <?= formatNumber((int)($stats['usuarios_activos'] ?? 0)) ?>
                </div>
                <div class="stat-card__label">Usuarios registrados</div>
                <div class="stat-card__sub">
                    <?= formatNumber((int)($stats['usuarios_premium'] ?? 0)) ?> premium
                </div>
            </div>

            <!-- Comentarios -->
            <div class="stat-card"
                 style="--card-color:var(--accent)">
                <div class="stat-card__header">
                    <div class="stat-card__icon"
                         style="background:rgba(69,123,157,.12);
                                color:var(--accent)">
                        <i class="bi bi-chat-dots-fill"></i>
                    </div>
                    <span class="stat-card__trend trend-up">
                        <i class="bi bi-arrow-up"></i>
                        Total
                    </span>
                </div>
                <div class="stat-card__value">
                    <?= formatNumber((int)($stats['total_comentarios'] ?? 0)) ?>
                </div>
                <div class="stat-card__label">Comentarios aprobados</div>
                <div class="stat-card__sub">
                    <?= $reportesPendientes ?> reportes pendientes
                </div>
            </div>

            <!-- Newsletter -->
            <div class="stat-card"
                 style="--card-color:var(--warning)">
                <div class="stat-card__header">
                    <div class="stat-card__icon"
                         style="background:rgba(245,158,11,.12);
                                color:var(--warning)">
                        <i class="bi bi-envelope-paper-fill"></i>
                    </div>
                    <span class="stat-card__trend trend-up">
                        <i class="bi bi-arrow-up"></i>
                        Activos
                    </span>
                </div>
                <div class="stat-card__value">
                    <?= formatNumber((int)($stats['suscriptores_activos'] ?? 0)) ?>
                </div>
                <div class="stat-card__label">Suscriptores newsletter</div>
                <div class="stat-card__sub">Confirmados y activos</div>
            </div>

            <!-- Anuncios -->
            <div class="stat-card"
                 style="--card-color:#8b5cf6">
                <div class="stat-card__header">
                    <div class="stat-card__icon"
                         style="background:rgba(139,92,246,.12);color:#8b5cf6">
                        <i class="bi bi-badge-ad-fill"></i>
                    </div>
                    <span class="stat-card__trend trend-neutral">
                        Activos
                    </span>
                </div>
                <div class="stat-card__value">
                    <?= formatNumber((int)($stats['anuncios_activos'] ?? 0)) ?>
                </div>
                <div class="stat-card__label">Anuncios activos</div>
                <div class="stat-card__sub">
                    <?= formatNumber((int)($stats['total_clics_ads'] ?? 0)) ?> clics totales
                </div>
            </div>

            <!-- Live blogs activos -->
            <div class="stat-card"
                 style="--card-color:var(--danger)">
                <div class="stat-card__header">
                    <div class="stat-card__icon"
                         style="background:rgba(230,57,70,.12);
                                color:var(--danger)">
                        <i class="bi bi-broadcast-pin"></i>
                    </div>
                    <?php if ((int)($stats['live_activos'] ?? 0) > 0): ?>
                    <span class="stat-card__trend trend-up"
                          style="animation:livePulse 2s infinite">
                        🔴 EN VIVO
                    </span>
                    <?php endif; ?>
                </div>
                <div class="stat-card__value">
                    <?= (int)($stats['live_activos'] ?? 0) ?>
                </div>
                <div class="stat-card__label">Live blogs activos</div>
                <div class="stat-card__sub">
                    <a href="<?= APP_URL ?>/live.php"
                       style="color:var(--primary)">Ver coberturas</a>
                </div>
            </div>

            <!-- Videos -->
            <div class="stat-card"
                 style="--card-color:var(--success)">
                <div class="stat-card__header">
                    <div class="stat-card__icon"
                         style="background:rgba(34,197,94,.12);
                                color:var(--success)">
                        <i class="bi bi-play-circle-fill"></i>
                    </div>
                </div>
                <div class="stat-card__value">
                    <?= formatNumber((int)($stats['videos_activos'] ?? 0)) ?>
                </div>
                <div class="stat-card__label">Videos activos</div>
                <div class="stat-card__sub">
                    <a href="<?= APP_URL ?>/admin/media.php?tab=videos"
                       style="color:var(--primary)">Gestionar</a>
                </div>
            </div>

        </div><!-- /.stats-grid -->

        <!-- ── Acciones rápidas ────────────────────────────── -->
        <div class="admin-card" style="margin-bottom:20px">
            <div class="admin-card__header">
                <span class="admin-card__title">
                    <i class="bi bi-lightning-charge-fill"
                       style="color:var(--warning)"></i>
                    Acciones rápidas
                </span>
            </div>
            <div class="admin-card__body">
                <div class="quick-actions">
                    <a href="<?= APP_URL ?>/admin/noticias.php?action=nueva"
                       class="quick-action">
                        <i class="bi bi-plus-circle-fill"
                           style="color:var(--primary)"></i>
                        Nueva noticia
                    </a>
                    <a href="<?= APP_URL ?>/admin/media.php"
                       class="quick-action">
                        <i class="bi bi-cloud-upload-fill"
                           style="color:var(--info)"></i>
                        Subir media
                    </a>
                    <a href="<?= APP_URL ?>/admin/usuarios.php"
                       class="quick-action">
                        <i class="bi bi-person-plus-fill"
                           style="color:var(--success)"></i>
                        Nuevo usuario
                    </a>
                    <a href="<?= APP_URL ?>/admin/anuncios.php?action=nuevo"
                       class="quick-action">
                        <i class="bi bi-badge-ad-fill"
                           style="color:#8b5cf6"></i>
                        Nuevo anuncio
                    </a>
                    <a href="<?= APP_URL ?>/admin/comentarios.php"
                       class="quick-action">
                        <i class="bi bi-chat-dots-fill"
                           style="color:var(--accent)"></i>
                        Moderar
                    </a>
                    <a href="<?= APP_URL ?>/admin/configuracion.php"
                       class="quick-action">
                        <i class="bi bi-gear-fill"
                           style="color:var(--warning)"></i>
                        Configurar
                    </a>
                    <button onclick="createBackup()"
                            class="quick-action">
                        <i class="bi bi-cloud-arrow-down-fill"
                           style="color:var(--success)"></i>
                        Backup BD
                    </button>
                    <a href="<?= APP_URL ?>/index.php"
                       target="_blank"
                       class="quick-action">
                        <i class="bi bi-box-arrow-up-right"
                           style="color:var(--text-muted)"></i>
                        Ver sitio
                    </a>
                </div>
            </div>
        </div>

        <!-- ── Gráfico de visitas + Noticias estado ────────── -->
        <div class="dashboard-grid">

            <!-- Gráfico de visitas diarias -->
            <div class="admin-card">
                <div class="admin-card__header">
                    <span class="admin-card__title">
                        <i class="bi bi-graph-up-arrow"
                           style="color:var(--primary)"></i>
                        <span id="visitasChartTitle">Visitas diarias (últimos 14 días)</span>
                        <?php
                        $logCount = (int)(db()->fetchOne(
                            "SELECT COUNT(*) AS c FROM visitas_log
                             WHERE fecha >= NOW() - INTERVAL 14 DAY"
                        )['c'] ?? 0);
                        ?>
                        
                    </span>
                    <div style="display:flex;gap:6px">
                        <button id="btn7d"
                                onclick="updateVisitasChart(7)"
                                style="padding:4px 10px;border-radius:var(--border-radius-full);
                                       font-size:.7rem;font-weight:700;cursor:pointer;
                                       border:1px solid var(--border-color);
                                       background:transparent;color:var(--text-muted);
                                       transition:all .2s ease">
                            7d
                        </button>
                        <button id="btn14d"
                                onclick="updateVisitasChart(14)"
                                style="padding:4px 10px;border-radius:var(--border-radius-full);
                                       font-size:.7rem;font-weight:700;cursor:pointer;
                                       border:1px solid var(--primary);
                                       background:var(--primary);color:#fff;
                                       transition:all .2s ease">
                            14d
                        </button>
                        <button id="btn30d"
                                onclick="updateVisitasChart(30)"
                                style="padding:4px 10px;border-radius:var(--border-radius-full);
                                       font-size:.7rem;font-weight:700;cursor:pointer;
                                       border:1px solid var(--border-color);
                                       background:transparent;color:var(--text-muted);
                                       transition:all .2s ease">
                            30d
                        </button>
                    </div>
                </div>
                <div class="admin-card__body">
                    <div class="chart-wrap">
                        <canvas id="visitasChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Noticias por estado -->
            <div class="admin-card">
                <div class="admin-card__header">
                    <span class="admin-card__title">
                        <i class="bi bi-pie-chart-fill"
                           style="color:var(--accent)"></i>
                        Estado de noticias
                    </span>
                </div>
                <div class="admin-card__body">
                    <div class="chart-wrap-sm">
                        <canvas id="estadoChart"></canvas>
                    </div>
                    <!-- Resumen estado -->
                    <div style="display:flex;gap:12px;flex-wrap:wrap;
                                margin-top:16px;justify-content:center">
                        <?php
                        $estadoInfo = [
                            'publicado'  => ['color'=>'var(--success)', 'label'=>'Publicadas'],
                            'borrador'   => ['color'=>'var(--warning)', 'label'=>'Borradores'],
                            'programado' => ['color'=>'var(--info)',    'label'=>'Programadas'],
                            'revision'   => ['color'=>'#8b5cf6',       'label'=>'En revisión'],
                        ];
                        foreach ($estadoInfo as $est => $info):
                            $cnt = (int)($notiEstado[$est] ?? 0);
                            if ($cnt === 0) continue;
                        ?>
                        <div style="text-align:center">
                            <div style="font-size:1.1rem;font-weight:900;
                                         color:<?= $info['color'] ?>">
                                <?= $cnt ?>
                            </div>
                            <div style="font-size:.65rem;color:var(--text-muted)">
                                <?= $info['label'] ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div><!-- /.dashboard-grid -->

        <!-- ── Top noticias + Categorías ──────────────────── -->
        <div class="dashboard-grid" style="margin-bottom:20px">

            <!-- Top noticias esta semana -->
            <div class="admin-card">
                <div class="admin-card__header">
                    <span class="admin-card__title">
                        <i class="bi bi-trophy-fill"
                           style="color:var(--warning)"></i>
                        Noticias más vistas (7 días)
                    </span>
                    <a href="<?= APP_URL ?>/admin/noticias.php"
                       class="admin-card__action">
                        Ver todas <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
                <div style="overflow-x:auto">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Noticia</th>
                                <th>Categoría</th>
                                <th>Vistas</th>
                                <th>Shares</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topNoticias as $idx => $n): ?>
                            <tr>
                                <td style="font-weight:800;
                                           color:<?= $idx === 0 ? 'var(--warning)' : 'var(--text-muted)' ?>;
                                           font-size:.9rem">
                                    <?= $idx + 1 ?>
                                </td>
                                <td>
                                    <div class="table-title">
                                        <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($n['slug']) ?>"
                                           target="_blank">
                                            <?= e(truncateChars($n['titulo'], 55)) ?>
                                        </a>
                                    </div>
                                    <small style="color:var(--text-muted);font-size:.7rem">
                                        <?= e($n['autor_nombre']) ?> ·
                                        <?= timeAgo($n['fecha_publicacion']) ?>
                                    </small>
                                </td>
                                <td>
                                    <span style="font-size:.7rem;font-weight:700;
                                                 color:<?= e($n['cat_color']) ?>">
                                        <?= e($n['cat_nombre']) ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color:var(--primary)">
                                        <?= formatNumber((int)$n['vistas']) ?>
                                    </strong>
                                </td>
                                <td style="color:var(--text-muted)">
                                    <?= formatNumber((int)($n['total_compartidos'] ?? 0)) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($topNoticias)): ?>
                            <tr>
                                <td colspan="5"
                                    style="text-align:center;
                                           color:var(--text-muted);
                                           padding:30px">
                                    No hay noticias publicadas esta semana
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Visitas por categoría -->
            <div class="admin-card">
                <div class="admin-card__header">
                    <span class="admin-card__title">
                        <i class="bi bi-grid-3x3-gap-fill"
                           style="color:var(--accent)"></i>
                        Visitas por categoría
                    </span>
                </div>
                <div class="admin-card__body">
                    <div class="chart-wrap-sm">
                        <canvas id="catChart"></canvas>
                    </div>
                    <div style="display:flex;flex-direction:column;
                                gap:8px;margin-top:16px">
                        <?php foreach (array_slice($visitasPorCat, 0, 5) as $cat): ?>
                        <div style="display:flex;align-items:center;gap:8px">
                            <span style="width:10px;height:10px;border-radius:50%;
                                         background:<?= e($cat['color']) ?>;
                                         flex-shrink:0"></span>
                            <span style="font-size:.78rem;flex:1;
                                         color:var(--text-secondary)">
                                <?= e($cat['nombre']) ?>
                            </span>
                            <span style="font-size:.75rem;font-weight:700;
                                         color:var(--text-primary)">
                                <?= formatNumber((int)$cat['total_vistas']) ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── Últimas noticias + Actividad ───────────────── -->
        <div class="dashboard-grid" style="margin-bottom:20px">

            <!-- Últimas noticias -->
            <div class="admin-card">
                <div class="admin-card__header">
                    <span class="admin-card__title">
                        <i class="bi bi-clock-history"
                           style="color:var(--primary)"></i>
                        Últimas noticias
                    </span>
                    <a href="<?= APP_URL ?>/admin/noticias.php"
                       class="admin-card__action">
                        Ver todas <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
                <div style="overflow-x:auto">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Título</th>
                                <th>Estado</th>
                                <th>Vistas</th>
                                <th>Fecha</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ultimasNoticias as $n): ?>
                            <tr>
                                <td>
                                    <div class="table-title">
                                        <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($n['slug']) ?>"
                                           target="_blank">
                                            <?= e(truncateChars($n['titulo'], 50)) ?>
                                        </a>
                                    </div>
                                    <div style="display:flex;gap:4px;margin-top:3px">
                                        <?php if ($n['breaking']): ?>
                                        <span style="font-size:.6rem;background:var(--primary);
                                                     color:#fff;padding:1px 5px;
                                                     border-radius:3px">
                                            BREAKING
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($n['destacado']): ?>
                                        <span style="font-size:.6rem;background:var(--warning);
                                                     color:#fff;padding:1px 5px;
                                                     border-radius:3px">
                                            DESTACADO
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($n['es_premium'] ?? false): ?>
                                        <span style="font-size:.6rem;background:#8b5cf6;
                                                     color:#fff;padding:1px 5px;
                                                     border-radius:3px">
                                            PREMIUM
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge-estado estado-<?= e($n['estado']) ?>">
                                        <?= e(ucfirst($n['estado'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?= formatNumber((int)$n['vistas']) ?></strong>
                                </td>
                                <td style="white-space:nowrap;
                                           color:var(--text-muted)">
                                    <?= timeAgo($n['fecha_creacion']) ?>
                                </td>
                                <td>
                                    <div style="display:flex;gap:4px">
                                        <a href="<?= APP_URL ?>/admin/noticias.php?action=editar&id=<?= (int)$n['id'] ?>"
                                           title="Editar"
                                           style="padding:4px 8px;
                                                  border-radius:var(--border-radius-sm);
                                                  color:var(--info);
                                                  background:rgba(59,130,246,.1);
                                                  font-size:.75rem;
                                                  text-decoration:none">
                                            <i class="bi bi-pencil-fill"></i>
                                        </a>
                                        <!-- Toggle estado rápido -->
                                        <label class="admin-toggle"
                                               title="<?= $n['estado'] === 'publicado' ? 'Despublicar' : 'Publicar' ?>">
                                            <input type="checkbox"
                                                   class="quick-toggle"
                                                   data-id="<?= (int)$n['id'] ?>"
                                                   data-tabla="noticias"
                                                   data-campo="estado"
                                                   <?= $n['estado'] === 'publicado' ? 'checked' : '' ?>>
                                            <span class="admin-toggle-slider"></span>
                                        </label>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Actividad reciente + Comentarios -->
            <div style="display:flex;flex-direction:column;gap:20px">

                <!-- Últimos comentarios -->
                <div class="admin-card">
                    <div class="admin-card__header">
                        <span class="admin-card__title">
                            <i class="bi bi-chat-dots-fill"
                               style="color:var(--accent)"></i>
                            Últimos comentarios
                        </span>
                        <a href="<?= APP_URL ?>/admin/comentarios.php"
                           class="admin-card__action">
                            Ver todos <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                    <div class="admin-card__body"
                         style="padding:12px 16px">
                        <?php foreach ($ultimosComentarios as $com): ?>
                        <div style="display:flex;gap:10px;padding:8px 0;
                                    border-bottom:1px solid var(--border-color)">
                            <img src="<?= e(getImageUrl($com['usuario_avatar'] ?? '', 'avatar')) ?>"
                                 alt="" width="30" height="30"
                                 style="border-radius:50%;object-fit:cover;flex-shrink:0">
                            <div style="flex:1;min-width:0">
                                <div style="font-size:.75rem;font-weight:600;
                                             color:var(--text-primary)">
                                    <?= e($com['usuario_nombre']) ?>
                                    <span style="color:var(--text-muted);
                                                  font-weight:400">
                                        en
                                        <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($com['noticia_slug']) ?>"
                                           target="_blank"
                                           style="color:var(--primary)">
                                            <?= e(truncateChars($com['noticia_titulo'], 30)) ?>
                                        </a>
                                    </span>
                                </div>
                                <div style="font-size:.72rem;color:var(--text-muted);
                                             margin-top:2px;white-space:nowrap;
                                             overflow:hidden;text-overflow:ellipsis">
                                    <?= e(truncateChars($com['comentario'], 60)) ?>
                                </div>
                                <div style="display:flex;align-items:center;
                                            gap:8px;margin-top:4px">
                                    <span style="font-size:.65rem;color:var(--text-muted)">
                                        <?= timeAgo($com['fecha']) ?>
                                    </span>
                                    <?php if (!$com['aprobado']): ?>
                                    <span style="font-size:.6rem;
                                                  background:rgba(245,158,11,.15);
                                                  color:var(--warning);
                                                  padding:1px 6px;
                                                  border-radius:3px;
                                                  font-weight:700">
                                        PENDIENTE
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Log de actividad -->
                <?php if (!empty($actividadReciente)): ?>
                <div class="admin-card">
                    <div class="admin-card__header">
                        <span class="admin-card__title">
                            <i class="bi bi-activity"
                               style="color:var(--success)"></i>
                            Actividad reciente
                        </span>
                    </div>
                    <div class="admin-card__body"
                         style="padding:12px 16px">
                        <?php foreach (array_slice($actividadReciente, 0, 6) as $act): ?>
                        <div class="activity-item">
                            <div class="activity-dot"></div>
                            <div>
                                <div class="activity-text">
                                    <strong><?= e($act['usuario_nombre']) ?></strong>
                                    · <?= e($act['accion']) ?>
                                    <?php if (!empty($act['descripcion'])): ?>
                                    <span style="color:var(--text-muted)">
                                        — <?= e(truncateChars($act['descripcion'], 40)) ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <span class="activity-time">
                                    <?= timeAgo($act['fecha']) ?>
                                    · <?= e($act['modulo']) ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- ── Top autores + Anuncios + Usuarios nuevos ─────── -->
        <div class="dashboard-grid-3col">

            <!-- Top autores -->
            <div class="admin-card">
                <div class="admin-card__header">
                    <span class="admin-card__title">
                        <i class="bi bi-person-badge-fill"
                           style="color:var(--primary)"></i>
                        Top autores
                    </span>
                </div>
                <div class="admin-card__body"
                     style="padding:12px 16px">
                    <?php foreach ($topAutores as $idx => $au): ?>
                    <div style="display:flex;align-items:center;
                                gap:10px;padding:8px 0;
                                border-bottom:1px solid var(--border-color)">
                        <span style="font-size:1rem;font-weight:900;
                                     color:<?= $idx === 0 ? 'var(--warning)' : 'var(--text-muted)' ?>;
                                     width:18px;text-align:center">
                            <?= $idx + 1 ?>
                        </span>
                        <img src="<?= e(getImageUrl($au['avatar'] ?? '', 'avatar')) ?>"
                             alt="" width="32" height="32"
                             style="border-radius:50%;object-fit:cover">
                        <div style="flex:1;min-width:0">
                            <div style="font-size:.8rem;font-weight:600;
                                         color:var(--text-primary);
                                         white-space:nowrap;overflow:hidden;
                                         text-overflow:ellipsis">
                                <?= e($au['nombre']) ?>
                            </div>
                            <div style="font-size:.7rem;color:var(--text-muted)">
                                <?= (int)$au['total_noticias'] ?> artículos
                            </div>
                        </div>
                        <div style="font-size:.75rem;font-weight:700;
                                     color:var(--primary)">
                            <?= formatNumber((int)$au['total_vistas']) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($topAutores)): ?>
                    <p style="text-align:center;color:var(--text-muted);
                               padding:20px 0;font-size:.82rem">
                        Sin datos aún
                    </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Anuncios rendimiento -->
            <div class="admin-card">
                <div class="admin-card__header">
                    <span class="admin-card__title">
                        <i class="bi bi-badge-ad-fill"
                           style="color:#8b5cf6"></i>
                        Rendimiento anuncios
                    </span>
                    <a href="<?= APP_URL ?>/admin/anuncios.php"
                       class="admin-card__action">
                        Ver todos <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
                <div class="admin-card__body" style="padding:12px 16px">
                    <?php foreach ($anunciosActivos as $ad): ?>
                    <div style="padding:8px 0;border-bottom:1px solid var(--border-color)">
                        <div style="font-size:.78rem;font-weight:600;
                                     color:var(--text-primary);margin-bottom:4px">
                            <?= e(truncateChars($ad['nombre'], 35)) ?>
                        </div>
                        <div style="display:flex;gap:12px;
                                    font-size:.7rem;color:var(--text-muted)">
                            <span>
                                <i class="bi bi-eye"></i>
                                <?= formatNumber((int)$ad['impresiones']) ?>
                            </span>
                            <span>
                                <i class="bi bi-cursor-fill"></i>
                                <?= formatNumber((int)$ad['clics']) ?>
                            </span>
                            <span style="color:<?= (float)$ad['ctr'] > 2
                                ? 'var(--success)' : 'var(--text-muted)' ?>;
                                font-weight:700">
                                CTR: <?= number_format((float)$ad['ctr'], 2) ?>%
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($anunciosActivos)): ?>
                    <p style="text-align:center;color:var(--text-muted);
                               padding:20px 0;font-size:.82rem">
                        No hay anuncios activos
                    </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Nuevos usuarios + Métricas dispositivos -->
            <div style="display:flex;flex-direction:column;gap:20px">

                <div class="admin-card">
                    <div class="admin-card__header">
                        <span class="admin-card__title">
                            <i class="bi bi-person-plus-fill"
                               style="color:var(--success)"></i>
                            Nuevos usuarios (7d)
                        </span>
                        <a href="<?= APP_URL ?>/admin/usuarios.php"
                           class="admin-card__action">
                            Ver todos <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                    <div class="admin-card__body" style="padding:10px 16px">
                        <?php if (!empty($nuevosUsuarios)): ?>
                        <?php foreach ($nuevosUsuarios as $nu): ?>
                        <div style="display:flex;align-items:center;
                                    gap:10px;padding:6px 0">
                            <img src="<?= e(getImageUrl($nu['avatar'] ?? '', 'avatar')) ?>"
                                 alt="" width="28" height="28"
                                 style="border-radius:50%;object-fit:cover">
                            <div style="flex:1;min-width:0">
                                <div style="font-size:.78rem;font-weight:600;
                                             color:var(--text-primary);
                                             white-space:nowrap;overflow:hidden;
                                             text-overflow:ellipsis">
                                    <?= e($nu['nombre']) ?>
                                </div>
                                <div style="font-size:.68rem;color:var(--text-muted)">
                                    <?= timeAgo($nu['fecha_registro']) ?>
                                </div>
                            </div>
                            <span style="font-size:.65rem;padding:2px 6px;
                                          border-radius:var(--border-radius-full);
                                          background:var(--bg-surface-3);
                                          color:var(--text-muted);
                                          text-transform:capitalize">
                                <?= e($nu['rol']) ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <p style="text-align:center;color:var(--text-muted);
                                   padding:16px 0;font-size:.82rem">
                            No hay nuevos usuarios esta semana
                        </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Dispositivos -->
                <?php if (!empty($metricasDispositivos)): ?>
                <div class="admin-card">
                    <div class="admin-card__header">
                        <span class="admin-card__title">
                            <i class="bi bi-phone-fill"
                               style="color:var(--info)"></i>
                            Dispositivos (30d)
                        </span>
                    </div>
                    <div class="admin-card__body">
                        <?php
                        $totalDisp = array_sum($dispositivosMap);
                        $dispInfo  = [
                            'desktop' => ['icon'=>'bi-pc-display', 'color'=>'var(--info)',    'label'=>'Desktop'],
                            'mobile'  => ['icon'=>'bi-phone-fill', 'color'=>'var(--success)', 'label'=>'Móvil'],
                            'tablet'  => ['icon'=>'bi-tablet-fill','color'=>'var(--warning)', 'label'=>'Tablet'],
                        ];
                        foreach ($dispInfo as $disp => $info):
                            $cnt = (int)($dispositivosMap[$disp] ?? 0);
                            if (!$cnt) continue;
                            $pct = $totalDisp > 0 ? round(($cnt / $totalDisp) * 100) : 0;
                        ?>
                        <div style="display:flex;align-items:center;
                                    gap:10px;margin-bottom:12px">
                            <i class="bi <?= $info['icon'] ?>"
                               style="color:<?= $info['color'] ?>;
                                      font-size:1rem;width:20px;
                                      text-align:center"></i>
                            <div style="flex:1">
                                <div style="display:flex;justify-content:space-between;
                                            font-size:.78rem;margin-bottom:4px">
                                    <span style="color:var(--text-secondary)">
                                        <?= $info['label'] ?>
                                    </span>
                                    <strong style="color:var(--text-primary)">
                                        <?= $pct ?>%
                                    </strong>
                                </div>
                                <div style="height:6px;background:var(--bg-surface-3);
                                            border-radius:3px;overflow:hidden">
                                    <div style="height:100%;width:<?= $pct ?>%;
                                                background:<?= $info['color'] ?>;
                                                border-radius:3px;
                                                transition:width 1s ease"></div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

    </div><!-- /.admin-content -->

</main>

</div><!-- /.admin-wrapper -->

<!-- Toast container -->
<div class="toast-container" id="toastContainer"></div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── Modo oscuro ───────────────────────────────────────────
    const saved = localStorage.getItem('pd_theme') ??
        (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', saved);
    const darkIcon = document.getElementById('darkIcon');
    if (darkIcon) {
        darkIcon.className = saved === 'dark'
            ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
    }

    window.toggleDark = function () {
        const curr = document.documentElement.getAttribute('data-theme');
        const next = curr === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('pd_theme', next);
        const icon = document.getElementById('darkIcon');
        if (icon) icon.className = next === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
    };

    // ── Sidebar toggle ────────────────────────────────────────
    window.toggleSidebar = function () {
        const sidebar = document.getElementById('adminSidebar');
        const overlay = document.getElementById('adminOverlay');
        const isOpen  = sidebar.classList.toggle('open');
        // Usamos clase .show en lugar de style.display para evitar conflictos con CSS
        if (isOpen) {
            overlay.classList.add('show');
        } else {
            overlay.classList.remove('show');
        }
        document.body.style.overflow = isOpen ? 'hidden' : '';
    };

    window.closeSidebar = function () {
        document.getElementById('adminSidebar').classList.remove('open');
        document.getElementById('adminOverlay').classList.remove('show');
        document.body.style.overflow = '';
    };

    // ── Charts ────────────────────────────────────────────────
    const isDark = () => document.documentElement.getAttribute('data-theme') === 'dark';
    const gridColor  = () => isDark() ? '#2a2a45' : '#e2e8f0';
    const tickColor  = () => isDark() ? '#606080' : '#8888aa';
    const labelColor = () => isDark() ? '#b0b0c0' : '#555577';

    // Visitas chart
    let visitasChart = null;

    function buildVisitasChart(labels, data) {
        const ctx = document.getElementById('visitasChart')?.getContext('2d');
        if (!ctx) return;

        if (visitasChart) visitasChart.destroy();

        visitasChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Visitas',
                    data,
                    borderColor:     '#e63946',
                    backgroundColor: 'rgba(230,57,70,.1)',
                    borderWidth:     2.5,
                    pointBackgroundColor: '#e63946',
                    pointRadius:     4,
                    pointHoverRadius:6,
                    fill:            true,
                    tension:         0.4,
                }],
            },
            options: {
                responsive:          true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: isDark() ? '#1a1a2e' : '#fff',
                        titleColor:      isDark() ? '#e8e8f0' : '#1a1a2e',
                        bodyColor:       isDark() ? '#a0a0c0' : '#4a4a6a',
                        borderColor:     isDark() ? '#2a2a45' : '#dee2e6',
                        borderWidth:     1,
                    },
                },
                scales: {
                    x: {
                        grid:  { color: gridColor() },
                        ticks: { color: tickColor(), font: { size: 11 } },
                    },
                    y: {
                        grid:  { color: gridColor() },
                        ticks: { color: tickColor(), font: { size: 11 } },
                        beginAtZero: true,
                    },
                },
            },
        });
    }

    buildVisitasChart(<?= $chartVisitasLabels ?>, <?= $chartVisitasData ?>);

    // Cambiar período visitas
    // Función principal — se llama desde los botones 7d / 14d / 30d
    window.updateVisitasChart = async function (dias) {

        // ── 1. Actualizar título INMEDIATAMENTE (sin esperar fetch) ──
        const titleEl = document.getElementById('visitasChartTitle');
        if (titleEl) {
            titleEl.textContent = 'Visitas diarias (últimos ' + dias + ' días)';
        }

        // ── 2. Actualizar estilos de botones ──────────────────────
        [7, 14, 30].forEach(function(d) {
            const btn = document.getElementById('btn' + d + 'd');
            if (!btn) return;
            const isActive = (d === dias);
            btn.style.background  = isActive ? 'var(--primary)' : 'transparent';
            btn.style.color       = isActive ? '#fff'           : 'var(--text-muted)';
            btn.style.borderColor = isActive ? 'var(--primary)' : 'var(--border-color)';
        });

        // ── 3. Obtener datos del servidor y redibujar gráfica ─────
        try {
            const res = await fetch(
                (window.APP?.url ?? '') + '/ajax/handler.php',
                {
                    method:  'POST',
                    headers: {
                        'Content-Type':     'application/json',
                        'X-CSRF-Token':     window.APP?.csrfToken ?? '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        action: 'get_stats',
                        tipo:   'visitas',
                        dias:   dias,
                    }),
                }
            );
            const json = await res.json();
            if (json.success) {
                buildVisitasChart(json.data.labels, json.data.data);

                // Mostrar/ocultar badge de fuente según si hay datos reales
                const badge = document.getElementById('visitasChartBadge');
                if (badge) {
                    const sinDatos = (json.data.data ?? []).every(function(v){ return v === 0; });
                    badge.style.display = sinDatos ? 'inline' : 'none';
                }
            }
        } catch (err) {
            console.error('[updateVisitasChart]', err);
        }
    };

    // Mantener compatibilidad si algo llama a changeChart directamente
    window.changeChart = window.updateVisitasChart;

    // Estado de noticias (donut)
    const ctxEstado = document.getElementById('estadoChart')?.getContext('2d');
    if (ctxEstado) {
        new Chart(ctxEstado, {
            type: 'doughnut',
            data: {
                labels: ['Publicadas', 'Borradores', 'Programadas', 'Revisión'],
                datasets: [{
                    data: [
                        <?= (int)($notiEstado['publicado']  ?? 0) ?>,
                        <?= (int)($notiEstado['borrador']   ?? 0) ?>,
                        <?= (int)($notiEstado['programado'] ?? 0) ?>,
                        <?= (int)($notiEstado['revision']   ?? 0) ?>,
                    ],
                    backgroundColor: ['#22c55e','#f59e0b','#3b82f6','#8b5cf6'],
                    borderWidth:  3,
                    borderColor:  isDark() ? '#131320' : '#fff',
                    hoverOffset:  6,
                }],
            },
            options: {
                responsive:          true,
                maintainAspectRatio: false,
                cutout:              '65%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color:   labelColor(),
                            padding: 12,
                            usePointStyle: true,
                            font: { size: 11 },
                        },
                    },
                },
            },
        });
    }

    // Categorías (donut)
    const ctxCat = document.getElementById('catChart')?.getContext('2d');
    if (ctxCat) {
        new Chart(ctxCat, {
            type: 'doughnut',
            data: {
                labels:   <?= $chartCatLabels ?>,
                datasets: [{
                    data:            <?= $chartCatData ?>,
                    backgroundColor: <?= $chartCatColors ?>,
                    borderWidth:     3,
                    borderColor:     isDark() ? '#131320' : '#fff',
                    hoverOffset:     6,
                }],
            },
            options: {
                responsive:          true,
                maintainAspectRatio: false,
                cutout:              '60%',
                plugins: {
                    legend: { display: false },
                },
            },
        });
    }

    // ── Toggle rápido publicar/despublicar ────────────────────
    document.querySelectorAll('.quick-toggle').forEach(chk => {
        chk.addEventListener('change', async function () {
            const id     = this.dataset.id;
            const tabla  = this.dataset.tabla;
            const campo  = 'estado';
            const valor  = this.checked ? 'publicado' : 'borrador';
            const orig   = this.checked;

            try {
                const res  = await fetch(`${window.APP?.url ?? ''}/ajax/handler.php`, {
                    method: 'POST',
                    headers: {
                        'Content-Type':     'application/json',
                        'X-CSRF-Token':     window.APP?.csrfToken ?? '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        action: 'admin_toggle_status',
                        tabla, id, campo, valor,
                    }),
                });
                const data = await res.json();

                if (data.success) {
                    PDApp?.showToast(
                        valor === 'publicado'
                            ? 'Noticia publicada' : 'Noticia despublicada',
                        'success', 2000
                    );
                } else {
                    this.checked = !orig;
                    PDApp?.showToast('Error al actualizar estado.', 'error');
                }
            } catch {
                this.checked = !orig;
                PDApp?.showToast('Error de conexión.', 'error');
            }
        });
    });

    // ── Backup ────────────────────────────────────────────────
    window.createBackup = async function () {
        const result = await Swal.fire({
            title:             '¿Crear backup de la BD?',
            text:              'Se generará un archivo SQL completo del sistema.',
            icon:              'question',
            showCancelButton:  true,
            confirmButtonText: 'Sí, crear backup',
            cancelButtonText:  'Cancelar',
            confirmButtonColor:'#e63946',
        });

        if (!result.isConfirmed) return;

        Swal.fire({
            title:             'Generando backup...',
            text:              'Por favor espera.',
            allowOutsideClick: false,
            didOpen:           () => Swal.showLoading(),
        });

        try {
            const res  = await fetch(`${window.APP?.url ?? ''}/ajax/handler.php`, {
                method: 'POST',
                headers: {
                    'Content-Type':     'application/json',
                    'X-CSRF-Token':     window.APP?.csrfToken ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ action: 'create_backup' }),
            });
            const data = await res.json();

            Swal.close();

            if (data.success) {
                Swal.fire({
                    title: '¡Backup creado!',
                    html:  `Archivo: <code>${data.archivo}</code><br>
                            Tamaño: ${(data.tamano / 1024).toFixed(1)} KB`,
                    icon:  'success',
                });
            } else {
                Swal.fire('Error', data.error || 'No se pudo crear el backup.', 'error');
            }
        } catch {
            Swal.close();
            PDApp?.showToast('Error al crear el backup.', 'error');
        }
    };

    // ── Configuración APP global ──────────────────────────────
    window.APP = window.APP || {
        url:       '<?= APP_URL ?>',
        csrfToken: '<?= csrfToken() ?>',
        userId:    <?= (int)$usuario['id'] ?>,
    };

});
</script>

</body>
</html>