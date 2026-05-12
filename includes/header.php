<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Header Global
 * ============================================================
 * Archivo : includes/header.php
 * Versión : 2.0.0
 * ============================================================
 * Variables esperadas del contexto que incluye este archivo:
 *   $pageTitle       string — Título de la página
 *   $pageDescription string — Descripción meta SEO
 *   $pageImage       string — Imagen Open Graph
 *   $pageSchema      array  — Schema.org (opcional)
 *   $bodyClass       string — Clase extra para <body>
 *   $noticiaData     array  — Datos noticia para meta (opcional)
 * ============================================================
 */

declare(strict_types=1);

if (!defined('APP_NAME')) {
    require_once dirname(__DIR__) . '/config/database.php';
}

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// ── Datos globales ────────────────────────────────────────────
$categorias   = getCategorias();
$breakingNews = getBreakingNews();
$usuario      = currentUser();
$notifCount   = $usuario ? countNotificacionesNoLeidas((int)$usuario['id']) : 0;
$flashMessage = getFlashMessage();
$clima        = getClima();
$redesSoc     = getRedesSociales();

// ── Variables de página con defaults ─────────────────────────
$pageTitle       = $pageTitle       ?? Config::get('site_nombre', APP_NAME);
$pageDescription = $pageDescription ?? Config::get('site_descripcion_seo', APP_TAGLINE);
$pageImage       = $pageImage       ?? IMG_DEFAULT_OG;
$bodyClass       = $bodyClass       ?? '';
$pageSchema      = $pageSchema      ?? null;
$noticiaData     = $noticiaData     ?? null;

// ── Modo oscuro ───────────────────────────────────────────────
$modoOscuroConfig = Config::get('apariencia_modo_oscuro', 'auto');
$colorPrimario    = Config::get('apariencia_color_primario',    '#e63946');
$colorSecundario  = Config::get('apariencia_color_secundario',  '#1d3557');
$colorAcento      = Config::get('apariencia_color_acento',      '#457b9d');
$tipografia       = Config::get('apariencia_tipografia',        'Inter');
$siteName         = Config::get('site_nombre', APP_NAME);
$siteLogo         = Config::get('site_logo',   '');
$siteFavicon      = Config::get('site_favicon','');
$breakingActivo   = Config::bool('contenido_breaking_activo');
$breakingVelocidad= Config::int('breaking_velocidad', 5000);

// ── SEO Meta Tags ─────────────────────────────────────────────
$metaTags = generateMetaTags([
    'title'          => $pageTitle,
    'description'    => $pageDescription,
    'image'          => $pageImage,
    'url'            => APP_URL . ($_SERVER['REQUEST_URI'] ?? '/'),
    'type'           => $noticiaData ? 'article' : 'website',
    'author'         => $noticiaData['autor_nombre'] ?? $siteName,
    'published_time' => $noticiaData['fecha_publicacion'] ?? null,
    'modified_time'  => $noticiaData['fecha_update']      ?? null,
    'section'        => $noticiaData['cat_nombre']         ?? null,
    'keywords'       => $noticiaData['keywords']           ?? null,
    'schema'         => $pageSchema,
]);

// ── Favicon ───────────────────────────────────────────────────
$faviconUrl = $siteFavicon
    ? APP_URL . '/' . ltrim($siteFavicon, '/')
    : APP_URL . '/assets/images/favicon.ico';

// ── Google Analytics ID ───────────────────────────────────────
$gaId = Config::get('analytics_google_id', '');

?><!DOCTYPE html>
<html lang="es" data-theme="<?= e($modoOscuroConfig === 'oscuro' ? 'dark' : ($modoOscuroConfig === 'claro' ? 'light' : 'auto')) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-3039231544823190" crossorigin="anonymous"></script>
    


    <?= $metaTags ?>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?= e($faviconUrl) ?>">
    <link rel="shortcut icon" href="<?= e($faviconUrl) ?>">

    <!-- PWA -->
    <?= getPWAHeadTags() ?>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=<?= urlencode($tipografia) ?>:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&family=Playfair+Display:ital,wght@0,700;0,800;1,700&display=swap" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- CSS Principal -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= APP_VERSION ?>">

    <!-- Variables CSS dinámicas (desde configuración BD) -->
    <style>
        :root {
            --primary:        <?= e($colorPrimario) ?>;
            --secondary:      <?= e($colorSecundario) ?>;
            --accent:         <?= e($colorAcento) ?>;
            --font-sans:      '<?= e($tipografia) ?>', system-ui, -apple-system, sans-serif;
            --breaking-speed: <?= (int)$breakingVelocidad ?>ms;
        }
    </style>

    <?php if ($gaId): ?>
    <!-- Google Analytics -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= e($gaId) ?>"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '<?= e($gaId) ?>', { anonymize_ip: true });
    </script>
    <?php endif; ?>

    <!-- Configuración JS global -->
    <script>
        window.APP = {
            url:          '<?= APP_URL ?>',
            version:      '<?= APP_VERSION ?>',
            csrfToken:    '<?= csrfToken() ?>',
            userId:       <?= $usuario ? (int)$usuario['id'] : 'null' ?>,
            userRol:      '<?= e($usuario['rol'] ?? '') ?>',
            isPremium:    <?= ($usuario && $usuario['premium']) ? 'true' : 'false' ?>,
            darkMode:     '<?= e($modoOscuroConfig) ?>',
            colorPrimary: '<?= e($colorPrimario) ?>',
            analyticsScroll:  <?= Config::bool('analytics_scroll_tracking') ? 'true' : 'false' ?>,
            analyticsHeatmap: <?= Config::bool('analytics_heatmap') ? 'true' : 'false' ?>,
            breakingSpeed:    <?= (int)$breakingVelocidad ?>,
        };
    </script>
</head>

<body class="<?= e($bodyClass) ?>" id="top">

<!-- ============================================================
     SKIP TO CONTENT (Accesibilidad)
     ============================================================ -->
<a href="#main-content" class="skip-link">Saltar al contenido</a>

<!-- ============================================================
     FLASH MESSAGE (mensajes de sesión)
     ============================================================ -->
<?php if ($flashMessage): ?>
<div class="flash-message flash-<?= e($flashMessage['type']) ?>" id="flashMessage" role="alert">
    <div class="flash-inner">
        <i class="bi bi-<?= match($flashMessage['type']) {
            'success' => 'check-circle-fill',
            'error'   => 'exclamation-circle-fill',
            'warning' => 'exclamation-triangle-fill',
            default   => 'info-circle-fill'
        } ?>"></i>
        <span><?= e($flashMessage['message']) ?></span>
        <button class="flash-close" onclick="this.closest('.flash-message').remove()" aria-label="Cerrar">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     BREAKING NEWS BAR
     ============================================================ -->
<?php if ($breakingActivo && !empty($breakingNews)): ?>
<div class="breaking-bar" id="breakingBar" role="marquee" aria-label="Noticias de última hora">
    <div class="breaking-bar__label">
        <i class="bi bi-broadcast-pin"></i>
        <span>ÚLTIMA HORA</span>
    </div>
    <div class="breaking-bar__track">
        <div class="breaking-bar__items" id="breakingItems">
            <?php foreach ($breakingNews as $bn): ?>
            <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($bn['slug']) ?>"
               class="breaking-bar__item">
                <i class="bi bi-dot"></i>
                <?= e($bn['titulo']) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <button class="breaking-bar__close" id="breakingClose" aria-label="Cerrar">
        <i class="bi bi-x"></i>
    </button>
</div>
<?php endif; ?>

<!-- ============================================================
     HEADER PRINCIPAL
     ============================================================ -->
<header class="site-header" id="siteHeader" role="banner">

    <!-- TOP BAR (fecha, clima, redes sociales) -->
    <div class="header-topbar">
        <div class="container-fluid px-3 px-lg-4">
            <div class="topbar-inner">

                <!-- Fecha y hora + Tasas de cambio -->
                <div class="topbar-left">
                    <span class="topbar-date" id="topbarDate">
                        <i class="bi bi-calendar3"></i>
                        <span id="currentDate">
                            <?php
                            $diasES  = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
                            $mesesES = ['enero','febrero','marzo','abril','mayo','junio',
                                        'julio','agosto','septiembre','octubre','noviembre','diciembre'];
                            $ts  = time();
                            echo $diasES[date('w',$ts)] . ', ' .
                                 date('j',$ts) . ' de ' .
                                 $mesesES[(int)date('n',$ts)-1] . ' de ' .
                                 date('Y',$ts);
                            ?>
                        </span>
                    </span>
                
                    <?php
                    // ── TASAS DE CAMBIO USD/EUR → DOP ─────────────────────────
                    $tasas = getTasasCambio();
                    if ($tasas['disponible']):
                    ?>
                    <span class="topbar-sep">|</span>
                    <span class="topbar-tasas" title="Última actualización: <?= e($tasas['ultima_actualizacion']) ?>">
                        <i class="bi bi-currency-exchange" style="font-size:.75rem"></i>
                        <span class="topbar-tasa-item">
                            <strong>1 US$</strong>
                            <span style="color:rgba(255,255,255,.55)">×</span>
                            <strong style="color:#34d399"><?= number_format($tasas['usd_dop'], 2) ?></strong>
                            <span style="color:rgba(255,255,255,.5)">Pesos</span>
                        </span>
                        <span class="topbar-sep" style="color:rgba(255,255,255,.25)">|</span>
                        <span class="topbar-tasa-item">
                            <strong>1 EUR$</strong>
                            <span style="color:rgba(255,255,255,.55)">×</span>
                            <strong style="color:#fbbf24"><?= number_format($tasas['eur_dop'], 2) ?></strong>
                            <span style="color:rgba(255,255,255,.5)">Pesos</span>
                        </span>
                    </span>
                    <?php endif; ?>
                
                    <?php if ($clima): ?>
                    <span class="topbar-sep">|</span>
                    <span class="topbar-clima" title="<?= e($clima['descripcion']) ?>">
                        <img src="<?= e($clima['icono_url']) ?>"
                             alt="<?= e($clima['descripcion']) ?>"
                             width="20" height="20"
                             style="vertical-align:middle">
                        <span><?= e($clima['temp']) ?>°<?= e($clima['unidad']) ?></span>
                        <span class="topbar-ciudad"><?= e($clima['ciudad']) ?></span>
                    </span>
                    <?php endif; ?>
                </div>

                <!-- Redes sociales + acciones -->
                <div class="topbar-right">
                    <!-- Redes sociales -->
                    <div class="topbar-social">
                        <?php if ($redesSoc['facebook']): ?>
                        <a href="<?= e($redesSoc['facebook']) ?>" target="_blank"
                           rel="noopener" class="topbar-social__link" aria-label="Facebook">
                            <i class="bi bi-facebook"></i>
                        </a>
                        <?php endif; ?>
                        <?php if ($redesSoc['twitter']): ?>
                        <a href="<?= e($redesSoc['twitter']) ?>" target="_blank"
                           rel="noopener" class="topbar-social__link" aria-label="Twitter/X">
                            <i class="bi bi-twitter-x"></i>
                        </a>
                        <?php endif; ?>
                        <?php if ($redesSoc['instagram']): ?>
                        <a href="<?= e($redesSoc['instagram']) ?>" target="_blank"
                           rel="noopener" class="topbar-social__link" aria-label="Instagram">
                            <i class="bi bi-instagram"></i>
                        </a>
                        <?php endif; ?>
                        <?php if ($redesSoc['youtube']): ?>
                        <a href="<?= e($redesSoc['youtube']) ?>" target="_blank"
                           rel="noopener" class="topbar-social__link" aria-label="YouTube">
                            <i class="bi bi-youtube"></i>
                        </a>
                        <?php endif; ?>
                        <?php if ($redesSoc['tiktok']): ?>
                        <a href="<?= e($redesSoc['tiktok']) ?>" target="_blank"
                           rel="noopener" class="topbar-social__link" aria-label="TikTok">
                            <i class="bi bi-tiktok"></i>
                        </a>
                        <?php endif; ?>
                    </div>

                    <!-- Separador -->
                    <span class="topbar-sep">|</span>

                    <!-- Modo oscuro toggle -->
                    <button class="topbar-btn topbar-darkmode" id="darkModeToggle"
                            aria-label="Cambiar modo oscuro"
                            title="Cambiar tema">
                        <i class="bi bi-moon-stars-fill" id="darkModeIcon"></i>
                    </button>

                    <!-- Tamaño de fuente -->
                    <div class="topbar-fontsize" title="Tamaño de texto">
                        <button class="font-size-btn" id="fontDecrease" aria-label="Reducir texto">A-</button>
                        <button class="font-size-btn" id="fontIncrease" aria-label="Aumentar texto">A+</button>
                    </div>

                    <!-- Usuario -->
                    <?php if ($usuario): ?>
                    <div class="topbar-user-menu" id="topbarUserMenu">
                        <button class="topbar-user-btn" aria-haspopup="true"
                                aria-expanded="false" id="topbarUserToggle">
                            <img src="<?= e(getImageUrl($usuario['avatar'], 'avatar')) ?>"
                                 alt="<?= e($usuario['nombre']) ?>"
                                 class="topbar-avatar"
                                 width="28" height="28">
                            <span class="topbar-username d-none d-md-inline">
                                <?= e(explode(' ', $usuario['nombre'])[0]) ?>
                            </span>
                            <?php if ($notifCount > 0): ?>
                            <span class="notif-badge"><?= $notifCount > 9 ? '9+' : $notifCount ?></span>
                            <?php endif; ?>
                            <i class="bi bi-chevron-down topbar-chevron"></i>
                        </button>
                        <div class="topbar-dropdown" id="topbarDropdown" role="menu">
                            <div class="topbar-dropdown__header">
                                <img src="<?= e(getImageUrl($usuario['avatar'], 'avatar')) ?>"
                                     alt="<?= e($usuario['nombre']) ?>"
                                     width="40" height="40">
                                <div>
                                    <strong><?= e($usuario['nombre']) ?></strong>
                                    <small><?= e($usuario['rol']) ?></small>
                                </div>
                            </div>
                            <div class="topbar-dropdown__body">
                                <a href="<?= APP_URL ?>/perfil.php" class="dropdown-item" role="menuitem">
                                    <i class="bi bi-person-circle"></i> Mi Perfil
                                </a>
                                <a href="<?= APP_URL ?>/perfil.php?tab=guardados" class="dropdown-item" role="menuitem">
                                    <i class="bi bi-bookmark-heart"></i> Guardados
                                    <?php
                                    $totalFavs = db()->count("SELECT COUNT(*) FROM favoritos WHERE usuario_id = ?", [$usuario['id']]);
                                    if ($totalFavs > 0): ?>
                                    <span class="badge-count"><?= $totalFavs ?></span>
                                    <?php endif; ?>
                                </a>
                                <a href="<?= APP_URL ?>/perfil.php?tab=notificaciones" class="dropdown-item" role="menuitem">
                                    <i class="bi bi-bell"></i> Notificaciones
                                    <?php if ($notifCount > 0): ?>
                                    <span class="badge-count badge-danger"><?= $notifCount ?></span>
                                    <?php endif; ?>
                                </a>
                                <?php if (isAdmin()): ?>
                                <div class="dropdown-divider"></div>
                                <a href="<?= APP_URL ?>/admin/dashboard.php" class="dropdown-item dropdown-item--admin" role="menuitem">
                                    <i class="bi bi-speedometer2"></i> Panel Admin
                                </a>
                                <?php endif; ?>
                                <?php if (!isPremium()): ?>
                                <div class="dropdown-divider"></div>
                                <a href="<?= APP_URL ?>/perfil.php?tab=premium" class="dropdown-item dropdown-item--premium" role="menuitem">
                                    <i class="bi bi-star-fill"></i> Hazte Premium
                                </a>
                                <?php endif; ?>
                                <div class="dropdown-divider"></div>
                                <a href="<?= APP_URL ?>/admin/logout.php" class="dropdown-item dropdown-item--logout" role="menuitem">
                                    <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <a href="<?= APP_URL ?>/login.php" class="topbar-login-btn">
                        <i class="bi bi-person-circle"></i>
                        <span class="d-none d-sm-inline">Iniciar Sesión</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- LOGO + BÚSQUEDA + ANUNCIO HEADER -->
    <div class="header-main">
        <div class="container-fluid px-3 px-lg-4">
            <div class="header-main__inner">

                <!-- Logo -->
                <div class="header-logo">
                    <a href="<?= APP_URL ?>/index.php" aria-label="<?= e($siteName) ?> — Inicio">
                        <?php if ($siteLogo): ?>
                        <img src="<?= APP_URL ?>/<?= e(ltrim($siteLogo, '/')) ?>"
                             alt="<?= e($siteName) ?>"
                             class="logo-img"
                             width="200" height="60">
                        <?php else: ?>
                        <div class="logo-text">
                            <span class="logo-name"><?= e($siteName) ?></span>
                            <span class="logo-tagline"><?= e(Config::get('site_tagline', APP_TAGLINE)) ?></span>
                        </div>
                        <?php endif; ?>
                    </a>
                </div>

                <!-- Anuncio Header (728x90) -->
                <?php
                $adsHeader = getAnuncios('header', null, 1);
                if (!empty($adsHeader) && Config::bool('ads_header')):
                ?>
                <div class="header-ad d-none d-lg-flex">
                    <?= renderAnuncio($adsHeader[0]) ?>
                </div>
                <?php endif; ?>

                <!-- Búsqueda rápida desktop -->
                <div class="header-search" id="headerSearch">
                    <form action="<?= APP_URL ?>/buscar.php" method="GET"
                          class="search-form" role="search">
                        <div class="search-input-wrap">
                            <i class="bi bi-search search-icon"></i>
                            <input type="search"
                                   name="q"
                                   id="headerSearchInput"
                                   class="search-input"
                                   placeholder="Buscar noticias..."
                                   autocomplete="off"
                                   maxlength="100"
                                   aria-label="Buscar noticias">
                            <div class="search-suggestions" id="searchSuggestions" hidden></div>
                        </div>
                        <button type="submit" class="search-btn" aria-label="Buscar">
                            <i class="bi bi-search"></i>
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </div>

    <!-- NAVEGACIÓN PRINCIPAL -->
    <nav class="site-nav" id="siteNav" role="navigation" aria-label="Navegación principal">
        <div class="container-fluid px-3 px-lg-4">
            <div class="nav-inner">

                <!-- Hamburger móvil -->
                <button class="nav-hamburger" id="navHamburger"
                        aria-label="Abrir menú" aria-expanded="false"
                        aria-controls="navMenu">
                    <span class="hamburger-line"></span>
                    <span class="hamburger-line"></span>
                    <span class="hamburger-line"></span>
                </button>

                <!-- Menú de navegación -->
                <ul class="nav-menu" id="navMenu" role="menubar">

                    <!-- Inicio -->
                    <li class="nav-item" role="none">
                        <a href="<?= APP_URL ?>/index.php"
                           class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : '' ?>"
                           role="menuitem">
                            <i class="bi bi-house-fill d-md-none"></i>
                            Inicio
                        </a>
                    </li>

                    <!-- Categorías dinámicas -->
                    <?php
                    $numCats = Config::int('contenido_cats_en_index', 4);
                    $catsNav = array_slice($categorias, 0, $numCats + 4);
                    foreach ($catsNav as $index => $cat):
                        $esActiva = (basename($_SERVER['PHP_SELF']) === 'categoria.php' &&
                                    ($_GET['slug'] ?? '') === $cat['slug']);
                    ?>
                    <li class="nav-item" role="none">
                        <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($cat['slug']) ?>"
                           class="nav-link <?= $esActiva ? 'active' : '' ?>"
                           style="--cat-color: <?= e($cat['color']) ?>"
                           role="menuitem">
                            <i class="bi <?= e($cat['icono'] ?? 'bi-tag') ?> d-md-none"></i>
                            <?= e($cat['nombre']) ?>
                        </a>
                    </li>
                    <?php endforeach; ?>

                    <!-- Más categorías (dropdown) -->
                    <?php if (count($categorias) > $numCats + 4): ?>
                    <li class="nav-item nav-item--dropdown" role="none">
                        <button class="nav-link nav-dropdown-toggle"
                                aria-haspopup="true" aria-expanded="false"
                                role="menuitem">
                            Más <i class="bi bi-chevron-down"></i>
                        </button>
                        <ul class="nav-dropdown" role="menu">
                            <?php foreach (array_slice($categorias, $numCats + 4) as $cat): ?>
                            <li role="none">
                                <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($cat['slug']) ?>"
                                   class="nav-dropdown__item" role="menuitem">
                                    <span class="cat-dot" style="background:<?= e($cat['color']) ?>"></span>
                                    <?= e($cat['nombre']) ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <?php endif; ?>

                    <!-- Live Blog (si hay activos) -->
                    <?php
                    $livesActivos = getLiveBlogsActivos(1);
                    if (!empty($livesActivos)):
                    ?>
                    <li class="nav-item" role="none">
                        <a href="<?= APP_URL ?>/live.php"
                           class="nav-link nav-link--live <?= basename($_SERVER['PHP_SELF']) === 'live.php' ? 'active' : '' ?>"
                           role="menuitem">
                            <span class="live-dot"></span>
                            EN VIVO
                        </a>
                    </li>
                    <?php endif; ?>

                </ul>

                <!-- Acciones derecha del nav -->
                <div class="nav-actions">

                    <!-- Botón búsqueda móvil -->
                    <button class="nav-action-btn" id="mobileSearchToggle"
                            aria-label="Buscar">
                        <i class="bi bi-search"></i>
                    </button>

                    <!-- Notificaciones móvil -->
                    <?php if ($usuario): ?>
                    <a href="<?= APP_URL ?>/perfil.php?tab=notificaciones"
                       class="nav-action-btn nav-notif-btn"
                       aria-label="Notificaciones">
                        <i class="bi bi-bell<?= $notifCount > 0 ? '-fill' : '' ?>"></i>
                        <?php if ($notifCount > 0): ?>
                        <span class="nav-notif-count"><?= $notifCount > 9 ? '9+' : $notifCount ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>

                </div>

            </div>

            <!-- Búsqueda móvil (expandible) -->
            <div class="mobile-search" id="mobileSearch" hidden>
                <form action="<?= APP_URL ?>/buscar.php" method="GET"
                      class="mobile-search__form" role="search">
                    <input type="search"
                           name="q"
                           class="mobile-search__input"
                           placeholder="Buscar noticias..."
                           autocomplete="off"
                           maxlength="100"
                           aria-label="Buscar noticias">
                    <button type="submit" aria-label="Buscar">
                        <i class="bi bi-search"></i>
                    </button>
                    <button type="button" id="mobileSearchClose" aria-label="Cerrar búsqueda">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </form>
            </div>

        </div>
    </nav>

    <!-- OVERLAY para menú móvil -->
    <div class="nav-overlay" id="navOverlay" aria-hidden="true"></div>

</header>

<!-- ============================================================
     MENÚ MÓVIL (drawer lateral)
     ============================================================ -->
<div class="mobile-drawer" id="mobileDrawer" role="dialog"
     aria-label="Menú de navegación" aria-hidden="true">
    <div class="mobile-drawer__header">
        <div class="mobile-drawer__logo">
            <?php if ($siteLogo): ?>
            <img src="<?= APP_URL ?>/<?= e(ltrim($siteLogo, '/')) ?>"
                 alt="<?= e($siteName) ?>" height="40">
            <?php else: ?>
            <span class="logo-text-sm"><?= e($siteName) ?></span>
            <?php endif; ?>
        </div>
        <button class="mobile-drawer__close" id="drawerClose" aria-label="Cerrar menú">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <!-- Usuario en el drawer -->
    <?php if ($usuario): ?>
    <div class="mobile-drawer__user">
        <img src="<?= e(getImageUrl($usuario['avatar'], 'avatar')) ?>"
             alt="<?= e($usuario['nombre']) ?>"
             width="48" height="48" class="drawer-avatar">
        <div class="drawer-user-info">
            <strong><?= e($usuario['nombre']) ?></strong>
            <span><?= e($usuario['email']) ?></span>
        </div>
    </div>
    <?php else: ?>
    <div class="mobile-drawer__auth">
        <a href="<?= APP_URL ?>/login.php" class="drawer-login-btn">
            <i class="bi bi-person-circle"></i>
            Iniciar Sesión / Registrarse
        </a>
    </div>
    <?php endif; ?>

    <!-- Navegación del drawer -->
    <nav class="mobile-drawer__nav" aria-label="Menú móvil">
        <ul class="drawer-menu">
            <li class="drawer-menu__item">
                <a href="<?= APP_URL ?>/index.php" class="drawer-menu__link">
                    <i class="bi bi-house-fill"></i> Inicio
                </a>
            </li>
            <?php foreach ($categorias as $cat): ?>
            <li class="drawer-menu__item">
                <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($cat['slug']) ?>"
                   class="drawer-menu__link">
                    <span class="drawer-cat-dot" style="background:<?= e($cat['color']) ?>"></span>
                    <?= e($cat['nombre']) ?>
                    <?php if ($cat['total_noticias'] > 0): ?>
                    <span class="drawer-cat-count"><?= formatNumber((int)$cat['total_noticias']) ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
            <?php if (!empty($livesActivos)): ?>
            <li class="drawer-menu__item">
                <a href="<?= APP_URL ?>/live.php" class="drawer-menu__link drawer-menu__link--live">
                    <span class="live-dot"></span> Cobertura en Vivo
                </a>
            </li>
            <?php endif; ?>
            <li class="drawer-menu__divider"></li>
            <li class="drawer-menu__item">
                <a href="<?= APP_URL ?>/buscar.php" class="drawer-menu__link">
                    <i class="bi bi-search"></i> Búsqueda Avanzada
                </a>
            </li>
            <?php if ($usuario): ?>
            <li class="drawer-menu__item">
                <a href="<?= APP_URL ?>/perfil.php" class="drawer-menu__link">
                    <i class="bi bi-person-circle"></i> Mi Perfil
                </a>
            </li>
            <li class="drawer-menu__item">
                <a href="<?= APP_URL ?>/perfil.php?tab=guardados" class="drawer-menu__link">
                    <i class="bi bi-bookmark-heart"></i> Guardados
                </a>
            </li>
            <?php if (isAdmin()): ?>
            <li class="drawer-menu__item">
                <a href="<?= APP_URL ?>/admin/dashboard.php" class="drawer-menu__link drawer-menu__link--admin">
                    <i class="bi bi-speedometer2"></i> Panel Admin
                </a>
            </li>
            <?php endif; ?>
            <li class="drawer-menu__item">
                <a href="<?= APP_URL ?>/admin/logout.php" class="drawer-menu__link drawer-menu__link--logout">
                    <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>

    <!-- Redes sociales en el drawer -->
    <div class="mobile-drawer__social">
        <?php foreach ($redesSoc as $red => $url): ?>
        <?php if (!empty($url)): ?>
        <a href="<?= e($url) ?>" target="_blank" rel="noopener"
           class="drawer-social-link" aria-label="<?= ucfirst($red) ?>">
            <i class="bi bi-<?= match($red) {
                'facebook'  => 'facebook',
                'twitter'   => 'twitter-x',
                'instagram' => 'instagram',
                'youtube'   => 'youtube',
                'tiktok'    => 'tiktok',
                'whatsapp'  => 'whatsapp',
                'telegram'  => 'telegram',
                default     => 'link-45deg',
            } ?>"></i>
        </a>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- Modo oscuro en el drawer -->
    <div class="mobile-drawer__footer">
        <button class="drawer-darkmode-btn" id="drawerDarkMode">
            <i class="bi bi-moon-stars-fill"></i>
            <span>Modo Oscuro</span>
            <div class="toggle-switch" id="drawerDarkSwitch">
                <div class="toggle-thumb"></div>
            </div>
        </button>
    </div>
</div>

<!-- ============================================================
     CONTENIDO PRINCIPAL (apertura del main)
     ============================================================ -->
<main id="main-content" class="site-main" tabindex="-1">

<?php
// ── Script de inicialización del header ─────────────────────
?>
<script>
(function() {
    'use strict';

    // ── Modo oscuro ──────────────────────────────────────────
    const darkConfig = '<?= e($modoOscuroConfig) ?>';
    const savedTheme = localStorage.getItem('pd_theme');

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        const icon = document.getElementById('darkModeIcon');
        if (icon) {
            icon.className = theme === 'dark'
                ? 'bi bi-sun-fill'
                : 'bi bi-moon-stars-fill';
        }
        const drawerSwitch = document.getElementById('drawerDarkSwitch');
        if (drawerSwitch) {
            drawerSwitch.classList.toggle('active', theme === 'dark');
        }
    }

    let currentTheme = 'light';
    if (savedTheme) {
        currentTheme = savedTheme;
    } else if (darkConfig === 'oscuro') {
        currentTheme = 'dark';
    } else if (darkConfig === 'auto') {
        currentTheme = window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark' : 'light';
    }
    applyTheme(currentTheme);

    // ── Toggle modo oscuro ───────────────────────────────────
    function toggleDarkMode() {
        const current = document.documentElement.getAttribute('data-theme');
        const next    = current === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        localStorage.setItem('pd_theme', next);
    }

    document.addEventListener('DOMContentLoaded', function() {

        // Botones de modo oscuro
        const dmToggle = document.getElementById('darkModeToggle');
        const dmDrawer = document.getElementById('drawerDarkMode');
        if (dmToggle) dmToggle.addEventListener('click', toggleDarkMode);
        if (dmDrawer) dmDrawer.addEventListener('click', toggleDarkMode);

        // ── Menú hamburger ───────────────────────────────────
        const hamburger = document.getElementById('navHamburger');
        const drawer    = document.getElementById('mobileDrawer');
        const overlay   = document.getElementById('navOverlay');
        const drawerClose = document.getElementById('drawerClose');

        function openDrawer() {
            drawer?.classList.add('open');
            overlay?.classList.add('active');
            hamburger?.setAttribute('aria-expanded', 'true');
            drawer?.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function closeDrawer() {
            drawer?.classList.remove('open');
            overlay?.classList.remove('active');
            hamburger?.setAttribute('aria-expanded', 'false');
            drawer?.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        hamburger?.addEventListener('click', openDrawer);
        drawerClose?.addEventListener('click', closeDrawer);
        overlay?.addEventListener('click', closeDrawer);

        // Cerrar con Escape
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                closeDrawer();
                closeMobileSearch();
            }
        });

        // ── Búsqueda móvil ───────────────────────────────────
        const mobileSearchToggle = document.getElementById('mobileSearchToggle');
        const mobileSearch       = document.getElementById('mobileSearch');
        const mobileSearchClose  = document.getElementById('mobileSearchClose');

        function openMobileSearch() {
            mobileSearch?.removeAttribute('hidden');
            mobileSearch?.querySelector('input')?.focus();
        }
        function closeMobileSearch() {
            mobileSearch?.setAttribute('hidden', '');
        }

        mobileSearchToggle?.addEventListener('click', openMobileSearch);
        mobileSearchClose?.addEventListener('click', closeMobileSearch);

        // ── Dropdown usuario ─────────────────────────────────
        const userToggle   = document.getElementById('topbarUserToggle');
        const userDropdown = document.getElementById('topbarDropdown');

        userToggle?.addEventListener('click', function(e) {
            e.stopPropagation();
            const open = userDropdown?.classList.toggle('open');
            userToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        document.addEventListener('click', function() {
            userDropdown?.classList.remove('open');
            userToggle?.setAttribute('aria-expanded', 'false');
        });

        // ── Breaking News rotación ───────────────────────────
        const breakingClose = document.getElementById('breakingClose');
        breakingClose?.addEventListener('click', function() {
            const bar = document.getElementById('breakingBar');
            bar?.remove();
            sessionStorage.setItem('breaking_closed', '1');
        });

        if (sessionStorage.getItem('breaking_closed') === '1') {
            document.getElementById('breakingBar')?.remove();
        }

        // ── Header sticky con shadow ─────────────────────────
        const siteHeader = document.getElementById('siteHeader');
        let lastScroll   = 0;

        window.addEventListener('scroll', function() {
            const current = window.scrollY;
            if (current > 80) {
                siteHeader?.classList.add('header-scrolled');
            } else {
                siteHeader?.classList.remove('header-scrolled');
            }
            // Hide on scroll down, show on scroll up
            if (current > lastScroll && current > 200) {
                siteHeader?.classList.add('header-hidden');
            } else {
                siteHeader?.classList.remove('header-hidden');
            }
            lastScroll = current <= 0 ? 0 : current;
        }, { passive: true });

        // ── Búsqueda con autocomplete ────────────────────────
        const searchInput = document.getElementById('headerSearchInput');
        const suggestions = document.getElementById('searchSuggestions');
        let searchTimeout = null;

        searchInput?.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const q = this.value.trim();

            if (q.length < 3) {
                suggestions?.setAttribute('hidden', '');
                return;
            }

            searchTimeout = setTimeout(async () => {
                try {
                    const res  = await fetch(
                        `${window.APP.url}/ajax/handler.php`,
                        {
                            method:  'POST',
                            headers: {
                                'Content-Type':    'application/json',
                                'X-CSRF-Token':    window.APP.csrfToken,
                                'X-Requested-With':'XMLHttpRequest',
                            },
                            body: JSON.stringify({ action: 'search_suggest', q }),
                        }
                    );
                    const data = await res.json();

                    if (data.results?.length > 0 && suggestions) {
                        suggestions.innerHTML = data.results.map(r => `
                            <a href="${window.APP.url}/noticia.php?slug=${r.slug}"
                               class="suggestion-item">
                                <img src="${r.imagen}" alt="" width="40" height="40" loading="lazy">
                                <div>
                                    <span class="suggestion-title">${r.titulo}</span>
                                    <span class="suggestion-cat" style="color:${r.cat_color}">${r.cat_nombre}</span>
                                </div>
                            </a>
                        `).join('');
                        suggestions.removeAttribute('hidden');
                    } else {
                        suggestions?.setAttribute('hidden', '');
                    }
                } catch {}
            }, 350);
        });

        searchInput?.addEventListener('blur', () => {
            setTimeout(() => suggestions?.setAttribute('hidden', ''), 200);
        });

        // ── Tamaño de fuente ─────────────────────────────────
        const fontSizes   = [14, 15, 16, 17, 18];
        let fontIdx       = parseInt(localStorage.getItem('pd_font_size') ?? '2');
        document.documentElement.style.fontSize = fontSizes[fontIdx] + 'px';

        document.getElementById('fontIncrease')?.addEventListener('click', () => {
            if (fontIdx < fontSizes.length - 1) {
                fontIdx++;
                document.documentElement.style.fontSize = fontSizes[fontIdx] + 'px';
                localStorage.setItem('pd_font_size', String(fontIdx));
            }
        });
        document.getElementById('fontDecrease')?.addEventListener('click', () => {
            if (fontIdx > 0) {
                fontIdx--;
                document.documentElement.style.fontSize = fontSizes[fontIdx] + 'px';
                localStorage.setItem('pd_font_size', String(fontIdx));
            }
        });

        // ── Dropdown nav ─────────────────────────────────────
        // Cierra todos los dropdowns abiertos
        function cerrarDropdowns() {
            document.querySelectorAll('.nav-dropdown.open').forEach(d => {
                d.classList.remove('open');
                d.closest('.nav-item--dropdown')
                  ?.querySelector('.nav-dropdown-toggle')
                  ?.setAttribute('aria-expanded', 'false');
            });
        }

        // Posiciona el dropdown en coordenadas viewport (position:fixed)
        // para escapar del overflow-x:auto del .nav-menu
        function posicionarDropdown(btn, dropdown) {
            const li   = btn.closest('.nav-item--dropdown');
            const rect = li.getBoundingClientRect();
            dropdown.style.top  = (rect.bottom + 3) + 'px';
            // Si el dropdown se sale por la derecha, lo alineamos a la derecha del botón
            const anchoDropdown = dropdown.offsetWidth || 220;
            const sobra = rect.left + anchoDropdown - window.innerWidth;
            dropdown.style.left = sobra > 0
                ? Math.max(0, rect.right - anchoDropdown) + 'px'
                : rect.left + 'px';
        }

        document.querySelectorAll('.nav-dropdown-toggle').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const dropdown = this.closest('.nav-item--dropdown')
                                     ?.querySelector('.nav-dropdown');
                if (!dropdown) return;

                const estaAbierto = dropdown.classList.contains('open');

                // Cierra cualquier otro dropdown abierto
                cerrarDropdowns();

                if (!estaAbierto) {
                    // Posicionar ANTES de mostrar para que offsetWidth sea correcto
                    dropdown.style.visibility = 'hidden';
                    dropdown.classList.add('open');
                    posicionarDropdown(this, dropdown);
                    dropdown.style.visibility = '';
                    this.setAttribute('aria-expanded', 'true');
                }
            });
        });

        // Clic fuera → cerrar
        document.addEventListener('click', cerrarDropdowns);

        // Scroll → cerrar (el dropdown fixed se desplazaría del botón)
        window.addEventListener('scroll', cerrarDropdowns, { passive: true });

        // Resize → reposicionar si está abierto
        window.addEventListener('resize', () => {
            document.querySelectorAll('.nav-dropdown.open').forEach(d => {
                const btn = d.closest('.nav-item--dropdown')
                              ?.querySelector('.nav-dropdown-toggle');
                if (btn) posicionarDropdown(btn, d);
            });
        }, { passive: true });

        // ── Flash message auto-dismiss ───────────────────────
        const flash = document.getElementById('flashMessage');
        if (flash) {
            setTimeout(() => {
                flash.style.opacity = '0';
                flash.style.transform = 'translateY(-20px)';
                setTimeout(() => flash.remove(), 400);
            }, 5000);
        }

    }); // DOMContentLoaded
})();
</script>