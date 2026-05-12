<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Footer Global
 * ============================================================
 * Archivo : includes/footer.php
 * Versión : 2.0.0
 * ============================================================
 * Cierra el <main> abierto en header.php y renderiza:
 *  1. Sección de newsletter
 *  2. Footer completo con columnas
 *  3. Bottom navigation bar (app móvil)
 *  4. PWA install prompt
 *  5. Botón volver arriba
 *  6. Modal de cookies
 *  7. Scripts JS globales
 * ============================================================
 */

declare(strict_types=1);

if (!defined('APP_NAME')) {
    require_once dirname(__DIR__) . '/config/database.php';
}

// ── Datos para el footer ──────────────────────────────────────
$footerCats   = getCategorias(false);
$footerRedes  = getRedesSociales();
$siteName     = Config::get('site_nombre',  APP_NAME);
$siteTagline  = Config::get('site_tagline', APP_TAGLINE);
$siteLogo     = Config::get('site_logo',    '');
$colorPrim    = Config::get('apariencia_color_primario', '#e63946');
$usuario      = currentUser();

// Noticias recientes para el footer
$footerNoticias = db()->fetchAll(
    "SELECT n.titulo, n.slug, n.imagen, n.fecha_publicacion,
            c.color AS cat_color, c.nombre AS cat_nombre
     FROM noticias n
     INNER JOIN categorias c ON c.id = n.categoria_id
     WHERE n.estado = 'publicado' AND n.fecha_publicacion <= NOW()
     ORDER BY n.fecha_publicacion DESC
     LIMIT 4"
);

// Anuncio footer
$adsFooter = getAnuncios('footer', null, 1);

?>

</main><!-- /#main-content -->

<!-- ============================================================
     SECCIÓN NEWSLETTER
     ============================================================ -->
<section class="newsletter-section" aria-labelledby="newsletter-title">
    <div class="container-fluid px-3 px-lg-4">
        <div class="newsletter-inner">
            <div class="newsletter-content">
                <div class="newsletter-icon">
                    <i class="bi bi-envelope-paper-fill"></i>
                </div>
                <div class="newsletter-text">
                    <h2 id="newsletter-title">¡No te pierdas ninguna noticia!</h2>
                    <p>Recibe las noticias más importantes directamente en tu correo. Sin spam, solo lo que importa.</p>
                </div>
            </div>
            <form class="newsletter-form" id="newsletterForm" novalidate>
                <?= csrfField() ?>
                <div class="newsletter-form__fields">
                    <input type="text"
                           name="nombre"
                           class="newsletter-input"
                           placeholder="Tu nombre"
                           maxlength="100"
                           autocomplete="given-name">
                    <input type="email"
                           name="email"
                           class="newsletter-input"
                           placeholder="Tu correo electrónico"
                           required
                           maxlength="150"
                           autocomplete="email">
                    <button type="submit" class="newsletter-btn">
                        <span class="btn-text">Suscribirme</span>
                        <i class="bi bi-send-fill"></i>
                        <span class="btn-spinner" hidden>
                            <i class="bi bi-arrow-repeat spin"></i>
                        </span>
                    </button>
                </div>
                <p class="newsletter-privacy">
                    <i class="bi bi-shield-check"></i>
                    Tu privacidad está protegida. Puedes darte de baja en cualquier momento.
                </p>
                <div class="newsletter-msg" id="newsletterMsg" hidden></div>
            </form>
        </div>
    </div>
</section>

<!-- ============================================================
     ANUNCIO ANTES DEL FOOTER
     ============================================================ -->
<?php if (!empty($adsFooter)): ?>
<div class="footer-ad-wrapper">
    <div class="container-fluid px-3 px-lg-4">
        <?= renderAnuncio($adsFooter[0]) ?>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     FOOTER PRINCIPAL
     ============================================================ -->
<footer class="site-footer" role="contentinfo">
    <div class="container-fluid px-3 px-lg-4">

        <!-- Grid de columnas del footer -->
        <div class="footer-grid">

            <!-- Columna 1: Logo + descripción + redes -->
            <div class="footer-col footer-col--brand">
                <div class="footer-logo">
                    <?php if ($siteLogo): ?>
                    <img src="<?= APP_URL ?>/<?= e(ltrim($siteLogo, '/')) ?>"
                         alt="<?= e($siteName) ?>"
                         height="48" loading="lazy">
                    <?php else: ?>
                    <div class="footer-logo-text">
                        <span class="footer-logo-name"><?= e($siteName) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <p class="footer-description">
                    <?= e($siteTagline) ?>.
                    Tu fuente confiable de noticias actualizadas las 24 horas del día, los 7 días de la semana.
                </p>

                <!-- Redes sociales -->
                <div class="footer-social" aria-label="Redes sociales">
                    <?php
                    $socialIcons = [
                        'facebook'  => ['icon'=>'bi-facebook',  'label'=>'Facebook'],
                        'twitter'   => ['icon'=>'bi-twitter-x', 'label'=>'Twitter/X'],
                        'instagram' => ['icon'=>'bi-instagram', 'label'=>'Instagram'],
                        'youtube'   => ['icon'=>'bi-youtube',   'label'=>'YouTube'],
                        'tiktok'    => ['icon'=>'bi-tiktok',    'label'=>'TikTok'],
                        'telegram'  => ['icon'=>'bi-telegram',  'label'=>'Telegram'],
                        'whatsapp'  => ['icon'=>'bi-whatsapp',  'label'=>'WhatsApp'],
                    ];
                    foreach ($socialIcons as $red => $info):
                        if (!empty($footerRedes[$red])):
                    ?>
                    <a href="<?= e($footerRedes[$red]) ?>"
                       target="_blank" rel="noopener"
                       class="footer-social__link footer-social__link--<?= $red ?>"
                       aria-label="<?= $info['label'] ?>">
                        <i class="bi <?= $info['icon'] ?>"></i>
                    </a>
                    <?php endif; endforeach; ?>
                </div>

                <!-- App badges -->
                <div class="footer-app-badges">
                    <span class="app-badge-label">
                        <i class="bi bi-phone-fill"></i>
                        Disponible como App
                    </span>
                    <button class="app-badge" id="pwaInstallFooter" hidden>
                        <i class="bi bi-download"></i>
                        Instalar App
                    </button>
                </div>
            </div>

            <!-- Columna 2: Categorías -->
            <div class="footer-col footer-col--cats">
                <h3 class="footer-col__title">
                    <i class="bi bi-grid-3x3-gap-fill"></i>
                    Secciones
                </h3>
                <ul class="footer-links">
                    <?php foreach ($footerCats as $cat): ?>
                    <li>
                        <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($cat['slug']) ?>"
                           class="footer-link">
                            <span class="footer-cat-dot"
                                  style="background:<?= e($cat['color']) ?>"></span>
                            <?= e($cat['nombre']) ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Columna 3: Noticias recientes -->
            <div class="footer-col footer-col--news">
                <h3 class="footer-col__title">
                    <i class="bi bi-newspaper"></i>
                    Noticias Recientes
                </h3>
                <div class="footer-news-list">
                    <?php foreach ($footerNoticias as $fn): ?>
                    <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($fn['slug']) ?>"
                       class="footer-news-item">
                        <div class="footer-news-img">
                            <img src="<?= e(getImageUrl($fn['imagen'])) ?>"
                                 alt="<?= e($fn['titulo']) ?>"
                                 width="60" height="50" loading="lazy">
                        </div>
                        <div class="footer-news-info">
                            <span class="footer-news-cat"
                                  style="color:<?= e($fn['cat_color']) ?>">
                                <?= e($fn['cat_nombre']) ?>
                            </span>
                            <span class="footer-news-title">
                                <?= e(truncateChars($fn['titulo'], 55)) ?>
                            </span>
                            <span class="footer-news-date">
                                <i class="bi bi-clock"></i>
                                <?= timeAgo($fn['fecha_publicacion']) ?>
                            </span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Columna 4: Información + contacto -->
            <div class="footer-col footer-col--info">
                <h3 class="footer-col__title">
                    <i class="bi bi-info-circle-fill"></i>
                    Información
                </h3>
                <ul class="footer-links">
                    <li>
                        <a href="<?= APP_URL ?>/buscar.php" class="footer-link">
                            <i class="bi bi-search"></i> Búsqueda
                        </a>
                    </li>
                    <li>
                        <a href="<?= APP_URL ?>/live.php" class="footer-link">
                            <i class="bi bi-broadcast-pin"></i> Cobertura en Vivo
                        </a>
                    </li>
                    <?php if ($usuario): ?>
                    <li>
                        <a href="<?= APP_URL ?>/perfil.php" class="footer-link">
                            <i class="bi bi-person-circle"></i> Mi Perfil
                        </a>
                    </li>
                    <?php else: ?>
                    <li>
                        <a href="<?= APP_URL ?>/login.php" class="footer-link">
                            <i class="bi bi-box-arrow-in-right"></i> Iniciar Sesión
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>

                <h3 class="footer-col__title mt-4">
                    <i class="bi bi-envelope-fill"></i>
                    Contacto
                </h3>
                <ul class="footer-links footer-contact">
                    <?php
                    $whatsapp = $footerRedes['whatsapp'] ?? '';
                    if ($whatsapp):
                    ?>
                    <li>
                        <a href="https://wa.me/<?= e(preg_replace('/\D/', '', $whatsapp)) ?>"
                           class="footer-link footer-link--whatsapp"
                           target="_blank" rel="noopener">
                            <i class="bi bi-whatsapp"></i>
                            WhatsApp
                        </a>
                    </li>
                    <?php endif; ?>
                    <li>
                        <span class="footer-link">
                            <i class="bi bi-geo-alt-fill"></i>
                            Santo Domingo, RD
                        </span>
                    </li>
                </ul>
            </div>

        </div><!-- /.footer-grid -->

    </div><!-- /.container-fluid -->

    <!-- Footer bottom bar -->
    <div class="footer-bottom">
        <div class="container-fluid px-3 px-lg-4">
            <div class="footer-bottom__inner">
                <p class="footer-copyright">
                    &copy; <?= date('Y') ?> <strong><?= e($siteName) ?></strong>.
                    Todos los derechos reservados.
                    Desarrollado por Orthiis con <i class="bi bi-heart-fill" style="color:#e63946"></i>
                    en República Dominicana.
                </p>
                <div class="footer-bottom__links">
                    <a href="#" class="footer-bottom__link">Privacidad</a>
                    <a href="#" class="footer-bottom__link">Términos</a>
                    <a href="#" class="footer-bottom__link">Cookies</a>
                    <a href="#" class="footer-bottom__link">Publicidad</a>
                </div>
                <div class="footer-bottom__tech">
                    <span>PHP <?= phpversion() ?></span>
                    <span>v<?= APP_VERSION ?></span>
                </div>
            </div>
        </div>
    </div>

</footer>

<!-- ============================================================
     BOTTOM NAVIGATION BAR (App móvil)
     ============================================================ -->
<nav class="bottom-nav" id="bottomNav" role="navigation"
     aria-label="Navegación inferior">
    <a href="<?= APP_URL ?>/index.php"
       class="bottom-nav__item <?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : '' ?>"
       aria-label="Inicio">
        <i class="bi bi-house<?= basename($_SERVER['PHP_SELF']) === 'index.php' ? '-fill' : '' ?>"></i>
        <span>Inicio</span>
    </a>

    <a href="<?= APP_URL ?>/buscar.php"
       class="bottom-nav__item <?= basename($_SERVER['PHP_SELF']) === 'buscar.php' ? 'active' : '' ?>"
       aria-label="Buscar">
        <i class="bi bi-search"></i>
        <span>Buscar</span>
    </a>

    <?php if (!empty($livesActivos ?? [])): ?>
    <a href="<?= APP_URL ?>/live.php"
       class="bottom-nav__item bottom-nav__item--live <?= basename($_SERVER['PHP_SELF']) === 'live.php' ? 'active' : '' ?>"
       aria-label="En Vivo">
        <span class="live-dot"></span>
        <i class="bi bi-broadcast-pin"></i>
        <span>En Vivo</span>
    </a>
    <?php else: ?>
    <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($footerCats[0]['slug'] ?? '') ?>"
       class="bottom-nav__item"
       aria-label="Categorías">
        <i class="bi bi-grid-3x3-gap"></i>
        <span>Secciones</span>
    </a>
    <?php endif; ?>

    <?php if ($usuario): ?>
    <a href="<?= APP_URL ?>/perfil.php?tab=notificaciones"
       class="bottom-nav__item <?= basename($_SERVER['PHP_SELF']) === 'perfil.php' && ($_GET['tab'] ?? '') === 'notificaciones' ? 'active' : '' ?>"
       aria-label="Notificaciones">
        <div class="bottom-nav__notif-wrap">
            <i class="bi bi-bell<?= ($notifCount ?? 0) > 0 ? '-fill' : '' ?>"></i>
            <?php if (($notifCount ?? 0) > 0): ?>
            <span class="bottom-nav__badge">
                <?= ($notifCount ?? 0) > 9 ? '9+' : ($notifCount ?? 0) ?>
            </span>
            <?php endif; ?>
        </div>
        <span>Alertas</span>
    </a>
    <a href="<?= APP_URL ?>/perfil.php"
       class="bottom-nav__item <?= basename($_SERVER['PHP_SELF']) === 'perfil.php' && ($_GET['tab'] ?? '') !== 'notificaciones' ? 'active' : '' ?>"
       aria-label="Mi Perfil">
        <div class="bottom-nav__avatar-wrap">
            <img src="<?= e(getImageUrl($usuario['avatar'], 'avatar')) ?>"
                 alt="<?= e($usuario['nombre']) ?>"
                 width="24" height="24"
                 class="bottom-nav__avatar">
        </div>
        <span>Perfil</span>
    </a>
    <?php else: ?>
    <a href="<?= APP_URL ?>/login.php"
       class="bottom-nav__item <?= basename($_SERVER['PHP_SELF']) === 'login.php' ? 'active' : '' ?>"
       aria-label="Iniciar Sesión">
        <i class="bi bi-person-circle"></i>
        <span>Acceder</span>
    </a>
    <?php endif; ?>

</nav>

<!-- ============================================================
     BOTÓN VOLVER ARRIBA
     ============================================================ -->
<button class="back-to-top" id="backToTop"
        aria-label="Volver arriba" title="Volver arriba" hidden>
    <i class="bi bi-arrow-up-short"></i>
</button>

<!-- ============================================================
     PWA INSTALL PROMPT
     ============================================================ -->
<div class="pwa-prompt" id="pwaPrompt" hidden role="dialog"
     aria-labelledby="pwaPromptTitle" aria-modal="true">
    <div class="pwa-prompt__inner">
        <div class="pwa-prompt__icon">
            <?php if ($siteLogo): ?>
            <img src="<?= APP_URL ?>/<?= e(ltrim($siteLogo, '/')) ?>"
                 alt="<?= e($siteName) ?>" width="56" height="56">
            <?php else: ?>
            <div class="pwa-prompt__logo-text"><?= e(mb_substr($siteName, 0, 2)) ?></div>
            <?php endif; ?>
        </div>
        <div class="pwa-prompt__content">
            <h3 id="pwaPromptTitle">Instala <?= e($siteName) ?></h3>
            <p>Agrega la app a tu pantalla de inicio para acceso rápido sin abrir el navegador.</p>
        </div>
        <div class="pwa-prompt__actions">
            <button class="pwa-prompt__install" id="pwaInstallBtn">
                <i class="bi bi-download"></i> Instalar
            </button>
            <button class="pwa-prompt__dismiss" id="pwaDismissBtn"
                    aria-label="Cerrar">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL COOKIES (GDPR básico)
     ============================================================ -->
<div class="cookie-banner" id="cookieBanner" hidden role="dialog"
     aria-labelledby="cookieBannerTitle" aria-modal="true">
    <div class="cookie-banner__inner">
        <div class="cookie-banner__content">
            <i class="bi bi-cookie cookie-icon"></i>
            <div>
                <strong id="cookieBannerTitle">Usamos cookies</strong>
                <p>
                    Utilizamos cookies para mejorar tu experiencia, analizar el tráfico y
                    mostrar publicidad relevante.
                    <a href="#" class="cookie-banner__link">Política de Cookies</a>
                </p>
            </div>
        </div>
        <div class="cookie-banner__actions">
            <button class="cookie-btn cookie-btn--accept" id="cookieAccept">
                <i class="bi bi-check-circle-fill"></i> Aceptar
            </button>
            <button class="cookie-btn cookie-btn--reject" id="cookieReject">
                Solo necesarias
            </button>
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL GENÉRICO (reutilizable via JS)
     ============================================================ -->
<div class="modal-overlay"
     id="globalModal"
     style="display:none"
     role="dialog"
     aria-modal="true"
     aria-labelledby="globalModalTitle">
    <div class="modal-box" id="globalModalBox">
        <div class="modal-header">
            <h3 class="modal-title" id="globalModalTitle"></h3>
            <button class="modal-close"
                    id="globalModalClose"
                    onclick="document.getElementById('globalModal').style.display='none'"
                    aria-label="Cerrar">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="modal-body" id="globalModalBody"></div>
        <div class="modal-footer" id="globalModalFooter"></div>
    </div>
</div>

<!-- ============================================================
     TOAST CONTAINER
     ============================================================ -->
<div class="toast-container" id="toastContainer" aria-live="polite" aria-atomic="true"></div>

<!-- ============================================================
     SCRIPTS JS
     ============================================================ -->

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<!-- App JS principal -->
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>"></script>

<script>
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {

        // ── Newsletter ───────────────────────────────────────
        const nlForm = document.getElementById('newsletterForm');
        const nlMsg  = document.getElementById('newsletterMsg');

        nlForm?.addEventListener('submit', async function(e) {
            e.preventDefault();

            const btn     = this.querySelector('.newsletter-btn');
            const btnText = btn.querySelector('.btn-text');
            const spinner = btn.querySelector('.btn-spinner');
            const email   = this.querySelector('[name="email"]').value.trim();
            const nombre  = this.querySelector('[name="nombre"]')?.value.trim() ?? '';

            if (!email) return;

            btnText.style.display = 'none';
            spinner.removeAttribute('hidden');
            btn.disabled = true;

            try {
                const res  = await fetch(`${window.APP.url}/ajax/handler.php`, {
                    method:  'POST',
                    headers: {
                        'Content-Type':     'application/json',
                        'X-CSRF-Token':     window.APP.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        action: 'newsletter_subscribe',
                        email,
                        nombre,
                    }),
                });
                const data = await res.json();

                if (nlMsg) {
                    nlMsg.textContent  = data.message;
                    nlMsg.className    = 'newsletter-msg newsletter-msg--' +
                                         (data.success ? 'success' : 'error');
                    nlMsg.removeAttribute('hidden');
                }

                if (data.success) {
                    this.reset();
                    setTimeout(() => nlMsg?.setAttribute('hidden', ''), 5000);
                }
            } catch {
                if (nlMsg) {
                    nlMsg.textContent = 'Error de conexión. Intenta de nuevo.';
                    nlMsg.className   = 'newsletter-msg newsletter-msg--error';
                    nlMsg.removeAttribute('hidden');
                }
            } finally {
                btnText.style.display = '';
                spinner.setAttribute('hidden', '');
                btn.disabled = false;
            }
        });

        // ── Volver arriba ────────────────────────────────────
        const backBtn = document.getElementById('backToTop');

        window.addEventListener('scroll', () => {
            if (window.scrollY > 400) {
                backBtn?.removeAttribute('hidden');
            } else {
                backBtn?.setAttribute('hidden', '');
            }
        }, { passive: true });

        backBtn?.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        // ── Bottom nav ocultar al hacer scroll hacia abajo ───
        const bottomNav = document.getElementById('bottomNav');
        let lastScrollY = 0;

        window.addEventListener('scroll', () => {
            const current = window.scrollY;
            if (current > lastScrollY && current > 100) {
                bottomNav?.classList.add('hidden');
            } else {
                bottomNav?.classList.remove('hidden');
            }
            lastScrollY = current <= 0 ? 0 : current;
        }, { passive: true });

        // ── PWA Install Prompt ───────────────────────────────
        let deferredPrompt = null;

        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;

            // Mostrar prompt si no fue rechazado antes
            const dismissed = localStorage.getItem('pwa_dismissed');
            const installed = localStorage.getItem('pwa_installed');

            if (!dismissed && !installed) {
                setTimeout(() => {
                    document.getElementById('pwaPrompt')?.removeAttribute('hidden');
                }, 3000);
            }

            // Mostrar botón de instalar en footer
            document.querySelectorAll('[id^="pwaInstall"]').forEach(btn => {
                btn.removeAttribute('hidden');
            });
        });

        document.getElementById('pwaInstallBtn')?.addEventListener('click', async () => {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            if (outcome === 'accepted') {
                localStorage.setItem('pwa_installed', '1');
                PDApp?.showToast('¡App instalada correctamente!', 'success');
            }
            deferredPrompt = null;
            document.getElementById('pwaPrompt')?.setAttribute('hidden', '');
        });

        document.getElementById('pwaDismissBtn')?.addEventListener('click', () => {
            document.getElementById('pwaPrompt')?.setAttribute('hidden', '');
            localStorage.setItem('pwa_dismissed', '1');
        });

        document.querySelectorAll('[id^="pwaInstallFooter"]').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!deferredPrompt) return;
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                if (outcome === 'accepted') {
                    localStorage.setItem('pwa_installed', '1');
                    PDApp?.showToast('¡App instalada!', 'success');
                }
                deferredPrompt = null;
            });
        });

        window.addEventListener('appinstalled', () => {
            localStorage.setItem('pwa_installed', '1');
            document.getElementById('pwaPrompt')?.setAttribute('hidden', '');
        });

        // ── Cookies Banner ───────────────────────────────────
        const cookiesAccepted = localStorage.getItem('pd_cookies');
        if (!cookiesAccepted) {
            setTimeout(() => {
                document.getElementById('cookieBanner')?.removeAttribute('hidden');
            }, 2000);
        }

        document.getElementById('cookieAccept')?.addEventListener('click', () => {
            localStorage.setItem('pd_cookies', 'accepted');
            document.getElementById('cookieBanner')?.setAttribute('hidden', '');
        });

        document.getElementById('cookieReject')?.addEventListener('click', () => {
            localStorage.setItem('pd_cookies', 'necessary');
            document.getElementById('cookieBanner')?.setAttribute('hidden', '');
        });

        // ── Modal global ─────────────────────────────────────────────
        const globalModal     = document.getElementById('globalModal');
        const globalModalClose= document.getElementById('globalModalClose');
        
        globalModalClose?.addEventListener('click', () => {
            if (globalModal) globalModal.style.display = 'none';
        });
        
        globalModal?.addEventListener('click', (e) => {
            if (e.target === globalModal) {
                globalModal.style.display = 'none';
            }
        });
        
        // Cerrar con Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && globalModal?.style.display !== 'none') {
                globalModal.style.display = 'none';
            }
        });
        
        // Exponer funciones globales
        window.openModal = function(title, body, footer = '') {
            const titleEl  = document.getElementById('globalModalTitle');
            const bodyEl   = document.getElementById('globalModalBody');
            const footerEl = document.getElementById('globalModalFooter');
        
            if (titleEl)  titleEl.textContent = title;
            if (bodyEl)   bodyEl.innerHTML    = body;
            if (footerEl) footerEl.innerHTML  = footer;
        
            if (globalModal) {
                globalModal.style.display = 'flex';
                globalModal.focus?.();
            }
        };
        
        window.closeModal = function() {
            if (globalModal) globalModal.style.display = 'none';
        };

        // Exponer función global para abrir modal
        window.openModal = function(title, body, footer = '') {
            document.getElementById('globalModalTitle').textContent = title;
            document.getElementById('globalModalBody').innerHTML    = body;
            document.getElementById('globalModalFooter').innerHTML  = footer;
            globalModal?.removeAttribute('hidden');
            globalModal?.focus();
        };

        window.closeModal = function() {
            globalModal?.setAttribute('hidden', '');
        };

        // ── Analytics: Scroll tracking ───────────────────────
        if (window.APP?.analyticsScroll) {
            const noticiaId = document.body.dataset.noticiaId;
            if (noticiaId) {
                let maxScroll    = 0;
                let startTime    = Date.now();
                let tracked25    = false;
                let tracked50    = false;
                let tracked75    = false;
                let tracked100   = false;
                let sendScheduled = false;

                function calcScrollPercent() {
                    const el     = document.getElementById('article-content');
                    if (!el) return 0;
                    const rect   = el.getBoundingClientRect();
                    const vh     = window.innerHeight;
                    const total  = el.offsetHeight;
                    const seen   = Math.min(vh - rect.top, total);
                    return Math.round(Math.max(0, Math.min(100, (seen / total) * 100)));
                }

                function sendScrollData(pct) {
                    const tiempoSeg = Math.round((Date.now() - startTime) / 1000);
                    navigator.sendBeacon(
                        `${window.APP.url}/ajax/handler.php`,
                        JSON.stringify({
                            action:      'track_scroll',
                            noticia_id:  parseInt(noticiaId),
                            porcentaje:  pct,
                            tiempo_seg:  tiempoSeg,
                            csrf_token:  window.APP.csrfToken,
                        })
                    );
                }

                window.addEventListener('scroll', () => {
                    const pct = calcScrollPercent();
                    maxScroll = Math.max(maxScroll, pct);

                    if (!tracked25  && pct >= 25)  { tracked25  = true; sendScrollData(25);  }
                    if (!tracked50  && pct >= 50)  { tracked50  = true; sendScrollData(50);  }
                    if (!tracked75  && pct >= 75)  { tracked75  = true; sendScrollData(75);  }
                    if (!tracked100 && pct >= 100) { tracked100 = true; sendScrollData(100); }
                }, { passive: true });

                // Enviar datos al salir de la página
                window.addEventListener('beforeunload', () => {
                    if (maxScroll > 0) sendScrollData(maxScroll);
                });
            }
        }

        // ── Analytics: Heatmap de clics ──────────────────────
        if (window.APP?.analyticsHeatmap) {
            document.addEventListener('click', (e) => {
                const target   = e.target.closest('[data-track]') || e.target;
                const elemento = target.dataset?.track
                    || target.tagName.toLowerCase()
                    + (target.className ? '.' + target.className.split(' ')[0] : '');

                const noticiaId = document.body.dataset.noticiaId;

                fetch(`${window.APP.url}/ajax/handler.php`, {
                    method:  'POST',
                    headers: {
                        'Content-Type':     'application/json',
                        'X-CSRF-Token':     window.APP.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        action:     'track_heatmap',
                        pagina:     window.location.pathname,
                        elemento:   elemento.substring(0, 200),
                        x:          Math.round(e.clientX),
                        y:          Math.round(e.clientY),
                        noticia_id: noticiaId ? parseInt(noticiaId) : null,
                    }),
                }).catch(() => {});
            });
        }

        // ── Service Worker (PWA) ─────────────────────────────
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register(`${window.APP.url}/sw.js`)
                .then(reg => {
                    // Actualización disponible
                    reg.addEventListener('updatefound', () => {
                        const newWorker = reg.installing;
                        newWorker?.addEventListener('statechange', () => {
                            if (newWorker.state === 'installed' &&
                                navigator.serviceWorker.controller) {
                                PDApp?.showToast(
                                    '¡Nueva versión disponible! Recarga para actualizar.',
                                    'info',
                                    8000
                                );
                            }
                        });
                    });
                })
                .catch(() => {});
        }

        // ── Compartir via Web Share API nativa ───────────────
        window.nativeShare = async function(title, url) {
            if (navigator.share) {
                try {
                    await navigator.share({ title, url });
                } catch {}
            } else {
                await navigator.clipboard.writeText(url);
                PDApp?.showToast('Enlace copiado al portapapeles', 'success');
            }
        };

        // ── Tracking de clics en anuncios ────────────────────
        window.trackAdClick = function(adId) {
            fetch(`${window.APP.url}/ajax/handler.php`, {
                method:  'POST',
                headers: {
                    'Content-Type':     'application/json',
                    'X-CSRF-Token':     window.APP.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    action:    'track_ad_click',
                    anuncio_id: adId,
                }),
            }).catch(() => {});
        };

        // ── Text-To-Speech (TTS) ─────────────────────────────
        window.TTSPlayer = {
            utterance:  null,
            playing:    false,
            speed:      1.0,

            init(contentId) {
                const el = document.getElementById(contentId);
                if (!el || !window.speechSynthesis) return;
                this.text = el.innerText || el.textContent;
            },

            play() {
                if (!this.text) return;
                window.speechSynthesis.cancel();
                this.utterance            = new SpeechSynthesisUtterance(this.text);
                this.utterance.lang       = 'es-DO';
                this.utterance.rate       = this.speed;
                this.utterance.pitch      = 1;

                this.utterance.onstart  = () => this.onStateChange('playing');
                this.utterance.onend    = () => this.onStateChange('ended');
                this.utterance.onerror  = () => this.onStateChange('error');

                window.speechSynthesis.speak(this.utterance);
                this.playing = true;
            },

            pause() {
                if (this.playing) {
                    window.speechSynthesis.pause();
                    this.playing = false;
                    this.onStateChange('paused');
                } else {
                    window.speechSynthesis.resume();
                    this.playing = true;
                    this.onStateChange('playing');
                }
            },

            stop() {
                window.speechSynthesis.cancel();
                this.playing = false;
                this.onStateChange('stopped');
            },

            setSpeed(speed) {
                this.speed = parseFloat(speed);
                if (this.playing) {
                    this.stop();
                    this.play();
                }
            },

            onStateChange(state) {
                const btn   = document.getElementById('ttsPlayBtn');
                const btnMob= document.getElementById('ttsPlayBtnMob');

                [btn, btnMob].forEach(b => {
                    if (!b) return;
                    if (state === 'playing') {
                        b.innerHTML = '<i class="bi bi-pause-fill"></i> Pausar';
                        b.classList.add('playing');
                    } else {
                        b.innerHTML = '<i class="bi bi-play-fill"></i> Escuchar';
                        b.classList.remove('playing');
                    }
                });
            },
        };

    }); // DOMContentLoaded

})();
</script>

<?php
// ── Manifest.json dinámico ───────────────────────────────────
// Se sirve via manifest.php o endpoint en ajax/handler.php
?>

</body>
</html>