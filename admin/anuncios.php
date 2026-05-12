<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Admin: Gestión de Anuncios
 * ============================================================
 * Archivo : admin/anuncios.php
 * Tabla   : anuncios, anuncio_log
 * Versión : 2.0.0
 * ============================================================
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$auth->requireAdmin();
$usuario = currentUser();

// ============================================================
// POST NORMAL — Guardar / Editar anuncio
// (usa enctype multipart para subida de imagen)
// ============================================================
$formErrors  = [];
$formSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_anuncio'])) {

    if (!$auth->verifyCSRF($_POST['csrf_token'] ?? '')) {
        $formErrors[] = 'Token de seguridad inválido. Recarga la página.';
    } else {
        $editId     = (int)($_POST['anuncio_id'] ?? 0);
        $nombre     = trim($_POST['nombre']      ?? '');
        $tipo       = trim($_POST['tipo']        ?? 'imagen');
        $posicion   = trim($_POST['posicion']    ?? 'sidebar');
        $tamano     = trim($_POST['tamano']      ?? 'responsive');
        $urlDestino = trim($_POST['url_destino'] ?? '');
        $altText    = trim($_POST['alt_text']    ?? '');
        $activo     = isset($_POST['activo'])   ? 1 : 0;
        $rotacion   = isset($_POST['rotacion']) ? 1 : 0;
        $prioridad  = max(1, min(10, (int)($_POST['prioridad'] ?? 5)));
        $rolUsuario = trim($_POST['rol_usuario'] ?? 'todos');
        $catId      = (int)($_POST['categoria_id'] ?? 0) ?: null;
        $fechaInicio= trim($_POST['fecha_inicio'] ?? '') ?: null;
        $fechaFin   = trim($_POST['fecha_fin']   ?? '') ?: null;
        $contenido  = '';
        $imagenActual = trim($_POST['imagen_actual'] ?? '');

        // Determinar contenido según tipo
        if ($tipo === 'html') {
            // NO usar trim() ni cleanInput() en HTML/AdSense — preservar el código intacto
            $contenido = $_POST['contenido_html'] ?? '';
            $contenido = str_replace("\0", '', $contenido); // Solo eliminar caracteres nulos
        } elseif ($tipo === 'video') {
            $contenido = trim($_POST['contenido_video'] ?? '');
        } elseif ($tipo === 'imagen') {
            $contenido = trim($_POST['imagen_url_manual'] ?? '');
        }

        // Validaciones
        $tiposOk    = ['imagen','video','html'];
        $posOk      = ['header','hero','sidebar','entre_noticias','in_article','footer','popup','mobile_banner'];
        $tamOk      = ['728x90','300x250','970x250','320x100','300x600','160x600','336x280','responsive'];
        $rolesOk    = ['todos','user','premium','admin'];

        if (mb_strlen($nombre) < 3)           $formErrors[] = 'El nombre debe tener al menos 3 caracteres.';
        if (!in_array($tipo, $tiposOk))        $formErrors[] = 'Tipo de anuncio inválido.';
        if (!in_array($posicion, $posOk))      $formErrors[] = 'Posición inválida.';
        if (!in_array($tamano, $tamOk))        $formErrors[] = 'Tamaño inválido.';
        if (!in_array($rolUsuario, $rolesOk))  $formErrors[] = 'Segmentación de rol inválida.';
        if ($tipo === 'html' && empty(trim($contenido)))
                                               $formErrors[] = 'El código HTML del anuncio es obligatorio.';
        if ($tipo === 'video' && empty($contenido))
                                               $formErrors[] = 'La URL del video es obligatoria.';

        // Procesar imagen subida
        $imagenFinal = $imagenActual;
        if ($tipo === 'imagen' && !empty($_FILES['imagen_archivo']['name'])) {
            $resultado = uploadImage($_FILES['imagen_archivo'], 'anuncios', 'ad');
            if ($resultado['success']) {
                $imagenFinal = $resultado['path'];
                if (!empty($imagenActual)) deleteImage($imagenActual);
            } else {
                $formErrors[] = 'Error al subir imagen: ' . ($resultado['error'] ?? 'Error desconocido.');
            }
        }

        // Si tipo imagen y no hay archivo ni URL ni imagen existente
        if ($tipo === 'imagen' && empty($imagenFinal) && empty($contenido)) {
            $formErrors[] = 'Debes subir una imagen o proporcionar una URL de imagen.';
        }

        // Para imagen: contenido = URL si se proporcionó, o se deja vacío y se usa columna imagen
        if ($tipo === 'imagen') {
            if (!empty($imagenFinal) && str_starts_with($imagenFinal, 'http')) {
                $contenido   = $imagenFinal;
                $imagenFinal = '';
            } elseif (!empty($contenido) && str_starts_with($contenido, 'http')) {
                // URL externa — se guarda en contenido
            } else {
                $contenido = '';
            }
        }

        if (empty($formErrors)) {
            try {
                if ($editId > 0) {
                    // ACTUALIZAR
                    db()->execute(
                        "UPDATE anuncios SET
                            nombre=?, tipo=?, posicion=?, tamano=?, contenido=?,
                            url_destino=?, alt_text=?, activo=?, prioridad=?, rotacion=?,
                            rol_usuario=?, fecha_inicio=?, fecha_fin=?,
                            categoria_id=?, imagen=?
                         WHERE id=?",
                        [
                            $nombre, $tipo, $posicion, $tamano, $contenido,
                            $urlDestino ?: null, $altText ?: null,
                            $activo, $prioridad, $rotacion,
                            $rolUsuario, $fechaInicio, $fechaFin,
                            $catId, $imagenFinal ?: null,
                            $editId
                        ]
                    );
                    logActividad((int)$usuario['id'], 'edit_anuncio', 'anuncios', $editId, "Editó anuncio: $nombre");
                    $formSuccess = "Anuncio «{$nombre}» actualizado correctamente.";
                } else {
                    // CREAR
                    $newId = db()->insert(
                        "INSERT INTO anuncios
                            (nombre, tipo, posicion, tamano, contenido,
                             url_destino, alt_text, activo, prioridad, rotacion,
                             rol_usuario, fecha_inicio, fecha_fin,
                             categoria_id, imagen, fecha_creacion)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
                        [
                            $nombre, $tipo, $posicion, $tamano, $contenido,
                            $urlDestino ?: null, $altText ?: null,
                            $activo, $prioridad, $rotacion,
                            $rolUsuario, $fechaInicio, $fechaFin,
                            $catId, $imagenFinal ?: null
                        ]
                    );
                    logActividad((int)$usuario['id'], 'create_anuncio', 'anuncios', $newId, "Creó anuncio: $nombre");
                    header('Location: ' . APP_URL . '/admin/anuncios.php?created=1');
                    exit;
                }
            } catch (\Throwable $e) {
                $formErrors[] = 'Error de base de datos: ' . $e->getMessage();
            }
        }
    }
}

// ============================================================
// AJAX — Acciones rápidas (solo toggle y delete)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $ajaxAc = $input['action'] ?? '';
    $csrfOk = $auth->verifyCSRF($input['csrf'] ?? '');

    if (!$csrfOk) {
        echo json_encode(['success' => false, 'message' => 'Token CSRF inválido.']); exit;
    }

    switch ($ajaxAc) {

        case 'toggle_activo':
            $id = (int)($input['id'] ?? 0);
            if (!$id) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            try {
                db()->execute("UPDATE anuncios SET activo = NOT activo WHERE id = ?", [$id]);
                $nuevo = (int)db()->fetchColumn("SELECT activo FROM anuncios WHERE id = ?", [$id]);
                logActividad((int)$usuario['id'], 'toggle_anuncio', 'anuncios', $id, "Activo → $nuevo");
                echo json_encode([
                    'success' => true,
                    'valor'   => $nuevo,
                    'message' => $nuevo ? 'Anuncio activado.' : 'Anuncio pausado.',
                ]);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
            }
            exit;

        case 'eliminar':
            $id = (int)($input['id'] ?? 0);
            if (!$id) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            try {
                $anun = db()->fetchOne("SELECT imagen FROM anuncios WHERE id = ?", [$id]);
                db()->execute("DELETE FROM anuncio_log WHERE anuncio_id = ?", [$id]);
                db()->execute("DELETE FROM anuncios WHERE id = ?", [$id]);
                if (!empty($anun['imagen'])) deleteImage($anun['imagen']);
                logActividad((int)$usuario['id'], 'delete_anuncio', 'anuncios', $id, "Eliminó anuncio #$id");
                echo json_encode(['success'=>true,'message'=>'Anuncio eliminado correctamente.']);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
            }
            exit;

        case 'reset_stats':
            $id = (int)($input['id'] ?? 0);
            if (!$id) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            try {
                db()->execute("UPDATE anuncios SET impresiones = 0, clics = 0 WHERE id = ?", [$id]);
                db()->execute("DELETE FROM anuncio_log WHERE anuncio_id = ?", [$id]);
                logActividad((int)$usuario['id'], 'reset_stats_anuncio', 'anuncios', $id, "Reseteó stats #$id");
                echo json_encode(['success'=>true,'message'=>'Estadísticas reseteadas.']);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
            }
            exit;

        case 'duplicar':
            $id = (int)($input['id'] ?? 0);
            if (!$id) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            try {
                $newId = db()->insert(
                    "INSERT INTO anuncios
                        (nombre,tipo,posicion,tamano,contenido,url_destino,alt_text,
                         activo,prioridad,rotacion,rol_usuario,fecha_inicio,fecha_fin,
                         categoria_id,imagen,fecha_creacion)
                     SELECT CONCAT('Copia de ',nombre),tipo,posicion,tamano,contenido,url_destino,alt_text,
                            0,prioridad,rotacion,rol_usuario,NULL,NULL,categoria_id,imagen,NOW()
                     FROM anuncios WHERE id=?",
                    [$id]
                );
                logActividad((int)$usuario['id'], 'duplicar_anuncio', 'anuncios', $newId, "Duplicó anuncio #$id");
                echo json_encode(['success'=>true,'message'=>'Anuncio duplicado (pausado).','nuevo_id'=>$newId]);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
            }
            exit;

        default:
            echo json_encode(['success'=>false,'message'=>'Acción desconocida.']); exit;
    }
}

// ============================================================
// CARGAR DATOS PARA EDICIÓN
// ============================================================
$editAnuncio = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
if ($editId > 0) {
    $editAnuncio = db()->fetchOne(
        "SELECT * FROM anuncios WHERE id = ? LIMIT 1",
        [$editId]
    );
    if (!$editAnuncio) {
        header('Location: ' . APP_URL . '/admin/anuncios.php?error=no_encontrado');
        exit;
    }
}

// ============================================================
// LISTA — Filtros + Paginación
// ============================================================
$filtroPosicion = trim($_GET['posicion'] ?? '');
$filtroTipo     = trim($_GET['tipo']     ?? '');
$filtroEstado   = trim($_GET['estado']   ?? '');
$filtroBusqueda = trim($_GET['q']        ?? '');
$ordenar        = trim($_GET['orden']    ?? 'reciente');
$porPagina      = 20;
$pagActual      = max(1, (int)($_GET['pag'] ?? 1));
$offset         = ($pagActual - 1) * $porPagina;

$posicionesValidas = ['header','hero','sidebar','entre_noticias','in_article','footer','popup','mobile_banner'];
$tiposValidos      = ['imagen','video','html'];

$where  = ['1=1'];
$params = [];

if (!empty($filtroPosicion) && in_array($filtroPosicion, $posicionesValidas)) {
    $where[]  = 'posicion = ?';
    $params[] = $filtroPosicion;
}
if (!empty($filtroTipo) && in_array($filtroTipo, $tiposValidos)) {
    $where[]  = 'tipo = ?';
    $params[] = $filtroTipo;
}
if ($filtroEstado === 'activo') {
    $where[] = 'activo = 1';
} elseif ($filtroEstado === 'inactivo') {
    $where[] = 'activo = 0';
} elseif ($filtroEstado === 'programado') {
    $where[] = 'fecha_inicio > NOW()';
} elseif ($filtroEstado === 'expirado') {
    $where[] = 'fecha_fin < NOW() AND fecha_fin IS NOT NULL';
}
if (!empty($filtroBusqueda)) {
    $where[]  = '(nombre LIKE ? OR alt_text LIKE ?)';
    $b = '%' . $filtroBusqueda . '%';
    $params[] = $b;
    $params[] = $b;
}

$whereStr = implode(' AND ', $where);

$orderBy = match($ordenar) {
    'nombre_asc'  => 'nombre ASC',
    'clics_desc'  => 'clics DESC',
    'imp_desc'    => 'impresiones DESC',
    'prioridad'   => 'prioridad ASC',
    default       => 'fecha_creacion DESC',
};

$totalAnuncios = (int)db()->fetchColumn(
    "SELECT COUNT(*) FROM anuncios WHERE $whereStr",
    $params
);
$totalPaginas = (int)ceil($totalAnuncios / $porPagina);

$anuncios = db()->fetchAll(
    "SELECT a.id, a.nombre, a.tipo, a.posicion, a.tamano,
            a.contenido, a.url_destino, a.imagen, a.alt_text,
            a.activo, a.prioridad, a.rotacion,
            a.impresiones, a.clics, a.rol_usuario,
            a.fecha_inicio, a.fecha_fin, a.fecha_creacion,
            c.nombre AS cat_nombre, c.color AS cat_color
     FROM anuncios a
     LEFT JOIN categorias c ON c.id = a.categoria_id
     WHERE $whereStr
     ORDER BY $orderBy
     LIMIT ? OFFSET ?",
    array_merge($params, [$porPagina, $offset])
);

// Stats por posición (para tabs)
$statsPorPos = db()->fetchAll(
    "SELECT posicion, COUNT(*) AS total, SUM(activo) AS activos
     FROM anuncios GROUP BY posicion ORDER BY total DESC",
    []
);
$statsPosMap = array_column($statsPorPos, null, 'posicion');

// Stats globales
$statsTotal   = (int)db()->fetchColumn("SELECT COUNT(*) FROM anuncios");
$statsActivos = (int)db()->fetchColumn("SELECT COUNT(*) FROM anuncios WHERE activo = 1");
$statsTotalImp= (int)db()->fetchColumn("SELECT COALESCE(SUM(impresiones),0) FROM anuncios");
$statsTotalClic=(int)db()->fetchColumn("SELECT COALESCE(SUM(clics),0) FROM anuncios");
$statsCTR     = $statsTotalImp > 0 ? round(($statsTotalClic / $statsTotalImp) * 100, 2) : 0;

// Categorías para select
$categorias = db()->fetchAll("SELECT id, nombre FROM categorias WHERE activa = 1 ORDER BY nombre", []);

// URL paginación
function adPagUrl(int $pag): string {
    global $filtroPosicion, $filtroTipo, $filtroEstado, $filtroBusqueda, $ordenar;
    $p = array_filter([
        'posicion' => $filtroPosicion,
        'tipo'     => $filtroTipo,
        'estado'   => $filtroEstado,
        'q'        => $filtroBusqueda,
        'orden'    => $ordenar !== 'reciente' ? $ordenar : '',
    ]);
    return APP_URL . '/admin/anuncios.php?' . http_build_query(array_merge($p, ['pag' => $pag]));
}

// Helpers visuales
function adPosLabel(string $p): string {
    return match($p) {
        'header'         => 'Header',
        'hero'           => 'Hero',
        'sidebar'        => 'Sidebar',
        'entre_noticias' => 'Entre Noticias',
        'in_article'     => 'En Artículo',
        'footer'         => 'Footer',
        'popup'          => 'Popup',
        'mobile_banner'  => 'Banner Móvil',
        default          => ucfirst($p),
    };
}
function adPosColor(string $p): string {
    return match($p) {
        'header'         => '#e63946',
        'hero'           => '#f59e0b',
        'sidebar'        => '#0d6efd',
        'entre_noticias' => '#059669',
        'in_article'     => '#7c3aed',
        'footer'         => '#6b7280',
        'popup'          => '#dc2626',
        'mobile_banner'  => '#0891b2',
        default          => '#6b7280',
    };
}
function adEstadoInfo(array $a): array {
    if (!$a['activo'])
        return ['label'=>'Pausado','color'=>'#6b7280','bg'=>'rgba(107,114,128,.12)'];
    if (!empty($a['fecha_fin']) && strtotime($a['fecha_fin']) < time())
        return ['label'=>'Expirado','color'=>'#ef4444','bg'=>'rgba(239,68,68,.12)'];
    if (!empty($a['fecha_inicio']) && strtotime($a['fecha_inicio']) > time())
        return ['label'=>'Programado','color'=>'#3b82f6','bg'=>'rgba(59,130,246,.12)'];
    return ['label'=>'Activo','color'=>'#22c55e','bg'=>'rgba(34,197,94,.12)'];
}
function adThumbSrc(array $a): string {
    if (!empty($a['imagen'])) {
        return str_starts_with($a['imagen'], 'http')
            ? $a['imagen']
            : APP_URL . '/' . $a['imagen'];
    }
    if ($a['tipo'] === 'imagen' && !empty($a['contenido'])) {
        return $a['contenido'];
    }
    return '';
}

$created  = isset($_GET['created']);
$pageTitle = $editAnuncio ? 'Editar Anuncio: ' . truncateChars($editAnuncio['nombre'], 30) : 'Gestión de Anuncios';
?>
<!DOCTYPE html>
<html lang="es">
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
        /* ══════════════════════════════════════
           BASE ADMIN LAYOUT (igual a noticias.php)
        ══════════════════════════════════════ */
        body{padding-bottom:0;background:var(--bg-body)}
        .admin-wrapper{display:flex;min-height:100vh}
        .admin-sidebar{width:260px;background:var(--secondary-dark);position:fixed;top:0;left:0;height:100vh;overflow-y:auto;z-index:var(--z-header);transition:transform var(--transition-base);display:flex;flex-direction:column}
        .admin-sidebar::-webkit-scrollbar{width:4px}
        .admin-sidebar::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1)}
        .admin-sidebar__logo{padding:24px 20px 16px;border-bottom:1px solid rgba(255,255,255,.07);flex-shrink:0}
        .admin-sidebar__logo a{display:flex;align-items:center;gap:10px;text-decoration:none}
        .admin-sidebar__logo-icon{width:36px;height:36px;background:var(--primary);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1rem;flex-shrink:0}
        .admin-sidebar__logo-text{font-family:var(--font-serif);font-size:1rem;font-weight:800;color:#fff;line-height:1.1}
        .admin-sidebar__logo-sub{font-size:.65rem;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.06em}
        .admin-sidebar__user{padding:14px 20px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;align-items:center;gap:10px;flex-shrink:0}
        .admin-sidebar__user img{width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.15)}
        .admin-sidebar__user-name{font-size:.82rem;font-weight:600;color:rgba(255,255,255,.9);display:block;line-height:1.2}
        .admin-sidebar__user-role{font-size:.68rem;color:rgba(255,255,255,.4)}
        .admin-nav{flex:1;padding:12px 0}
        .admin-nav__section{padding:14px 20px 6px;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.25)}
        .admin-nav__item{display:flex;align-items:center;gap:10px;padding:10px 20px;color:rgba(255,255,255,.6);font-size:.82rem;font-weight:500;text-decoration:none;transition:all var(--transition-fast);position:relative}
        .admin-nav__item:hover{background:rgba(255,255,255,.06);color:rgba(255,255,255,.9)}
        .admin-nav__item.active{background:rgba(230,57,70,.18);color:#fff;font-weight:600}
        .admin-nav__item.active::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--primary);border-radius:0 3px 3px 0}
        .admin-nav__item i{width:18px;text-align:center;font-size:.9rem;flex-shrink:0}
        .admin-nav__badge{margin-left:auto;background:var(--primary);color:#fff;font-size:.6rem;font-weight:700;padding:2px 6px;border-radius:var(--border-radius-full);min-width:18px;text-align:center}
        .admin-sidebar__footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,.07);flex-shrink:0}
        .admin-main{margin-left:260px;flex:1;min-height:100vh;display:flex;flex-direction:column}
        .admin-topbar{background:var(--bg-surface);border-bottom:1px solid var(--border-color);padding:0 28px;height:62px;display:flex;align-items:center;gap:16px;position:sticky;top:0;z-index:var(--z-sticky);box-shadow:var(--shadow-sm)}
        .admin-topbar__toggle{display:none;color:var(--text-muted);font-size:1.2rem;padding:6px;border-radius:var(--border-radius-sm);background:none;border:none;cursor:pointer}
        .admin-topbar__toggle:hover{background:var(--bg-surface-2)}
        .admin-topbar__title{font-family:var(--font-serif);font-size:1.1rem;font-weight:700;color:var(--text-primary)}
        .admin-topbar__right{margin-left:auto;display:flex;align-items:center;gap:10px}
        .admin-content{padding:28px;flex:1}
        .admin-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:calc(var(--z-header) - 1)}

        /* ══════════════════════════════════════
           STATS
        ══════════════════════════════════════ */
        .stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:22px}
        .stat-card{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--border-radius-xl);padding:16px;display:flex;align-items:center;gap:12px}
        .stat-icon{width:42px;height:42px;border-radius:var(--border-radius-lg);display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
        .stat-num{font-size:1.3rem;font-weight:900;color:var(--text-primary);line-height:1}
        .stat-lbl{font-size:.7rem;color:var(--text-muted);margin-top:2px}

        /* ══════════════════════════════════════
           POSITION TABS
        ══════════════════════════════════════ */
        .pos-tabs{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:18px}
        .pos-tab{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:var(--border-radius-full);font-size:.78rem;font-weight:600;border:1px solid var(--border-color);text-decoration:none;color:var(--text-muted);transition:all .2s;background:var(--bg-body);white-space:nowrap}
        .pos-tab:hover{border-color:var(--primary);color:var(--primary)}
        .pos-tab.active{background:var(--primary);border-color:var(--primary);color:#fff}
        .pos-tab .badge{font-size:.62rem;padding:1px 5px;border-radius:8px;background:rgba(255,255,255,.2)}
        .pos-tab:not(.active) .badge{background:var(--bg-surface-3);color:var(--text-muted)}

        /* ══════════════════════════════════════
           FILTROS
        ══════════════════════════════════════ */
        .filters-bar{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--border-radius-xl);padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
        .search-wrap{position:relative;flex:1;min-width:200px}
        .search-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none}
        .search-inp{width:100%;padding:9px 12px 9px 34px;border:1px solid var(--border-color);border-radius:var(--border-radius);background:var(--bg-body);color:var(--text-primary);font-size:.83rem}
        .search-inp:focus{outline:none;border-color:var(--primary)}
        .filter-sel{padding:9px 12px;border:1px solid var(--border-color);border-radius:var(--border-radius);background:var(--bg-body);color:var(--text-primary);font-size:.82rem;cursor:pointer}

        /* ══════════════════════════════════════
           TABLA
        ══════════════════════════════════════ */
        .table-wrap{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--border-radius-xl);overflow:hidden}
        .admin-table{width:100%;border-collapse:collapse}
        .admin-table thead th{background:var(--bg-surface-2);font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);padding:12px 14px;text-align:left;border-bottom:2px solid var(--border-color);white-space:nowrap}
        .admin-table td{padding:10px 14px;border-bottom:1px solid var(--border-color);font-size:.82rem;color:var(--text-secondary);vertical-align:middle}
        .admin-table tr:last-child td{border-bottom:none}
        .admin-table tr:hover td{background:var(--bg-surface-2)}
        .admin-table th:first-child,.admin-table td:first-child{padding-left:20px}

        .ad-thumb{width:80px;height:50px;object-fit:cover;border-radius:var(--border-radius-sm);flex-shrink:0;background:var(--bg-surface-3)}
        .ad-name{font-weight:700;color:var(--text-primary);font-size:.83rem;margin-bottom:3px}

        /* Toggle */
        .tgl{position:relative;display:inline-block;width:34px;height:18px}
        .tgl input{opacity:0;width:0;height:0}
        .tgl-slider{position:absolute;cursor:pointer;inset:0;background:var(--bg-surface-3);border-radius:18px;transition:.3s}
        .tgl-slider::before{content:'';position:absolute;width:12px;height:12px;border-radius:50%;background:#fff;left:3px;top:3px;transition:.3s}
        .tgl input:checked+.tgl-slider{background:var(--primary)}
        .tgl input:checked+.tgl-slider::before{transform:translateX(16px)}

        /* Row actions */
        .row-acts{display:flex;gap:4px}
        .act-btn{display:flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:var(--border-radius-sm);font-size:.8rem;text-decoration:none;border:none;cursor:pointer;transition:all var(--transition-fast)}
        .act-edit{color:var(--info);background:rgba(59,130,246,.1)}
        .act-del{color:var(--danger);background:rgba(239,68,68,.1)}
        .act-dup{color:var(--warning);background:rgba(245,158,11,.1)}
        .act-stats{color:#7c3aed;background:rgba(124,58,237,.1)}
        .act-btn:hover{transform:scale(1.1)}

        /* Paginación */
        .pag-wrap{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-top:1px solid var(--border-color);flex-wrap:wrap;gap:10px}
        .pag-info{font-size:.8rem;color:var(--text-muted)}
        .pag-links{display:flex;gap:4px}
        .pag-btn{display:flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 6px;border:1px solid var(--border-color);border-radius:var(--border-radius-sm);font-size:.8rem;font-weight:600;text-decoration:none;color:var(--text-secondary);transition:all var(--transition-fast)}
        .pag-btn:hover{border-color:var(--primary);color:var(--primary)}
        .pag-btn.active{background:var(--primary);border-color:var(--primary);color:#fff}
        .pag-btn.disabled{opacity:.4;pointer-events:none}

        /* Botones */
        .btn-primary{display:inline-flex;align-items:center;gap:7px;padding:11px 22px;background:var(--primary);color:#fff;border:none;border-radius:var(--border-radius-lg);font-size:.875rem;font-weight:700;cursor:pointer;transition:all var(--transition-fast);text-decoration:none}
        .btn-primary:hover{background:var(--primary-dark);transform:translateY(-1px)}
        .btn-secondary{display:inline-flex;align-items:center;gap:7px;padding:11px 18px;background:var(--bg-surface-2);color:var(--text-secondary);border:1px solid var(--border-color);border-radius:var(--border-radius-lg);font-size:.875rem;font-weight:600;cursor:pointer;transition:all var(--transition-fast);text-decoration:none}
        .btn-secondary:hover{background:var(--bg-surface-3)}
        .btn-danger{display:inline-flex;align-items:center;gap:7px;padding:10px 16px;background:var(--danger);color:#fff;border:none;border-radius:var(--border-radius-lg);font-size:.83rem;font-weight:700;cursor:pointer}

        /* Alerts */
        .alert{padding:12px 16px;border-radius:var(--border-radius-lg);display:flex;align-items:flex-start;gap:10px;margin-bottom:16px;font-size:.875rem}
        .alert-success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:var(--success)}
        .alert-danger{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:var(--danger)}

        /* ══════════════════════════════════════
           FORMULARIO CREAR/EDITAR
        ══════════════════════════════════════ */
        .form-layout{display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start}
        .form-card{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--border-radius-xl);overflow:hidden;margin-bottom:16px}
        .form-card__header{padding:14px 18px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:8px}
        .form-card__title{font-size:.875rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px}
        .form-card__body{padding:18px}
        .form-group{margin-bottom:16px}
        .form-label{display:block;font-size:.8rem;font-weight:600;color:var(--text-secondary);margin-bottom:5px}
        .form-label span{color:var(--danger);margin-left:2px}
        .form-control{width:100%;padding:10px 14px;border:1px solid var(--border-color);border-radius:var(--border-radius);background:var(--bg-body);color:var(--text-primary);font-size:.875rem}
        .form-control:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(230,57,70,.08)}
        textarea.form-control{resize:vertical;min-height:100px}
        .form-hint{font-size:.7rem;color:var(--text-muted);margin-top:4px}
        .form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}

        /* Tipos de contenido según tipo de anuncio */
        .tipo-section{display:none}
        .tipo-section.visible{display:block}

        /* Image upload */
        .img-upload-zone{border:2px dashed var(--border-color);border-radius:var(--border-radius-lg);padding:20px;text-align:center;cursor:pointer;transition:all var(--transition-fast);background:var(--bg-body);position:relative}
        .img-upload-zone:hover{border-color:var(--primary)}
        .img-upload-zone.has-img{border-style:solid;padding:8px}
        .img-upload-zone img.preview{width:100%;max-height:180px;object-fit:contain;border-radius:var(--border-radius);display:none}
        .img-upload-zone.has-img img.preview{display:block}
        .img-upload-placeholder{pointer-events:none}
        .img-upload-zone.has-img .img-upload-placeholder{display:none}

        /* HTML preview */
        .html-preview-box{background:#fff;color:#000;border:1px solid var(--border-color);border-radius:var(--border-radius-lg);padding:12px;min-height:80px;overflow:auto;font-size:.85rem;margin-top:8px}

        /* Prioridad visual */
        .prioridad-bar{height:6px;background:var(--bg-surface-3);border-radius:3px;overflow:hidden;margin-top:6px}
        .prioridad-fill{height:100%;border-radius:3px;transition:width .3s;background:var(--primary)}

        /* Toggle en formulario */
        .opts-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
        .opt-item{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:var(--bg-surface-2);border-radius:var(--border-radius-lg);border:1px solid var(--border-color)}
        .opt-label{font-size:.82rem;font-weight:600;color:var(--text-secondary)}

        /* Modal confirm */
        .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;display:none;align-items:center;justify-content:center;padding:20px}
        .modal-overlay.visible{display:flex}
        .modal-box{background:var(--bg-surface);border-radius:var(--border-radius-xl);padding:28px;max-width:420px;width:100%;box-shadow:var(--shadow-xl)}
        .modal-title{font-size:1.05rem;font-weight:700;color:var(--text-primary);margin-bottom:8px}
        .modal-body-text{font-size:.875rem;color:var(--text-secondary);margin-bottom:20px}
        .modal-actions{display:flex;gap:10px;justify-content:flex-end}

        /* Stats modal */
        .stats-modal-panel{background:var(--bg-surface);border-radius:var(--border-radius-xl);width:100%;max-width:500px;box-shadow:var(--shadow-xl)}
        .stats-grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px}
        .stats-card-sm{background:var(--bg-surface-2);border-radius:var(--border-radius-lg);padding:16px;text-align:center}

        /* Toast */
        #toast-container{position:fixed;bottom:24px;right:24px;display:flex;flex-direction:column;gap:8px;z-index:9999}
        .toast{padding:12px 18px;border-radius:var(--border-radius-lg);background:var(--secondary-dark);color:#fff;font-size:.85rem;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shadow-xl);animation:toastIn .3s ease;min-width:260px}
        .toast.success{border-left:4px solid var(--success)}
        .toast.error{border-left:4px solid var(--danger)}
        .toast.info{border-left:4px solid var(--info)}
        @keyframes toastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
        @keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}

        /* Responsive */
        @media(max-width:1100px){.form-layout{grid-template-columns:1fr}.stats-grid{grid-template-columns:repeat(3,1fr)}}
        @media(max-width:768px){
            .admin-sidebar{transform:translateX(-100%)}
            .admin-sidebar.open{transform:translateX(0);box-shadow:var(--shadow-xl)}
            .admin-main{margin-left:0}
            .admin-topbar__toggle{display:flex}
            .admin-content{padding:14px}
            .admin-overlay{display:block}
            .stats-grid{grid-template-columns:repeat(2,1fr)}
            .filters-bar{flex-direction:column;align-items:stretch}
            .form-row-2{grid-template-columns:1fr}
            .opts-grid{grid-template-columns:1fr}
            .stats-grid-3{grid-template-columns:1fr 1fr}
        }
        @media(max-width:480px){.stats-grid{grid-template-columns:1fr 1fr}}
    </style>
</head>
<body>
<div class="admin-wrapper">

<?php require_once __DIR__ . '/sidebar.php'; ?>
<div class="admin-overlay" id="adminOverlay" onclick="closeSidebar()"></div>

<main class="admin-main">

    <!-- ── TOPBAR ───────────────────────────────────────────── -->
    <div class="admin-topbar">
        <button class="admin-topbar__toggle" onclick="toggleSidebar()" aria-label="Menú">
            <i class="bi bi-list"></i>
        </button>
        <h1 class="admin-topbar__title">
            <i class="bi bi-badge-ad-fill" style="color:var(--primary);margin-right:6px"></i>
            <?= $editAnuncio ? 'Editar Anuncio' : 'Gestión de Anuncios' ?>
        </h1>
        <div class="admin-topbar__right">
            <?php if ($editAnuncio): ?>
            <a href="<?= APP_URL ?>/admin/anuncios.php"
               style="display:flex;align-items:center;gap:6px;padding:8px 14px;
                      border-radius:var(--border-radius);font-size:.8rem;font-weight:600;
                      text-decoration:none;color:var(--text-secondary);
                      background:var(--bg-surface-2);border:1px solid var(--border-color)">
                <i class="bi bi-arrow-left"></i> Volver a la lista
            </a>
            <?php else: ?>
            <a href="<?= APP_URL ?>/admin/anuncios.php?nuevo=1"
               class="btn-primary" style="padding:8px 16px;font-size:.82rem">
                <i class="bi bi-plus-lg"></i> Nuevo Anuncio
            </a>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/index.php" target="_blank"
               style="display:flex;align-items:center;justify-content:center;
                      width:36px;height:36px;border-radius:var(--border-radius);
                      background:var(--bg-surface-2);color:var(--text-muted);text-decoration:none">
                <i class="bi bi-box-arrow-up-right"></i>
            </a>
        </div>
    </div>

    <div class="admin-content">

    <?php if ($created): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle-fill"></i>
        ¡Anuncio creado correctamente! Ahora aparece en la lista.
    </div>
    <?php endif; ?>

    <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-circle-fill"></i>
        <?= e($_GET['error'] === 'no_encontrado' ? 'Anuncio no encontrado.' : 'Error desconocido.') ?>
    </div>
    <?php endif; ?>

    <!-- ════════════════════════════════════════════════════════
         VISTA: CREAR / EDITAR ANUNCIO
    ════════════════════════════════════════════════════════ -->
    <?php if (isset($_GET['nuevo']) || $editAnuncio): ?>

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
        <i class="bi bi-check-circle-fill"></i> <?= e($formSuccess) ?>
    </div>
    <?php endif; ?>

    <?php
    // Datos para el formulario (edición o nuevo)
    $f = $editAnuncio ?? [];
    $fId       = (int)($f['id'] ?? 0);
    $fNombre   = $f['nombre']      ?? '';
    $fTipo     = $f['tipo']        ?? 'imagen';
    $fPosicion = $f['posicion']    ?? 'sidebar';
    $fTamano   = $f['tamano']      ?? 'responsive';
    $fContenido= $f['contenido']   ?? '';
    $fUrlDest  = $f['url_destino'] ?? '';
    $fAltText  = $f['alt_text']    ?? '';
    $fActivo   = (int)($f['activo']   ?? 1);
    $fRotacion = (int)($f['rotacion'] ?? 1);
    $fPrioridad= (int)($f['prioridad'] ?? 5);
    $fRolUser  = $f['rol_usuario']  ?? 'todos';
    $fCatId    = (int)($f['categoria_id'] ?? 0);
    $fFechaIni = !empty($f['fecha_inicio']) ? date('Y-m-d\TH:i', strtotime($f['fecha_inicio'])) : '';
    $fFechaFin = !empty($f['fecha_fin'])    ? date('Y-m-d\TH:i', strtotime($f['fecha_fin']))    : '';
    $fImagen   = $f['imagen'] ?? '';
    // Thumbnail actual
    $fThumb = '';
    if (!empty($fImagen)) {
        $fThumb = str_starts_with($fImagen,'http') ? $fImagen : APP_URL.'/'.$fImagen;
    } elseif ($fTipo==='imagen' && !empty($fContenido)) {
        $fThumb = $fContenido;
    }
    ?>

    <form method="POST"
          enctype="multipart/form-data"
          id="adForm"
          novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="guardar_anuncio" value="1">
        <input type="hidden" name="anuncio_id"     value="<?= $fId ?>">
        <input type="hidden" name="imagen_actual"  id="imagenActual" value="<?= e($fImagen) ?>">

        <div class="form-layout">

            <!-- ── COLUMNA PRINCIPAL ────────────────────────── -->
            <div>

                <!-- Nombre -->
                <div class="form-card">
                    <div class="form-card__header">
                        <div class="form-card__title">
                            <i class="bi bi-badge-ad" style="color:var(--primary)"></i>
                            Información Básica
                        </div>
                    </div>
                    <div class="form-card__body">
                        <div class="form-group">
                            <label class="form-label" for="adNombre">
                                Nombre del anuncio <span>*</span>
                            </label>
                            <input type="text" id="adNombre" name="nombre"
                                   class="form-control"
                                   placeholder="Ej: Banner Header Principal..."
                                   value="<?= e($fNombre) ?>"
                                   required maxlength="150">
                        </div>
                        <div class="form-row-2">
                            <div class="form-group">
                                <label class="form-label" for="adTipo">
                                    Tipo de anuncio <span>*</span>
                                </label>
                                <select id="adTipo" name="tipo"
                                        class="form-control"
                                        onchange="cambiarTipo(this.value)">
                                    <option value="imagen"  <?= $fTipo==='imagen' ?'selected':'' ?>>🖼️ Imagen</option>
                                    <option value="html"    <?= $fTipo==='html'   ?'selected':'' ?>>💻 HTML / Código</option>
                                    <option value="video"   <?= $fTipo==='video'  ?'selected':'' ?>>🎬 Video (URL)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="adPosicion">
                                    Posición <span>*</span>
                                </label>
                                <select id="adPosicion" name="posicion" class="form-control">
                                    <?php
                                    $posOpts = [
                                        'header'         => 'Header',
                                        'hero'           => 'Hero Banner',
                                        'sidebar'        => 'Sidebar',
                                        'entre_noticias' => 'Entre Noticias',
                                        'in_article'     => 'Dentro del Artículo',
                                        'footer'         => 'Footer',
                                        'popup'          => 'Popup',
                                        'mobile_banner'  => 'Banner Móvil',
                                    ];
                                    foreach ($posOpts as $val => $lab):
                                    ?>
                                    <option value="<?= $val ?>" <?= $fPosicion===$val?'selected':'' ?>>
                                        <?= $lab ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row-2">
                            <div class="form-group">
                                <label class="form-label" for="adTamano">Tamaño</label>
                                <select id="adTamano" name="tamano" class="form-control">
                                    <option value="responsive" <?= $fTamano==='responsive'?'selected':''?>>Responsive</option>
                                    <option value="728x90"     <?= $fTamano==='728x90'    ?'selected':''?>>728×90 — Leaderboard</option>
                                    <option value="300x250"    <?= $fTamano==='300x250'   ?'selected':''?>>300×250 — Medium Rect.</option>
                                    <option value="970x250"    <?= $fTamano==='970x250'   ?'selected':''?>>970×250 — Billboard</option>
                                    <option value="320x100"    <?= $fTamano==='320x100'   ?'selected':''?>>320×100 — Large Mobile</option>
                                    <option value="300x600"    <?= $fTamano==='300x600'   ?'selected':''?>>300×600 — Half Page</option>
                                    <option value="160x600"    <?= $fTamano==='160x600'   ?'selected':''?>>160×600 — Wide Sky</option>
                                    <option value="336x280"    <?= $fTamano==='336x280'   ?'selected':''?>>336×280 — Large Rect.</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="adUrlDestino">URL de Destino (clic)</label>
                                <input type="url" id="adUrlDestino" name="url_destino"
                                       class="form-control"
                                       placeholder="https://anunciante.com"
                                       value="<?= e($fUrlDest) ?>">
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label" for="adAltText">Alt Text / Descripción</label>
                            <input type="text" id="adAltText" name="alt_text"
                                   class="form-control"
                                   placeholder="Descripción del anuncio para accesibilidad..."
                                   value="<?= e($fAltText) ?>"
                                   maxlength="200">
                        </div>
                    </div>
                </div>

                <!-- CONTENIDO: Imagen -->
                <div class="form-card tipo-section <?= $fTipo==='imagen'?'visible':'' ?>"
                     id="seccionImagen">
                    <div class="form-card__header">
                        <div class="form-card__title">
                            <i class="bi bi-image" style="color:var(--info)"></i>
                            Imagen del Anuncio
                        </div>
                    </div>
                    <div class="form-card__body">
                        <div class="form-group">
                            <label class="form-label">Subir imagen</label>
                            <div class="img-upload-zone <?= !empty($fThumb)?'has-img':'' ?>"
                                 id="imgZone"
                                 onclick="document.getElementById('adImagenFile').click()">
                                <?php if (!empty($fThumb)): ?>
                                <img src="<?= e($fThumb) ?>"
                                     class="preview"
                                     id="imgPreview"
                                     alt="Vista previa"
                                     onerror="this.style.display='none'">
                                <?php else: ?>
                                <img src="" class="preview" id="imgPreview" alt="Vista previa">
                                <?php endif; ?>
                                <div class="img-upload-placeholder">
                                    <i class="bi bi-cloud-upload-fill" style="font-size:2.2rem;color:var(--text-muted)"></i>
                                    <p style="font-size:.82rem;color:var(--text-muted);margin:6px 0 2px">
                                        <strong>Haz clic para subir</strong> o arrastra la imagen
                                    </p>
                                    <p style="font-size:.72rem;color:var(--text-muted)">JPG, PNG, WebP — Máx 5MB</p>
                                </div>
                            </div>
                            <input type="file" id="adImagenFile" name="imagen_archivo"
                                   accept="image/jpeg,image/png,image/webp,image/gif"
                                   style="display:none"
                                   onchange="previewImagen(this)">
                            <?php if (!empty($fThumb)): ?>
                            <button type="button" onclick="quitarImagen()"
                                    style="margin-top:8px;padding:5px 12px;border:1px solid var(--danger);
                                           border-radius:var(--border-radius-sm);background:rgba(239,68,68,.1);
                                           color:var(--danger);font-size:.78rem;cursor:pointer">
                                <i class="bi bi-trash"></i> Quitar imagen
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label" for="imagenUrlManual">
                                — O pega la URL de la imagen directamente:
                            </label>
                            <input type="url" id="imagenUrlManual"
                                   name="imagen_url_manual"
                                   class="form-control"
                                   placeholder="https://cdn.ejemplo.com/banner.jpg"
                                   value="<?= $fTipo==='imagen' && !empty($fContenido) && str_starts_with($fContenido,'http') ? e($fContenido) : '' ?>"
                                   oninput="previewImagenUrl(this.value)">
                            <div class="form-hint">Si subiste un archivo, esta URL se ignorará.</div>
                        </div>
                    </div>
                </div>

                <!-- CONTENIDO: HTML -->
                <div class="form-card tipo-section <?= $fTipo==='html'?'visible':'' ?>"
                     id="seccionHtml">
                    <div class="form-card__header">
                        <div class="form-card__title">
                            <i class="bi bi-code-slash" style="color:var(--warning)"></i>
                            Código HTML del Anuncio
                        </div>
                    </div>
                    <div class="form-card__body">
                        <div class="form-group">
                            <label class="form-label" for="adContenidoHtml">
                                Código HTML / Script <span>*</span>
                            </label>
                            <textarea id="adContenidoHtml"
                              name="contenido_html"
                              class="form-control"
                              style="font-family:monospace;font-size:.82rem;min-height:150px"
                              placeholder="Pega aquí el código del anuncio (Google AdSense, HTML personalizado, iFrame, script...)"
                              oninput="previewHtml(this.value)"><?= htmlspecialchars($fTipo==='html' ? ($fContenido ?? '') : '', ENT_NOQUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label">Vista previa del código</label>
                            <div class="html-preview-box" id="htmlPreview">
                                <?php if ($fTipo==='html' && !empty($fContenido)): ?>
                                <?= $fContenido ?>
                                <?php else: ?>
                                <em style="color:#999">El HTML renderizado aparecerá aquí...</em>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CONTENIDO: Video -->
                <div class="form-card tipo-section <?= $fTipo==='video'?'visible':'' ?>"
                     id="seccionVideo">
                    <div class="form-card__header">
                        <div class="form-card__title">
                            <i class="bi bi-play-circle" style="color:var(--danger)"></i>
                            URL del Video
                        </div>
                    </div>
                    <div class="form-card__body">
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label" for="adContenidoVideo">
                                URL del archivo de video <span>*</span>
                            </label>
                            <input type="url" id="adContenidoVideo"
                                   name="contenido_video"
                                   class="form-control"
                                   placeholder="https://cdn.ejemplo.com/anuncio.mp4"
                                   value="<?= e($fTipo==='video'?$fContenido:'') ?>">
                            <div class="form-hint">URL directa a archivo MP4, WebM u OGV. El video se reproducirá automáticamente sin sonido.</div>
                        </div>
                    </div>
                </div>

                <!-- Segmentación -->
                <div class="form-card">
                    <div class="form-card__header">
                        <div class="form-card__title">
                            <i class="bi bi-sliders" style="color:var(--success)"></i>
                            Segmentación
                        </div>
                    </div>
                    <div class="form-card__body">
                        <div class="form-row-2">
                            <div class="form-group">
                                <label class="form-label" for="adCatId">Categoría</label>
                                <select id="adCatId" name="categoria_id" class="form-control">
                                    <option value="">Todas las categorías</option>
                                    <?php foreach ($categorias as $cat): ?>
                                    <option value="<?= (int)$cat['id'] ?>"
                                        <?= $fCatId===$cat['id']?'selected':'' ?>>
                                        <?= e($cat['nombre']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-hint">Aparecerá preferentemente en esta categoría.</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="adRolUser">Mostrar a</label>
                                <select id="adRolUser" name="rol_usuario" class="form-control">
                                    <option value="todos"   <?= $fRolUser==='todos'  ?'selected':''?>>👥 Todos los usuarios</option>
                                    <option value="user"    <?= $fRolUser==='user'   ?'selected':''?>>👤 Solo registrados</option>
                                    <option value="premium" <?= $fRolUser==='premium'?'selected':''?>>⭐ Solo premium</option>
                                    <option value="admin"   <?= $fRolUser==='admin'  ?'selected':''?>>🛡️ Solo admins</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row-2" style="margin-bottom:0">
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label" for="adFechaInicio">
                                    Fecha de inicio
                                </label>
                                <input type="datetime-local" id="adFechaInicio"
                                       name="fecha_inicio"
                                       class="form-control"
                                       value="<?= e($fFechaIni) ?>">
                                <div class="form-hint">Vacío = publicar inmediatamente.</div>
                            </div>
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label" for="adFechaFin">
                                    Fecha de fin
                                </label>
                                <input type="datetime-local" id="adFechaFin"
                                       name="fecha_fin"
                                       class="form-control"
                                       value="<?= e($fFechaFin) ?>">
                                <div class="form-hint">Vacío = sin expiración.</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /columna principal -->

            <!-- ── COLUMNA LATERAL ───────────────────────────── -->
            <div>

                <!-- Publicar -->
                <div class="form-card" style="margin-bottom:16px">
                    <div class="form-card__header">
                        <div class="form-card__title">
                            <i class="bi bi-send" style="color:var(--success)"></i>
                            Publicación
                        </div>
                    </div>
                    <div class="form-card__body">
                        <div class="opts-grid" style="margin-bottom:14px">
                            <div class="opt-item">
                                <label class="opt-label" for="adActivo">Activo</label>
                                <label class="tgl">
                                    <input type="checkbox" id="adActivo" name="activo"
                                           <?= $fActivo ? 'checked' : '' ?>>
                                    <span class="tgl-slider"></span>
                                </label>
                            </div>
                            <div class="opt-item">
                                <label class="opt-label" for="adRotacion">Rotación</label>
                                <label class="tgl">
                                    <input type="checkbox" id="adRotacion" name="rotacion"
                                           <?= $fRotacion ? 'checked' : '' ?>>
                                    <span class="tgl-slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom:16px">
                            <label class="form-label">
                                Prioridad: <strong id="prioVal"><?= $fPrioridad ?></strong>/10
                            </label>
                            <input type="range" name="prioridad" id="prioRange"
                                   min="1" max="10"
                                   value="<?= $fPrioridad ?>"
                                   style="width:100%;accent-color:var(--primary)"
                                   oninput="document.getElementById('prioVal').textContent=this.value">
                            <div class="form-hint">1 = máxima prioridad · 10 = mínima</div>
                        </div>
                        <div style="display:flex;gap:8px">
                            <button type="submit" class="btn-primary" style="flex:1;justify-content:center">
                                <i class="bi bi-check-lg"></i>
                                <?= $fId ? 'Actualizar' : 'Crear Anuncio' ?>
                            </button>
                        </div>
                        <?php if ($fId): ?>
                        <div style="margin-top:10px">
                            <a href="<?= APP_URL ?>/admin/anuncios.php"
                               class="btn-secondary" style="width:100%;justify-content:center;font-size:.82rem">
                                <i class="bi bi-x-lg"></i> Cancelar
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Info del anuncio (solo en edición) -->
                <?php if ($fId): ?>
                <div class="form-card">
                    <div class="form-card__header">
                        <div class="form-card__title">
                            <i class="bi bi-graph-up" style="color:#7c3aed)"></i>
                            Estadísticas
                        </div>
                    </div>
                    <div class="form-card__body">
                        <?php
                        $ctrActual = (int)$f['impresiones'] > 0
                            ? round(($f['clics'] / $f['impresiones']) * 100, 2)
                            : 0;
                        $items = [
                            ['icon'=>'bi-eye-fill',    'color'=>'var(--info)',    'val'=>number_format((int)$f['impresiones']), 'lbl'=>'Impresiones'],
                            ['icon'=>'bi-cursor-fill', 'color'=>'var(--primary)', 'val'=>number_format((int)$f['clics']),       'lbl'=>'Clics'],
                            ['icon'=>'bi-percent',     'color'=>$ctrActual>=2?'var(--success)':($ctrActual>=0.5?'var(--warning)':'var(--danger)'), 'val'=>$ctrActual.'%', 'lbl'=>'CTR'],
                        ];
                        ?>
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:12px">
                            <?php foreach ($items as $it): ?>
                            <div style="text-align:center;padding:10px;background:var(--bg-surface-2);border-radius:var(--border-radius-lg)">
                                <i class="bi <?= $it['icon'] ?>" style="color:<?= $it['color'] ?>;font-size:1.1rem;display:block;margin-bottom:4px"></i>
                                <div style="font-size:1rem;font-weight:900;color:var(--text-primary)"><?= $it['val'] ?></div>
                                <div style="font-size:.67rem;color:var(--text-muted)"><?= $it['lbl'] ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="font-size:.72rem;color:var(--text-muted);margin-bottom:8px">
                            <i class="bi bi-calendar3"></i>
                            Creado: <?= date('d/m/Y', strtotime($f['fecha_creacion'])) ?>
                        </div>
                        <button type="button"
                                onclick="confirmarResetStats(<?= $fId ?>,'<?= e(addslashes($fNombre)) ?>')"
                                style="width:100%;padding:8px;border:1px solid var(--border-color);
                                       border-radius:var(--border-radius);background:transparent;
                                       color:var(--text-muted);font-size:.78rem;cursor:pointer">
                            <i class="bi bi-arrow-counterclockwise"></i> Resetear estadísticas
                        </button>
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- /columna lateral -->
        </div><!-- /form-layout -->
    </form>

    <!-- ════════════════════════════════════════════════════════
         VISTA: LISTA DE ANUNCIOS
    ════════════════════════════════════════════════════════ -->
    <?php else: // LISTA ?>

    <!-- Stats globales -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(59,130,246,.1);color:var(--info)">
                <i class="bi bi-badge-ad"></i>
            </div>
            <div>
                <div class="stat-num"><?= number_format($statsTotal) ?></div>
                <div class="stat-lbl">Total</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(34,197,94,.1);color:var(--success)">
                <i class="bi bi-play-circle-fill"></i>
            </div>
            <div>
                <div class="stat-num"><?= number_format($statsActivos) ?></div>
                <div class="stat-lbl">Activos</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(245,158,11,.1);color:var(--warning)">
                <i class="bi bi-eye-fill"></i>
            </div>
            <div>
                <div class="stat-num"><?= formatNumber($statsTotalImp) ?></div>
                <div class="stat-lbl">Impresiones</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(230,57,70,.1);color:var(--primary)">
                <i class="bi bi-cursor-fill"></i>
            </div>
            <div>
                <div class="stat-num"><?= formatNumber($statsTotalClic) ?></div>
                <div class="stat-lbl">Clics totales</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:rgba(124,58,237,.1);color:#7c3aed">
                <i class="bi bi-percent"></i>
            </div>
            <div>
                <div class="stat-num"
                     style="color:<?= $statsCTR>=2?'var(--success)':($statsCTR>=0.5?'var(--warning)':'var(--danger)') ?>">
                    <?= $statsCTR ?>%
                </div>
                <div class="stat-lbl">CTR Global</div>
            </div>
        </div>
    </div>

    <!-- Tabs por posición -->
    <div class="pos-tabs">
        <a href="<?= APP_URL ?>/admin/anuncios.php"
           class="pos-tab <?= empty($filtroPosicion)?'active':'' ?>">
            <i class="bi bi-grid-3x3-gap-fill"></i>
            Todas
            <span class="badge"><?= $statsTotal ?></span>
        </a>
        <?php foreach ($statsPorPos as $sp): ?>
        <a href="<?= APP_URL ?>/admin/anuncios.php?posicion=<?= e($sp['posicion']) ?>"
           class="pos-tab <?= $filtroPosicion===$sp['posicion']?'active':'' ?>">
            <?= adPosLabel($sp['posicion']) ?>
            <span class="badge"><?= $sp['total'] ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Filtros -->
    <form method="GET" action="<?= APP_URL ?>/admin/anuncios.php" class="filters-bar">
        <?php if ($filtroPosicion): ?>
        <input type="hidden" name="posicion" value="<?= e($filtroPosicion) ?>">
        <?php endif; ?>
        <div class="search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" name="q" class="search-inp"
                   placeholder="Buscar por nombre..."
                   value="<?= e($filtroBusqueda) ?>">
        </div>
        <select name="tipo" class="filter-sel" onchange="this.form.submit()">
            <option value="">Todos los tipos</option>
            <option value="imagen" <?= $filtroTipo==='imagen'?'selected':''?>>🖼️ Imagen</option>
            <option value="html"   <?= $filtroTipo==='html'  ?'selected':''?>>💻 HTML</option>
            <option value="video"  <?= $filtroTipo==='video' ?'selected':''?>>🎬 Video</option>
        </select>
        <select name="estado" class="filter-sel" onchange="this.form.submit()">
            <option value="">Estado: todos</option>
            <option value="activo"     <?= $filtroEstado==='activo'    ?'selected':''?>>✅ Activos</option>
            <option value="inactivo"   <?= $filtroEstado==='inactivo'  ?'selected':''?>>⏸️ Pausados</option>
            <option value="programado" <?= $filtroEstado==='programado'?'selected':''?>>📅 Programados</option>
            <option value="expirado"   <?= $filtroEstado==='expirado'  ?'selected':''?>>❌ Expirados</option>
        </select>
        <select name="orden" class="filter-sel" onchange="this.form.submit()">
            <option value="reciente"  <?= $ordenar==='reciente'  ?'selected':''?>>Más recientes</option>
            <option value="clics_desc"<?= $ordenar==='clics_desc'?'selected':''?>>Más clics</option>
            <option value="imp_desc"  <?= $ordenar==='imp_desc'  ?'selected':''?>>Más vistos</option>
            <option value="prioridad" <?= $ordenar==='prioridad' ?'selected':''?>>Por prioridad</option>
        </select>
        <button type="submit" class="btn-primary" style="padding:9px 16px;font-size:.82rem">
            <i class="bi bi-funnel-fill"></i> Filtrar
        </button>
        <?php if ($filtroBusqueda || $filtroTipo || $filtroEstado): ?>
        <a href="<?= APP_URL ?>/admin/anuncios.php<?= $filtroPosicion?'?posicion='.e($filtroPosicion):'' ?>"
           style="padding:9px 12px;border:1px solid var(--border-color);border-radius:var(--border-radius);
                  font-size:.82rem;text-decoration:none;color:var(--text-muted);background:var(--bg-body)">
            <i class="bi bi-x-lg"></i> Limpiar
        </a>
        <?php endif; ?>
    </form>

    <!-- Tabla -->
    <div class="table-wrap">
        <?php if (empty($anuncios)): ?>
        <div style="padding:60px 20px;text-align:center">
            <i class="bi bi-badge-ad" style="font-size:3rem;color:var(--text-muted)"></i>
            <div style="font-size:1rem;font-weight:700;color:var(--text-primary);margin:10px 0 6px">
                No hay anuncios
            </div>
            <div style="font-size:.85rem;color:var(--text-muted);margin-bottom:16px">
                <?= $filtroBusqueda || $filtroTipo || $filtroEstado
                    ? 'Sin resultados para los filtros aplicados.'
                    : 'Crea tu primer anuncio para empezar.' ?>
            </div>
            <a href="<?= APP_URL ?>/admin/anuncios.php?nuevo=1" class="btn-primary">
                <i class="bi bi-plus-lg"></i> Crear primer anuncio
            </a>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Anuncio</th>
                    <th>Posición</th>
                    <th>Tipo / Tamaño</th>
                    <th>Impres.</th>
                    <th>Clics</th>
                    <th>CTR</th>
                    <th>Fechas</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($anuncios as $a):
                $estado  = adEstadoInfo($a);
                $thumb   = adThumbSrc($a);
                $ctr     = (int)$a['impresiones'] > 0
                    ? round(($a['clics'] / $a['impresiones']) * 100, 2)
                    : 0;
                $ctrColor= $ctr >= 2 ? 'var(--success)' : ($ctr >= 0.5 ? 'var(--warning)' : 'var(--text-muted)');
            ?>
            <tr id="arow-<?= (int)$a['id'] ?>">
                <td style="min-width:220px">
                    <div style="display:flex;align-items:center;gap:10px">
                        <?php if (!empty($thumb) && $a['tipo'] === 'imagen'): ?>
                        <img src="<?= e($thumb) ?>"
                             class="ad-thumb"
                             alt="<?= e($a['nombre']) ?>"
                             loading="lazy"
                             onerror="this.src='';this.style.display='none'">
                        <?php else: ?>
                        <div class="ad-thumb" style="display:flex;align-items:center;justify-content:center">
                            <i class="bi <?= $a['tipo']==='html'?'bi-code-slash':'bi-play-circle' ?>"
                               style="color:var(--text-muted);font-size:1.4rem"></i>
                        </div>
                        <?php endif; ?>
                        <div style="min-width:0">
                            <div class="ad-name"><?= e($a['nombre']) ?></div>
                            <div style="font-size:.7rem;color:var(--text-muted);margin-top:2px;display:flex;gap:6px;flex-wrap:wrap">
                                <?php if (!empty($a['url_destino'])): ?>
                                <a href="<?= e($a['url_destino']) ?>" target="_blank"
                                   style="color:var(--info);font-size:.68rem;text-decoration:none;
                                          max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block"
                                   title="<?= e($a['url_destino']) ?>">
                                    <i class="bi bi-link-45deg"></i>
                                    <?= e(parse_url($a['url_destino'],PHP_URL_HOST) ?: $a['url_destino']) ?>
                                </a>
                                <?php endif; ?>
                                <?php if (!empty($a['cat_nombre'])): ?>
                                <span style="font-size:.65rem;color:<?= e($a['cat_color']??'var(--text-muted)') ?>">
                                    <i class="bi bi-tag"></i> <?= e($a['cat_nombre']) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <span style="display:inline-flex;align-items:center;gap:4px;
                                 padding:3px 8px;border-radius:var(--border-radius-full);
                                 font-size:.67rem;font-weight:700;
                                 background:<?= adPosColor($a['posicion']) ?>20;
                                 color:<?= adPosColor($a['posicion']) ?>">
                        <?= adPosLabel($a['posicion']) ?>
                    </span>
                </td>
                <td>
                    <div style="font-size:.78rem;font-weight:600;color:var(--text-primary)">
                        <?= match($a['tipo']){ 'imagen'=>'🖼️ Imagen','html'=>'💻 HTML','video'=>'🎬 Video', default=>$a['tipo'] } ?>
                    </div>
                    <div style="font-size:.68rem;color:var(--text-muted);margin-top:2px">
                        <?= $a['tamano'] ?>
                    </div>
                    <?php if ($a['rol_usuario'] !== 'todos'): ?>
                    <div style="font-size:.63rem;color:var(--warning);margin-top:2px">
                        👤 <?= $a['rol_usuario'] ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td>
                    <strong style="color:var(--text-primary);font-size:.85rem">
                        <?= number_format((int)$a['impresiones']) ?>
                    </strong>
                </td>
                <td>
                    <strong style="color:var(--text-primary);font-size:.85rem">
                        <?= number_format((int)$a['clics']) ?>
                    </strong>
                </td>
                <td>
                    <strong style="color:<?= $ctrColor ?>;font-size:.85rem">
                        <?= $ctr ?>%
                    </strong>
                </td>
                <td style="font-size:.75rem;color:var(--text-muted);white-space:nowrap">
                    <?php if (!empty($a['fecha_inicio'])): ?>
                    <div>▶ <?= date('d/m/Y', strtotime($a['fecha_inicio'])) ?></div>
                    <?php else: ?>
                    <div style="font-style:italic">Sin inicio</div>
                    <?php endif; ?>
                    <?php if (!empty($a['fecha_fin'])): ?>
                    <div>⏹ <?= date('d/m/Y', strtotime($a['fecha_fin'])) ?></div>
                    <?php else: ?>
                    <div style="font-style:italic">Sin fin</div>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="margin-bottom:6px">
                        <span style="padding:3px 8px;border-radius:var(--border-radius-full);
                                     font-size:.68rem;font-weight:700;
                                     background:<?= $estado['bg'] ?>;
                                     color:<?= $estado['color'] ?>">
                            <?= $estado['label'] ?>
                        </span>
                    </div>
                    <label class="tgl">
                        <input type="checkbox"
                               id="tgl-<?= (int)$a['id'] ?>"
                               <?= $a['activo'] ? 'checked' : '' ?>
                               onchange="toggleAnuncio(<?= (int)$a['id'] ?>, this)">
                        <span class="tgl-slider"></span>
                    </label>
                </td>
                <td>
                    <div class="row-acts">
                        <a href="<?= APP_URL ?>/admin/anuncios.php?edit=<?= (int)$a['id'] ?>"
                           class="act-btn act-edit" title="Editar">
                            <i class="bi bi-pencil-fill"></i>
                        </a>
                        <button onclick="duplicarAnuncio(<?= (int)$a['id'] ?>)"
                                class="act-btn act-dup" title="Duplicar">
                            <i class="bi bi-copy"></i>
                        </button>
                        <button onclick="confirmarEliminar(<?= (int)$a['id'] ?>,'<?= e(addslashes($a['nombre'])) ?>')"
                                class="act-btn act-del" title="Eliminar">
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
        <?php if ($totalPaginas > 1 || $totalAnuncios > 0): ?>
        <div class="pag-wrap">
            <div class="pag-info">
                Mostrando <?= number_format(min($offset + 1, $totalAnuncios)) ?>–<?= number_format(min($offset + $porPagina, $totalAnuncios)) ?>
                de <strong><?= number_format($totalAnuncios) ?></strong> anuncios
            </div>
            <div class="pag-links">
                <a href="<?= adPagUrl($pagActual - 1) ?>"
                   class="pag-btn <?= $pagActual <= 1 ? 'disabled' : '' ?>">&laquo;</a>
                <?php for ($p = max(1, $pagActual - 3); $p <= min($totalPaginas, $pagActual + 3); $p++): ?>
                <a href="<?= adPagUrl($p) ?>"
                   class="pag-btn <?= $p === $pagActual ? 'active' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a href="<?= adPagUrl($pagActual + 1) ?>"
                   class="pag-btn <?= $pagActual >= $totalPaginas ? 'disabled' : '' ?>">&raquo;</a>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; // empty anuncios ?>
    </div>

    <?php endif; // lista vs formulario ?>

    </div><!-- /admin-content -->
</main>
</div><!-- /admin-wrapper -->

<!-- Modal confirmar eliminación -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <div class="modal-title">⚠️ Confirmar eliminación</div>
        <div class="modal-body-text" id="deleteModalBody">
            ¿Estás seguro de eliminar este anuncio?
        </div>
        <div class="modal-actions">
            <button onclick="cerrarModal('deleteModal')" class="btn-secondary">
                <i class="bi bi-x-lg"></i> Cancelar
            </button>
            <button onclick="ejecutarEliminar()" class="btn-danger">
                <i class="bi bi-trash-fill"></i> Eliminar
            </button>
        </div>
    </div>
</div>

<!-- Modal confirmar reset stats -->
<div class="modal-overlay" id="resetModal">
    <div class="modal-box">
        <div class="modal-title">🔄 Resetear estadísticas</div>
        <div class="modal-body-text" id="resetModalBody">
            ¿Resetear todas las estadísticas de este anuncio?
        </div>
        <div class="modal-actions">
            <button onclick="cerrarModal('resetModal')" class="btn-secondary">Cancelar</button>
            <button onclick="ejecutarResetStats()"
                    style="display:inline-flex;align-items:center;gap:7px;padding:10px 16px;
                           background:var(--warning);color:#fff;border:none;
                           border-radius:var(--border-radius-lg);font-size:.83rem;
                           font-weight:700;cursor:pointer">
                <i class="bi bi-arrow-counterclockwise"></i> Resetear
            </button>
        </div>
    </div>
</div>

<div id="toast-container"></div>

<!-- CSRF para JS -->
<input type="hidden" id="csrfTokenJs" value="<?= csrfToken() ?>">

<script>
/* ════════════════════════════════════════════
   UTILS BASE
════════════════════════════════════════════ */
function getCsrf() {
    return document.getElementById('csrfTokenJs')?.value ?? '';
}
function toggleSidebar() {
    document.getElementById('adminSidebar').classList.toggle('open');
}
function closeSidebar() {
    document.getElementById('adminSidebar').classList.remove('open');
}
function cerrarModal(id) {
    document.getElementById(id)?.classList.remove('visible');
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.visible').forEach(m => m.classList.remove('visible'));
    }
});

function showToast(msg, type = 'success', ms = 4000) {
    const c = document.getElementById('toast-container');
    if (!c) return;
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    const icons = { success: 'bi-check-circle-fill', error: 'bi-x-circle-fill', info: 'bi-info-circle-fill' };
    t.innerHTML = `<i class="bi ${icons[type] || icons.info}"></i>${msg}`;
    c.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transition = '.3s'; }, ms - 300);
    setTimeout(() => t.remove(), ms);
}

async function ajaxPost(data) {
    const r = await fetch('<?= APP_URL ?>/admin/anuncios.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ ...data, csrf: getCsrf() })
    });
    return r.json();
}

/* ════════════════════════════════════════════
   FORMULARIO — Tipo de anuncio
════════════════════════════════════════════ */
function cambiarTipo(tipo) {
    // Ocultar todas las secciones de contenido
    document.querySelectorAll('.tipo-section').forEach(s => s.classList.remove('visible'));
    // Mostrar la que corresponde
    const secMap = { imagen: 'seccionImagen', html: 'seccionHtml', video: 'seccionVideo' };
    const sec = document.getElementById(secMap[tipo]);
    if (sec) sec.classList.add('visible');
}

/* ════════════════════════════════════════════
   IMAGEN PREVIEW
════════════════════════════════════════════ */
function previewImagen(input) {
    if (!input.files?.length) return;
    const reader = new FileReader();
    reader.onload = e => {
        const zone  = document.getElementById('imgZone');
        const img   = document.getElementById('imgPreview');
        if (img)  { img.src = e.target.result; img.style.display = 'block'; }
        if (zone) zone.classList.add('has-img');
        // Limpiar URL manual si se subió archivo
        const urlField = document.getElementById('imagenUrlManual');
        if (urlField) urlField.value = '';
    };
    reader.readAsDataURL(input.files[0]);
}

function previewImagenUrl(url) {
    if (!url) return;
    const zone = document.getElementById('imgZone');
    const img  = document.getElementById('imgPreview');
    if (img)  { img.src = url; img.style.display = 'block'; img.onerror = () => img.style.display='none'; }
    if (zone) zone.classList.add('has-img');
    // Limpiar archivo si se puso URL
    const fileInput = document.getElementById('adImagenFile');
    if (fileInput) fileInput.value = '';
}

function quitarImagen() {
    const zone      = document.getElementById('imgZone');
    const img       = document.getElementById('imgPreview');
    const fileInput = document.getElementById('adImagenFile');
    const urlField  = document.getElementById('imagenUrlManual');
    const hiddenAct = document.getElementById('imagenActual');
    if (img)       { img.src = ''; img.style.display = 'none'; }
    if (zone)       zone.classList.remove('has-img');
    if (fileInput)  fileInput.value = '';
    if (urlField)   urlField.value = '';
    if (hiddenAct)  hiddenAct.value = '';
}

/* ════════════════════════════════════════════
   HTML PREVIEW
════════════════════════════════════════════ */
function previewHtml(html) {
    const box = document.getElementById('htmlPreview');
    if (!box) return;

    if (!html || !html.trim()) {
        box.innerHTML = '<em style="color:#999">El HTML renderizado aparecerá aquí...</em>';
        return;
    }

    // Detectar si contiene scripts (AdSense, JS, etc.)
    const hasScript = /<script[\s\S]*?>[\s\S]*?<\/script>/i.test(html)
                   || /adsbygoogle/i.test(html)
                   || /<ins\s/i.test(html);

    if (hasScript) {
        // Para AdSense y scripts: mostrar aviso informativo en lugar de intentar renderizar
        box.innerHTML = `
            <div style="display:flex;flex-direction:column;align-items:center;
                        justify-content:center;padding:24px;gap:12px;
                        background:rgba(251,191,36,.08);border:1px dashed #f59e0b;
                        border-radius:8px;min-height:80px">
                <i class="bi bi-google" style="font-size:2rem;color:#f59e0b"></i>
                <div style="text-align:center">
                    <strong style="color:var(--text-primary);font-size:.9rem">
                        Código Google AdSense / Script detectado
                    </strong>
                    <div style="font-size:.78rem;color:var(--text-muted);margin-top:4px;max-width:320px">
                        Los scripts de AdSense no se pueden previsualizar aquí por seguridad del navegador.
                        El anuncio aparecerá correctamente en las páginas del sitio una vez guardado.
                    </div>
                </div>
                <div style="font-size:.72rem;font-family:monospace;
                            background:var(--bg-surface-2);padding:6px 12px;
                            border-radius:6px;color:var(--text-muted);
                            max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    ${html.substring(0, 80).replace(/</g,'&lt;').replace(/>/g,'&gt;')}...
                </div>
            </div>`;
    } else {
        // Para HTML normal sin scripts: renderizar directo
        box.innerHTML = html;
    }
}

/* ════════════════════════════════════════════
   TOGGLE ACTIVO (en la lista)
════════════════════════════════════════════ */
async function toggleAnuncio(id, cb) {
    try {
        const d = await ajaxPost({ action: 'toggle_activo', id });
        if (d.success) {
            showToast(d.message, 'success', 2500);
            // Actualizar badge de estado en la fila
            const row = document.getElementById('arow-' + id);
            if (row) {
                const badge = row.querySelector('[style*="border-radius: var(--border-radius-full)"]');
                if (badge) {
                    if (d.valor) {
                        badge.textContent = 'Activo';
                        badge.style.background = 'rgba(34,197,94,.12)';
                        badge.style.color = '#22c55e';
                    } else {
                        badge.textContent = 'Pausado';
                        badge.style.background = 'rgba(107,114,128,.12)';
                        badge.style.color = '#6b7280';
                    }
                }
            }
        } else {
            cb.checked = !cb.checked;
            showToast(d.message || 'Error.', 'error');
        }
    } catch {
        cb.checked = !cb.checked;
        showToast('Error de conexión.', 'error');
    }
}

/* ════════════════════════════════════════════
   DUPLICAR
════════════════════════════════════════════ */
async function duplicarAnuncio(id) {
    if (!confirm('¿Duplicar este anuncio como borrador pausado?')) return;
    try {
        const d = await ajaxPost({ action: 'duplicar', id });
        if (d.success) {
            showToast('✅ ' + d.message, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast('❌ ' + d.message, 'error');
        }
    } catch {
        showToast('Error de conexión.', 'error');
    }
}

/* ════════════════════════════════════════════
   ELIMINAR
════════════════════════════════════════════ */
let _deleteId = null;

function confirmarEliminar(id, nombre) {
    _deleteId = id;
    document.getElementById('deleteModalBody').innerHTML =
        `¿Eliminar el anuncio <strong>"${nombre}"</strong>?<br>
         <small style="color:var(--danger)">También se eliminará todo el historial de clics e impresiones.</small>`;
    document.getElementById('deleteModal').classList.add('visible');
}

async function ejecutarEliminar() {
    if (!_deleteId) return;
    const id = _deleteId;
    cerrarModal('deleteModal');
    _deleteId = null;
    try {
        const d = await ajaxPost({ action: 'eliminar', id });
        if (d.success) {
            const row = document.getElementById('arow-' + id);
            if (row) {
                row.style.opacity = '0';
                row.style.transition = '.3s';
                setTimeout(() => row.remove(), 300);
            }
            showToast('🗑️ ' + d.message, 'success');
        } else {
            showToast('❌ ' + d.message, 'error');
        }
    } catch {
        showToast('Error de conexión.', 'error');
    }
}

/* ════════════════════════════════════════════
   RESET STATS
════════════════════════════════════════════ */
let _resetId = null;

function confirmarResetStats(id, nombre) {
    _resetId = id;
    document.getElementById('resetModalBody').innerHTML =
        `¿Resetear todas las estadísticas (impresiones y clics) de <strong>"${nombre}"</strong>?<br>
         <small style="color:var(--warning)">Esta acción no se puede deshacer.</small>`;
    document.getElementById('resetModal').classList.add('visible');
}

async function ejecutarResetStats() {
    if (!_resetId) return;
    const id = _resetId;
    cerrarModal('resetModal');
    _resetId = null;
    try {
        const d = await ajaxPost({ action: 'reset_stats', id });
        if (d.success) {
            showToast('✅ ' + d.message, 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast('❌ ' + d.message, 'error');
        }
    } catch {
        showToast('Error de conexión.', 'error');
    }
}

/* ════════════════════════════════════════════
   INIT
════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
    // Activar la sección correcta al cargar en modo edición/nuevo
    const tipoSel = document.getElementById('adTipo');
    if (tipoSel) {
        cambiarTipo(tipoSel.value);
    }

    // Preview HTML si ya tiene contenido
    const htmlArea = document.getElementById('adContenidoHtml');
    if (htmlArea && htmlArea.value.trim()) {
        previewHtml(htmlArea.value);
    }
});
</script>
</body>
</html>