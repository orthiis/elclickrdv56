<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Admin: Configuración Global
 * ============================================================
 * Archivo : admin/configuracion.php
 * Versión : 2.0.0
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$auth->requireSuperAdmin();
$usuario = currentUser();

// ── Tab activa ────────────────────────────────────────────────
$tabActiva = cleanInput($_GET['tab'] ?? 'general');
$tabsValidas = ['general','apariencia','contenido','redes',
                'monetizacion','clima','notificaciones',
                'analytics','sistema'];
if (!in_array($tabActiva, $tabsValidas, true)) {
    $tabActiva = 'general';
}

// ── Procesar formulario ───────────────────────────────────────
$errors     = [];
$successMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de seguridad inválido.';
    } else {
        $postAction = cleanInput($_POST['action'] ?? 'save_config');

        if ($postAction === 'save_config') {
            $configs = [];

            // ── Recoger todos los campos del formulario ───────
            $camposPermitidos = [
                // General
                'site_nombre','site_tagline','site_descripcion_seo',
                'site_idioma','site_timezone','site_formato_fecha',
                'site_modo_mantenimiento',
                // Apariencia
                'apariencia_modo_oscuro','apariencia_color_primario',
                'apariencia_color_secundario','apariencia_color_acento',
                'apariencia_tipografia','apariencia_layout',
                // Contenido
                'contenido_cats_en_index','contenido_noticias_bloque',
                'contenido_orden',
                'contenido_breaking_activo','contenido_trending_activo',
                'contenido_videos_activo','contenido_podcasts_activo',
                'contenido_opinion_activo',
                // Breaking
                'breaking_velocidad','breaking_autoplay',
                // Redes
                'social_facebook','social_twitter','social_instagram',
                'social_tiktok','social_youtube',
                'social_whatsapp','social_telegram',
                // Monetización
                'ads_activos_global','ads_header','ads_entre_noticias',
                'ads_sidebar','ads_dentro_articulo','ads_frecuencia',
                'premium_precio_mensual','premium_precio_anual',
                // Clima
                'clima_ciudad','clima_unidad','clima_api_key',
                // Notificaciones
                'notif_push_activas','notif_frecuencia',
                'notif_breaking','notif_trending',
                // Analytics
                'analytics_google_id','analytics_scroll_tracking',
                'analytics_heatmap',
            ];

            foreach ($camposPermitidos as $campo) {
                if (isset($_POST[$campo])) {
                    $val = cleanInput($_POST[$campo], 5000);
                    $configs[$campo] = $val;
                } else {
                    // Para checkboxes no enviados = false
                    $boolCampos = [
                        'site_modo_mantenimiento',
                        'contenido_breaking_activo','contenido_trending_activo',
                        'contenido_videos_activo','contenido_podcasts_activo',
                        'contenido_opinion_activo','breaking_autoplay',
                        'ads_activos_global','ads_header','ads_entre_noticias',
                        'ads_sidebar','ads_dentro_articulo',
                        'notif_push_activas','notif_breaking','notif_trending',
                        'analytics_scroll_tracking','analytics_heatmap',
                    ];
                    if (in_array($campo, $boolCampos, true)) {
                        $configs[$campo] = '0';
                    }
                }
            }

            // Subir logo
            if (!empty($_FILES['site_logo']['name']) &&
                $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
                $upload = uploadImage($_FILES['site_logo'], 'noticias', 'logo');
                if ($upload['success']) {
                    $configs['site_logo'] = $upload['path'];
                } else {
                    $errors[] = 'Error al subir logo: ' . $upload['error'];
                }
            }

            // Subir favicon
            if (!empty($_FILES['site_favicon']['name']) &&
                $_FILES['site_favicon']['error'] === UPLOAD_ERR_OK) {
                $upload = uploadImage($_FILES['site_favicon'], 'noticias', 'favicon');
                if ($upload['success']) {
                    $configs['site_favicon'] = $upload['path'];
                } else {
                    $errors[] = 'Error al subir favicon: ' . $upload['error'];
                }
            }

            if (empty($errors)) {
                $ok = Config::setMultiple($configs);
                if ($ok) {
                    logActividad(
                        (int)$usuario['id'],
                        'update_config',
                        'configuracion',
                        null,
                        'Actualizó ' . count($configs) . ' configuraciones en: ' . $tabActiva
                    );
                    $successMsg = '✅ Configuración guardada correctamente.';
                    // Recargar config
                    Config::reload();
                } else {
                    $errors[] = 'Error al guardar. Intenta de nuevo.';
                }
            }
        }
    }
}

// ── Cargar configuración actual ───────────────────────────────
$cfg = Config::group('');

// Helpers
function cfgVal(string $key, mixed $default = ''): mixed {
    return Config::get($key, $default);
}

function cfgBool(string $key, bool $default = false): bool {
    return Config::bool($key, $default);
}

function cfgInt(string $key, int $default = 0): int {
    return Config::int($key, $default);
}

// ── Timezones disponibles ─────────────────────────────────────
$timezones = [
    'America/Santo_Domingo' => 'Santo Domingo (UTC-4)',
    'America/New_York'      => 'New York (UTC-5)',
    'America/Chicago'       => 'Chicago (UTC-6)',
    'America/Los_Angeles'   => 'Los Angeles (UTC-8)',
    'America/Mexico_City'   => 'Ciudad de México (UTC-6)',
    'America/Bogota'        => 'Bogotá (UTC-5)',
    'America/Lima'          => 'Lima (UTC-5)',
    'America/Santiago'      => 'Santiago (UTC-3)',
    'America/Buenos_Aires'  => 'Buenos Aires (UTC-3)',
    'America/Sao_Paulo'     => 'São Paulo (UTC-3)',
    'Europe/Madrid'         => 'Madrid (UTC+1)',
    'UTC'                   => 'UTC',
];

// ── Fuentes disponibles ───────────────────────────────────────
$fuentes = [
    'Inter'          => 'Inter (Recomendada)',
    'Roboto'         => 'Roboto',
    'Open Sans'      => 'Open Sans',
    'Lato'           => 'Lato',
    'Montserrat'     => 'Montserrat',
    'Source Sans Pro'=> 'Source Sans Pro',
    'Nunito'         => 'Nunito',
    'Poppins'        => 'Poppins',
];

$pageTitle = 'Configuración — Panel Admin';
?>
<!DOCTYPE html>
<html lang="es" data-theme="<?= e(cfgVal('apariencia_modo_oscuro', 'auto')) ?>">
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
        /* ── Reutilizar estilos del dashboard ────────────────── */
        body { padding-bottom: 0; background: var(--bg-body); }

        .admin-wrapper { display: flex; min-height: 100vh; }

        .admin-sidebar {
            width: 260px;
            background: var(--secondary-dark);
            position: fixed;
            top: 0; left: 0;
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
        }
        .admin-sidebar__logo a {
            display: flex; align-items: center; gap: 10px; text-decoration: none;
        }
        .admin-sidebar__logo-icon {
            width: 36px; height: 36px;
            background: var(--primary); border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1rem; flex-shrink: 0;
        }
        .admin-sidebar__logo-text {
            font-family: var(--font-serif); font-size: 1rem;
            font-weight: 800; color: #fff; line-height: 1.1;
        }
        .admin-sidebar__logo-sub {
            font-size: .65rem; color: rgba(255,255,255,.4);
            font-family: var(--font-sans); font-weight: 400;
            text-transform: uppercase; letter-spacing: .06em;
        }

        .admin-nav { flex: 1; padding: 12px 0; overflow-y: auto; }
        .admin-nav__section {
            padding: 14px 20px 6px;
            font-size: .62rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .1em;
            color: rgba(255,255,255,.25);
        }
        .admin-nav__item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 20px;
            color: rgba(255,255,255,.6); font-size: .82rem;
            font-weight: 500; text-decoration: none;
            transition: all var(--transition-fast);
            position: relative;
        }
        .admin-nav__item:hover {
            background: rgba(255,255,255,.06);
            color: rgba(255,255,255,.9);
        }
        .admin-nav__item.active {
            background: rgba(230,57,70,.18);
            color: #fff; font-weight: 600;
        }
        .admin-nav__item.active::before {
            content: ''; position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 3px; background: var(--primary);
            border-radius: 0 3px 3px 0;
        }
        .admin-nav__item i { width: 18px; text-align: center; font-size: .9rem; flex-shrink: 0; }
        .admin-nav__badge {
            margin-left: auto;
            background: var(--primary); color: #fff;
            font-size: .6rem; font-weight: 700;
            padding: 2px 6px;
            border-radius: var(--border-radius-full);
            min-width: 18px; text-align: center;
            flex-shrink: 0;
        }

        .admin-sidebar__user {
            padding: 14px 20px;
            border-bottom: 1px solid rgba(255,255,255,.07);
            display: flex; align-items: center; gap: 10px;
        }
        .admin-sidebar__user img {
            width: 36px; height: 36px;
            border-radius: 50%; object-fit: cover;
            border: 2px solid rgba(255,255,255,.15);
        }
        .admin-sidebar__user-name {
            font-size: .82rem; font-weight: 600;
            color: rgba(255,255,255,.9); display: block; line-height: 1.2;
        }
        .admin-sidebar__user-role {
            font-size: .68rem; color: rgba(255,255,255,.4); text-transform: capitalize;
        }

        .admin-sidebar__footer {
            padding: 16px 20px;
            border-top: 1px solid rgba(255,255,255,.07);
        }

        .admin-main { margin-left: 260px; flex: 1; min-height: 100vh; display: flex; flex-direction: column; }

        .admin-topbar {
            background: var(--bg-surface);
            border-bottom: 1px solid var(--border-color);
            padding: 0 28px; height: 62px;
            display: flex; align-items: center; gap: 16px;
            position: sticky; top: 0; z-index: var(--z-sticky);
            box-shadow: var(--shadow-sm);
        }
        .admin-topbar__toggle {
            display: none; color: var(--text-muted);
            font-size: 1.2rem; padding: 6px;
            border-radius: var(--border-radius-sm);
        }
        .admin-topbar__title {
            font-family: var(--font-serif); font-size: 1.1rem;
            font-weight: 700; color: var(--text-primary);
        }
        .admin-topbar__right { margin-left: auto; display: flex; align-items: center; gap: 10px; }

        .admin-content { padding: 28px; flex: 1; }

        /* ── Config Tabs ─────────────────────────────────────── */
        .config-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            background: var(--bg-surface);
            border-radius: var(--border-radius-xl);
            padding: 8px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }

        .config-tab {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 9px 16px;
            border-radius: var(--border-radius-lg);
            font-size: .8rem;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            transition: all var(--transition-fast);
            white-space: nowrap;
        }

        .config-tab:hover {
            background: var(--bg-surface-2);
            color: var(--text-primary);
        }

        .config-tab.active {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 2px 8px rgba(230,57,70,.3);
        }

        /* ── Config Form ─────────────────────────────────────── */
        .config-card {
            background: var(--bg-surface);
            border-radius: var(--border-radius-xl);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .config-card__header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-color);
            background: var(--bg-surface-2);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .config-card__icon {
            width: 36px;
            height: 36px;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .9rem;
        }

        .config-card__title {
            font-size: .9rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .config-card__desc {
            font-size: .75rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .config-card__body {
            padding: 24px;
        }

        /* ── Form Fields ─────────────────────────────────────── */
        .config-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .config-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .config-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .config-field.full-width {
            grid-column: 1 / -1;
        }

        .config-label {
            font-size: .8rem;
            font-weight: 600;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .config-label .required {
            color: var(--primary);
            font-size: .7rem;
        }

        .config-input,
        .config-select,
        .config-textarea {
            padding: 10px 14px;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            font-size: .85rem;
            background: var(--bg-surface);
            color: var(--text-primary);
            transition: border-color var(--transition-fast),
                        box-shadow var(--transition-fast);
            font-family: var(--font-sans);
        }

        .config-input:focus,
        .config-select:focus,
        .config-textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(230,57,70,.12);
        }

        .config-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%238888aa' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 32px;
        }

        .config-textarea {
            resize: vertical;
            min-height: 80px;
        }

        .config-helper {
            font-size: .72rem;
            color: var(--text-muted);
        }

        /* ── Toggle/Checkbox ─────────────────────────────────── */
        .config-toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .config-toggle-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .config-toggle-label {
            display: flex;
            flex-direction: column;
        }

        .config-toggle-label strong {
            font-size: .85rem;
            color: var(--text-primary);
            font-weight: 600;
        }

        .config-toggle-label small {
            font-size: .72rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .toggle-wrap {
            position: relative;
            width: 44px;
            height: 24px;
            flex-shrink: 0;
        }

        .toggle-wrap input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            inset: 0;
            background: var(--bg-surface-3);
            border-radius: var(--border-radius-full);
            cursor: pointer;
            transition: background var(--transition-fast);
        }

        .toggle-slider::before {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            background: #fff;
            border-radius: 50%;
            top: 3px;
            left: 3px;
            transition: transform var(--transition-fast);
            box-shadow: var(--shadow-sm);
        }

        .toggle-wrap input:checked + .toggle-slider {
            background: var(--primary);
        }

        .toggle-wrap input:checked + .toggle-slider::before {
            transform: translateX(20px);
        }

        /* ── Color picker ────────────────────────────────────── */
        .color-picker-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .color-preview {
            width: 40px;
            height: 40px;
            border-radius: var(--border-radius);
            border: 2px solid var(--border-color);
            cursor: pointer;
        }

        input[type="color"] {
            opacity: 0;
            width: 0;
            height: 0;
            position: absolute;
        }

        /* ── Social links ────────────────────────────────────── */
        .social-input-wrap {
            display: flex;
            align-items: center;
            gap: 0;
        }

        .social-input-icon {
            padding: 10px 14px;
            background: var(--bg-surface-2);
            border: 2px solid var(--border-color);
            border-right: none;
            border-radius: var(--border-radius) 0 0 var(--border-radius);
            color: var(--text-muted);
            font-size: .9rem;
        }

        .social-input-wrap .config-input {
            border-radius: 0 var(--border-radius) var(--border-radius) 0;
            flex: 1;
        }

        /* ── Logo upload ─────────────────────────────────────── */
        .logo-upload-area {
            border: 2px dashed var(--border-color);
            border-radius: var(--border-radius-lg);
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all var(--transition-fast);
            background: var(--bg-surface-2);
        }

        .logo-upload-area:hover {
            border-color: var(--primary);
            background: rgba(230,57,70,.04);
        }

        /* ── Save button ─────────────────────────────────────── */
        .config-save-bar {
            position: sticky;
            bottom: 0;
            z-index: 100;
            background: var(--bg-surface);
            border-top: 1px solid var(--border-color);
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            box-shadow: 0 -4px 20px rgba(0,0,0,.06);
            margin: 0 -28px -28px;
        }

        .btn-save {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 28px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: var(--border-radius-lg);
            font-size: .9rem;
            font-weight: 700;
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .btn-save:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(230,57,70,.35);
        }

        .btn-save:disabled {
            opacity: .6;
            cursor: not-allowed;
            transform: none;
        }

        /* ── Responsive ──────────────────────────────────────── */
        @media (max-width: 768px) {
            .admin-sidebar { transform: translateX(-100%); }
            .admin-sidebar.open { transform: translateX(0); box-shadow: var(--shadow-xl); }
            .admin-main { margin-left: 0; }
            .admin-topbar__toggle { display: flex; }
            .admin-content { padding: 16px; }
            .config-row, .config-row-3 { grid-template-columns: 1fr; }
            .config-tabs { gap: 2px; }
            .config-tab { padding: 7px 10px; font-size: .72rem; }
            .config-save-bar { margin: 0 -16px -16px; }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">

<!-- ── SIDEBAR (mismo que dashboard) ─────────────────────────── -->
<aside class="admin-sidebar" id="adminSidebar">
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
            <span class="admin-sidebar__user-name"><?= e($usuario['nombre']) ?></span>
            <span class="admin-sidebar__user-role"><?= e($usuario['rol']) ?></span>
        </div>
    </div>

    <nav class="admin-nav">
        <div class="admin-nav__section">Principal</div>
        <a href="<?= APP_URL ?>/admin/dashboard.php" class="admin-nav__item">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <div class="admin-nav__section">Contenido</div>
        <a href="<?= APP_URL ?>/admin/noticias.php" class="admin-nav__item">
            <i class="bi bi-newspaper"></i> Noticias
        </a>
        <a href="<?= APP_URL ?>/admin/media.php" class="admin-nav__item">
            <i class="bi bi-images"></i> Multimedia
        </a>
        <a href="<?= APP_URL ?>/admin/comentarios.php" class="admin-nav__item">
            <i class="bi bi-chat-dots-fill"></i> Comentarios
        </a>
        <a href="<?= APP_URL ?>/admin/coberturasenvivo.php" class="admin-nav__item">
            <i class="bi bi-broadcast-pin"></i> Coberturas en Vivo
            <?php
            $livesActivosConf = (int)db()->count(
                "SELECT COUNT(*) FROM live_blog WHERE estado = 'activo'"
            );
            if ($livesActivosConf > 0):
            ?>
            <span class="admin-nav__badge" style="background:var(--danger)">
                <?= $livesActivosConf ?>
            </span>
            <?php endif; ?>
        </a>
        <div class="admin-nav__section">Monetización</div>
        <a href="<?= APP_URL ?>/admin/anuncios.php" class="admin-nav__item">
            <i class="bi bi-badge-ad-fill"></i> Anuncios
        </a>
        <div class="admin-nav__section">Gestión</div>
        <a href="<?= APP_URL ?>/admin/usuarios.php" class="admin-nav__item">
            <i class="bi bi-people-fill"></i> Usuarios
        </a>
        <div class="admin-nav__section">Sistema</div>
        <a href="<?= APP_URL ?>/admin/configuracion.php"
           class="admin-nav__item active">
            <i class="bi bi-gear-fill"></i> Configuración
        </a>
        <a href="<?= APP_URL ?>/index.php" target="_blank" class="admin-nav__item">
            <i class="bi bi-box-arrow-up-right"></i> Ver sitio
        </a>
    </nav>

    <div class="admin-sidebar__footer">
        <a href="<?= APP_URL ?>/admin/logout.php"
           class="admin-nav__item"
           style="border-radius:var(--border-radius-lg);color:rgba(255,255,255,.5)">
            <i class="bi bi-box-arrow-right"></i> Cerrar sesión
        </a>
    </div>
</aside>

<div class="admin-overlay" id="adminOverlay"
     onclick="closeSidebar()"
     style="display:none;position:fixed;inset:0;
            background:rgba(0,0,0,.5);z-index:calc(var(--z-header) - 1)">
</div>

<!-- ── MAIN ──────────────────────────────────────────────────── -->
<main class="admin-main">

    <div class="admin-topbar">
        <button class="admin-topbar__toggle"
                id="sidebarToggle"
                onclick="toggleSidebar()"
                style="background:none;border:none;cursor:pointer">
            <i class="bi bi-list"></i>
        </button>
        <h1 class="admin-topbar__title">
            <i class="bi bi-gear-fill"
               style="color:var(--primary);margin-right:6px"></i>
            Configuración Global
        </h1>
        <div class="admin-topbar__right">
            <button onclick="toggleDark()"
                    style="padding:8px;border-radius:var(--border-radius);
                           background:var(--bg-surface-2);
                           color:var(--text-muted);
                           border:1px solid var(--border-color);
                           cursor:pointer" id="darkBtn">
                <i class="bi bi-moon-stars-fill" id="darkIcon"></i>
            </button>
        </div>
    </div>

    <div class="admin-content">

        <!-- Mensajes -->
        <?php if ($successMsg): ?>
        <div style="background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);
                    border-radius:var(--border-radius-lg);padding:14px 18px;
                    margin-bottom:20px;color:var(--success);
                    display:flex;align-items:center;gap:10px;font-size:.875rem">
            <i class="bi bi-check-circle-fill" style="font-size:1.1rem"></i>
            <?= e($successMsg) ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);
                    border-radius:var(--border-radius-lg);padding:14px 18px;
                    margin-bottom:20px;color:var(--danger)">
            <?php foreach ($errors as $err): ?>
            <div style="display:flex;align-items:center;gap:8px;font-size:.875rem">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= e($err) ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Tabs de configuración -->
        <div class="config-tabs">
            <?php
            $tabsConfig = [
                'general'       => ['icon'=>'bi-globe2',          'label'=>'General'],
                'apariencia'    => ['icon'=>'bi-palette-fill',     'label'=>'Apariencia'],
                'contenido'     => ['icon'=>'bi-layout-text-sidebar-reverse','label'=>'Contenido'],
                'redes'         => ['icon'=>'bi-share-fill',       'label'=>'Redes sociales'],
                'monetizacion'  => ['icon'=>'bi-currency-dollar',  'label'=>'Monetización'],
                'clima'         => ['icon'=>'bi-cloud-sun-fill',   'label'=>'Clima'],
                'notificaciones'=> ['icon'=>'bi-bell-fill',        'label'=>'Notificaciones'],
                'analytics'     => ['icon'=>'bi-graph-up-arrow',   'label'=>'Analytics'],
                'sistema'       => ['icon'=>'bi-shield-lock-fill', 'label'=>'Sistema'],
            ];
            foreach ($tabsConfig as $tab => $info):
            ?>
            <a href="?tab=<?= e($tab) ?>"
               class="config-tab <?= $tabActiva === $tab ? 'active' : '' ?>">
                <i class="bi <?= e($info['icon']) ?>"></i>
                <span class="d-none d-md-inline"><?= e($info['label']) ?></span>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- ══════════════════════════════════════════════════
             FORMULARIO
             ══════════════════════════════════════════════════ -->
        <form method="POST"
              action="?tab=<?= e($tabActiva) ?>"
              enctype="multipart/form-data"
              id="configForm"
              novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_config">

            <?php
            // ══════════════════════════════════════════════════
            // TAB: GENERAL
            // ══════════════════════════════════════════════════
            if ($tabActiva === 'general'):
            ?>

            <div class="config-card">
                <div class="config-card__header">
                    <div class="config-card__icon"
                         style="background:rgba(230,57,70,.1);color:var(--primary)">
                        <i class="bi bi-globe2"></i>
                    </div>
                    <div>
                        <div class="config-card__title">Información del sitio</div>
                        <div class="config-card__desc">
                            Datos básicos e identidad de tu periódico digital
                        </div>
                    </div>
                </div>
                <div class="config-card__body">
                    <div class="config-row">
                        <div class="config-field">
                            <label class="config-label" for="site_nombre">
                                <i class="bi bi-type-h1"
                                   style="color:var(--primary)"></i>
                                Nombre del sitio
                                <span class="required">*</span>
                            </label>
                            <input type="text"
                                   id="site_nombre"
                                   name="site_nombre"
                                   class="config-input"
                                   value="<?= e(cfgVal('site_nombre', APP_NAME)) ?>"
                                   required
                                   maxlength="100"
                                   placeholder="Mi Periódico Digital">
                            <span class="config-helper">
                                Aparece en el header, SEO y emails
                            </span>
                        </div>

                        <div class="config-field">
                            <label class="config-label" for="site_tagline">
                                <i class="bi bi-chat-quote"
                                   style="color:var(--accent)"></i>
                                Eslogan
                            </label>
                            <input type="text"
                                   id="site_tagline"
                                   name="site_tagline"
                                   class="config-input"
                                   value="<?= e(cfgVal('site_tagline', APP_TAGLINE)) ?>"
                                   maxlength="150"
                                   placeholder="Tu fuente de noticias">
                        </div>
                    </div>

                    <div class="config-field" style="margin-bottom:20px">
                        <label class="config-label" for="site_descripcion_seo">
                            <i class="bi bi-search"
                               style="color:var(--info)"></i>
                            Descripción SEO
                        </label>
                        <textarea id="site_descripcion_seo"
                                  name="site_descripcion_seo"
                                  class="config-textarea"
                                  rows="3"
                                  maxlength="300"
                                  placeholder="Descripción del sitio para motores de búsqueda..."><?= e(cfgVal('site_descripcion_seo')) ?></textarea>
                        <span class="config-helper">
                            Máx. 300 caracteres. Aparece en Google, Facebook y Twitter.
                        </span>
                    </div>

                    <div class="config-row">
                        <div class="config-field">
                            <label class="config-label" for="site_idioma">
                                <i class="bi bi-translate"
                                   style="color:var(--success)"></i>
                                Idioma principal
                            </label>
                            <select id="site_idioma"
                                    name="site_idioma"
                                    class="config-select">
                                <option value="es"
                                    <?= cfgVal('site_idioma') === 'es' ? 'selected' : '' ?>>
                                    🇪🇸 Español
                                </option>
                                <option value="en"
                                    <?= cfgVal('site_idioma') === 'en' ? 'selected' : '' ?>>
                                    🇺🇸 English
                                </option>
                                <option value="pt"
                                    <?= cfgVal('site_idioma') === 'pt' ? 'selected' : '' ?>>
                                    🇧🇷 Português
                                </option>
                                <option value="fr"
                                    <?= cfgVal('site_idioma') === 'fr' ? 'selected' : '' ?>>
                                    🇫🇷 Français
                                </option>
                            </select>
                        </div>

                        <div class="config-field">
                            <label class="config-label" for="site_timezone">
                                <i class="bi bi-clock-fill"
                                   style="color:var(--warning)"></i>
                                Zona horaria
                            </label>
                            <select id="site_timezone"
                                    name="site_timezone"
                                    class="config-select">
                                <?php foreach ($timezones as $tz => $label): ?>
                                <option value="<?= e($tz) ?>"
                                    <?= cfgVal('site_timezone', 'America/Santo_Domingo') === $tz ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="config-field">
                            <label class="config-label" for="site_formato_fecha">
                                <i class="bi bi-calendar3"
                                   style="color:var(--info)"></i>
                                Formato de fecha
                            </label>
                            <select id="site_formato_fecha"
                                    name="site_formato_fecha"
                                    class="config-select">
                                <option value="full"
                                    <?= cfgVal('site_formato_fecha') === 'full' ? 'selected' : '' ?>>
                                    Completo (Lunes 24 de abril de 2026 · 3:00 PM)
                                </option>
                                <option value="short"
                                    <?= cfgVal('site_formato_fecha') === 'short' ? 'selected' : '' ?>>
                                    Corto (24 de abril de 2026)
                                </option>
                                <option value="medium"
                                    <?= cfgVal('site_formato_fecha') === 'medium' ? 'selected' : '' ?>>
                                    Medio (24 abril 2026)
                                </option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Logo y favicon -->
            <div class="config-card">
                <div class="config-card__header">
                    <div class="config-card__icon"
                         style="background:rgba(69,123,157,.1);color:var(--accent)">
                        <i class="bi bi-image-fill"></i>
                    </div>
                    <div>
                        <div class="config-card__title">Logo y Favicon</div>
                        <div class="config-card__desc">
                            Imágenes de identidad visual del sitio
                        </div>
                    </div>
                </div>
                <div class="config-card__body">
                    <div class="config-row">
                        <!-- Logo -->
                        <div class="config-field">
                            <label class="config-label">
                                <i class="bi bi-image"
                                   style="color:var(--primary)"></i>
                                Logo del sitio
                            </label>
                            <label class="logo-upload-area"
                                   for="site_logo_file">
                                <?php
                                $logoActual = cfgVal('site_logo', '');
                                if ($logoActual):
                                ?>
                                <img src="<?= APP_URL ?>/<?= e(ltrim($logoActual, '/')) ?>"
                                     alt="Logo actual"
                                     style="max-height:60px;max-width:200px;
                                            margin:0 auto 10px;object-fit:contain"
                                     id="logoPreview">
                                <?php else: ?>
                                <div id="logoPreview"
                                     style="font-size:2.5rem;opacity:.3;
                                            margin-bottom:10px">
                                    🖼️
                                </div>
                                <?php endif; ?>
                                <div style="font-size:.8rem;font-weight:600;
                                             color:var(--text-secondary)">
                                    Haz clic para subir el logo
                                </div>
                                <div style="font-size:.7rem;color:var(--text-muted);
                                             margin-top:4px">
                                    PNG, WebP · Recomendado: 200×60px
                                </div>
                            </label>
                            <input type="file"
                                   id="site_logo_file"
                                   name="site_logo"
                                   accept="image/*"
                                   style="display:none"
                                   onchange="previewImage(this, 'logoPreview')">
                        </div>

                        <!-- Favicon -->
                        <div class="config-field">
                            <label class="config-label">
                                <i class="bi bi-star-fill"
                                   style="color:var(--warning)"></i>
                                Favicon
                            </label>
                            <label class="logo-upload-area"
                                   for="site_favicon_file">
                                <?php
                                $faviconActual = cfgVal('site_favicon', '');
                                if ($faviconActual):
                                ?>
                                <img src="<?= APP_URL ?>/<?= e(ltrim($faviconActual, '/')) ?>"
                                     alt="Favicon actual"
                                     style="width:48px;height:48px;
                                            margin:0 auto 10px;
                                            object-fit:contain"
                                     id="faviconPreview">
                                <?php else: ?>
                                <div id="faviconPreview"
                                     style="font-size:2.5rem;opacity:.3;
                                            margin-bottom:10px">
                                    ⭐
                                </div>
                                <?php endif; ?>
                                <div style="font-size:.8rem;font-weight:600;
                                             color:var(--text-secondary)">
                                    Subir favicon
                                </div>
                                <div style="font-size:.7rem;color:var(--text-muted);
                                             margin-top:4px">
                                    ICO, PNG · 32×32px o 64×64px
                                </div>
                            </label>
                            <input type="file"
                                   id="site_favicon_file"
                                   name="site_favicon"
                                   accept="image/*,.ico"
                                   style="display:none"
                                   onchange="previewImage(this, 'faviconPreview')">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modo mantenimiento -->
            <div class="config-card">
                <div class="config-card__header">
                    <div class="config-card__icon"
                         style="background:rgba(239,68,68,.1);color:var(--danger)">
                        <i class="bi bi-tools"></i>
                    </div>
                    <div>
                        <div class="config-card__title">Modo Mantenimiento</div>
                        <div class="config-card__desc">
                            Oculta el sitio público para visitantes
                        </div>
                    </div>
                </div>
                <div class="config-card__body">
                    <div class="config-toggle-row">
                        <div class="config-toggle-label">
                            <strong>
                                <i class="bi bi-wrench-adjustable"
                                   style="color:var(--danger)"></i>
                                Activar modo mantenimiento
                            </strong>
                            <small>
                                Los visitantes verán una página de mantenimiento.
                                Los admins pueden seguir accediendo.
                            </small>
                        </div>
                        <label class="toggle-wrap">
                            <input type="checkbox"
                                   name="site_modo_mantenimiento"
                                   value="1"
                                   <?= cfgBool('site_modo_mantenimiento') ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <?php
            // ══════════════════════════════════════════════════
            // TAB: APARIENCIA
            // ══════════════════════════════════════════════════
            elseif ($tabActiva === 'apariencia'):
            ?>

            <div class="config-card">
                <div class="config-card__header">
                    <div class="config-card__icon"
                         style="background:rgba(139,92,246,.1);color:#8b5cf6">
                        <i class="bi bi-palette-fill"></i>
                    </div>
                    <div>
                        <div class="config-card__title">Colores del tema</div>
                        <div class="config-card__desc">
                            Personaliza la paleta de colores de toda la web
                        </div>
                    </div>
                </div>
                <div class="config-card__body">
                    <div class="config-row-3">

                        <!-- Color primario -->
                        <div class="config-field">
                            <label class="config-label">
                                <i class="bi bi-circle-fill"
                                   style="color:var(--primary)"></i>
                                Color primario
                            </label>
                            <div class="color-picker-wrap">
                                <div class="color-preview"
                                     id="previewPrimario"
                                     style="background:<?= e(cfgVal('apariencia_color_primario', '#e63946')) ?>"
                                     onclick="document.getElementById('color_primario').click()">
                                </div>
                                <input type="color"
                                       id="color_primario"
                                       value="<?= e(cfgVal('apariencia_color_primario', '#e63946')) ?>"
                                       onchange="updateColor('primario', this.value)">
                                <input type="text"
                                       name="apariencia_color_primario"
                                       id="hex_primario"
                                       class="config-input"
                                       value="<?= e(cfgVal('apariencia_color_primario', '#e63946')) ?>"
                                       maxlength="7"
                                       pattern="#[0-9a-fA-F]{6}"
                                       style="width:110px"
                                       oninput="syncColor('primario', this.value)">
                            </div>
                            <span class="config-helper">
                                Botones, badges, links activos
                            </span>
                        </div>

                        <!-- Color secundario -->
                        <div class="config-field">
                            <label class="config-label">
                                <i class="bi bi-circle-fill"
                                   style="color:var(--secondary)"></i>
                                Color secundario
                            </label>
                            <div class="color-picker-wrap">
                                <div class="color-preview"
                                     id="previewSecundario"
                                     style="background:<?= e(cfgVal('apariencia_color_secundario', '#1d3557')) ?>"
                                     onclick="document.getElementById('color_secundario').click()">
                                </div>
                                <input type="color"
                                       id="color_secundario"
                                       value="<?= e(cfgVal('apariencia_color_secundario', '#1d3557')) ?>"
                                       onchange="updateColor('secundario', this.value)">
                                <input type="text"
                                       name="apariencia_color_secundario"
                                       id="hex_secundario"
                                       class="config-input"
                                       value="<?= e(cfgVal('apariencia_color_secundario', '#1d3557')) ?>"
                                       maxlength="7"
                                       style="width:110px"
                                       oninput="syncColor('secundario', this.value)">
                            </div>
                            <span class="config-helper">
                                Header, footer, navbar
                            </span>
                        </div>

                        <!-- Color acento -->
                        <div class="config-field">
                            <label class="config-label">
                                <i class="bi bi-circle-fill"
                                   style="color:var(--accent)"></i>
                                Color de acento
                            </label>
                            <div class="color-picker-wrap">
                                <div class="color-preview"
                                     id="previewAcento"
                                     style="background:<?= e(cfgVal('apariencia_color_acento', '#457b9d')) ?>"
                                     onclick="document.getElementById('color_acento').click()">
                                </div>
                                <input type="color"
                                       id="color_acento"
                                       value="<?= e(cfgVal('apariencia_color_acento', '#457b9d')) ?>"
                                       onchange="updateColor('acento', this.value)">
                                <input type="text"
                                       name="apariencia_color_acento"
                                       id="hex_acento"
                                       class="config-input"
                                       value="<?= e(cfgVal('apariencia_color_acento', '#457b9d')) ?>"
                                       maxlength="7"
                                       style="width:110px"
                                       oninput="syncColor('acento', this.value)">
                            </div>
                            <span class="config-helper">
                                Detalles y elementos secundarios
                            </span>
                        </div>
                    </div>

                    <!-- Preview de colores -->
                    <div style="padding:16px;border-radius:var(--border-radius-lg);
                                border:1px solid var(--border-color);
                                background:var(--bg-surface-2);margin-top:8px">
                        <div style="font-size:.75rem;font-weight:700;
                                     color:var(--text-muted);margin-bottom:12px;
                                     text-transform:uppercase;letter-spacing:.06em">
                            Preview de colores
                        </div>
                        <div style="display:flex;gap:10px;flex-wrap:wrap">
                            <button style="padding:8px 16px;border-radius:var(--border-radius-full);
                                           color:#fff;font-size:.8rem;font-weight:600;
                                           background:var(--primary);border:none"
                                    type="button">
                                Botón primario
                            </button>
                            <button style="padding:8px 16px;border-radius:var(--border-radius-full);
                                           color:#fff;font-size:.8rem;font-weight:600;
                                           background:var(--secondary);border:none"
                                    type="button">
                                Botón secundario
                            </button>
                            <span style="padding:4px 12px;border-radius:var(--border-radius-full);
                                          background:var(--accent);color:#fff;
                                          font-size:.75rem;font-weight:700">
                                Badge acento
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="config-card">
                <div class="config-card__header">
                    <div class="config-card__icon"
                         style="background:rgba(245,158,11,.1);color:var(--warning)">
                        <i class="bi bi-fonts"></i>
                    </div>
                    <div>
                        <div class="config-card__title">Tipografía y Layout</div>
                        <div class="config-card__desc">
                            Fuente principal y disposición del contenido
                        </div>
                    </div>
                </div>
                <div class="config-card__body">
                    <div class="config-row">
                        <div class="config-field">
                            <label class="config-label" for="apariencia_tipografia">
                                <i class="bi bi-type"
                                   style="color:var(--warning)"></i>
                                Tipografía principal
                            </label>
                            <select id="apariencia_tipografia"
                                    name="apariencia_tipografia"
                                    class="config-select">
                                <?php foreach ($fuentes as $fuente => $label): ?>
                                <option value="<?= e($fuente) ?>"
                                    <?= cfgVal('apariencia_tipografia', 'Inter') === $fuente ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="config-field">
                            <label class="config-label" for="apariencia_layout">
                                <i class="bi bi-layout-split"
                                   style="color:var(--info)"></i>
                                Layout del sitio
                            </label>
                            <select id="apariencia_layout"
                                    name="apariencia_layout"
                                    class="config-select">
                                <option value="amplio"
                                    <?= cfgVal('apariencia_layout') === 'amplio' ? 'selected' : '' ?>>
                                    Amplio (max-width: 1400px)
                                </option>
                                <option value="compacto"
                                    <?= cfgVal('apariencia_layout') === 'compacto' ? 'selected' : '' ?>>
                                    Compacto (max-width: 1100px)
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="config-toggle-row">
                        <div class="config-toggle-label">
                            <strong>
                                <i class="bi bi-moon-stars-fill"
                                   style="color:var(--secondary)"></i>
                                Modo oscuro
                            </strong>
                            <small>
                                auto = según preferencia del sistema /
                                claro = siempre claro /
                                oscuro = siempre oscuro
                            </small>
                        </div>
                        <select name="apariencia_modo_oscuro"
                                class="config-select"
                                style="width:140px">
                            <option value="auto"
                                <?= cfgVal('apariencia_modo_oscuro') === 'auto' ? 'selected' : '' ?>>
                                🔄 Automático
                            </option>
                            <option value="claro"
                                <?= cfgVal('apariencia_modo_oscuro') === 'claro' ? 'selected' : '' ?>>
                                ☀️ Siempre claro
                            </option>
                            <option value="oscuro"
                                <?= cfgVal('apariencia_modo_oscuro') === 'oscuro' ? 'selected' : '' ?>>
                                🌙 Siempre oscuro
                            </option>
                        </select>
                    </div>
                </div>
            </div>

            <?php
            // ══════════════════════════════════════════════════
            // TAB: CONTENIDO
            // ══════════════════════════════════════════════════
            elseif ($tabActiva === 'contenido'):
            ?>

            <div class="config-card">
                <div class="config-card__header">
                    <div class="config-card__icon"
                         style="background:rgba(59,130,246,.1);color:var(--info)">
                        <i class="bi bi-layout-text-sidebar-reverse"></i>
                    </div>
                    <div>
                        <div class="config-card__title">Control de Contenido</div>
                        <div class="config-card__desc">
                            Configura cómo se muestra el contenido en la página principal
                        </div>
                    </div>
                </div>
                <div class="config-card__body">
                    <div class="config-row">
                        <div class="config-field">
                            <label class="config-label" for="contenido_cats_en_index">
                                <i class="bi bi-grid-3x3-gap-fill"
                                   style="color:var(--primary)"></i>
                                Categorías en el homepage
                            </label>
                            <input type="number"
                                   id="contenido_cats_en_index"
                                   name="contenido_cats_en_index"
                                   class="config-input"
                                   value="<?= e(cfgInt('contenido_cats_en_index', 4)) ?>"
                                   min="1" max="10">
                            <span class="config-helper">
                                Cuántos bloques de categoría mostrar (1-10)
                            </span>
                        </div>

                        <div class="config-field">
                            <label class="config-label" for="contenido_noticias_bloque">
                                <i class="bi bi-newspaper"
                                   style="color:var(--accent)"></i>
                                Noticias por bloque
                            </label>
                            <input type="number"
                                   id="contenido_noticias_bloque"
                                   name="contenido_noticias_bloque"
                                   class="config-input"
                                   value="<?= e(cfgInt('contenido_noticias_bloque', 4)) ?>"
                                   min="1" max="12">
                            <span class="config-helper">
                                Noticias a mostrar por bloque de categoría (1-12)
                            </span>
                        </div>

                        <div class="config-field">
                            <label class="config-label" for="contenido_orden">
                                <i class="bi bi-sort-down"
                                   style="color:var(--warning)"></i>
                                Orden predeterminado
                            </label>
                            <select id="contenido_orden"
                                    name="contenido_orden"
                                    class="config-select">
                                <option value="recientes"
                                    <?= cfgVal('contenido_orden') === 'recientes' ? 'selected' : '' ?>>
                                    📅 Más recientes
                                </option>
                                <option value="populares"
                                    <?= cfgVal('contenido_orden') === 'populares' ? 'selected' : '' ?>>
                                    🔥 Más populares
                                </option>
                                <option value="aleatorio"
                                    <?= cfgVal('contenido_orden') === 'aleatorio' ? 'selected' : '' ?>>
                                    🎲 Aleatorio
                                </option>
                            </select>
                        </div>
                    </div>

                    <!-- Módulos on/off -->
                    <div style="border-top:1px solid var(--border-color);
                                padding-top:20px;margin-top:8px">
                        <h4 style="font-size:.85rem;font-weight:700;
                                   color:var(--text-primary);margin-bottom:16px">
                            <i class="bi bi-toggles"
                               style="color:var(--primary)"></i>
                            Activar / Desactivar módulos
                        </h4>

                        <?php
                        $modulos = [
                            ['key'=>'contenido_breaking_activo',  'icon'=>'bi-broadcast-pin',
                             'label'=>'Breaking News Bar',
                             'desc'=>'Barra roja en la parte superior con últimas noticias'],
                            ['key'=>'contenido_trending_activo',  'icon'=>'bi-graph-up-arrow',
                             'label'=>'Sección Trending',
                             'desc'=>'Widget de noticias más vistas en el sidebar'],
                            ['key'=>'contenido_videos_activo',    'icon'=>'bi-play-circle-fill',
                             'label'=>'Módulo de Videos',
                             'desc'=>'Sección de videos con playlist en el homepage'],
                            ['key'=>'contenido_podcasts_activo',  'icon'=>'bi-mic-fill',
                             'label'=>'Módulo de Podcasts',
                             'desc'=>'Reproductor de podcasts (próximamente)'],
                            ['key'=>'contenido_podcasts_homepage', 'icon'=>'bi-headphones',
                             'label'=>'Podcasts en Homepage',
                             'desc'=>'Sección de podcasts visible en la página principal'],
                            ['key'=>'contenido_opinion_activo',   'icon'=>'bi-chat-quote-fill',
                             'label'=>'Sección Opinión',
                             'desc'=>'Bloque de artículos de opinión en el homepage'],
                        ];
                        
                        
                        
                        foreach ($modulos as $mod):
                        ?>
                        <div class="config-toggle-row">
                            <div class="config-toggle-label">
                                <strong>
                                    <i class="bi <?= $mod['icon'] ?>"
                                       style="color:var(--primary);margin-right:6px"></i>
                                    <?= e($mod['label']) ?>
                                </strong>
                                <small><?= e($mod['desc']) ?></small>
                            </div>
                            <label class="toggle-wrap">
                                <input type="checkbox"
                                       name="<?= e($mod['key']) ?>"
                                       value="1"
                                       <?= cfgBool($mod['key'], true) ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Breaking News config -->
            <div class="config-card">
                <div class="config-card__header">
                    <div class="config-card__icon"
                         style="background:rgba(230,57,70,.1);color:var(--primary)">
                        <i class="bi bi-broadcast-pin"></i>
                    </div>
                    <div>
                        <div class="config-card__title">Configuración Breaking News</div>
                        <div class="config-card__desc">
                            Control de la barra de últimas noticias
                        </div>
                    </div>
                </div>
                <div class="config-card__body">
                    <div class="config-row">
                        <div class="config-field">
                            <label class="config-label" for="breaking_velocidad">
                                <i class="bi bi-speedometer"
                                   style="color:var(--primary)"></i>
                                Velocidad de rotación (ms)
                            </label>
                            <input type="number"
                                   id="breaking_velocidad"
                                   name="breaking_velocidad"
                                   class="config-input"
                                   value="<?= e(cfgInt('breaking_velocidad', 5000)) ?>"
                                   min="1000" max="20000" step="500">
                            <span class="config-helper">
                                Milisegundos entre cada titular (mín. 1000ms)
                            </span>
                        </div>

                        <div class="config-field">
                            <label class="config-label">Autoplay</label>
                            <div class="config-toggle-row"
                                 style="padding:0;border:none">
                                <div class="config-toggle-label">
                                    <small>Reproducir automáticamente el desfile de noticias</small>
                                </div>
                                <label class="toggle-wrap">
                                    <input type="checkbox"
                                           name="breaking_autoplay"
                                           value="1"
                                           <?= cfgBool('breaking_autoplay', true) ? 'checked' : '' ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php
            // ══════════════════════════════════════════════════
            // TAB: REDES SOCIALES
            // ══════════════════════════════════════════════════
            elseif ($tabActiva === 'redes'):
            ?>

            <div class="config-card">
                <div class="config-card__header">
                    <div class="config-card__icon"
                         style="background:rgba(29,53,87,.1);color:var(--secondary)">
                        <i class="bi bi-share-fill"></i>
                    </div>
                    <div>
                        <div class="config-card__title">Redes Sociales</div>
                        <div class="config-card__desc">
                            URLs que aparecen en los botones de compartir y redes del sitio
                        </div>
                    </div>
                </div>
                <div class="config-card__body">
                    <?php
                    $redesConfig = [
                        ['key'=>'social_facebook',  'icon'=>'bi-facebook',  'color'=>'#1877F2',
                         'label'=>'Facebook',        'placeholder'=>'https://facebook.com/tupagina'],
                        ['key'=>'social_twitter',   'icon'=>'bi-twitter-x', 'color'=>'#000000',
                         'label'=>'Twitter / X',     'placeholder'=>'https://x.com/tuusuario'],
                        ['key'=>'social_instagram', 'icon'=>'bi-instagram', 'color'=>'#E4405F',
                         'label'=>'Instagram',       'placeholder'=>'https://instagram.com/tuperfil'],
                        ['key'=>'social_youtube',   'icon'=>'bi-youtube',   'color'=>'#FF0000',
                         'label'=>'YouTube',         'placeholder'=>'https://youtube.com/@tucanal'],
                        ['key'=>'social_tiktok',    'icon'=>'bi-tiktok',    'color'=>'#000000',
                         'label'=>'TikTok',          'placeholder'=>'https://tiktok.com/@tuperfil'],
                        ['key'=>'social_telegram',  'icon'=>'bi-telegram',  'color'=>'#229ED9',
                         'label'=>'Telegram',        'placeholder'=>'https://t.me/tucanal'],
                        ['key'=>'social_whatsapp',  'icon'=>'bi-whatsapp',  'color'=>'#25D366',
                         'label'=>'WhatsApp (número)','placeholder'=>'+18091234567'],
                    ];
                    foreach ($redesConfig as $red):
                    ?>
                    <div class="config-field" style="margin-bottom:16px">
                        <label class="config-label">
                            <i class="bi <?= e($red['icon']) ?>"
                               style="color:<?= e($red['color']) ?>"></i>
                            <?= e($red['label']) ?>
                        </label>
                        <div class="social-input-wrap">
                            <span class="social-input-icon">
                                <i class="bi <?= e($red['icon']) ?>"
                                   style="color:<?= e($red['color']) ?>"></i>
                            </span>
                            <input type="<?= $red['key'] === 'social_whatsapp' ? 'tel' : 'url' ?>"
                                   name="<?= e($red['key']) ?>"
                                   class="config-input"
                                   value="<?= e(cfgVal($red['key'])) ?>"
                                   placeholder="<?= e($red['placeholder']) ?>"
                                   maxlength="255">
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div style="padding:14px;background:rgba(59,130,246,.06);
                                border-radius:var(--border-radius-lg);
                                border:1px solid rgba(59,130,246,.15);
                                font-size:.8rem;color:var(--info)">
                        <i class="bi bi-info-circle-fill"></i>
                        Estas URLs se usan automáticamente en los botones de compartir,
                        el header y el footer del sitio.
                    </div>
                </div>
            </div>

            <?php
            // ══════════════════════════════════════════════════
            // TAB: MONETIZACIÓN
            // ══════════════════════════════════════════════════
            elseif ($tabActiva === 'monetizacion'):
            ?>

            <div class="config-card">
                <div class="config-card__header">
                    <div class="config-card__icon"
                         style="background:rgba(34,197,94,.1);color:var(--success)">
                        <i class="bi bi-currency-dollar"></i>
                    </div>
                    <div>
                        <div class="config-card__title">Control de Anuncios</div>
                        <div class="config-card__desc">
                            Configura dónde y cómo se muestran los anuncios
                        </div>
                    </div>
                </div>
                <div class="config-card__body">

                    <div class="config-toggle-row">
                        <div class="config-toggle-label">
                            <strong>
                                <i class="bi bi-badge-ad-fill"
                                   style="color:var(--success)"></i>
                                Anuncios activos globalmente
                            </strong>
                            <small>
                                Desactivar esto oculta TODOS los anuncios del sitio
                            </small>
                        </div>
                        <label class="toggle-wrap">
                            <input type="checkbox"
                                   name="ads_activos_global"
                                   value="1"
                                   id="adsGlobalToggle"
                                   <?= cfgBool('ads_activos_global', true) ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div style="border-top:1px solid var(--border-color);
                                padding-top:16px;margin-top:4px">
                        <h4 style="font-size:.82rem;font-weight:700;
                                   color:var(--text-muted);margin-bottom:12px;
                                   text-transform:uppercase;letter-spacing:.06em">
                            Posiciones de anuncios
                        </h4>

                        <?php
                        $posAds = [
                            ['key'=>'ads_header',          'label'=>'Header (728×90)',
                             'desc'=>'Anuncio en la parte superior del header'],
                            ['key'=>'ads_entre_noticias',  'label'=>'Entre noticias',
                             'desc'=>'Anuncio insertado cada N noticias en el feed'],
                            ['key'=>'ads_sidebar',         'label'=>'Sidebar (300×250)',
                             'desc'=>'Anuncio en la barra lateral derecha'],
                            ['key'=>'ads_dentro_articulo', 'label'=>'Dentro del artículo',
                             'desc'=>'Anuncio antes del contenido de la noticia'],
                        ];
                        foreach ($posAds as $pos):
                        ?>
                        <div class="config-toggle-row">
                            <div class="config-toggle-label">
                                <strong><?= e($pos['label']) ?></strong>
                                <small><?= e($pos['desc']) ?></small>
                            </div>
                            <label class="toggle-wrap">
                                <input type="checkbox"
                                       name="<?= e($pos['key']) ?>"
                                       value="1"
                                       <?= cfgBool($pos['key'], true) ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="config-row"
                         style="margin-top:20px;padding-top:16px;
                                border-top:1px solid var(--border-color)">
                        <div class="config-field">
                            <label class="config-label" for="ads_frecuencia">
                                <i class="bi bi-skip-forward-fill"
                                   style="color:var(--warning)"></i>
                                Frecuencia de anuncios
                            </label>
                            <input type="number"
                                   id="ads_frecuencia"
                                   name="ads_frecuencia"
                                   class="config-input"
                                   value="<?= e(cfgInt('ads_frecuencia', 3)) ?>"
                                   min="1" max="10">
                            <span class="config-helper">
                                Insertar anuncio cada N noticias en el feed
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Precios Premium -->
            <div class="config-card">
                <div class="config-card__header">
                    <div class="config-card__icon"
                         style="background:rgba(245,158,11,.1);color:var(--warning)">
                        <i class="bi bi-star-fill"></i>
                    </div>
                    <div>
                        <div class="config-card__title">Suscripción Premium</div>
                        <div class="config-card__desc">
                            Precios de los planes de suscripción
                        </div>
                    </div>
                </div>
                <div class="config-card__body">
                    <div class="config-row">
                        <div class="config-field">
                            <label class="config-label" for="premium_precio_mensual">
                                <i class="bi bi-calendar-month"
                                   style="color:var(--primary)"></i>
                                Precio mensual (USD)
                            </label>
                            <div style="position:relative">
                                <span style="position:absolute;left:12px;top:50%;
                                             transform:translateY(-50%);
                                             color:var(--text-muted);font-weight:700">
                                    $
                                </span>
                                <input type="number"
                                       id="premium_precio_mensual"
                                       name="premium_precio_mensual"
                                       class="config-input"
                                       value="<?= e(cfgVal('premium_precio_mensual', '4.99')) ?>"
                                       min="0.99" max="99.99" step="0.01"
                                       style="padding-left:28px">
                            </div>
                        </div>

                        <div class="config-field">
                            <label class="config-label" for="premium_precio_anual">
                                <i class="bi bi-calendar-year"
                                   style="color:var(--success)"></i>
                                Precio anual (USD)
                            </label>
                            <div style="position:relative">
                                <span style="position:absolute;left:12px;top:50%;
                                             transform:translateY(-50%);
                                             color:var(--text-muted);font-weight:700">
                                    $
                                </span>
                                <input type="number"
                                       id="premium_precio_anual"
                                       name="premium_precio_anual"
                                       class="config-input"
                                       value="<?= e(cfgVal('premium_precio_anual', '39.99')) ?>"
                                       min="9.99" max="999.99" step="0.01"
                                       style="padding-left:28px">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php
            // ══════════════════════════════════════════════════
            // TAB: CLIMA
            // ══════════════════════════════════════════════════
            elseif ($tabActiva === 'clima'):
            ?>

            <div class="config-card">
                <div class="config-card__header">
                    <div class="config-card__icon"
                         style="background:rgba(59,130,246,.1);color:var(--info)">
                        <i class="bi bi-cloud-sun-fill"></i>
                    </div>
                    <div>
                        <div class="config-card__title">Widget del Clima</div>
                        <div class="config-card__desc">
                            Muestra el clima en tiempo real usando OpenWeatherMap API
                        </div>
                    </div>
                </div>
                <div class="config-card__body">
                    <div class="config-row">
                        <div class="config-field">
                            <label class="config-label" for="clima_ciudad">
                                <i class="bi bi-geo-alt-fill"
                                   style="color:var(--primary)"></i>
                                Ciudad predeterminada
                            </label>
                            <input type="text"
                                   id="clima_ciudad"
                                   name="clima_ciudad"
                                   class="config-input"
                                   value="<?= e(cfgVal('clima_ciudad', 'Santo Domingo')) ?>"
                                   placeholder="Ej: Santo Domingo, DO"
                                   maxlength="100">
                            <span class="config-helper">
                                Nombre de la ciudad (puede incluir código de país: "Santo Domingo, DO")
                            </span>
                        </div>

                        <div class="config-field">
                            <label class="config-label" for="clima_unidad">
                                <i class="bi bi-thermometer-half"
                                   style="color:var(--danger)"></i>
                                Unidad de temperatura
                            </label>
                            <select id="clima_unidad"
                                    name="clima_unidad"
                                    class="config-select">
                                <option value="C"
                                    <?= cfgVal('clima_unidad', 'C') === 'C' ? 'selected' : '' ?>>
                                    °C — Celsius
                                </option>
                                <option value="F"
                                    <?= cfgVal('clima_unidad') === 'F' ? 'selected' : '' ?>>
                                    °F — Fahrenheit
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="config-field">
                        <label class="config-label" for="clima_api_key">
                            <i class="bi bi-key-fill"
                               style="color:var(--warning)"></i>
                            API Key de OpenWeatherMap
                            <span style="font-size:.68rem;color:var(--text-muted);
                                          font-weight:400">
                                (Gratis en openweathermap.org)
                            </span>
                        </label>
                        <div style="position:relative">
                            <input type="password"
                                   id="clima_api_key"
                                   name="clima_api_key"
                                   class="config-input"
                                   value="<?= e(cfgVal('clima_api_key')) ?>"
                                   placeholder="Ej: abc123def456..."
                                   maxlength="64"
                                   autocomplete="off">
                            <button type="button"
                                    onclick="toggleApiKey()"
                                    style="position:absolute;right:10px;top:50%;
                                           transform:translateY(-50%);
                                           background:none;border:none;
                                           cursor:pointer;color:var(--text-muted)">
                                <i class="bi bi-eye" id="apiKeyIcon"></i>
                            </button>
                        </div>
                        <span class="config-helper">
                            Sin API key, el widget de clima estará oculto.
                            <a href="https://openweathermap.org/api"
                               target="_blank"
                               style="color:var(--primary)">
                                Obtener API key gratuita →
                            </a>
                        </span>
                    </div>
                    
                    

                    <!-- Test de API -->
                    <div style="margin-top:16px">
                        <button type="button"
                                onclick="testClima()"
                                style="display:inline-flex;align-items:center;gap:8px;
                                       padding:10px 20px;background:var(--info);
                                       color:#fff;border-radius:var(--border-radius-full);
                                       font-size:.82rem;font-weight:700;cursor:pointer;
                                       border:none;transition:all .2s ease">
                            <i class="bi bi-cloud-check-fill"></i>
                            Probar conexión con API
                        </button>
                        <div id="climaTestResult"
                             style="margin-top:12px;font-size:.82rem"></div>
                    </div>
                </div>
            </div>
            
            <!-- ── TARJETA: TASAS DE CAMBIO ────────────────────────────── -->
                    <div class="config-card" style="margin-top:20px">
                        <div class="config-card__header">
                            <div class="config-card__icon"
                                 style="background:rgba(251,191,36,.1);color:#fbbf24">
                                <i class="bi bi-currency-exchange"></i>
                            </div>
                            <div>
                                <div class="config-card__title">Tasas de Cambio — Topbar</div>
                                <div class="config-card__desc">
                                    Muestra USD/EUR → Peso Dominicano en la barra superior.
                                    Actualización automática 2x al día vía Cron Job.
                                </div>
                            </div>
                        </div>
                        <div class="config-card__body">
                    
                            <!-- API Key -->
                            <div class="config-field" style="margin-bottom:20px">
                                <label class="config-label" for="exchange_api_key">
                                    <i class="bi bi-key-fill" style="color:var(--warning)"></i>
                                    API Key — ExchangeRate-API
                                    <a href="https://app.exchangerate-api.com/" target="_blank"
                                       style="font-size:.7rem;font-weight:400;color:var(--primary);
                                              margin-left:8px">
                                        Obtener gratis →
                                    </a>
                                </label>
                                <div style="position:relative">
                                    <input type="password"
                                           id="exchange_api_key"
                                           name="exchange_api_key"
                                           class="config-input"
                                           value="<?= e(cfgVal('exchange_api_key')) ?>"
                                           placeholder="Ej: 49d6feee74b797bfef8a7bf4"
                                           maxlength="64"
                                           autocomplete="off">
                                    <button type="button"
                                            onclick="toggleExKey()"
                                            style="position:absolute;right:10px;top:50%;transform:translateY(-50%);
                                                   background:none;border:none;cursor:pointer;color:var(--text-muted)">
                                        <i class="bi bi-eye" id="exKeyIco"></i>
                                    </button>
                                </div>
                                <span class="config-helper">
                                    Plan gratuito: 1.500 req/mes (2 req/día × 30 días = 60 req/mes ✓).
                                    Define también <code>EXCHANGE_API_KEY</code> en <code>config/database.php</code>.
                                </span>
                            </div>
                    
                            <!-- Tasas actuales (solo lectura) -->
                            <?php
                            $tasaUSD  = cfgVal('tasa_usd_dop', '0');
                            $tasaEUR  = cfgVal('tasa_eur_dop', '0');
                            $ultimaAct = cfgVal('tasa_ultima_actualizacion', '');
                            ?>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
                                <div style="padding:14px;background:var(--bg-surface-2);
                                            border-radius:var(--border-radius-lg);border:1px solid var(--border-color)">
                                    <div style="font-size:.68rem;font-weight:700;color:var(--text-muted);
                                                 text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">
                                        <i class="bi bi-currency-dollar"></i> USD → DOP
                                    </div>
                                    <div style="font-size:1.4rem;font-weight:900;color:#fbbf24">
                                        <?= (float)$tasaUSD > 0
                                            ? '1 US$ = ' . number_format((float)$tasaUSD, 2) . ' RD$'
                                            : '—' ?>
                                    </div>
                                </div>
                                <div style="padding:14px;background:var(--bg-surface-2);
                                            border-radius:var(--border-radius-lg);border:1px solid var(--border-color)">
                                    <div style="font-size:.68rem;font-weight:700;color:var(--text-muted);
                                                 text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">
                                        <i class="bi bi-currency-euro"></i> EUR → DOP
                                    </div>
                                    <div style="font-size:1.4rem;font-weight:900;color:#34d399">
                                        <?= (float)$tasaEUR > 0
                                            ? '1 EUR = ' . number_format((float)$tasaEUR, 2) . ' RD$'
                                            : '—' ?>
                                    </div>
                                </div>
                            </div>
                    
                            <?php if ($ultimaAct): ?>
                            <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:16px">
                                <i class="bi bi-clock-history"></i>
                                Última actualización: <strong><?= e($ultimaAct) ?></strong>
                            </div>
                            <?php endif; ?>
                    
                            <!-- Info Cron -->
                            <div style="padding:14px;background:rgba(59,130,246,.06);
                                        border:1px solid rgba(59,130,246,.2);
                                        border-radius:var(--border-radius-lg);font-size:.8rem">
                                <div style="font-weight:700;color:var(--info);margin-bottom:8px">
                                    <i class="bi bi-terminal-fill"></i> Configuración del Cron Job
                                </div>
                                <code style="display:block;background:var(--bg-surface);padding:10px 12px;
                                             border-radius:var(--border-radius);font-size:.78rem;
                                             color:var(--text-primary);word-break:break-all;
                                             border:1px solid var(--border-color)">
                                    0 0,12 * * * /usr/local/bin/php /home/lnuazoql/elclickrd.com/update_rates.php
                                </code>
                                <div style="margin-top:8px;color:var(--text-muted)">
                                    Se ejecuta a las <strong>12:00 AM</strong> y <strong>12:00 PM</strong> todos los días.
                                    Logs en: <code>logs/update_rates.log</code>
                                </div>
                            </div>
                    
                            <!-- Botón actualizar manualmente -->
                            <div style="margin-top:16px">
                                <button type="button"
                                        onclick="actualizarTasasManual()"
                                        id="btnActTasas"
                                        style="display:inline-flex;align-items:center;gap:8px;
                                               padding:10px 20px;background:var(--info);color:#fff;
                                               border:none;border-radius:var(--border-radius-full);
                                               font-size:.82rem;font-weight:700;cursor:pointer;
                                               transition:all .2s">
                                    <i class="bi bi-arrow-repeat"></i>
                                    Actualizar tasas ahora
                                </button>
                                <div id="tasasTestResult" style="margin-top:10px;font-size:.82rem"></div>
                            </div>
                    
                        </div>
                    </div>

            <?php
            // ══════════════════════════════════════════════════
            // TAB: NOTIFICACIONES
            // ══════════════════════════════════════════════════
            elseif ($tabActiva === 'notificaciones'):
            ?>

            <div class="config-card">
                <div class="config-card__header">
                    <div class="config-card__icon"
                         style="background:rgba(230,57,70,.1);color:var(--primary)">
                        <i class="bi bi-bell-fill"></i>
                    </div>
                    <div>
                        <div class="config-card__title">Notificaciones Push</div>
                        <div class="config-card__desc">
                            Configura las notificaciones enviadas a los usuarios
                        </div>
                    </div>
                </div>
                <div class="config-card__body">

                    <div class="config-toggle-row">
                        <div class="config-toggle-label">
                            <strong>
                                <i class="bi bi-bell-fill"
                                   style="color:var(--primary)"></i>
                                Activar push notifications
                            </strong>
                            <small>
                                Requiere Service Worker y HTTPS en producción
                            </small>
                        </div>
                        <label class="toggle-wrap">
                            <input type="checkbox"
                                   name="notif_push_activas"
                                   value="1"
                                   <?= cfgBool('notif_push_activas') ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="config-field"
                         style="margin:16px 0;padding-top:16px;
                                border-top:1px solid var(--border-color)">
                        <label class="config-label" for="notif_frecuencia">
                            <i class="bi bi-clock-fill"
                               style="color:var(--accent)"></i>
                            Frecuencia (minutos)
                        </label>
                        <input type="number"
                               id="notif_frecuencia"
                               name="notif_frecuencia"
                               class="config-input"
                               value="<?= e(cfgInt('notif_frecuencia', 60)) ?>"
                               min="5" max="1440"
                               style="max-width:200px">
                        <span class="config-helper">
                            Cada cuántos minutos verificar nuevas notificaciones
                        </span>
                    </div>

                    <h4 style="font-size:.82rem;font-weight:700;
                               color:var(--text-muted);margin-bottom:12px;
                               text-transform:uppercase;letter-spacing:.06em;
                               padding-top:8px">
                        Tipos de notificación
                    </h4>

                    <?php
                    $tiposNotif = [
                        ['key'=>'notif_breaking', 'label'=>'Breaking News',
                         'desc'=>'Notificar cuando se publique una noticia breaking'],
                        ['key'=>'notif_trending', 'label'=>'Trending',
                         'desc'=>'Notificar cuando una noticia entre en trending'],
                    ];
                    foreach ($tiposNotif as $notif):
                    ?>
                    <div class="config-toggle-row">
                        <div class="config-toggle-label">
                            <strong><?= e($notif['label']) ?></strong>
                            <small><?= e($notif['desc']) ?></small>
                        </div>
                        <label class="toggle-wrap">
                            <input type="checkbox"
                                   name="<?= e($notif['key']) ?>"
                                   value="1"
                                   <?= cfgBool($notif['key'], true) ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php
            // ══════════════════════════════════════════════════
            // TAB: ANALYTICS
            // ══════════════════════════════════════════════════
            elseif ($tabActiva === 'analytics'):
            ?>

            <div class="config-card">
                <div class="config-card__header">
                    <div class="config-card__icon"
                         style="background:rgba(245,158,11,.1);color:var(--warning)">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                    <div>
                        <div class="config-card__title">Google Analytics</div>
                        <div class="config-card__desc">
                            Integración con Google Analytics 4
                        </div>
                    </div>
                </div>
                <div class="config-card__body">
                    <div class="config-field">
                        <label class="config-label" for="analytics_google_id">
                            <i class="bi bi-google"
                               style="color:#4285f4"></i>
                            Google Analytics Measurement ID
                        </label>
                        <input type="text"
                               id="analytics_google_id"
                               name="analytics_google_id"
                               class="config-input"
                               value="<?= e(cfgVal('analytics_google_id')) ?>"
                               placeholder="G-XXXXXXXXXX"
                               maxlength="20"
                               style="max-width:300px">
                        <span class="config-helper">
                            Formato: G-XXXXXXXXXX (GA4).
                            <a href="https://analytics.google.com"
                               target="_blank"
                               style="color:var(--primary)">
                                Crear cuenta en Google Analytics →
                            </a>
                        </span>
                    </div>
                </div>
            </div>

            <div class="config-card">
                <div class="config-card__header">
                    <div class="config-card__icon"
                         style="background:rgba(59,130,246,.1);color:var(--info)">
                        <i class="bi bi-activity"></i>
                    </div>
                    <div>
                        <div class="config-card__title">Analytics Internos</div>
                        <div class="config-card__desc">
                            Herramientas de análisis del comportamiento del usuario
                        </div>
                    </div>
                </div>
                <div class="config-card__body">

                    <div class="config-toggle-row">
                        <div class="config-toggle-label">
                            <strong>
                                <i class="bi bi-arrow-down-up"
                                   style="color:var(--info)"></i>
                                Tracking de scroll
                            </strong>
                            <small>
                                Mide hasta qué porcentaje leen los artículos
                                (25%, 50%, 75%, 100%)
                            </small>
                        </div>
                        <label class="toggle-wrap">
                            <input type="checkbox"
                                   name="analytics_scroll_tracking"
                                   value="1"
                                   <?= cfgBool('analytics_scroll_tracking', true) ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="config-toggle-row">
                        <div class="config-toggle-label">
                            <strong>
                                <i class="bi bi-cursor-fill"
                                   style="color:var(--warning)"></i>
                                Heatmap de clics
                            </strong>
                            <small>
                                Registra en qué elementos hace clic el usuario
                                para análisis visual
                            </small>
                        </div>
                        <label class="toggle-wrap">
                            <input type="checkbox"
                                   name="analytics_heatmap"
                                   value="1"
                                   <?= cfgBool('analytics_heatmap', true) ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div style="padding:14px;background:rgba(245,158,11,.06);
                                border-radius:var(--border-radius-lg);
                                border:1px solid rgba(245,158,11,.2);
                                margin-top:12px;font-size:.78rem;
                                color:var(--text-secondary)">
                        <i class="bi bi-exclamation-triangle-fill"
                           style="color:var(--warning)"></i>
                        Activar demasiadas métricas puede ralentizar el sitio.
                        Se recomienda activar solo las necesarias.
                    </div>
                </div>
            </div>

            <?php
            // ══════════════════════════════════════════════════
            // TAB: SISTEMA
            // ══════════════════════════════════════════════════
            elseif ($tabActiva === 'sistema'):
            ?>

            <!-- Info del sistema -->
            <div class="config-card">
                <div class="config-card__header">
                    <div class="config-card__icon"
                         style="background:rgba(139,92,246,.1);color:#8b5cf6">
                        <i class="bi bi-info-circle-fill"></i>
                    </div>
                    <div>
                        <div class="config-card__title">Información del sistema</div>
                        <div class="config-card__desc">
                            Estado y versiones del sistema
                        </div>
                    </div>
                </div>
                <div class="config-card__body">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px">
                        <?php
                        $sysInfo = [
                            ['label'=>'Versión del sistema','value'=>'v' . APP_VERSION,
                             'icon'=>'bi-code-slash','color'=>'var(--primary)'],
                            ['label'=>'PHP','value'=>PHP_VERSION,
                             'icon'=>'bi-filetype-php','color'=>'#777bb3'],
                            ['label'=>'MariaDB / MySQL',
                             'value'=>db()->fetchColumn("SELECT VERSION()") ?? 'N/D',
                             'icon'=>'bi-database-fill','color'=>'#00758f'],
                            ['label'=>'Entorno',
                             'value'=>APP_DEBUG ? 'Desarrollo' : 'Producción',
                             'icon'=>'bi-gear-fill',
                             'color'=>APP_DEBUG ? 'var(--warning)' : 'var(--success)'],
                            ['label'=>'Zona horaria','value'=>date_default_timezone_get(),
                             'icon'=>'bi-clock-fill','color'=>'var(--info)'],
                            ['label'=>'Memoria PHP',
                             'value'=>ini_get('memory_limit'),
                             'icon'=>'bi-memory','color'=>'var(--accent)'],
                        ];
                        foreach ($sysInfo as $s):
                        ?>
                        <div style="background:var(--bg-surface-2);
                                    border-radius:var(--border-radius-lg);
                                    padding:16px;
                                    border:1px solid var(--border-color)">
                            <div style="display:flex;align-items:center;gap:8px;
                                        margin-bottom:8px">
                                <i class="bi <?= e($s['icon']) ?>"
                                   style="color:<?= e($s['color']) ?>"></i>
                                <span style="font-size:.72rem;font-weight:600;
                                              color:var(--text-muted);
                                              text-transform:uppercase;
                                              letter-spacing:.05em">
                                    <?= e($s['label']) ?>
                                </span>
                            </div>
                            <div style="font-size:.95rem;font-weight:700;
                                         color:var(--text-primary)">
                                <?= e($s['value']) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Backup -->
            <div class="config-card">
                <div class="config-card__header">
                    <div class="config-card__icon"
                         style="background:rgba(34,197,94,.1);color:var(--success)">
                        <i class="bi bi-cloud-arrow-down-fill"></i>
                    </div>
                    <div>
                        <div class="config-card__title">Backup de base de datos</div>
                        <div class="config-card__desc">
                            Genera una copia de seguridad completa de la BD
                        </div>
                    </div>
                </div>
                <div class="config-card__body">
                    <?php
                    $ultimosBackups = db()->fetchAll(
                        "SELECT * FROM backup_log ORDER BY fecha DESC LIMIT 5",
                        []
                    );
                    ?>

                    <button type="button"
                            onclick="createBackupFromConfig()"
                            style="display:inline-flex;align-items:center;gap:8px;
                                   padding:12px 24px;background:var(--success);
                                   color:#fff;border-radius:var(--border-radius-full);
                                   font-size:.875rem;font-weight:700;cursor:pointer;
                                   border:none;margin-bottom:20px;
                                   transition:all .2s ease">
                        <i class="bi bi-cloud-arrow-down-fill"></i>
                        Crear backup ahora
                    </button>

                    <?php if (!empty($ultimosBackups)): ?>
                    <div style="margin-top:4px">
                        <h4 style="font-size:.8rem;font-weight:700;
                                   color:var(--text-muted);margin-bottom:12px;
                                   text-transform:uppercase">
                            Últimos backups
                        </h4>
                        <div style="display:flex;flex-direction:column;gap:8px">
                            <?php foreach ($ultimosBackups as $bk): ?>
                            <div style="display:flex;align-items:center;gap:12px;
                                        padding:10px 14px;
                                        background:var(--bg-surface-2);
                                        border-radius:var(--border-radius-lg);
                                        font-size:.78rem">
                                <i class="bi bi-file-earmark-zip-fill"
                                   style="color:var(--success);font-size:1rem"></i>
                                <span style="flex:1;color:var(--text-secondary)">
                                    <?= e($bk['archivo']) ?>
                                </span>
                                <span style="color:var(--text-muted)">
                                    <?= round($bk['tamano'] / 1024, 1) ?> KB
                                </span>
                                <span style="color:<?= $bk['estado'] === 'completado'
                                    ? 'var(--success)' : 'var(--danger)' ?>;
                                    font-weight:700">
                                    <?= ucfirst($bk['estado']) ?>
                                </span>
                                <span style="color:var(--text-muted)">
                                    <?= timeAgo($bk['fecha']) ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Limpiar caché -->
            <div class="config-card">
                <div class="config-card__header">
                    <div class="config-card__icon"
                         style="background:rgba(59,130,246,.1);color:var(--info)">
                        <i class="bi bi-trash3-fill"></i>
                    </div>
                    <div>
                        <div class="config-card__title">Mantenimiento</div>
                        <div class="config-card__desc">
                            Herramientas de limpieza y optimización
                        </div>
                    </div>
                </div>
                <div class="config-card__body">
                    <div style="display:flex;flex-wrap:wrap;gap:12px">
                        <button type="button"
                                onclick="clearOldSessions()"
                                style="display:inline-flex;align-items:center;gap:8px;
                                       padding:10px 20px;background:var(--warning);
                                       color:#fff;border-radius:var(--border-radius-full);
                                       font-size:.82rem;font-weight:700;cursor:pointer;
                                       border:none">
                            <i class="bi bi-shield-x-fill"></i>
                            Limpiar sesiones expiradas
                        </button>

                        <button type="button"
                                onclick="clearHeatmapData()"
                                style="display:inline-flex;align-items:center;gap:8px;
                                       padding:10px 20px;
                                       background:var(--bg-surface-2);
                                       color:var(--text-secondary);
                                       border:1px solid var(--border-color);
                                       border-radius:var(--border-radius-full);
                                       font-size:.82rem;font-weight:700;cursor:pointer">
                            <i class="bi bi-cursor-x-fill"></i>
                            Limpiar datos heatmap (30d+)
                        </button>

                        <button type="button"
                                onclick="clearOldLogs()"
                                style="display:inline-flex;align-items:center;gap:8px;
                                       padding:10px 20px;
                                       background:var(--bg-surface-2);
                                       color:var(--text-secondary);
                                       border:1px solid var(--border-color);
                                       border-radius:var(--border-radius-full);
                                       font-size:.82rem;font-weight:700;cursor:pointer">
                            <i class="bi bi-journal-x-fill"></i>
                            Limpiar logs (90d+)
                        </button>
                    </div>

                    <div id="maintenanceResult"
                         style="margin-top:12px;font-size:.82rem"></div>
                </div>
            </div>

            <!-- Tabs que no son sistema no tienen action de guardar -->
            <?php endif; ?>

            <!-- ── Barra de guardado (solo si no es tab sistema) ── -->
            <?php if ($tabActiva !== 'sistema'): ?>
            <div class="config-save-bar">
                <div style="font-size:.8rem;color:var(--text-muted)">
                    <i class="bi bi-info-circle"></i>
                    Los cambios se aplican inmediatamente al guardar.
                </div>
                <div style="display:flex;gap:12px;align-items:center">
                    <a href="?tab=<?= e($tabActiva) ?>"
                       style="padding:10px 20px;border:1px solid var(--border-color);
                              border-radius:var(--border-radius-lg);
                              color:var(--text-muted);text-decoration:none;
                              font-size:.875rem;font-weight:600;
                              transition:all .2s ease">
                        Cancelar
                    </a>
                    <button type="submit"
                            class="btn-save"
                            id="saveBtn">
                        <i class="bi bi-cloud-check-fill"></i>
                        Guardar configuración
                    </button>
                </div>
            </div>
            <?php endif; ?>

        </form><!-- /#configForm -->

    </div><!-- /.admin-content -->
</main>
</div><!-- /.admin-wrapper -->

<!-- Toast container -->
<div class="toast-container" id="toastContainer"></div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── APP Config ────────────────────────────────────────────
    window.APP = {
        url:       '<?= APP_URL ?>',
        csrfToken: '<?= csrfToken() ?>',
        userId:    <?= (int)$usuario['id'] ?>,
    };

    // ── Modo oscuro ───────────────────────────────────────────
    const saved = localStorage.getItem('pd_theme') ??
        (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', saved);
    const di = document.getElementById('darkIcon');
    if (di) di.className = saved === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';

    window.toggleDark = function () {
        const curr = document.documentElement.getAttribute('data-theme');
        const next = curr === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('pd_theme', next);
        const icon = document.getElementById('darkIcon');
        if (icon) icon.className = next === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
    };

    // ── Sidebar ───────────────────────────────────────────────
    window.toggleSidebar = function () {
        const sidebar  = document.getElementById('adminSidebar');
        const overlay  = document.getElementById('adminOverlay');
        const isOpen   = sidebar.classList.toggle('open');
        overlay.style.display = isOpen ? 'block' : 'none';
        document.body.style.overflow = isOpen ? 'hidden' : '';
    };

    window.closeSidebar = function () {
        document.getElementById('adminSidebar').classList.remove('open');
        document.getElementById('adminOverlay').style.display = 'none';
        document.body.style.overflow = '';
    };

    // ── Preview de imágenes logo/favicon ──────────────────────
    window.previewImage = function (input, previewId) {
        if (!input.files?.length) return;
        const file = input.files[0];
        if (file.size > 2 * 1024 * 1024) {
            PDApp?.showToast('La imagen no puede superar 2MB.', 'warning');
            input.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = (e) => {
            const preview = document.getElementById(previewId);
            if (!preview) return;
            if (preview.tagName === 'IMG') {
                preview.src = e.target.result;
            } else {
                preview.innerHTML = `<img src="${e.target.result}"
                    style="max-height:60px;max-width:200px;margin:0 auto 10px;object-fit:contain">`;
            }
        };
        reader.readAsDataURL(file);
    };

    // ── Color pickers ─────────────────────────────────────────
    window.updateColor = function (nombre, valor) {
        const preview = document.getElementById(`preview${nombre.charAt(0).toUpperCase() + nombre.slice(1)}`);
        const hexInput = document.getElementById(`hex_${nombre}`);
        if (preview)  preview.style.background = valor;
        if (hexInput) hexInput.value = valor;

        // Actualizar variable CSS en tiempo real
        const varMap = {
            'primario':    '--primary',
            'secundario':  '--secondary',
            'acento':      '--accent',
        };
        if (varMap[nombre]) {
            document.documentElement.style.setProperty(varMap[nombre], valor);
        }
    };

    window.syncColor = function (nombre, valor) {
        if (!/^#[0-9a-fA-F]{6}$/.test(valor)) return;
        const colorInput = document.getElementById(`color_${nombre}`);
        const preview    = document.getElementById(`preview${nombre.charAt(0).toUpperCase() + nombre.slice(1)}`);
        if (colorInput) colorInput.value = valor;
        if (preview)    preview.style.background = valor;

        const varMap = { 'primario':'--primary','secundario':'--secondary','acento':'--accent' };
        if (varMap[nombre]) {
            document.documentElement.style.setProperty(varMap[nombre], valor);
        }
    };

    // ── Toggle API key visibility ─────────────────────────────
    window.toggleApiKey = function () {
        const input = document.getElementById('clima_api_key');
        const icon  = document.getElementById('apiKeyIcon');
        if (!input) return;
        const isPass = input.type === 'password';
        input.type   = isPass ? 'text' : 'password';
        if (icon) icon.className = isPass ? 'bi bi-eye-slash' : 'bi bi-eye';
    };

    // ── Test clima API ────────────────────────────────────────
    window.testClima = async function () {
        const apiKey = document.getElementById('clima_api_key')?.value.trim();
        const ciudad = document.getElementById('clima_ciudad')?.value.trim();
        const result = document.getElementById('climaTestResult');

        if (!apiKey) {
            if (result) {
                result.innerHTML = `<span style="color:var(--warning)">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    Ingresa una API key primero.
                </span>`;
            }
            return;
        }

        if (result) {
            result.innerHTML = '<i class="bi bi-arrow-repeat spin"' +
                ' style="color:var(--info)"></i> Probando conexión...';
        }

        try {
            const res  = await fetch(
                `https://api.openweathermap.org/data/2.5/weather?q=${encodeURIComponent(ciudad || 'Santo Domingo')}&appid=${encodeURIComponent(apiKey)}&units=metric&lang=es`
            );
            const data = await res.json();

            if (data.cod === 200) {
                if (result) {
                    result.innerHTML = `
                        <span style="color:var(--success)">
                            <i class="bi bi-check-circle-fill"></i>
                            ✅ Conexión exitosa: ${data.main.temp}°C en ${data.name}, ${data.sys.country}.
                        </span>`;
                }
            } else {
                if (result) {
                    result.innerHTML = `
                        <span style="color:var(--danger)">
                            <i class="bi bi-x-circle-fill"></i>
                            ❌ Error: ${data.message || 'API key inválida'}
                        </span>`;
                }
            }
        } catch {
            if (result) {
                result.innerHTML = `<span style="color:var(--danger)">
                    <i class="bi bi-x-circle-fill"></i>
                    Error de conexión con OpenWeatherMap.
                </span>`;
            }
        }
    };

    // ── Backup desde configuración ────────────────────────────
    window.createBackupFromConfig = async function () {
        const btn = event.target.closest('button');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Generando...'; }

        try {
            const res  = await fetch(window.APP.url + '/ajax/handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type':     'application/json',
                    'X-CSRF-Token':     window.APP.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ action: 'create_backup' }),
            });
            const data = await res.json();

            if (data.success) {
                PDApp?.showToast(`✅ Backup creado: ${data.archivo}`, 'success', 5000);
                setTimeout(() => location.reload(), 2000);
            } else {
                PDApp?.showToast(data.error || 'Error al crear backup.', 'error');
            }
        } catch {
            PDApp?.showToast('Error de conexión.', 'error');
        } finally {
            if (btn) {
                btn.disabled  = false;
                btn.innerHTML = '<i class="bi bi-cloud-arrow-down-fill"></i> Crear backup ahora';
            }
        }
    };

    // ── Mantenimiento ─────────────────────────────────────────
    async function maintenanceAction(action, label) {
        const result = document.getElementById('maintenanceResult');

        const confirm = await Swal.fire({
            title:             `¿${label}?`,
            text:              'Esta acción no se puede deshacer.',
            icon:              'warning',
            showCancelButton:  true,
            confirmButtonText: 'Sí, continuar',
            cancelButtonText:  'Cancelar',
            confirmButtonColor:'#e63946',
        });

        if (!confirm.isConfirmed) return;

        try {
            const res  = await fetch(window.APP.url + '/ajax/handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type':     'application/json',
                    'X-CSRF-Token':     window.APP.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ action }),
            });
            const data = await res.json();

            if (result) {
                result.innerHTML = data.success
                    ? `<span style="color:var(--success)"><i class="bi bi-check-circle-fill"></i> ${data.message ?? '¡Completado!'}</span>`
                    : `<span style="color:var(--danger)"><i class="bi bi-x-circle-fill"></i> ${data.message ?? 'Error'}</span>`;
            }
        } catch {
            PDApp?.showToast('Error de conexión.', 'error');
        }
    }

    window.clearOldSessions = () => maintenanceAction('clear_sessions', 'Limpiar sesiones expiradas');
    window.clearHeatmapData  = () => maintenanceAction('clear_heatmap',  'Limpiar datos del heatmap');
    window.clearOldLogs      = () => maintenanceAction('clear_logs',     'Limpiar logs antiguos');

    // ── Submit con loading ────────────────────────────────────
    document.getElementById('configForm')?.addEventListener('submit', function (e) {
        const btn  = document.getElementById('saveBtn');
        if (!btn) return;
        const orig = btn.innerHTML;
        btn.disabled  = true;
        btn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Guardando...';

        setTimeout(() => {
            btn.disabled  = false;
            btn.innerHTML = orig;
        }, 8000);
    });

    // ── Auto-dismiss success message ──────────────────────────
    const successDiv = document.querySelector('[style*="rgba(34,197,94"]');
    if (successDiv) {
        setTimeout(() => {
            successDiv.style.opacity   = '0';
            successDiv.style.transition = 'opacity .4s ease';
            setTimeout(() => successDiv.remove(), 400);
        }, 5000);
    }

});

    // ── Toggle visibilidad API Key Exchange ───────────────────────
    window.toggleExKey = function() {
        const inp  = document.getElementById('exchange_api_key');
        const ico  = document.getElementById('exKeyIco');
        if (!inp) return;
        const isPass = inp.type === 'password';
        inp.type = isPass ? 'text' : 'password';
        if (ico) ico.className = isPass ? 'bi bi-eye-slash' : 'bi bi-eye';
    };
    
    // ── Actualizar tasas desde el panel admin ─────────────────────
    window.actualizarTasasManual = async function() {
        const btn = document.getElementById('btnActTasas');
        const res = document.getElementById('tasasTestResult');
        if (!btn) return;
    
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-arrow-repeat" style="animation:spin 1s linear infinite;display:inline-block"></i> Actualizando...';
        if (res) res.innerHTML = '';
    
        try {
            const r = await fetch(window.APP.url + '/ajax/handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type':     'application/json',
                    'X-CSRF-Token':     window.APP.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    action:     'update_exchange_rates',
                    csrf_token: window.APP.csrfToken,
                }),
            });
    
            const data = await r.json();
    
            if (data.success) {
                if (res) res.innerHTML = `
                    <div style="padding:12px 14px;background:rgba(34,197,94,.08);
                                border:1px solid rgba(34,197,94,.25);
                                border-radius:var(--border-radius-lg);
                                font-size:.82rem;line-height:1.6">
                        <div style="color:var(--success);font-weight:700;margin-bottom:6px">
                            <i class="bi bi-check-circle-fill"></i> Tasas actualizadas correctamente
                        </div>
                        <div style="color:var(--text-secondary)">
                            <strong>1 USD</strong> = <span style="color:#fbbf24;font-weight:700">${parseFloat(data.usd_dop).toFixed(2)} RD$</span>
                            &nbsp;|&nbsp;
                            <strong>1 EUR</strong> = <span style="color:#34d399;font-weight:700">${parseFloat(data.eur_dop).toFixed(2)} RD$</span>
                        </div>
                        <div style="color:var(--text-muted);font-size:.72rem;margin-top:4px">
                            <i class="bi bi-clock"></i> ${data.fecha}
                            &nbsp;·&nbsp;
                            <a href="javascript:location.reload()" style="color:var(--primary)">
                                Recargar página para ver los cambios →
                            </a>
                        </div>
                    </div>`;
    
                // Actualizar también los valores mostrados en las tarjetas sin recargar
                const usdCard = document.querySelector('[data-tasa="usd"]');
                const eurCard = document.querySelector('[data-tasa="eur"]');
                if (usdCard) usdCard.textContent = `1 US$ = ${parseFloat(data.usd_dop).toFixed(2)} RD$`;
                if (eurCard) eurCard.textContent = `1 EUR = ${parseFloat(data.eur_dop).toFixed(2)} RD$`;
    
            } else {
                if (res) res.innerHTML = `
                    <div style="padding:12px 14px;background:rgba(239,68,68,.07);
                                border:1px solid rgba(239,68,68,.25);
                                border-radius:var(--border-radius-lg);
                                font-size:.82rem;color:var(--danger)">
                        <i class="bi bi-x-circle-fill"></i>
                        <strong>Error:</strong> ${data.message ?? 'Error desconocido.'}
                    </div>`;
            }
    
        } catch (e) {
            if (res) res.innerHTML = `
                <div style="padding:12px 14px;background:rgba(239,68,68,.07);
                            border:1px solid rgba(239,68,68,.25);
                            border-radius:var(--border-radius-lg);
                            font-size:.82rem;color:var(--danger)">
                    <i class="bi bi-x-circle-fill"></i>
                    <strong>Error de conexión:</strong> ${e.message}
                </div>`;
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Actualizar tasas ahora';
        }
    };
</script>

</body>
</html>