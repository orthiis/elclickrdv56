<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Página de Noticia Individual
 * ============================================================
 * Archivo : noticia.php
 * Versión : 2.0.0
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ── Obtener noticia por slug ──────────────────────────────────
$slug = cleanInput($_GET['slug'] ?? '');

if (empty($slug)) {
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

$noticia = getNoticiaPorSlug($slug);

// ── 404 si no existe ──────────────────────────────────────────
if (!$noticia) {
    http_response_code(404);
    $pageTitle = 'Noticia no encontrada — ' . APP_NAME;
    $bodyClass = 'page-404';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="container-fluid px-3 px-lg-4" style="padding:80px 0;text-align:center">
        <div style="max-width:480px;margin:0 auto">
            <div style="font-size:5rem;margin-bottom:20px">📰</div>
            <h1 style="font-family:var(--font-serif);font-size:2rem;margin-bottom:12px">
                Noticia no encontrada
            </h1>
            <p style="color:var(--text-muted);margin-bottom:28px">
                La noticia que buscas no existe, fue eliminada o la URL es incorrecta.
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

// ── Verificar acceso premium ──────────────────────────────────
$usuario = currentUser();
if (($noticia['es_premium'] ?? 0) && !isPremium() && !isAdmin()) {
    // Mostrar teaser (primeros 300 chars) y paywall
    $mostrarPaywall = true;
    $contenidoTeaser = mb_substr(strip_tags($noticia['contenido']), 0, 300) . '...';
} else {
    $mostrarPaywall  = false;
    $contenidoTeaser = '';
}

// ── Registrar visita ──────────────────────────────────────────
registrarVisita((int)$noticia['id']);

// ── Datos relacionados ────────────────────────────────────────
$tags             = getTagsNoticia((int)$noticia['id']);
$relacionadas     = getNoticiasRelacionadas(
    (int)$noticia['id'],
    (int)$noticia['categoria_id'],
    4
);
$comentarios      = getComentarios(
    (int)$noticia['id'],
    COMENTARIOS_POR_PAGINA
);
$totalComentarios = countComentarios((int)$noticia['id']);
$esFavorito       = $usuario
    ? esFavorito((int)$usuario['id'], (int)$noticia['id'])
    : false;

// ── Reacciones ────────────────────────────────────────────────
$reacciones = getReaccionesNoticia(
    (int)$noticia['id'],
    $usuario ? (int)$usuario['id'] : null
);

// ── Encuesta (si tiene) ───────────────────────────────────────
$encuesta = ($noticia['tiene_encuesta'] ?? 0)
    ? getEncuestaNoticia((int)$noticia['id'])
    : null;

// ── Sidebar: noticias de la misma categoría ───────────────────
$noticiasCategoria = db()->fetchAll(
    "SELECT n.id, n.titulo, n.slug, n.imagen,
            n.fecha_publicacion, n.vistas, n.tiempo_lectura,
            c.nombre AS cat_nombre, c.color AS cat_color
     FROM noticias n
     INNER JOIN categorias c ON c.id = n.categoria_id
     WHERE n.categoria_id = ?
       AND n.id != ?
       AND n.estado = 'publicado'
       AND n.fecha_publicacion <= NOW()
     ORDER BY n.fecha_publicacion DESC
     LIMIT 5",
    [$noticia['categoria_id'], $noticia['id']]
);

// ── Trending sidebar ──────────────────────────────────────────
$trending = getNoticiasTrending(5);

// ── Botones de compartir ──────────────────────────────────────
$shareButtons = getShareButtons($noticia);

// ── Tiempo de lectura ─────────────────────────────────────────
$tiempoLectura = (int)($noticia['tiempo_lectura'] ?? 0) ?: calcularTiempoLectura($noticia['contenido']);

// ── Anuncios ──────────────────────────────────────────────────
$anuncioSidebar  = getAnuncios('sidebar',     (int)$noticia['categoria_id'], 1);
$anuncioArticulo = getAnuncios('in_article',  (int)$noticia['categoria_id'], 1);

// ── Tags para SEO ─────────────────────────────────────────────
$keywordsArray = array_column($tags, 'nombre');
$keywordsArray[] = $noticia['cat_nombre'];
$keywords = implode(', ', array_filter($keywordsArray));

// ── Schema.org JSON-LD ────────────────────────────────────────
$schema = generateNewsSchema($noticia);

// ── SEO Meta ──────────────────────────────────────────────────
$pageTitle       = e($noticia['titulo']) . ' — ' . Config::get('site_nombre', APP_NAME);
$pageDescription = !empty($noticia['resumen'])
    ? truncateChars($noticia['resumen'], 160)
    : truncateChars(strip_tags($noticia['contenido']), 160);
$pageImage       = !empty($noticia['imagen'])
    ? getImageUrl($noticia['imagen'])
    : IMG_DEFAULT_OG;
$bodyClass       = 'page-noticia';
$noticiaData     = $noticia;
$noticiaData['keywords'] = $keywords;
$pageSchema      = $schema;

require_once __DIR__ . '/includes/header.php';
?>

<!-- Barra de progreso de lectura -->
<div id="readingProgressBar"
     style="position:fixed;top:0;left:0;height:3px;
            background:var(--primary);width:0%;
            z-index:9999;transition:width .1s linear;
            box-shadow:0 0 8px var(--primary)">
</div>

<div class="container-fluid px-3 px-lg-4 mt-6"
     data-page="noticia">
    <div class="main-layout">

        <!-- ── ARTÍCULO PRINCIPAL ────────────────────────────── -->
        <article id="article-main"
                 data-noticia-id="<?= (int)$noticia['id'] ?>"
                 itemscope
                 itemtype="https://schema.org/NewsArticle">

            <!-- HEADER DEL ARTÍCULO -->
            <header class="article-header">

                <!-- Breadcrumb -->
                <nav aria-label="Ruta de navegación"
                     style="display:flex;align-items:center;gap:6px;
                            font-size:.78rem;color:var(--text-muted);
                            margin-bottom:16px;flex-wrap:wrap">
                    <a href="<?= APP_URL ?>/index.php"
                       style="color:var(--text-muted);text-decoration:none">
                        <i class="bi bi-house-fill"></i> Inicio
                    </a>
                    <i class="bi bi-chevron-right" style="font-size:.6rem"></i>
                    <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($noticia['cat_slug']) ?>"
                       style="color:<?= e($noticia['cat_color']) ?>;
                              font-weight:600;text-decoration:none">
                        <?= e($noticia['cat_nombre']) ?>
                    </a>
                    <i class="bi bi-chevron-right" style="font-size:.6rem"></i>
                    <span style="color:var(--text-primary)">
                        <?= e(truncateChars($noticia['titulo'], 50)) ?>
                    </span>
                </nav>

                <!-- Badge de categoría -->
                <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($noticia['cat_slug']) ?>"
                   class="article-cat-badge"
                   style="background:<?= e($noticia['cat_color']) ?>">
                    <i class="bi <?= e($noticia['cat_icono'] ?? 'bi-tag') ?>"></i>
                    <?= e($noticia['cat_nombre']) ?>
                </a>

                <?php if ($noticia['breaking']): ?>
                <span class="news-card__badge badge-breaking"
                      style="display:inline-flex;margin-left:8px;
                             vertical-align:middle">
                    <i class="bi bi-broadcast-pin"></i> Breaking News
                </span>
                <?php endif; ?>

                <?php if ($noticia['es_premium'] ?? false): ?>
                <span class="news-card__badge badge-premium"
                      style="display:inline-flex;margin-left:8px;
                             vertical-align:middle">
                    <i class="bi bi-star-fill"></i> Premium
                </span>
                <?php endif; ?>

                <!-- Título -->
                <h1 class="article-title" itemprop="headline">
                    <?= e($noticia['titulo']) ?>
                </h1>

                <!-- Resumen -->
                <?php if (!empty($noticia['resumen'])): ?>
                <p class="article-summary" itemprop="description">
                    <?= e($noticia['resumen']) ?>
                </p>
                <?php endif; ?>

                <!-- Meta del artículo -->
                <div class="article-meta">
                    <!-- Autor -->
                    <div class="article-author"
                         itemscope
                         itemtype="https://schema.org/Person">
                        <img src="<?= e(getImageUrl($noticia['autor_avatar'] ?? '', 'avatar')) ?>"
                             alt="<?= e($noticia['autor_nombre']) ?>"
                             class="article-author__avatar"
                             itemprop="image"
                             width="44" height="44">
                        <div>
                            <span class="article-author__name" itemprop="name">
                                <?= e($noticia['autor_nombre']) ?>
                                <?php if ($noticia['autor_verificado'] ?? false): ?>
                                <i class="bi bi-patch-check-fill"
                                   style="color:var(--info);font-size:.8rem"
                                   title="Verificado"></i>
                                <?php endif; ?>
                            </span>
                            <?php if (!empty($noticia['autor_bio'])): ?>
                            <span class="article-author__role">
                                <?= e(truncateChars($noticia['autor_bio'], 50)) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Fecha -->
                    <div class="article-meta-item">
                        <i class="bi bi-calendar3"></i>
                        <time datetime="<?= e($noticia['fecha_publicacion']) ?>"
                              itemprop="datePublished">
                            <?= formatDate($noticia['fecha_publicacion'], 'full') ?>
                        </time>
                    </div>

                    <!-- Tiempo de lectura -->
                    <div class="article-meta-item">
                        <i class="bi bi-book"></i>
                        <span><?= $tiempoLectura ?> min de lectura</span>
                    </div>

                    <!-- Vistas -->
                    <div class="article-meta-item">
                        <i class="bi bi-eye"></i>
                        <span id="viewCount">
                            <?= formatNumber((int)$noticia['vistas']) ?>
                        </span> vistas
                    </div>

                    <!-- Comentarios -->
                    <div class="article-meta-item">
                        <i class="bi bi-chat-dots"></i>
                        <a href="#comentarios"
                           style="color:inherit;text-decoration:none">
                            <span id="commentCount"><?= $totalComentarios ?></span>
                            comentario<?= $totalComentarios !== 1 ? 's' : '' ?>
                        </a>
                    </div>

                    <!-- Acciones rápidas -->
                    <div style="margin-left:auto;display:flex;align-items:center;gap:8px">
                        <!-- Favorito -->
                        <button data-action="toggle-favorite"
                                data-noticia-id="<?= (int)$noticia['id'] ?>"
                                id="favBtn"
                                title="<?= $esFavorito ? 'Quitar de guardados' : 'Guardar noticia' ?>"
                                aria-label="Guardar noticia"
                                style="display:flex;align-items:center;gap:6px;
                                       padding:8px 14px;border-radius:var(--border-radius-full);
                                       border:2px solid <?= $esFavorito ? 'var(--primary)' : 'var(--border-color)' ?>;
                                       color:<?= $esFavorito ? 'var(--primary)' : 'var(--text-muted)' ?>;
                                       background:<?= $esFavorito ? 'rgba(230,57,70,.08)' : 'transparent' ?>;
                                       cursor:pointer;font-size:.82rem;
                                       font-weight:600;transition:all .2s ease">
                            <i class="bi <?= $esFavorito ? 'bi-bookmark-fill' : 'bi-bookmark' ?>"></i>
                            <span class="d-none d-sm-inline">
                                <?= $esFavorito ? 'Guardado' : 'Guardar' ?>
                            </span>
                        </button>

                        <!-- Modo lectura -->
                        <button id="readingModeBtn"
                                title="Modo lectura"
                                style="display:flex;align-items:center;gap:6px;
                                       padding:8px 14px;border-radius:var(--border-radius-full);
                                       border:2px solid var(--border-color);
                                       color:var(--text-muted);background:transparent;
                                       cursor:pointer;font-size:.82rem;
                                       font-weight:600;transition:all .2s ease">
                            <i class="bi bi-book"></i>
                            <span class="d-none d-sm-inline">Leer</span>
                        </button>

                        <!-- Compartir nativo -->
                        <?php if (!empty($noticia['slug'])): ?>
                        <button onclick="window.nativeShare('<?= e(addslashes($noticia['titulo'])) ?>', '<?= APP_URL ?>/noticia.php?slug=<?= e($noticia['slug']) ?>')"
                                title="Compartir"
                                style="display:flex;align-items:center;gap:6px;
                                       padding:8px 14px;border-radius:var(--border-radius-full);
                                       border:2px solid var(--border-color);
                                       color:var(--text-muted);background:transparent;
                                       cursor:pointer;font-size:.82rem;transition:all .2s ease">
                            <i class="bi bi-share-fill"></i>
                            <span class="d-none d-sm-inline">Compartir</span>
                        </button>
                        <?php endif; ?>
                    </div>
                </div><!-- /.article-meta -->

            </header><!-- /.article-header -->

            <!-- IMAGEN PRINCIPAL -->
            <?php if (!empty($noticia['imagen'])): ?>
            <figure style="margin:0 0 var(--space-8) 0">
                <img src="<?= e(getImageUrl($noticia['imagen'])) ?>"
                     alt="<?= e($noticia['titulo']) ?>"
                     class="article-hero-img"
                     itemprop="image"
                     loading="eager"
                     fetchpriority="high">
                <?php if (!empty($noticia['imagen_caption'] ?? '')): ?>
                <figcaption style="font-size:.78rem;color:var(--text-muted);
                                   text-align:center;margin-top:8px;font-style:italic">
                    <?= e($noticia['imagen_caption']) ?>
                </figcaption>
                <?php endif; ?>
            </figure>
            <?php endif; ?>

            <!-- TTS PLAYER -->
            <div class="tts-player" id="ttsPlayerWrap">
                <i class="bi bi-headphones"
                   style="font-size:1.2rem;color:var(--primary)"></i>
                <div style="flex:1">
                    <strong style="font-size:.82rem;color:var(--text-primary)">
                        Escuchar noticia
                    </strong>
                    <span style="display:block;font-size:.72rem;color:var(--text-muted)">
                        Text-to-Speech · <?= $tiempoLectura ?> min aprox.
                    </span>
                </div>
                <button id="ttsPlayBtn"
                        class="tts-play-btn">
                    <i class="bi bi-play-fill"></i> Escuchar
                </button>
                <button id="ttsStopBtn"
                        title="Detener"
                        style="padding:8px;border-radius:50%;
                               border:1px solid var(--border-color);
                               color:var(--text-muted);transition:all .2s ease">
                    <i class="bi bi-stop-fill"></i>
                </button>
                <div class="tts-speed">
                    <label for="ttsSpeed"
                           style="font-size:.72rem">Vel.</label>
                    <select id="ttsSpeed">
                        <option value="0.75">0.75x</option>
                        <option value="1" selected>1x</option>
                        <option value="1.25">1.25x</option>
                        <option value="1.5">1.5x</option>
                    </select>
                </div>
            </div>

            <!-- ANUNCIO DENTRO DEL ARTÍCULO (antes del contenido) -->
            <?php if (!empty($anuncioArticulo) && Config::bool('ads_dentro_articulo')): ?>
            <div style="margin:var(--space-6) 0;text-align:center">
                <?= renderAnuncio($anuncioArticulo[0]) ?>
            </div>
            <?php endif; ?>

            <!-- ── CONTENIDO DEL ARTÍCULO ──────────────────── -->
            <?php if ($mostrarPaywall): ?>

            <!-- Teaser para contenido premium -->
            <div class="article-content" id="article-content">
                <p><?= e($contenidoTeaser) ?></p>
            </div>

            <!-- Paywall -->
            <div style="position:relative;margin:var(--space-8) 0;
                        border-radius:var(--border-radius-xl);overflow:hidden">
                <!-- Blur overlay -->
                <div style="background:linear-gradient(to bottom,transparent,var(--bg-body) 60%);
                            position:absolute;top:0;left:0;right:0;bottom:0;z-index:1"></div>

                <!-- Paywall card -->
                <div style="background:linear-gradient(135deg,var(--secondary),var(--secondary-dark));
                            color:#fff;padding:var(--space-10);text-align:center;
                            border-radius:var(--border-radius-xl);position:relative;z-index:2">
                    <div style="font-size:3rem;margin-bottom:16px">⭐</div>
                    <h3 style="font-family:var(--font-serif);font-size:1.5rem;
                               color:#fff;margin-bottom:8px">
                        Contenido Exclusivo Premium
                    </h3>
                    <p style="color:rgba(255,255,255,.75);margin-bottom:24px;max-width:400px;margin-inline:auto">
                        Este artículo es exclusivo para suscriptores Premium.
                        Accede a contenido sin límites por solo
                        <strong style="color:#fff">
                            $<?= Config::get('premium_precio_mensual', '4.99') ?>/mes
                        </strong>
                    </p>
                    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
                        <a href="<?= APP_URL ?>/perfil.php?tab=premium"
                           style="display:inline-flex;align-items:center;gap:8px;
                                  padding:12px 28px;background:var(--primary);
                                  color:#fff;border-radius:var(--border-radius-full);
                                  font-weight:700;text-decoration:none;transition:all .2s ease">
                            <i class="bi bi-star-fill"></i>
                            Suscribirme Ahora
                        </a>
                        <?php if (!$usuario): ?>
                        <a href="<?= APP_URL ?>/login.php"
                           style="display:inline-flex;align-items:center;gap:8px;
                                  padding:12px 28px;background:rgba(255,255,255,.15);
                                  color:#fff;border-radius:var(--border-radius-full);
                                  font-weight:600;text-decoration:none">
                            <i class="bi bi-box-arrow-in-right"></i>
                            Ya tengo cuenta
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php else: ?>

            <div class="article-content" id="article-content" itemprop="articleBody">
                <?= $noticia['contenido'] ?>
            </div>

            <?php endif; ?>

            <!-- FUENTE -->
            <?php if (!empty($noticia['fuente'])): ?>
            <p style="font-size:.78rem;color:var(--text-muted);margin-top:var(--space-4);
                      padding-top:var(--space-4);border-top:1px solid var(--border-color)">
                <i class="bi bi-link-45deg"></i>
                <strong>Fuente:</strong> <?= e($noticia['fuente']) ?>
            </p>
            <?php endif; ?>

            <!-- TAGS -->
            <?php if (!empty($tags)): ?>
            <div style="margin:var(--space-6) 0;
                        padding:var(--space-4) 0;
                        border-top:1px solid var(--border-color)">
                <strong style="font-size:.78rem;color:var(--text-muted);
                               text-transform:uppercase;letter-spacing:.06em;
                               margin-right:8px">
                    <i class="bi bi-tags-fill"></i> Etiquetas:
                </strong>
                <?php foreach ($tags as $tag): ?>
                <a href="<?= APP_URL ?>/buscar.php?tag=<?= e($tag['slug']) ?>"
                   class="tag-pill">
                    #<?= e($tag['nombre']) ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ── SECCIÓN DE COMPARTIR ────────────────────── -->
            <div class="share-section" id="shareSection">
                <p class="share-title">
                    <i class="bi bi-share-fill"></i>
                    Compartir esta noticia
                </p>
                <div class="share-buttons">
                    <?php foreach ($shareButtons as $btn): ?>
                    <a href="<?= e($btn['url']) ?>"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="share-btn share-btn--<?= e($btn['red']) ?>"
                       data-action="share"
                       data-noticia-id="<?= (int)$noticia['id'] ?>"
                       data-red="<?= e($btn['red']) ?>"
                       data-url="<?= e($btn['url']) ?>"
                       data-title="<?= e($noticia['titulo']) ?>"
                       onclick="trackAndShare(event, this)"
                       style="background:<?= e($btn['color']) ?>">
                        <i class="bi <?= e($btn['icon']) ?>"></i>
                        <span><?= e($btn['label']) ?></span>
                    </a>
                    <?php endforeach; ?>
                    <!-- Copiar enlace -->
                    <button class="share-btn share-btn--copy"
                            onclick="copyToClipboard('<?= APP_URL ?>/noticia.php?slug=<?= e($noticia['slug']) ?>')"
                            style="background:var(--text-muted)">
                        <i class="bi bi-link-45deg"></i>
                        <span>Copiar</span>
                    </button>
                </div>
            </div>

            <!-- ── REACCIONES ─────────────────────────────── -->
            <div class="reactions-section">
                <p style="font-size:.78rem;color:var(--text-muted);
                           text-transform:uppercase;letter-spacing:.06em;
                           margin-bottom:12px">
                    <i class="bi bi-emoji-smile"></i>
                    ¿Qué te pareció esta noticia?
                    <span id="reactionTotal"
                          style="color:var(--text-primary);font-weight:600">
                        <?= formatNumber($reacciones['total']) ?>
                    </span>
                    reacciones
                </p>
                <div class="reactions-bar" id="reactionsBar">
                    <?php foreach ($reacciones['emojis'] as $tipo => $info): ?>
                    <button class="reaction-btn <?= ($reacciones['mi_reaccion'] === $tipo) ? 'active' : '' ?>"
                            data-noticia-id="<?= (int)$noticia['id'] ?>"
                            data-tipo="<?= e($tipo) ?>"
                            aria-label="<?= e($info['label']) ?>"
                            aria-pressed="<?= ($reacciones['mi_reaccion'] === $tipo) ? 'true' : 'false' ?>"
                            title="<?= e($info['label']) ?>">
                        <span class="reaction-emoji"><?= $info['emoji'] ?></span>
                        <span class="reaction-count">
                            <?php
                            $total = $reacciones['totales'][$tipo] ?? 0;
                            echo $total > 0 ? formatNumber($total) : '';
                            ?>
                        </span>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ── ENCUESTA (si tiene) ───────────────────── -->
            <?php if ($encuesta && !$mostrarPaywall): ?>
            <div class="poll-widget" id="pollWidget"
                 data-encuesta-id="<?= (int)$encuesta['id'] ?>">
                <div class="poll-header">
                    <div class="poll-icon">
                        <i class="bi bi-bar-chart-fill"></i>
                    </div>
                    <h3 class="poll-question"><?= e($encuesta['pregunta']) ?></h3>
                </div>

                <?php
                // Verificar si el usuario ya votó
                $yaVoto = false;
                if ($usuario) {
                    $yaVoto = db()->count(
                        "SELECT COUNT(*) FROM encuesta_votos
                         WHERE encuesta_id = ? AND usuario_id = ?",
                        [$encuesta['id'], $usuario['id']]
                    ) > 0;
                } else {
                    $yaVoto = db()->count(
                        "SELECT COUNT(*) FROM encuesta_votos
                         WHERE encuesta_id = ? AND ip = ?",
                        [$encuesta['id'], getClientIp()]
                    ) > 0;
                }
                ?>

                <div class="poll-options">
                    <?php foreach ($encuesta['opciones'] as $opcion): ?>
                    <?php
                    $pctOpcion = ($encuesta['total_votos'] > 0)
                        ? round(($opcion['votos'] / $encuesta['total_votos']) * 100)
                        : 0;
                    ?>
                    <div class="poll-option">
                        <?php if (!$yaVoto): ?>
                        <input type="radio"
                               name="poll_option"
                               id="opcion_<?= (int)$opcion['id'] ?>"
                               value="<?= (int)$opcion['id'] ?>">
                        <label class="poll-option-label"
                               for="opcion_<?= (int)$opcion['id'] ?>">
                            <div class="poll-bar"
                                 style="width:<?= $pctOpcion ?>%"></div>
                            <span class="poll-option-text">
                                <?= e($opcion['opcion']) ?>
                            </span>
                        </label>
                        <?php else: ?>
                        <div class="poll-option-label">
                            <div class="poll-bar"
                                 style="width:<?= $pctOpcion ?>%"></div>
                            <span class="poll-option-text">
                                <?= e($opcion['opcion']) ?>
                            </span>
                            <span class="poll-pct"><?= $pctOpcion ?>%</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!$yaVoto && $encuesta['activa']): ?>
                <button class="poll-vote-btn">
                    <i class="bi bi-check2-circle"></i>
                    Votar
                </button>
                <?php endif; ?>

                <p class="poll-footer">
                    <i class="bi bi-people-fill"></i>
                    <?= formatNumber((int)$encuesta['total_votos']) ?>
                    voto<?= $encuesta['total_votos'] !== 1 ? 's' : '' ?> en total
                    <?= $yaVoto ? '· Ya votaste' : '' ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- ── AUTOR BIO ──────────────────────────────── -->
            <div style="background:var(--bg-surface-2);
                        border-radius:var(--border-radius-xl);
                        padding:var(--space-6);margin:var(--space-10) 0;
                        display:flex;gap:var(--space-5);align-items:flex-start">
                <img src="<?= e(getImageUrl($noticia['autor_avatar'] ?? '', 'avatar')) ?>"
                     alt="<?= e($noticia['autor_nombre']) ?>"
                     style="width:80px;height:80px;border-radius:50%;
                            object-fit:cover;flex-shrink:0;
                            border:3px solid var(--primary)"
                     loading="lazy">
                <div>
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                        <strong style="font-size:1rem;color:var(--text-primary)">
                            <?= e($noticia['autor_nombre']) ?>
                        </strong>
                        <?php if ($noticia['autor_verificado'] ?? false): ?>
                        <i class="bi bi-patch-check-fill"
                           style="color:var(--info)" title="Verificado"></i>
                        <?php endif; ?>
                        <span style="font-size:.72rem;background:var(--primary);
                                     color:#fff;padding:2px 8px;
                                     border-radius:var(--border-radius-full);
                                     font-weight:700;text-transform:capitalize">
                            <?= e($noticia['autor_rol'] ?? 'Redactor') ?>
                        </span>
                    </div>
                    <?php if (!empty($noticia['autor_bio'])): ?>
                    <p style="font-size:.85rem;color:var(--text-muted);
                               line-height:1.6;margin:0 0 12px">
                        <?= e($noticia['autor_bio']) ?>
                    </p>
                    <?php endif; ?>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <?php if ($usuario && (int)$usuario['id'] !== (int)$noticia['autor_id']): ?>
                        <?php
                        $sigueAutor = sigueA((int)$usuario['id'], (int)$noticia['autor_id']);
                        ?>
                        <button data-action="follow-user"
                                data-user-id="<?= (int)$noticia['autor_id'] ?>"
                                style="display:inline-flex;align-items:center;gap:6px;
                                       padding:6px 16px;border-radius:var(--border-radius-full);
                                       border:2px solid <?= $sigueAutor ? 'var(--primary)' : 'var(--border-color)' ?>;
                                       color:<?= $sigueAutor ? 'var(--primary)' : 'var(--text-muted)' ?>;
                                       background:transparent;cursor:pointer;
                                       font-size:.8rem;font-weight:600;
                                       transition:all .2s ease">
                            <i class="bi bi-person-plus<?= $sigueAutor ? '-fill' : '' ?>"></i>
                            <?= $sigueAutor ? 'Siguiendo' : 'Seguir' ?>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ── NOTICIAS RELACIONADAS ─────────────────── -->
            <?php if (!empty($relacionadas)): ?>
            <section aria-labelledby="relacionadas-title"
                     style="margin:var(--space-10) 0">
                <div class="section-header">
                    <h2 class="section-title" id="relacionadas-title"
                        style="font-size:1.3rem">
                        También te puede interesar
                    </h2>
                </div>
                <div class="news-grid news-grid--<?= min(4, count($relacionadas)) ?>col">
                    <?php foreach ($relacionadas as $rel): ?>
                    <article class="news-card">
                        <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($rel['slug']) ?>"
                           class="news-card__img-wrap">
                            <img data-src="<?= e(getImageUrl($rel['imagen'])) ?>"
                                 src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 9'%3E%3C/svg%3E"
                                 alt="<?= e($rel['titulo']) ?>"
                                 class="news-card__img"
                                 loading="lazy">
                        </a>
                        <div class="news-card__body">
                            <h3 class="news-card__title"
                                style="font-size:.92rem">
                                <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($rel['slug']) ?>">
                                    <?= e(truncateChars($rel['titulo'], 75)) ?>
                                </a>
                            </h3>
                            <div class="news-card__meta">
                                <span>
                                    <i class="bi bi-clock"></i>
                                    <?= timeAgo($rel['fecha_publicacion']) ?>
                                </span>
                                <span>
                                    <i class="bi bi-eye"></i>
                                    <?= formatNumber((int)$rel['vistas']) ?>
                                </span>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <!-- ── SECCIÓN DE COMENTARIOS ─────────────────── -->
            <?php if ($noticia['permitir_comentarios'] ?? 1): ?>
            <section id="comentarios"
                     class="comments-section"
                     aria-labelledby="comments-title">

                <div class="comments-header">
                    <h2 class="comments-title" id="comments-title">
                        <i class="bi bi-chat-dots-fill"
                           style="color:var(--primary)"></i>
                        Comentarios
                        <span style="font-size:.85rem;font-weight:400;
                                     color:var(--text-muted);margin-left:6px">
                            (<span id="commentCount"><?= $totalComentarios ?></span>)
                        </span>
                    </h2>
                </div>

                <!-- Formulario de comentario -->
                <?php if ($usuario): ?>
                <div class="comment-form-wrap">
                    <form id="commentForm" novalidate>
                        <?= csrfField() ?>
                        <input type="hidden"
                               name="noticia_id"
                               value="<?= (int)$noticia['id'] ?>">
                        <input type="hidden" name="padre_id" value="0">

                        <div class="comment-form-inner">
                            <img src="<?= e(getImageUrl($usuario['avatar'], 'avatar')) ?>"
                                 alt="<?= e($usuario['nombre']) ?>"
                                 class="comment-form-avatar"
                                 width="40" height="40">
                            <div class="comment-form-fields" style="flex:1">
                                <textarea name="comentario"
                                          class="comment-textarea"
                                          placeholder="Escribe tu comentario... (mín. 3 caracteres)"
                                          rows="3"
                                          maxlength="2000"
                                          data-auto-resize
                                          required></textarea>
                                <div style="display:flex;justify-content:space-between;
                                            align-items:center;margin-top:8px">
                                    <span class="char-counter"
                                          style="font-size:.72rem;color:var(--text-muted)">
                                        0/2000
                                    </span>
                                    <div class="comment-submit-row" style="margin-top:0">
                                        <button type="submit"
                                                class="comment-submit-btn">
                                            <i class="bi bi-send-fill"></i>
                                            Publicar comentario
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <?php else: ?>
                <div style="background:var(--bg-surface-2);border-radius:var(--border-radius-lg);
                            padding:var(--space-5);text-align:center;margin-bottom:var(--space-6)">
                    <p style="color:var(--text-muted);margin-bottom:12px">
                        <i class="bi bi-lock-fill"></i>
                        Inicia sesión para comentar en esta noticia.
                    </p>
                    <a href="<?= APP_URL ?>/login.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>"
                       style="display:inline-flex;align-items:center;gap:8px;
                              padding:10px 24px;background:var(--primary);
                              color:#fff;border-radius:var(--border-radius-full);
                              font-size:.875rem;font-weight:600;text-decoration:none">
                        <i class="bi bi-box-arrow-in-right"></i>
                        Iniciar Sesión
                    </a>
                </div>
                <?php endif; ?>

                <!-- Lista de comentarios -->
                <div class="comments-list" id="commentsList">
                    <?php if (!empty($comentarios)): ?>
                        <?php foreach ($comentarios as $com): ?>
                        <div class="comment-item"
                             id="comment-<?= (int)$com['id'] ?>">
                            <div class="comment-avatar">
                                <img src="<?= e(getImageUrl($com['usuario_avatar'] ?? '', 'avatar')) ?>"
                                     alt="<?= e($com['usuario_nombre']) ?>"
                                     width="40" height="40"
                                     loading="lazy">
                            </div>
                            <div class="comment-body">
                                <div class="comment-header">
                                    <strong class="comment-author">
                                        <?= e($com['usuario_nombre']) ?>
                                    </strong>
                                    <?php if (in_array($com['usuario_rol'], ['super_admin','admin'])): ?>
                                    <span class="badge-staff">Staff</span>
                                    <?php endif; ?>
                                    <?php if ($com['usuario_verificado'] ?? false): ?>
                                    <i class="bi bi-patch-check-fill"
                                       style="color:var(--info);font-size:.8rem"
                                       title="Verificado"></i>
                                    <?php endif; ?>
                                    <time class="comment-time"
                                          datetime="<?= e($com['fecha']) ?>"
                                          title="<?= e(formatDate($com['fecha'], 'full')) ?>">
                                        <?= timeAgo($com['fecha']) ?>
                                    </time>
                                </div>
                                <p class="comment-text">
                                    <?= nl2br(e($com['comentario'])) ?>
                                </p>
                                <div class="comment-actions">
                                    <?php if ($usuario): ?>
                                    <button class="comment-vote-btn"
                                            data-comentario-id="<?= (int)$com['id'] ?>"
                                            data-tipo="like"
                                            onclick="PDApp.voteComment(this)">
                                        <i class="bi bi-hand-thumbs-up"></i>
                                        <span class="like-count">
                                            <?= (int)$com['likes'] ?>
                                        </span>
                                    </button>
                                    <button class="comment-vote-btn"
                                            data-comentario-id="<?= (int)$com['id'] ?>"
                                            data-tipo="dislike"
                                            onclick="PDApp.voteComment(this)">
                                        <i class="bi bi-hand-thumbs-down"></i>
                                        <span class="dislike-count">
                                            <?= (int)($com['dislikes'] ?? 0) ?>
                                        </span>
                                    </button>
                                    <button class="comment-reply-btn"
                                            data-id="<?= (int)$com['id'] ?>"
                                            data-nombre="<?= e($com['usuario_nombre']) ?>"
                                            onclick="PDApp.showReplyForm(this)">
                                        <i class="bi bi-reply"></i>
                                        Responder
                                    </button>
                                    <?php endif; ?>

                                    <!-- Reportar -->
                                    <button class="comment-report-btn"
                                            onclick="PDApp.reportContent('comentario', <?= (int)$com['id'] ?>)"
                                            title="Reportar comentario">
                                        <i class="bi bi-flag"></i>
                                    </button>

                                    <!-- Eliminar (si es el autor o admin) -->
                                    <?php if ($usuario && ((int)$usuario['id'] === (int)$com['usuario_id'] || isAdmin())): ?>
                                    <button class="comment-delete-btn"
                                            data-id="<?= (int)$com['id'] ?>"
                                            title="Eliminar comentario"
                                            style="margin-left:4px;color:var(--danger);
                                                   background:rgba(239,68,68,.08);
                                                   border-radius:var(--border-radius-full);
                                                   padding:4px 10px;font-size:.72rem">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                    <?php endif; ?>

                                    <span style="margin-left:auto;font-size:.7rem;
                                                 color:var(--text-muted)">
                                        <?= formatDate($com['fecha'], 'short') ?>
                                    </span>
                                </div>

                                <!-- Área de respuesta -->
                                <div class="reply-form-wrap"
                                     id="reply-form-<?= (int)$com['id'] ?>"
                                     style="display:none"></div>

                                <!-- Respuestas anidadas -->
                                <?php if (!empty($com['respuestas'])): ?>
                                <div class="comment-replies">
                                    <?php foreach ($com['respuestas'] as $resp): ?>
                                    <div class="comment-item"
                                         id="comment-<?= (int)$resp['id'] ?>">
                                        <div class="comment-avatar">
                                            <img src="<?= e(getImageUrl($resp['usuario_avatar'] ?? '', 'avatar')) ?>"
                                                 alt="<?= e($resp['usuario_nombre']) ?>"
                                                 width="32" height="32"
                                                 loading="lazy">
                                        </div>
                                        <div class="comment-body"
                                             style="background:var(--bg-surface-2)">
                                            <div class="comment-header">
                                                <strong class="comment-author">
                                                    <?= e($resp['usuario_nombre']) ?>
                                                </strong>
                                                <?php if (in_array($resp['usuario_rol'], ['super_admin','admin'])): ?>
                                                <span class="badge-staff">Staff</span>
                                                <?php endif; ?>
                                                <time class="comment-time"
                                                      datetime="<?= e($resp['fecha']) ?>">
                                                    <?= timeAgo($resp['fecha']) ?>
                                                </time>
                                            </div>
                                            <p class="comment-text">
                                                <?= nl2br(e($resp['comentario'])) ?>
                                            </p>
                                            <div class="comment-actions">
                                                <?php if ($usuario): ?>
                                                <button class="comment-vote-btn"
                                                        data-comentario-id="<?= (int)$resp['id'] ?>"
                                                        data-tipo="like"
                                                        onclick="PDApp.voteComment(this)">
                                                    <i class="bi bi-hand-thumbs-up"></i>
                                                    <span class="like-count">
                                                        <?= (int)$resp['likes'] ?>
                                                    </span>
                                                </button>
                                                <?php endif; ?>
                                                <button class="comment-report-btn"
                                                        onclick="PDApp.reportContent('comentario', <?= (int)$resp['id'] ?>)"
                                                        title="Reportar">
                                                    <i class="bi bi-flag"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>

                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <div style="text-align:center;padding:40px 20px;
                                color:var(--text-muted)">
                        <i class="bi bi-chat-square-dots"
                           style="font-size:2.5rem;opacity:.3;display:block;
                                  margin-bottom:12px"></i>
                        Sé el primero en comentar esta noticia.
                    </div>
                    <?php endif; ?>
                </div><!-- /#commentsList -->

                <!-- Paginación de comentarios -->
                <?php if ($totalComentarios > COMENTARIOS_POR_PAGINA): ?>
                <div style="text-align:center;margin-top:var(--space-6)">
                    <button id="loadMoreComments"
                            data-pagina="2"
                            data-noticia-id="<?= (int)$noticia['id'] ?>"
                            style="display:inline-flex;align-items:center;gap:8px;
                                   padding:10px 24px;border:2px solid var(--border-color);
                                   color:var(--text-secondary);background:transparent;
                                   border-radius:var(--border-radius-full);
                                   cursor:pointer;font-size:.875rem;
                                   font-weight:600;transition:all .2s ease">
                        <i class="bi bi-arrow-down-circle"></i>
                        Cargar más comentarios
                        (<?= $totalComentarios - COMENTARIOS_POR_PAGINA ?> restantes)
                    </button>
                </div>
                <?php endif; ?>

            </section>
            <?php else: ?>
            <div style="padding:var(--space-6);background:var(--bg-surface-2);
                        border-radius:var(--border-radius-lg);text-align:center;
                        margin-top:var(--space-8);color:var(--text-muted)">
                <i class="bi bi-chat-slash" style="font-size:1.5rem"></i>
                <p style="margin-top:8px">Los comentarios están desactivados en esta noticia.</p>
            </div>
            <?php endif; ?>

        </article><!-- /#article-main -->

        <!-- ── SIDEBAR ────────────────────────────────────── -->
        <aside class="sidebar" role="complementary">

            <!-- Widget: Anuncio -->
            <?php if (!empty($anuncioSidebar) && Config::bool('ads_sidebar')): ?>
            <div class="widget">
                <?= renderAnuncio($anuncioSidebar[0]) ?>
            </div>
            <?php endif; ?>

            <!-- Widget: Más de esta categoría -->
            <?php if (!empty($noticiasCategoria)): ?>
            <div class="widget">
                <div class="widget__header">
                    <h3 class="widget__title"
                        style="color:<?= e($noticia['cat_color']) ?>">
                        <i class="bi <?= e($noticia['cat_icono'] ?? 'bi-tag') ?>"></i>
                        Más de <?= e($noticia['cat_nombre']) ?>
                    </h3>
                </div>
                <div class="widget__body"
                     style="padding:var(--space-3) var(--space-4)">
                    <?php foreach ($noticiasCategoria as $cn): ?>
                    <div class="news-card news-card--compact">
                        <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($cn['slug']) ?>"
                           class="news-card__img-wrap">
                            <img data-src="<?= e(getImageUrl($cn['imagen'])) ?>"
                                 src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 4 3'%3E%3C/svg%3E"
                                 alt="<?= e($cn['titulo']) ?>"
                                 class="news-card__img"
                                 loading="lazy">
                        </a>
                        <div class="news-card__body">
                            <h4 class="news-card__title"
                                style="font-size:.82rem">
                                <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($cn['slug']) ?>">
                                    <?= e(truncateChars($cn['titulo'], 70)) ?>
                                </a>
                            </h4>
                            <div class="news-card__meta"
                                 style="font-size:.68rem">
                                <span>
                                    <i class="bi bi-clock"></i>
                                    <?= timeAgo($cn['fecha_publicacion']) ?>
                                </span>
                                <span>
                                    <i class="bi bi-eye"></i>
                                    <?= formatNumber((int)$cn['vistas']) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($noticia['cat_slug']) ?>"
                       style="display:block;text-align:center;padding:10px;
                              color:var(--primary);font-size:.82rem;
                              font-weight:600;border-top:1px solid var(--border-color);
                              margin-top:8px;text-decoration:none">
                        Ver todo en <?= e($noticia['cat_nombre']) ?>
                        <i class="bi bi-arrow-right"></i>
                    </a>
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
                        Lo más visto
                    </h3>
                </div>
                <div class="widget__body"
                     style="padding:0 var(--space-4)">
                    <?php foreach (array_slice($trending, 0, 5) as $idx => $tr): ?>
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

            <!-- Widget: Tags -->
            <?php if (!empty($tags)): ?>
            <div class="widget">
                <div class="widget__header">
                    <h3 class="widget__title">
                        <i class="bi bi-tags-fill"
                           style="color:var(--accent)"></i>
                        Etiquetas
                    </h3>
                </div>
                <div class="widget__body">
                    <div class="tags-cloud">
                        <?php foreach ($tags as $tag): ?>
                        <a href="<?= APP_URL ?>/buscar.php?tag=<?= e($tag['slug']) ?>"
                           class="tag-pill">
                            #<?= e($tag['nombre']) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Widget: Newsletter -->
            <div class="widget widget-newsletter">
                <div class="widget__header">
                    <h3 class="widget__title">
                        <i class="bi bi-envelope-paper-fill"
                           style="color:var(--primary)"></i>
                        Boletín
                    </h3>
                </div>
                <div class="widget__body">
                    <p style="font-size:.82rem;color:var(--text-muted);
                               margin-bottom:12px">
                        Recibe las noticias más importantes cada mañana.
                    </p>
                    <form class="newsletter-widget-form"
                          id="nlSidebarForm">
                        <?= csrfField() ?>
                        <input type="email"
                               name="email"
                               placeholder="tu@correo.com"
                               required
                               class="form-input"
                               style="margin-bottom:8px">
                        <button type="submit" class="subscribe-btn w-full">
                            <i class="bi bi-send-fill"></i>
                            Suscribirme
                        </button>
                        <div id="nlSidebarMsg"
                             style="display:none;font-size:.75rem;
                                    padding:6px 10px;border-radius:6px;
                                    margin-top:8px"></div>
                    </form>
                </div>
            </div>

        </aside><!-- /.sidebar -->

    </div><!-- /.main-layout -->
</div><!-- /.container -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── Barra de progreso de lectura ──────────────────────────
    const progressBar = document.getElementById('readingProgressBar');
    if (progressBar) {
        window.addEventListener('scroll', function () {
            const content = document.getElementById('article-content');
            if (!content) return;
            const rect    = content.getBoundingClientRect();
            const total   = content.offsetHeight;
            const seen    = Math.max(0, -rect.top);
            const pct     = Math.min(100, (seen / (total - window.innerHeight + 200)) * 100);
            progressBar.style.width = Math.max(0, pct) + '%';
        }, { passive: true });
    }

    // ── TTS ───────────────────────────────────────────────────
    const ttsPlayBtn = document.getElementById('ttsPlayBtn');
    const ttsStopBtn = document.getElementById('ttsStopBtn');
    const ttsSpeed   = document.getElementById('ttsSpeed');

    if (ttsPlayBtn && window.speechSynthesis) {
        window.TTSPlayer.init('article-content');

        ttsPlayBtn.addEventListener('click', function () {
            if (window.TTSPlayer.playing) {
                window.TTSPlayer.pause();
            } else {
                window.TTSPlayer.play();
            }
        });

        ttsStopBtn?.addEventListener('click', () => window.TTSPlayer.stop());
        ttsSpeed?.addEventListener('change', () => {
            window.TTSPlayer.setSpeed(ttsSpeed.value);
        });
    } else if (document.getElementById('ttsPlayerWrap')) {
        document.getElementById('ttsPlayerWrap').style.display = 'none';
    }

    // ── Favorito ──────────────────────────────────────────────
    const favBtn = document.getElementById('favBtn');
    favBtn?.addEventListener('click', async function () {
        if (!window.APP.userId) {
            PDApp.showToast('Inicia sesión para guardar noticias.', 'warning');
            setTimeout(() => {
                window.location.href = window.APP.url + '/login.php';
            }, 1200);
            return;
        }

        const noticiaId = this.dataset.noticiaId;
        const icon = this.querySelector('i');
        const label = this.querySelector('span');

        try {
            const res = await fetch(window.APP.url + '/ajax/handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.APP.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    action: 'toggle_favorite',
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
                if (label) label.textContent = isSaved ? 'Guardado' : 'Guardar';
                this.style.borderColor = isSaved
                    ? 'var(--primary)' : 'var(--border-color)';
                this.style.color = isSaved
                    ? 'var(--primary)' : 'var(--text-muted)';
                this.style.background = isSaved
                    ? 'rgba(230,57,70,.08)' : 'transparent';
                PDApp.showToast(data.message, 'success', 2000);
            }
        } catch {
            PDApp.showToast('Error de conexión.', 'error');
        }
    });

    // ── Modo lectura ──────────────────────────────────────────
    const readingBtn = document.getElementById('readingModeBtn');
    let readingMode  = localStorage.getItem('pd_reading_mode') === '1';

    function applyReading(active) {
        document.body.classList.toggle('reading-mode', active);
        if (readingBtn) {
            const icon = readingBtn.querySelector('i');
            const span = readingBtn.querySelector('span');
            if (icon) icon.className = active ? 'bi bi-book-fill' : 'bi bi-book';
            if (span) span.textContent = active ? 'Normal' : 'Leer';
            readingBtn.style.borderColor = active
                ? 'var(--primary)' : 'var(--border-color)';
            readingBtn.style.color = active
                ? 'var(--primary)' : 'var(--text-muted)';
        }
    }

    applyReading(readingMode);

    readingBtn?.addEventListener('click', function () {
        readingMode = !readingMode;
        applyReading(readingMode);
        localStorage.setItem('pd_reading_mode', readingMode ? '1' : '0');
        PDApp.showToast(
            readingMode ? 'Modo lectura activado' : 'Modo lectura desactivado',
            'info', 1500
        );
    });

    // ── Compartir ─────────────────────────────────────────────
    window.trackAndShare = async function (e, btn) {
        const noticiaId = btn.dataset.noticiaId;
        const red       = btn.dataset.red;
        if (noticiaId && red) {
            await fetch(window.APP.url + '/ajax/handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.APP.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    action: 'track_share',
                    noticia_id: noticiaId,
                    red,
                }),
            }).catch(() => {});
        }
    };

    // ── Reacciones ────────────────────────────────────────────
    const reactionsBar = document.getElementById('reactionsBar');
    reactionsBar?.addEventListener('click', async function (e) {
        const btn = e.target.closest('.reaction-btn');
        if (!btn) return;

        btn.style.transform = 'scale(1.3)';
        setTimeout(() => btn.style.transform = '', 200);

        const noticiaId = btn.dataset.noticiaId;
        const tipo      = btn.dataset.tipo;

        try {
            const res  = await fetch(window.APP.url + '/ajax/handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.APP.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    action: 'react_noticia',
                    noticia_id: noticiaId,
                    tipo,
                }),
            });
            const data = await res.json();

            if (data.success) {
                // Actualizar todos los botones
                reactionsBar.querySelectorAll('.reaction-btn').forEach(b => {
                    const bTipo = b.dataset.tipo;
                    const count = b.querySelector('.reaction-count');
                    const total = data.totales?.[bTipo] ?? 0;
                    if (count) count.textContent = total > 0 ? total : '';
                    const isActive = (data.accion !== 'removed' && bTipo === tipo);
                    b.classList.toggle('active', isActive);
                });

                // Total
                const totalEl = document.getElementById('reactionTotal');
                if (totalEl) {
                    const sum = Object.values(data.totales ?? {})
                        .reduce((a, b) => a + b, 0);
                    totalEl.textContent = sum;
                }

                PDApp.showToast(
                    data.accion === 'removed' ? 'Reacción eliminada' : '¡Reacción registrada!',
                    'success', 1500
                );
            }
        } catch {
            PDApp.showToast('Error al registrar reacción.', 'error');
        }
    });

    // ── Comentarios ───────────────────────────────────────────
    const commentForm = document.getElementById('commentForm');
    commentForm?.addEventListener('submit', async function (e) {
        e.preventDefault();

        const textarea  = this.querySelector('textarea[name="comentario"]');
        const noticiaId = this.querySelector('[name="noticia_id"]')?.value;
        const padreId   = this.querySelector('[name="padre_id"]')?.value ?? '0';
        const submitBtn = this.querySelector('[type="submit"]');
        const comentario = textarea?.value.trim();

        if (!comentario || comentario.length < 3) {
            PDApp.showToast('El comentario debe tener al menos 3 caracteres.', 'warning');
            return;
        }

        const origText  = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Enviando...';

        try {
            const res  = await fetch(window.APP.url + '/ajax/handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.APP.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    action:      'add_comment',
                    noticia_id:  noticiaId,
                    comentario,
                    padre_id:    padreId,
                }),
            });
            const data = await res.json();

            if (data.success) {
                textarea.value = '';
                textarea.style.height = 'auto';

                // Actualizar contador
                const counter = document.getElementById('commentCount');
                if (counter && data.total) counter.textContent = data.total;

                // Insertar comentario nuevo
                if (data.comment_html) {
                    const list = document.getElementById('commentsList');
                    if (list) {
                        // Remover mensaje "sé el primero..."
                        const emptyMsg = list.querySelector('[style*="text-align:center"]');
                        emptyMsg?.remove();

                        const temp = document.createElement('div');
                        temp.innerHTML = data.comment_html;
                        const newEl = temp.firstElementChild;
                        if (newEl) {
                            newEl.style.animation = 'slideUp 0.4s ease';
                            list.prepend(newEl);
                        }
                    }
                }

                // Reinicializar lazy images
                document.querySelectorAll('img[data-src]').forEach(img => {
                    img.src = img.dataset.src;
                    img.removeAttribute('data-src');
                });

                PDApp.showToast('¡Comentario publicado!', 'success');
            } else {
                PDApp.showToast(data.message || 'Error al publicar.', 'error');
            }
        } catch {
            PDApp.showToast('Error de conexión.', 'error');
        } finally {
            submitBtn.disabled  = false;
            submitBtn.innerHTML = origText;
        }
    });

    // ── Contador de caracteres del textarea ───────────────────
    const commentTextarea = commentForm?.querySelector('textarea');
    const charCounter     = commentForm?.querySelector('.char-counter');
    commentTextarea?.addEventListener('input', function () {
        const len = this.value.length;
        if (charCounter) {
            charCounter.textContent = len + '/2000';
            charCounter.style.color = len > 1800
                ? 'var(--danger)' : 'var(--text-muted)';
        }
        // Auto-resize
        this.style.height = 'auto';
        this.style.height = this.scrollHeight + 'px';
    });

    // ── Cargar más comentarios ────────────────────────────────
    const loadMoreComments = document.getElementById('loadMoreComments');
    loadMoreComments?.addEventListener('click', async function () {
        const pagina    = parseInt(this.dataset.pagina);
        const noticiaId = this.dataset.noticiaId;
        const origText  = this.innerHTML;

        this.disabled   = true;
        this.innerHTML  = '<i class="bi bi-arrow-repeat spin"></i> Cargando...';

        try {
            const res  = await fetch(window.APP.url + '/ajax/handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.APP.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    action:     'load_comments',
                    noticia_id: noticiaId,
                    pagina,
                }),
            });
            const data = await res.json();

            if (data.success && data.html) {
                const list = document.getElementById('commentsList');
                if (list) {
                    const temp = document.createElement('div');
                    temp.innerHTML = data.html;
                    while (temp.firstChild) {
                        list.appendChild(temp.firstChild);
                    }
                }

                if (data.hay_mas) {
                    this.dataset.pagina = pagina + 1;
                    this.innerHTML = origText;
                    this.disabled  = false;
                } else {
                    this.style.display = 'none';
                }
            }
        } catch {
            this.innerHTML = origText;
            this.disabled  = false;
            PDApp.showToast('Error al cargar comentarios.', 'error');
        }
    });

    // ── Seguir autor ──────────────────────────────────────────
    document.addEventListener('click', async function (e) {
        const btn = e.target.closest('[data-action="follow-user"]');
        if (!btn) return;

        if (!window.APP.userId) {
            PDApp.showToast('Inicia sesión para seguir autores.', 'warning');
            return;
        }

        const userId = btn.dataset.userId;
        try {
            const res  = await fetch(window.APP.url + '/ajax/handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.APP.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    action:     'toggle_follow',
                    seguido_id: userId,
                }),
            });
            const data = await res.json();

            if (data.success) {
                const isFollowing = data.action === 'followed';
                const icon = btn.querySelector('i');
                if (icon) {
                    icon.className = isFollowing
                        ? 'bi bi-person-plus-fill'
                        : 'bi bi-person-plus';
                }
                btn.style.borderColor = isFollowing
                    ? 'var(--primary)' : 'var(--border-color)';
                btn.style.color = isFollowing
                    ? 'var(--primary)' : 'var(--text-muted)';
                btn.lastChild.textContent = isFollowing ? ' Siguiendo' : ' Seguir';
                PDApp.showToast(data.message, 'success', 2000);
            }
        } catch {
            PDApp.showToast('Error de conexión.', 'error');
        }
    });

    // ── Encuesta ──────────────────────────────────────────────
    const pollWidget = document.getElementById('pollWidget');
    pollWidget?.querySelector('.poll-vote-btn')
        ?.addEventListener('click', async function () {
            const encuestaId = pollWidget.dataset.encuestaId;
            const selected   = pollWidget.querySelector('input[name="poll_option"]:checked');

            if (!selected) {
                PDApp.showToast('Selecciona una opción antes de votar.', 'warning');
                return;
            }

            this.disabled    = true;
            this.textContent = 'Votando...';

            try {
                const res  = await fetch(window.APP.url + '/ajax/handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.APP.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        action:      'vote_poll',
                        encuesta_id: encuestaId,
                        opcion_id:   selected.value,
                    }),
                });
                const data = await res.json();

                if (data.success) {
                    // Renderizar resultados
                    const optList = pollWidget.querySelector('.poll-options');
                    if (optList && data.opciones) {
                        optList.innerHTML = data.opciones.map(op => {
                            const pct = data.total_votos > 0
                                ? Math.round((op.votos / data.total_votos) * 100)
                                : 0;
                            return `
                                <div class="poll-option">
                                    <div class="poll-option-label">
                                        <div class="poll-bar" style="width:${pct}%"></div>
                                        <span class="poll-option-text">${PDApp.escapeHtml(op.opcion)}</span>
                                        <span class="poll-pct">${pct}%</span>
                                    </div>
                                </div>`;
                        }).join('');
                    }
                    this.remove();

                    const footer = pollWidget.querySelector('.poll-footer');
                    if (footer) {
                        footer.innerHTML = `
                            <i class="bi bi-people-fill"></i>
                            ${PDApp.formatNumber(data.total_votos)} votos en total · Ya votaste`;
                    }
                    PDApp.showToast('¡Voto registrado!', 'success');
                } else {
                    PDApp.showToast(data.message || 'Error al votar.', 'warning');
                    this.disabled = false;
                    this.innerHTML = '<i class="bi bi-check2-circle"></i> Votar';
                }
            } catch {
                this.disabled = false;
                this.innerHTML = '<i class="bi bi-check2-circle"></i> Votar';
                PDApp.showToast('Error de conexión.', 'error');
            }
        });

    // ── Newsletter sidebar ────────────────────────────────────
    const nlForm = document.getElementById('nlSidebarForm');
    const nlMsg  = document.getElementById('nlSidebarMsg');

    nlForm?.addEventListener('submit', async function (e) {
        e.preventDefault();
        const email  = this.querySelector('[name="email"]').value.trim();
        const btn    = this.querySelector('.subscribe-btn');
        const orig   = btn.innerHTML;

        btn.disabled  = true;
        btn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i>';

        try {
            const res  = await fetch(window.APP.url + '/ajax/handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.APP.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ action: 'newsletter_subscribe', email }),
            });
            const data = await res.json();

            if (nlMsg) {
                nlMsg.textContent = data.message;
                nlMsg.style.display = 'block';
                nlMsg.style.background = data.success
                    ? 'rgba(34,197,94,.15)' : 'rgba(239,68,68,.15)';
                nlMsg.style.color = data.success
                    ? 'var(--success)' : 'var(--danger)';
            }
            if (data.success) {
                this.reset();
                setTimeout(() => { if (nlMsg) nlMsg.style.display = 'none'; }, 4000);
            }
        } catch {
            if (nlMsg) {
                nlMsg.textContent = 'Error de conexión.';
                nlMsg.style.display = 'block';
            }
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    });

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
        }, { rootMargin: '200px' });
        document.querySelectorAll('img[data-src]').forEach(img => obs.observe(img));
    } else {
        document.querySelectorAll('img[data-src]').forEach(img => {
            img.src = img.dataset.src;
        });
    }

});
</script>