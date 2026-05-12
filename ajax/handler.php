<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Handler AJAX Central
 * ============================================================
 * Archivo : ajax/handler.php
 * Versión : 2.0.0
 * ============================================================
 * Maneja TODAS las peticiones AJAX/fetch del sistema.
 * Método aceptado: POST con JSON body o form-data
 * Headers requeridos:
 *   X-Requested-With: XMLHttpRequest
 *   X-CSRF-Token: <token>
 *   Content-Type: application/json
 * ============================================================
 * ACCIONES DISPONIBLES:
 *  — Noticias      : search_suggest, get_more_news, get_noticia
 *  — Comentarios   : add_comment, vote_comment, delete_comment
 *  — Reacciones    : react_noticia, get_reactions
 *  — Favoritos     : toggle_favorite, get_favorites
 *  — Encuestas     : vote_poll, get_poll_results
 *  — Compartidos   : track_share
 *  — Newsletter    : newsletter_subscribe
 *  — Anuncios      : track_ad_click, track_ad_impression
 *  — Analytics     : track_scroll, track_heatmap
 *  — Live Blog     : get_live_updates, post_live_update
 *  — Notificaciones: get_notifications, mark_read
 *  — Usuarios      : toggle_follow, toggle_follow_cat
 *  — Auth          : check_session, refresh_csrf
 *  — Admin         : toggle_status, quick_delete, get_stats
 *  — PWA/Sistema   : get_manifest, get_clima
 *  — Reportes      : report_content
 * ============================================================
 */

declare(strict_types=1);

// ── Bootstrap ─────────────────────────────────────────────────
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// ── Solo aceptar peticiones AJAX ──────────────────────────────
if (!isAjax() && !isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    // Permitir sendBeacon (no envía X-Requested-With en algunos browsers)
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (!str_contains($contentType, 'application/json') &&
        !str_contains($contentType, 'text/plain')) {
        http_response_code(403);
        exit('Acceso denegado');
    }
}

// ── Headers de respuesta ──────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

// ── Leer input ────────────────────────────────────────────────
$rawInput = file_get_contents('php://input');
$input    = [];

if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $input = $decoded;
    } else {
        parse_str($rawInput, $input);
    }
}

// Mezclar con POST por si viene como form-data
$input = array_merge($_POST, $input);

// ── Obtener acción ────────────────────────────────────────────
$action = cleanInput($input['action'] ?? $_GET['action'] ?? '');

if (empty($action)) {
    jsonResponse(['success' => false, 'message' => 'Acción no especificada.'], 400);
}

// ── Rate limiting básico por IP ───────────────────────────────
$ip = getClientIp();
$rateLimitKey = 'rl_ajax_' . md5($ip);

if (!isset($_SESSION[$rateLimitKey])) {
    $_SESSION[$rateLimitKey] = ['count' => 0, 'ts' => time()];
}

// Resetear cada minuto
if (time() - $_SESSION[$rateLimitKey]['ts'] > 60) {
    $_SESSION[$rateLimitKey] = ['count' => 0, 'ts' => time()];
}

$_SESSION[$rateLimitKey]['count']++;

// Máximo 120 requests por minuto por IP (excepto analytics que son más frecuentes)
$analyticsActions = ['track_scroll', 'track_heatmap', 'track_ad_click', 'track_ad_impression'];
if (!in_array($action, $analyticsActions, true) &&
    $_SESSION[$rateLimitKey]['count'] > 120) {
    jsonResponse(['success' => false, 'message' => 'Demasiadas peticiones. Espera un momento.'], 429);
}

// ── Verificar CSRF para acciones que modifican datos ─────────
$csrfExempt = [
    'search_suggest', 'get_more_news', 'get_noticia',
    'get_reactions', 'get_poll_results', 'get_live_updates',
    'get_notifications', 'check_session', 'get_clima',
    'get_manifest', 'get_stats',
    'track_scroll', 'track_heatmap', // enviados con sendBeacon
];

if (!in_array($action, $csrfExempt, true)) {
    $csrfToken = $input['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';

    if (!$auth->verifyCSRF($csrfToken)) {
        jsonResponse(['success' => false, 'message' => 'Token de seguridad inválido.'], 403);
    }
}

// ── Usuario actual ────────────────────────────────────────────
$usuario = currentUser();

// ============================================================
// ROUTER DE ACCIONES
// ============================================================

try {
    switch ($action) {

        // ────────────────────────────────────────────────────
        // NOTICIAS
        // ────────────────────────────────────────────────────

        case 'search_suggest':
            $q     = cleanInput($input['q'] ?? '', 100);
            $limit = 6;

            if (mb_strlen($q) < 2) {
                jsonResponse(['results' => []]);
            }

            $results = db()->fetchAll(
                "SELECT n.titulo, n.slug, n.imagen,
                        c.nombre AS cat_nombre, c.color AS cat_color
                 FROM noticias n
                 INNER JOIN categorias c ON c.id = n.categoria_id
                 WHERE n.estado = 'publicado'
                   AND n.fecha_publicacion <= NOW()
                   AND (n.titulo LIKE ? OR n.resumen LIKE ?)
                 ORDER BY n.vistas DESC
                 LIMIT ?",
                ["%$q%", "%$q%", $limit]
            );

            // Formatear resultados
            $formatted = array_map(function ($r) {
                return [
                    'titulo'    => e($r['titulo']),
                    'slug'      => $r['slug'],
                    'imagen'    => getImageUrl($r['imagen']),
                    'cat_nombre'=> e($r['cat_nombre']),
                    'cat_color' => $r['cat_color'],
                ];
            }, $results);

            jsonResponse(['results' => $formatted]);
            break;

        // ────────────────────────────────────────────────────

        case 'get_more_news':
            $pagina     = cleanInt($input['pagina']      ?? 1, 1);
            $categoriaId= cleanInt($input['categoria_id']?? 0);
            $orden      = cleanInput($input['orden']     ?? 'recientes');
            $limit      = NOTICIAS_POR_PAGINA;
            $offset     = ($pagina - 1) * $limit;

            $noticias = getNoticiasRecientes(
                $limit,
                $offset,
                $categoriaId > 0 ? $categoriaId : null,
                $orden
            );

            $total = countNoticias($categoriaId > 0 ? $categoriaId : null);

            $html = '';
            foreach ($noticias as $n) {
                $html .= renderNoticiaCard($n);
            }

            jsonResponse([
                'success'  => true,
                'html'     => $html,
                'pagina'   => $pagina,
                'hay_mas'  => ($pagina * $limit) < $total,
                'total'    => $total,
            ]);
            break;

        // ────────────────────────────────────────────────────
        // COMENTARIOS
        // ────────────────────────────────────────────────────

        case 'add_comment':
            if (!$usuario) {
                jsonResponse(['success' => false, 'message' => 'Debes iniciar sesión para comentar.'], 401);
            }

            $noticiaId  = cleanInt($input['noticia_id'] ?? 0);
            $comentario = cleanInput($input['comentario'] ?? '');
            $padreId    = cleanInt($input['padre_id'] ?? 0) ?: null;

            if (!$noticiaId) {
                jsonResponse(['success' => false, 'message' => 'Noticia no especificada.']);
            }

            // Verificar que la noticia permite comentarios
            $noticia = db()->fetchOne(
                "SELECT id, permitir_comentarios FROM noticias WHERE id = ? LIMIT 1",
                [$noticiaId]
            );

            if (!$noticia || !$noticia['permitir_comentarios']) {
                jsonResponse(['success' => false, 'message' => 'Los comentarios están desactivados en esta noticia.']);
            }

            $result = guardarComentario(
                (int)$usuario['id'],
                $noticiaId,
                $comentario,
                $padreId
            );

            if ($result['success']) {
                // Retornar el HTML del nuevo comentario
                $newComment = db()->fetchOne(
                    "SELECT co.id, co.comentario, co.fecha, co.likes, co.dislikes,
                            co.padre_id,
                            u.id AS usuario_id, u.nombre AS usuario_nombre,
                            u.avatar AS usuario_avatar, u.rol AS usuario_rol,
                            u.verificado AS usuario_verificado
                     FROM comentarios co
                     INNER JOIN usuarios u ON u.id = co.usuario_id
                     WHERE co.id = ? LIMIT 1",
                    [$result['id']]
                );

                $result['comment_html'] = $newComment
                    ? renderComentario($newComment, false)
                    : '';
                $result['total'] = countComentarios($noticiaId);
            }

            jsonResponse($result);
            break;

        // ────────────────────────────────────────────────────

        case 'vote_comment':
            if (!$usuario) {
                jsonResponse(['success' => false, 'message' => 'Debes iniciar sesión.'], 401);
            }

            $comentarioId = cleanInt($input['comentario_id'] ?? 0);
            $tipo         = cleanInput($input['tipo'] ?? 'like');

            if (!$comentarioId) {
                jsonResponse(['success' => false, 'message' => 'Comentario no especificado.']);
            }

            $result = votarComentario((int)$usuario['id'], $comentarioId, $tipo);
            jsonResponse($result);
            break;

        // ────────────────────────────────────────────────────

        case 'delete_comment':
            if (!$usuario) {
                jsonResponse(['success' => false, 'message' => 'No autorizado.'], 401);
            }

            $comentarioId = cleanInt($input['comentario_id'] ?? 0);

            // Solo el autor del comentario o un admin puede eliminarlo
            $comentario = db()->fetchOne(
                "SELECT id, usuario_id FROM comentarios WHERE id = ? LIMIT 1",
                [$comentarioId]
            );

            if (!$comentario) {
                jsonResponse(['success' => false, 'message' => 'Comentario no encontrado.']);
            }

            if ((int)$comentario['usuario_id'] !== (int)$usuario['id'] && !isAdmin()) {
                jsonResponse(['success' => false, 'message' => 'Sin permiso para eliminar este comentario.'], 403);
            }

            db()->execute("DELETE FROM comentarios WHERE id = ?", [$comentarioId]);

            if (isAdmin()) {
                logActividad(
                    (int)$usuario['id'],
                    'delete_comment',
                    'comentarios',
                    $comentarioId,
                    'Eliminó comentario #' . $comentarioId
                );
            }

            jsonResponse(['success' => true, 'message' => 'Comentario eliminado.']);
            break;

        // ────────────────────────────────────────────────────

        case 'load_comments':
            $noticiaId = cleanInt($input['noticia_id'] ?? 0);
            $pagina    = cleanInt($input['pagina']     ?? 1, 1);
            $limit     = COMENTARIOS_POR_PAGINA;
            $offset    = ($pagina - 1) * $limit;

            if (!$noticiaId) {
                jsonResponse(['success' => false, 'message' => 'Noticia no especificada.']);
            }

            $comentarios = getComentarios($noticiaId, $limit, $offset);
            $total       = countComentarios($noticiaId);

            $html = '';
            foreach ($comentarios as $com) {
                $html .= renderComentario($com, true);
            }

            jsonResponse([
                'success' => true,
                'html'    => $html,
                'total'   => $total,
                'pagina'  => $pagina,
                'hay_mas' => ($pagina * $limit) < $total,
            ]);
            break;

        // ────────────────────────────────────────────────────
        // REACCIONES
        // ────────────────────────────────────────────────────

        case 'react_noticia':
            $noticiaId = cleanInt($input['noticia_id'] ?? 0);
            $tipo      = cleanInput($input['tipo']      ?? '');

            if (!$noticiaId || !$tipo) {
                jsonResponse(['success' => false, 'message' => 'Datos incompletos.']);
            }

            $result = reaccionarNoticia(
                $noticiaId,
                $tipo,
                $usuario ? (int)$usuario['id'] : null
            );

            jsonResponse($result);
            break;

        // ────────────────────────────────────────────────────

        case 'get_reactions':
            $noticiaId = cleanInt($input['noticia_id'] ?? 0);

            if (!$noticiaId) {
                jsonResponse(['success' => false, 'message' => 'Noticia no especificada.']);
            }

            $result = getReaccionesNoticia(
                $noticiaId,
                $usuario ? (int)$usuario['id'] : null
            );

            jsonResponse(['success' => true, 'data' => $result]);
            break;

        // ────────────────────────────────────────────────────
        // FAVORITOS
        // ────────────────────────────────────────────────────

        case 'toggle_favorite':
            if (!$usuario) {
                jsonResponse(['success' => false, 'message' => 'Debes iniciar sesión.', 'require_login' => true], 401);
            }

            $noticiaId = cleanInt($input['noticia_id'] ?? 0);

            if (!$noticiaId) {
                jsonResponse(['success' => false, 'message' => 'Noticia no especificada.']);
            }

            $result = toggleFavorito((int)$usuario['id'], $noticiaId);
            jsonResponse($result);
            break;

        // ────────────────────────────────────────────────────

        case 'get_favorites':
            if (!$usuario) {
                jsonResponse(['success' => false, 'message' => 'Debes iniciar sesión.'], 401);
            }

            $pagina = cleanInt($input['pagina'] ?? 1, 1);
            $limit  = 12;
            $offset = ($pagina - 1) * $limit;

            $favoritos = getFavoritosUsuario((int)$usuario['id'], $limit, $offset);
            $total     = db()->count(
                "SELECT COUNT(*) FROM favoritos WHERE usuario_id = ?",
                [$usuario['id']]
            );

            jsonResponse([
                'success'  => true,
                'data'     => $favoritos,
                'total'    => $total,
                'hay_mas'  => ($pagina * $limit) < $total,
            ]);
            break;

        // ────────────────────────────────────────────────────
        // ENCUESTAS
        // ────────────────────────────────────────────────────

        case 'vote_poll':
            $encuestaId = cleanInt($input['encuesta_id'] ?? 0);
            $opcionId   = cleanInt($input['opcion_id']   ?? 0);

            if (!$encuestaId || !$opcionId) {
                jsonResponse(['success' => false, 'message' => 'Datos de encuesta incompletos.']);
            }

            $result = votarEncuesta(
                $encuestaId,
                $opcionId,
                $usuario ? (int)$usuario['id'] : null
            );

            jsonResponse($result);
            break;

        // ────────────────────────────────────────────────────

        case 'get_poll_results':
            $encuestaId = cleanInt($input['encuesta_id'] ?? 0);

            if (!$encuestaId) {
                jsonResponse(['success' => false, 'message' => 'Encuesta no especificada.']);
            }

            $encuesta = db()->fetchOne(
                "SELECT id, pregunta, total_votos, activa FROM encuestas WHERE id = ? LIMIT 1",
                [$encuestaId]
            );

            if (!$encuesta) {
                jsonResponse(['success' => false, 'message' => 'Encuesta no encontrada.']);
            }

            $opciones = db()->fetchAll(
                "SELECT id, opcion, votos FROM encuesta_opciones
                 WHERE encuesta_id = ? ORDER BY orden ASC",
                [$encuestaId]
            );

            jsonResponse([
                'success'     => true,
                'pregunta'    => $encuesta['pregunta'],
                'total_votos' => (int)$encuesta['total_votos'],
                'activa'      => (bool)$encuesta['activa'],
                'opciones'    => $opciones,
            ]);
            break;
            
        // ────────────────────────────────────────────────────
        // CAMBIAR VOTO EN ENCUESTA
        // ────────────────────────────────────────────────────

        case 'change_vote':
            $encuestaId = cleanInt($input['encuesta_id'] ?? 0);
            $opcionIds  = array_map('intval', (array)($input['opcion_ids'] ?? []));
            $opcionId   = cleanInt($input['opcion_id'] ?? ($opcionIds[0] ?? 0)); // compatibilidad

            if (!$encuestaId || (empty($opcionIds) && !$opcionId)) {
                jsonResponse(['success' => false, 'message' => 'Datos incompletos.']);
            }

            // Si viene opcion_id simple, convertir a array
            if (empty($opcionIds) && $opcionId) {
                $opcionIds = [$opcionId];
            }

            $ip = getClientIp();

            // Verificar que la encuesta permite cambiar voto
            $encuesta = db()->fetchOne(
                "SELECT id, activa, tipo, puede_cambiar_voto, fecha_cierre, mostrar_resultados
                 FROM encuestas WHERE id = ? LIMIT 1",
                [$encuestaId]
            );

            if (!$encuesta || !$encuesta['activa']) {
                jsonResponse(['success' => false, 'message' => 'Encuesta no disponible.']);
            }
            if (!$encuesta['puede_cambiar_voto']) {
                jsonResponse(['success' => false, 'message' => 'Esta encuesta no permite cambiar el voto.']);
            }
            if (!empty($encuesta['fecha_cierre']) && $encuesta['fecha_cierre'] < date('Y-m-d H:i:s')) {
                jsonResponse(['success' => false, 'message' => 'Esta encuesta ya cerró.']);
            }

            // Verificar que las opciones pertenecen a la encuesta
            foreach ($opcionIds as $opId) {
                $opcion = db()->fetchOne(
                    "SELECT id FROM encuesta_opciones WHERE id = ? AND encuesta_id = ? LIMIT 1",
                    [$opId, $encuestaId]
                );
                if (!$opcion) {
                    jsonResponse(['success' => false, 'message' => 'Opción inválida.']);
                }
            }

            try {
                // Obtener votos anteriores para descontar
                if ($usuario) {
                    $votosAnteriores = db()->fetchAll(
                        "SELECT opcion_id FROM encuesta_votos WHERE encuesta_id = ? AND usuario_id = ?",
                        [$encuestaId, (int)$usuario['id']]
                    );
                } else {
                    $votosAnteriores = db()->fetchAll(
                        "SELECT opcion_id FROM encuesta_votos WHERE encuesta_id = ? AND ip = ? AND usuario_id IS NULL",
                        [$encuestaId, $ip]
                    );
                }

                $idsAnteriores = array_column($votosAnteriores, 'opcion_id');
                $numVotosAnt   = count($idsAnteriores);

                // Descontar votos de opciones anteriores
                foreach ($idsAnteriores as $opAnterior) {
                    db()->execute(
                        "UPDATE encuesta_opciones SET votos = GREATEST(0, votos - 1) WHERE id = ?",
                        [$opAnterior]
                    );
                }

                // Eliminar registros anteriores
                if ($usuario) {
                    db()->execute(
                        "DELETE FROM encuesta_votos WHERE encuesta_id = ? AND usuario_id = ?",
                        [$encuestaId, (int)$usuario['id']]
                    );
                } else {
                    db()->execute(
                        "DELETE FROM encuesta_votos WHERE encuesta_id = ? AND ip = ? AND usuario_id IS NULL",
                        [$encuestaId, $ip]
                    );
                }

                // Insertar nuevos votos
                foreach ($opcionIds as $newOpId) {
                    db()->insert(
                        "INSERT INTO encuesta_votos (encuesta_id, opcion_id, usuario_id, ip, fecha, fecha_cambio)
                         VALUES (?,?,?,?,NOW(),NOW())",
                        [$encuestaId, $newOpId, $usuario ? (int)$usuario['id'] : null, $ip]
                    );
                    db()->execute(
                        "UPDATE encuesta_opciones SET votos = votos + 1 WHERE id = ?",
                        [$newOpId]
                    );
                }

                // Recalcular total_votos
                $nuevoTotal = (int)db()->fetchColumn(
                    "SELECT COALESCE(SUM(votos),0) FROM encuesta_opciones WHERE encuesta_id = ?",
                    [$encuestaId]
                );
                db()->execute(
                    "UPDATE encuestas SET total_votos = ? WHERE id = ?",
                    [$nuevoTotal, $encuestaId]
                );

                // Retornar resultados actualizados
                $opciones = db()->fetchAll(
                    "SELECT id, opcion, votos FROM encuesta_opciones
                     WHERE encuesta_id = ? ORDER BY orden ASC",
                    [$encuestaId]
                );

                jsonResponse([
                    'success'     => true,
                    'message'     => '¡Voto actualizado correctamente!',
                    'opciones'    => $opciones,
                    'total_votos' => $nuevoTotal,
                    'cambiado'    => true,
                ]);

            } catch (\Throwable $e) {
                jsonResponse(['success' => false, 'message' => 'Error al cambiar el voto: ' . $e->getMessage()]);
            }
            break;

        // ────────────────────────────────────────────────────
        // COMPARTIDOS
        // ────────────────────────────────────────────────────

        case 'track_share':
            $noticiaId = cleanInt($input['noticia_id'] ?? 0);
            $red       = cleanInput($input['red']       ?? '');

            if ($noticiaId && $red) {
                registrarCompartido(
                    $noticiaId,
                    $red,
                    $usuario ? (int)$usuario['id'] : null
                );
            }

            jsonResponse(['success' => true]);
            break;

        // ────────────────────────────────────────────────────
        // NEWSLETTER
        // ────────────────────────────────────────────────────

        case 'newsletter_subscribe':
            $email  = cleanInput($input['email']  ?? '');
            $nombre = cleanInput($input['nombre'] ?? '');

            $result = suscribirNewsletter($email, $nombre);
            jsonResponse($result);
            break;

        // ────────────────────────────────────────────────────
        // ANUNCIOS
        // ────────────────────────────────────────────────────

        case 'track_ad_click':
            $anuncioId = cleanInt($input['anuncio_id'] ?? 0);

            if ($anuncioId) {
                try {
                    db()->execute(
                        "UPDATE anuncios SET clics = clics + 1 WHERE id = ?",
                        [$anuncioId]
                    );
                    db()->insert(
                        "INSERT INTO anuncio_log (anuncio_id, tipo, ip, user_agent)
                         VALUES (?, 'clic', ?, ?)",
                        [
                            $anuncioId,
                            $ip,
                            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                        ]
                    );
                } catch (\Throwable $e) {}
            }

            jsonResponse(['success' => true]);
            break;

        // ────────────────────────────────────────────────────

        case 'track_ad_impression':
            $anuncioId = cleanInt($input['anuncio_id'] ?? 0);

            if ($anuncioId) {
                registrarImpresionAnuncio($anuncioId);
            }

            jsonResponse(['success' => true]);
            break;

        // ────────────────────────────────────────────────────
        // ANALYTICS
        // ────────────────────────────────────────────────────

        case 'track_scroll':
            $noticiaId  = cleanInt($input['noticia_id']  ?? 0);
            $porcentaje = cleanInt($input['porcentaje']  ?? 0);
            $tiempoSeg  = cleanInt($input['tiempo_seg']  ?? 0);

            if ($noticiaId) {
                registrarScroll($noticiaId, $porcentaje, $tiempoSeg);
            }

            jsonResponse(['success' => true]);
            break;

        // ────────────────────────────────────────────────────

        case 'track_heatmap':
            $pagina    = cleanInput($input['pagina']    ?? '', 255);
            $elemento  = cleanInput($input['elemento']  ?? '', 200);
            $x         = cleanInt($input['x']           ?? 0);
            $y         = cleanInt($input['y']           ?? 0);
            $noticiaId = cleanInt($input['noticia_id']  ?? 0);

            if ($pagina && $elemento) {
                registrarHeatmapClic(
                    $pagina,
                    $elemento,
                    $x,
                    $y,
                    $noticiaId > 0 ? $noticiaId : null
                );
            }

            jsonResponse(['success' => true]);
            break;

        // ────────────────────────────────────────────────────
        // LIVE BLOG
        // ────────────────────────────────────────────────────

        case 'get_live_updates':
            $blogId      = cleanInt($input['blog_id']       ?? 0);
            $despuesDeId = cleanInt($input['despues_de_id'] ?? 0);

            if (!$blogId) {
                jsonResponse(['success' => false, 'message' => 'Blog no especificado.']);
            }

            $updates = getLiveBlogUpdates(
                $blogId,
                $despuesDeId > 0 ? $despuesDeId : null
            );

            // Verificar estado del blog
            $blog = db()->fetchOne(
                "SELECT estado, total_updates FROM live_blog WHERE id = ? LIMIT 1",
                [$blogId]
            );

            // Formatear updates para la respuesta
            $formattedUpdates = array_map(function ($u) {
                return [
                    'id'           => (int)$u['id'],
                    'contenido'    => $u['contenido'],
                    'tipo'         => $u['tipo'],
                    'imagen'       => $u['imagen'] ? getImageUrl($u['imagen']) : null,
                    'video_url'    => $u['video_url'],
                    'es_destacado' => (bool)$u['es_destacado'],
                    'fecha'        => $u['fecha'],
                    'fecha_human'  => timeAgo($u['fecha']),
                    'autor'        => [
                        'nombre' => e($u['autor_nombre']),
                        'avatar' => getImageUrl($u['autor_avatar'], 'avatar'),
                    ],
                ];
            }, $updates);

            jsonResponse([
                'success'       => true,
                'updates'       => $formattedUpdates,
                'blog_estado'   => $blog['estado']        ?? 'activo',
                'total_updates' => (int)($blog['total_updates'] ?? 0),
                'timestamp'     => time(),
            ]);
            break;

        // ────────────────────────────────────────────────────

        case 'post_live_update':
            if (!$usuario || !isAdmin()) {
                jsonResponse(['success' => false, 'message' => 'Sin permiso.'], 403);
            }

            $blogId = cleanInt($input['blog_id'] ?? 0);

            if (!$blogId) {
                jsonResponse(['success' => false, 'message' => 'Blog no especificado.']);
            }

            $result = publicarUpdateLiveBlog(
                $blogId,
                (int)$usuario['id'],
                $input
            );

            if ($result['success']) {
                logActividad(
                    (int)$usuario['id'],
                    'post_live_update',
                    'live_blog',
                    $blogId,
                    'Publicó update en live blog #' . $blogId
                );
            }

            jsonResponse($result);
            break;

        // ────────────────────────────────────────────────────

        case 'toggle_live_blog_status':
            if (!$usuario || !isAdmin()) {
                jsonResponse(['success' => false, 'message' => 'Sin permiso.'], 403);
            }

            $blogId = cleanInt($input['blog_id'] ?? 0);
            $estado = cleanInput($input['estado'] ?? '');

            if (!$blogId || !in_array($estado, ['activo', 'pausado', 'finalizado'], true)) {
                jsonResponse(['success' => false, 'message' => 'Datos inválidos.']);
            }

            $updateData = ['estado' => $estado];
            if ($estado === 'finalizado') {
                $updateData['fecha_fin'] = date('Y-m-d H:i:s');
            }

            db()->execute(
                "UPDATE live_blog SET estado = ?,
                 fecha_fin = " . ($estado === 'finalizado' ? 'NOW()' : 'fecha_fin') . "
                 WHERE id = ?",
                [$estado, $blogId]
            );

            jsonResponse(['success' => true, 'estado' => $estado]);
            break;

        // ────────────────────────────────────────────────────
        // NOTIFICACIONES
        // ────────────────────────────────────────────────────

        case 'get_notifications':
            if (!$usuario) {
                jsonResponse(['success' => false, 'message' => 'No autenticado.'], 401);
            }

            $notifs = getNotificaciones((int)$usuario['id'], 20);
            $total  = countNotificacionesNoLeidas((int)$usuario['id']);

            jsonResponse([
                'success'       => true,
                'notifications' => $notifs,
                'unread_count'  => $total,
            ]);
            break;

        // ────────────────────────────────────────────────────

        case 'mark_notifications_read':
            if (!$usuario) {
                jsonResponse(['success' => false, 'message' => 'No autenticado.'], 401);
            }

            $notifId = cleanInt($input['notif_id'] ?? 0);
            marcarNotificacionesLeidas(
                (int)$usuario['id'],
                $notifId > 0 ? $notifId : null
            );

            jsonResponse(['success' => true, 'unread_count' => 0]);
            break;

        // ────────────────────────────────────────────────────
        // USUARIOS — SEGUIR
        // ────────────────────────────────────────────────────

        case 'toggle_follow':
            if (!$usuario) {
                jsonResponse(['success' => false, 'message' => 'Debes iniciar sesión.', 'require_login' => true], 401);
            }

            $seguidoId = cleanInt($input['seguido_id'] ?? 0);

            if (!$seguidoId) {
                jsonResponse(['success' => false, 'message' => 'Usuario no especificado.']);
            }

            $result = toggleSeguirUsuario((int)$usuario['id'], $seguidoId);
            jsonResponse($result);
            break;

        // ────────────────────────────────────────────────────

        case 'toggle_follow_cat':
            if (!$usuario) {
                jsonResponse(['success' => false, 'message' => 'Debes iniciar sesión.', 'require_login' => true], 401);
            }

            $catId = cleanInt($input['categoria_id'] ?? 0);

            if (!$catId) {
                jsonResponse(['success' => false, 'message' => 'Categoría no especificada.']);
            }

            $result = toggleSeguirCategoria((int)$usuario['id'], $catId);
            jsonResponse($result);
            break;

        // ────────────────────────────────────────────────────
        // AUTH
        // ────────────────────────────────────────────────────

        case 'check_session':
            jsonResponse([
                'success'    => true,
                'logged_in'  => (bool)$usuario,
                'user_id'    => $usuario ? (int)$usuario['id']  : null,
                'user_nombre'=> $usuario ? $usuario['nombre']   : null,
                'user_rol'   => $usuario ? $usuario['rol']      : null,
                'csrf_token' => csrfToken(),
            ]);
            break;

        // ────────────────────────────────────────────────────

        case 'refresh_csrf':
            $newToken = $auth->regenerateCSRF();
            jsonResponse(['success' => true, 'csrf_token' => $newToken]);
            break;

        // ────────────────────────────────────────────────────
        // REPORTES
        // ────────────────────────────────────────────────────

        case 'report_content':
            $tipo       = cleanInput($input['tipo']        ?? '');
            $itemId     = cleanInt($input['item_id']       ?? 0);
            $motivo     = cleanInput($input['motivo']      ?? 'otro');
            $descripcion= cleanInput($input['descripcion'] ?? '', 500);

            if (!$tipo || !$itemId) {
                jsonResponse(['success' => false, 'message' => 'Datos del reporte incompletos.']);
            }

            $result = reportarContenido(
                $tipo,
                $itemId,
                $motivo,
                $usuario ? (int)$usuario['id'] : null,
                $descripcion
            );

            jsonResponse($result);
            break;

        // ────────────────────────────────────────────────────
        // ADMIN — ACCIONES RÁPIDAS
        // ────────────────────────────────────────────────────

        case 'admin_toggle_status':
            if (!$usuario || !isAdmin()) {
                jsonResponse(['success' => false, 'message' => 'Sin permiso.'], 403);
            }

            $tabla   = cleanInput($input['tabla']   ?? '');
            $id      = cleanInt($input['id']         ?? 0);
            $campo   = cleanInput($input['campo']    ?? 'activo');
            $valor   = (int)(bool)($input['valor']   ?? false);

            $tablasPermitidas = ['noticias','categorias','anuncios','videos','usuarios','comentarios'];
            $camposPermitidos = ['activo','destacado','breaking','aprobado','es_premium'];

            if (!in_array($tabla, $tablasPermitidas, true) ||
                !in_array($campo, $camposPermitidos, true) ||
                !$id) {
                jsonResponse(['success' => false, 'message' => 'Operación no permitida.']);
            }

            db()->execute(
                "UPDATE `$tabla` SET `$campo` = ? WHERE id = ?",
                [$valor, $id]
            );

            logActividad(
                (int)$usuario['id'],
                "toggle_{$campo}",
                $tabla,
                $id,
                "Cambió $campo a $valor en $tabla #$id"
            );

            // Invalidar caché si es necesario
            if ($tabla === 'categorias') {
                db()->invalidateCachePrefix('categorias_');
            }

            jsonResponse(['success' => true, 'valor' => $valor]);
            break;

        // ────────────────────────────────────────────────────

        case 'admin_quick_delete':
            if (!$usuario || !isAdmin()) {
                jsonResponse(['success' => false, 'message' => 'Sin permiso.'], 403);
            }

            $tabla = cleanInput($input['tabla'] ?? '');
            $id    = cleanInt($input['id']       ?? 0);

            $tablasPermitidas = ['noticias','comentarios','tags','anuncios','videos','live_blog'];

            if (!in_array($tabla, $tablasPermitidas, true) || !$id) {
                jsonResponse(['success' => false, 'message' => 'Operación no permitida.']);
            }

            // Para noticias, verificar que el admin tenga permiso
            if ($tabla === 'noticias' && !can('noticias', 'eliminar')) {
                jsonResponse(['success' => false, 'message' => 'Sin permiso para eliminar noticias.'], 403);
            }

            // Obtener info antes de eliminar (para el log)
            $item = db()->fetchOne("SELECT * FROM `$tabla` WHERE id = ? LIMIT 1", [$id]);

            db()->execute("DELETE FROM `$tabla` WHERE id = ?", [$id]);

            logActividad(
                (int)$usuario['id'],
                "delete_{$tabla}",
                $tabla,
                $id,
                "Eliminó registro #$id de $tabla"
            );

            jsonResponse(['success' => true, 'message' => 'Elemento eliminado correctamente.']);
            break;

        // ────────────────────────────────────────────────────

        case 'get_stats':
            if (!$usuario || !isAuditor()) {
                jsonResponse(['success' => false, 'message' => 'Sin permiso.'], 403);
            }

            $tipo = cleanInput($input['tipo'] ?? 'general');

            $data = match($tipo) {
                'visitas'    => getVisitasDiarias(cleanInt($input['dias'] ?? 14, 1, 90)),
                'general'    => getEstadisticasAdmin(),
                'populares'  => getNoticiasPopulares(10, cleanInput($input['periodo'] ?? 'semana')),
                'autores'    => getRankingAutores(10),
                default      => [],
            };

            jsonResponse(['success' => true, 'data' => $data, 'tipo' => $tipo]);
            break;

        // ────────────────────────────────────────────────────

        case 'admin_approve_comment':
            if (!$usuario || !can('comentarios', 'aprobar')) {
                jsonResponse(['success' => false, 'message' => 'Sin permiso.'], 403);
            }

            $comentarioId = cleanInt($input['comentario_id'] ?? 0);
            $aprobar      = (bool)($input['aprobar'] ?? true);

            if (!$comentarioId) {
                jsonResponse(['success' => false, 'message' => 'Comentario no especificado.']);
            }

            db()->execute(
                "UPDATE comentarios SET aprobado = ? WHERE id = ?",
                [(int)$aprobar, $comentarioId]
            );

            logActividad(
                (int)$usuario['id'],
                $aprobar ? 'approve_comment' : 'reject_comment',
                'comentarios',
                $comentarioId
            );

            jsonResponse(['success' => true, 'aprobado' => $aprobar]);
            break;

        // ────────────────────────────────────────────────────

        case 'admin_resolve_report':
            if (!$usuario || !isAdmin()) {
                jsonResponse(['success' => false, 'message' => 'Sin permiso.'], 403);
            }

            $reporteId = cleanInt($input['reporte_id'] ?? 0);
            $estado    = cleanInput($input['estado']    ?? 'resuelto');
            $nota      = cleanInput($input['nota']      ?? '', 500);

            if (!$reporteId ||
                !in_array($estado, ['revisado','resuelto','rechazado'], true)) {
                jsonResponse(['success' => false, 'message' => 'Datos inválidos.']);
            }

            db()->execute(
                "UPDATE reportes_contenido SET estado = ?, admin_nota = ? WHERE id = ?",
                [$estado, $nota, $reporteId]
            );

            logActividad(
                (int)$usuario['id'],
                'resolve_report',
                'reportes',
                $reporteId,
                "Reporte marcado como: $estado"
            );

            jsonResponse(['success' => true, 'estado' => $estado]);
            break;

        // ────────────────────────────────────────────────────
        // SISTEMA
        // ────────────────────────────────────────────────────

        case 'get_clima':
            $ciudad = cleanInput($input['ciudad'] ?? '');
            $data   = getClima($ciudad ?: null);

            if ($data) {
                jsonResponse(['success' => true, 'data' => $data]);
            } else {
                jsonResponse(['success' => false, 'message' => 'No se pudo obtener el clima.']);
            }
            break;

        // ────────────────────────────────────────────────────

        case 'get_manifest':
            header('Content-Type: application/manifest+json');
            echo generatePWAManifest();
            exit;

        // ────────────────────────────────────────────────────

        case 'save_config':
            if (!$usuario || !isSuperAdmin()) {
                jsonResponse(['success' => false, 'message' => 'Solo el Super Admin puede cambiar configuraciones.'], 403);
            }

            $configs = $input['configs'] ?? [];

            if (!is_array($configs) || empty($configs)) {
                jsonResponse(['success' => false, 'message' => 'No hay configuraciones que guardar.']);
            }

            // Sanitizar y guardar
            $sanitized = [];
            foreach ($configs as $key => $val) {
                $key             = cleanInput($key, 100);
                $val             = is_array($val) ? json_encode($val) : cleanInput($val, 5000);
                $sanitized[$key] = $val;
            }

            $success = Config::setMultiple($sanitized);

            if ($success) {
                logActividad(
                    (int)$usuario['id'],
                    'update_config',
                    'configuracion',
                    null,
                    'Actualizó ' . count($sanitized) . ' configuraciones'
                );
            }

            jsonResponse([
                'success' => $success,
                'message' => $success
                    ? 'Configuración guardada correctamente.'
                    : 'Error al guardar la configuración.',
            ]);
            break;

        // ────────────────────────────────────────────────────
        
        
        // TASAS DE CAMBIO — Actualización manual desde el panel admin
        // ────────────────────────────────────────────────────────────
        case 'update_exchange_rates':
        
            // Solo admins
            if (!$usuario || !isAdmin()) {
                jsonResponse(['success' => false, 'message' => 'Sin permisos.'], 403);
            }
        
            // ── Obtener API Key ─────────────────────────────────────
            $exApiKey = '';
        
            // Prioridad 1: constante definida en database.php
            if (defined('EXCHANGE_API_KEY') && !empty(trim(EXCHANGE_API_KEY))) {
                $exApiKey = trim(EXCHANGE_API_KEY);
            }
        
            // Prioridad 2: BD (campo guardado desde el panel)
            if (empty($exApiKey)) {
                $exApiKey = trim(Config::get('exchange_api_key', ''));
            }
        
            if (empty($exApiKey)) {
                jsonResponse([
                    'success' => false,
                    'message' => 'No hay API Key configurada. Agrégala en la tarjeta "Tasas de Cambio" del panel.',
                ]);
            }
        
            // ── Función interna: llamar a ExchangeRate-API ──────────
            $fetchRates = function (string $key, string $base) use ($exApiKey): ?array {
                $url = "https://v6.exchangerate-api.com/v6/{$key}/latest/{$base}";
                $ctx = stream_context_create([
                    'http' => [
                        'timeout'       => 12,
                        'ignore_errors' => true,
                        'method'        => 'GET',
                        'header'        => 'User-Agent: ElClickRD/2.0 PHP/' . PHP_VERSION . "\r\n",
                    ],
                ]);
                $json = @file_get_contents($url, false, $ctx);
                if (!$json) return null;
                $data = json_decode($json, true);
                if (json_last_error() !== JSON_ERROR_NONE) return null;
                return $data;
            };
        
            // ── Paso 1: Base USD ────────────────────────────────────
            $dataUSD = $fetchRates($exApiKey, 'USD');
        
            if (!$dataUSD || ($dataUSD['result'] ?? '') !== 'success') {
                $errType = $dataUSD['error-type'] ?? 'error_desconocido';
        
                $mensajesError = [
                    'invalid-key'       => 'API Key inválida. Verifica que sea correcta en ExchangeRate-API.com.',
                    'inactive-account'  => 'Cuenta inactiva. Confirma tu email en ExchangeRate-API.com.',
                    'quota-reached'     => 'Se agotó el límite de solicitudes del plan gratuito (1.500/mes).',
                    'malformed-request' => 'Solicitud mal formada. Revisa la API Key.',
                ];
        
                $msgError = $mensajesError[$errType]
                    ?? "Error de API: {$errType}. Revisa en logs/update_rates.log";
        
                jsonResponse(['success' => false, 'message' => $msgError]);
            }
        
            $rates  = $dataUSD['conversion_rates'] ?? [];
            $usdDOP = round((float)($rates['DOP'] ?? 0), 4);
            $usdEUR = (float)($rates['EUR'] ?? 0);
        
            if ($usdDOP <= 0) {
                jsonResponse([
                    'success' => false,
                    'message' => 'La API no devolvió la tasa DOP. Intenta de nuevo.',
                ]);
            }
        
            // ── Paso 2: EUR→DOP directo ──────────────────────────────
            $eurDOP = null;
            $dataEUR = $fetchRates($exApiKey, 'EUR');
        
            if ($dataEUR && ($dataEUR['result'] ?? '') === 'success') {
                $rEUR   = $dataEUR['conversion_rates'] ?? [];
                $eurDOP = round((float)($rEUR['DOP'] ?? 0), 4);
            }
        
            // Fallback: calcular EUR→DOP desde las ratios USD
            if (($eurDOP === null || $eurDOP <= 0) && $usdEUR > 0) {
                $eurDOP = round($usdDOP / $usdEUR, 4);
            }
        
            if (!$eurDOP || $eurDOP <= 0) {
                jsonResponse([
                    'success' => false,
                    'message' => 'No se pudo obtener la tasa EUR→DOP. Intenta de nuevo.',
                ]);
            }
        
            // ── Paso 3: Guardar en BD ────────────────────────────────
            $now  = date('Y-m-d H:i:s');
            $keys = [
                'tasa_usd_dop'              => [(string)$usdDOP, 'Tasa USD → DOP'],
                'tasa_eur_dop'              => [(string)$eurDOP, 'Tasa EUR → DOP'],
                'tasa_ultima_actualizacion' => [$now,            'Última actualización de tasas'],
                'tasa_usd_eur'              => [(string)round($usdEUR, 6), 'Tasa USD → EUR'],
            ];
        
            foreach ($keys as $clave => [$valor, $desc]) {
                $existe = db()->fetchOne(
                    "SELECT id FROM configuracion_global WHERE clave = ? LIMIT 1",
                    [$clave]
                );
                if ($existe) {
                    db()->execute(
                        "UPDATE configuracion_global SET valor = ?, fecha_update = NOW() WHERE clave = ?",
                        [$valor, $clave]
                    );
                } else {
                    db()->execute(
                        "INSERT INTO configuracion_global (clave, valor, tipo, grupo, descripcion)
                         VALUES (?, ?, 'numero', 'tasas', ?)",
                        [$clave, $valor, $desc]
                    );
                }
            }
        
            // ── Log de actividad ─────────────────────────────────────
            logActividad(
                (int)$usuario['id'],
                'update_exchange_rates',
                'configuracion',
                null,
                "Actualizó tasas: 1 USD = {$usdDOP} DOP | 1 EUR = {$eurDOP} DOP"
            );
        
            jsonResponse([
                'success' => true,
                'message' => "Tasas actualizadas correctamente.",
                'usd_dop' => $usdDOP,
                'eur_dop' => $eurDOP,
                'fecha'   => $now,
            ]);
            break;

        case 'create_backup':
            if (!$usuario || !isSuperAdmin()) {
                jsonResponse(['success' => false, 'message' => 'Sin permiso.'], 403);
            }

            $result = crearBackup((int)$usuario['id']);

            if ($result['success']) {
                logActividad(
                    (int)$usuario['id'],
                    'create_backup',
                    'sistema',
                    null,
                    'Backup creado: ' . $result['archivo']
                );
            }

            jsonResponse($result);
            break;

        // ────────────────────────────────────────────────────

        case 'search_advanced':
            $q          = cleanInput($input['q']            ?? '');
            $catId      = cleanInt($input['categoria_id']   ?? 0);
            $desde      = cleanInput($input['fecha_desde']  ?? '');
            $hasta      = cleanInput($input['fecha_hasta']  ?? '');
            $pagina     = cleanInt($input['pagina']         ?? 1, 1);
            $limit      = NOTICIAS_POR_PAGINA;
            $offset     = ($pagina - 1) * $limit;

            $resultados = buscarNoticias(
                $q,
                $catId > 0 ? $catId : null,
                $desde ?: null,
                $hasta ?: null,
                $limit,
                $offset
            );

            $total = countBusqueda(
                $q,
                $catId > 0 ? $catId : null,
                $desde ?: null,
                $hasta ?: null
            );

            jsonResponse([
                'success'    => true,
                'resultados' => $resultados,
                'total'      => $total,
                'pagina'     => $pagina,
                'hay_mas'    => ($pagina * $limit) < $total,
            ]);
            break;

        // ────────────────────────────────────────────────────

        default:
            jsonResponse([
                'success' => false,
                'message' => "Acción '$action' no reconocida.",
            ], 404);
            break;
    }

} catch (\Throwable $e) {
    // Log del error
    error_log('[AJAX Handler Error] Action: ' . $action .
              ' | Error: ' . $e->getMessage() .
              ' | File: ' . $e->getFile() .
              ' | Line: ' . $e->getLine());

    $response = ['success' => false, 'message' => 'Error interno del servidor.'];

    if (APP_DEBUG) {
        $response['debug'] = [
            'error'   => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => explode("\n", $e->getTraceAsString()),
        ];
    }

    jsonResponse($response, 500);
}

// ============================================================
// FUNCIONES AUXILIARES DEL HANDLER
// ============================================================

/**
 * Renderizar HTML de una tarjeta de noticia (para scroll infinito)
 */
function renderNoticiaCard(array $n): string
{
    $url     = APP_URL . '/noticia.php?slug=' . $n['slug'];
    $catUrl  = APP_URL . '/categoria.php?slug=' . ($n['cat_slug'] ?? '');
    $imagen  = getImageUrl($n['imagen'] ?? '');
    $tiempo  = $n['tiempo_lectura'] ?? 1;

    return '
    <article class="news-card animate-in" data-id="' . (int)$n['id'] . '">
        <a href="' . e($url) . '" class="news-card__img-wrap">
            <img src="' . e($imagen) . '"
                 alt="' . e($n['titulo']) . '"
                 loading="lazy"
                 class="news-card__img">
            ' . (!empty($n['breaking']) ? '<span class="news-card__badge badge-breaking">Breaking</span>' : '') . '
            ' . (!empty($n['es_premium']) ? '<span class="news-card__badge badge-premium"><i class="bi bi-star-fill"></i> Premium</span>' : '') . '
        </a>
        <div class="news-card__body">
            <a href="' . e($catUrl) . '"
               class="news-card__cat"
               style="color:' . e($n['cat_color'] ?? '#e63946') . '">
                ' . e($n['cat_nombre'] ?? '') . '
            </a>
            <h3 class="news-card__title">
                <a href="' . e($url) . '">' . e($n['titulo']) . '</a>
            </h3>
            <div class="news-card__meta">
                <span class="news-card__author">
                    <i class="bi bi-person"></i>
                    ' . e($n['autor_nombre'] ?? '') . '
                </span>
                <span class="news-card__time">
                    <i class="bi bi-clock"></i>
                    ' . e(timeAgo($n['fecha_publicacion'] ?? '')) . '
                </span>
                <span class="news-card__read">
                    <i class="bi bi-book"></i>
                    ' . (int)$tiempo . ' min
                </span>
                <span class="news-card__views">
                    <i class="bi bi-eye"></i>
                    ' . formatNumber((int)($n['vistas'] ?? 0)) . '
                </span>
            </div>
        </div>
    </article>';
}

/**
 * Renderizar HTML de un comentario (para carga dinámica)
 */
function renderComentario(array $com, bool $conRespuestas = false): string
{
    $avatarUrl = getImageUrl($com['usuario_avatar'] ?? '', 'avatar');
    $esAdmin   = in_array($com['usuario_rol'] ?? '', ['super_admin','admin'], true);
    $verificado = !empty($com['usuario_verificado']);

    $html = '
    <div class="comment-item" id="comment-' . (int)$com['id'] . '"
         data-id="' . (int)$com['id'] . '"
         data-padre="' . (int)($com['padre_id'] ?? 0) . '">
        <div class="comment-avatar">
            <img src="' . e($avatarUrl) . '"
                 alt="' . e($com['usuario_nombre'] ?? '') . '"
                 width="40" height="40" loading="lazy">
        </div>
        <div class="comment-body">
            <div class="comment-header">
                <strong class="comment-author">' . e($com['usuario_nombre'] ?? '') . '</strong>
                ' . ($esAdmin ? '<span class="badge-staff">Staff</span>' : '') . '
                ' . ($verificado ? '<i class="bi bi-patch-check-fill text-primary" title="Verificado"></i>' : '') . '
                <time class="comment-time" datetime="' . e($com['fecha'] ?? '') . '">
                    ' . e(timeAgo($com['fecha'] ?? '')) . '
                </time>
            </div>
            <p class="comment-text">' . nl2br(e($com['comentario'] ?? '')) . '</p>
            <div class="comment-actions">
                <button class="comment-vote-btn"
                        data-comentario-id="' . (int)$com['id'] . '"
                        data-tipo="like"
                        onclick="PDApp.voteComment(this)">
                    <i class="bi bi-hand-thumbs-up"></i>
                    <span class="like-count">' . (int)($com['likes'] ?? 0) . '</span>
                </button>
                <button class="comment-vote-btn"
                        data-comentario-id="' . (int)$com['id'] . '"
                        data-tipo="dislike"
                        onclick="PDApp.voteComment(this)">
                    <i class="bi bi-hand-thumbs-down"></i>
                    <span class="dislike-count">' . (int)($com['dislikes'] ?? 0) . '</span>
                </button>
                <button class="comment-reply-btn"
                        data-id="' . (int)$com['id'] . '"
                        data-nombre="' . e($com['usuario_nombre'] ?? '') . '"
                        onclick="PDApp.showReplyForm(this)">
                    <i class="bi bi-reply"></i> Responder
                </button>
                <button class="comment-report-btn"
                        data-id="' . (int)$com['id'] . '"
                        onclick="PDApp.reportContent(\'comentario\', ' . (int)$com['id'] . ')">
                    <i class="bi bi-flag"></i>
                </button>
            </div>
            <div class="reply-form-wrap" id="reply-form-' . (int)$com['id'] . '" hidden></div>';

    // Respuestas anidadas
    if ($conRespuestas && !empty($com['respuestas'])) {
        $html .= '<div class="comment-replies">';
        foreach ($com['respuestas'] as $respuesta) {
            $html .= renderComentario($respuesta, false);
        }
        $html .= '</div>';
    }

    $html .= '
        </div>
    </div>';

    return $html;
}