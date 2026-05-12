<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Live Blog (Cobertura en Vivo)
 * ============================================================
 * Archivo : live.php
 * Versión : 2.0.0
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// ── Parámetros ────────────────────────────────────────────────
$slug    = cleanInput($_GET['slug'] ?? '');
$usuario = currentUser();

// ── Si hay slug, mostrar live blog específico ─────────────────
$liveBlog = null;
if (!empty($slug)) {
    $liveBlog = getLiveBlogPorSlug($slug);
    if (!$liveBlog) {
        setFlashMessage('error', 'Cobertura no encontrada.');
        header('Location: ' . APP_URL . '/live.php');
        exit;
    }
    // Registrar visita
    db()->execute(
        "UPDATE live_blog SET vistas = vistas + 1 WHERE id = ?",
        [$liveBlog['id']]
    );
}

// ── Lista de live blogs ───────────────────────────────────────
$livesActivos     = getLiveBlogsActivos(10);
$livesFinalizados = db()->fetchAll(
    "SELECT lb.*, c.nombre AS cat_nombre, c.color AS cat_color,
            u.nombre AS autor_nombre, u.avatar AS autor_avatar
     FROM live_blog lb
     LEFT JOIN categorias c ON c.id = lb.categoria_id
     INNER JOIN usuarios  u ON u.id = lb.autor_id
     WHERE lb.estado = 'finalizado'
     ORDER BY lb.fecha_fin DESC
     LIMIT 6",
    []
);

// ── Procesar post de admin (publicar update) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $liveBlog && isAdmin()) {

    if (!$auth->verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errorPost = 'Token de seguridad inválido.';
    } else {
        $postAction = cleanInput($_POST['action'] ?? '');

        if ($postAction === 'post_update') {
            $result = publicarUpdateLiveBlog(
                (int)$liveBlog['id'],
                (int)$usuario['id'],
                [
                    'contenido'    => $_POST['contenido']   ?? '',
                    'tipo'         => $_POST['tipo']        ?? 'texto',
                    'es_destacado' => isset($_POST['es_destacado']),
                ]
            );
            if ($result['success']) {
                header('Location: ' . APP_URL . '/live.php?slug=' . urlencode($slug));
                exit;
            } else {
                $errorPost = $result['message'];
            }
        }

        if ($postAction === 'cambiar_estado') {
            $nuevoEstado = cleanInput($_POST['estado'] ?? '');
            if (in_array($nuevoEstado, ['activo','pausado','finalizado'], true)) {
                $fin = $nuevoEstado === 'finalizado'
                    ? ", fecha_fin = NOW()" : '';
                db()->execute(
                    "UPDATE live_blog SET estado = ? $fin WHERE id = ?",
                    [$nuevoEstado, $liveBlog['id']]
                );
                header('Location: ' . APP_URL . '/live.php?slug=' . urlencode($slug));
                exit;
            }
        }
    }
}

// ── Datos del live blog específico ───────────────────────────
if ($liveBlog) {
    $updates = getLiveBlogUpdates((int)$liveBlog['id']);
    $lastUpdateId = !empty($updates) ? (int)$updates[0]['id'] : 0;

    // Noticias relacionadas de la misma categoría
    $noticiasRelacionadas = [];
    if ($liveBlog['categoria_id']) {
        $noticiasRelacionadas = getNoticiasRecientes(4, 0, (int)$liveBlog['categoria_id']);
    }
}

// ── SEO ───────────────────────────────────────────────────────
if ($liveBlog) {
    $pageTitle       = e($liveBlog['titulo']) . ' — ' .
                       Config::get('site_nombre', APP_NAME);
    $pageDescription = !empty($liveBlog['descripcion'])
        ? truncateChars($liveBlog['descripcion'], 160)
        : 'Cobertura en vivo de ' . $liveBlog['titulo'];
    $pageImage       = !empty($liveBlog['imagen'])
        ? getImageUrl($liveBlog['imagen'])
        : IMG_DEFAULT_OG;
} else {
    $pageTitle       = 'Coberturas en Vivo — ' .
                       Config::get('site_nombre', APP_NAME);
    $pageDescription = 'Sigue en tiempo real la cobertura de los eventos más importantes.';
    $pageImage       = IMG_DEFAULT_OG;
}

$bodyClass = 'page-live';
require_once __DIR__ . '/includes/header.php';
?>

<?php if ($liveBlog): ?>
<!-- ════════════════════════════════════════════════════════════
     VISTA: LIVE BLOG ESPECÍFICO
     ════════════════════════════════════════════════════════════ -->

<!-- Barra de estado LIVE -->
<?php if ($liveBlog['estado'] === 'activo'): ?>
<div style="background:var(--primary);color:#fff;
            padding:10px 0;text-align:center;
            position:sticky;top:0;z-index:200;
            font-size:.82rem;font-weight:700;
            letter-spacing:.08em;text-transform:uppercase">
    <div class="container-fluid px-3 px-lg-4"
         style="display:flex;align-items:center;
                justify-content:center;gap:12px">
        <span class="live-dot"
              style="width:10px;height:10px"></span>
        EN VIVO · <?= e($liveBlog['titulo']) ?>
        <span id="liveUpdateCount"
              style="background:rgba(255,255,255,.2);
                     padding:2px 8px;border-radius:var(--border-radius-full);
                     font-size:.72rem">
            <?= (int)$liveBlog['total_updates'] ?> updates
        </span>
        <span style="background:rgba(255,255,255,.15);
                     padding:2px 8px;border-radius:var(--border-radius-full);
                     font-size:.72rem">
            <i class="bi bi-eye-fill"></i>
            <?= formatNumber((int)$liveBlog['vistas']) ?>
        </span>
    </div>
</div>
<?php endif; ?>

<div class="container-fluid px-3 px-lg-4 mt-6">
    <div class="main-layout">

        <!-- ── COLUMNA PRINCIPAL ─────────────────────────────── -->
        <div class="main-content">

            <!-- Header del Live Blog -->
            <div class="live-blog-header">
                <!-- Breadcrumb -->
                <nav style="font-size:.78rem;color:rgba(255,255,255,.5);
                            margin-bottom:16px">
                    <a href="<?= APP_URL ?>/index.php"
                       style="color:rgba(255,255,255,.5);text-decoration:none">
                        Inicio
                    </a>
                    <span style="margin:0 6px">/</span>
                    <a href="<?= APP_URL ?>/live.php"
                       style="color:rgba(255,255,255,.5);text-decoration:none">
                        Coberturas en vivo
                    </a>
                    <span style="margin:0 6px">/</span>
                    <span style="color:rgba(255,255,255,.8)">
                        <?= e(truncateChars($liveBlog['titulo'], 40)) ?>
                    </span>
                </nav>

                <!-- Badge de estado -->
                <div style="margin-bottom:16px">
                    <?php if ($liveBlog['estado'] === 'activo'): ?>
                    <span class="live-badge">
                        <span class="live-dot"
                              style="background:#fff"></span>
                        EN VIVO
                    </span>
                    <?php elseif ($liveBlog['estado'] === 'pausado'): ?>
                    <span class="live-badge"
                          style="background:rgba(245,158,11,.3)">
                        <i class="bi bi-pause-fill"></i>
                        PAUSADO
                    </span>
                    <?php else: ?>
                    <span class="live-badge"
                          style="background:rgba(255,255,255,.1)">
                        <i class="bi bi-stop-fill"></i>
                        FINALIZADO
                    </span>
                    <?php endif; ?>

                    <?php if ($liveBlog['cat_nombre']): ?>
                    <a href="<?= APP_URL ?>/categoria.php?slug=<?= e($liveBlog['cat_slug'] ?? '') ?>"
                       style="display:inline-flex;align-items:center;gap:4px;
                              margin-left:8px;padding:3px 10px;
                              background:<?= e($liveBlog['cat_color'] ?? 'rgba(255,255,255,.1)') ?>;
                              color:#fff;border-radius:var(--border-radius-full);
                              font-size:.72rem;font-weight:700;text-decoration:none">
                        <?= e($liveBlog['cat_nombre']) ?>
                    </a>
                    <?php endif; ?>
                </div>

                <h1 class="live-blog-title">
                    <?= e($liveBlog['titulo']) ?>
                </h1>

                <?php if (!empty($liveBlog['descripcion'])): ?>
                <p class="live-blog-desc">
                    <?= e($liveBlog['descripcion']) ?>
                </p>
                <?php endif; ?>

                <!-- Stats del live -->
                <div class="live-blog-stats">
                    <div class="live-stat">
                        <span class="live-stat-value"
                              id="liveTotalUpdates">
                            <?= (int)$liveBlog['total_updates'] ?>
                        </span>
                        <span class="live-stat-label">Updates</span>
                    </div>
                    <div class="live-stat">
                        <span class="live-stat-value">
                            <?= formatNumber((int)$liveBlog['vistas']) ?>
                        </span>
                        <span class="live-stat-label">Vistas</span>
                    </div>
                    <div class="live-stat">
                        <span class="live-stat-value">
                            <?= formatDate($liveBlog['fecha_inicio'], 'time') ?>
                        </span>
                        <span class="live-stat-label">
                            <?= $liveBlog['estado'] === 'activo'
                                ? 'Inicio'
                                : 'Finalizó' ?>
                        </span>
                    </div>
                    <div class="live-stat">
                        <img src="<?= e(getImageUrl($liveBlog['autor_avatar'] ?? '', 'avatar')) ?>"
                             alt="<?= e($liveBlog['autor_nombre']) ?>"
                             style="width:36px;height:36px;border-radius:50%;
                                    object-fit:cover;border:2px solid rgba(255,255,255,.3)">
                        <span class="live-stat-label">
                            <?= e($liveBlog['autor_nombre']) ?>
                        </span>
                    </div>
                </div>
            </div><!-- /.live-blog-header -->

            <!-- Panel de admin (publicar updates) -->
            <?php if (isAdmin()): ?>
            <div style="background:var(--bg-surface-2);
                        border-radius:var(--border-radius-xl);
                        padding:var(--space-5);
                        margin-bottom:var(--space-6);
                        border:2px solid var(--primary);
                        position:sticky;top:44px;z-index:100">
                <div style="display:flex;align-items:center;
                            justify-content:space-between;
                            margin-bottom:12px;flex-wrap:wrap;gap:8px">
                    <span style="font-size:.82rem;font-weight:700;
                                  color:var(--primary)">
                        <i class="bi bi-broadcast-pin"></i>
                        Panel del Redactor
                    </span>
                    <!-- Cambiar estado -->
                    <form method="POST" style="display:flex;gap:8px">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="cambiar_estado">
                        <?php if ($liveBlog['estado'] !== 'activo'): ?>
                        <button name="estado" value="activo"
                                style="padding:5px 12px;background:var(--success);
                                       color:#fff;border-radius:var(--border-radius-full);
                                       font-size:.75rem;font-weight:700;cursor:pointer">
                            <i class="bi bi-play-fill"></i> Activar
                        </button>
                        <?php endif; ?>
                        <?php if ($liveBlog['estado'] === 'activo'): ?>
                        <button name="estado" value="pausado"
                                style="padding:5px 12px;background:var(--warning);
                                       color:#fff;border-radius:var(--border-radius-full);
                                       font-size:.75rem;font-weight:700;cursor:pointer">
                            <i class="bi bi-pause-fill"></i> Pausar
                        </button>
                        <?php endif; ?>
                        <?php if ($liveBlog['estado'] !== 'finalizado'): ?>
                        <button name="estado" value="finalizado"
                                onclick="return confirm('¿Finalizar esta cobertura?')"
                                style="padding:5px 12px;background:var(--danger);
                                       color:#fff;border-radius:var(--border-radius-full);
                                       font-size:.75rem;font-weight:700;cursor:pointer">
                            <i class="bi bi-stop-fill"></i> Finalizar
                        </button>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if (isset($errorPost)): ?>
                <div style="padding:8px 12px;background:rgba(239,68,68,.1);
                            color:var(--danger);border-radius:var(--border-radius);
                            margin-bottom:10px;font-size:.82rem">
                    <?= e($errorPost) ?>
                </div>
                <?php endif; ?>

                <form method="POST" id="livePublishForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="post_update">

                    <div style="display:grid;grid-template-columns:1fr auto;
                                gap:10px;align-items:flex-start">
                        <div>
                            <textarea name="contenido"
                                      id="liveContent"
                                      placeholder="Escribe la actualización..."
                                      rows="3"
                                      class="comment-textarea"
                                      required
                                      style="margin-bottom:8px"></textarea>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <select name="tipo"
                                        class="form-select"
                                        style="width:auto;padding:6px 12px;
                                               font-size:.78rem">
                                    <option value="texto">📝 Texto</option>
                                    <option value="breaking">🔴 Breaking</option>
                                    <option value="alerta">⚠️ Alerta</option>
                                    <option value="cita">💬 Cita</option>
                                    <option value="imagen">🖼️ Imagen</option>
                                    <option value="video">🎬 Video</option>
                                </select>
                                <label style="display:flex;align-items:center;
                                             gap:6px;font-size:.78rem;
                                             color:var(--text-secondary);
                                             cursor:pointer">
                                    <input type="checkbox" name="es_destacado"
                                           style="accent-color:var(--primary)">
                                    Destacar
                                </label>
                            </div>
                        </div>
                        <button type="submit"
                                style="padding:12px 20px;
                                       background:var(--primary);
                                       color:#fff;border-radius:var(--border-radius-lg);
                                       font-weight:700;cursor:pointer;
                                       white-space:nowrap;
                                       display:flex;align-items:center;gap:6px">
                            <i class="bi bi-send-fill"></i>
                            Publicar
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- Indicador de nuevas actualizaciones -->
            <div id="newUpdatesBar"
                 style="display:none;text-align:center;
                        margin-bottom:var(--space-4);
                        position:sticky;top:<?= isAdmin() ? '200px' : '60px' ?>;
                        z-index:99">
                <button onclick="scrollToNewUpdates()"
                        style="background:var(--primary);color:#fff;
                               padding:8px 20px;
                               border-radius:var(--border-radius-full);
                               font-size:.82rem;font-weight:700;cursor:pointer;
                               box-shadow:var(--shadow-md);
                               display:inline-flex;align-items:center;gap:8px;
                               animation:slideUp .4s ease">
                    <span class="live-dot"></span>
                    <span id="newUpdatesText">1 nueva actualización</span>
                    <i class="bi bi-arrow-up-circle-fill"></i>
                </button>
            </div>

            <!-- Timeline de actualizaciones -->
            <div class="live-updates"
                 id="liveUpdatesContainer"
                 data-blog-id="<?= (int)$liveBlog['id'] ?>"
                 data-estado="<?= e($liveBlog['estado']) ?>">

                <?php if (!empty($updates)): ?>
                    <?php foreach ($updates as $update): ?>
                    <div class="live-update
                         <?= $update['tipo'] === 'breaking' ? 'live-update--breaking' : '' ?>
                         <?= $update['tipo'] === 'alerta'   ? 'live-update--alerta'   : '' ?>
                         <?= $update['es_destacado'] ? 'live-update--destacado' : '' ?>"
                         id="live-update-<?= (int)$update['id'] ?>"
                         data-id="<?= (int)$update['id'] ?>">

                        <div class="live-update__dot"></div>

                        <div class="live-update__body">
                            <div class="live-update__header">
                                <div class="live-update__author">
                                    <img src="<?= e(getImageUrl($update['autor_avatar'] ?? '', 'avatar')) ?>"
                                         alt="<?= e($update['autor_nombre']) ?>"
                                         width="28" height="28"
                                         loading="lazy">
                                    <strong>
                                        <?= e($update['autor_nombre']) ?>
                                    </strong>
                                </div>

                                <?php if ($update['tipo'] === 'breaking'): ?>
                                <span class="live-update__type-badge type-breaking">
                                    <i class="bi bi-broadcast-pin"></i>
                                    BREAKING
                                </span>
                                <?php elseif ($update['tipo'] === 'alerta'): ?>
                                <span class="live-update__type-badge type-alerta">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    ALERTA
                                </span>
                                <?php elseif ($update['tipo'] === 'cita'): ?>
                                <span class="live-update__type-badge"
                                      style="background:rgba(69,123,157,.15);
                                             color:var(--accent)">
                                    <i class="bi bi-chat-quote-fill"></i>
                                    CITA
                                </span>
                                <?php endif; ?>

                                <?php if ($update['es_destacado']): ?>
                                <span style="font-size:.7rem;color:var(--warning);
                                             font-weight:700">
                                    <i class="bi bi-star-fill"></i>
                                    Destacado
                                </span>
                                <?php endif; ?>

                                <time class="live-update__time"
                                      datetime="<?= e($update['fecha']) ?>"
                                      title="<?= e(formatDate($update['fecha'], 'full')) ?>">
                                    <?= formatDate($update['fecha'], 'time') ?>
                                    <span style="opacity:.6;margin-left:4px;font-size:.7em">
                                        (<?= timeAgo($update['fecha']) ?>)
                                    </span>
                                </time>
                            </div>

                            <!-- Contenido -->
                            <?php if ($update['tipo'] === 'cita'): ?>
                            <blockquote style="border-left:4px solid var(--accent);
                                               padding:12px 16px;margin:0;
                                               background:var(--bg-surface-2);
                                               border-radius:0 var(--border-radius) var(--border-radius) 0;
                                               font-style:italic;
                                               color:var(--text-secondary)">
                                <?= nl2br(e($update['contenido'])) ?>
                            </blockquote>
                            <?php else: ?>
                            <div class="live-update__content">
                                <?= nl2br(e($update['contenido'])) ?>
                            </div>
                            <?php endif; ?>

                            <!-- Imagen adjunta -->
                            <?php if (!empty($update['imagen'])): ?>
                            <div style="margin-top:12px">
                                <img src="<?= e(getImageUrl($update['imagen'])) ?>"
                                     alt="Imagen de la actualización"
                                     style="width:100%;max-height:400px;
                                            object-fit:cover;
                                            border-radius:var(--border-radius-lg)"
                                     loading="lazy">
                            </div>
                            <?php endif; ?>

                            <!-- Video adjunto -->
                            <?php if (!empty($update['video_url'])): ?>
                            <?php
                            /**
                             * Convierte cualquier URL de YouTube/Vimeo/MP4
                             * a la URL de embed correcta para un <iframe>.
                             * YouTube rechaza sus URLs normales en iframes —
                             * solo acepta el formato /embed/VIDEO_ID.
                             */
                            function getLiveUpdateEmbedUrl(string $url): array {
                                // YouTube: watch, youtu.be, shorts, embed
                                if (preg_match(
                                    '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_\-]{11})/',
                                    $url, $m
                                )) {
                                    return [
                                        'type' => 'iframe',
                                        'src'  => 'https://www.youtube.com/embed/' . $m[1]
                                                  . '?rel=0&modestbranding=1',
                                    ];
                                }
                                // YouTube Shorts
                                if (preg_match('/youtube\.com\/shorts\/([a-zA-Z0-9_\-]{11})/', $url, $m)) {
                                    return [
                                        'type' => 'iframe',
                                        'src'  => 'https://www.youtube.com/embed/' . $m[1]
                                                  . '?rel=0&modestbranding=1',
                                    ];
                                }
                                // Vimeo
                                if (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
                                    return [
                                        'type' => 'iframe',
                                        'src'  => 'https://player.vimeo.com/video/' . $m[1],
                                    ];
                                }
                                // MP4 / archivo de video directo
                                if (preg_match('/\.(mp4|webm|ogg)(\?.*)?$/i', $url)) {
                                    return ['type' => 'video', 'src' => $url];
                                }
                                // Cualquier otra URL (ya podría ser un embed válido)
                                return ['type' => 'iframe', 'src' => $url];
                            }
                            $videoEmbed = getLiveUpdateEmbedUrl($update['video_url']);
                            ?>
                            <div style="margin-top:12px;
                                        position:relative;padding-bottom:56.25%;height:0;
                                        border-radius:var(--border-radius-lg);overflow:hidden;
                                        background:#000">
                                <?php if ($videoEmbed['type'] === 'video'): ?>
                                <video src="<?= e($videoEmbed['src']) ?>"
                                       controls
                                       style="position:absolute;top:0;left:0;
                                              width:100%;height:100%">
                                    Tu navegador no soporta video HTML5.
                                </video>
                                <?php else: ?>
                                <iframe src="<?= e($videoEmbed['src']) ?>"
                                        frameborder="0"
                                        allow="accelerometer; autoplay; clipboard-write;
                                               encrypted-media; gyroscope; picture-in-picture;
                                               web-share"
                                        allowfullscreen
                                        loading="lazy"
                                        style="position:absolute;top:0;left:0;
                                               width:100%;height:100%;border:none">
                                </iframe>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- Acciones del update -->
                            <div style="display:flex;align-items:center;
                                        gap:12px;margin-top:10px;
                                        padding-top:8px;
                                        border-top:1px solid var(--border-color)">
                                <!-- Compartir update -->
                                <button onclick="shareUpdate(<?= (int)$update['id'] ?>, '<?= e(addslashes($update['contenido'])) ?>')"
                                        style="display:flex;align-items:center;gap:4px;
                                               font-size:.72rem;color:var(--text-muted);
                                               background:none;border:none;cursor:pointer;
                                               transition:color .2s ease">
                                    <i class="bi bi-share"></i>
                                    Compartir
                                </button>

                                <span style="margin-left:auto;font-size:.7rem;
                                             color:var(--text-muted)">
                                    #<?= (int)$update['id'] ?>
                                </span>

                                <?php if (isAdmin()): ?>
                                <button onclick="confirmDelete('<?= APP_URL ?>/ajax/handler.php', '¿Eliminar esta actualización?')"
                                        style="font-size:.72rem;color:var(--danger);
                                               background:none;border:none;cursor:pointer">
                                    <i class="bi bi-trash3"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                <?php else: ?>
                <div style="text-align:center;padding:60px 20px;
                            color:var(--text-muted)">
                    <i class="bi bi-broadcast"
                       style="font-size:3rem;opacity:.2;
                              display:block;margin-bottom:16px"></i>
                    <p>
                        <?= $liveBlog['estado'] === 'activo'
                            ? 'La cobertura comenzará en breve. ¡Mantente atento!'
                            : 'No hay actualizaciones disponibles.' ?>
                    </p>
                </div>
                <?php endif; ?>

            </div><!-- /.live-updates -->

            <!-- Fin de la cobertura -->
            <?php if ($liveBlog['estado'] === 'finalizado'): ?>
            <div style="background:var(--bg-surface-2);
                        border-radius:var(--border-radius-xl);
                        padding:var(--space-6);text-align:center;
                        margin-top:var(--space-8);
                        border:2px solid var(--border-color)">
                <i class="bi bi-stop-circle-fill"
                   style="font-size:2rem;color:var(--text-muted);
                          display:block;margin-bottom:12px"></i>
                <h3 style="font-family:var(--font-serif);
                           color:var(--text-primary);margin-bottom:6px">
                    Cobertura finalizada
                </h3>
                <p style="color:var(--text-muted);font-size:.875rem">
                    Esta cobertura concluyó el
                    <?= formatDate($liveBlog['fecha_fin'] ?? '', 'full') ?>
                    con <?= formatNumber((int)$liveBlog['total_updates']) ?>
                    actualizaciones.
                </p>
            </div>
            <?php endif; ?>

            <!-- Noticias relacionadas -->
            <?php if (!empty($noticiasRelacionadas)): ?>
            <section style="margin-top:var(--space-10)">
                <div class="section-header">
                    <h2 class="section-title"
                        style="font-size:1.2rem">
                        Noticias relacionadas
                    </h2>
                </div>
                <div class="news-grid news-grid--4col">
                    <?php foreach ($noticiasRelacionadas as $rel): ?>
                    <article class="news-card">
                        <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($rel['slug']) ?>"
                           class="news-card__img-wrap" tabindex="-1">
                            <img data-src="<?= e(getImageUrl($rel['imagen'])) ?>"
                                 src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 9'%3E%3C/svg%3E"
                                 alt="<?= e($rel['titulo']) ?>"
                                 class="news-card__img" loading="lazy">
                        </a>
                        <div class="news-card__body">
                            <h3 class="news-card__title"
                                style="font-size:.85rem">
                                <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($rel['slug']) ?>">
                                    <?= e(truncateChars($rel['titulo'], 70)) ?>
                                </a>
                            </h3>
                            <div class="news-card__meta">
                                <span>
                                    <i class="bi bi-clock"></i>
                                    <?= timeAgo($rel['fecha_publicacion']) ?>
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
        <aside class="sidebar">

            <!-- Widget: Info del live -->
            <div class="widget">
                <div class="widget__header"
                     style="border-bottom-color:var(--primary)">
                    <h3 class="widget__title"
                        style="color:var(--primary)">
                        <i class="bi bi-info-circle-fill"></i>
                        Sobre esta cobertura
                    </h3>
                </div>
                <div class="widget__body">
                    <ul style="list-style:none;
                                display:flex;flex-direction:column;gap:10px">
                        <li style="display:flex;gap:10px;
                                   font-size:.82rem;color:var(--text-secondary)">
                            <i class="bi bi-clock-fill"
                               style="color:var(--primary);
                                      flex-shrink:0;margin-top:1px"></i>
                            <div>
                                <span style="display:block;font-weight:600;
                                             color:var(--text-primary)">
                                    Inicio
                                </span>
                                <?= formatDate($liveBlog['fecha_inicio'], 'full') ?>
                            </div>
                        </li>
                        <li style="display:flex;gap:10px;
                                   font-size:.82rem;color:var(--text-secondary)">
                            <i class="bi bi-pen-fill"
                               style="color:var(--accent);
                                      flex-shrink:0;margin-top:1px"></i>
                            <div>
                                <span style="display:block;font-weight:600;
                                             color:var(--text-primary)">
                                    Redactor
                                </span>
                                <div style="display:flex;align-items:center;gap:6px">
                                    <img src="<?= e(getImageUrl($liveBlog['autor_avatar'] ?? '', 'avatar')) ?>"
                                         alt="" width="20" height="20"
                                         style="border-radius:50%;object-fit:cover">
                                    <?= e($liveBlog['autor_nombre']) ?>
                                </div>
                            </div>
                        </li>
                        <li style="display:flex;gap:10px;
                                   font-size:.82rem;color:var(--text-secondary)">
                            <i class="bi bi-collection-fill"
                               style="color:var(--success);
                                      flex-shrink:0;margin-top:1px"></i>
                            <div>
                                <span style="display:block;font-weight:600;
                                             color:var(--text-primary)">
                                    Total de updates
                                </span>
                                <span id="sidebarTotalUpdates">
                                    <?= (int)$liveBlog['total_updates'] ?>
                                </span>
                            </div>
                        </li>
                        <li style="display:flex;gap:10px;
                                   font-size:.82rem;color:var(--text-secondary)">
                            <i class="bi bi-eye-fill"
                               style="color:var(--info);
                                      flex-shrink:0;margin-top:1px"></i>
                            <div>
                                <span style="display:block;font-weight:600;
                                             color:var(--text-primary)">
                                    Lectores
                                </span>
                                <?= formatNumber((int)$liveBlog['vistas']) ?>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Widget: Compartir -->
            <div class="widget">
                <div class="widget__header">
                    <h3 class="widget__title">
                        <i class="bi bi-share-fill"
                           style="color:var(--primary)"></i>
                        Compartir cobertura
                    </h3>
                </div>
                <div class="widget__body">
                    <div style="display:flex;flex-direction:column;gap:8px">
                        <?php
                        $liveUrl   = urlencode(APP_URL . '/live.php?slug=' . $liveBlog['slug']);
                        $liveTitle = urlencode('🔴 EN VIVO: ' . $liveBlog['titulo']);
                        $shareLinks = [
                            ['icon'=>'bi-whatsapp',  'color'=>'#25D366','label'=>'WhatsApp',
                             'url'=>"https://wa.me/?text={$liveTitle}%20{$liveUrl}"],
                            ['icon'=>'bi-facebook',  'color'=>'#1877F2','label'=>'Facebook',
                             'url'=>"https://www.facebook.com/sharer/sharer.php?u={$liveUrl}"],
                            ['icon'=>'bi-twitter-x', 'color'=>'#000','label'=>'Twitter/X',
                             'url'=>"https://twitter.com/intent/tweet?text={$liveTitle}&url={$liveUrl}"],
                            ['icon'=>'bi-telegram',  'color'=>'#229ED9','label'=>'Telegram',
                             'url'=>"https://t.me/share/url?url={$liveUrl}&text={$liveTitle}"],
                        ];
                        foreach ($shareLinks as $sl):
                        ?>
                        <a href="<?= e($sl['url']) ?>"
                           target="_blank" rel="noopener noreferrer"
                           style="display:flex;align-items:center;gap:10px;
                                  padding:8px 12px;border-radius:var(--border-radius);
                                  background:<?= e($sl['color']) ?>;
                                  color:#fff;text-decoration:none;
                                  font-size:.82rem;font-weight:600;
                                  transition:opacity .2s ease">
                            <i class="bi <?= e($sl['icon']) ?>"></i>
                            <?= e($sl['label']) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Widget: Otras coberturas activas -->
            <?php
            $otrasActivas = array_filter(
                $livesActivos,
                fn($l) => (int)$l['id'] !== (int)$liveBlog['id']
            );
            if (!empty($otrasActivas)):
            ?>
            <div class="widget">
                <div class="widget__header">
                    <h3 class="widget__title">
                        <i class="bi bi-broadcast-pin"
                           style="color:var(--primary)"></i>
                        Otras coberturas
                    </h3>
                </div>
                <div class="widget__body"
                     style="padding:var(--space-3) var(--space-4)">
                    <?php foreach (array_slice(array_values($otrasActivas), 0, 3) as $otra): ?>
                    <a href="<?= APP_URL ?>/live.php?slug=<?= e($otra['slug']) ?>"
                       style="display:flex;flex-direction:column;
                              padding:10px 0;
                              border-bottom:1px solid var(--border-color);
                              text-decoration:none;transition:all .2s ease">
                        <div style="display:flex;align-items:center;
                                    gap:6px;margin-bottom:4px">
                            <span class="live-dot"
                                  style="width:7px;height:7px"></span>
                            <span style="font-size:.65rem;font-weight:700;
                                         color:var(--primary);
                                         text-transform:uppercase">
                                <?= ucfirst($otra['estado']) ?>
                            </span>
                        </div>
                        <span style="font-size:.82rem;font-weight:600;
                                     color:var(--text-primary);
                                     line-height:1.3">
                            <?= e(truncateChars($otra['titulo'], 65)) ?>
                        </span>
                        <span style="font-size:.7rem;color:var(--text-muted);
                                     margin-top:4px">
                            <i class="bi bi-collection"></i>
                            <?= (int)$otra['total_updates'] ?> updates
                        </span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </aside>

    </div>
</div>

<?php else: ?>
<!-- ════════════════════════════════════════════════════════════
     VISTA: LISTA DE COBERTURAS
     ════════════════════════════════════════════════════════════ -->

<!-- Hero -->
<div style="background:linear-gradient(135deg,var(--secondary),var(--secondary-dark));
            padding:var(--space-16) 0;text-align:center;
            margin-bottom:var(--space-8)">
    <div class="container-fluid px-3 px-lg-4">
        <div style="display:inline-flex;align-items:center;gap:10px;
                    background:rgba(230,57,70,.2);
                    padding:6px 20px;border-radius:var(--border-radius-full);
                    margin-bottom:16px">
            <span class="live-dot"></span>
            <span style="color:#fff;font-size:.82rem;font-weight:700;
                          text-transform:uppercase;letter-spacing:.1em">
                Coberturas en vivo
            </span>
        </div>
        <h1 style="font-family:var(--font-serif);font-size:clamp(1.8rem,4vw,3rem);
                   font-weight:900;color:#fff;margin-bottom:10px">
            Noticias en tiempo real
        </h1>
        <p style="color:rgba(255,255,255,.7);font-size:1rem;
                   max-width:500px;margin:0 auto">
            Sigue minuto a minuto los eventos más importantes
        </p>
    </div>
</div>

<div class="container-fluid px-3 px-lg-4">

    <!-- Coberturas activas -->
    <?php if (!empty($livesActivos)): ?>
    <section style="margin-bottom:var(--space-10)">
        <div class="section-header">
            <h2 class="section-title">
                <span class="live-dot"
                      style="display:inline-block;
                             width:10px;height:10px;
                             margin-right:8px;
                             vertical-align:middle"></span>
                En este momento
            </h2>
        </div>
        <div style="display:grid;
                    grid-template-columns:repeat(auto-fill,minmax(320px,1fr));
                    gap:var(--space-5)">
            <?php foreach ($livesActivos as $lb): ?>
            <a href="<?= APP_URL ?>/live.php?slug=<?= e($lb['slug']) ?>"
               style="display:flex;flex-direction:column;
                      background:var(--bg-surface);
                      border-radius:var(--border-radius-xl);
                      overflow:hidden;text-decoration:none;
                      box-shadow:var(--shadow-sm);
                      border:2px solid transparent;
                      transition:all .25s ease;
                      <?= $lb['estado'] === 'activo'
                          ? 'border-color:var(--primary);'
                          : '' ?>">

                <!-- Imagen o gradient -->
                <div style="aspect-ratio:16/7;position:relative;
                            background:linear-gradient(135deg,
                            var(--secondary),var(--secondary-dark));
                            overflow:hidden">
                    <?php if (!empty($lb['imagen'])): ?>
                    <img src="<?= e(getImageUrl($lb['imagen'])) ?>"
                         alt="<?= e($lb['titulo']) ?>"
                         style="width:100%;height:100%;
                                object-fit:cover;opacity:.6"
                         loading="lazy">
                    <?php endif; ?>
                    <div style="position:absolute;inset:0;
                                display:flex;flex-direction:column;
                                justify-content:flex-end;
                                padding:var(--space-4);
                                background:linear-gradient(to top,
                                rgba(0,0,0,.8),transparent)">
                        <!-- Estado badge -->
                        <div style="margin-bottom:8px">
                            <?php if ($lb['estado'] === 'activo'): ?>
                            <span style="display:inline-flex;align-items:center;
                                         gap:6px;background:var(--primary);
                                         color:#fff;padding:3px 10px;
                                         border-radius:var(--border-radius-full);
                                         font-size:.65rem;font-weight:800;
                                         text-transform:uppercase;letter-spacing:.08em">
                                <span class="live-dot"
                                      style="width:6px;height:6px;background:#fff"></span>
                                EN VIVO
                            </span>
                            <?php else: ?>
                            <span style="display:inline-flex;align-items:center;
                                         gap:6px;background:rgba(255,255,255,.15);
                                         color:rgba(255,255,255,.8);padding:3px 10px;
                                         border-radius:var(--border-radius-full);
                                         font-size:.65rem;font-weight:800">
                                ⏸ PAUSADO
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Info -->
                <div style="padding:var(--space-4)">
                    <?php if ($lb['cat_nombre']): ?>
                    <span style="font-size:.7rem;font-weight:700;
                                  color:<?= e($lb['cat_color'] ?? 'var(--primary)') ?>;
                                  text-transform:uppercase;letter-spacing:.06em">
                        <?= e($lb['cat_nombre']) ?>
                    </span>
                    <?php endif; ?>
                    <h3 style="font-family:var(--font-serif);
                               font-size:1rem;font-weight:800;
                               color:var(--text-primary);
                               line-height:1.3;margin:6px 0 10px">
                        <?= e(truncateChars($lb['titulo'], 80)) ?>
                    </h3>
                    <?php if (!empty($lb['descripcion'])): ?>
                    <p style="font-size:.78rem;color:var(--text-muted);
                               line-height:1.5;margin-bottom:12px;
                               display:-webkit-box;-webkit-line-clamp:2;
                               -webkit-box-orient:vertical;overflow:hidden">
                        <?= e($lb['descripcion']) ?>
                    </p>
                    <?php endif; ?>
                    <div style="display:flex;align-items:center;
                                justify-content:space-between;
                                font-size:.72rem;color:var(--text-muted)">
                        <div style="display:flex;align-items:center;gap:12px">
                            <span>
                                <i class="bi bi-collection"></i>
                                <?= formatNumber((int)$lb['total_updates']) ?> updates
                            </span>
                            <span>
                                <i class="bi bi-eye"></i>
                                <?= formatNumber((int)$lb['vistas']) ?>
                            </span>
                        </div>
                        <div style="display:flex;align-items:center;gap:6px">
                            <img src="<?= e(getImageUrl($lb['autor_avatar'] ?? '', 'avatar')) ?>"
                                 alt="" width="18" height="18"
                                 style="border-radius:50%;object-fit:cover">
                            <span><?= e($lb['autor_nombre']) ?></span>
                        </div>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php else: ?>
    <div style="text-align:center;padding:80px 20px;
                background:var(--bg-surface);
                border-radius:var(--border-radius-xl);
                border:2px dashed var(--border-color);
                margin-bottom:var(--space-8)">
        <i class="bi bi-broadcast"
           style="font-size:3rem;opacity:.2;
                  display:block;margin-bottom:16px;
                  color:var(--text-muted)"></i>
        <h3 style="font-family:var(--font-serif);
                   color:var(--text-muted);margin-bottom:8px">
            No hay coberturas activas en este momento
        </h3>
        <p style="color:var(--text-muted);font-size:.875rem">
            Vuelve pronto. Cuando haya eventos importantes,
            los cubriremos en tiempo real.
        </p>
    </div>
    <?php endif; ?>

    <!-- Coberturas finalizadas -->
    <?php if (!empty($livesFinalizados)): ?>
    <section>
        <div class="section-header">
            <h2 class="section-title">
                <i class="bi bi-archive-fill"
                   style="color:var(--text-muted);margin-right:8px"></i>
                Coberturas anteriores
            </h2>
        </div>
        <div style="display:grid;
                    grid-template-columns:repeat(auto-fill,minmax(280px,1fr));
                    gap:var(--space-4)">
            <?php foreach ($livesFinalizados as $lf): ?>
            <a href="<?= APP_URL ?>/live.php?slug=<?= e($lf['slug']) ?>"
               style="display:flex;gap:var(--space-3);
                      background:var(--bg-surface);
                      border-radius:var(--border-radius-lg);
                      padding:var(--space-4);
                      text-decoration:none;
                      box-shadow:var(--shadow-sm);
                      transition:all .2s ease;
                      border:1px solid var(--border-color)">
                <div style="flex-shrink:0;width:8px;
                            background:var(--text-muted);
                            border-radius:4px;opacity:.3">
                </div>
                <div style="flex:1;min-width:0">
                    <?php if ($lf['cat_nombre']): ?>
                    <span style="font-size:.65rem;font-weight:700;
                                  color:<?= e($lf['cat_color'] ?? 'var(--text-muted)') ?>;
                                  text-transform:uppercase">
                        <?= e($lf['cat_nombre']) ?>
                    </span>
                    <?php endif; ?>
                    <h3 style="font-size:.875rem;font-weight:700;
                               color:var(--text-primary);margin:4px 0 6px;
                               display:-webkit-box;-webkit-line-clamp:2;
                               -webkit-box-orient:vertical;overflow:hidden">
                        <?= e($lf['titulo']) ?>
                    </h3>
                    <div style="display:flex;gap:12px;
                                font-size:.7rem;color:var(--text-muted)">
                        <span>
                            <i class="bi bi-collection"></i>
                            <?= formatNumber((int)$lf['total_updates']) ?>
                        </span>
                        <span>
                            <i class="bi bi-calendar3"></i>
                            <?= formatDate($lf['fecha_fin'] ?? $lf['fecha_inicio'], 'short') ?>
                        </span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

</div><!-- /.container -->

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php if ($liveBlog): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {

    const blogId      = <?= (int)$liveBlog['id'] ?>;
    const blogEstado  = '<?= e($liveBlog['estado']) ?>';
    let lastId        = <?= $lastUpdateId ?>;
    let pollInterval  = null;
    let newCount      = 0;
    let pendingUpdates= [];

    // ── Solo hacer polling si está activo ─────────────────────
    if (blogEstado === 'activo') {
        startPolling();

        // Pausar polling cuando la pestaña no está visible
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stopPolling();
            } else {
                startPolling();
                // Aplicar updates pendientes al volver
                if (pendingUpdates.length > 0) {
                    applyPendingUpdates();
                }
            }
        });
    }

    function startPolling() {
        if (pollInterval) return;
        pollInterval = setInterval(checkUpdates, 12000); // cada 12s
    }

    function stopPolling() {
        clearInterval(pollInterval);
        pollInterval = null;
    }

    async function checkUpdates() {
        try {
            const res  = await fetch(window.APP.url + '/ajax/handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type':     'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    action:        'get_live_updates',
                    blog_id:       blogId,
                    despues_de_id: lastId,
                }),
            });
            const data = await res.json();

            if (!data.success) return;

            // Verificar si el blog fue finalizado
            if (data.blog_estado === 'finalizado') {
                stopPolling();
                showFinalizadoBanner();
            }

            if (!data.updates?.length) return;

            // Actualizar último ID
            const maxId = Math.max(...data.updates.map(u => u.id));
            lastId = Math.max(lastId, maxId);
            newCount += data.updates.length;

            // Si la página está visible, insertar directamente
            if (!document.hidden) {
                insertUpdates(data.updates, data.total_updates);
            } else {
                // Guardar para cuando vuelva el usuario
                pendingUpdates.push(...data.updates);
            }
        } catch {}
    }

    function insertUpdates(updates, totalUpdates) {
        const container = document.getElementById('liveUpdatesContainer');
        if (!container) return;

        // Mostrar barra de nuevas updates
        showNewUpdatesBar(updates.length);

        // Insertar al principio (más reciente primero)
        [...updates].reverse().forEach(u => {
            const el = createUpdateElement(u);
            el.style.animation = 'slideInLeft 0.4s ease';
            container.insertBefore(el, container.firstChild);
        });

        // Actualizar contadores
        updateCounters(totalUpdates);

        // Reinit lazy images
        container.querySelectorAll('img[data-src]').forEach(img => {
            img.src = img.dataset.src;
            img.removeAttribute('data-src');
        });
    }

    function applyPendingUpdates() {
        if (!pendingUpdates.length) return;
        insertUpdates(pendingUpdates, null);
        pendingUpdates = [];
        newCount = 0;
    }

    function createUpdateElement(u) {
        const div = document.createElement('div');
        div.className = [
            'live-update',
            u.tipo === 'breaking'  ? 'live-update--breaking'  : '',
            u.tipo === 'alerta'    ? 'live-update--alerta'    : '',
            u.es_destacado         ? 'live-update--destacado' : '',
        ].filter(Boolean).join(' ');
        div.id       = 'live-update-' + u.id;
        div.dataset.id = u.id;

        const typeBadge = u.tipo === 'breaking'
            ? '<span class="live-update__type-badge type-breaking"><i class="bi bi-broadcast-pin"></i> BREAKING</span>'
            : u.tipo === 'alerta'
            ? '<span class="live-update__type-badge type-alerta"><i class="bi bi-exclamation-triangle-fill"></i> ALERTA</span>'
            : '';

        const contenido = u.tipo === 'cita'
            ? `<blockquote style="border-left:4px solid var(--accent);padding:12px 16px;
                                  margin:0;background:var(--bg-surface-2);
                                  border-radius:0 8px 8px 0;font-style:italic;
                                  color:var(--text-secondary)">
                    ${PDApp.escapeHtml(u.contenido)}
               </blockquote>`
            : `<div class="live-update__content">${PDApp.escapeHtml(u.contenido).replace(/\n/g, '<br>')}</div>`;

        div.innerHTML = `
            <div class="live-update__dot"></div>
            <div class="live-update__body">
                <div class="live-update__header">
                    <div class="live-update__author">
                        <img src="${PDApp.escapeHtml(u.autor.avatar)}"
                             alt="${PDApp.escapeHtml(u.autor.nombre)}"
                             width="28" height="28">
                        <strong>${PDApp.escapeHtml(u.autor.nombre)}</strong>
                    </div>
                    ${typeBadge}
                    ${u.es_destacado
                        ? '<span style="font-size:.7rem;color:var(--warning);font-weight:700"><i class="bi bi-star-fill"></i> Destacado</span>'
                        : ''}
                    <time class="live-update__time">
                        ${PDApp.escapeHtml(u.fecha_human)}
                    </time>
                </div>
                ${contenido}
                ${u.imagen
                    ? `<div style="margin-top:12px">
                            <img src="${PDApp.escapeHtml(u.imagen)}"
                                 style="width:100%;max-height:400px;object-fit:cover;
                                        border-radius:var(--border-radius-lg)"
                                 loading="lazy">
                       </div>`
                    : ''}
                <div style="display:flex;align-items:center;gap:12px;
                            margin-top:10px;padding-top:8px;
                            border-top:1px solid var(--border-color)">
                    <button onclick="shareUpdate(${u.id}, '${PDApp.escapeHtml(u.contenido.substring(0, 100))}')"
                            style="display:flex;align-items:center;gap:4px;
                                   font-size:.72rem;color:var(--text-muted);
                                   background:none;border:none;cursor:pointer">
                        <i class="bi bi-share"></i> Compartir
                    </button>
                    <span style="margin-left:auto;font-size:.7rem;color:var(--text-muted)">
                        #${u.id}
                    </span>
                </div>
            </div>`;

        return div;
    }

    function showNewUpdatesBar(count) {
        newCount = count;
        const bar  = document.getElementById('newUpdatesBar');
        const text = document.getElementById('newUpdatesText');
        if (bar && text) {
            text.textContent = count === 1
                ? '1 nueva actualización'
                : `${count} nuevas actualizaciones`;
            bar.style.display = 'block';
        }
    }

    window.scrollToNewUpdates = function () {
        const container = document.getElementById('liveUpdatesContainer');
        container?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        document.getElementById('newUpdatesBar').style.display = 'none';
        newCount = 0;
    };

    function updateCounters(total) {
        if (total === null) return;
        document.getElementById('liveTotalUpdates')
            && (document.getElementById('liveTotalUpdates').textContent = total);
        document.getElementById('sidebarTotalUpdates')
            && (document.getElementById('sidebarTotalUpdates').textContent = total);
        document.getElementById('liveUpdateCount')
            && (document.getElementById('liveUpdateCount').textContent = total + ' updates');
    }

    function showFinalizadoBanner() {
        const bar = document.querySelector('[style*="background:var(--primary)"][style*="position:sticky"]');
        if (bar) {
            bar.style.background = 'var(--secondary)';
            bar.innerHTML = bar.innerHTML.replace('EN VIVO', 'FINALIZADO');
        }
    }

    // ── Compartir update ──────────────────────────────────────
    window.shareUpdate = async function (id, text) {
        const url = window.APP.url + '/live.php?slug=<?= e($liveBlog['slug']) ?>#live-update-' + id;

        if (navigator.share) {
            try {
                await navigator.share({ title: text, url });
            } catch {}
        } else {
            try {
                await navigator.clipboard.writeText(url);
                PDApp.showToast('Enlace copiado al portapapeles.', 'success', 2000);
            } catch {
                PDApp.showToast('No se pudo copiar el enlace.', 'error');
            }
        }
    };

    // ── Reloj en vivo ─────────────────────────────────────────
    if (blogEstado === 'activo') {
        const tiempoEls = document.querySelectorAll('.live-update__time');
        setInterval(() => {
            // Solo actualizar en desktop para no sobrecargar móvil
            if (window.innerWidth < 768) return;
        }, 60000);
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
    }

});
</script>
<?php endif; ?>