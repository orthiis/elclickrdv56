<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Admin: Gestión de Noticias
 * ============================================================
 * Archivo : admin/noticias.php
 * Versión : 2.0.0
 * ============================================================
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$auth->requireAdmin();
$usuario = currentUser();

// ── Parámetros de acción ──────────────────────────────────────
$action   = trim($_GET['action'] ?? 'lista');
$noticiaId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ============================================================
// AJAX — Acciones rápidas (POST JSON)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $ajaxAc = $input['action'] ?? ($_POST['action'] ?? '');

    if (!$auth->verifyCSRF($input['csrf'] ?? ($_POST['csrf_token'] ?? ''))) {
        echo json_encode(['success' => false, 'message' => 'Token CSRF inválido.']);
        exit;
    }

    switch ($ajaxAc) {

        // ── Eliminar noticia ──────────────────────────────────
        case 'eliminar':
            $id = (int)($input['id'] ?? 0);
            if (!$id) { echo json_encode(['success'=>false,'message'=>'ID inválido']); exit; }
            if (!$auth->can('noticias','eliminar')) {
                echo json_encode(['success'=>false,'message'=>'Sin permiso']); exit;
            }
            try {
                // Obtener imagen antes de borrar
                $n = db()->fetchOne("SELECT imagen FROM noticias WHERE id = ?", [$id]);
                db()->execute("DELETE FROM noticias WHERE id = ?", [$id]);
                // Eliminar imagen si existe
                if (!empty($n['imagen'])) {
                    $imgPath = dirname(__DIR__) . '/uploads/' . $n['imagen'];
                    if (file_exists($imgPath)) @unlink($imgPath);
                }
                logActividad((int)$usuario['id'], 'delete_noticia', 'noticias', $id, "Eliminó noticia #$id");
                echo json_encode(['success'=>true,'message'=>'Noticia eliminada correctamente.']);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>'Error al eliminar: '.$e->getMessage()]);
            }
            exit;

        // ── Cambiar estado ────────────────────────────────────
        case 'cambiar_estado':
            $id     = (int)($input['id'] ?? 0);
            $estado = trim($input['estado'] ?? '');
            $permitidos = ['publicado','borrador','programado','archivado'];
            if (!$id || !in_array($estado, $permitidos)) {
                echo json_encode(['success'=>false,'message'=>'Datos inválidos']); exit;
            }
            try {
                $fechaPub = ($estado === 'publicado')
                    ? ", fecha_publicacion = IFNULL(fecha_publicacion, NOW())"
                    : "";
                db()->execute(
                    "UPDATE noticias SET estado = ? $fechaPub WHERE id = ?",
                    [$estado, $id]
                );
                logActividad((int)$usuario['id'], 'cambiar_estado_noticia', 'noticias', $id, "Estado → $estado");
                echo json_encode(['success'=>true,'message'=>"Estado cambiado a $estado",'nuevo_estado'=>$estado]);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
            }
            exit;

        // ── Toggle campo booleano ─────────────────────────────
        case 'toggle_campo':
            $id    = (int)($input['id'] ?? 0);
            $campo = trim($input['campo'] ?? '');
            $camposPermitidos = ['destacado','breaking','es_premium','es_opinion','allow_comments','permitir_comentarios'];
            if (!$id || !in_array($campo, $camposPermitidos)) {
                echo json_encode(['success'=>false,'message'=>'Campo no permitido']); exit;
            }
            try {
                db()->execute(
                    "UPDATE noticias SET `$campo` = NOT `$campo` WHERE id = ?",
                    [$id]
                );
                $nuevo = (int)db()->fetchColumn("SELECT `$campo` FROM noticias WHERE id = ?", [$id]);
                echo json_encode(['success'=>true,'valor'=>$nuevo]);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
            }
            exit;

        // ── Eliminar masivo ───────────────────────────────────
        case 'eliminar_masivo':
            $ids = array_map('intval', $input['ids'] ?? []);
            if (empty($ids)) { echo json_encode(['success'=>false,'message'=>'Sin IDs']); exit; }
            if (!$auth->can('noticias','eliminar')) {
                echo json_encode(['success'=>false,'message'=>'Sin permiso']); exit;
            }
            try {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                db()->execute("DELETE FROM noticias WHERE id IN ($placeholders)", $ids);
                logActividad((int)$usuario['id'], 'delete_masivo_noticias', 'noticias', null, "Eliminó ".count($ids)." noticias");
                echo json_encode(['success'=>true,'message'=>count($ids).' noticias eliminadas.']);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
            }
            exit;

        // ── Duplicar noticia ──────────────────────────────────
        case 'duplicar':
            $id = (int)($input['id'] ?? 0);
            if (!$id) { echo json_encode(['success'=>false,'message'=>'ID inválido']); exit; }
            try {
                $orig = db()->fetchOne("SELECT * FROM noticias WHERE id = ?", [$id]);
                if (!$orig) { echo json_encode(['success'=>false,'message'=>'No encontrada']); exit; }
                $nuevoTitulo = 'Copia de '.$orig['titulo'];
                $nuevoSlug   = generateSlug($nuevoTitulo, 'noticias');
                $nuevoId = db()->insert(
                    "INSERT INTO noticias
                     (titulo,slug,resumen,contenido,imagen,imagen_alt,imagen_caption,
                      autor_id,categoria_id,estado,destacado,breaking,es_premium,es_opinion,
                      allow_comments,fuente,fuente_url,video_url,tiempo_lectura,
                      meta_title,meta_description,keywords,fecha_creacion)
                     SELECT ?,?,resumen,contenido,imagen,imagen_alt,imagen_caption,
                            autor_id,categoria_id,'borrador',0,0,es_premium,es_opinion,
                            allow_comments,fuente,fuente_url,video_url,tiempo_lectura,
                            meta_title,meta_description,keywords,NOW()
                     FROM noticias WHERE id = ?",
                    [$nuevoTitulo, $nuevoSlug, $id]
                );
                echo json_encode(['success'=>true,'message'=>'Duplicada correctamente.','nuevo_id'=>$nuevoId]);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
            }
            exit;

        // ── Subir imagen ──────────────────────────────────────
        case 'subir_imagen':
            if (!isset($_FILES['imagen'])) {
                echo json_encode(['success'=>false,'message'=>'No se recibió archivo']); exit;
            }
            $result = uploadImage($_FILES['imagen'], 'noticias');
            echo json_encode($result);
            exit;

        default:
            echo json_encode(['success'=>false,'message'=>'Acción desconocida']);
            exit;
    }
}

// ============================================================
// GUARDAR / EDITAR NOTICIA (POST normal)
// ============================================================
$formErrors  = [];
$formSuccess = '';
$noticia     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_noticia'])) {

    if (!$auth->verifyCSRF($_POST['csrf_token'] ?? '')) {
        $formErrors[] = 'Token de seguridad inválido. Recarga la página.';
    } else {

        $editId    = (int)($_POST['noticia_id'] ?? 0);
        $titulo    = trim($_POST['titulo'] ?? '');
        $resumen   = trim($_POST['resumen'] ?? '');
        $contenido = $_POST['contenido'] ?? '';
        $catId     = (int)($_POST['categoria_id'] ?? 0);
        $estado    = trim($_POST['estado'] ?? 'borrador');
        $destacado = isset($_POST['destacado']) ? 1 : 0;
        $breaking  = isset($_POST['breaking']) ? 1 : 0;
        $esPremium = isset($_POST['es_premium']) ? 1 : 0;
        $esOpinion = isset($_POST['es_opinion']) ? 1 : 0;
        $allowCom  = isset($_POST['allow_comments']) ? 1 : 0;
        $fuente    = trim($_POST['fuente'] ?? '');
        $fuenteUrl = trim($_POST['fuente_url'] ?? '');
        $videoUrl  = trim($_POST['video_url'] ?? '');
        $metaTitle = trim($_POST['meta_title'] ?? '');
        $metaDesc  = trim($_POST['meta_description'] ?? '');
        $keywords  = trim($_POST['keywords'] ?? '');
        $imagenAlt = trim($_POST['imagen_alt'] ?? '');
        $imagenCap = trim($_POST['imagen_caption'] ?? '');
        $fechaPub  = trim($_POST['fecha_publicacion'] ?? '');
        $tagsIds   = array_map('intval', $_POST['tags'] ?? []);
        $imagenActual = trim($_POST['imagen_actual'] ?? '');

        // Validaciones
        if (mb_strlen($titulo) < 5)        $formErrors[] = 'El título debe tener al menos 5 caracteres.';
        if (mb_strlen($titulo) > 300)       $formErrors[] = 'El título no puede exceder 300 caracteres.';
        if (empty($contenido))              $formErrors[] = 'El contenido es obligatorio.';
        if (!$catId)                        $formErrors[] = 'Debes seleccionar una categoría.';
        if (!in_array($estado, ['publicado','borrador','programado','archivado']))
                                            $formErrors[] = 'Estado inválido.';
        if ($estado === 'programado' && empty($fechaPub))
                                            $formErrors[] = 'Debes indicar la fecha de publicación programada.';

        // Procesar imagen
        $imagenFinal = $imagenActual;
        if (!empty($_FILES['imagen']['name'])) {
            $uploadResult = uploadImage($_FILES['imagen'], 'noticias');
            if ($uploadResult['success']) {
                $imagenFinal = $uploadResult['path'];
                // Borrar imagen anterior si existe
                if (!empty($imagenActual)) {
                    $oldPath = dirname(__DIR__) . '/uploads/' . $imagenActual;
                    if (file_exists($oldPath)) @unlink($oldPath);
                }
            } else {
                $formErrors[] = 'Error al subir imagen: ' . $uploadResult['message'];
            }
        }

        if (empty($formErrors)) {
            try {
                $tiempoLectura = calcularTiempoLectura($contenido);
                $fechaPubFinal = $estado === 'publicado'
                    ? (empty($fechaPub) ? date('Y-m-d H:i:s') : $fechaPub)
                    : (empty($fechaPub) ? null : $fechaPub);

                if ($editId) {
                    // ACTUALIZAR
                    $slug = generateSlug($titulo, 'noticias', $editId);
                    db()->execute(
                        "UPDATE noticias SET
                            titulo = ?, slug = ?, resumen = ?, contenido = ?,
                            imagen = ?, imagen_alt = ?, imagen_caption = ?,
                            categoria_id = ?, estado = ?,
                            destacado = ?, breaking = ?, es_premium = ?, es_opinion = ?,
                            allow_comments = ?, permitir_comentarios = ?,
                            fuente = ?, fuente_url = ?, video_url = ?,
                            meta_title = ?, meta_description = ?, keywords = ?,
                            tiempo_lectura = ?, fecha_publicacion = ?,
                            fecha_actualizacion = NOW()
                         WHERE id = ?",
                        [
                            $titulo, $slug, $resumen, $contenido,
                            $imagenFinal, $imagenAlt, $imagenCap,
                            $catId, $estado,
                            $destacado, $breaking, $esPremium, $esOpinion,
                            $allowCom, $allowCom,
                            $fuente, $fuenteUrl, $videoUrl,
                            $metaTitle, $metaDesc, $keywords,
                            $tiempoLectura, $fechaPubFinal,
                            $editId
                        ]
                    );
                    // Sincronizar tags
                    db()->execute("DELETE FROM noticia_tags WHERE noticia_id = ?", [$editId]);
                    foreach ($tagsIds as $tid) {
                        if ($tid > 0) {
                            db()->execute(
                                "INSERT IGNORE INTO noticia_tags (noticia_id, tag_id) VALUES (?,?)",
                                [$editId, $tid]
                            );
                        }
                    }
                    logActividad((int)$usuario['id'], 'edit_noticia', 'noticias', $editId, "Editó noticia: $titulo");
                    $formSuccess = 'Noticia actualizada correctamente.';
                    $action = 'editar';
                    $noticiaId = $editId;
                } else {
                    // CREAR
                    $slug = generateSlug($titulo, 'noticias');
                    $newId = db()->insert(
                        "INSERT INTO noticias
                            (titulo, slug, resumen, contenido,
                             imagen, imagen_alt, imagen_caption,
                             autor_id, categoria_id, estado,
                             destacado, breaking, es_premium, es_opinion,
                             allow_comments, permitir_comentarios,
                             fuente, fuente_url, video_url,
                             meta_title, meta_description, keywords,
                             tiempo_lectura, fecha_publicacion, fecha_creacion)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
                        [
                            $titulo, $slug, $resumen, $contenido,
                            $imagenFinal, $imagenAlt, $imagenCap,
                            (int)$usuario['id'], $catId, $estado,
                            $destacado, $breaking, $esPremium, $esOpinion,
                            $allowCom, $allowCom,
                            $fuente, $fuenteUrl, $videoUrl,
                            $metaTitle, $metaDesc, $keywords,
                            $tiempoLectura, $fechaPubFinal
                        ]
                    );
                    // Insertar tags
                    foreach ($tagsIds as $tid) {
                        if ($tid > 0) {
                            db()->execute(
                                "INSERT IGNORE INTO noticia_tags (noticia_id, tag_id) VALUES (?,?)",
                                [$newId, $tid]
                            );
                        }
                    }
                    logActividad((int)$usuario['id'], 'create_noticia', 'noticias', $newId, "Creó noticia: $titulo");
                    $formSuccess = 'Noticia creada correctamente.';
                    // Redirigir a edición para continuar
                    header("Location: " . APP_URL . "/admin/noticias.php?action=editar&id=$newId&created=1");
                    exit;
                }
            } catch (\Throwable $e) {
                $formErrors[] = 'Error de base de datos: ' . ($e->getMessage());
            }
        }
    }
}

// ============================================================
// CARGAR DATOS PARA EDICIÓN
// ============================================================
if (in_array($action, ['editar', 'nueva']) && $noticiaId > 0) {
    $noticia = db()->fetchOne(
        "SELECT n.*,
                c.nombre AS cat_nombre,
                u.nombre AS autor_nombre
         FROM noticias n
         INNER JOIN categorias c ON c.id = n.categoria_id
         INNER JOIN usuarios   u ON u.id = n.autor_id
         WHERE n.id = ?
         LIMIT 1",
        [$noticiaId]
    );
    if (!$noticia) {
        header('Location: ' . APP_URL . '/admin/noticias.php?error=no_encontrada');
        exit;
    }
    // Tags de la noticia
    $noticiaTags = db()->fetchAll(
        "SELECT tag_id FROM noticia_tags WHERE noticia_id = ?",
        [$noticiaId]
    );
    $noticiaTagIds = array_column($noticiaTags, 'tag_id');
    $action = 'editar';
} else {
    $noticiaTagIds = [];
}

// ============================================================
// LISTA DE NOTICIAS (con filtros + paginación)
// ============================================================
if ($action === 'lista') {

    // Filtros
    $filtroEstado    = trim($_GET['estado']    ?? '');
    $filtroCategoria = (int)($_GET['categoria'] ?? 0);
    $filtroAutor     = (int)($_GET['autor']     ?? 0);
    $filtroBusqueda  = trim($_GET['q']          ?? '');
    $filtroDestacado = trim($_GET['destacado']  ?? '');
    $filtroBreaking  = trim($_GET['breaking']   ?? '');
    $filtroPremium   = trim($_GET['premium']    ?? '');
    $ordenar         = trim($_GET['orden']      ?? 'fecha_desc');
    $porPagina       = 25;
    $paginaActual    = max(1, (int)($_GET['pag'] ?? 1));
    $offset          = ($paginaActual - 1) * $porPagina;

    // Construir WHERE
    $where  = ['1=1'];
    $params = [];

    if (!empty($filtroEstado) && in_array($filtroEstado, ['publicado','borrador','programado','archivado'])) {
        $where[]  = 'n.estado = ?';
        $params[] = $filtroEstado;
    }
    if ($filtroCategoria > 0) {
        $where[]  = 'n.categoria_id = ?';
        $params[] = $filtroCategoria;
    }
    if ($filtroAutor > 0) {
        $where[]  = 'n.autor_id = ?';
        $params[] = $filtroAutor;
    }
    if ($filtroDestacado !== '') {
        $where[]  = 'n.destacado = ?';
        $params[] = (int)$filtroDestacado;
    }
    if ($filtroBreaking !== '') {
        $where[]  = 'n.breaking = ?';
        $params[] = (int)$filtroBreaking;
    }
    if ($filtroPremium !== '') {
        $where[]  = 'n.es_premium = ?';
        $params[] = (int)$filtroPremium;
    }
    if (!empty($filtroBusqueda)) {
        $where[]    = '(n.titulo LIKE ? OR n.resumen LIKE ? OR n.slug LIKE ?)';
        $busqParam  = '%' . $filtroBusqueda . '%';
        $params[]   = $busqParam;
        $params[]   = $busqParam;
        $params[]   = $busqParam;
    }

    $whereStr = implode(' AND ', $where);

    // Ordenación
    $orderBy = match($ordenar) {
        'titulo_asc'   => 'n.titulo ASC',
        'titulo_desc'  => 'n.titulo DESC',
        'vistas_desc'  => 'n.vistas DESC',
        'vistas_asc'   => 'n.vistas ASC',
        'fecha_asc'    => 'n.fecha_creacion ASC',
        default        => 'n.fecha_creacion DESC',
    };

    // Contar total
    $totalNoticias = (int)db()->fetchColumn(
        "SELECT COUNT(*) FROM noticias n WHERE $whereStr",
        $params
    );

    // Obtener noticias
    $paramsQuery = array_merge($params, [$porPagina, $offset]);
    $noticias = db()->fetchAll(
        "SELECT n.id, n.titulo, n.slug, n.imagen, n.estado,
                n.vistas, n.destacado, n.breaking, n.es_premium, n.es_opinion,
                n.fecha_publicacion, n.fecha_creacion, n.total_compartidos,
                n.total_reacciones, n.tiempo_lectura, n.allow_comments,
                c.nombre AS cat_nombre, c.color AS cat_color, c.id AS cat_id,
                u.nombre AS autor_nombre, u.id AS autor_id,
                (SELECT COUNT(*) FROM comentarios co
                 WHERE co.noticia_id = n.id AND co.aprobado = 1) AS total_comentarios
         FROM noticias n
         INNER JOIN categorias c ON c.id = n.categoria_id
         INNER JOIN usuarios   u ON u.id = n.autor_id
         WHERE $whereStr
         ORDER BY $orderBy
         LIMIT ? OFFSET ?",
        $paramsQuery
    );

    $totalPaginas = (int)ceil($totalNoticias / $porPagina);

    // Estadísticas rápidas para el header
    $statsEstados = db()->fetchAll(
        "SELECT estado, COUNT(*) AS total FROM noticias GROUP BY estado",
        []
    );
    $statsMap = [];
    foreach ($statsEstados as $s) $statsMap[$s['estado']] = (int)$s['total'];
}

// Categorías y autores para filtros y formulario
$categorias = db()->fetchAll(
    "SELECT id, nombre, color FROM categorias WHERE activa = 1 ORDER BY nombre ASC",
    []
);
$autores = db()->fetchAll(
    "SELECT id, nombre FROM usuarios
     WHERE rol IN ('super_admin','admin','editor','periodista') AND activo = 1
     ORDER BY nombre ASC",
    []
);
$todosLosTags = db()->fetchAll(
    "SELECT id, nombre FROM tags ORDER BY nombre ASC",
    []
);

$pageTitle = ($action === 'lista')
    ? 'Gestión de Noticias'
    : ($noticia ? 'Editar: ' . truncateChars($noticia['titulo'], 40) : 'Nueva Noticia');

$created = isset($_GET['created']) && $_GET['created'] == '1';
?>
<!DOCTYPE html>
<html lang="es" data-theme="<?= e(Config::get('apariencia_modo_oscuro','auto')) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — Panel Admin</title>
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= APP_VERSION ?>">

    <style>
        /* ══════════════════════════════════════════
           ADMIN LAYOUT BASE
        ══════════════════════════════════════════ */
        body { padding-bottom: 0; background: var(--bg-body); }
        .admin-wrapper { display: flex; min-height: 100vh; }

        /* Sidebar */
        .admin-sidebar {
            width: 260px; background: var(--secondary-dark);
            position: fixed; top: 0; left: 0;
            height: 100vh; overflow-y: auto;
            z-index: var(--z-header);
            transition: transform var(--transition-base);
            display: flex; flex-direction: column;
        }
        .admin-sidebar::-webkit-scrollbar { width: 4px; }
        .admin-sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); }
        .admin-sidebar__logo {
            padding: 24px 20px 16px;
            border-bottom: 1px solid rgba(255,255,255,.07); flex-shrink: 0;
        }
        .admin-sidebar__logo a { display: flex; align-items: center; gap: 10px; text-decoration: none; }
        .admin-sidebar__logo-icon {
            width: 36px; height: 36px; background: var(--primary);
            border-radius: 10px; display: flex; align-items: center;
            justify-content: center; color: #fff; font-size: 1rem; flex-shrink: 0;
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
        .admin-sidebar__user {
            padding: 14px 20px; border-bottom: 1px solid rgba(255,255,255,.07);
            display: flex; align-items: center; gap: 10px; flex-shrink: 0;
        }
        .admin-sidebar__user img {
            width: 36px; height: 36px; border-radius: 50%;
            object-fit: cover; border: 2px solid rgba(255,255,255,.15);
        }
        .admin-sidebar__user-name {
            font-size: .82rem; font-weight: 600;
            color: rgba(255,255,255,.9); display: block; line-height: 1.2;
        }
        .admin-sidebar__user-role { font-size: .68rem; color: rgba(255,255,255,.4); }
        .admin-nav { flex: 1; padding: 12px 0; overflow-y: auto; }
        .admin-nav__section {
            padding: 14px 20px 6px; font-size: .62rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .1em;
            color: rgba(255,255,255,.25);
        }
        .admin-nav__item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 20px; color: rgba(255,255,255,.6);
            font-size: .82rem; font-weight: 500; text-decoration: none;
            transition: all var(--transition-fast); position: relative;
        }
        .admin-nav__item:hover { background: rgba(255,255,255,.06); color: rgba(255,255,255,.9); }
        .admin-nav__item.active {
            background: rgba(230,57,70,.18); color: #fff; font-weight: 600;
        }
        .admin-nav__item.active::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0;
            width: 3px; background: var(--primary); border-radius: 0 3px 3px 0;
        }
        .admin-nav__item i { width: 18px; text-align: center; font-size: .9rem; flex-shrink: 0; }
        .admin-nav__badge {
            margin-left: auto; background: var(--primary); color: #fff;
            font-size: .6rem; font-weight: 700; padding: 2px 6px;
            border-radius: var(--border-radius-full); min-width: 18px; text-align: center;
        }
        .admin-sidebar__footer {
            padding: 16px 20px; border-top: 1px solid rgba(255,255,255,.07); flex-shrink: 0;
        }

        /* Main */
        .admin-main { margin-left: 260px; flex: 1; min-height: 100vh; display: flex; flex-direction: column; }

        /* Topbar */
        .admin-topbar {
            background: var(--bg-surface); border-bottom: 1px solid var(--border-color);
            padding: 0 28px; height: 62px; display: flex; align-items: center;
            gap: 16px; position: sticky; top: 0; z-index: var(--z-sticky);
            box-shadow: var(--shadow-sm);
        }
        .admin-topbar__toggle {
            display: none; color: var(--text-muted); font-size: 1.2rem;
            padding: 6px; border-radius: var(--border-radius-sm);
            transition: all var(--transition-fast); background: none; border: none; cursor: pointer;
        }
        .admin-topbar__toggle:hover { background: var(--bg-surface-2); }
        .admin-topbar__title {
            font-family: var(--font-serif); font-size: 1.1rem;
            font-weight: 700; color: var(--text-primary);
        }
        .admin-topbar__right {
            margin-left: auto; display: flex; align-items: center; gap: 10px;
        }
        .admin-content { padding: 28px; flex: 1; }

        /* Overlay móvil */
        .admin-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.5); z-index: calc(var(--z-header) - 1);
        }

        /* ══════════════════════════════════════════
           COMPONENTES NOTICIAS
        ══════════════════════════════════════════ */
        /* Stats rápidas */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }
        .quick-stat {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-lg);
            padding: 14px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            text-decoration: none;
            transition: all var(--transition-fast);
        }
        .quick-stat:hover { border-color: var(--primary); }
        .quick-stat.active-filter { border-color: var(--primary); background: rgba(230,57,70,.05); }
        .quick-stat__icon {
            width: 36px; height: 36px; border-radius: var(--border-radius);
            display: flex; align-items: center; justify-content: center;
            font-size: .9rem; flex-shrink: 0;
        }
        .quick-stat__num {
            font-size: 1.2rem; font-weight: 900; color: var(--text-primary); line-height: 1;
        }
        .quick-stat__label { font-size: .7rem; color: var(--text-muted); font-weight: 500; }

        /* Barra de acciones */
        .actions-bar {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-xl);
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .search-input-wrap {
            position: relative; flex: 1; min-width: 200px;
        }
        .search-input-wrap i {
            position: absolute; left: 12px; top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted); font-size: .9rem; pointer-events: none;
        }
        .search-input {
            width: 100%; padding: 9px 12px 9px 36px;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            background: var(--bg-body);
            color: var(--text-primary);
            font-size: .85rem;
            transition: border-color var(--transition-fast);
        }
        .search-input:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(230,57,70,.1);
        }
        .filter-select {
            padding: 9px 12px; border: 1px solid var(--border-color);
            border-radius: var(--border-radius); background: var(--bg-body);
            color: var(--text-primary); font-size: .82rem;
            cursor: pointer;
        }
        .filter-select:focus { outline: none; border-color: var(--primary); }

        /* Tabla */
        .noticias-table-wrap { background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--border-radius-xl); overflow: hidden; }
        .admin-table { width: 100%; border-collapse: collapse; }
        .admin-table thead th {
            background: var(--bg-surface-2); font-size: .72rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted);
            padding: 12px 14px; text-align: left; border-bottom: 2px solid var(--border-color);
            white-space: nowrap;
        }
        .admin-table td {
            padding: 12px 14px; border-bottom: 1px solid var(--border-color);
            font-size: .82rem; color: var(--text-secondary); vertical-align: middle;
        }
        .admin-table tr:last-child td { border-bottom: none; }
        .admin-table tr:hover td { background: var(--bg-surface-2); }
        .admin-table th:first-child, .admin-table td:first-child { padding-left: 20px; }

        /* Noticia en tabla */
        .noticia-mini { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .noticia-mini__img {
            width: 52px; height: 38px; object-fit: cover;
            border-radius: var(--border-radius); flex-shrink: 0;
            background: var(--bg-surface-3);
        }
        .noticia-mini__title {
            font-weight: 600; color: var(--text-primary); font-size: .83rem;
            display: -webkit-box; -webkit-line-clamp: 2;
            -webkit-box-orient: vertical; overflow: hidden;
            line-height: 1.35;
        }
        .noticia-mini__title a { color: inherit; text-decoration: none; }
        .noticia-mini__title a:hover { color: var(--primary); }
        .noticia-mini__meta {
            font-size: .68rem; color: var(--text-muted); margin-top: 2px;
            display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
        }

        /* Badges */
        .badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 8px; border-radius: var(--border-radius-full);
            font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
        }
        .badge-publicado  { background: rgba(34,197,94,.12);  color: var(--success); }
        .badge-borrador   { background: rgba(245,158,11,.12); color: var(--warning); }
        .badge-programado { background: rgba(59,130,246,.12); color: var(--info); }
        .badge-archivado  { background: rgba(107,114,128,.12);color: var(--text-muted); }
        .badge-breaking   { background: rgba(239,68,68,.15);  color: var(--danger); }
        .badge-destacado  { background: rgba(245,158,11,.15); color: var(--warning); }
        .badge-premium    { background: rgba(109,40,217,.12); color: #7c3aed; }

        /* Acciones de fila */
        .row-actions { display: flex; gap: 4px; align-items: center; }
        .btn-action {
            display: flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; border-radius: var(--border-radius-sm);
            font-size: .8rem; text-decoration: none; border: none; cursor: pointer;
            transition: all var(--transition-fast); background: transparent;
        }
        .btn-action-edit  { color: var(--info);    background: rgba(59,130,246,.1); }
        .btn-action-view  { color: var(--success);  background: rgba(34,197,94,.1);  }
        .btn-action-dup   { color: var(--warning);  background: rgba(245,158,11,.1); }
        .btn-action-del   { color: var(--danger);   background: rgba(239,68,68,.1);  }
        .btn-action:hover { transform: scale(1.1); }

        /* Toggle switch */
        .tgl { position: relative; display: inline-block; width: 34px; height: 18px; }
        .tgl input { opacity: 0; width: 0; height: 0; }
        .tgl-slider {
            position: absolute; cursor: pointer; inset: 0;
            background: var(--bg-surface-3); border-radius: 18px;
            transition: .3s;
        }
        .tgl-slider::before {
            content: ''; position: absolute;
            width: 12px; height: 12px; border-radius: 50%;
            background: #fff; left: 3px; top: 3px; transition: .3s;
        }
        .tgl input:checked + .tgl-slider { background: var(--primary); }
        .tgl input:checked + .tgl-slider::before { transform: translateX(16px); }

        /* Paginación */
        .pagination {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 20px; border-top: 1px solid var(--border-color);
            flex-wrap: wrap; gap: 12px;
        }
        .pagination__info { font-size: .8rem; color: var(--text-muted); }
        .pagination__links { display: flex; gap: 4px; }
        .pagination__btn {
            display: flex; align-items: center; justify-content: center;
            min-width: 32px; height: 32px; padding: 0 6px;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-sm);
            font-size: .8rem; font-weight: 600;
            text-decoration: none; color: var(--text-secondary);
            transition: all var(--transition-fast);
        }
        .pagination__btn:hover { border-color: var(--primary); color: var(--primary); }
        .pagination__btn.active { background: var(--primary); border-color: var(--primary); color: #fff; }
        .pagination__btn.disabled { opacity: .4; pointer-events: none; }

        /* Selección masiva */
        .bulk-bar {
            display: none; align-items: center; gap: 12px;
            padding: 10px 20px;
            background: rgba(230,57,70,.06);
            border: 1px solid rgba(230,57,70,.2);
            border-radius: var(--border-radius-lg);
            margin-bottom: 12px;
        }
        .bulk-bar.visible { display: flex; }

        /* ══════════════════════════════════════════
           FORMULARIO CREAR / EDITAR
        ══════════════════════════════════════════ */
        .form-layout {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 20px;
            align-items: start;
        }
        .form-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-xl);
            overflow: hidden;
        }
        .form-card__header {
            padding: 16px 20px; border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center; gap: 10px;
        }
        .form-card__title {
            font-size: .875rem; font-weight: 700; color: var(--text-primary);
            display: flex; align-items: center; gap: 8px;
        }
        .form-card__body { padding: 20px; }
        .form-group { margin-bottom: 18px; }
        .form-label {
            display: block; font-size: .8rem; font-weight: 600;
            color: var(--text-secondary); margin-bottom: 6px;
        }
        .form-label span { color: var(--danger); margin-left: 2px; }
        .form-control {
            width: 100%; padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            background: var(--bg-body); color: var(--text-primary);
            font-size: .875rem; font-family: var(--font-sans);
            transition: border-color var(--transition-fast);
        }
        .form-control:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(230,57,70,.08);
        }
        textarea.form-control { resize: vertical; min-height: 90px; }

        /* Image preview */
        .img-preview-wrap {
            border: 2px dashed var(--border-color); border-radius: var(--border-radius-lg);
            padding: 16px; text-align: center; cursor: pointer;
            transition: border-color var(--transition-fast); position: relative;
        }
        .img-preview-wrap:hover { border-color: var(--primary); }
        .img-preview-wrap.has-img { border-style: solid; padding: 8px; }
        .img-preview-wrap img { width: 100%; border-radius: var(--border-radius); display: block; }
        .img-overlay {
            position: absolute; inset: 0; background: rgba(0,0,0,.5);
            border-radius: var(--border-radius-lg);
            display: none; align-items: center; justify-content: center; gap: 8px;
        }
        .img-preview-wrap:hover .img-overlay { display: flex; }

        /* Tag selector */
        .tags-selector {
            display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px;
        }
        .tag-chip {
            padding: 4px 10px; border-radius: var(--border-radius-full);
            font-size: .72rem; font-weight: 600;
            border: 1px solid var(--border-color); cursor: pointer;
            transition: all var(--transition-fast);
            color: var(--text-secondary); background: transparent;
            user-select: none;
        }
        .tag-chip:hover { border-color: var(--primary); color: var(--primary); }
        .tag-chip.selected { background: var(--primary); border-color: var(--primary); color: #fff; }

        /* Editor de contenido */
        #contenido-editor {
            min-height: 300px; border: 1px solid var(--border-color);
            border-radius: var(--border-radius); background: var(--bg-body);
            color: var(--text-primary); padding: 14px;
            font-size: .875rem; line-height: 1.7;
            white-space: pre-wrap; overflow-y: auto;
        }
        .editor-toolbar {
            display: flex; flex-wrap: wrap; gap: 4px; padding: 8px;
            border: 1px solid var(--border-color);
            border-bottom: none;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            background: var(--bg-surface-2);
        }
        .editor-btn {
            padding: 5px 9px; border: 1px solid var(--border-color);
            border-radius: var(--border-radius-sm); cursor: pointer;
            font-size: .78rem; background: var(--bg-surface);
            color: var(--text-secondary); transition: all var(--transition-fast);
        }
        .editor-btn:hover { background: var(--primary); color: #fff; border-color: var(--primary); }

        /* Botones form */
        .btn-primary {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 11px 24px; background: var(--primary); color: #fff;
            border: none; border-radius: var(--border-radius-lg);
            font-size: .875rem; font-weight: 700; cursor: pointer;
            transition: all var(--transition-fast); text-decoration: none;
        }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); }
        .btn-secondary {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 11px 20px; background: var(--bg-surface-2); color: var(--text-secondary);
            border: 1px solid var(--border-color); border-radius: var(--border-radius-lg);
            font-size: .875rem; font-weight: 600; cursor: pointer;
            transition: all var(--transition-fast); text-decoration: none;
        }
        .btn-secondary:hover { background: var(--bg-surface-3); }
        .btn-success {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 11px 24px; background: var(--success); color: #fff;
            border: none; border-radius: var(--border-radius-lg);
            font-size: .875rem; font-weight: 700; cursor: pointer; transition: all var(--transition-fast);
        }

        /* Alert */
        .alert {
            padding: 12px 16px; border-radius: var(--border-radius-lg);
            display: flex; align-items: flex-start; gap: 10px;
            margin-bottom: 16px; font-size: .875rem;
        }
        .alert-success { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.3); color: var(--success); }
        .alert-danger  { background: rgba(239,68,68,.1);  border: 1px solid rgba(239,68,68,.3);  color: var(--danger);  }

        /* Toast */
        #toast-container {
            position: fixed; bottom: 24px; right: 24px;
            display: flex; flex-direction: column; gap: 8px; z-index: 9999;
        }
        .toast {
            padding: 12px 18px; border-radius: var(--border-radius-lg);
            background: var(--secondary-dark); color: #fff;
            font-size: .85rem; font-weight: 500;
            display: flex; align-items: center; gap: 10px;
            box-shadow: var(--shadow-xl);
            animation: toastIn .3s ease; min-width: 260px;
        }
        .toast.success { border-left: 4px solid var(--success); }
        .toast.error   { border-left: 4px solid var(--danger); }
        .toast.info    { border-left: 4px solid var(--info); }
        @keyframes toastIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }

        /* Modal confirmación */
        .modal-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,.6);
            z-index: 1000; display: none; align-items: center; justify-content: center; padding: 20px;
        }
        .modal-overlay.visible { display: flex; }
        .modal-box {
            background: var(--bg-surface); border-radius: var(--border-radius-xl);
            padding: 28px; max-width: 420px; width: 100%;
            box-shadow: var(--shadow-xl);
        }
        .modal-title { font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 8px; }
        .modal-body  { font-size: .875rem; color: var(--text-secondary); margin-bottom: 20px; }
        .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }

        /* Responsive */
        @media (max-width: 1100px) {
            .quick-stats { grid-template-columns: repeat(3, 1fr); }
            .form-layout { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .admin-sidebar { transform: translateX(-100%); }
            .admin-sidebar.open { transform: translateX(0); box-shadow: var(--shadow-xl); }
            .admin-main { margin-left: 0; }
            .admin-topbar__toggle { display: flex; }
            .admin-content { padding: 16px; }
            .quick-stats { grid-template-columns: repeat(2, 1fr); }
            .admin-overlay { display: block; }
            .actions-bar { flex-direction: column; align-items: stretch; }
        }
        @media (max-width: 480px) {
            .quick-stats { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">

<!-- ── SIDEBAR ───────────────────────────────────────────────── -->
<?php require_once __DIR__ . '/sidebar.php'; ?>

<!-- Overlay móvil -->
<div class="admin-overlay" id="adminOverlay" onclick="closeSidebar()"></div>

<!-- ── MAIN ──────────────────────────────────────────────────── -->
<main class="admin-main">

    <!-- Topbar -->
    <div class="admin-topbar">
        <button class="admin-topbar__toggle" id="sidebarToggle"
                onclick="toggleSidebar()" aria-label="Menú">
            <i class="bi bi-list"></i>
        </button>
        <h1 class="admin-topbar__title">
            <i class="bi bi-newspaper" style="color:var(--primary);margin-right:6px"></i>
            <?php if ($action === 'lista'): ?>
                Gestión de Noticias
            <?php elseif ($action === 'nueva'): ?>
                Nueva Noticia
            <?php else: ?>
                Editar Noticia
            <?php endif; ?>
        </h1>
        <div class="admin-topbar__right">
            <?php if ($action !== 'lista'): ?>
            <a href="<?= APP_URL ?>/admin/noticias.php"
               style="display:flex;align-items:center;gap:6px;padding:8px 14px;
                      border-radius:var(--border-radius);font-size:.8rem;font-weight:600;
                      text-decoration:none;color:var(--text-secondary);
                      background:var(--bg-surface-2);border:1px solid var(--border-color)">
                <i class="bi bi-arrow-left"></i> Volver a la lista
            </a>
            <?php else: ?>
            <a href="<?= APP_URL ?>/admin/noticias.php?action=nueva"
               style="display:flex;align-items:center;gap:6px;padding:8px 16px;
                      border-radius:var(--border-radius);font-size:.82rem;font-weight:700;
                      text-decoration:none;color:#fff;background:var(--primary)">
                <i class="bi bi-plus-lg"></i> Nueva Noticia
            </a>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/index.php" target="_blank"
               style="display:flex;align-items:center;gap:5px;padding:8px 12px;
                      border-radius:var(--border-radius);font-size:.78rem;
                      text-decoration:none;color:var(--text-muted);
                      background:var(--bg-surface-2)">
                <i class="bi bi-box-arrow-up-right"></i>
            </a>
        </div>
    </div>

    <!-- Contenido -->
    <div class="admin-content">

    <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-circle-fill"></i>
        <?= e($_GET['error'] === 'no_encontrada' ? 'Noticia no encontrada.' : 'Error desconocido.') ?>
    </div>
    <?php endif; ?>

    <?php if ($created): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle-fill"></i>
        ¡Noticia creada exitosamente! Ahora puedes continuar editando todos sus detalles.
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════
         VISTA: LISTA DE NOTICIAS
    ══════════════════════════════════════════════════════ -->
    <?php if ($action === 'lista'): ?>

    <!-- Stats rápidas -->
    <div class="quick-stats">
        <?php
        $statsConfig = [
            ''           => ['label'=>'Total',      'color'=>'rgba(59,130,246,.12)',  'tc'=>'var(--info)',    'icon'=>'bi-collection'],
            'publicado'  => ['label'=>'Publicadas',  'color'=>'rgba(34,197,94,.12)',   'tc'=>'var(--success)', 'icon'=>'bi-check-circle'],
            'borrador'   => ['label'=>'Borradores',  'color'=>'rgba(245,158,11,.12)',  'tc'=>'var(--warning)', 'icon'=>'bi-pencil'],
            'programado' => ['label'=>'Programadas', 'color'=>'rgba(59,130,246,.12)',  'tc'=>'var(--info)',    'icon'=>'bi-calendar-check'],
            'archivado'  => ['label'=>'Archivadas',  'color'=>'rgba(107,114,128,.12)','tc'=>'var(--text-muted)','icon'=>'bi-archive'],
        ];
        $totalAll = array_sum($statsMap);
        foreach ($statsConfig as $est => $cfg):
            $cnt = $est === '' ? $totalAll : ($statsMap[$est] ?? 0);
            $url = $est === ''
                ? APP_URL . '/admin/noticias.php'
                : APP_URL . '/admin/noticias.php?estado=' . $est;
            $isActive = $filtroEstado === $est || ($est === '' && $filtroEstado === '');
        ?>
        <a href="<?= $url ?>" class="quick-stat <?= $isActive ? 'active-filter' : '' ?>">
            <div class="quick-stat__icon" style="background:<?= $cfg['color'] ?>;color:<?= $cfg['tc'] ?>">
                <i class="bi <?= $cfg['icon'] ?>"></i>
            </div>
            <div>
                <div class="quick-stat__num"><?= number_format($cnt) ?></div>
                <div class="quick-stat__label"><?= $cfg['label'] ?></div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Barra de selección masiva -->
    <div class="bulk-bar" id="bulkBar">
        <i class="bi bi-check2-square" style="color:var(--primary)"></i>
        <span id="bulkCount" style="font-size:.85rem;font-weight:600;color:var(--text-primary)">0 seleccionadas</span>
        <div style="display:flex;gap:8px;margin-left:auto">
            <button onclick="bulkAction('publicado')"
                    style="padding:6px 12px;border-radius:var(--border-radius-sm);
                           border:none;background:rgba(34,197,94,.15);color:var(--success);
                           font-size:.78rem;font-weight:600;cursor:pointer">
                <i class="bi bi-check-lg"></i> Publicar
            </button>
            <button onclick="bulkAction('borrador')"
                    style="padding:6px 12px;border-radius:var(--border-radius-sm);
                           border:none;background:rgba(245,158,11,.15);color:var(--warning);
                           font-size:.78rem;font-weight:600;cursor:pointer">
                <i class="bi bi-pencil"></i> Borrador
            </button>
            <button onclick="bulkEliminar()"
                    style="padding:6px 12px;border-radius:var(--border-radius-sm);
                           border:none;background:rgba(239,68,68,.15);color:var(--danger);
                           font-size:.78rem;font-weight:600;cursor:pointer">
                <i class="bi bi-trash"></i> Eliminar
            </button>
            <button onclick="clearSelection()"
                    style="padding:6px 10px;border-radius:var(--border-radius-sm);
                           border:1px solid var(--border-color);background:transparent;
                           font-size:.78rem;cursor:pointer;color:var(--text-muted)">
                Cancelar
            </button>
        </div>
    </div>

    <!-- Filtros y buscador -->
    <form method="GET" action="<?= APP_URL ?>/admin/noticias.php" class="actions-bar" id="filterForm">
        <input type="hidden" name="action" value="lista">
        <div class="search-input-wrap">
            <i class="bi bi-search"></i>
            <input type="text" name="q" class="search-input"
                   placeholder="Buscar por título, resumen o slug..."
                   value="<?= e($filtroBusqueda) ?>">
        </div>
        <select name="estado" class="filter-select" onchange="this.form.submit()">
            <option value="">Todos los estados</option>
            <option value="publicado"  <?= $filtroEstado==='publicado'  ?'selected':'' ?>>✅ Publicado</option>
            <option value="borrador"   <?= $filtroEstado==='borrador'   ?'selected':'' ?>>📝 Borrador</option>
            <option value="programado" <?= $filtroEstado==='programado' ?'selected':'' ?>>📅 Programado</option>
            <option value="archivado"  <?= $filtroEstado==='archivado'  ?'selected':'' ?>>📦 Archivado</option>
        </select>
        <select name="categoria" class="filter-select" onchange="this.form.submit()">
            <option value="">Todas las categorías</option>
            <?php foreach ($categorias as $cat): ?>
            <option value="<?= (int)$cat['id'] ?>" <?= $filtroCategoria===$cat['id']?'selected':'' ?>>
                <?= e($cat['nombre']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="autor" class="filter-select" onchange="this.form.submit()">
            <option value="">Todos los autores</option>
            <?php foreach ($autores as $au): ?>
            <option value="<?= (int)$au['id'] ?>" <?= $filtroAutor===$au['id']?'selected':'' ?>>
                <?= e($au['nombre']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="orden" class="filter-select" onchange="this.form.submit()">
            <option value="fecha_desc" <?= $ordenar==='fecha_desc'?'selected':'' ?>>Más recientes</option>
            <option value="fecha_asc"  <?= $ordenar==='fecha_asc' ?'selected':'' ?>>Más antiguas</option>
            <option value="vistas_desc"<?= $ordenar==='vistas_desc'?'selected':'' ?>>Más vistas</option>
            <option value="titulo_asc" <?= $ordenar==='titulo_asc'?'selected':'' ?>>Título A-Z</option>
        </select>
        <select name="destacado" class="filter-select" onchange="this.form.submit()">
            <option value="">Destacado: todos</option>
            <option value="1" <?= $filtroDestacado==='1'?'selected':'' ?>>⭐ Sí</option>
            <option value="0" <?= $filtroDestacado==='0'?'selected':'' ?>>No</option>
        </select>
        <button type="submit" class="btn-primary" style="padding:9px 18px;font-size:.82rem">
            <i class="bi bi-funnel-fill"></i> Filtrar
        </button>
        <?php if ($filtroBusqueda || $filtroEstado || $filtroCategoria || $filtroAutor): ?>
        <a href="<?= APP_URL ?>/admin/noticias.php"
           style="padding:9px 14px;border:1px solid var(--border-color);
                  border-radius:var(--border-radius);font-size:.82rem;
                  text-decoration:none;color:var(--text-muted);
                  background:var(--bg-body)">
            <i class="bi bi-x-lg"></i> Limpiar
        </a>
        <?php endif; ?>
    </form>

    <!-- Tabla de noticias -->
    <div class="noticias-table-wrap">
        <?php if (empty($noticias)): ?>
        <div style="padding:60px 20px;text-align:center">
            <div style="font-size:3rem;margin-bottom:12px">📰</div>
            <div style="font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:6px">
                No se encontraron noticias
            </div>
            <div style="font-size:.85rem;color:var(--text-muted);margin-bottom:20px">
                <?= ($filtroBusqueda || $filtroEstado || $filtroCategoria)
                    ? 'No hay resultados para los filtros aplicados.'
                    : 'Aún no has creado ninguna noticia.' ?>
            </div>
            <a href="<?= APP_URL ?>/admin/noticias.php?action=nueva" class="btn-primary">
                <i class="bi bi-plus-lg"></i> Crear primera noticia
            </a>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width:40px">
                        <input type="checkbox" id="selectAll"
                               onchange="toggleSelectAll(this)"
                               style="cursor:pointer;width:16px;height:16px">
                    </th>
                    <th>Noticia</th>
                    <th>Categoría</th>
                    <th>Estado</th>
                    <th>Vistas</th>
                    <th>Comentarios</th>
                    <th>Fecha</th>
                    <th>Flags</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="noticiasTableBody">
                <?php foreach ($noticias as $n): ?>
                <tr id="row-<?= (int)$n['id'] ?>">
                    <td>
                        <input type="checkbox" class="row-check"
                               value="<?= (int)$n['id'] ?>"
                               onchange="updateBulk()"
                               style="cursor:pointer;width:16px;height:16px">
                    </td>
                    <td style="max-width:340px">
                        <div class="noticia-mini">
                            <?php if (!empty($n['imagen'])): ?>
                            <img class="noticia-mini__img"
                                 src="<?= e(getImageUrl($n['imagen'])) ?>"
                                 alt="<?= e($n['titulo']) ?>"
                                 loading="lazy"
                                 onerror="this.src='<?= APP_URL ?>/assets/images/default-news.jpg'">
                            <?php else: ?>
                            <div class="noticia-mini__img"
                                 style="display:flex;align-items:center;justify-content:center;
                                        color:var(--text-muted)">
                                <i class="bi bi-image"></i>
                            </div>
                            <?php endif; ?>
                            <div style="min-width:0">
                                <div class="noticia-mini__title">
                                    <a href="<?= APP_URL ?>/admin/noticias.php?action=editar&id=<?= (int)$n['id'] ?>">
                                        <?= e($n['titulo']) ?>
                                    </a>
                                </div>
                                <div class="noticia-mini__meta">
                                    <span><i class="bi bi-person"></i> <?= e($n['autor_nombre']) ?></span>
                                    <?php if (!empty($n['tiempo_lectura'])): ?>
                                    <span><i class="bi bi-clock"></i> <?= (int)$n['tiempo_lectura'] ?> min</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span style="display:inline-flex;align-items:center;gap:4px;
                                     font-size:.75rem;font-weight:600;
                                     color:<?= e($n['cat_color']) ?>">
                            <span style="width:8px;height:8px;border-radius:50%;
                                         background:<?= e($n['cat_color']) ?>;
                                         flex-shrink:0"></span>
                            <?= e($n['cat_nombre']) ?>
                        </span>
                    </td>
                    <td>
                        <select class="estado-select"
                                data-id="<?= (int)$n['id'] ?>"
                                onchange="cambiarEstado(this)"
                                style="padding:4px 8px;border-radius:6px;border:1px solid var(--border-color);
                                       font-size:.72rem;font-weight:700;background:var(--bg-body);
                                       color:var(--text-primary);cursor:pointer">
                            <option value="publicado"  <?= $n['estado']==='publicado'  ?'selected':'' ?>>✅ Publicado</option>
                            <option value="borrador"   <?= $n['estado']==='borrador'   ?'selected':'' ?>>📝 Borrador</option>
                            <option value="programado" <?= $n['estado']==='programado' ?'selected':'' ?>>📅 Programado</option>
                            <option value="archivado"  <?= $n['estado']==='archivado'  ?'selected':'' ?>>📦 Archivado</option>
                        </select>
                    </td>
                    <td>
                        <strong style="color:var(--text-primary)"><?= formatNumber((int)$n['vistas']) ?></strong>
                        <?php if ($n['total_compartidos'] > 0): ?>
                        <div style="font-size:.68rem;color:var(--text-muted)">
                            <?= (int)$n['total_compartidos'] ?> comp.
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span style="font-size:.82rem;color:var(--text-secondary)">
                            <?= (int)($n['total_comentarios'] ?? 0) ?>
                        </span>
                    </td>
                    <td style="white-space:nowrap">
                        <div style="font-size:.78rem;color:var(--text-secondary)">
                            <?= !empty($n['fecha_publicacion'])
                                ? date('d/m/Y', strtotime($n['fecha_publicacion']))
                                : date('d/m/Y', strtotime($n['fecha_creacion'])) ?>
                        </div>
                        <div style="font-size:.68rem;color:var(--text-muted)">
                            <?= !empty($n['fecha_publicacion'])
                                ? date('H:i', strtotime($n['fecha_publicacion']))
                                : date('H:i', strtotime($n['fecha_creacion'])) ?>
                        </div>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap">
                            <?php if ($n['breaking']): ?>
                            <span class="badge badge-breaking" title="Breaking">🔴 BRK</span>
                            <?php endif; ?>
                            <?php if ($n['destacado']): ?>
                            <span class="badge badge-destacado" title="Destacado">⭐</span>
                            <?php endif; ?>
                            <?php if ($n['es_premium']): ?>
                            <span class="badge badge-premium" title="Premium">💎</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a href="<?= APP_URL ?>/admin/noticias.php?action=editar&id=<?= (int)$n['id'] ?>"
                               class="btn-action btn-action-edit" title="Editar">
                                <i class="bi bi-pencil-fill"></i>
                            </a>
                            <?php if ($n['estado'] === 'publicado'): ?>
                            <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($n['slug']) ?>"
                               target="_blank" class="btn-action btn-action-view" title="Ver">
                                <i class="bi bi-eye-fill"></i>
                            </a>
                            <?php endif; ?>
                            <button onclick="duplicarNoticia(<?= (int)$n['id'] ?>)"
                                    class="btn-action btn-action-dup" title="Duplicar">
                                <i class="bi bi-copy"></i>
                            </button>
                            <button onclick="confirmarEliminar(<?= (int)$n['id'] ?>, '<?= e(addslashes($n['titulo'])) ?>')"
                                    class="btn-action btn-action-del" title="Eliminar">
                                <i class="bi bi-trash-fill"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <!-- Paginación -->
        <?php if ($totalPaginas > 1 || $totalNoticias > 0): ?>
        <div class="pagination">
            <div class="pagination__info">
                Mostrando <?= number_format(min($offset + 1, $totalNoticias)) ?>–<?= number_format(min($offset + $porPagina, $totalNoticias)) ?>
                de <strong><?= number_format($totalNoticias) ?></strong> noticias
            </div>
            <div class="pagination__links">
                <?php
                // Construir URL base para paginación
                $queryParams = array_filter([
                    'action'    => 'lista',
                    'q'         => $filtroBusqueda,
                    'estado'    => $filtroEstado,
                    'categoria' => $filtroCategoria ?: '',
                    'autor'     => $filtroAutor ?: '',
                    'orden'     => $ordenar !== 'fecha_desc' ? $ordenar : '',
                    'destacado' => $filtroDestacado,
                ]);
                $baseUrl = APP_URL . '/admin/noticias.php?' . http_build_query($queryParams);

                // Botón anterior
                $prevClass = $paginaActual <= 1 ? 'disabled' : '';
                echo '<a href="' . $baseUrl . '&pag=' . ($paginaActual - 1) . '" class="pagination__btn '.$prevClass.'">&laquo;</a>';

                // Páginas
                $rango = 2;
                for ($p = max(1, $paginaActual - $rango); $p <= min($totalPaginas, $paginaActual + $rango); $p++) {
                    $activeClass = $p === $paginaActual ? 'active' : '';
                    echo '<a href="' . $baseUrl . '&pag=' . $p . '" class="pagination__btn '.$activeClass.'">' . $p . '</a>';
                }

                // Botón siguiente
                $nextClass = $paginaActual >= $totalPaginas ? 'disabled' : '';
                echo '<a href="' . $baseUrl . '&pag=' . ($paginaActual + 1) . '" class="pagination__btn '.$nextClass.'">&raquo;</a>';
                ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; // empty($noticias) ?>
    </div>

    <!-- ══════════════════════════════════════════════════════
         VISTA: CREAR / EDITAR NOTICIA
    ══════════════════════════════════════════════════════ -->
    <?php else: /* action = nueva o editar */ ?>

    <?php if (!empty($formErrors)): ?>
    <div class="alert alert-danger">
        <div>
            <i class="bi bi-exclamation-circle-fill" style="font-size:1.1rem"></i>
        </div>
        <div>
            <?php foreach ($formErrors as $err): ?>
            <div><?= e($err) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($formSuccess)): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle-fill"></i>
        <?= e($formSuccess) ?>
    </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="noticiaForm" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="guardar_noticia" value="1">
        <input type="hidden" name="noticia_id" value="<?= (int)($noticia['id'] ?? 0) ?>">
        <input type="hidden" name="imagen_actual" id="imagenActual"
               value="<?= e($noticia['imagen'] ?? '') ?>">
        <input type="hidden" name="contenido" id="contenidoHidden"
               value="<?= e($noticia['contenido'] ?? '') ?>">

        <div class="form-layout">

            <!-- Columna principal -->
            <div>

                <!-- Título y slug -->
                <div class="form-card" style="margin-bottom:16px">
                    <div class="form-card__header">
                        <div class="form-card__title">
                            <i class="bi bi-type" style="color:var(--primary)"></i>
                            Título y Slug
                        </div>
                    </div>
                    <div class="form-card__body">
                        <div class="form-group">
                            <label class="form-label" for="titulo">
                                Título <span>*</span>
                            </label>
                            <input type="text" id="titulo" name="titulo"
                                   class="form-control"
                                   placeholder="Escribe el título de la noticia..."
                                   value="<?= e($noticia['titulo'] ?? '') ?>"
                                   oninput="actualizarSlug(this.value)"
                                   required maxlength="300">
                            <div style="font-size:.72rem;color:var(--text-muted);margin-top:4px">
                                <span id="tituloCount">0</span>/300 caracteres
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label" for="slugField">Slug (URL)</label>
                            <div style="display:flex;gap:8px">
                                <input type="text" id="slugField" name="slug_preview"
                                       class="form-control"
                                       value="<?= e($noticia['slug'] ?? '') ?>"
                                       style="font-family:monospace;font-size:.8rem"
                                       readonly>
                                <button type="button" onclick="regenerarSlug()"
                                        style="padding:8px 12px;border:1px solid var(--border-color);
                                               border-radius:var(--border-radius);background:var(--bg-body);
                                               color:var(--text-muted);cursor:pointer;white-space:nowrap;
                                               font-size:.78rem">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Resumen -->
                <div class="form-card" style="margin-bottom:16px">
                    <div class="form-card__header">
                        <div class="form-card__title">
                            <i class="bi bi-card-text" style="color:var(--info)"></i>
                            Resumen / Entradilla
                        </div>
                    </div>
                    <div class="form-card__body">
                        <div class="form-group" style="margin-bottom:0">
                            <textarea id="resumen" name="resumen" class="form-control"
                                      placeholder="Resumen breve de la noticia (se usa en listados y SEO)..."
                                      rows="3" maxlength="600"><?= e($noticia['resumen'] ?? '') ?></textarea>
                            <div style="font-size:.72rem;color:var(--text-muted);margin-top:4px">
                                <span id="resumenCount">0</span>/600 caracteres — Se usa como meta description
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contenido -->
                <div class="form-card" style="margin-bottom:16px">
                    <div class="form-card__header">
                        <div class="form-card__title">
                            <i class="bi bi-pencil-square" style="color:var(--warning)"></i>
                            Contenido Principal
                        </div>
                        <span style="font-size:.75rem;color:var(--text-muted)" id="lecturaEst"></span>
                    </div>
                    <div class="form-card__body" style="padding:0">
                        <!-- Toolbar del editor -->
                        <div class="editor-toolbar">
                            <button type="button" class="editor-btn" onclick="formatText('bold')" title="Negrita">
                                <strong>B</strong>
                            </button>
                            <button type="button" class="editor-btn" onclick="formatText('italic')" title="Cursiva">
                                <em>I</em>
                            </button>
                            <button type="button" class="editor-btn" onclick="insertHeading('h2')" title="Título H2">H2</button>
                            <button type="button" class="editor-btn" onclick="insertHeading('h3')" title="Título H3">H3</button>
                            <button type="button" class="editor-btn" onclick="insertLink()" title="Enlace">
                                <i class="bi bi-link-45deg"></i>
                            </button>
                            <button type="button" class="editor-btn" onclick="insertQuote()" title="Cita">
                                <i class="bi bi-quote"></i>
                            </button>
                            <button type="button" class="editor-btn" onclick="insertList()" title="Lista">
                                <i class="bi bi-list-ul"></i>
                            </button>
                            <button type="button" class="editor-btn" onclick="insertHr()" title="Separador">─</button>
                            <button type="button" class="editor-btn" onclick="insertEmbed()" title="Video embed">
                                <i class="bi bi-play-circle"></i>
                            </button>
                            <div style="margin-left:auto;display:flex;gap:4px">
                                <button type="button" class="editor-btn" onclick="togglePreview()" title="Vista previa">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button type="button" class="editor-btn" onclick="toggleFullscreen()" title="Pantalla completa">
                                    <i class="bi bi-fullscreen"></i>
                                </button>
                            </div>
                        </div>
                        <!-- Editor -->
                        <div id="contenido-editor"
                             contenteditable="true"
                             style="border-radius:0 0 var(--border-radius) var(--border-radius);
                                    min-height:400px;padding:20px"
                             data-placeholder="Escribe el contenido completo de la noticia aquí..."
                             oninput="syncContent(this)">
                        </div>
                        <!-- Preview -->
                        <div id="contenido-preview"
                             style="display:none;min-height:400px;padding:20px;
                                    border-radius:0 0 var(--border-radius) var(--border-radius);
                                    border:1px solid var(--border-color);
                                    line-height:1.8;font-size:.9rem">
                        </div>
                    </div>
                </div>

                <!-- SEO -->
                <div class="form-card" style="margin-bottom:16px">
                    <div class="form-card__header">
                        <div class="form-card__title">
                            <i class="bi bi-search" style="color:var(--success)"></i>
                            SEO / Meta
                        </div>
                        <button type="button" onclick="toggleSection('seoSection')"
                                style="background:none;border:none;cursor:pointer;
                                       color:var(--text-muted);font-size:.8rem">
                            <i class="bi bi-chevron-down" id="seoChevron"></i>
                        </button>
                    </div>
                    <div id="seoSection" class="form-card__body" style="display:none">
                        <div class="form-group">
                            <label class="form-label" for="meta_title">Meta Title</label>
                            <input type="text" id="meta_title" name="meta_title"
                                   class="form-control"
                                   placeholder="Título SEO (deja vacío para usar el título principal)"
                                   value="<?= e($noticia['meta_title'] ?? '') ?>"
                                   maxlength="200">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="meta_description">Meta Description</label>
                            <textarea id="meta_description" name="meta_description"
                                      class="form-control" rows="2"
                                      placeholder="Descripción SEO (deja vacío para usar el resumen)"
                                      maxlength="300"><?= e($noticia['meta_description'] ?? '') ?></textarea>
                        </div>
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label" for="keywords">Palabras Clave</label>
                            <input type="text" id="keywords" name="keywords"
                                   class="form-control"
                                   placeholder="palabra1, palabra2, palabra3..."
                                   value="<?= e($noticia['keywords'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <!-- Fuente -->
                <div class="form-card">
                    <div class="form-card__header">
                        <div class="form-card__title">
                            <i class="bi bi-link" style="color:var(--text-muted)"></i>
                            Fuente y Video
                        </div>
                        <button type="button" onclick="toggleSection('fuenteSection')"
                                style="background:none;border:none;cursor:pointer;
                                       color:var(--text-muted);font-size:.8rem">
                            <i class="bi bi-chevron-down" id="fuenteChevron"></i>
                        </button>
                    </div>
                    <div id="fuenteSection" class="form-card__body" style="display:none">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                            <div class="form-group">
                                <label class="form-label" for="fuente">Fuente</label>
                                <input type="text" id="fuente" name="fuente"
                                       class="form-control"
                                       placeholder="Ej: Reuters, AFP, EFE..."
                                       value="<?= e($noticia['fuente'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="fuente_url">URL de la fuente</label>
                                <input type="url" id="fuente_url" name="fuente_url"
                                       class="form-control"
                                       placeholder="https://..."
                                       value="<?= e($noticia['fuente_url'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label" for="video_url">URL de Video (YouTube/MP4)</label>
                            <input type="url" id="video_url" name="video_url"
                                   class="form-control"
                                   placeholder="https://www.youtube.com/watch?v=..."
                                   value="<?= e($noticia['video_url'] ?? '') ?>">
                        </div>
                    </div>
                </div>

            </div><!-- /columna principal -->

            <!-- Columna lateral -->
            <div>

                <!-- Estado y publicación -->
                <div class="form-card" style="margin-bottom:16px">
                    <div class="form-card__header">
                        <div class="form-card__title">
                            <i class="bi bi-send" style="color:var(--success)"></i>
                            Publicación
                        </div>
                    </div>
                    <div class="form-card__body">
                        <div class="form-group">
                            <label class="form-label">Estado</label>
                            <select name="estado" id="estadoSelect" class="form-control"
                                    onchange="estadoChanged(this.value)">
                                <option value="borrador"   <?= ($noticia['estado']??'borrador')==='borrador'  ?'selected':''?>>📝 Borrador</option>
                                <option value="publicado"  <?= ($noticia['estado']??'')==='publicado' ?'selected':''?>>✅ Publicado</option>
                                <option value="programado" <?= ($noticia['estado']??'')==='programado'?'selected':''?>>📅 Programado</option>
                                <option value="archivado"  <?= ($noticia['estado']??'')==='archivado' ?'selected':''?>>📦 Archivado</option>
                            </select>
                        </div>
                        <div class="form-group" id="fechaPubGroup"
                             style="display:<?= in_array($noticia['estado']??'borrador',['programado','publicado'])?'block':'none' ?>">
                            <label class="form-label">Fecha de Publicación</label>
                            <input type="datetime-local" name="fecha_publicacion"
                                   class="form-control"
                                   value="<?= !empty($noticia['fecha_publicacion'])
                                       ? date('Y-m-d\TH:i', strtotime($noticia['fecha_publicacion'])) : '' ?>">
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                            <button type="submit" name="guardar_accion" value="borrador"
                                    class="btn-secondary" style="justify-content:center;font-size:.8rem">
                                <i class="bi bi-save"></i> Guardar
                            </button>
                            <button type="submit" name="guardar_accion" value="publicado"
                                    onclick="document.getElementById('estadoSelect').value='publicado'"
                                    class="btn-success" style="justify-content:center;font-size:.8rem">
                                <i class="bi bi-send-fill"></i> Publicar
                            </button>
                        </div>
                        <?php if (!empty($noticia) && $noticia['estado'] === 'publicado'): ?>
                        <div style="margin-top:10px;padding:8px 10px;background:rgba(34,197,94,.08);
                                    border-radius:var(--border-radius);text-align:center">
                            <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($noticia['slug']) ?>"
                               target="_blank"
                               style="font-size:.75rem;color:var(--success);text-decoration:none;
                                      display:flex;align-items:center;justify-content:center;gap:4px">
                                <i class="bi bi-box-arrow-up-right"></i> Ver noticia publicada
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Imagen destacada -->
                <div class="form-card" style="margin-bottom:16px">
                    <div class="form-card__header">
                        <div class="form-card__title">
                            <i class="bi bi-image" style="color:var(--info)"></i>
                            Imagen Destacada
                        </div>
                    </div>
                    <div class="form-card__body">
                        <div class="img-preview-wrap <?= !empty($noticia['imagen']) ? 'has-img' : '' ?>"
                             id="imgWrap"
                             onclick="document.getElementById('imagenFile').click()">
                            <?php if (!empty($noticia['imagen'])): ?>
                            <img src="<?= e(getImageUrl($noticia['imagen'])) ?>"
                                 id="imgPreview" alt="Vista previa">
                            <div class="img-overlay">
                                <button type="button"
                                        style="padding:8px 14px;background:var(--primary);color:#fff;
                                               border:none;border-radius:var(--border-radius);
                                               font-size:.8rem;cursor:pointer"
                                        onclick="event.stopPropagation();document.getElementById('imagenFile').click()">
                                    <i class="bi bi-camera"></i> Cambiar
                                </button>
                                <button type="button"
                                        style="padding:8px 14px;background:var(--danger);color:#fff;
                                               border:none;border-radius:var(--border-radius);
                                               font-size:.8rem;cursor:pointer"
                                        onclick="event.stopPropagation();quitarImagen()">
                                    <i class="bi bi-trash"></i> Quitar
                                </button>
                            </div>
                            <?php else: ?>
                            <div style="padding:20px 0">
                                <i class="bi bi-cloud-upload" style="font-size:2rem;color:var(--text-muted)"></i>
                                <div style="font-size:.8rem;color:var(--text-muted);margin-top:6px">
                                    Haz clic para subir imagen
                                </div>
                                <div style="font-size:.7rem;color:var(--text-muted);margin-top:2px">
                                    JPG, PNG, WebP — Máx 5MB
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <input type="file" id="imagenFile" name="imagen"
                               accept="image/jpeg,image/png,image/webp,image/gif"
                               style="display:none" onchange="previewImage(this)">

                        <div class="form-group" style="margin-top:12px">
                            <label class="form-label">Alt Text (accesibilidad)</label>
                            <input type="text" name="imagen_alt" class="form-control"
                                   placeholder="Descripción de la imagen..."
                                   value="<?= e($noticia['imagen_alt'] ?? '') ?>"
                                   maxlength="200">
                        </div>
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label">Pie de foto</label>
                            <input type="text" name="imagen_caption" class="form-control"
                                   placeholder="Crédito o descripción..."
                                   value="<?= e($noticia['imagen_caption'] ?? '') ?>"
                                   maxlength="300">
                        </div>
                    </div>
                </div>

                <!-- Categoría y Autor -->
                <div class="form-card" style="margin-bottom:16px">
                    <div class="form-card__header">
                        <div class="form-card__title">
                            <i class="bi bi-folder" style="color:var(--warning)"></i>
                            Categoría y Autor
                        </div>
                    </div>
                    <div class="form-card__body">
                        <div class="form-group">
                            <label class="form-label">Categoría <span>*</span></label>
                            <select name="categoria_id" class="form-control" required>
                                <option value="">Seleccionar categoría...</option>
                                <?php foreach ($categorias as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>"
                                    <?= ($noticia['categoria_id'] ?? 0) == $cat['id'] ? 'selected' : '' ?>>
                                    <?= e($cat['nombre']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label">Autor <span>*</span></label>
                            <select name="autor_id" class="form-control" required>
                                <?php foreach ($autores as $au): ?>
                                <option value="<?= (int)$au['id'] ?>"
                                    <?= ($noticia['autor_id'] ?? $usuario['id']) == $au['id'] ? 'selected' : '' ?>>
                                    <?= e($au['nombre']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Opciones -->
                <div class="form-card" style="margin-bottom:16px">
                    <div class="form-card__header">
                        <div class="form-card__title">
                            <i class="bi bi-sliders" style="color:var(--text-muted)"></i>
                            Opciones
                        </div>
                    </div>
                    <div class="form-card__body">
                        <?php
                        $opciones = [
                            ['name'=>'destacado',        'label'=>'⭐ Noticia destacada',       'checked'=>$noticia['destacado']??0],
                            ['name'=>'breaking',         'label'=>'🔴 Breaking news',           'checked'=>$noticia['breaking']??0],
                            ['name'=>'es_premium',       'label'=>'💎 Contenido premium',        'checked'=>$noticia['es_premium']??0],
                            ['name'=>'es_opinion',       'label'=>'✍️ Artículo de opinión',      'checked'=>$noticia['es_opinion']??0],
                            ['name'=>'allow_comments',   'label'=>'💬 Permitir comentarios',    'checked'=>$noticia['allow_comments']??1],
                        ];
                        foreach ($opciones as $i => $opt):
                        ?>
                        <div style="display:flex;align-items:center;justify-content:space-between;
                                    padding:8px 0;<?= $i < count($opciones)-1 ? 'border-bottom:1px solid var(--border-color)' : '' ?>">
                            <label for="opt_<?= $opt['name'] ?>"
                                   style="font-size:.82rem;color:var(--text-secondary);cursor:pointer">
                                <?= $opt['label'] ?>
                            </label>
                            <label class="tgl">
                                <input type="checkbox" id="opt_<?= $opt['name'] ?>"
                                       name="<?= $opt['name'] ?>"
                                       <?= $opt['checked'] ? 'checked' : '' ?>>
                                <span class="tgl-slider"></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Tags -->
                <div class="form-card">
                    <div class="form-card__header">
                        <div class="form-card__title">
                            <i class="bi bi-tags" style="color:var(--info)"></i>
                            Etiquetas (Tags)
                        </div>
                    </div>
                    <div class="form-card__body">
                        <input type="text" id="tagSearch" class="form-control"
                               placeholder="Buscar etiqueta..."
                               oninput="filtrarTags(this.value)"
                               style="margin-bottom:10px">
                        <div class="tags-selector" id="tagsSelectorWrap">
                            <?php foreach ($todosLosTags as $tag): ?>
                            <label class="tag-chip <?= in_array((int)$tag['id'], $noticiaTagIds) ? 'selected' : '' ?>"
                                   data-nombre="<?= strtolower(e($tag['nombre'])) ?>">
                                <input type="checkbox" name="tags[]"
                                       value="<?= (int)$tag['id'] ?>"
                                       <?= in_array((int)$tag['id'], $noticiaTagIds) ? 'checked' : '' ?>
                                       style="display:none"
                                       onchange="this.closest('.tag-chip').classList.toggle('selected',this.checked)">
                                #<?= e($tag['nombre']) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top:10px;font-size:.75rem;color:var(--text-muted)">
                            <span id="selectedTagsCount"><?= count($noticiaTagIds) ?></span> etiqueta(s) seleccionada(s)
                        </div>
                    </div>
                </div>

            </div><!-- /columna lateral -->
        </div><!-- /form-layout -->

        <!-- Botón guardar fijo en móvil -->
        <div style="margin-top:20px;display:flex;gap:10px;justify-content:flex-end">
            <a href="<?= APP_URL ?>/admin/noticias.php" class="btn-secondary">
                <i class="bi bi-x-lg"></i> Cancelar
            </a>
            <button type="submit" class="btn-primary">
                <i class="bi bi-check-lg"></i>
                <?= !empty($noticia) ? 'Actualizar Noticia' : 'Crear Noticia' ?>
            </button>
        </div>

    </form>

    <?php endif; /* acción */ ?>

    </div><!-- /admin-content -->
</main>
</div><!-- /admin-wrapper -->

<!-- Toast container -->
<div id="toast-container"></div>

<!-- Modal eliminar -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <div class="modal-title">⚠️ Confirmar eliminación</div>
        <div class="modal-body" id="deleteModalBody">
            ¿Estás seguro de eliminar esta noticia? Esta acción no se puede deshacer.
        </div>
        <div class="modal-actions">
            <button onclick="cerrarModal('deleteModal')" class="btn-secondary">
                <i class="bi bi-x-lg"></i> Cancelar
            </button>
            <button onclick="ejecutarEliminar()" class="btn-primary"
                    style="background:var(--danger)">
                <i class="bi bi-trash-fill"></i> Eliminar
            </button>
        </div>
    </div>
</div>

<script>
/* ═══════════════════════════════════════════════
   SIDEBAR TOGGLE
═══════════════════════════════════════════════ */
function toggleSidebar() {
    document.getElementById('adminSidebar').classList.toggle('open');
}
function closeSidebar() {
    document.getElementById('adminSidebar').classList.remove('open');
}

/* ═══════════════════════════════════════════════
   CSRF
═══════════════════════════════════════════════ */
function getCsrf() {
    const el = document.querySelector('input[name="csrf_token"], input[name="' + (window._csrfName || 'csrf_token') + '"]');
    return el ? el.value : '';
}

/* ═══════════════════════════════════════════════
   TOAST
═══════════════════════════════════════════════ */
function showToast(msg, type = 'success', duration = 4000) {
    const c = document.getElementById('toast-container');
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    const icons = { success:'bi-check-circle-fill', error:'bi-x-circle-fill', info:'bi-info-circle-fill' };
    t.innerHTML = `<i class="bi ${icons[type]||'bi-info-circle-fill'}"></i>${msg}`;
    c.appendChild(t);
    setTimeout(() => t.style.opacity = '0', duration - 300);
    setTimeout(() => t.remove(), duration);
}

/* ═══════════════════════════════════════════════
   MODAL
═══════════════════════════════════════════════ */
let deleteTargetId = null;
function confirmarEliminar(id, titulo) {
    deleteTargetId = id;
    document.getElementById('deleteModalBody').innerHTML =
        `¿Eliminar la noticia <strong>"${titulo.substring(0,60)}..."</strong>?<br>
         <small style="color:var(--danger)">Esta acción eliminará también sus tags y no se puede deshacer.</small>`;
    document.getElementById('deleteModal').classList.add('visible');
}
function cerrarModal(id) {
    document.getElementById(id).classList.remove('visible');
    deleteTargetId = null;
}
function ejecutarEliminar() {
    if (!deleteTargetId) return;
    const id = deleteTargetId;
    cerrarModal('deleteModal');
    fetch('<?= APP_URL ?>/admin/noticias.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ action: 'eliminar', id: id, csrf: getCsrf() })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            const row = document.getElementById('row-' + id);
            if (row) { row.style.opacity = '0'; row.style.transition = '.3s'; setTimeout(() => row.remove(), 300); }
            showToast(d.message, 'success');
        } else {
            showToast(d.message || 'Error al eliminar', 'error');
        }
    })
    .catch(() => showToast('Error de conexión', 'error'));
}

/* ═══════════════════════════════════════════════
   CAMBIAR ESTADO
═══════════════════════════════════════════════ */
function cambiarEstado(sel) {
    const id     = sel.dataset.id;
    const estado = sel.value;
    fetch('<?= APP_URL ?>/admin/noticias.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ action: 'cambiar_estado', id: parseInt(id), estado, csrf: getCsrf() })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) showToast(d.message, 'success');
        else { showToast(d.message, 'error'); location.reload(); }
    });
}

/* ═══════════════════════════════════════════════
   DUPLICAR
═══════════════════════════════════════════════ */
function duplicarNoticia(id) {
    if (!confirm('¿Duplicar esta noticia como borrador?')) return;
    fetch('<?= APP_URL ?>/admin/noticias.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ action: 'duplicar', id, csrf: getCsrf() })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            showToast(d.message, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(d.message, 'error');
        }
    });
}

/* ═══════════════════════════════════════════════
   SELECCIÓN MASIVA
═══════════════════════════════════════════════ */
function toggleSelectAll(cb) {
    document.querySelectorAll('.row-check').forEach(c => c.checked = cb.checked);
    updateBulk();
}
function updateBulk() {
    const checks = document.querySelectorAll('.row-check:checked');
    const bar    = document.getElementById('bulkBar');
    const count  = document.getElementById('bulkCount');
    if (checks.length > 0) {
        bar.classList.add('visible');
        count.textContent = checks.length + ' seleccionada' + (checks.length > 1 ? 's' : '');
    } else {
        bar.classList.remove('visible');
        document.getElementById('selectAll').checked = false;
    }
}
function clearSelection() {
    document.querySelectorAll('.row-check').forEach(c => c.checked = false);
    document.getElementById('selectAll').checked = false;
    document.getElementById('bulkBar').classList.remove('visible');
}
function getSelectedIds() {
    return Array.from(document.querySelectorAll('.row-check:checked')).map(c => parseInt(c.value));
}
function bulkAction(estado) {
    const ids = getSelectedIds();
    if (!ids.length) return;
    const promises = ids.map(id =>
        fetch('<?= APP_URL ?>/admin/noticias.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: 'cambiar_estado', id, estado, csrf: getCsrf() })
        }).then(r => r.json())
    );
    Promise.all(promises).then(() => {
        showToast(ids.length + ' noticias actualizadas.', 'success');
        setTimeout(() => location.reload(), 1200);
    });
}
function bulkEliminar() {
    const ids = getSelectedIds();
    if (!ids.length) return;
    if (!confirm(`¿Eliminar las ${ids.length} noticias seleccionadas? Esta acción no se puede deshacer.`)) return;
    fetch('<?= APP_URL ?>/admin/noticias.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ action: 'eliminar_masivo', ids, csrf: getCsrf() })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { showToast(d.message, 'success'); setTimeout(() => location.reload(), 1500); }
        else showToast(d.message, 'error');
    });
}

/* ═══════════════════════════════════════════════
   FORMULARIO — EDITOR
═══════════════════════════════════════════════ */
let isPreviewMode = false;

function syncContent(el) {
    document.getElementById('contenidoHidden').value = el.innerHTML;
    // Contador tiempo lectura
    const words = el.innerText.split(/\s+/).filter(w => w.length > 0).length;
    const mins  = Math.max(1, Math.round(words / 200));
    document.getElementById('lecturaEst').textContent = `~${mins} min lectura · ${words} palabras`;
}

function formatText(cmd) {
    document.getElementById('contenido-editor').focus();
    document.execCommand(cmd, false, null);
    syncContent(document.getElementById('contenido-editor'));
}

function insertHeading(tag) {
    const editor = document.getElementById('contenido-editor');
    editor.focus();
    const sel = window.getSelection();
    let text = sel && sel.rangeCount > 0 && sel.toString() ? sel.toString() : 'Título';
    const el  = document.createElement(tag);
    el.textContent = text;
    if (sel && sel.rangeCount > 0) {
        sel.getRangeAt(0).deleteContents();
        sel.getRangeAt(0).insertNode(el);
    } else {
        editor.appendChild(el);
    }
    syncContent(editor);
}

function insertLink() {
    const url = prompt('Ingresa la URL:');
    if (url) document.execCommand('createLink', false, url);
    syncContent(document.getElementById('contenido-editor'));
}

function insertQuote() {
    const editor = document.getElementById('contenido-editor');
    editor.focus();
    const bq = document.createElement('blockquote');
    bq.style.cssText = 'border-left:4px solid var(--primary);padding:10px 16px;margin:16px 0;font-style:italic;color:var(--text-secondary)';
    bq.textContent = 'Escribe la cita aquí...';
    const range = window.getSelection().getRangeAt(0);
    range.insertNode(bq);
    syncContent(editor);
}

function insertList() {
    document.getElementById('contenido-editor').focus();
    document.execCommand('insertUnorderedList', false, null);
    syncContent(document.getElementById('contenido-editor'));
}

function insertHr() {
    const editor = document.getElementById('contenido-editor');
    editor.focus();
    const hr = document.createElement('hr');
    const range = window.getSelection().getRangeAt(0);
    range.insertNode(hr);
    syncContent(editor);
}

function insertEmbed() {
    const url = prompt('URL del video (YouTube, Vimeo):');
    if (!url) return;
    let embedUrl = url;
    // Convertir URL de YouTube a embed
    const ytMatch = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([^&\s]+)/);
    if (ytMatch) embedUrl = `https://www.youtube.com/embed/${ytMatch[1]}`;
    const editor = document.getElementById('contenido-editor');
    const wrapper = document.createElement('div');
    wrapper.style.cssText = 'position:relative;padding-bottom:56.25%;height:0;overflow:hidden;margin:16px 0';
    const iframe = document.createElement('iframe');
    iframe.src = embedUrl;
    iframe.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%';
    iframe.setAttribute('frameborder', '0');
    iframe.setAttribute('allowfullscreen', '');
    wrapper.appendChild(iframe);
    editor.appendChild(wrapper);
    syncContent(editor);
}

function togglePreview() {
    const editor  = document.getElementById('contenido-editor');
    const preview = document.getElementById('contenido-preview');
    isPreviewMode = !isPreviewMode;
    if (isPreviewMode) {
        preview.innerHTML = editor.innerHTML;
        editor.style.display  = 'none';
        preview.style.display = 'block';
    } else {
        editor.style.display  = 'block';
        preview.style.display = 'none';
    }
}

function toggleFullscreen() {
    const editor = document.getElementById('contenido-editor');
    if (!document.fullscreenElement) {
        editor.requestFullscreen();
    } else {
        document.exitFullscreen();
    }
}

/* ═══════════════════════════════════════════════
   SLUG
═══════════════════════════════════════════════ */
function slugify(str) {
    return str
        .toLowerCase()
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/[\s_]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-+|-+$/g, '')
        .substring(0, 320);
}
function actualizarSlug(val) {
    document.getElementById('slugField').value = slugify(val);
    document.getElementById('tituloCount').textContent = val.length;
}
function regenerarSlug() {
    const titulo = document.getElementById('titulo').value;
    document.getElementById('slugField').value = slugify(titulo);
}

/* ═══════════════════════════════════════════════
   IMAGEN
═══════════════════════════════════════════════ */
function previewImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            const wrap = document.getElementById('imgWrap');
            wrap.classList.add('has-img');
            let img = document.getElementById('imgPreview');
            if (!img) {
                img = document.createElement('img');
                img.id = 'imgPreview';
                wrap.innerHTML = '';
                const overlay = document.createElement('div');
                overlay.className = 'img-overlay';
                overlay.innerHTML = `<button type="button" onclick="event.stopPropagation();document.getElementById('imagenFile').click()" style="padding:8px 14px;background:var(--primary);color:#fff;border:none;border-radius:var(--border-radius);font-size:.8rem;cursor:pointer"><i class="bi bi-camera"></i> Cambiar</button><button type="button" onclick="event.stopPropagation();quitarImagen()" style="padding:8px 14px;background:var(--danger);color:#fff;border:none;border-radius:var(--border-radius);font-size:.8rem;cursor:pointer"><i class="bi bi-trash"></i> Quitar</button>`;
                wrap.appendChild(img);
                wrap.appendChild(overlay);
            }
            img.src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}
function quitarImagen() {
    document.getElementById('imagenActual').value = '';
    const wrap = document.getElementById('imgWrap');
    wrap.classList.remove('has-img');
    wrap.innerHTML = `<div style="padding:20px 0"><i class="bi bi-cloud-upload" style="font-size:2rem;color:var(--text-muted)"></i><div style="font-size:.8rem;color:var(--text-muted);margin-top:6px">Haz clic para subir imagen</div><div style="font-size:.7rem;color:var(--text-muted);margin-top:2px">JPG, PNG, WebP — Máx 5MB</div></div>`;
    document.getElementById('imagenFile').value = '';
}

/* ═══════════════════════════════════════════════
   ESTADO SELECT
═══════════════════════════════════════════════ */
function estadoChanged(val) {
    const group = document.getElementById('fechaPubGroup');
    group.style.display = ['publicado','programado'].includes(val) ? 'block' : 'none';
}

/* ═══════════════════════════════════════════════
   TOGGLE SECTION
═══════════════════════════════════════════════ */
function toggleSection(id) {
    const el = document.getElementById(id);
    const ch = document.getElementById(id.replace('Section','Chevron'));
    const vis = el.style.display !== 'none';
    el.style.display = vis ? 'none' : 'block';
    if (ch) ch.className = vis ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
}

/* ═══════════════════════════════════════════════
   TAGS FILTER
═══════════════════════════════════════════════ */
function filtrarTags(q) {
    const chips = document.querySelectorAll('.tag-chip');
    q = q.toLowerCase();
    chips.forEach(c => {
        c.style.display = q === '' || c.dataset.nombre.includes(q) ? '' : 'none';
    });
}
function updateTagCount() {
    const sel = document.querySelectorAll('.tag-chip input:checked').length;
    const el  = document.getElementById('selectedTagsCount');
    if (el) el.textContent = sel;
}

/* ═══════════════════════════════════════════════
   RESUMEN COUNTER
═══════════════════════════════════════════════ */
function setupCounters() {
    const res = document.getElementById('resumen');
    const cnt = document.getElementById('resumenCount');
    if (res && cnt) {
        cnt.textContent = res.value.length;
        res.addEventListener('input', () => cnt.textContent = res.value.length);
    }
    const tit = document.getElementById('titulo');
    const tcnt= document.getElementById('tituloCount');
    if (tit && tcnt) tcnt.textContent = tit.value.length;
}

/* ═══════════════════════════════════════════════
   INIT
═══════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function() {
    setupCounters();

    // Cargar contenido en el editor si es edición
    const hidden = document.getElementById('contenidoHidden');
    const editor = document.getElementById('contenido-editor');
    if (editor && hidden && hidden.value) {
        editor.innerHTML = hidden.value;
        syncContent(editor);
    }

    // Event de tags
    document.querySelectorAll('.tag-chip input').forEach(inp => {
        inp.addEventListener('change', updateTagCount);
    });

    // Guardar con Ctrl+S
    document.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            const form = document.getElementById('noticiaForm');
            if (form) form.submit();
        }
    });

    // Cerrar modal con Escape
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.visible').forEach(m => m.classList.remove('visible'));
        }
    });
});
</script>
</body>
</html>