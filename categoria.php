<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Página de Categoría
 * ============================================================
 * Archivo : categoria.php
 * Versión : 2.0.0
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// ── Obtener categoría por slug ────────────────────────────────
$slug = cleanInput($_GET['slug'] ?? '');

if (empty($slug)) {
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

$categoria = getCategoriaPorSlug($slug);

// ── 404 si no existe ──────────────────────────────────────────
if (!$categoria) {
    http_response_code(404);
    $pageTitle = 'Categoría no encontrada — ' . APP_NAME;
    $bodyClass = 'page-404';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="container-fluid px-3 px-lg-4"
         style="padding:80px 0;text-align:center">
        <div style="max-width:480px;margin:0 auto">
            <div style="font-size:5rem;margin-bottom:20px">📂</div>
            <h1 style="font-family:var(--font-serif);font-size:2rem;margin-bottom:12px">
                Categoría no encontrada
            </h1>
            <p style="color:var(--text-muted);margin-bottom:28px">
                La categoría que buscas no existe o fue desactivada.
            </p>
            <a href="<?= APP_URL ?>/index.php"
               style="display:inline-flex;align-items:center;gap:8px;
                      padding:12px 28px;background:var(--primary);
                      color:#fff;border-radius:var(--border-radius-full);
                      font-weight:600;text-decoration:none">
                <i class="bi bi-house-fill"></i> Volver al inicio
            </a>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ── Parámetros de filtro y paginación ─────────────────────────
$paginaActual  = max(1, cleanInt($_GET['pagina'] ?? 1));
$ordenActual   = cleanInput($_GET['orden'] ?? 'recientes');
$tagSlug       = cleanInput($_GET['tag']    ?? '');

// Filtro por tag dentro de la categoría
$tagData    = null;
$tagNoticiaIds = [];
if (!empty($tagSlug)) {
    $tagData = db()->fetchOne(
        "SELECT id, nombre, slug FROM tags WHERE slug = ? LIMIT 1",
        [$tagSlug]
    );
    if ($tagData) {
        $tagNoticiaIds = array_column(
            db()->fetchAll(
                "SELECT noticia_id FROM noticia_tags WHERE tag_id = ?",
                [$tagData['id']]
            ),
            'noticia_id'
        );
    }
}

// ── Contar noticias ───────────────────────────────────────────
$totalNoticias = countNoticias((int)$categoria['id']);

// Si hay filtro de tag, reducir el total
if ($tagData && !empty($tagNoticiaIds)) {
    $totalNoticias = db()->count(
        "SELECT COUNT(*) FROM noticias
         WHERE estado = 'publicado'
           AND fecha_publicacion <= NOW()
           AND categoria_id = ?
           AND id IN (" . implode(',', $tagNoticiaIds) . ")",
        [$categoria['id']]
    );
} elseif ($tagData && empty($tagNoticiaIds)) {
    $totalNoticias = 0;
}

$pagination = paginate($totalNoticias, $paginaActual, NOTICIAS_POR_PAGINA);

// ── Obtener noticias ──────────────────────────────────────────
$noticias = [];
if ($totalNoticias > 0) {
    $noticias = getNoticiasRecientes(
        NOTICIAS_POR_PAGINA,
        $pagination['offset'],
        (int)$categoria['id'],
        $ordenActual
    );

    // Filtrar por tag si aplica
    if ($tagData && !empty($tagNoticiaIds)) {
        $noticias = array_filter(
            $noticias,
            fn($n) => in_array($n['id'], $tagNoticiaIds)
        );
    }
}

// ── Noticia destacada de la categoría ────────────────────────
$noticiaDestacada = null;
if ($paginaActual === 1 && empty($tagData)) {
    $noticiaDestacada = db()->fetchOne(
        "SELECT n.id, n.titulo, n.slug, n.resumen, n.imagen,
                n.vistas, n.fecha_publicacion, n.tiempo_lectura,
                n.breaking, n.total_compartidos,
                u.nombre AS autor_nombre, u.avatar AS autor_avatar,
                c.nombre AS cat_nombre, c.color AS cat_color,
                c.icono  AS cat_icono, c.slug AS cat_slug
         FROM noticias n
         INNER JOIN usuarios   u ON u.id = n.autor_id
         INNER JOIN categorias c ON c.id = n.categoria_id
         WHERE n.categoria_id = ?
           AND n.estado = 'publicado'
           AND n.fecha_publicacion <= NOW()
           AND n.destacado = 1
         ORDER BY n.fecha_publicacion DESC
         LIMIT 1",
        [$categoria['id']]
    );
}

// ── Datos del sidebar ─────────────────────────────────────────
$trending      = getNoticiasTrending(5);
$tagsCategoria = db()->fetchAll(
    "SELECT t.id, t.nombre, t.slug, COUNT(nt.noticia_id) AS total
     FROM tags t
     INNER JOIN noticia_tags nt ON nt.tag_id = t.id
     INNER JOIN noticias     n  ON n.id = nt.noticia_id
         AND n.categoria_id = ?
         AND n.estado = 'publicado'
     GROUP BY t.id
     ORDER BY total DESC
     LIMIT 15",
    [$categoria['id']]
);

// Otras categorías para el sidebar
$otrasCategorias = array_filter(
    getCategorias(),
    fn($c) => (int)$c['id'] !== (int)$categoria['id']
);

// Anuncios
$anuncioHeader  = getAnuncios('header',  (int)$categoria['id'], 1);
$anuncioSidebar = getAnuncios('sidebar', (int)$categoria['id'], 1);

// Usuario y favoritos
$usuario      = currentUser();
$favoritosIds = [];
if ($usuario) {
    $favs = db()->fetchAll(
        "SELECT noticia_id FROM favoritos WHERE usuario_id = ?",
        [$usuario['id']]
    );
    $favoritosIds = array_column($favs, 'noticia_id');

    // Verificar si sigue la categoría
    $sigueCategoria = db()->count(
        "SELECT COUNT(*) FROM seguir_categorias
         WHERE usuario_id = ? AND categoria_id = ?",
        [$usuario['id'], $categoria['id']]
    ) > 0;
} else {
    $sigueCategoria = false;
}

// ── SEO ───────────────────────────────────────────────────────
$pageTitle = e($categoria['nombre']) . ' — ' . Config::get('site_nombre', APP_NAME);
$pageDescription = !empty($categoria['descripcion'])
    ? truncateChars($categoria['descripcion'], 160)
    : "Todas las noticias de {$categoria['nombre']} en " . Config::get('site_nombre', APP_NAME);
$bodyClass = 'page-categoria';

require_once __DIR__ . '/includes/header.php';
?>

<!-- ── HERO DE CATEGORÍA ─────────────────────────────────────── -->
<div class="cat-hero"
     style="background:linear-gradient(135deg,
            <?= e($categoria['color']) ?>22,
            <?= e($categoria['color']) ?>08);
            border-bottom:4px solid <?= e($categoria['color']) ?>;
            padding:var(--space-10) 0;
            margin-bottom:var(--space-8)">
    <div class="container-fluid px-3 px-lg-4">
        <div style="display:flex;align-items:center;
                    justify-content:space-between;flex-wrap:wrap;gap:16px">
            <div>
                <!-- Breadcrumb -->
                <nav aria-label="Ruta de navegación"
                     style="display:flex;align-items:center;gap:6px;
                            font-size:.78rem;color:var(--text-muted);
                            margin-bottom:12px">
                    <a href="<?= APP_URL ?>/index.php"
                       style="color:var(--text-muted);text-decoration:none">
                        <i class="bi bi-house-fill"></i> Inicio
                    </a>
                    <i class="bi bi-chevron-right" style="font-size:.6rem"></i>
                    <span style="color:<?= e($categoria['color']) ?>;font-weight:600">
                        <?= e($categoria['nombre']) ?>
                    </span>
                </nav>

                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                    <!-- Icono de la categoría -->
                    <div style="width:70px;height:70px;
                                background:<?= e($categoria['color']) ?>22;
                                border:3px solid <?= e($categoria['color']) ?>;
                                border-radius:var(--border-radius-xl);
                                display:flex;align-items:center;justify-content:center;
                                font-size:2rem;color:<?= e($categoria['color']) ?>;
                                flex-shrink:0">
                        <i class="bi <?= e($categoria['icono'] ?? 'bi-tag') ?>"></i>
                    </div>

                    <div>
                        <h1 style="font-family:var(--font-serif);
                                   font-size:clamp(1.5rem,3vw,2.5rem);
                                   font-weight:900;color:var(--text-primary);
                                   margin-bottom:4px">
                            <?= e($categoria['nombre']) ?>
                        </h1>
                        <?php if (!empty($categoria['descripcion'])): ?>
                        <p style="color:var(--text-muted);font-size:.9rem;
                                   max-width:500px;margin:0">
                            <?= e($categoria['descripcion']) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Stats + Acciones -->
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:12px">
                <div style="display:flex;gap:24px;text-align:center">
                    <div>
                        <span style="display:block;font-size:1.5rem;
                                     font-weight:900;color:<?= e($categoria['color']) ?>">
                            <?= formatNumber((int)($categoria['total_noticias'] ?? $totalNoticias)) ?>
                        </span>
                        <span style="font-size:.72rem;color:var(--text-muted);
                                     text-transform:uppercase;letter-spacing:.06em">
                            Noticias
                        </span>
                    </div>
                    <?php if (!empty($tagsCategoria)): ?>
                    <div>
                        <span style="display:block;font-size:1.5rem;
                                     font-weight:900;color:var(--text-primary)">
                            <?= count($tagsCategoria) ?>
                        </span>
                        <span style="font-size:.72rem;color:var(--text-muted);
                                     text-transform:uppercase;letter-spacing:.06em">
                            Temas
                        </span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Botón seguir categoría -->
                <?php if ($usuario): ?>
                <button id="followCatBtn"
                        data-action="follow-cat"
                        data-cat-id="<?= (int)$categoria['id'] ?>"
                        style="display:flex;align-items:center;gap:8px;
                               padding:10px 20px;border-radius:var(--border-radius-full);
                               border:2px solid <?= $sigueCategoria ? $categoria['color'] : 'var(--border-color)' ?>;
                               color:<?= $sigueCategoria ? $categoria['color'] : 'var(--text-muted)' ?>;
                               background:<?= $sigueCategoria ? $categoria['color'] . '15' : 'transparent' ?>;
                               cursor:pointer;font-size:.875rem;font-weight:600;
                               transition:all .2s ease">
                    <i class="bi <?= $sigueCategoria ? 'bi-bell-fill' : 'bi-bell' ?>"></i>
                    <?= $sigueCategoria ? 'Siguiendo' : 'Seguir categoría' ?>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filtros de tags de la categoría -->
        <?php if (!empty($tagsCategoria)): ?>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:24px;
                    padding-top:20px;border-top:1px solid <?= e($categoria['color']) ?>33">
            <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($categoria['slug']) ?>"
               class="tag-pill"
               style="<?= empty($tagSlug) ? "background:{$categoria['color']};color:#fff;" : '' ?>">
                Todas
            </a>
            <?php foreach (array_slice($tagsCategoria, 0, 10) as $tag): ?>
            <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($categoria['slug']) ?>&tag=<?= e($tag['slug']) ?>"
               class="tag-pill"
               style="<?= $tagSlug === $tag['slug'] ? "background:{$categoria['color']};color:#fff;" : '' ?>">
                #<?= e($tag['nombre']) ?>
                <span style="font-size:.65rem;opacity:.7;margin-left:3px">
                    (<?= (int)$tag['total'] ?>)
                </span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- ── ANUNCIO HEADER ─────────────────────────────────────────── -->
<?php if (!empty($anuncioHeader) && Config::bool('ads_header')): ?>
<div class="container-fluid px-3 px-lg-4 mb-6" style="text-align:center">
    <?= renderAnuncio($anuncioHeader[0]) ?>
</div>
<?php endif; ?>

<!-- ── CONTENIDO PRINCIPAL + SIDEBAR ─────────────────────────── -->
<div class="container-fluid px-3 px-lg-4">
    <div class="main-layout">

        <!-- ── COLUMNA PRINCIPAL ─────────────────────────────── -->
        <div class="main-content">

            <!-- Noticia destacada de la categoría -->
            <?php if ($noticiaDestacada && $paginaActual === 1): ?>
            <div style="margin-bottom:var(--space-8)">
                <article style="display:grid;grid-template-columns:1fr 1fr;
                                gap:0;border-radius:var(--border-radius-xl);
                                overflow:hidden;background:var(--bg-surface);
                                box-shadow:var(--shadow-md);
                                border:2px solid <?= e($categoria['color']) ?>33">
                    <!-- Imagen -->
                    <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($noticiaDestacada['slug']) ?>"
                       style="display:block;aspect-ratio:16/9;overflow:hidden;
                              background:var(--bg-surface-3)">
                        <img src="<?= e(getImageUrl($noticiaDestacada['imagen'])) ?>"
                             alt="<?= e($noticiaDestacada['titulo']) ?>"
                             style="width:100%;height:100%;object-fit:cover;
                                    transition:transform .4s ease"
                             loading="eager">
                    </a>
                    <!-- Contenido -->
                    <div style="padding:var(--space-6) var(--space-8);
                                display:flex;flex-direction:column;justify-content:center">
                        <span style="display:inline-flex;align-items:center;gap:6px;
                                     background:<?= e($categoria['color']) ?>;color:#fff;
                                     padding:4px 12px;border-radius:var(--border-radius-full);
                                     font-size:.72rem;font-weight:800;
                                     text-transform:uppercase;letter-spacing:.08em;
                                     margin-bottom:12px;width:fit-content">
                            <i class="bi bi-star-fill"></i> Destacado
                        </span>
                        <h2 style="font-family:var(--font-serif);
                                   font-size:clamp(1.2rem,2vw,1.6rem);
                                   font-weight:900;color:var(--text-primary);
                                   line-height:1.3;margin-bottom:12px">
                            <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($noticiaDestacada['slug']) ?>"
                               style="color:inherit;text-decoration:none">
                                <?= e($noticiaDestacada['titulo']) ?>
                            </a>
                        </h2>
                        <?php if (!empty($noticiaDestacada['resumen'])): ?>
                        <p style="color:var(--text-muted);font-size:.875rem;
                                   line-height:1.6;margin-bottom:16px;
                                   display:-webkit-box;-webkit-line-clamp:3;
                                   -webkit-box-orient:vertical;overflow:hidden">
                            <?= e($noticiaDestacada['resumen']) ?>
                        </p>
                        <?php endif; ?>
                        <div style="display:flex;align-items:center;gap:16px;
                                    font-size:.78rem;color:var(--text-muted);flex-wrap:wrap">
                            <span style="display:flex;align-items:center;gap:4px">
                                <img src="<?= e(getImageUrl($noticiaDestacada['autor_avatar'] ?? '', 'avatar')) ?>"
                                     alt="<?= e($noticiaDestacada['autor_nombre']) ?>"
                                     style="width:24px;height:24px;border-radius:50%;
                                            object-fit:cover">
                                <?= e($noticiaDestacada['autor_nombre']) ?>
                            </span>
                            <span>
                                <i class="bi bi-clock"></i>
                                <?= timeAgo($noticiaDestacada['fecha_publicacion']) ?>
                            </span>
                            <span>
                                <i class="bi bi-eye"></i>
                                <?= formatNumber((int)$noticiaDestacada['vistas']) ?>
                            </span>
                            <?php if (($noticiaDestacada['tiempo_lectura'] ?? 0) > 0): ?>
                            <span>
                                <i class="bi bi-book"></i>
                                <?= (int)$noticiaDestacada['tiempo_lectura'] ?> min
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            </div>
            <?php endif; ?>

            <!-- Encabezado con filtros -->
            <div style="display:flex;align-items:center;justify-content:space-between;
                        flex-wrap:wrap;gap:12px;margin-bottom:var(--space-6);
                        padding-bottom:var(--space-4);
                        border-bottom:3px solid <?= e($categoria['color']) ?>">
                <div>
                    <h2 style="font-family:var(--font-serif);font-size:1.3rem;
                               font-weight:800;color:var(--text-primary)">
                        <?php if ($tagData): ?>
                            <span style="color:<?= e($categoria['color']) ?>">
                                #<?= e($tagData['nombre']) ?>
                            </span>
                            <span style="font-size:.85rem;font-weight:400;
                                         color:var(--text-muted)">
                                en <?= e($categoria['nombre']) ?>
                            </span>
                        <?php else: ?>
                            Todas las noticias
                        <?php endif; ?>
                    </h2>
                    <p style="font-size:.78rem;color:var(--text-muted);margin-top:2px">
                        <?= formatNumber($totalNoticias) ?>
                        noticia<?= $totalNoticias !== 1 ? 's' : '' ?>
                        <?php if ($totalNoticias > 0 && $paginaActual > 1): ?>
                        — Página <?= $paginaActual ?> de <?= $pagination['total_pages'] ?>
                        <?php endif; ?>
                    </p>
                </div>

                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <!-- Orden -->
                    <select id="ordenSelect"
                            onchange="changeOrden(this.value)"
                            class="form-select"
                            style="width:auto;padding:6px 28px 6px 10px;font-size:.8rem">
                        <option value="recientes"
                            <?= $ordenActual === 'recientes' ? 'selected' : '' ?>>
                            Más recientes
                        </option>
                        <option value="populares"
                            <?= $ordenActual === 'populares' ? 'selected' : '' ?>>
                            Más populares
                        </option>
                        <option value="comentados"
                            <?= $ordenActual === 'comentados' ? 'selected' : '' ?>>
                            Más comentados
                        </option>
                    </select>

                    <!-- Vista grid/lista -->
                    <div style="display:flex;gap:4px">
                        <button id="viewGrid"
                                onclick="setView('grid')"
                                title="Vista en cuadrícula"
                                style="padding:6px 10px;border-radius:var(--border-radius-sm);
                                       border:1px solid var(--border-color);
                                       color:var(--primary);background:rgba(230,57,70,.08);
                                       cursor:pointer;transition:all .2s ease">
                            <i class="bi bi-grid-3x3-gap-fill"></i>
                        </button>
                        <button id="viewList"
                                onclick="setView('list')"
                                title="Vista en lista"
                                style="padding:6px 10px;border-radius:var(--border-radius-sm);
                                       border:1px solid var(--border-color);
                                       color:var(--text-muted);background:transparent;
                                       cursor:pointer;transition:all .2s ease">
                            <i class="bi bi-list-ul"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Grid de noticias -->
            <?php if (!empty($noticias)): ?>
            <div class="news-grid news-grid--3col stagger-children"
                 id="categoryGrid"
                 data-categoria="<?= (int)$categoria['id'] ?>"
                 data-orden="<?= e($ordenActual) ?>">

                <?php
                $adsFreq = Config::int('ads_frecuencia', 3);
                $counter = 0;
                foreach ($noticias as $noticia):
                    $counter++;
                    $isFav = in_array($noticia['id'], $favoritosIds);
                ?>
                <article class="news-card animate-on-scroll"
                         data-id="<?= (int)$noticia['id'] ?>">
                    <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($noticia['slug']) ?>"
                       class="news-card__img-wrap" tabindex="-1">
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
                              style="top:auto;bottom:8px;left:8px">
                            <i class="bi bi-star-fill"></i> Premium
                        </span>
                        <?php endif; ?>
                        <?php if ($usuario): ?>
                        <button data-action="toggle-favorite"
                                data-noticia-id="<?= (int)$noticia['id'] ?>"
                                title="<?= $isFav ? 'Quitar' : 'Guardar' ?>"
                                style="position:absolute;top:8px;right:8px;
                                       background:rgba(0,0,0,.5);border:none;
                                       color:<?= $isFav ? 'var(--primary)' : '#fff' ?>;
                                       width:32px;height:32px;border-radius:50%;
                                       display:flex;align-items:center;
                                       justify-content:center;cursor:pointer;
                                       font-size:.9rem;transition:all .2s ease">
                            <i class="bi <?= $isFav ? 'bi-bookmark-fill' : 'bi-bookmark' ?>"></i>
                        </button>
                        <?php endif; ?>
                    </a>
                    <div class="news-card__body">
                        <h3 class="news-card__title">
                            <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($noticia['slug']) ?>">
                                <?= e(truncateChars($noticia['titulo'], 90)) ?>
                            </a>
                        </h3>
                        <?php if (!empty($noticia['resumen'])): ?>
                        <p class="news-card__excerpt">
                            <?= e(truncateChars($noticia['resumen'], 90)) ?>
                        </p>
                        <?php endif; ?>
                        <div class="news-card__meta">
                            <span>
                                <i class="bi bi-person"></i>
                                <?= e($noticia['autor_nombre']) ?>
                            </span>
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
                        </div>
                    </div>
                </article>

                <?php
                // Anuncio entre noticias
                if (Config::bool('ads_entre_noticias') && $counter % $adsFreq === 0):
                    $adsInline = getAnuncios('entre_noticias', (int)$categoria['id'], 1);
                    if (!empty($adsInline)):
                ?>
                <div class="news-card news-card--ad"
                     style="border:2px dashed var(--border-color);
                            justify-content:center;align-items:center;
                            min-height:200px;background:var(--bg-surface-2)">
                    <?= renderAnuncio($adsInline[0]) ?>
                </div>
                <?php endif; endif; ?>

                <?php endforeach; ?>
            </div>

            <!-- Loader scroll infinito -->
            <div class="infinite-loader" id="infiniteLoader"
                 style="display:none">
                <div class="loader-spinner"></div>
                <span>Cargando más noticias...</span>
            </div>

            <!-- Botón cargar más -->
            <?php if ($pagination['has_next']): ?>
            <div style="text-align:center;margin-top:var(--space-8)">
                <button id="loadMoreBtn"
                        style="display:inline-flex;align-items:center;gap:8px;
                               padding:12px 32px;background:var(--bg-surface);
                               border:2px solid <?= e($categoria['color']) ?>;
                               color:<?= e($categoria['color']) ?>;
                               border-radius:var(--border-radius-full);
                               font-size:.9rem;font-weight:700;
                               cursor:pointer;transition:all .2s ease">
                    <i class="bi bi-arrow-down-circle"></i>
                    Cargar más noticias
                </button>
            </div>
            <?php else: ?>
            <p style="text-align:center;color:var(--text-muted);
                       font-size:.85rem;margin-top:var(--space-8);
                       padding:var(--space-4);border-top:1px solid var(--border-color)">
                <i class="bi bi-check-circle-fill"
                   style="color:var(--success)"></i>
                Has visto todas las noticias de <?= e($categoria['nombre']) ?>
            </p>
            <?php endif; ?>

            <!-- Paginación clásica como respaldo -->
            <?php if ($pagination['total_pages'] > 1): ?>
            <div style="margin-top:var(--space-8)">
                <?php
                $baseUrl = APP_URL . '/categoria.php?slug=' . urlencode($categoria['slug']);
                if ($ordenActual !== 'recientes') {
                    $baseUrl .= '&orden=' . urlencode($ordenActual);
                }
                if ($tagSlug) {
                    $baseUrl .= '&tag=' . urlencode($tagSlug);
                }
                echo renderPagination($pagination, $baseUrl);
                ?>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <!-- Sin noticias -->
            <div style="text-align:center;padding:80px 20px;
                        background:var(--bg-surface);
                        border-radius:var(--border-radius-xl);
                        border:2px dashed var(--border-color)">
                <i class="bi bi-newspaper"
                   style="font-size:3.5rem;color:var(--text-muted);
                          opacity:.3;display:block;margin-bottom:16px"></i>
                <h3 style="font-family:var(--font-serif);color:var(--text-muted);
                           margin-bottom:8px">
                    <?php if ($tagData): ?>
                    No hay noticias con la etiqueta
                    <strong style="color:<?= e($categoria['color']) ?>">
                        #<?= e($tagData['nombre']) ?>
                    </strong>
                    <?php else: ?>
                    No hay noticias en esta categoría todavía
                    <?php endif; ?>
                </h3>
                <?php if ($tagData): ?>
                <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($categoria['slug']) ?>"
                   style="display:inline-flex;align-items:center;gap:8px;
                          margin-top:16px;padding:10px 24px;
                          background:<?= e($categoria['color']) ?>;
                          color:#fff;border-radius:var(--border-radius-full);
                          font-size:.875rem;font-weight:600;text-decoration:none">
                    Ver todas las noticias de <?= e($categoria['nombre']) ?>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div><!-- /.main-content -->

        <!-- ── SIDEBAR ────────────────────────────────────── -->
        <aside class="sidebar" role="complementary">

            <!-- Widget: Anuncio -->
            <?php if (!empty($anuncioSidebar) && Config::bool('ads_sidebar')): ?>
            <div class="widget">
                <?= renderAnuncio($anuncioSidebar[0]) ?>
            </div>
            <?php endif; ?>

            <!-- Widget: Info de la categoría -->
            <div class="widget">
                <div class="widget__header"
                     style="border-bottom-color:<?= e($categoria['color']) ?>">
                    <h3 class="widget__title"
                        style="color:<?= e($categoria['color']) ?>">
                        <i class="bi <?= e($categoria['icono'] ?? 'bi-tag') ?>"></i>
                        <?= e($categoria['nombre']) ?>
                    </h3>
                </div>
                <div class="widget__body">
                    <?php if (!empty($categoria['descripcion'])): ?>
                    <p style="font-size:.82rem;color:var(--text-muted);
                               line-height:1.6;margin-bottom:12px">
                        <?= e($categoria['descripcion']) ?>
                    </p>
                    <?php endif; ?>
                    <div style="display:flex;gap:16px;padding:12px 0;
                                border-top:1px solid var(--border-color)">
                        <div style="text-align:center;flex:1">
                            <span style="display:block;font-size:1.3rem;
                                         font-weight:900;
                                         color:<?= e($categoria['color']) ?>">
                                <?= formatNumber((int)($categoria['total_noticias'] ?? $totalNoticias)) ?>
                            </span>
                            <small style="font-size:.68rem;color:var(--text-muted);
                                          text-transform:uppercase">
                                Noticias
                            </small>
                        </div>
                        <div style="text-align:center;flex:1">
                            <span style="display:block;font-size:1.3rem;
                                         font-weight:900;color:var(--text-primary)">
                                <?= count($tagsCategoria) ?>
                            </span>
                            <small style="font-size:.68rem;color:var(--text-muted);
                                          text-transform:uppercase">
                                Temas
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Widget: Tags de esta categoría -->
            <?php if (!empty($tagsCategoria)): ?>
            <div class="widget">
                <div class="widget__header">
                    <h3 class="widget__title">
                        <i class="bi bi-tags-fill"
                           style="color:<?= e($categoria['color']) ?>"></i>
                        Temas en <?= e($categoria['nombre']) ?>
                    </h3>
                </div>
                <div class="widget__body">
                    <div class="tags-cloud">
                        <?php foreach ($tagsCategoria as $tag): ?>
                        <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($categoria['slug']) ?>&tag=<?= e($tag['slug']) ?>"
                           class="tag-pill"
                           style="<?= ($tagSlug === $tag['slug'])
                               ? "background:{$categoria['color']};color:#fff;"
                               : '' ?>">
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

            <!-- Widget: Trending -->
            <?php if (!empty($trending)): ?>
            <div class="widget">
                <div class="widget__header">
                    <h3 class="widget__title">
                        <i class="bi bi-graph-up-arrow"
                           style="color:var(--primary)"></i>
                        Trending Ahora
                    </h3>
                </div>
                <div class="widget__body"
                     style="padding:0 var(--space-4)">
                    <?php foreach ($trending as $idx => $tr): ?>
                    <div class="trending-card">
                        <span class="trending-number"><?= $idx + 1 ?></span>
                        <div class="trending-card__body">
                            <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($tr['cat_slug'] ?? '') ?>"
                               class="trending-card__cat"
                               style="color:<?= e($tr['cat_color']) ?>">
                                <?= e($tr['cat_nombre']) ?>
                            </a>
                            <h4 class="trending-card__title">
                                <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($tr['slug']) ?>">
                                    <?= e(truncateChars($tr['titulo'], 65)) ?>
                                </a>
                            </h4>
                            <span style="font-size:.7rem;color:var(--text-muted)">
                                <i class="bi bi-eye"></i>
                                <?= formatNumber((int)$tr['vistas']) ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Widget: Otras categorías -->
            <div class="widget">
                <div class="widget__header">
                    <h3 class="widget__title">
                        <i class="bi bi-grid-3x3-gap-fill"
                           style="color:var(--primary)"></i>
                        Otras Secciones
                    </h3>
                </div>
                <div class="widget__body"
                     style="padding:var(--space-2) var(--space-4)">
                    <?php foreach (array_slice(array_values($otrasCategorias), 0, 8) as $oc): ?>
                    <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($oc['slug']) ?>"
                       style="display:flex;align-items:center;gap:10px;
                              padding:8px 0;border-bottom:1px solid var(--border-color);
                              color:var(--text-secondary);text-decoration:none;
                              font-size:.82rem;transition:all .2s ease">
                        <span style="width:8px;height:8px;border-radius:50%;
                                     background:<?= e($oc['color']) ?>;
                                     flex-shrink:0"></span>
                        <i class="bi <?= e($oc['icono'] ?? 'bi-tag') ?>"
                           style="color:<?= e($oc['color']) ?>;font-size:.9rem"></i>
                        <span style="flex:1"><?= e($oc['nombre']) ?></span>
                        <span style="font-size:.7rem;color:var(--text-muted)">
                            <?= formatNumber((int)($oc['total_noticias'] ?? 0)) ?>
                        </span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

        </aside><!-- /.sidebar -->

    </div><!-- /.main-layout -->
</div><!-- /.container -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<style>
/* Noticia destacada responsive */
@media (max-width: 768px) {
    article[style*="grid-template-columns:1fr 1fr"] {
        grid-template-columns: 1fr !important;
    }
}

/* Vista lista */
#categoryGrid.view-list {
    grid-template-columns: 1fr !important;
}

#categoryGrid.view-list .news-card {
    flex-direction: row;
    max-height: 160px;
}

#categoryGrid.view-list .news-card__img-wrap {
    width: 200px;
    aspect-ratio: unset;
    flex-shrink: 0;
}

#categoryGrid.view-list .news-card__body {
    padding: var(--space-4);
}

#categoryGrid.view-list .news-card__excerpt {
    display: block;
    -webkit-line-clamp: 2;
}

@media (max-width: 768px) {
    #categoryGrid.view-list .news-card {
        flex-direction: column;
        max-height: none;
    }
    #categoryGrid.view-list .news-card__img-wrap {
        width: 100%;
        aspect-ratio: 16/9;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── Cambiar orden ─────────────────────────────────────────
    window.changeOrden = function (orden) {
        const url = new URL(window.location.href);
        url.searchParams.set('orden', orden);
        url.searchParams.delete('pagina');
        window.location.href = url.toString();
    };

    // ── Cambiar vista (grid/list) ─────────────────────────────
    window.setView = function (view) {
        const grid     = document.getElementById('categoryGrid');
        const btnGrid  = document.getElementById('viewGrid');
        const btnList  = document.getElementById('viewList');

        if (!grid) return;

        const primaryColor = '<?= e($categoria['color']) ?>';

        if (view === 'list') {
            grid.classList.add('view-list');
            if (btnList) {
                btnList.style.color      = primaryColor;
                btnList.style.background = primaryColor + '15';
                btnList.style.borderColor= primaryColor;
            }
            if (btnGrid) {
                btnGrid.style.color      = 'var(--text-muted)';
                btnGrid.style.background = 'transparent';
                btnGrid.style.borderColor= 'var(--border-color)';
            }
        } else {
            grid.classList.remove('view-list');
            if (btnGrid) {
                btnGrid.style.color      = primaryColor;
                btnGrid.style.background = primaryColor + '15';
                btnGrid.style.borderColor= primaryColor;
            }
            if (btnList) {
                btnList.style.color      = 'var(--text-muted)';
                btnList.style.background = 'transparent';
                btnList.style.borderColor= 'var(--border-color)';
            }
        }

        localStorage.setItem('pd_cat_view', view);
    };

    // Restaurar vista guardada
    const savedView = localStorage.getItem('pd_cat_view') ?? 'grid';
    setView(savedView);

    // ── Seguir categoría ──────────────────────────────────────
    const followBtn = document.getElementById('followCatBtn');
    followBtn?.addEventListener('click', async function () {
        if (!window.APP.userId) {
            PDApp.showToast('Inicia sesión para seguir categorías.', 'warning');
            return;
        }

        const catId = this.dataset.catId;
        const color = '<?= e($categoria['color']) ?>';

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
                if (icon) {
                    icon.className = isFollowing
                        ? 'bi bi-bell-fill'
                        : 'bi bi-bell';
                }
                this.style.borderColor = isFollowing ? color : 'var(--border-color)';
                this.style.color       = isFollowing ? color : 'var(--text-muted)';
                this.style.background  = isFollowing ? color + '15' : 'transparent';
                this.lastChild.textContent = isFollowing
                    ? ' Siguiendo'
                    : ' Seguir categoría';
                PDApp.showToast(data.message, 'success', 2000);
            }
        } catch {
            PDApp.showToast('Error de conexión.', 'error');
        }
    });

    // ── Scroll infinito ───────────────────────────────────────
    const grid    = document.getElementById('categoryGrid');
    const loader  = document.getElementById('infiniteLoader');
    const loadBtn = document.getElementById('loadMoreBtn');

    if (grid && loader) {
        let page    = <?= (int)$paginaActual ?>;
        let loading = false;
        let hasMore = <?= $pagination['has_next'] ? 'true' : 'false' ?>;
        const catId = grid.dataset.categoria;
        const orden = grid.dataset.orden;
        const color = '<?= e($categoria['color']) ?>';

        if ('IntersectionObserver' in window) {
            const obs = new IntersectionObserver((entries) => {
                if (entries[0].isIntersecting && !loading && hasMore) {
                    loadMore();
                }
            }, { rootMargin: '400px' });
            obs.observe(loader);
        }

        loadBtn?.addEventListener('click', loadMore);

        async function loadMore() {
            if (loading || !hasMore) return;
            loading = true;
            page++;

            loader.style.display = 'flex';
            if (loadBtn) loadBtn.disabled = true;

            try {
                const res  = await fetch(window.APP.url + '/ajax/handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type':     'application/json',
                        'X-CSRF-Token':     window.APP.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        action:       'get_more_news',
                        pagina:       page,
                        categoria_id: catId,
                        orden,
                    }),
                });
                const data = await res.json();

                if (data.success && data.html) {
                    const temp = document.createElement('div');
                    temp.innerHTML = data.html;

                    Array.from(temp.children).forEach((card, i) => {
                        card.style.animationDelay = `${i * 60}ms`;
                        // Mantener vista actual
                        if (grid.classList.contains('view-list')) {
                            card.classList.add('news-card--horizontal');
                        }
                        grid.appendChild(card);
                    });

                    // Lazy loading para nuevas imágenes
                    temp.querySelectorAll?.('img[data-src]');
                    grid.querySelectorAll('img[data-src]').forEach(img => {
                        if ('IntersectionObserver' in window) {
                            const imgObs = new IntersectionObserver((entries, obs) => {
                                if (entries[0].isIntersecting) {
                                    img.src = img.dataset.src;
                                    img.removeAttribute('data-src');
                                    obs.disconnect();
                                }
                            }, { rootMargin: '200px' });
                            imgObs.observe(img);
                        } else {
                            img.src = img.dataset.src;
                        }
                    });

                    hasMore = data.hay_mas;
                }

                if (!hasMore) {
                    loader.innerHTML = `
                        <span style="color:var(--text-muted);font-size:.85rem">
                            <i class="bi bi-check-circle-fill"
                               style="color:var(--success)"></i>
                            Has visto todas las noticias de <?= e($categoria['nombre']) ?>
                        </span>`;
                    loader.style.display = 'flex';
                    if (loadBtn) loadBtn.style.display = 'none';
                } else {
                    loader.style.display = 'none';
                    if (loadBtn) {
                        loadBtn.disabled  = false;
                        loadBtn.innerHTML = '<i class="bi bi-arrow-down-circle"></i> Cargar más noticias';
                    }
                }

            } catch {
                page--;
                loader.style.display = 'none';
                if (loadBtn) loadBtn.disabled = false;
                PDApp.showToast('Error al cargar más noticias.', 'error');
            } finally {
                loading = false;
            }
        }
    }

    // ── Favoritos ─────────────────────────────────────────────
    document.addEventListener('click', async function (e) {
        const btn = e.target.closest('[data-action="toggle-favorite"]');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();

        if (!window.APP.userId) {
            PDApp.showToast('Inicia sesión para guardar noticias.', 'warning');
            return;
        }

        const noticiaId = btn.dataset.noticiaId;
        const icon      = btn.querySelector('i');

        btn.style.transform = 'scale(0.85)';
        setTimeout(() => btn.style.transform = '', 200);

        try {
            const res  = await fetch(window.APP.url + '/ajax/handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type':     'application/json',
                    'X-CSRF-Token':     window.APP.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    action:     'toggle_favorite',
                    noticia_id: noticiaId,
                }),
            });
            const data = await res.json();

            if (data.success) {
                const isSaved = data.action === 'added';
                if (icon) {
                    icon.className = isSaved
                        ? 'bi bi-bookmark-fill'
                        : 'bi bi-bookmark';
                }
                btn.style.color = isSaved ? 'var(--primary)' : '#fff';
                PDApp.showToast(data.message, 'success', 2000);
            }
        } catch {
            PDApp.showToast('Error de conexión.', 'error');
        }
    });

    // ── Animaciones ───────────────────────────────────────────
    if ('IntersectionObserver' in window) {
        const obs = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity   = '1';
                    entry.target.style.transform = 'translateY(0)';
                    obs.unobserve(entry.target);
                }
            });
        }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

        document.querySelectorAll('.animate-on-scroll').forEach((el, i) => {
            el.style.opacity    = '0';
            el.style.transform  = 'translateY(20px)';
            el.style.transition = `opacity 0.4s ease ${Math.min(i, 8) * 50}ms, transform 0.4s ease ${Math.min(i, 8) * 50}ms`;
            obs.observe(el);
        });
    }

    // ── Lazy loading ──────────────────────────────────────────
    if ('IntersectionObserver' in window) {
        const obs = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                    }
                    observer.unobserve(img);
                }
            });
        }, { rootMargin: '300px' });

        document.querySelectorAll('img[data-src]').forEach(img => obs.observe(img));
    } else {
        document.querySelectorAll('img[data-src]').forEach(img => {
            img.src = img.dataset.src;
        });
    }

});
</script>