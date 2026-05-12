<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Admin: Sidebar Parcial
 * ============================================================
 * Archivo  : admin/sidebar.php
 * Versión  : 2.0.0
 * Autor    : MM Lab Studio
 * ============================================================
 * Componente reutilizable del sidebar del panel administrativo.
 * Debe ser incluido DESPUÉS de inicializar $auth y $usuario.
 * Detecta automáticamente la página activa por REQUEST_URI.
 * ============================================================
 */

declare(strict_types=1);

if (!defined('APP_NAME')) {
    require_once dirname(__DIR__) . '/config/database.php';
}

// ── Asegurar que tenemos el usuario ──────────────────────────
if (!isset($usuario)) {
    $usuario = currentUser();
}

// ── Detectar página activa ───────────────────────────────────
$currentFile = basename($_SERVER['PHP_SELF'] ?? '');
$currentPath = $_SERVER['REQUEST_URI'] ?? '';

function isActivePage(string $file, string $current): string {
    return (basename($current) === $file || str_contains($current, $file))
        ? 'active' : '';
}

// ── Contadores para badges ────────────────────────────────────
$sidebarBadges = [];
try {
    // Noticias en borrador
    $sidebarBadges['borradores'] = (int)db()->count(
        "SELECT COUNT(*) FROM noticias WHERE estado = 'borrador'"
    );
    // Comentarios pendientes de aprobación
    $sidebarBadges['comentarios_pendientes'] = (int)db()->count(
        "SELECT COUNT(*) FROM comentarios WHERE aprobado = 0"
    );
    // Reportes de contenido pendientes
    $sidebarBadges['reportes'] = (int)db()->count(
        "SELECT COUNT(*) FROM reportes_contenido WHERE estado = 'pendiente'"
    );
    // Usuarios pendientes de activación
    $sidebarBadges['usuarios_inactivos'] = (int)db()->count(
        "SELECT COUNT(*) FROM usuarios WHERE activo = 0 AND fecha_registro >= NOW() - INTERVAL 7 DAY"
    );
    // Anuncios próximos a vencer (en 7 días)
    $sidebarBadges['anuncios_por_vencer'] = (int)db()->count(
        "SELECT COUNT(*) FROM anuncios
         WHERE activo = 1
           AND fecha_fin IS NOT NULL
           AND fecha_fin BETWEEN NOW() AND NOW() + INTERVAL 7 DAY"
    );
} catch (\Throwable $e) {
    $sidebarBadges = [
        'borradores'            => 0,
        'comentarios_pendientes'=> 0,
        'reportes'              => 0,
        'usuarios_inactivos'    => 0,
        'anuncios_por_vencer'   => 0,
    ];
}

$totalAlerts = $sidebarBadges['comentarios_pendientes']
             + $sidebarBadges['reportes'];
?>

<!-- ══════════════════════════════════════════════════════════
     SIDEBAR ADMIN — El Click RD
     ══════════════════════════════════════════════════════════ -->
<aside class="admin-sidebar" id="adminSidebar" role="navigation"
       aria-label="Panel de administración">

    <!-- Logo / Marca -->
    <div class="admin-sidebar__logo">
        <a href="<?= APP_URL ?>/admin/dashboard.php"
           title="Ir al Dashboard">
            <div class="admin-sidebar__logo-icon">
                <i class="bi bi-newspaper"></i>
            </div>
            <div>
                <div class="admin-sidebar__logo-text">
                    <?= e(Config::get('site_nombre', APP_NAME)) ?>
                </div>
                <div class="admin-sidebar__logo-sub">Panel Admin v2.0</div>
            </div>
        </a>
    </div>

    <!-- Usuario logueado -->
    <div class="admin-sidebar__user">
        <img src="<?= e(getImageUrl($usuario['avatar'] ?? '', 'avatar')) ?>"
             alt="<?= e($usuario['nombre']) ?>"
             loading="lazy">
        <div style="overflow:hidden">
            <span class="admin-sidebar__user-name">
                <?= e(truncateChars($usuario['nombre'], 22)) ?>
            </span>
            <span class="admin-sidebar__user-role">
                <?php
                $roleLabels = [
                    'super_admin' => '⭐ Super Admin',
                    'admin'       => '🛡️ Admin',
                    'auditor'     => '👁️ Auditor',
                    'user'        => '👤 Usuario',
                ];
                echo e($roleLabels[$usuario['rol']] ?? ucfirst($usuario['rol']));
                ?>
            </span>
        </div>
        <?php if ($totalAlerts > 0): ?>
        <span style="margin-left:auto;background:var(--danger);color:#fff;
                     font-size:.62rem;font-weight:800;width:18px;height:18px;
                     border-radius:50%;display:flex;align-items:center;
                     justify-content:center;flex-shrink:0">
            <?= $totalAlerts > 9 ? '9+' : $totalAlerts ?>
        </span>
        <?php endif; ?>
    </div>

    <!-- Navegación -->
    <nav class="admin-nav" id="adminNav">

        <!-- ── PRINCIPAL ─────────────────────────────────── -->
        <div class="admin-nav__section">Principal</div>

        <a href="<?= APP_URL ?>/admin/dashboard.php"
           class="admin-nav__item <?= isActivePage('dashboard.php', $currentFile) ?>">
            <i class="bi bi-speedometer2"></i>
            <span>Dashboard</span>
        </a>

        <!-- ── CONTENIDO ─────────────────────────────────── -->
        <div class="admin-nav__section">Contenido</div>

        <a href="<?= APP_URL ?>/admin/noticias.php"
           class="admin-nav__item <?= isActivePage('noticias.php', $currentFile) ?>">
            <i class="bi bi-newspaper"></i>
            <span>Noticias</span>
            <?php if ($sidebarBadges['borradores'] > 0): ?>
            <span class="admin-nav__badge">
                <?= $sidebarBadges['borradores'] ?>
            </span>
            <?php endif; ?>
        </a>

        <a href="<?= APP_URL ?>/admin/media.php"
           class="admin-nav__item <?= isActivePage('media.php', $currentFile) ?>">
            <i class="bi bi-images"></i>
            <span>Multimedia</span>
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
           class="admin-nav__item <?= isActivePage('comentarios.php', $currentFile) ?>">
            <i class="bi bi-chat-dots-fill"></i>
            <span>Comentarios</span>
            <?php if ($sidebarBadges['comentarios_pendientes'] > 0): ?>
            <span class="admin-nav__badge">
                <?= $sidebarBadges['comentarios_pendientes'] ?>
            </span>
            <?php endif; ?>
        </a>
        
        <a href="<?= APP_URL ?>/admin/combustibles.php"
           class="admin-nav__item <?= isActivePage('combustibles.php', $currentFile) ?>">
            <i class="bi bi-fuel-pump-fill"
               style="color:<?= isActivePage('combustibles.php', $currentFile) === 'active'
                   ? 'inherit' : '#f59e0b' ?>"></i>
            <span>Precio Combustibles</span>
        </a>

        <!-- ── MONETIZACIÓN ──────────────────────────────── -->
        <div class="admin-nav__section">Monetización</div>

        <a href="<?= APP_URL ?>/admin/anuncios.php"
           class="admin-nav__item <?= isActivePage('anuncios.php', $currentFile) ?>">
            <i class="bi bi-badge-ad-fill"></i>
            <span>Anuncios</span>
            <?php if ($sidebarBadges['anuncios_por_vencer'] > 0): ?>
            <span class="admin-nav__badge" style="background:var(--warning)">
                <?= $sidebarBadges['anuncios_por_vencer'] ?>
            </span>
            <?php endif; ?>
        </a>

        <!-- ── GESTIÓN ───────────────────────────────────── -->
        <div class="admin-nav__section">Gestión</div>

        <a href="<?= APP_URL ?>/admin/usuarios.php"
           class="admin-nav__item <?= isActivePage('usuarios.php', $currentFile) ?>">
            <i class="bi bi-people-fill"></i>
            <span>Usuarios</span>
            <?php if ($sidebarBadges['usuarios_inactivos'] > 0): ?>
            <span class="admin-nav__badge" style="background:var(--info)">
                <?= $sidebarBadges['usuarios_inactivos'] ?>
            </span>
            <?php endif; ?>
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
           class="admin-nav__item <?= isActivePage('configuracion.php', $currentFile) ?>">
            <i class="bi bi-gear-fill"></i>
            <span>Configuración</span>
        </a>

        <a href="<?= APP_URL ?>/index.php"
           target="_blank"
           rel="noopener noreferrer"
           class="admin-nav__item">
            <i class="bi bi-box-arrow-up-right"></i>
            <span>Ver sitio</span>
        </a>

    </nav><!-- /.admin-nav -->

    <!-- Footer del sidebar -->
    <div class="admin-sidebar__footer">
        <div style="padding:0 20px 8px;font-size:.65rem;
                    color:rgba(255,255,255,.2);text-align:center">
            v<?= APP_VERSION ?> · <?= date('Y') ?> El Click RD
        </div>
        <a href="<?= APP_URL ?>/admin/logout.php"
           class="admin-nav__item"
           style="color:rgba(255,100,100,.7)"
           onclick="return confirm('¿Cerrar sesión?')">
            <i class="bi bi-box-arrow-right"></i>
            <span>Cerrar sesión</span>
        </a>
    </div>

</aside><!-- /.admin-sidebar -->

<!-- Overlay para móvil -->
<div class="admin-overlay" id="adminOverlay"
     onclick="closeSidebar()" aria-hidden="true"></div>