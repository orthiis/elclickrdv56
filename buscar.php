<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Búsqueda Avanzada
 * ============================================================
 * Archivo : buscar.php
 * Versión : 2.0.0
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// ── Parámetros de búsqueda ────────────────────────────────────
$q             = cleanInput($_GET['q']         ?? '', 200);
$categoriaSlug = cleanInput($_GET['categoria'] ?? '');
$fechaDesde    = cleanInput($_GET['desde']     ?? '');
$fechaHasta    = cleanInput($_GET['hasta']     ?? '');
$tagSlug       = cleanInput($_GET['tag']       ?? '');
$orden         = cleanInput($_GET['orden']     ?? 'relevancia');
$paginaActual  = max(1, cleanInt($_GET['pagina'] ?? 1));

// ── Validar fechas ────────────────────────────────────────────
if ($fechaDesde && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) {
    $fechaDesde = '';
}
if ($fechaHasta && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) {
    $fechaHasta = '';
}

// ── Datos base ────────────────────────────────────────────────
$categorias = getCategorias(false);
$usuario    = currentUser();

// Buscar categoría activa
$categoriaActiva = null;
$categoriaId     = null;
if (!empty($categoriaSlug)) {
    $categoriaActiva = getCategoriaPorSlug($categoriaSlug);
    $categoriaId     = $categoriaActiva ? (int)$categoriaActiva['id'] : null;
}

// Buscar tag activo
$tagActivo    = null;
$tagNoticiaIds = null;
if (!empty($tagSlug)) {
    $tagActivo = db()->fetchOne(
        "SELECT id, nombre, slug FROM tags WHERE slug = ? LIMIT 1",
        [$tagSlug]
    );
    if ($tagActivo) {
        $tagNoticiaIds = array_column(
            db()->fetchAll(
                "SELECT noticia_id FROM noticia_tags WHERE tag_id = ?",
                [$tagActivo['id']]
            ),
            'noticia_id'
        );
    }
}

// ── Ejecutar búsqueda ─────────────────────────────────────────
$resultados = [];
$total      = 0;
$pagination = paginate(0, 1);
$busquedaActiva = !empty($q) || !empty($categoriaSlug) ||
                  !empty($fechaDesde) || !empty($fechaHasta) ||
                  !empty($tagSlug);

if ($busquedaActiva) {
    // Si hay filtro de tag y está vacío, no hay resultados
    if ($tagActivo && is_array($tagNoticiaIds) && empty($tagNoticiaIds)) {
        $total = 0;
    } else {
        $total = countBusqueda(
            $q,
            $categoriaId,
            $fechaDesde ?: null,
            $fechaHasta ?: null
        );

        // Reducir total si hay filtro de tag
        if ($tagActivo && !empty($tagNoticiaIds)) {
            // Contar intersección
            $total = db()->count(
                "SELECT COUNT(*) FROM noticias n
                 INNER JOIN noticia_tags nt ON nt.noticia_id = n.id
                 WHERE n.estado = 'publicado'
                   AND n.fecha_publicacion <= NOW()
                   AND nt.tag_id = ?
                   " . ($categoriaId ? "AND n.categoria_id = $categoriaId" : "") . "
                   " . ($fechaDesde ? "AND DATE(n.fecha_publicacion) >= '$fechaDesde'" : "") . "
                   " . ($fechaHasta ? "AND DATE(n.fecha_publicacion) <= '$fechaHasta'" : ""),
                [$tagActivo['id']]
            );
        }
    }

    $pagination = paginate($total, $paginaActual, NOTICIAS_POR_PAGINA);

    if ($total > 0) {
        if ($tagActivo && !empty($tagNoticiaIds)) {
            // Búsqueda con filtro de tag
            $placeholders = implode(',', array_fill(0, count($tagNoticiaIds), '?'));
            $params       = $tagNoticiaIds;

            $whereExtra  = '';
            if ($categoriaId) {
                $whereExtra .= " AND n.categoria_id = ?";
                $params[]   = $categoriaId;
            }
            if ($fechaDesde) {
                $whereExtra .= " AND DATE(n.fecha_publicacion) >= ?";
                $params[]   = $fechaDesde;
            }
            if ($fechaHasta) {
                $whereExtra .= " AND DATE(n.fecha_publicacion) <= ?";
                $params[]   = $fechaHasta;
            }

            $orderSql = match($orden) {
                'populares'  => 'n.vistas DESC',
                'recientes'  => 'n.fecha_publicacion DESC',
                default      => 'n.fecha_publicacion DESC',
            };

            $params[] = NOTICIAS_POR_PAGINA;
            $params[] = $pagination['offset'];

            $resultados = db()->fetchAll(
                "SELECT n.id, n.titulo, n.slug, n.resumen, n.imagen,
                        n.vistas, n.fecha_publicacion, n.tiempo_lectura,
                        n.breaking, n.es_premium,
                        u.nombre AS autor_nombre,
                        c.nombre AS cat_nombre, c.slug AS cat_slug,
                        c.color AS cat_color
                 FROM noticias n
                 INNER JOIN usuarios   u ON u.id = n.autor_id
                 INNER JOIN categorias c ON c.id = n.categoria_id
                 WHERE n.estado = 'publicado'
                   AND n.fecha_publicacion <= NOW()
                   AND n.id IN ($placeholders)
                   $whereExtra
                 ORDER BY $orderSql
                 LIMIT ? OFFSET ?",
                $params
            );
        } else {
            // Búsqueda normal con FTS
            $resultados = buscarNoticias(
                $q,
                $categoriaId,
                $fechaDesde ?: null,
                $fechaHasta ?: null,
                NOTICIAS_POR_PAGINA,
                $pagination['offset']
            );
        }
    }
}

// ── Favoritos del usuario ─────────────────────────────────────
$favoritosIds = [];
if ($usuario) {
    $favs = db()->fetchAll(
        "SELECT noticia_id FROM favoritos WHERE usuario_id = ?",
        [$usuario['id']]
    );
    $favoritosIds = array_column($favs, 'noticia_id');
}

// ── Búsquedas populares (tags) ────────────────────────────────
$tagsPopulares = getTagsPopulares(16);

// ── Categorías con más noticias ───────────────────────────────
$catsDestacadas = array_slice($categorias, 0, 6);

// ── URL base para paginación ──────────────────────────────────
$queryParams = array_filter([
    'q'         => $q,
    'categoria' => $categoriaSlug,
    'desde'     => $fechaDesde,
    'hasta'     => $fechaHasta,
    'tag'       => $tagSlug,
    'orden'     => $orden !== 'relevancia' ? $orden : '',
]);
$baseUrl = APP_URL . '/buscar.php?' . http_build_query($queryParams);

// ── SEO ───────────────────────────────────────────────────────
if (!empty($q)) {
    $pageTitle = 'Resultados para "' . e($q) . '" — ' .
                 Config::get('site_nombre', APP_NAME);
} elseif ($tagActivo) {
    $pageTitle = '#' . e($tagActivo['nombre']) . ' — ' .
                 Config::get('site_nombre', APP_NAME);
} elseif ($categoriaActiva) {
    $pageTitle = e($categoriaActiva['nombre']) . ' — ' .
                 Config::get('site_nombre', APP_NAME);
} else {
    $pageTitle = 'Búsqueda — ' . Config::get('site_nombre', APP_NAME);
}
$pageDescription = 'Busca noticias en ' .
                   Config::get('site_nombre', APP_NAME) .
                   '. Filtra por categoría, fecha, etiquetas y más.';
$bodyClass = 'page-buscar';

require_once __DIR__ . '/includes/header.php';
?>

<!-- ── HERO DE BÚSQUEDA ──────────────────────────────────────── -->
<div class="search-hero">
    <div class="container-fluid px-3 px-lg-4">
        <h1 class="search-hero__title">
            <?php if (!empty($q)): ?>
                Resultados para
                <span style="color:var(--primary-light)">"<?= e($q) ?>"</span>
            <?php elseif ($tagActivo): ?>
                <span style="color:var(--primary-light)">
                    #<?= e($tagActivo['nombre']) ?>
                </span>
            <?php elseif ($categoriaActiva): ?>
                <i class="bi <?= e($categoriaActiva['icono'] ?? 'bi-tag') ?>"></i>
                <?= e($categoriaActiva['nombre']) ?>
            <?php else: ?>
                <i class="bi bi-search"></i>
                Buscador de Noticias
            <?php endif; ?>
        </h1>

        <!-- Formulario de búsqueda principal -->
        <form action="<?= APP_URL ?>/buscar.php"
              method="GET"
              class="search-hero__form"
              id="searchForm"
              role="search">
            <div style="position:relative;flex:1">
                <input type="search"
                       name="q"
                       id="searchInput"
                       class="search-hero__input"
                       value="<?= e($q) ?>"
                       placeholder="¿Qué noticia buscas?"
                       autocomplete="off"
                       maxlength="200"
                       aria-label="Buscar noticias">
                <!-- Sugerencias autocomplete -->
                <div id="searchSuggestions"
                     style="display:none;position:absolute;top:calc(100% + 4px);
                            left:0;right:0;background:var(--bg-surface);
                            border:1px solid var(--border-color);
                            border-radius:var(--border-radius-lg);
                            box-shadow:var(--shadow-xl);z-index:999;
                            max-height:360px;overflow-y:auto">
                </div>
            </div>
            <button type="submit" class="search-hero__btn">
                <i class="bi bi-search"></i>
                <span>Buscar</span>
            </button>
        </form>

        <!-- Tags populares como accesos rápidos -->
        <?php if (!empty($tagsPopulares)): ?>
        <div style="margin-top:20px;display:flex;flex-wrap:wrap;
                    gap:8px;justify-content:center">
            <span style="color:rgba(255,255,255,.5);
                          font-size:.78rem;margin-right:4px;
                          align-self:center">
                Populares:
            </span>
            <?php foreach (array_slice($tagsPopulares, 0, 8) as $tag): ?>
            <a href="<?= APP_URL ?>/buscar.php?tag=<?= e($tag['slug']) ?>"
               style="padding:5px 14px;background:rgba(255,255,255,.1);
                      color:rgba(255,255,255,.85);border-radius:var(--border-radius-full);
                      font-size:.78rem;font-weight:600;text-decoration:none;
                      border:1px solid rgba(255,255,255,.15);
                      transition:all .2s ease;
                      <?= $tagSlug === $tag['slug']
                          ? 'background:var(--primary);border-color:var(--primary);'
                          : '' ?>">
                #<?= e($tag['nombre']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- ── FILTROS AVANZADOS ─────────────────────────────────────── -->
<div class="container-fluid px-3 px-lg-4 mt-6">

    <form action="<?= APP_URL ?>/buscar.php"
          method="GET"
          id="filtersForm">
        <?php if (!empty($q)): ?>
        <input type="hidden" name="q" value="<?= e($q) ?>">
        <?php endif; ?>
        <?php if (!empty($tagSlug)): ?>
        <input type="hidden" name="tag" value="<?= e($tagSlug) ?>">
        <?php endif; ?>

        <div class="search-filters">

            <!-- Categoría -->
            <div class="search-filter-group">
                <label class="search-filter-label">
                    <i class="bi bi-grid-3x3-gap-fill"></i>
                    Categoría
                </label>
                <select name="categoria"
                        class="search-filter-select"
                        onchange="this.form.submit()">
                    <option value="">Todas las categorías</option>
                    <?php foreach ($categorias as $cat): ?>
                    <option value="<?= e($cat['slug']) ?>"
                            <?= $categoriaSlug === $cat['slug'] ? 'selected' : '' ?>>
                        <?= e($cat['nombre']) ?>
                        (<?= formatNumber((int)$cat['total_noticias']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Fecha desde -->
            <div class="search-filter-group">
                <label class="search-filter-label">
                    <i class="bi bi-calendar3"></i>
                    Desde
                </label>
                <input type="date"
                       name="desde"
                       class="search-filter-input"
                       value="<?= e($fechaDesde) ?>"
                       max="<?= date('Y-m-d') ?>"
                       onchange="this.form.submit()">
            </div>

            <!-- Fecha hasta -->
            <div class="search-filter-group">
                <label class="search-filter-label">
                    <i class="bi bi-calendar3"></i>
                    Hasta
                </label>
                <input type="date"
                       name="hasta"
                       class="search-filter-input"
                       value="<?= e($fechaHasta) ?>"
                       max="<?= date('Y-m-d') ?>"
                       onchange="this.form.submit()">
            </div>

            <!-- Orden -->
            <div class="search-filter-group">
                <label class="search-filter-label">
                    <i class="bi bi-sort-down"></i>
                    Ordenar por
                </label>
                <select name="orden"
                        class="search-filter-select"
                        onchange="this.form.submit()">
                    <option value="relevancia"
                            <?= $orden === 'relevancia' ? 'selected' : '' ?>>
                        Relevancia
                    </option>
                    <option value="recientes"
                            <?= $orden === 'recientes' ? 'selected' : '' ?>>
                        Más recientes
                    </option>
                    <option value="populares"
                            <?= $orden === 'populares' ? 'selected' : '' ?>>
                        Más populares
                    </option>
                </select>
            </div>

            <!-- Botón limpiar filtros -->
            <?php if ($busquedaActiva): ?>
            <div class="search-filter-group"
                 style="justify-content:flex-end">
                <label class="search-filter-label">&nbsp;</label>
                <a href="<?= APP_URL ?>/buscar.php<?= !empty($q) ? '?q=' . urlencode($q) : '' ?>"
                   style="display:inline-flex;align-items:center;gap:6px;
                          padding:8px 16px;border-radius:var(--border-radius);
                          border:1px solid var(--border-color);
                          color:var(--text-muted);font-size:.82rem;
                          text-decoration:none;transition:all .2s ease;
                          background:var(--bg-surface)">
                    <i class="bi bi-x-circle"></i>
                    Limpiar filtros
                </a>
            </div>
            <?php endif; ?>

        </div>
    </form>

    <!-- ── CONTENIDO PRINCIPAL + SIDEBAR ─────────────────────── -->
    <div class="main-layout">

        <!-- ── RESULTADOS ────────────────────────────────────── -->
        <div class="main-content">

            <!-- Info de resultados -->
            <?php if ($busquedaActiva): ?>
            <div class="search-results-info"
                 style="display:flex;align-items:center;
                        justify-content:space-between;
                        flex-wrap:wrap;gap:8px;
                        margin-bottom:var(--space-5)">
                <div>
                    <?php if ($total > 0): ?>
                    <span>
                        <strong><?= formatNumber($total) ?></strong>
                        resultado<?= $total !== 1 ? 's' : '' ?>
                        <?php if (!empty($q)): ?>
                        para <strong>"<?= e($q) ?>"</strong>
                        <?php endif; ?>
                        <?php if ($categoriaActiva): ?>
                        en <strong><?= e($categoriaActiva['nombre']) ?></strong>
                        <?php endif; ?>
                        <?php if ($tagActivo): ?>
                        con etiqueta <strong>#<?= e($tagActivo['nombre']) ?></strong>
                        <?php endif; ?>
                        <?php if ($fechaDesde || $fechaHasta): ?>
                        <span style="color:var(--text-muted)">
                            · <?= $fechaDesde ? formatDate($fechaDesde . ' 00:00:00', 'short') : '' ?>
                            <?= ($fechaDesde && $fechaHasta) ? ' — ' : '' ?>
                            <?= $fechaHasta ? formatDate($fechaHasta . ' 00:00:00', 'short') : '' ?>
                        </span>
                        <?php endif; ?>
                    </span>
                    <?php if ($pagination['total_pages'] > 1): ?>
                    <span style="color:var(--text-muted);font-size:.82rem">
                        · Página <?= $paginaActual ?> de <?= $pagination['total_pages'] ?>
                    </span>
                    <?php endif; ?>
                    <?php else: ?>
                    No se encontraron resultados
                    <?php if (!empty($q)): ?>
                    para <strong>"<?= e($q) ?>"</strong>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Chips de filtros activos -->
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <?php if ($categoriaActiva): ?>
                    <a href="<?= APP_URL ?>/buscar.php?q=<?= urlencode($q) ?><?= $tagSlug ? '&tag=' . urlencode($tagSlug) : '' ?>"
                       style="display:inline-flex;align-items:center;gap:5px;
                              padding:4px 10px;background:<?= e($categoriaActiva['color']) ?>20;
                              color:<?= e($categoriaActiva['color']) ?>;
                              border:1px solid <?= e($categoriaActiva['color']) ?>40;
                              border-radius:var(--border-radius-full);
                              font-size:.72rem;font-weight:600;text-decoration:none">
                        <?= e($categoriaActiva['nombre']) ?>
                        <i class="bi bi-x"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($tagActivo): ?>
                    <a href="<?= APP_URL ?>/buscar.php?q=<?= urlencode($q) ?><?= $categoriaSlug ? '&categoria=' . urlencode($categoriaSlug) : '' ?>"
                       style="display:inline-flex;align-items:center;gap:5px;
                              padding:4px 10px;background:var(--accent);
                              color:#fff;border-radius:var(--border-radius-full);
                              font-size:.72rem;font-weight:600;text-decoration:none">
                        #<?= e($tagActivo['nombre']) ?>
                        <i class="bi bi-x"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── RESULTADOS GRID ──────────────────────────── -->
            <?php if ($busquedaActiva && $total > 0): ?>

            <div class="news-grid news-grid--3col stagger-children"
                 id="searchGrid">
                <?php
                $adsFreq = Config::int('ads_frecuencia', 3);
                $counter = 0;
                foreach ($resultados as $n):
                    $counter++;
                    $isFav = in_array($n['id'], $favoritosIds);
                ?>
                <article class="news-card animate-on-scroll"
                         data-id="<?= (int)$n['id'] ?>">
                    <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($n['slug']) ?>"
                       class="news-card__img-wrap" tabindex="-1">
                        <img data-src="<?= e(getImageUrl($n['imagen'])) ?>"
                             src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 9'%3E%3C/svg%3E"
                             alt="<?= e($n['titulo']) ?>"
                             class="news-card__img"
                             loading="lazy">
                        <?php if ($n['breaking'] ?? false): ?>
                        <span class="news-card__badge badge-breaking">
                            <i class="bi bi-broadcast-pin"></i> Breaking
                        </span>
                        <?php endif; ?>
                        <?php if ($n['es_premium'] ?? false): ?>
                        <span class="news-card__badge badge-premium"
                              style="top:auto;bottom:8px;left:8px">
                            <i class="bi bi-star-fill"></i> Premium
                        </span>
                        <?php endif; ?>
                        <?php if ($usuario): ?>
                        <button data-action="toggle-favorite"
                                data-noticia-id="<?= (int)$n['id'] ?>"
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
                        <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($n['cat_slug']) ?>"
                           class="news-card__cat"
                           style="color:<?= e($n['cat_color']) ?>">
                            <?= e($n['cat_nombre']) ?>
                        </a>
                        <h3 class="news-card__title">
                            <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($n['slug']) ?>">
                                <?php
                                // Resaltar término de búsqueda en el título
                                if (!empty($q)) {
                                    $titulo = e($n['titulo']);
                                    $qEscaped = preg_quote(e($q), '/');
                                    $titulo = preg_replace(
                                        '/(' . $qEscaped . ')/i',
                                        '<mark style="background:rgba(230,57,70,.2);
                                               color:var(--primary);
                                               border-radius:3px;padding:0 2px">$1</mark>',
                                        $titulo
                                    );
                                    echo $titulo;
                                } else {
                                    echo e(truncateChars($n['titulo'], 90));
                                }
                                ?>
                            </a>
                        </h3>
                        <?php if (!empty($n['resumen'])): ?>
                        <p class="news-card__excerpt">
                            <?php
                            $resumen = e(truncateChars($n['resumen'], 100));
                            if (!empty($q)) {
                                $qEscaped = preg_quote(e($q), '/');
                                $resumen  = preg_replace(
                                    '/(' . $qEscaped . ')/i',
                                    '<mark style="background:rgba(230,57,70,.15);
                                           color:var(--primary);
                                           border-radius:3px;padding:0 2px">$1</mark>',
                                    $resumen
                                );
                            }
                            echo $resumen;
                            ?>
                        </p>
                        <?php endif; ?>
                        <div class="news-card__meta">
                            <span>
                                <i class="bi bi-person"></i>
                                <?= e($n['autor_nombre']) ?>
                            </span>
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

                <?php
                // Anuncio entre resultados
                if (Config::bool('ads_entre_noticias') && $counter % $adsFreq === 0):
                    $adsInline = getAnuncios('entre_noticias', $categoriaId, 1);
                    if (!empty($adsInline)):
                ?>
                <div class="news-card"
                     style="border:2px dashed var(--border-color);
                            justify-content:center;align-items:center;
                            min-height:200px;background:var(--bg-surface-2)">
                    <?= renderAnuncio($adsInline[0]) ?>
                </div>
                <?php endif; endif; ?>

                <?php endforeach; ?>
            </div>

            <!-- Paginación -->
            <?php if ($pagination['total_pages'] > 1): ?>
            <div style="margin-top:var(--space-8)">
                <?= renderPagination($pagination, $baseUrl) ?>
            </div>
            <?php endif; ?>

            <!-- ── SIN RESULTADOS ─────────────────────────── -->
            <?php elseif ($busquedaActiva && $total === 0): ?>

            <div style="text-align:center;padding:80px 20px;
                        background:var(--bg-surface);
                        border-radius:var(--border-radius-xl);
                        border:2px dashed var(--border-color)">
                <i class="bi bi-search"
                   style="font-size:3.5rem;opacity:.2;
                          display:block;margin-bottom:16px;
                          color:var(--text-muted)"></i>
                <h3 style="font-family:var(--font-serif);
                           font-size:1.3rem;margin-bottom:8px;
                           color:var(--text-primary)">
                    No encontramos resultados
                    <?php if (!empty($q)): ?>
                    para "<?= e($q) ?>"
                    <?php endif; ?>
                </h3>
                <p style="color:var(--text-muted);max-width:400px;
                           margin:0 auto 24px;font-size:.9rem">
                    Intenta con otras palabras, revisa la ortografía
                    o usa términos más generales.
                </p>

                <!-- Sugerencias -->
                <div style="max-width:480px;margin:0 auto">
                    <p style="font-size:.82rem;font-weight:600;
                               color:var(--text-secondary);margin-bottom:12px">
                        Sugerencias:
                    </p>
                    <ul style="text-align:left;font-size:.85rem;
                                color:var(--text-muted);
                                list-style:none;
                                display:flex;flex-direction:column;gap:6px">
                        <li>
                            <i class="bi bi-check-circle"
                               style="color:var(--primary)"></i>
                            Verifica la ortografía
                        </li>
                        <li>
                            <i class="bi bi-check-circle"
                               style="color:var(--primary)"></i>
                            Usa palabras más generales
                        </li>
                        <li>
                            <i class="bi bi-check-circle"
                               style="color:var(--primary)"></i>
                            Prueba con sinónimos
                        </li>
                        <li>
                            <i class="bi bi-check-circle"
                               style="color:var(--primary)"></i>
                            Elimina los filtros de fecha o categoría
                        </li>
                    </ul>

                    <?php if ($busquedaActiva && ($categoriaSlug || $fechaDesde || $fechaHasta)): ?>
                    <div style="margin-top:20px">
                        <a href="<?= APP_URL ?>/buscar.php<?= !empty($q) ? '?q=' . urlencode($q) : '' ?>"
                           style="display:inline-flex;align-items:center;gap:8px;
                                  padding:10px 24px;background:var(--primary);
                                  color:#fff;border-radius:var(--border-radius-full);
                                  font-size:.875rem;font-weight:600;text-decoration:none">
                            <i class="bi bi-x-circle"></i>
                            Buscar sin filtros
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── PÁGINA INICIAL (sin búsqueda) ───────────── -->
            <?php else: ?>

            <!-- Explorar por categorías -->
            <section style="margin-bottom:var(--space-10)">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="bi bi-grid-3x3-gap-fill"
                           style="color:var(--primary);margin-right:8px"></i>
                        Explorar por sección
                    </h2>
                </div>
                <div style="display:grid;
                            grid-template-columns:repeat(auto-fill,minmax(160px,1fr));
                            gap:var(--space-4)">
                    <?php foreach ($catsDestacadas as $cat): ?>
                    <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($cat['slug']) ?>"
                       style="display:flex;flex-direction:column;
                              align-items:center;justify-content:center;
                              gap:12px;padding:var(--space-6);
                              background:var(--bg-surface);
                              border-radius:var(--border-radius-xl);
                              border:2px solid <?= e($cat['color']) ?>33;
                              text-decoration:none;
                              transition:all .25s ease;
                              text-align:center">
                        <div style="width:52px;height:52px;
                                    background:<?= e($cat['color']) ?>18;
                                    border-radius:14px;
                                    display:flex;align-items:center;
                                    justify-content:center;
                                    font-size:1.4rem;
                                    color:<?= e($cat['color']) ?>">
                            <i class="bi <?= e($cat['icono'] ?? 'bi-tag') ?>"></i>
                        </div>
                        <span style="font-size:.875rem;font-weight:700;
                                     color:var(--text-primary)">
                            <?= e($cat['nombre']) ?>
                        </span>
                        <span style="font-size:.72rem;
                                     color:var(--text-muted)">
                            <?= formatNumber((int)$cat['total_noticias']) ?> noticias
                        </span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Noticias recientes como placeholder -->
            <section>
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="bi bi-newspaper"
                           style="color:var(--primary);margin-right:8px"></i>
                        Noticias recientes
                    </h2>
                    <a href="<?= APP_URL ?>/index.php" class="section-link">
                        Ver todas <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
                <?php $recientes = getNoticiasRecientes(6); ?>
                <div class="news-grid news-grid--3col stagger-children">
                    <?php foreach ($recientes as $n): ?>
                    <article class="news-card animate-on-scroll">
                        <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($n['slug']) ?>"
                           class="news-card__img-wrap" tabindex="-1">
                            <img data-src="<?= e(getImageUrl($n['imagen'])) ?>"
                                 src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 9'%3E%3C/svg%3E"
                                 alt="<?= e($n['titulo']) ?>"
                                 class="news-card__img"
                                 loading="lazy">
                        </a>
                        <div class="news-card__body">
                            <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($n['cat_slug']) ?>"
                               class="news-card__cat"
                               style="color:<?= e($n['cat_color']) ?>">
                                <?= e($n['cat_nombre']) ?>
                            </a>
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
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php endif; ?>

        </div><!-- /.main-content -->

        <!-- ── SIDEBAR ────────────────────────────────────── -->
        <aside class="sidebar" role="complementary">

            <!-- Widget: Búsquedas populares -->
            <?php if (!empty($tagsPopulares)): ?>
            <div class="widget">
                <div class="widget__header">
                    <h3 class="widget__title">
                        <i class="bi bi-hash"
                           style="color:var(--primary)"></i>
                        Temas Populares
                    </h3>
                </div>
                <div class="widget__body">
                    <div class="tags-cloud">
                        <?php foreach ($tagsPopulares as $tag): ?>
                        <a href="<?= APP_URL ?>/buscar.php?tag=<?= e($tag['slug']) ?>"
                           class="tag-pill"
                           style="<?= $tagSlug === $tag['slug']
                               ? 'background:var(--primary);color:#fff;'
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

            <!-- Widget: Categorías -->
            <div class="widget">
                <div class="widget__header">
                    <h3 class="widget__title">
                        <i class="bi bi-grid-3x3-gap-fill"
                           style="color:var(--primary)"></i>
                        Categorías
                    </h3>
                </div>
                <div class="widget__body"
                     style="padding:var(--space-2) var(--space-4)">
                    <?php foreach ($categorias as $cat): ?>
                    <a href="<?= APP_URL ?>/buscar.php?categoria=<?= e($cat['slug']) ?><?= !empty($q) ? '&q=' . urlencode($q) : '' ?>"
                       style="display:flex;align-items:center;gap:10px;
                              padding:8px 0;
                              border-bottom:1px solid var(--border-color);
                              color:<?= $categoriaSlug === $cat['slug'] ? e($cat['color']) : 'var(--text-secondary)' ?>;
                              font-size:.82rem;font-weight:<?= $categoriaSlug === $cat['slug'] ? '700' : '400' ?>;
                              text-decoration:none;
                              transition:all .2s ease;
                              background:<?= $categoriaSlug === $cat['slug'] ? e($cat['color']) . '10' : 'transparent' ?>;
                              border-radius:var(--border-radius-sm);
                              padding-left:<?= $categoriaSlug === $cat['slug'] ? '8px' : '0' ?>">
                        <span style="width:8px;height:8px;border-radius:50%;
                                     background:<?= e($cat['color']) ?>;
                                     flex-shrink:0"></span>
                        <i class="bi <?= e($cat['icono'] ?? 'bi-tag') ?>"
                           style="color:<?= e($cat['color']) ?>;font-size:.9rem"></i>
                        <span style="flex:1"><?= e($cat['nombre']) ?></span>
                        <span style="font-size:.7rem;color:var(--text-muted)">
                            <?= formatNumber((int)$cat['total_noticias']) ?>
                        </span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Widget: Búsquedas rápidas -->
            <div class="widget">
                <div class="widget__header">
                    <h3 class="widget__title">
                        <i class="bi bi-lightning-charge-fill"
                           style="color:var(--warning)"></i>
                        Búsquedas rápidas
                    </h3>
                </div>
                <div class="widget__body"
                     style="display:flex;flex-direction:column;gap:6px">
                    <?php
                    $terminos = ['Breaking News','Política','Economía',
                                 'Tecnología','Deportes','Salud',
                                 'Cultura','Internacional'];
                    foreach ($terminos as $term):
                    ?>
                    <a href="<?= APP_URL ?>/buscar.php?q=<?= urlencode($term) ?>"
                       style="display:flex;align-items:center;gap:8px;
                              padding:8px 10px;border-radius:var(--border-radius);
                              color:var(--text-secondary);font-size:.82rem;
                              text-decoration:none;
                              border:1px solid var(--border-color);
                              background:var(--bg-surface);
                              transition:all .2s ease">
                        <i class="bi bi-search"
                           style="color:var(--text-muted);font-size:.8rem"></i>
                        <?= e($term) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Widget: Noticias recientes (sidebar) -->
            <?php $recSidebar = getNoticiasRecientes(4); ?>
            <?php if (!empty($recSidebar)): ?>
            <div class="widget">
                <div class="widget__header">
                    <h3 class="widget__title">
                        <i class="bi bi-clock-fill"
                           style="color:var(--accent)"></i>
                        Lo más reciente
                    </h3>
                </div>
                <div class="widget__body"
                     style="padding:var(--space-3) var(--space-4)">
                    <?php foreach ($recSidebar as $rs): ?>
                    <div class="news-card news-card--compact">
                        <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($rs['slug']) ?>"
                           class="news-card__img-wrap">
                            <img data-src="<?= e(getImageUrl($rs['imagen'])) ?>"
                                 src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 4 3'%3E%3C/svg%3E"
                                 alt="<?= e($rs['titulo']) ?>"
                                 class="news-card__img"
                                 loading="lazy">
                        </a>
                        <div class="news-card__body">
                            <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($rs['cat_slug']) ?>"
                               class="news-card__cat"
                               style="color:<?= e($rs['cat_color']) ?>;font-size:.65rem">
                                <?= e($rs['cat_nombre']) ?>
                            </a>
                            <h4 class="news-card__title"
                                style="font-size:.8rem">
                                <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($rs['slug']) ?>">
                                    <?= e(truncateChars($rs['titulo'], 65)) ?>
                                </a>
                            </h4>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </aside><!-- /.sidebar -->

    </div><!-- /.main-layout -->
</div><!-- /.container -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── Autocomplete búsqueda ─────────────────────────────────
    const searchInput = document.getElementById('searchInput');
    const suggestions = document.getElementById('searchSuggestions');
    let searchTimeout = null;

    searchInput?.addEventListener('input', function () {
        clearTimeout(searchTimeout);
        const q = this.value.trim();

        if (q.length < 2) {
            suggestions.style.display = 'none';
            return;
        }

        searchTimeout = setTimeout(async () => {
            try {
                const res  = await fetch(window.APP.url + '/ajax/handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type':     'application/json',
                        'X-CSRF-Token':     window.APP.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ action: 'search_suggest', q }),
                });
                const data = await res.json();

                if (data.results?.length > 0) {
                    suggestions.innerHTML = data.results.map(r => `
                        <a href="${window.APP.url}/noticia.php?slug=${PDApp.escapeHtml(r.slug)}"
                           style="display:flex;align-items:center;gap:12px;
                                  padding:10px 16px;color:var(--text-secondary);
                                  text-decoration:none;transition:background .15s ease;
                                  border-bottom:1px solid var(--border-color)">
                            <img src="${PDApp.escapeHtml(r.imagen)}"
                                 alt="" width="44" height="36"
                                 style="border-radius:6px;object-fit:cover;flex-shrink:0">
                            <div style="min-width:0">
                                <span style="display:block;font-size:.82rem;
                                             font-weight:600;color:var(--text-primary);
                                             white-space:nowrap;overflow:hidden;
                                             text-overflow:ellipsis">
                                    ${PDApp.escapeHtml(r.titulo)}
                                </span>
                                <span style="font-size:.7rem;font-weight:700;
                                             color:${PDApp.escapeHtml(r.cat_color)}">
                                    ${PDApp.escapeHtml(r.cat_nombre)}
                                </span>
                            </div>
                        </a>
                    `).join('');
                    suggestions.innerHTML += `
                        <a href="${window.APP.url}/buscar.php?q=${encodeURIComponent(q)}"
                           style="display:flex;align-items:center;gap:8px;
                                  padding:10px 16px;color:var(--primary);
                                  font-size:.82rem;font-weight:600;
                                  text-decoration:none;
                                  background:var(--bg-surface-2)">
                            <i class="bi bi-search"></i>
                            Ver todos los resultados para "${PDApp.escapeHtml(q)}"
                        </a>`;
                    suggestions.style.display = 'block';
                } else {
                    suggestions.style.display = 'none';
                }
            } catch {
                suggestions.style.display = 'none';
            }
        }, 300);
    });

    // Cerrar sugerencias al hacer click fuera
    document.addEventListener('click', function (e) {
        if (!e.target.closest('#searchForm')) {
            suggestions.style.display = 'none';
        }
    });

    // Cerrar con Escape
    searchInput?.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            suggestions.style.display = 'none';
        }
    });

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
            el.style.transition = `opacity 0.4s ease ${Math.min(i, 8) * 50}ms,
                                   transform 0.4s ease ${Math.min(i, 8) * 50}ms`;
            obs.observe(el);
        });
    }

    // ── Lazy loading imágenes ─────────────────────────────────
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

    // ── Focus en búsqueda si hay query ────────────────────────
    const hasQuery = '<?= !empty($q) ? '1' : '0' ?>';
    if (!hasQuery && searchInput) {
        searchInput.focus();
    }

});
</script>