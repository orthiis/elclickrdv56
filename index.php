<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Página Principal (Home)
 * ============================================================
 * Archivo : index.php
 * Versión : 2.1.0  (espaciado corregido, móvil mejorado)
 * ============================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// ── Noticias destacadas para el hero (máx. 5) ────────────────
$noticiasHero    = getNoticiasDestacadas(5);
$noticiaHeroMain = !empty($noticiasHero) ? array_shift($noticiasHero) : null;

// ── Paginación de recientes ───────────────────────────────────
$paginaActual  = max(1, cleanInt($_GET['pagina'] ?? 1));
$totalNoticias = countNoticias();
$pagination    = paginate($totalNoticias, $paginaActual, NOTICIAS_POR_PAGINA);
$noticiasRecientes = getNoticiasRecientes(
    NOTICIAS_POR_PAGINA,
    $pagination['offset'],
    null,
    Config::get('contenido_orden', 'recientes')
);

// ── Noticias por categorías ───────────────────────────────────
$numCatsIndex      = Config::int('contenido_cats_en_index', 4);
$numNoticiasBloque = Config::int('contenido_noticias_bloque', 4);
$categorias        = getCategorias();
$noticiasXCat      = [];
foreach (array_slice($categorias, 0, $numCatsIndex) as $cat) {
    $noticias = getNoticiasRecientes($numNoticiasBloque, 0, (int)$cat['id']);
    if (!empty($noticias)) {
        $noticiasXCat[] = ['categoria' => $cat, 'noticias' => $noticias];
    }
}

// ── Trending ──────────────────────────────────────────────────
$trending = Config::bool('contenido_trending_activo') ? getNoticiasTrending(8) : [];

// ── Videos ───────────────────────────────────────────────────
$videosActivos  = Config::bool('contenido_videos_activo') ? getVideos(6, null, false) : [];
$videoDestacado = Config::bool('contenido_videos_activo') ? getVideos(1, null, true)  : [];
$videoDestacado = $videoDestacado[0] ?? null;

// ── Opinión ───────────────────────────────────────────────────
$opinionActiva   = Config::bool('contenido_opinion_activo');
$noticiasOpinion = $opinionActiva ? getNoticiasOpinion(3) : [];

// ── Misc ──────────────────────────────────────────────────────
$tagsPopulares     = getTagsPopulares(20);
$livesActivos      = getLiveBlogsActivos(2);
$noticiasPopulares = getNoticiasPopulares(5, 'semana');

// ── Precios de Combustibles (widget sidebar) ──────────────────
$combustiblesWidget  = getCombustiblesVigentes();
$combustiblesSemAct  = getCombustiblesSemanaActual();

// ── Usuario y favoritos ───────────────────────────────────────
$usuario      = currentUser();
$favoritosIds = [];
$recomendadas = [];
if ($usuario) {
    $favs         = db()->fetchAll(
        "SELECT noticia_id FROM favoritos WHERE usuario_id = ?",
        [$usuario['id']]
    );
    $favoritosIds = array_column($favs, 'noticia_id');
    $recomendadas = getNoticiasRecomendadas((int)$usuario['id'], 4);
}

// ── Anuncios ──────────────────────────────────────────────────
$anuncioHero    = getAnuncios('header',         null, 1);
$anuncioSidebar = getAnuncios('sidebar',        null, 1);
$anuncioMiddle  = getAnuncios('entre_noticias', null, 1);

// ── Podcasts recientes ────────────────────────────────────────
$podcastsRecientes = db()->fetchAll(
    "SELECT p.id, p.titulo, p.slug, p.descripcion, p.url_audio,
            p.thumbnail, p.duracion, p.temporada, p.episodio,
            p.reproducciones, p.fecha_publicacion,
            c.nombre AS cat_nombre, c.color AS cat_color,
            u.nombre AS autor_nombre
     FROM podcasts p
     LEFT JOIN categorias c ON c.id = p.categoria_id
     LEFT JOIN usuarios   u ON u.id = p.autor_id
     WHERE p.activo = 1
       AND (p.fecha_publicacion IS NULL OR p.fecha_publicacion <= NOW())
     ORDER BY p.fecha_creacion DESC
     LIMIT 5",
    []
);

// ── SEO ───────────────────────────────────────────────────────
$pageTitle       = Config::get('site_nombre', APP_NAME) . ' — ' .
                   Config::get('site_tagline', APP_TAGLINE);
$pageDescription = Config::get(
    'site_descripcion_seo',
    'Tu fuente de noticias digitales. Política, Economía, Tecnología, Deportes y más.'
);
$bodyClass = 'page-home';

require_once __DIR__ . '/includes/header.php';
?>

<!-- ============================================================
     ESTILOS ESPECÍFICOS DEL HOME (corrección móvil + espaciado)
============================================================ -->
<style>
/* ── Corrección de padding móvil ──────────────────────────── */
.container-fluid {
    padding-left:  max(16px, env(safe-area-inset-left));
    padding-right: max(16px, env(safe-area-inset-right));
}

/* ── Espaciado entre secciones ────────────────────────────── */
.home-section {
    margin-bottom: 3.5rem;   /* 56px entre secciones */
}
.home-section:last-child {
    margin-bottom: 0;
}

/* El section-header ya tiene border-bottom + margin-bottom,
   añadimos padding-top para separar del contenido anterior   */
.home-section .section-header {
    padding-top: 0;
    margin-bottom: var(--space-6);
}

/* ── Sección de anuncio entre hero y noticias ─────────────── */
.ad-between-news {
    margin-bottom: var(--space-8);
}

/* ── Sección de videos en el home ────────────────────────── */
.home-video-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: var(--space-5);
}
@media (max-width: 768px) {
    .home-video-grid { grid-template-columns: 1fr; }
    .home-video-playlist { display: none; }
}

/* ── Sección de podcasts en el home ──────────────────────── */
.home-podcast-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-6);
    align-items: start;
}
@media (max-width: 768px) {
    .home-podcast-grid { grid-template-columns: 1fr; }
}

.podcast-home-card {
    background: linear-gradient(135deg, var(--secondary), #2d1b69);
    border-radius: var(--border-radius-xl);
    padding: var(--space-5);
    display: flex;
    gap: var(--space-4);
    color: #fff;
}
.podcast-home-cover {
    width: 100px; height: 100px;
    border-radius: var(--border-radius-lg);
    object-fit: cover;
    flex-shrink: 0;
    box-shadow: var(--shadow-lg);
}
.podcast-home-info { flex: 1; min-width: 0; }
.podcast-home-ep {
    display: inline-block;
    background: rgba(255,255,255,.2);
    padding: 2px 8px;
    border-radius: var(--border-radius-full);
    font-size: .7rem;
    font-weight: 700;
    color: rgba(255,255,255,.9);
    margin-bottom: var(--space-2);
}
.podcast-home-title {
    font-family: var(--font-serif);
    font-size: var(--font-size-base);
    font-weight: 700;
    color: #fff;
    margin-bottom: var(--space-2);
    line-height: var(--line-height-snug);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.podcast-home-desc {
    font-size: var(--font-size-xs);
    color: rgba(255,255,255,.65);
    margin-bottom: var(--space-3);
    line-height: 1.5;
}
.podcast-home-controls { display: flex; align-items: center; gap: var(--space-3); }
.podcast-home-play-btn {
    width: 40px; height: 40px;
    border-radius: 50%;
    background: var(--primary);
    color: #fff; font-size: 1rem;
    display: flex; align-items: center; justify-content: center;
    transition: all var(--transition-fast);
    flex-shrink: 0;
}
.podcast-home-play-btn:hover { transform: scale(1.1); }
.podcast-home-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
}
.podcast-home-list-item {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-3);
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-lg);
    cursor: pointer;
    transition: all var(--transition-fast);
}
.podcast-home-list-item:hover {
    border-color: var(--primary);
    transform: translateX(4px);
    background: rgba(230,57,70,.03);
}
.podcast-home-list-thumb {
    width: 48px; height: 48px;
    border-radius: var(--border-radius);
    object-fit: cover;
    flex-shrink: 0;
}
.podcast-home-list-info { flex: 1; min-width: 0; }
.podcast-home-list-ep {
    font-size: .65rem;
    color: var(--primary);
    font-weight: 700;
}
.podcast-home-list-title {
    font-size: var(--font-size-sm);
    font-weight: 600;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.podcast-home-list-play {
    width: 30px; height: 30px;
    border-radius: 50%;
    background: var(--bg-surface-2);
    color: var(--primary);
    font-size: .75rem;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    transition: all var(--transition-fast);
}
.podcast-home-list-item:hover .podcast-home-list-play {
    background: var(--primary);
    color: #fff;
}
</style>

<!-- ============================================================
     HERO SECTION
============================================================ -->
<?php if ($noticiaHeroMain): ?>
<section class="hero-section" aria-label="Noticia principal">
    <div class="container-fluid px-3 px-lg-4">
        <div class="hero-grid">

            <!-- Noticia principal grande -->
            <article class="hero-main animate-on-scroll"
                     data-id="<?= (int)$noticiaHeroMain['id'] ?>">
                <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($noticiaHeroMain['slug']) ?>"
                   aria-label="<?= e($noticiaHeroMain['titulo']) ?>">
                    <img src="<?= e(getImageUrl($noticiaHeroMain['imagen'])) ?>"
                         alt="<?= e($noticiaHeroMain['titulo']) ?>"
                         class="hero-main__img"
                         loading="eager"
                         fetchpriority="high">
                </a>
                <div class="hero-main__overlay"></div>

                <!-- Badges -->
                <div class="hero-main__badges">
                    <?php if ($noticiaHeroMain['breaking']): ?>
                    <span class="news-card__badge badge-breaking">
                        <i class="bi bi-broadcast-pin"></i> Breaking
                    </span>
                    <?php endif; ?>
                    <?php if ($noticiaHeroMain['es_premium'] ?? false): ?>
                    <span class="news-card__badge badge-premium">
                        <i class="bi bi-star-fill"></i> Premium
                    </span>
                    <?php endif; ?>
                </div>

                <div class="hero-main__content">
                    <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($noticiaHeroMain['cat_slug']) ?>"
                       class="hero-main__cat"
                       style="background:<?= e($noticiaHeroMain['cat_color']) ?>">
                        <i class="bi <?= e($noticiaHeroMain['cat_icono'] ?? 'bi-tag') ?>"></i>
                        <?= e($noticiaHeroMain['cat_nombre']) ?>
                    </a>

                    <h1 class="hero-main__title">
                        <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($noticiaHeroMain['slug']) ?>">
                            <?= e($noticiaHeroMain['titulo']) ?>
                        </a>
                    </h1>

                    <div class="hero-main__meta">
                        <span>
                            <i class="bi bi-person"></i>
                            <?= e($noticiaHeroMain['autor_nombre']) ?>
                        </span>
                        <span>
                            <i class="bi bi-clock"></i>
                            <?= timeAgo($noticiaHeroMain['fecha_publicacion']) ?>
                        </span>
                        <span>
                            <i class="bi bi-eye"></i>
                            <?= formatNumber((int)$noticiaHeroMain['vistas']) ?>
                        </span>
                        <?php if (($noticiaHeroMain['tiempo_lectura'] ?? 0) > 0): ?>
                        <span>
                            <i class="bi bi-book"></i>
                            <?= (int)$noticiaHeroMain['tiempo_lectura'] ?> min
                        </span>
                        <?php endif; ?>

                        <!-- Favorito rápido -->
                        <?php if ($usuario): ?>
                        <button class="hero-fav-btn"
                                data-action="toggle-favorite"
                                data-noticia-id="<?= (int)$noticiaHeroMain['id'] ?>"
                                title="<?= in_array($noticiaHeroMain['id'], $favoritosIds) ? 'Quitar de guardados' : 'Guardar' ?>"
                                aria-label="Guardar noticia"
                                style="margin-left:auto;background:rgba(255,255,255,.15);
                                       border:1px solid rgba(255,255,255,.3);
                                       color:#fff;padding:6px 12px;border-radius:20px;
                                       display:flex;align-items:center;gap:6px;font-size:.8rem;">
                            <i class="bi <?= in_array($noticiaHeroMain['id'], $favoritosIds)
                                ? 'bi-bookmark-fill'
                                : 'bi-bookmark' ?>"></i>
                            <span class="d-none d-md-inline">Guardar</span>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </article>

            <!-- Noticias laterales del hero -->
            <?php if (!empty($noticiasHero)): ?>
            <div class="hero-side stagger-children">
                <?php foreach (array_slice($noticiasHero, 0, 4) as $hn): ?>
                <article class="hero-side-card animate-on-scroll"
                         data-titulo="<?= e($hn['titulo']) ?>"
                         data-vistas="<?= (int)$hn['vistas'] ?>"
                         data-tiempo="<?= (int)($hn['tiempo_lectura'] ?? 1) ?>">
                    <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($hn['slug']) ?>">
                        <img src="<?= e(getImageUrl($hn['imagen'])) ?>"
                             alt="<?= e($hn['titulo']) ?>"
                             class="hero-side-card__img"
                             loading="lazy">
                    </a>
                    <div class="hero-side-card__body">
                        <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($hn['cat_slug']) ?>"
                           class="hero-side-card__cat"
                           style="color:<?= e($hn['cat_color']) ?>">
                            <?= e($hn['cat_nombre']) ?>
                        </a>
                        <h3 class="hero-side-card__title">
                            <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($hn['slug']) ?>">
                                <?= e(truncateChars($hn['titulo'], 80)) ?>
                            </a>
                        </h3>
                        <span class="hero-side-card__time">
                            <i class="bi bi-clock"></i>
                            <?= timeAgo($hn['fecha_publicacion']) ?>
                        </span>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div><!-- /.hero-grid -->
    </div>
</section>
<?php endif; ?>

<!-- ============================================================
     LIVE BLOGS ACTIVOS (banner)
============================================================ -->
<?php if (!empty($livesActivos)): ?>
<div class="container-fluid px-3 px-lg-4 mt-4">
    <div class="live-blogs-banner">
        <?php foreach ($livesActivos as $live): ?>
        <a href="<?= APP_URL ?>/live.php?slug=<?= e($live['slug']) ?>"
           class="live-blog-banner-item">
            <span class="live-dot"></span>
            <span class="live-blog-banner__label">EN VIVO</span>
            <span class="live-blog-banner__title">
                <?= e(truncateChars($live['titulo'], 60)) ?>
            </span>
            <i class="bi bi-arrow-right-circle-fill"></i>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     CONTENIDO PRINCIPAL + SIDEBAR
============================================================ -->
<div class="container-fluid px-3 px-lg-4 mt-6">
    <div class="main-layout">

        <!-- ════════════════════════════════════════════════════
             COLUMNA PRINCIPAL
        ════════════════════════════════════════════════════ -->
        <div class="main-content">

            <!-- Anuncio entre hero y recientes -->
            <?php if (!empty($anuncioMiddle) && Config::bool('ads_activos_global') && Config::bool('ads_entre_noticias')): ?>
            <div class="ad-between-news animate-on-scroll">
                <?= renderAnuncio($anuncioMiddle[0]) ?>
            </div>
            <?php endif; ?>

            <!-- ── ÚLTIMAS NOTICIAS ───────────────────────── -->
            <section aria-labelledby="recientes-title"
                     class="home-section">
                <div class="section-header">
                    <h2 class="section-title" id="recientes-title">
                        <i class="bi bi-newspaper"
                           style="color:var(--primary);margin-right:8px"></i>
                        Últimas Noticias
                    </h2>
                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                        <!-- Filtro de orden -->
                        <select id="ordenSelect"
                                class="form-select"
                                style="width:auto;padding:6px 28px 6px 10px;font-size:.8rem"
                                onchange="changeOrden(this.value)">
                            <option value="recientes"
                                <?= Config::get('contenido_orden') === 'recientes' ? 'selected' : '' ?>>
                                Más recientes
                            </option>
                            <option value="populares"
                                <?= Config::get('contenido_orden') === 'populares' ? 'selected' : '' ?>>
                                Más populares
                            </option>
                        </select>
                        <a href="<?= APP_URL ?>/buscar.php" class="section-link">
                            Ver todo <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>

                <!-- Grid de noticias (scroll infinito) -->
                <div class="news-grid news-grid--3col stagger-children"
                     id="newsGrid"
                     data-page="home"
                     data-orden="<?= e(Config::get('contenido_orden', 'recientes')) ?>">

                    <?php
                    $adsFreq = Config::int('ads_frecuencia', 3);
                    $counter = 0;
                    if (!empty($noticiasRecientes)):
                        foreach ($noticiasRecientes as $noticia):
                            $counter++;
                            $isFav = in_array($noticia['id'], $favoritosIds);
                    ?>
                    <article class="news-card animate-on-scroll"
                             data-id="<?= (int)$noticia['id'] ?>"
                             data-titulo="<?= e($noticia['titulo']) ?>"
                             data-resumen="<?= e(truncateChars($noticia['resumen'] ?? '', 100)) ?>"
                             data-vistas="<?= (int)$noticia['vistas'] ?>"
                             data-tiempo="<?= (int)($noticia['tiempo_lectura'] ?? 1) ?>"
                             data-preview>

                        <!-- Imagen -->
                        <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($noticia['slug']) ?>"
                           class="news-card__img-wrap"
                           tabindex="-1" aria-hidden="true">
                            <img data-src="<?= e(getImageUrl($noticia['imagen'])) ?>"
                                 src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 9'%3E%3C/svg%3E"
                                 alt="<?= e($noticia['titulo']) ?>"
                                 class="news-card__img"
                                 loading="lazy">

                            <?php if ($noticia['breaking']): ?>
                            <span class="news-card__badge badge-breaking">
                                <i class="bi bi-broadcast-pin"></i> Breaking
                            </span>
                            <?php endif; ?>
                            <?php if ($noticia['es_premium'] ?? false): ?>
                            <span class="news-card__badge badge-premium"
                                  style="top:auto;bottom:8px">
                                <i class="bi bi-star-fill"></i> Premium
                            </span>
                            <?php endif; ?>
                        </a>

                        <div class="news-card__body">
                            <!-- Categoría -->
                            <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($noticia['cat_slug'] ?? '') ?>"
                               class="news-card__cat"
                               style="color:<?= e($noticia['cat_color']) ?>">
                                <?= e($noticia['cat_nombre']) ?>
                            </a>

                            <!-- Título -->
                            <h3 class="news-card__title">
                                <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($noticia['slug']) ?>">
                                    <?= e(truncateChars($noticia['titulo'], 80)) ?>
                                </a>
                            </h3>

                            <!-- Meta -->
                            <div class="news-card__meta">
                                <span>
                                    <i class="bi bi-clock"></i>
                                    <?= timeAgo($noticia['fecha_publicacion']) ?>
                                </span>
                                <span>
                                    <i class="bi bi-eye"></i>
                                    <?= formatNumber((int)$noticia['vistas']) ?>
                                </span>
                                <?php if (($noticia['tiempo_lectura'] ?? 0) > 0): ?>
                                <span>
                                    <i class="bi bi-book"></i>
                                    <?= (int)$noticia['tiempo_lectura'] ?> min
                                </span>
                                <?php endif; ?>
                                <?php if (($noticia['total_comentarios'] ?? 0) > 0): ?>
                                <span>
                                    <i class="bi bi-chat-dots"></i>
                                    <?= (int)$noticia['total_comentarios'] ?>
                                </span>
                                <?php endif; ?>

                                <!-- Guardar -->
                                <?php if ($usuario): ?>
                                <button class="news-card__save"
                                        data-action="toggle-favorite"
                                        data-noticia-id="<?= (int)$noticia['id'] ?>"
                                        title="<?= $isFav ? 'Quitar de guardados' : 'Guardar' ?>"
                                        style="margin-left:auto;background:none;border:none;
                                               color:<?= $isFav ? 'var(--primary)' : 'var(--text-muted)' ?>;
                                               font-size:.9rem;cursor:pointer;
                                               padding:2px 4px;transition:color .2s">
                                    <i class="bi <?= $isFav ? 'bi-bookmark-fill' : 'bi-bookmark' ?>"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>

                    <?php
                    // Anuncio inline cada N noticias
                    if (!empty($anuncioMiddle)
                        && Config::bool('ads_activos_global')
                        && Config::bool('ads_entre_noticias')
                        && $counter % $adsFreq === 0
                    ):
                    ?>
                    <div class="news-card"
                         style="border:2px dashed var(--border-color);
                                justify-content:center;align-items:center;
                                min-height:200px;background:var(--bg-surface-2);
                                grid-column:1/-1">
                        <?= renderAnuncio($anuncioMiddle[0]) ?>
                    </div>
                    <?php endif; ?>

                    <?php endforeach; ?>
                    <?php else: ?>
                    <div style="grid-column:1/-1;text-align:center;padding:60px 20px;
                                color:var(--text-muted)">
                        <i class="bi bi-newspaper" style="font-size:3rem;opacity:.3"></i>
                        <p style="margin-top:16px">No hay noticias publicadas todavía.</p>
                    </div>
                    <?php endif; ?>

                </div><!-- /#newsGrid -->

                <!-- Loader para scroll infinito -->
                <div class="infinite-loader" id="infiniteLoader" style="display:none">
                    <div class="loader-spinner"></div>
                    <span>Cargando más noticias...</span>
                </div>

                <!-- Botón manual de carga -->
                <?php if ($pagination['has_next']): ?>
                <div style="text-align:center;margin-top:var(--space-8)">
                    <button class="btn-load-more"
                            id="loadMoreBtn"
                            style="display:inline-flex;align-items:center;gap:8px;
                                   padding:12px 32px;background:var(--bg-surface);
                                   border:2px solid var(--primary);color:var(--primary);
                                   border-radius:var(--border-radius-full);font-size:.9rem;
                                   font-weight:600;cursor:pointer;transition:all .2s ease">
                        <i class="bi bi-arrow-down-circle"></i>
                        Cargar más noticias
                    </button>
                </div>
                <?php else: ?>
                <p style="text-align:center;color:var(--text-muted);
                           font-size:.85rem;margin-top:var(--space-8);
                           padding:var(--space-4);border-top:1px solid var(--border-color)">
                    <i class="bi bi-check-circle-fill" style="color:var(--success)"></i>
                    Has visto todas las noticias disponibles
                </p>
                <?php endif; ?>

            </section>

            <!-- ── NOTICIAS POR CATEGORÍAS ────────────────── -->
            <?php foreach ($noticiasXCat as $bloque): ?>
            <section aria-labelledby="cat-<?= e($bloque['categoria']['slug']) ?>"
                     class="home-section animate-on-scroll">
                <div class="section-header"
                     style="border-bottom-color:<?= e($bloque['categoria']['color']) ?>">
                    <h2 class="section-title"
                        id="cat-<?= e($bloque['categoria']['slug']) ?>"
                        style="--cat-color:<?= e($bloque['categoria']['color']) ?>">
                        <span style="display:inline-block;width:4px;height:24px;
                                     background:<?= e($bloque['categoria']['color']) ?>;
                                     border-radius:2px;margin-right:10px;
                                     vertical-align:middle"></span>
                        <i class="bi <?= e($bloque['categoria']['icono'] ?? 'bi-tag') ?>"
                           style="color:<?= e($bloque['categoria']['color']) ?>;
                                  margin-right:6px"></i>
                        <?= e($bloque['categoria']['nombre']) ?>
                    </h2>
                    <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($bloque['categoria']['slug']) ?>"
                       class="section-link"
                       style="color:<?= e($bloque['categoria']['color']) ?>">
                        Ver todo <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

                <div class="news-grid news-grid--<?= min(4, count($bloque['noticias'])) ?>col stagger-children">
                    <?php foreach ($bloque['noticias'] as $n): ?>
                    <article class="news-card animate-on-scroll"
                             data-id="<?= (int)$n['id'] ?>"
                             data-titulo="<?= e($n['titulo']) ?>"
                             data-vistas="<?= (int)$n['vistas'] ?>"
                             data-tiempo="<?= (int)($n['tiempo_lectura'] ?? 1) ?>"
                             data-preview>
                        <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($n['slug']) ?>"
                           class="news-card__img-wrap" tabindex="-1">
                            <img data-src="<?= e(getImageUrl($n['imagen'])) ?>"
                                 src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 9'%3E%3C/svg%3E"
                                 alt="<?= e($n['titulo']) ?>"
                                 class="news-card__img"
                                 loading="lazy">
                        </a>
                        <div class="news-card__body">
                            <h3 class="news-card__title">
                                <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($n['slug']) ?>">
                                    <?= e(truncateChars($n['titulo'], 80)) ?>
                                </a>
                            </h3>
                            <div class="news-card__meta">
                                <span>
                                    <i class="bi bi-clock"></i>
                                    <?= timeAgo($n['fecha_publicacion']) ?>
                                </span>
                                <span>
                                    <i class="bi bi-eye"></i>
                                    <?= formatNumber((int)$n['vistas']) ?>
                                </span>
                                <?php if (($n['tiempo_lectura'] ?? 0) > 0): ?>
                                <span>
                                    <i class="bi bi-book"></i>
                                    <?= (int)$n['tiempo_lectura'] ?> min
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endforeach; ?>

            <!-- ── SECCIÓN DE VIDEOS ──────────────────────── -->
            <?php if (Config::bool('contenido_videos_activo') && !empty($videosActivos)): ?>
            <section aria-labelledby="videos-title"
                     class="home-section video-section animate-on-scroll">
                <div class="section-header">
                    <h2 class="section-title" id="videos-title">
                        <i class="bi bi-play-circle-fill"
                           style="color:var(--primary);margin-right:8px"></i>
                        Videos
                    </h2>
                    <a href="<?= APP_URL ?>/videos.php" class="section-link">
                        Ver todos <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

                <?php if ($videoDestacado): ?>
                <div class="home-video-grid">
                    <!-- Player principal -->
                    <div>
                        <div class="video-player-wrap" id="mainVideoPlayer">
                            <?php $embedUrl = getVideoEmbedUrl($videoDestacado); ?>
                            <?php if ($videoDestacado['tipo'] === 'mp4'): ?>
                            <video src="<?= e($embedUrl) ?>"
                                   controls
                                   poster="<?= e(getImageUrl($videoDestacado['thumbnail'] ?? '')) ?>"
                                   style="width:100%;height:100%">
                                Tu navegador no soporta video HTML5.
                            </video>
                            <?php else: ?>
                            <iframe src="<?= e($embedUrl) ?>"
                                    title="<?= e($videoDestacado['titulo']) ?>"
                                    frameborder="0"
                                    allow="accelerometer; autoplay; clipboard-write;
                                           encrypted-media; gyroscope; picture-in-picture"
                                    allowfullscreen
                                    loading="lazy"
                                    style="width:100%;height:100%">
                            </iframe>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top:12px">
                            <h3 style="font-family:var(--font-serif);font-size:1.1rem;
                                       font-weight:700;color:var(--text-primary)"
                                id="currentVideoTitle">
                                <?= e($videoDestacado['titulo']) ?>
                            </h3>
                            <?php if ($videoDestacado['descripcion']): ?>
                            <p style="font-size:.85rem;color:var(--text-muted);margin-top:6px">
                                <?= e(truncateChars($videoDestacado['descripcion'], 120)) ?>
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Playlist lateral -->
                    <div class="video-playlist home-video-playlist" id="videoPlaylist">
                        <?php foreach ($videosActivos as $vid): ?>
                        <?php
                        $vThumb = !empty($vid['thumbnail'])
                            ? getImageUrl($vid['thumbnail'])
                            : ($vid['tipo'] === 'youtube'
                                ? 'https://img.youtube.com/vi/' . getYoutubeId($vid['url'] ?? '') . '/mqdefault.jpg'
                                : APP_URL . '/assets/images/default-news.jpg');
                        ?>
                        <div class="video-playlist-item <?= ($vid['id'] ?? 0) === ($videoDestacado['id'] ?? null) ? 'active' : '' ?>"
                             data-embed-url="<?= e(getVideoEmbedUrl($vid)) ?>"
                             data-tipo="<?= e($vid['tipo']) ?>"
                             data-titulo="<?= e($vid['titulo']) ?>">
                            <div class="video-playlist-thumb">
                                <img src="<?= e($vThumb) ?>"
                                     alt="<?= e($vid['titulo']) ?>"
                                     loading="lazy"
                                     style="width:100%;height:100%;object-fit:cover;
                                            border-radius:var(--border-radius-sm)">
                                <span class="video-play-icon-sm">
                                    <i class="bi bi-play-fill"></i>
                                </span>
                            </div>
                            <div class="video-playlist-info">
                                <p class="video-playlist-title">
                                    <?= e(truncateChars($vid['titulo'], 60)) ?>
                                </p>
                                <?php if (!empty($vid['cat_nombre'])): ?>
                                <span class="video-playlist-duration"
                                      style="color:<?= e($vid['cat_color'] ?? 'var(--text-muted)') ?>">
                                    <?= e($vid['cat_nombre']) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <!-- ── SECCIÓN DE PODCASTS ────────────────────── -->
            <?php if (Config::bool('contenido_podcasts_homepage') && !empty($podcastsRecientes)): ?>
            <section aria-labelledby="podcasts-title"
                     class="home-section podcast-section animate-on-scroll">
                <div class="section-header">
                    <h2 class="section-title" id="podcasts-title">
                        <i class="bi bi-mic-fill"
                           style="color:#7c3aed;margin-right:8px"></i>
                        Podcasts
                    </h2>
                    <a href="<?= APP_URL ?>/podcasts.php" class="section-link">
                        Ver todos <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

                <?php $podPrincipal = $podcastsRecientes[0]; ?>
                <div class="home-podcast-grid">

                    <!-- Player principal del podcast -->
                    <div>
                        <?php
                        $podThumbMain = !empty($podPrincipal['thumbnail'])
                            ? (str_starts_with($podPrincipal['thumbnail'], 'http')
                                ? $podPrincipal['thumbnail']
                                : APP_URL . '/' . $podPrincipal['thumbnail'])
                            : APP_URL . '/assets/images/default-news.jpg';
                        ?>
                        <div class="podcast-home-card">
                            <img src="<?= e($podThumbMain) ?>"
                                 alt="<?= e($podPrincipal['titulo']) ?>"
                                 class="podcast-home-cover"
                                 id="mainPodcastCover">
                            <div class="podcast-home-info">
                                <span class="podcast-home-ep">
                                    T<?= (int)$podPrincipal['temporada'] ?> · EP<?= (int)$podPrincipal['episodio'] ?>
                                </span>
                                <h3 class="podcast-home-title" id="mainPodcastTitle">
                                    <?= e($podPrincipal['titulo']) ?>
                                </h3>
                                <?php if (!empty($podPrincipal['descripcion'])): ?>
                                <p class="podcast-home-desc">
                                    <?= e(truncateChars($podPrincipal['descripcion'], 100)) ?>
                                </p>
                                <?php endif; ?>
                                <div class="podcast-home-controls">
                                    <button class="podcast-home-play-btn"
                                            id="mainPodcastPlayBtn"
                                            onclick="homePodcastToggle()"
                                            aria-label="Reproducir">
                                        <i class="bi bi-play-fill"
                                           id="mainPodcastIcon"></i>
                                    </button>
                                    <a href="<?= APP_URL ?>/podcasts.php"
                                       style="display:flex;align-items:center;gap:6px;
                                              padding:6px 14px;border-radius:var(--border-radius-full);
                                              border:1px solid rgba(255,255,255,.25);
                                              color:rgba(255,255,255,.8);font-size:.78rem;
                                              font-weight:600;transition:all .2s"
                                       onmouseover="this.style.background='rgba(255,255,255,.15)'"
                                       onmouseout="this.style.background='transparent'">
                                        <i class="bi bi-collection-play"></i> Ver todos
                                    </a>
                                </div>
                                <audio id="mainPodcastAudio" preload="none">
                                    <source src="<?= e($podPrincipal['url_audio']) ?>"
                                            type="audio/mpeg">
                                </audio>
                            </div>
                        </div>
                    </div>

                    <!-- Lista de episodios -->
                    <div class="podcast-home-list">
                        <?php foreach (array_slice($podcastsRecientes, 1) as $pod):
                            $podT = !empty($pod['thumbnail'])
                                ? (str_starts_with($pod['thumbnail'], 'http')
                                    ? $pod['thumbnail']
                                    : APP_URL . '/' . $pod['thumbnail'])
                                : APP_URL . '/assets/images/default-news.jpg';
                        ?>
                        <div class="podcast-home-list-item"
                             data-audio="<?= e($pod['url_audio']) ?>"
                             data-titulo="<?= e($pod['titulo']) ?>"
                             data-thumb="<?= e($podT) ?>"
                             onclick="homePodcastSwitch(this)">
                            <img src="<?= e($podT) ?>"
                                 alt="<?= e($pod['titulo']) ?>"
                                 class="podcast-home-list-thumb">
                            <div class="podcast-home-list-info">
                                <div class="podcast-home-list-ep">
                                    T<?= (int)$pod['temporada'] ?> · EP<?= (int)$pod['episodio'] ?>
                                </div>
                                <div class="podcast-home-list-title">
                                    <?= e(truncateChars($pod['titulo'], 55)) ?>
                                </div>
                                <div style="font-size:.68rem;color:var(--text-muted)">
                                    <?= !empty($pod['fecha_publicacion'])
                                        ? date('d/m/Y', strtotime($pod['fecha_publicacion']))
                                        : '' ?>
                                </div>
                            </div>
                            <button class="podcast-home-list-play"
                                    aria-label="Reproducir">
                                <i class="bi bi-play-fill"></i>
                            </button>
                        </div>
                        <?php endforeach; ?>
                        <a href="<?= APP_URL ?>/podcasts.php"
                           style="display:flex;align-items:center;justify-content:center;
                                  gap:6px;margin-top:6px;padding:10px;
                                  border-radius:var(--border-radius-lg);
                                  border:1px dashed var(--border-color);font-size:.8rem;
                                  color:var(--text-muted);text-decoration:none;
                                  transition:all var(--transition-fast)"
                           onmouseover="this.style.borderColor='#7c3aed';this.style.color='#7c3aed'"
                           onmouseout="this.style.borderColor='var(--border-color)';this.style.color='var(--text-muted)'">
                            <i class="bi bi-grid"></i> Ver todos los episodios
                        </a>
                    </div>

                </div>
            </section>
            <?php endif; ?>

            <!-- ── SECCIÓN DE OPINIÓN ─────────────────────── -->
            <?php if ($opinionActiva && !empty($noticiasOpinion)): ?>
            <section aria-labelledby="opinion-title"
                     class="home-section animate-on-scroll">
                <div class="section-header">
                    <h2 class="section-title" id="opinion-title">
                        <i class="bi bi-chat-quote-fill"
                           style="color:var(--accent);margin-right:8px"></i>
                        Opinión
                    </h2>
                    <a href="<?= APP_URL ?>/categoria.php?slug=opinion"
                       class="section-link">
                        Ver todo <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));
                             gap:var(--space-5)">
                    <?php foreach ($noticiasOpinion as $op): ?>
                    <article class="news-card animate-on-scroll"
                             style="border-top:4px solid var(--accent)">
                        <div class="news-card__body" style="padding-top:var(--space-5)">
                            <!-- Autor de opinión -->
                            <div style="display:flex;align-items:center;gap:12px;
                                        margin-bottom:12px;padding-bottom:12px;
                                        border-bottom:1px solid var(--border-color)">
                                <img src="<?= e(getImageUrl($op['autor_avatar'] ?? '')) ?>"
                                     alt="<?= e($op['autor_nombre']) ?>"
                                     style="width:40px;height:40px;border-radius:50%;
                                            object-fit:cover;border:2px solid var(--accent)"
                                     onerror="this.src='<?= APP_URL ?>/assets/images/default-avatar.png'">
                                <div>
                                    <div style="font-size:.82rem;font-weight:700;
                                                color:var(--accent)">
                                        <?= e($op['autor_nombre']) ?>
                                    </div>
                                    <div style="font-size:.7rem;color:var(--text-muted)">
                                        <?= timeAgo($op['fecha_publicacion']) ?>
                                    </div>
                                </div>
                            </div>
                            <h3 class="news-card__title">
                                <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($op['slug']) ?>">
                                    <?= e(truncateChars($op['titulo'], 100)) ?>
                                </a>
                            </h3>
                            <?php if (!empty($op['resumen'])): ?>
                            <p style="font-size:.82rem;color:var(--text-muted);
                                       margin-top:var(--space-2);line-height:1.5">
                                <?= e(truncateChars($op['resumen'], 100)) ?>
                            </p>
                            <?php endif; ?>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <!-- ── NOTICIAS RECOMENDADAS ──────────────────── -->
            <?php if ($usuario && !empty($recomendadas)): ?>
            <section aria-labelledby="recomendadas-title"
                     class="home-section animate-on-scroll">
                <div class="section-header">
                    <h2 class="section-title" id="recomendadas-title">
                        <i class="bi bi-stars"
                           style="color:var(--warning);margin-right:8px"></i>
                        Para Ti
                    </h2>
                    <a href="<?= APP_URL ?>/perfil.php?tab=siguiendo"
                       class="section-link">
                        Ver más <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
                <div class="news-grid news-grid--4col stagger-children">
                    <?php foreach ($recomendadas as $rec): ?>
                    <article class="news-card animate-on-scroll"
                             data-id="<?= (int)$rec['id'] ?>">
                        <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($rec['slug']) ?>"
                           class="news-card__img-wrap" tabindex="-1">
                            <img data-src="<?= e(getImageUrl($rec['imagen'])) ?>"
                                 src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 9'%3E%3C/svg%3E"
                                 alt="<?= e($rec['titulo']) ?>"
                                 class="news-card__img"
                                 loading="lazy">
                        </a>
                        <div class="news-card__body">
                            <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($rec['cat_slug'] ?? '') ?>"
                               class="news-card__cat"
                               style="color:<?= e($rec['cat_color']) ?>">
                                <?= e($rec['cat_nombre']) ?>
                            </a>
                            <h3 class="news-card__title">
                                <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($rec['slug']) ?>">
                                    <?= e(truncateChars($rec['titulo'], 75)) ?>
                                </a>
                            </h3>
                            <div class="news-card__meta">
                                <span>
                                    <i class="bi bi-clock"></i>
                                    <?= timeAgo($rec['fecha_publicacion']) ?>
                                </span>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

        </div><!-- /.main-content -->

        <!-- ════════════════════════════════════════════════════
             SIDEBAR
        ════════════════════════════════════════════════════ -->
        <aside class="sidebar" role="complementary"
               aria-label="Contenido adicional">

            <!-- Widget: Anuncio sidebar -->
            <?php if (!empty($anuncioSidebar) && Config::bool('ads_activos_global') && Config::bool('ads_sidebar')): ?>
            <div class="widget animate-on-scroll">
                <?= renderAnuncio($anuncioSidebar[0]) ?>
            </div>
            <?php endif; ?>

            <!-- Widget: Clima -->
            <?php
            $climaData = getClima();
            if ($climaData):
            ?>
            <div class="widget widget-clima animate-on-scroll">
                <div class="widget__header">
                    <h3 class="widget__title">
                        <i class="bi bi-cloud-sun-fill"></i>
                        El Tiempo
                    </h3>
                </div>
                <div class="widget__body" style="text-align:center">
                    <img src="<?= e($climaData['icono_url']) ?>"
                         alt="<?= e($climaData['descripcion']) ?>"
                         width="64" height="64"
                         style="margin:0 auto 8px">
                    <div class="clima-temp">
                        <?= (int)$climaData['temp'] ?>°<?= e($climaData['unidad']) ?>
                    </div>
                    <div class="clima-desc"><?= e($climaData['descripcion']) ?></div>
                    <div style="font-size:.8rem;color:rgba(255,255,255,.7);margin-top:4px">
                        <?= e($climaData['ciudad']) ?>, <?= e($climaData['pais']) ?>
                    </div>
                    <div class="clima-details">
                        <div class="clima-detail">
                            <i class="bi bi-droplet-fill"></i>
                            <span><?= (int)$climaData['humedad'] ?>%</span>
                            <small>Humedad</small>
                        </div>
                        <div class="clima-detail">
                            <i class="bi bi-wind"></i>
                            <span><?= (int)$climaData['viento'] ?> m/s</span>
                            <small>Viento</small>
                        </div>
                        <div class="clima-detail">
                            <i class="bi bi-thermometer-half"></i>
                            <span><?= (int)$climaData['sensacion'] ?>°</span>
                            <small>Sensación</small>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- ═══════════════════════════════════════════════
                 Widget: Precio de Combustibles
                 Posición: entre "El Tiempo" y "Trending Ahora"
            ═══════════════════════════════════════════════ -->
            <?php if (!empty($combustiblesWidget)): ?>
            <div class="widget animate-on-scroll" id="widget-combustibles">
                <div class="widget__header"
                     style="background:linear-gradient(135deg,#0f2027,#203a43,#2c5364);
                            border-bottom:3px solid #f59e0b;">
                    <h3 class="widget__title" style="color:#fff;">
                        <i class="bi bi-fuel-pump-fill"
                           style="color:#f59e0b"></i>
                        Precio Combustibles
                    </h3>
                    <?php if ($combustiblesSemAct): ?>
                    <span style="font-size:.64rem;color:rgba(255,255,255,.55);
                                 display:block;margin-top:2px;
                                 padding-left:26px">
                        <?= formatFechaEsp($combustiblesSemAct['fecha_vigencia'], 'medio') ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="widget__body" style="padding:0">

                    <!-- Lista de precios compacta -->
                    <ul style="list-style:none;margin:0;padding:0">
                    <?php foreach ($combustiblesWidget as $i => $cb):
                        $subio  = ($cb['variacion'] ?? 0) > 0;
                        $bajo   = ($cb['variacion'] ?? 0) < 0;
                        $igual  = ($cb['variacion'] ?? 0) == 0;
                        $varAbs = abs((float)($cb['variacion'] ?? 0));
                        $varPct = abs((float)($cb['variacion_pct'] ?? 0));

                        // Nombre corto
                        $nombreCorto = match($cb['tipo_slug'] ?? '') {
                            'gasolina-premium' => 'Gasolina Premium',
                            'gasolina-regular' => 'Gasolina Regular',
                            'gasoil-optimo'    => 'Gasoil Óptimo',
                            'gasoil-regular'   => 'Gasoil Regular',
                            'kerosene'         => 'Kerosene',
                            'glp'              => 'GLP',
                            'gnv'              => 'Gas Natural',
                            default            => truncateChars($cb['nombre'], 14),
                        };
                    ?>
                    <li style="display:flex;align-items:center;
                                justify-content:space-between;
                                padding:9px 14px;
                                border-bottom:1px solid var(--border-color);
                                gap:6px;
                                transition:background .15s ease"
                        onmouseover="this.style.background='var(--bg-surface-2)'"
                        onmouseout="this.style.background='transparent'">

                        <!-- Icono + Nombre -->
                        <div style="display:flex;align-items:center;
                                    gap:7px;flex:1;min-width:0">
                            <span style="width:26px;height:26px;
                                         border-radius:6px;flex-shrink:0;
                                         background:<?= e($cb['color']) ?>20;
                                         border:1px solid <?= e($cb['color']) ?>40;
                                         display:flex;align-items:center;
                                         justify-content:center">
                                <i class="bi <?= e($cb['icono']) ?>"
                                   style="font-size:.78rem;
                                          color:<?= e($cb['color']) ?>"></i>
                            </span>
                            <span style="font-size:.775rem;
                                         font-weight:600;
                                         color:var(--text-primary);
                                         white-space:nowrap;
                                         overflow:hidden;
                                         text-overflow:ellipsis">
                                <?= e($nombreCorto) ?>
                            </span>
                        </div>

                        <!-- Precio + Variación -->
                        <div style="text-align:right;flex-shrink:0">
                            <div style="font-size:.82rem;
                                         font-weight:700;
                                         color:var(--text-primary);
                                         font-variant-numeric:tabular-nums">
                                RD$ <?= number_format((float)$cb['precio'], 2) ?>
                            </div>
                            <div style="font-size:.62rem;
                                         font-weight:600;
                                         margin-top:1px;
                                         <?php if ($subio): ?>color:#ef4444<?php elseif ($bajo): ?>color:#22c55e<?php else: ?>color:var(--text-muted)<?php endif; ?>">
                                <?php if ($subio): ?>
                                    <i class="bi bi-arrow-up-short"></i>+<?= number_format($varAbs, 2) ?>
                                    <span style="opacity:.75">(<?= number_format($varPct, 2) ?>%)</span>
                                <?php elseif ($bajo): ?>
                                    <i class="bi bi-arrow-down-short"></i>-<?= number_format($varAbs, 2) ?>
                                    <span style="opacity:.75">(<?= number_format($varPct, 2) ?>%)</span>
                                <?php else: ?>
                                    <i class="bi bi-dash"></i> Sin cambio
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                    </ul>

                    <!-- Pie del widget -->
                    <div style="padding:10px 14px;
                                background:var(--bg-surface-2);
                                border-top:1px solid var(--border-color)">
                        <a href="<?= APP_URL ?>/combustibles.php"
                           style="display:flex;align-items:center;
                                  justify-content:center;gap:6px;
                                  font-size:.75rem;font-weight:700;
                                  color:var(--primary);
                                  text-decoration:none;
                                  transition:opacity .15s ease"
                           onmouseover="this.style.opacity='.75'"
                           onmouseout="this.style.opacity='1'">
                            <i class="bi bi-clock-history"></i>
                            Ver histórico completo
                            <i class="bi bi-arrow-right" style="font-size:.65rem"></i>
                        </a>
                    </div>

                </div><!-- /.widget__body -->
            </div><!-- /#widget-combustibles -->
            <?php endif; ?>

            <!-- Widget: Trending / Lo más visto -->
            <?php if (!empty($trending)): ?>
            <div class="widget animate-on-scroll">
                <div class="widget__header">
                    <h3 class="widget__title">
                        <i class="bi bi-graph-up-arrow"
                           style="color:var(--primary)"></i>
                        Trending Ahora
                    </h3>
                </div>
                <div class="widget__body" style="padding:0 var(--space-4)">
                    <?php foreach (array_slice($trending, 0, 5) as $idx => $trend): ?>
                    <div class="trending-card">
                        <span class="trending-number"><?= $idx + 1 ?></span>
                        <?php if ($trend['imagen']): ?>
                        <img src="<?= e(getImageUrl($trend['imagen'])) ?>"
                             alt="<?= e($trend['titulo']) ?>"
                             class="trending-card__img"
                             loading="lazy">
                        <?php endif; ?>
                        <div class="trending-card__body">
                            <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($trend['cat_slug'] ?? '') ?>"
                               class="trending-card__cat"
                               style="color:<?= e($trend['cat_color']) ?>">
                                <?= e($trend['cat_nombre']) ?>
                            </a>
                            <h4 class="trending-card__title">
                                <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($trend['slug']) ?>">
                                    <?= e(truncateChars($trend['titulo'], 70)) ?>
                                </a>
                            </h4>
                            <div style="display:flex;gap:8px;margin-top:4px;
                                        font-size:.72rem;color:var(--text-muted)">
                                <span>
                                    <i class="bi bi-eye"></i>
                                    <?= formatNumber((int)$trend['vistas']) ?>
                                </span>
                                <span>
                                    <i class="bi bi-share"></i>
                                    <?= formatNumber((int)($trend['total_compartidos'] ?? 0)) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Widget: Más populares de la semana -->
            <?php if (!empty($noticiasPopulares)): ?>
            <div class="widget animate-on-scroll">
                <div class="widget__header">
                    <h3 class="widget__title">
                        <i class="bi bi-fire" style="color:var(--warning)"></i>
                        Más Populares
                    </h3>
                </div>
                <div class="widget__body"
                     style="padding:var(--space-3) var(--space-4)">
                    <?php foreach ($noticiasPopulares as $pop): ?>
                    <div class="news-card news-card--compact"
                         style="display:flex;gap:10px;padding:var(--space-3) 0;
                                border-bottom:1px solid var(--border-color);
                                background:none;border-radius:0;box-shadow:none;
                                border:none;border-bottom:1px solid var(--border-color)">
                        <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($pop['slug']) ?>"
                           style="flex-shrink:0">
                            <img data-src="<?= e(getImageUrl($pop['imagen'])) ?>"
                                 src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 4 3'%3E%3C/svg%3E"
                                 alt="<?= e($pop['titulo']) ?>"
                                 class="news-card__img"
                                 loading="lazy"
                                 style="width:70px;height:52px;object-fit:cover;
                                        border-radius:var(--border-radius-sm)">
                        </a>
                        <div style="flex:1;min-width:0">
                            <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($pop['cat_slug'] ?? '') ?>"
                               class="news-card__cat"
                               style="color:<?= e($pop['cat_color']) ?>;font-size:.65rem">
                                <?= e($pop['cat_nombre']) ?>
                            </a>
                            <h4 class="news-card__title" style="font-size:.82rem">
                                <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($pop['slug']) ?>">
                                    <?= e(truncateChars($pop['titulo'], 70)) ?>
                                </a>
                            </h4>
                            <div class="news-card__meta" style="font-size:.68rem">
                                <span>
                                    <i class="bi bi-eye"></i>
                                    <?= formatNumber((int)$pop['vistas']) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Widget: Tags populares -->
            <?php if (!empty($tagsPopulares)): ?>
            <div class="widget animate-on-scroll">
                <div class="widget__header">
                    <h3 class="widget__title">
                        <i class="bi bi-tags-fill" style="color:var(--accent)"></i>
                        Temas Populares
                    </h3>
                </div>
                <div class="widget__body">
                    <div class="tags-cloud">
                        <?php foreach ($tagsPopulares as $tag): ?>
                        <a href="<?= APP_URL ?>/buscar.php?tag=<?= e($tag['slug']) ?>"
                           class="tag-pill"
                           title="<?= (int)$tag['total'] ?> noticias">
                            #<?= e($tag['nombre']) ?>
                            <span style="font-size:.65rem;opacity:.6;margin-left:3px">
                                (<?= (int)$tag['total'] ?>)
                            </span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- ── Widget: Encuesta aleatoria ──────────────── -->
            <?php
            // Obtener encuesta aleatoria activa para el sidebar
            $encuestaSidebar = db()->fetchOne(
                "SELECT e.id, e.pregunta, e.slug, e.total_votos, e.color,
                        e.tipo, e.puede_cambiar_voto, e.mostrar_resultados
                 FROM encuestas e
                 WHERE e.activa = 1
                   AND e.es_standalone = 1
                   AND (e.fecha_cierre IS NULL OR e.fecha_cierre > NOW())
                 ORDER BY RAND()
                 LIMIT 1"
            );
            if ($encuestaSidebar):
                $encSibOpciones = db()->fetchAll(
                    "SELECT id, opcion, votos FROM encuesta_opciones
                     WHERE encuesta_id = ? ORDER BY orden ASC LIMIT 6",
                    [$encuestaSidebar['id']]
                );
                // Verificar si ya votó
                $encSibTv = (int)$encuestaSidebar['total_votos'];
                if ($usuario) {
                    $encSibVoted = (bool)db()->count(
                        "SELECT COUNT(*) FROM encuesta_votos WHERE encuesta_id = ? AND usuario_id = ?",
                        [$encuestaSidebar['id'], $usuario['id']]
                    );
                    $encSibMisOpc = $encSibVoted ? array_column(db()->fetchAll(
                        "SELECT opcion_id FROM encuesta_votos WHERE encuesta_id = ? AND usuario_id = ?",
                        [$encuestaSidebar['id'], $usuario['id']]
                    ), 'opcion_id') : [];
                } else {
                    $encSibVoted = (bool)db()->count(
                        "SELECT COUNT(*) FROM encuesta_votos WHERE encuesta_id = ? AND ip = ? AND usuario_id IS NULL",
                        [$encuestaSidebar['id'], getClientIp()]
                    );
                    $encSibMisOpc = $encSibVoted ? array_column(db()->fetchAll(
                        "SELECT opcion_id FROM encuesta_votos WHERE encuesta_id = ? AND ip = ? AND usuario_id IS NULL",
                        [$encuestaSidebar['id'], getClientIp()]
                    ), 'opcion_id') : [];
                }
                $encSibMaxVotos = !empty($encSibOpciones) ? max(array_column($encSibOpciones, 'votos')) : 0;
            ?>
            <div class="widget animate-on-scroll"
                 id="sidebarPollWidget"
                 data-encuesta-id="<?= (int)$encuestaSidebar['id'] ?>"
                 data-tipo="<?= e($encuestaSidebar['tipo']) ?>"
                 style="--poll-accent:<?= e($encuestaSidebar['color'] ?? '#e63946') ?>">

                <div class="widget__header"
                     style="background:linear-gradient(135deg,<?= e($encuestaSidebar['color'] ?? '#e63946') ?>,#7c3aed);
                            border-radius:var(--border-radius-lg) var(--border-radius-lg) 0 0;margin:-1px -1px 0">
                    <h3 class="widget__title" style="color:#fff;padding:12px 16px">
                        <i class="bi bi-bar-chart-fill" style="margin-right:5px"></i>
                        Encuesta del momento
                    </h3>
                </div>

                <div class="widget__body" style="padding:14px 16px">
                    <!-- Pregunta -->
                    <p style="font-size:.84rem;font-weight:700;color:var(--text-primary);
                               line-height:1.35;margin-bottom:12px">
                        <?= e($encuestaSidebar['pregunta']) ?>
                    </p>

                    <!-- Ya votó mensaje -->
                    <?php if ($encSibVoted): ?>
                    <div style="display:flex;align-items:center;gap:6px;font-size:.75rem;
                                color:var(--success);background:rgba(34,197,94,.08);
                                padding:6px 10px;border-radius:var(--border-radius);
                                margin-bottom:10px">
                        <i class="bi bi-check-circle-fill"></i>
                        Ya votaste
                        <?php if ($encuestaSidebar['puede_cambiar_voto']): ?>
                        · <a href="<?= APP_URL ?>/encuestas.php?slug=<?= e($encuestaSidebar['slug']) ?>"
                             style="color:var(--primary)">Cambiar →</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Opciones -->
                    <div id="sidebarPollOpts">
                    <?php foreach ($encSibOpciones as $encSibOp):
                        $pctSib = $encSibTv > 0 ? round(($encSibOp['votos'] / $encSibTv) * 100, 1) : 0;
                        $selSib = in_array((int)$encSibOp['id'], $encSibMisOpc);
                        $ganSib = (int)$encSibOp['votos'] === $encSibMaxVotos && $encSibTv > 0 && $encSibMaxVotos > 0;
                    ?>
                    <label style="display:flex;align-items:center;gap:7px;padding:7px 9px;
                                  border:1.5px solid <?= $selSib ? e($encuestaSidebar['color'] ?? 'var(--primary)') : 'var(--border-color)' ?>;
                                  border-radius:var(--border-radius);margin-bottom:6px;cursor:pointer;
                                  background:<?= $selSib ? e($encuestaSidebar['color'] ?? 'var(--primary)') . '10' : 'var(--bg-body)' ?>;
                                  position:relative;overflow:hidden;transition:all .2s"
                          data-opcion-id="<?= (int)$encSibOp['id'] ?>"
                          onclick="sidebarPollSelect(this)">
                        <?php if ($encSibVoted || $encuestaSidebar['mostrar_resultados'] === 'siempre'): ?>
                        <div style="position:absolute;left:0;top:0;height:100%;
                                    width:<?= $pctSib ?>%;
                                    background:<?= e($encuestaSidebar['color'] ?? 'var(--primary)') ?>15;
                                    transition:width .6s ease;z-index:0"></div>
                        <?php endif; ?>
                        <input type="<?= $encuestaSidebar['tipo'] === 'multiple' ? 'checkbox' : 'radio' ?>"
                               name="sbpoll" value="<?= (int)$encSibOp['id'] ?>"
                               <?= $selSib ? 'checked' : '' ?>
                               style="accent-color:<?= e($encuestaSidebar['color'] ?? 'var(--primary)') ?>;
                                      position:relative;z-index:1;flex-shrink:0">
                        <span style="flex:1;font-size:.78rem;color:var(--text-primary);
                                     position:relative;z-index:1">
                            <?= $ganSib ? '🏆 ' : '' ?><?= e(truncateChars($encSibOp['opcion'], 40)) ?>
                        </span>
                        <?php if ($encSibVoted || $encuestaSidebar['mostrar_resultados'] === 'siempre'): ?>
                        <span style="font-size:.72rem;font-weight:800;
                                     color:<?= e($encuestaSidebar['color'] ?? 'var(--primary)') ?>;
                                     position:relative;z-index:1">
                            <?= $pctSib ?>%
                        </span>
                        <?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                    </div>

                    <!-- Botón votar / footer -->
                    <div style="margin-top:10px" id="sidebarPollActions">
                        <?php if (!$encSibVoted): ?>
                        <button onclick="submitSidebarVote()"
                                id="sidebarVoteBtn"
                                style="width:100%;padding:9px;background:<?= e($encuestaSidebar['color'] ?? 'var(--primary)') ?>;
                                       color:#fff;border:none;border-radius:var(--border-radius-full);
                                       font-size:.8rem;font-weight:700;cursor:pointer;
                                       display:flex;align-items:center;justify-content:center;gap:6px;
                                       transition:opacity .2s">
                            <i class="bi bi-check2-circle"></i> Votar
                        </button>
                        <?php endif; ?>
                        <div style="margin-top:8px;display:flex;align-items:center;
                                    justify-content:space-between">
                            <span style="font-size:.7rem;color:var(--text-muted)">
                                <i class="bi bi-people-fill"></i>
                                <?= formatNumber($encSibTv) ?> votos
                            </span>
                            <a href="<?= APP_URL ?>/encuestas.php?slug=<?= e($encuestaSidebar['slug']) ?>"
                               style="font-size:.7rem;color:var(--primary);
                                      font-weight:600;text-decoration:none">
                                Ver completa →
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            (function(){
            const sidebarPollId = <?= (int)$encuestaSidebar['id'] ?>;
            const sbCsrf  = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const sbColor = '<?= e($encuestaSidebar['color'] ?? '#e63946') ?>';
            const sbAppUrl = '<?= APP_URL ?>';

            window.sidebarPollSelect = function(label){
                const widget = document.getElementById('sidebarPollWidget');
                const tipo   = widget?.dataset?.tipo || 'unica';
                const container = document.getElementById('sidebarPollOpts');

                if(tipo !== 'multiple'){
                    container.querySelectorAll('label').forEach(l=>{
                        l.style.borderColor  = 'var(--border-color)';
                        l.style.background   = 'var(--bg-body)';
                        const inp = l.querySelector('input');
                        if(inp) inp.checked = false;
                    });
                }
                const isSelected = label.style.borderColor === sbColor || label.querySelector('input')?.checked;
                label.style.borderColor = isSelected ? 'var(--border-color)' : sbColor;
                label.style.background  = isSelected ? 'var(--bg-body)' : sbColor + '10';
                const inp = label.querySelector('input');
                if(inp) inp.checked = !isSelected;

                // Habilitar botón votar
                const btn = document.getElementById('sidebarVoteBtn');
                const haySel = container.querySelectorAll('input:checked').length > 0;
                if(btn){ btn.disabled = !haySel; btn.style.opacity = haySel ? '1' : '.5'; }
            };

            window.submitSidebarVote = async function(){
                const container = document.getElementById('sidebarPollOpts');
                const checked   = Array.from(container.querySelectorAll('input:checked'));
                if(checked.length === 0){ return; }

                const btn = document.getElementById('sidebarVoteBtn');
                if(btn){ btn.disabled = true; btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite"></span>'; }

                try{
                    const opcionIds = checked.map(i => parseInt(i.value));
                    let lastData;

                    for(const opId of opcionIds){
                        const res = await fetch(sbAppUrl + '/ajax/handler.php',{
                            method: 'POST',
                            headers:{ 'Content-Type':'application/json','X-CSRF-Token':sbCsrf,'X-Requested-With':'XMLHttpRequest' },
                            body: JSON.stringify({ action:'vote_poll', encuesta_id: sidebarPollId, opcion_id: opId })
                        });
                        lastData = await res.json();
                    }

                    if(lastData?.success){
                        // Actualizar barras de resultados
                        const opciones    = lastData.opciones || [];
                        const totalVotos  = lastData.total_votos || 0;
                        const maxVotos    = Math.max(...opciones.map(o=>o.votos));

                        container.querySelectorAll('label').forEach(lbl=>{
                            const opId = parseInt(lbl.dataset.opcionId);
                            const op   = opciones.find(o=>o.id===opId);
                            if(!op) return;
                            const pct = totalVotos > 0 ? Math.round((op.votos/totalVotos)*100*10)/10 : 0;

                            // Agregar/actualizar barra de fondo
                            let bar = lbl.querySelector('.sb-bar');
                            if(!bar){
                                bar = document.createElement('div');
                                bar.className = 'sb-bar';
                                bar.style.cssText = `position:absolute;left:0;top:0;height:100%;
                                    width:0%;background:${sbColor}15;transition:width .6s ease;z-index:0`;
                                lbl.style.position = 'relative';
                                lbl.style.overflow = 'hidden';
                                lbl.insertBefore(bar, lbl.firstChild);
                            }
                            setTimeout(()=>{ bar.style.width = pct + '%'; }, 50);

                            // Agregar porcentaje
                            let pctSpan = lbl.querySelector('.sb-pct');
                            if(!pctSpan){
                                pctSpan = document.createElement('span');
                                pctSpan.className = 'sb-pct';
                                pctSpan.style.cssText = `font-size:.72rem;font-weight:800;color:${sbColor};position:relative;z-index:1`;
                                lbl.appendChild(pctSpan);
                            }
                            pctSpan.textContent = pct + '%';

                            // Winner trophy
                            const opTextSpan = lbl.querySelector('span:not(.sb-pct)');
                            if(op.votos === maxVotos && op.votos > 0 && opTextSpan && !opTextSpan.textContent.includes('🏆')){
                                opTextSpan.textContent = '🏆 ' + opTextSpan.textContent;
                            }
                        });

                        // Reemplazar botón con mensaje
                        const actDiv = document.getElementById('sidebarPollActions');
                        if(actDiv){
                            actDiv.innerHTML = `
                                <div style="display:flex;align-items:center;gap:6px;font-size:.75rem;
                                            color:var(--success);background:rgba(34,197,94,.08);
                                            padding:8px 10px;border-radius:var(--border-radius);margin-bottom:8px">
                                    <i class="bi bi-check-circle-fill"></i> ¡Voto registrado!
                                </div>
                                <div style="display:flex;align-items:center;justify-content:space-between">
                                    <span style="font-size:.7rem;color:var(--text-muted)">
                                        <i class="bi bi-people-fill"></i> ${totalVotos.toLocaleString()} votos
                                    </span>
                                    <a href="${sbAppUrl}/encuestas.php?slug=<?= e($encuestaSidebar['slug']) ?>"
                                       style="font-size:.7rem;color:${sbColor};font-weight:600;text-decoration:none">
                                        Ver completa →
                                    </a>
                                </div>`;
                        }
                    } else {
                        if(btn){ btn.disabled=false; btn.innerHTML='<i class="bi bi-check2-circle"></i> Votar'; }
                        // Mostrar error brevemente
                        const errDiv = document.createElement('div');
                        errDiv.style.cssText = 'font-size:.75rem;color:var(--danger);padding:5px 0;text-align:center';
                        errDiv.textContent = lastData?.message || 'Error al registrar voto';
                        document.getElementById('sidebarPollActions')?.prepend(errDiv);
                        setTimeout(()=>errDiv.remove(), 3000);
                    }
                }catch(err){
                    console.error(err);
                    if(btn){ btn.disabled=false; btn.innerHTML='<i class="bi bi-check2-circle"></i> Votar'; }
                }
            };
            })();
            </script>

            <?php endif; // encuestaSidebar ?>

            <!-- Widget: Categorías con barra de progreso -->
            <div class="widget animate-on-scroll">
                <div class="widget__header">
                    <h3 class="widget__title">
                        <i class="bi bi-grid-3x3-gap-fill"
                           style="color:var(--primary)"></i>
                        Categorías
                    </h3>
                </div>
                <div class="widget__body"
                     style="padding:var(--space-3) var(--space-4)">
                    <?php
                    $maxNoticias = max(
                        array_column($categorias, 'total_noticias') ?: [1]
                    );
                    $maxNoticias = max(1, (int)$maxNoticias);
                    foreach ($categorias as $cat):
                        $pct = $cat['total_noticias'] > 0
                            ? (int)round(($cat['total_noticias'] / $maxNoticias) * 100)
                            : 0;
                    ?>
                    <div style="margin-bottom:10px">
                        <div style="display:flex;justify-content:space-between;
                                    align-items:center;margin-bottom:4px">
                            <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($cat['slug']) ?>"
                               style="display:flex;align-items:center;gap:6px;
                                      font-size:.82rem;font-weight:600;
                                      color:var(--text-secondary);text-decoration:none">
                                <span style="width:8px;height:8px;border-radius:50%;
                                             background:<?= e($cat['color']) ?>;
                                             flex-shrink:0"></span>
                                <?= e($cat['nombre']) ?>
                            </a>
                            <span style="font-size:.72rem;color:var(--text-muted)">
                                <?= formatNumber((int)$cat['total_noticias']) ?>
                            </span>
                        </div>
                        <div style="height:4px;background:var(--bg-surface-3);
                                    border-radius:2px;overflow:hidden">
                            <div style="height:100%;width:<?= $pct ?>%;
                                        background:<?= e($cat['color']) ?>;
                                        border-radius:2px;
                                        transition:width 1s ease">
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Widget: Seguir categorías (solo usuarios logueados) -->
            <?php if ($usuario): ?>
            <?php $catsSeguidas = getCategoriasSeguidasUsuario((int)$usuario['id']); ?>
            <div class="widget animate-on-scroll">
                <div class="widget__header">
                    <h3 class="widget__title">
                        <i class="bi bi-bell-fill" style="color:var(--primary)"></i>
                        Mis Categorías
                    </h3>
                </div>
                <div class="widget__body"
                     style="padding:var(--space-3) var(--space-4)">
                    <?php if (!empty($catsSeguidas)): ?>
                    <div class="tags-cloud" style="margin-bottom:12px">
                        <?php foreach ($catsSeguidas as $cs): ?>
                        <span class="tag-pill"
                              style="background:<?= e($cs['color']) ?>20;
                                     color:<?= e($cs['color']) ?>;
                                     border:1px solid <?= e($cs['color']) ?>40">
                            <i class="bi <?= e($cs['icono'] ?? 'bi-tag') ?>"></i>
                            <?= e($cs['nombre']) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Seguir nuevas categorías -->
                    <p style="font-size:.78rem;color:var(--text-muted);
                               margin-bottom:10px">
                        Sigue categorías para recibir notificaciones de nuevas noticias.
                    </p>
                    <?php foreach (array_slice($categorias, 0, 6) as $cat):
                        $sigue = in_array(
                            $cat['id'],
                            array_column($catsSeguidas ?? [], 'id')
                        );
                    ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;
                                padding:6px 0;border-bottom:1px solid var(--border-color)">
                        <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($cat['slug']) ?>"
                           style="display:flex;align-items:center;gap:6px;
                                  font-size:.82rem;color:var(--text-secondary);
                                  text-decoration:none">
                            <span style="width:8px;height:8px;border-radius:50%;
                                         background:<?= e($cat['color']) ?>"></span>
                            <?= e($cat['nombre']) ?>
                        </a>
                        <button class="follow-cat-btn"
                                data-cat-id="<?= (int)$cat['id'] ?>"
                                data-cat-color="<?= e($cat['color']) ?>"
                                style="padding:3px 10px;border-radius:20px;
                                       font-size:.72rem;font-weight:600;
                                       border:1px solid <?= $sigue ? 'var(--primary)' : 'var(--border-color)' ?>;
                                       color:<?= $sigue ? 'var(--primary)' : 'var(--text-muted)' ?>;
                                       background:<?= $sigue ? 'rgba(230,57,70,.08)' : 'transparent' ?>;
                                       cursor:pointer;transition:all .2s ease">
                            <i class="bi <?= $sigue ? 'bi-bell-fill' : 'bi-bell' ?>"></i>
                            <?= $sigue ? 'Siguiendo' : 'Seguir' ?>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Widget: Newsletter -->
            <div class="widget widget-newsletter animate-on-scroll">
                <div class="widget__header">
                    <h3 class="widget__title">
                        <i class="bi bi-envelope-paper-fill"
                           style="color:var(--primary)"></i>
                        Boletín de Noticias
                    </h3>
                </div>
                <div class="widget__body">
                    <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:12px">
                        Recibe las noticias más importantes cada mañana en tu correo.
                    </p>
                    <form class="newsletter-widget-form"
                          id="newsletterWidgetForm">
                        <?= csrfField() ?>
                        <input type="email"
                               name="email"
                               placeholder="tu@correo.com"
                               required
                               class="form-control"
                               style="margin-bottom:8px">
                        <button type="submit"
                                class="subscribe-btn w-full"
                                style="display:flex;align-items:center;justify-content:center;
                                       gap:6px;width:100%;padding:10px;
                                       background:var(--primary);color:#fff;
                                       border-radius:var(--border-radius);
                                       font-size:.85rem;font-weight:600;
                                       transition:background .2s">
                            <i class="bi bi-send-fill"></i>
                            Suscribirme
                        </button>
                        <div id="widgetNewsletterMsg"
                             style="display:none;font-size:.75rem;margin-top:8px;
                                    padding:6px 10px;border-radius:6px"></div>
                    </form>
                </div>
            </div>

        </aside><!-- /.sidebar -->

    </div><!-- /.main-layout -->
</div><!-- /.container-fluid -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<!-- ============================================================
     JAVASCRIPT ESPECÍFICO DEL HOME
============================================================ -->
<script>
/* ── Scroll Infinito ─────────────────────────────────────────── */
(function () {
    const newsGrid = document.getElementById('newsGrid');
    const loader   = document.getElementById('infiniteLoader');
    const loadBtn  = document.getElementById('loadMoreBtn');

    if (!newsGrid || !loader) return;

    let page    = <?= (int)$paginaActual ?>;
    let loading = false;
    let hasMore = <?= $pagination['has_next'] ? 'true' : 'false' ?>;
    const orden = document.getElementById('ordenSelect')?.value ?? 'recientes';

    // IntersectionObserver para auto-carga
    if ('IntersectionObserver' in window) {
        const obs = new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting && !loading && hasMore) loadMore();
        }, { rootMargin: '400px' });
        obs.observe(loader);
    }

    // Botón manual como fallback
    loadBtn?.addEventListener('click', loadMore);

    async function loadMore() {
        if (loading || !hasMore) return;
        loading = true;
        page++;
        loader.style.display = 'flex';
        if (loadBtn) loadBtn.disabled = true;

        try {
            const res = await fetch(window.APP.url + '/ajax/handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type':     'application/json',
                    'X-CSRF-Token':     window.APP.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    action: 'get_more_news',
                    pagina: page,
                    orden,
                }),
            });
            const data = await res.json();

            if (data.success && data.html) {
                const temp = document.createElement('div');
                temp.innerHTML = data.html;

                Array.from(temp.children).forEach((card, i) => {
                    card.style.animationDelay = `${i * 60}ms`;
                    newsGrid.appendChild(card);
                });

                // Lazy loading para nuevas imágenes
                if (window.PDApp?.initLazyImages) {
                    window.PDApp.initLazyImages();
                } else {
                    // Fallback: activar imágenes lazy manualmente
                    newsGrid.querySelectorAll('img[data-src]').forEach(img => {
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                            img.removeAttribute('data-src');
                        }
                    });
                }
            }

            hasMore = data.hay_mas ?? false;

            if (!hasMore) {
                if (loadBtn) {
                    loadBtn.textContent = 'No hay más noticias';
                    loadBtn.disabled    = true;
                    loadBtn.style.opacity = '.5';
                }
            } else {
                if (loadBtn) loadBtn.disabled = false;
            }

        } catch (err) {
            console.error('loadMore error:', err);
            page--;
            PDApp?.showToast?.('Error al cargar noticias.', 'error');
        } finally {
            loading = false;
            loader.style.display = 'none';
        }
    }
})();

/* ── Cambiar orden de noticias ───────────────────────────────── */
function changeOrden(orden) {
    const url = new URL(window.location.href);
    url.searchParams.set('orden', orden);
    url.searchParams.delete('pagina');
    window.location.href = url.toString();
}

/* ── Playlist de videos del home ────────────────────────────── */
(function initHomeVideos() {
    const playlist = document.getElementById('videoPlaylist');
    const player   = document.getElementById('mainVideoPlayer');
    if (!playlist || !player) return;

    playlist.addEventListener('click', function (e) {
        const item = e.target.closest('.video-playlist-item');
        if (!item) return;

        playlist.querySelectorAll('.video-playlist-item')
                .forEach(i => i.classList.remove('active'));
        item.classList.add('active');

        const embedUrl = item.dataset.embedUrl;
        const tipo     = item.dataset.tipo;
        const titulo   = item.dataset.titulo;

        if (tipo === 'mp4') {
            player.innerHTML = `<video src="${embedUrl}" controls autoplay
                style="width:100%;height:100%"></video>`;
        } else {
            player.innerHTML = `<iframe src="${embedUrl}?autoplay=1"
                frameborder="0"
                allow="autoplay;encrypted-media;fullscreen"
                allowfullscreen
                style="width:100%;height:100%"></iframe>`;
        }

        const titleEl = document.getElementById('currentVideoTitle');
        if (titleEl) titleEl.textContent = titulo;

        if (window.innerWidth < 768) {
            player.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
})();

/* ── Podcasts del home ───────────────────────────────────────── */
const _podAudio = document.getElementById('mainPodcastAudio');
let   _podPlaying = false;

function homePodcastToggle() {
    if (!_podAudio) return;
    if (_podPlaying) {
        _podAudio.pause();
        _podPlaying = false;
        document.getElementById('mainPodcastIcon').className = 'bi bi-play-fill';
    } else {
        _podAudio.play().then(() => {
            _podPlaying = true;
            document.getElementById('mainPodcastIcon').className = 'bi bi-pause-fill';
        }).catch(() => {});
    }
}

function homePodcastSwitch(el) {
    if (!el || !_podAudio) return;
    const audio  = el.dataset.audio;
    const titulo = el.dataset.titulo;
    const thumb  = el.dataset.thumb;

    _podAudio.pause();
    _podAudio.src = audio;
    _podPlaying   = false;
    document.getElementById('mainPodcastIcon').className = 'bi bi-play-fill';

    const titleEl = document.getElementById('mainPodcastTitle');
    const coverEl = document.getElementById('mainPodcastCover');
    if (titleEl) titleEl.textContent = titulo;
    if (coverEl) coverEl.src = thumb;

    _podAudio.play().then(() => {
        _podPlaying = true;
        document.getElementById('mainPodcastIcon').className = 'bi bi-pause-fill';
    }).catch(() => {});
}

/* ── Seguir categorías ───────────────────────────────────────── */
document.querySelectorAll('.follow-cat-btn').forEach(btn => {
    btn.addEventListener('click', async function () {
        if (!window.APP?.userId) {
            PDApp?.showToast?.('Inicia sesión para seguir categorías.', 'warning');
            return;
        }
        const catId = this.dataset.catId;
        const color = this.dataset.catColor;
        try {
            const res  = await fetch(window.APP.url + '/ajax/handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type':     'application/json',
                    'X-CSRF-Token':     window.APP.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    action:       'toggle_follow_cat',
                    categoria_id: catId,
                }),
            });
            const data = await res.json();

            if (data.success) {
                const isFollowing = data.action === 'followed';
                const icon = this.querySelector('i');
                if (icon) icon.className = isFollowing ? 'bi bi-bell-fill' : 'bi bi-bell';
                this.style.borderColor = isFollowing ? 'var(--primary)' : 'var(--border-color)';
                this.style.color       = isFollowing ? 'var(--primary)' : 'var(--text-muted)';
                this.style.background  = isFollowing ? 'rgba(230,57,70,.08)' : 'transparent';
                this.lastChild.textContent = isFollowing ? ' Siguiendo' : ' Seguir';
                PDApp?.showToast?.(data.message, 'success', 2000);
            } else {
                PDApp?.showToast?.(data.message || 'Error.', 'error');
            }
        } catch {
            PDApp?.showToast?.('Error de conexión.', 'error');
        }
    });
});

/* ── Newsletter del widget sidebar ──────────────────────────── */
const nlForm = document.getElementById('newsletterWidgetForm');
const nlMsg  = document.getElementById('widgetNewsletterMsg');
if (nlForm) {
    nlForm.addEventListener('submit', async function (e) {
        e.preventDefault();
        const btn  = this.querySelector('button[type="submit"]');
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="loader-spinner" style="width:16px;height:16px;border-width:2px"></span>';

        try {
            const res = await fetch(window.APP.url + '/ajax/handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type':     'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    action: 'newsletter_subscribe',
                    email:  this.querySelector('[name="email"]').value,
                    csrf:   window.APP?.csrfToken,
                }),
            });
            const data = await res.json();

            if (nlMsg) {
                nlMsg.style.display    = 'block';
                nlMsg.textContent      = data.message || (data.success ? '¡Suscrito!' : 'Error.');
                nlMsg.style.background = data.success
                    ? 'rgba(34,197,94,.15)' : 'rgba(239,68,68,.15)';
                nlMsg.style.color = data.success ? 'var(--success)' : 'var(--danger)';
            }
            if (data.success) {
                this.reset();
                setTimeout(() => { if (nlMsg) nlMsg.style.display = 'none'; }, 5000);
            }
        } catch {
            if (nlMsg) {
                nlMsg.textContent      = 'Error de conexión.';
                nlMsg.style.display    = 'block';
                nlMsg.style.color      = 'var(--danger)';
            }
        } finally {
            btn.disabled  = false;
            btn.innerHTML = orig;
        }
    });
}
</script>