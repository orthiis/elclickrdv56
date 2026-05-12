<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Admin: Coberturas en Vivo
 * ============================================================
 * Archivo : admin/coberturasenvivo.php
 * Versión : 2.0.0
 * Tablas  : live_blog, live_blog_updates, categorias, usuarios
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// ── Protección: solo admins ───────────────────────────────────
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

if (!can('live_blog', 'ver')) {
    setFlashMessage('error', 'No tienes permiso para acceder a esta sección.');
    header('Location: ' . APP_URL . '/admin/dashboard.php');
    exit;
}

$usuario = currentUser();

// ============================================================
// PARÁMETROS Y ACCIONES
// ============================================================
$accion   = cleanInput($_GET['accion']  ?? 'lista');   // lista|nuevo|editar|ver
$blogId   = (int)($_GET['id']           ?? 0);
$updateId = (int)($_GET['update_id']    ?? 0);

// Filtros para la lista
$fEstado  = cleanInput($_GET['estado']  ?? '');
$fCat     = (int)($_GET['cat']          ?? 0);
$fBusq    = cleanInput($_GET['q']       ?? '');
$pagActual = max(1, (int)($_GET['pag'] ?? 1));
$porPag   = 15;

// ============================================================
// CSRF helpers locales
// ============================================================
$csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrfToken;

function verifyCsrfLocal(string $token): bool {
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// ============================================================
// PROCESAR POST — CRUD
// ============================================================
$flash = ['type' => '', 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfLocal($_POST['csrf_token'] ?? '')) {
        $flash = ['type' => 'error', 'msg' => 'Token de seguridad inválido. Recarga la página.'];
    } else {

        $postAccion = cleanInput($_POST['post_accion'] ?? '');

        // ── CREAR / EDITAR COBERTURA ──────────────────────────
        if (in_array($postAccion, ['crear_blog', 'editar_blog'], true)) {
            if (!can('live_blog', $postAccion === 'crear_blog' ? 'crear' : 'editar')) {
                $flash = ['type'=>'error','msg'=>'Sin permiso.'];
            } else {
                $titulo      = cleanInput($_POST['titulo']      ?? '', 200);
                $descripcion = cleanInput($_POST['descripcion'] ?? '', 1000);
                $catId       = (int)($_POST['categoria_id']    ?? 0);
                $estado      = in_array($_POST['estado'] ?? '', ['activo','pausado','finalizado'], true)
                               ? $_POST['estado'] : 'activo';
                $imagen      = cleanInput($_POST['imagen']      ?? '', 500);
                $editId      = (int)($_POST['blog_id']         ?? 0);

                if (empty($titulo)) {
                    $flash = ['type'=>'error','msg'=>'El título es obligatorio.'];
                } else {
                    if ($postAccion === 'crear_blog') {
                        $slug = generateSlug($titulo, 'live_blog');
                        $newId = db()->insert(
                            "INSERT INTO live_blog
                                (titulo, slug, descripcion, imagen, categoria_id,
                                 autor_id, estado, fecha_inicio, total_updates, vistas)
                             VALUES (?,?,?,?,?,?,?,NOW(),0,0)",
                            [$titulo, $slug, $descripcion, $imagen,
                             $catId ?: null, $usuario['id'], $estado]
                        );
                        logActividad((int)$usuario['id'], 'crear_live_blog', 'live_blog', $newId,
                            "Creó cobertura: $titulo");
                        setFlashMessage('success', '¡Cobertura creada exitosamente!');
                        header('Location: ' . APP_URL . '/admin/coberturasenvivo.php?accion=ver&id=' . $newId);
                        exit;
                    } else {
                        $slugActual = db()->fetchColumn(
                            "SELECT slug FROM live_blog WHERE id = ? LIMIT 1", [$editId]
                        );
                        $slugActualTitulo = db()->fetchColumn(
                            "SELECT titulo FROM live_blog WHERE id = ? LIMIT 1", [$editId]
                        );
                        $nuevoSlug = ($slugActualTitulo !== $titulo)
                            ? generateSlug($titulo, 'live_blog', $editId)
                            : $slugActual;

                        $finSql = $estado === 'finalizado'
                            ? ', fecha_fin = IF(fecha_fin IS NULL, NOW(), fecha_fin)' : '';

                        db()->execute(
                            "UPDATE live_blog
                             SET titulo = ?, slug = ?, descripcion = ?, imagen = ?,
                                 categoria_id = ?, estado = ? $finSql
                             WHERE id = ?",
                            [$titulo, $nuevoSlug, $descripcion, $imagen,
                             $catId ?: null, $estado, $editId]
                        );
                        logActividad((int)$usuario['id'], 'editar_live_blog', 'live_blog', $editId,
                            "Editó cobertura: $titulo");
                        setFlashMessage('success', 'Cobertura actualizada correctamente.');
                        header('Location: ' . APP_URL . '/admin/coberturasenvivo.php?accion=ver&id=' . $editId);
                        exit;
                    }
                }
            }
        }

        // ── CAMBIAR ESTADO ────────────────────────────────────
        elseif ($postAccion === 'cambiar_estado') {
            if (!can('live_blog', 'editar')) {
                $flash = ['type'=>'error','msg'=>'Sin permiso.'];
            } else {
                $bid       = (int)($_POST['blog_id'] ?? 0);
                $nuevoEst  = cleanInput($_POST['estado'] ?? '');
                if ($bid && in_array($nuevoEst, ['activo','pausado','finalizado'], true)) {
                    $finSql = $nuevoEst === 'finalizado'
                        ? ', fecha_fin = IF(fecha_fin IS NULL, NOW(), fecha_fin)' : '';
                    db()->execute(
                        "UPDATE live_blog SET estado = ? $finSql WHERE id = ?",
                        [$nuevoEst, $bid]
                    );
                    logActividad((int)$usuario['id'], 'cambiar_estado_live', 'live_blog', $bid,
                        "Cambió estado a: $nuevoEst");
                    $flash = ['type'=>'success','msg'=>'Estado actualizado: ' . ucfirst($nuevoEst)];
                    $accion  = 'ver';
                    $blogId  = $bid;
                }
            }
        }

        // ── PUBLICAR UPDATE (minuto a minuto) ─────────────────
        elseif ($postAccion === 'publicar_update') {
            if (!can('live_blog', 'editar')) {
                $flash = ['type'=>'error','msg'=>'Sin permiso.'];
            } else {
                $bid         = (int)($_POST['blog_id']       ?? 0);
                $contenido   = trim($_POST['contenido']       ?? '');
                $tipo        = in_array($_POST['tipo'] ?? '', ['texto','imagen','video','cita','breaking','alerta'], true)
                               ? $_POST['tipo'] : 'texto';
                $imagenUpd   = cleanInput($_POST['imagen']    ?? '', 500);
                $videoUrl    = cleanInput($_POST['video_url'] ?? '', 500);
                $esDestacado = isset($_POST['es_destacado']) ? 1 : 0;

                if (empty($contenido)) {
                    $flash = ['type'=>'error','msg'=>'El contenido del update es obligatorio.'];
                    $accion = 'ver'; $blogId = $bid;
                } else {
                    db()->insert(
                        "INSERT INTO live_blog_updates
                            (blog_id, autor_id, contenido, tipo, imagen,
                             video_url, es_destacado, fecha)
                         VALUES (?,?,?,?,?,?,?,NOW())",
                        [$bid, $usuario['id'], $contenido, $tipo,
                         $imagenUpd ?: null, $videoUrl ?: null, $esDestacado]
                    );
                    db()->execute(
                        "UPDATE live_blog SET total_updates = total_updates + 1 WHERE id = ?",
                        [$bid]
                    );
                    logActividad((int)$usuario['id'], 'publicar_update', 'live_blog_updates', $bid,
                        "Publicó update tipo $tipo en blog #$bid");
                    $flash  = ['type'=>'success','msg'=>'✅ Update publicado correctamente.'];
                    $accion = 'ver';
                    $blogId = $bid;
                }
            }
        }

        // ── EDITAR UPDATE ─────────────────────────────────────
        elseif ($postAccion === 'editar_update') {
            $uid      = (int)($_POST['update_id'] ?? 0);
            $bid      = (int)($_POST['blog_id']   ?? 0);
            $cont     = trim($_POST['contenido']  ?? '');
            $destac   = isset($_POST['es_destacado']) ? 1 : 0;
            $tipoUpd  = in_array($_POST['tipo'] ?? '', ['texto','imagen','video','cita','breaking','alerta'], true)
                        ? $_POST['tipo'] : 'texto';

            if ($uid && !empty($cont)) {
                db()->execute(
                    "UPDATE live_blog_updates
                     SET contenido = ?, tipo = ?, es_destacado = ?
                     WHERE id = ? AND blog_id = ?",
                    [$cont, $tipoUpd, $destac, $uid, $bid]
                );
                $flash  = ['type'=>'success','msg'=>'Update editado correctamente.'];
            } else {
                $flash  = ['type'=>'error','msg'=>'El contenido no puede estar vacío.'];
            }
            $accion = 'ver'; $blogId = $bid;
        }

        // ── ELIMINAR UPDATE ───────────────────────────────────
        elseif ($postAccion === 'eliminar_update') {
            if (!can('live_blog', 'eliminar')) {
                $flash = ['type'=>'error','msg'=>'Sin permiso para eliminar updates.'];
            } else {
                $uid = (int)($_POST['update_id'] ?? 0);
                $bid = (int)($_POST['blog_id']   ?? 0);
                if ($uid && $bid) {
                    db()->execute(
                        "DELETE FROM live_blog_updates WHERE id = ? AND blog_id = ?",
                        [$uid, $bid]
                    );
                    db()->execute(
                        "UPDATE live_blog SET total_updates = GREATEST(total_updates - 1, 0) WHERE id = ?",
                        [$bid]
                    );
                    $flash  = ['type'=>'success','msg'=>'Update eliminado.'];
                }
                $accion = 'ver'; $blogId = $bid;
            }
        }

        // ── ELIMINAR COBERTURA ────────────────────────────────
        elseif ($postAccion === 'eliminar_blog') {
            if (!can('live_blog', 'eliminar')) {
                $flash = ['type'=>'error','msg'=>'Sin permiso para eliminar coberturas.'];
            } else {
                $bid = (int)($_POST['blog_id'] ?? 0);
                if ($bid) {
                    db()->execute("DELETE FROM live_blog_updates WHERE blog_id = ?", [$bid]);
                    db()->execute("DELETE FROM live_blog WHERE id = ?", [$bid]);
                    logActividad((int)$usuario['id'], 'eliminar_live_blog', 'live_blog', $bid,
                        "Eliminó cobertura #$bid");
                    setFlashMessage('success', 'Cobertura eliminada correctamente.');
                    header('Location: ' . APP_URL . '/admin/coberturasenvivo.php');
                    exit;
                }
            }
        }
    }
}

// ============================================================
// CARGAR DATOS SEGÚN ACCIÓN
// ============================================================

// Categorías para selects
$categorias = db()->fetchAll(
    "SELECT id, nombre, color, icono FROM categorias WHERE activa = 1 ORDER BY nombre",
    []
);

// ── Vista: lista ──────────────────────────────────────────────
$blogData   = [];
$totalBlogs = 0;

if ($accion === 'lista') {
    $where  = ['1=1'];
    $params = [];

    if ($fEstado) {
        $where[]  = 'lb.estado = ?';
        $params[] = $fEstado;
    }
    if ($fCat) {
        $where[]  = 'lb.categoria_id = ?';
        $params[] = $fCat;
    }
    if ($fBusq) {
        $where[]  = '(lb.titulo LIKE ? OR lb.descripcion LIKE ?)';
        $b = "%$fBusq%";
        $params[] = $b; $params[] = $b;
    }

    $whereStr   = implode(' AND ', $where);
    $totalBlogs = (int)db()->fetchColumn(
        "SELECT COUNT(*) FROM live_blog lb WHERE $whereStr", $params
    );
    $offset = ($pagActual - 1) * $porPag;

    $blogData = db()->fetchAll(
        "SELECT lb.*,
                c.nombre AS cat_nombre, c.color AS cat_color,
                u.nombre AS autor_nombre, u.avatar AS autor_avatar
         FROM live_blog lb
         LEFT JOIN categorias c ON c.id = lb.categoria_id
         LEFT JOIN usuarios   u ON u.id = lb.autor_id
         WHERE $whereStr
         ORDER BY
            CASE lb.estado WHEN 'activo' THEN 0 WHEN 'pausado' THEN 1 ELSE 2 END,
            lb.fecha_inicio DESC
         LIMIT ? OFFSET ?",
        array_merge($params, [$porPag, $offset])
    );

    // Stats globales
    $statsGlobales = [
        'total'      => (int)db()->fetchColumn("SELECT COUNT(*) FROM live_blog"),
        'activos'    => (int)db()->fetchColumn("SELECT COUNT(*) FROM live_blog WHERE estado='activo'"),
        'pausados'   => (int)db()->fetchColumn("SELECT COUNT(*) FROM live_blog WHERE estado='pausado'"),
        'finalizados'=> (int)db()->fetchColumn("SELECT COUNT(*) FROM live_blog WHERE estado='finalizado'"),
        'updates'    => (int)db()->fetchColumn("SELECT COALESCE(SUM(total_updates),0) FROM live_blog"),
        'vistas'     => (int)db()->fetchColumn("SELECT COALESCE(SUM(vistas),0) FROM live_blog"),
    ];
}

// ── Vista: ver (detalle + updates) ────────────────────────────
$blogActual  = null;
$updates     = [];
$totalUpdPag = 0;
$pagUpd      = max(1, (int)($_GET['pag_upd'] ?? 1));
$porPagUpd   = 20;

if (in_array($accion, ['ver','editar'], true) && $blogId) {
    $blogActual = db()->fetchOne(
        "SELECT lb.*,
                c.nombre AS cat_nombre, c.color AS cat_color,
                u.nombre AS autor_nombre, u.avatar AS autor_avatar
         FROM live_blog lb
         LEFT JOIN categorias c ON c.id = lb.categoria_id
         LEFT JOIN usuarios   u ON u.id = lb.autor_id
         WHERE lb.id = ? LIMIT 1",
        [$blogId]
    );

    if (!$blogActual) {
        setFlashMessage('error', 'Cobertura no encontrada.');
        header('Location: ' . APP_URL . '/admin/coberturasenvivo.php');
        exit;
    }

    if ($accion === 'ver') {
        $totalUpdPag = (int)db()->fetchColumn(
            "SELECT COUNT(*) FROM live_blog_updates WHERE blog_id = ?", [$blogId]
        );
        $offUpd  = ($pagUpd - 1) * $porPagUpd;
        $updates = db()->fetchAll(
            "SELECT lbu.*, u.nombre AS autor_nombre, u.avatar AS autor_avatar
             FROM live_blog_updates lbu
             INNER JOIN usuarios u ON u.id = lbu.autor_id
             WHERE lbu.blog_id = ?
             ORDER BY lbu.fecha DESC
             LIMIT ? OFFSET ?",
            [$blogId, $porPagUpd, $offUpd]
        );
    }
}

// Flash del session (de redirects anteriores)
$sessionFlash = getFlashMessage();

// ── Título de página ──────────────────────────────────────────
$pageTitle = match($accion) {
    'nuevo'  => 'Nueva Cobertura en Vivo',
    'editar' => 'Editar Cobertura: ' . truncateChars($blogActual['titulo'] ?? '', 40),
    'ver'    => 'Gestionar: ' . truncateChars($blogActual['titulo'] ?? '', 40),
    default  => 'Coberturas en Vivo',
};

require_once __DIR__ . '/sidebar.php';
?>
<!DOCTYPE html>
<html lang="es" data-theme="<?= e(Config::get('apariencia_modo_oscuro', 'auto')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — Panel Admin</title>
<meta name="robots" content="noindex, nofollow">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= APP_VERSION ?>">

<style>
/* ================================================================
   ADMIN BASE LAYOUT
================================================================ */
body { padding-bottom: 0; background: var(--bg-body); }
.admin-wrapper { display: flex; min-height: 100vh; }

/* ================================================================
   SIDEBAR — Estilos completos (idénticos a noticias/comentarios)
================================================================ */
.admin-sidebar {
    width: 260px;
    background: var(--secondary-dark);
    position: fixed; top: 0; left: 0;
    height: 100vh; overflow-y: auto;
    z-index: var(--z-header);
    transition: transform var(--transition-base);
    display: flex; flex-direction: column;
}
.admin-sidebar::-webkit-scrollbar { width: 4px; }
.admin-sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); }

/* Forzar fondo oscuro en todos los temas */
[data-theme] .admin-sidebar { background: #0f1f33 !important; }

.admin-sidebar__logo {
    padding: 24px 20px 16px;
    border-bottom: 1px solid rgba(255,255,255,.07);
    flex-shrink: 0;
}
.admin-sidebar__logo a {
    display: flex; align-items: center; gap: 10px; text-decoration: none;
}
.admin-sidebar__logo-icon {
    width: 36px; height: 36px;
    background: var(--primary); border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 1rem; flex-shrink: 0;
}
.admin-sidebar__logo-text {
    font-family: var(--font-serif);
    font-size: 1rem; font-weight: 800;
    color: #fff; line-height: 1.1;
}
.admin-sidebar__logo-sub {
    font-size: .65rem; color: rgba(255,255,255,.4);
    font-family: var(--font-sans); font-weight: 400;
    text-transform: uppercase; letter-spacing: .06em;
}

.admin-sidebar__user {
    padding: 14px 20px;
    border-bottom: 1px solid rgba(255,255,255,.07);
    display: flex; align-items: center; gap: 10px; flex-shrink: 0;
}
.admin-sidebar__user img {
    width: 36px; height: 36px;
    border-radius: 50%; object-fit: cover;
    border: 2px solid rgba(255,255,255,.15);
}
.admin-sidebar__user-name {
    font-size: .82rem; font-weight: 600;
    color: rgba(255,255,255,.9);
    display: block; line-height: 1.2;
}
.admin-sidebar__user-role {
    font-size: .68rem;
    color: rgba(255,255,255,.4);
    text-transform: capitalize;
}

.admin-nav { flex: 1; padding: 12px 0; overflow-y: auto; }

.admin-nav__section {
    padding: 14px 20px 6px;
    font-size: .62rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .1em;
    color: rgba(255,255,255,.25);
}

.admin-nav__item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 20px;
    color: rgba(255,255,255,.6);
    font-size: .82rem; font-weight: 500;
    text-decoration: none;
    transition: all var(--transition-fast);
    position: relative;
}
.admin-nav__item:hover {
    background: rgba(255,255,255,.06);
    color: rgba(255,255,255,.9);
}
.admin-nav__item.active {
    background: rgba(230,57,70,.18);
    color: #fff; font-weight: 600;
}
.admin-nav__item.active::before {
    content: '';
    position: absolute; left: 0; top: 0; bottom: 0;
    width: 3px; background: var(--primary);
    border-radius: 0 3px 3px 0;
}
.admin-nav__item i {
    width: 18px; text-align: center;
    font-size: .9rem; flex-shrink: 0;
}
.admin-nav__item > span:not(.admin-nav__badge) { flex: 1; }

.admin-nav__badge {
    margin-left: auto;
    background: var(--primary); color: #fff;
    font-size: .6rem; font-weight: 700;
    padding: 2px 6px;
    border-radius: var(--border-radius-full);
    min-width: 18px; text-align: center;
    flex-shrink: 0;
}

.admin-sidebar__footer {
    padding: 16px 20px;
    border-top: 1px solid rgba(255,255,255,.07);
    flex-shrink: 0;
}

.admin-main {
    margin-left: 260px;
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
}

.admin-topbar {
    position: sticky; top: 0; z-index: 200;
    background: var(--bg-surface);
    border-bottom: 1px solid var(--border-color);
    display: flex; align-items: center;
    gap: 12px; padding: 0 24px;
    height: 60px;
    box-shadow: var(--shadow-sm);
}

.admin-topbar__toggle {
    display: none;
    align-items: center; justify-content: center;
    width: 36px; height: 36px;
    border-radius: var(--border-radius-sm);
    color: var(--text-secondary);
    font-size: 1.2rem;
    transition: background var(--transition-fast);
}
.admin-topbar__toggle:hover { background: var(--bg-surface-2); }

.admin-topbar__title {
    font-size: .95rem; font-weight: 700;
    color: var(--text-primary);
    flex: 1; display: flex; align-items: center; gap: 8px;
}

.admin-topbar__right {
    display: flex; align-items: center; gap: 8px; margin-left: auto;
}

.admin-content { padding: 24px; flex: 1; }

.admin-overlay {
    display: none;
    position: fixed; inset: 0;
    background: var(--bg-overlay);
    z-index: calc(var(--z-header) - 1);
}

/* ================================================================
   BOTONES
================================================================ */
.btn-p {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; background: var(--primary); color: #fff;
    border: none; border-radius: var(--border-radius);
    font-size: .82rem; font-weight: 600; cursor: pointer;
    transition: all var(--transition-fast); text-decoration: none;
    white-space: nowrap;
}
.btn-p:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: var(--shadow-primary); }

.btn-s {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 16px; background: var(--bg-surface-2);
    color: var(--text-secondary); border: 1px solid var(--border-color);
    border-radius: var(--border-radius); font-size: .82rem;
    font-weight: 500; cursor: pointer; transition: all var(--transition-fast);
    text-decoration: none; white-space: nowrap;
}
.btn-s:hover { background: var(--bg-surface-3); color: var(--text-primary); }

.btn-danger {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; background: rgba(239,68,68,.1);
    color: var(--danger); border: 1px solid rgba(239,68,68,.25);
    border-radius: var(--border-radius); font-size: .78rem;
    font-weight: 600; cursor: pointer; transition: all var(--transition-fast);
    text-decoration: none;
}
.btn-danger:hover { background: var(--danger); color: #fff; }

.btn-warning {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; background: rgba(245,158,11,.1);
    color: var(--warning); border: 1px solid rgba(245,158,11,.25);
    border-radius: var(--border-radius); font-size: .78rem;
    font-weight: 600; cursor: pointer; transition: all var(--transition-fast);
    text-decoration: none;
}
.btn-warning:hover { background: var(--warning); color: #fff; }

.btn-success {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; background: rgba(34,197,94,.1);
    color: var(--success); border: 1px solid rgba(34,197,94,.25);
    border-radius: var(--border-radius); font-size: .78rem;
    font-weight: 600; cursor: pointer; transition: all var(--transition-fast);
    text-decoration: none;
}
.btn-success:hover { background: var(--success); color: #fff; }

.btn-sm { padding: 5px 10px; font-size: .72rem; }

/* ================================================================
   STATS GRID
================================================================ */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 12px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-lg);
    padding: 16px;
    display: flex; align-items: center; gap: 12px;
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-fast);
}
.stat-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }

.stat-card__icon {
    width: 44px; height: 44px;
    border-radius: var(--border-radius);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; flex-shrink: 0;
}

.stat-card__num {
    font-size: 1.4rem; font-weight: 800;
    color: var(--text-primary); line-height: 1;
}

.stat-card__label {
    font-size: .7rem; color: var(--text-muted);
    font-weight: 500; margin-top: 2px;
    text-transform: uppercase; letter-spacing: .04em;
}

/* ================================================================
   FILTROS BAR
================================================================ */
.filters-bar {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-lg);
    padding: 14px 16px;
    display: flex; align-items: center;
    gap: 10px; flex-wrap: wrap;
    margin-bottom: 20px;
    box-shadow: var(--shadow-sm);
}

.search-wrap {
    position: relative; flex: 1; min-width: 180px;
}
.search-wrap i {
    position: absolute; left: 10px; top: 50%;
    transform: translateY(-50%); color: var(--text-muted);
    font-size: .85rem; pointer-events: none;
}
.search-inp {
    width: 100%; padding: 8px 10px 8px 32px;
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    background: var(--bg-surface-2);
    color: var(--text-primary); font-size: .82rem;
    transition: border-color var(--transition-fast);
}
.search-inp:focus { outline: none; border-color: var(--primary); }

.filter-sel {
    padding: 8px 10px;
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    background: var(--bg-surface-2);
    color: var(--text-primary); font-size: .82rem;
    cursor: pointer; min-width: 130px;
}
.filter-sel:focus { outline: none; border-color: var(--primary); }

/* ================================================================
   TABLA DE COBERTURAS
================================================================ */
.table-wrap {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

.data-table {
    width: 100%; border-collapse: collapse;
}

.data-table thead th {
    background: var(--bg-surface-2);
    padding: 12px 14px;
    font-size: .7rem; font-weight: 700;
    color: var(--text-muted); text-transform: uppercase;
    letter-spacing: .06em; text-align: left;
    border-bottom: 1px solid var(--border-color);
    white-space: nowrap;
}

.data-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background var(--transition-fast);
}
.data-table tbody tr:last-child { border-bottom: none; }
.data-table tbody tr:hover { background: var(--bg-surface-2); }

.data-table tbody td {
    padding: 12px 14px;
    font-size: .82rem; color: var(--text-secondary);
    vertical-align: middle;
}

/* ================================================================
   BADGES DE ESTADO
================================================================ */
.estado-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: var(--border-radius-full);
    font-size: .67rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .06em; white-space: nowrap;
}
.estado-badge--activo   { background: rgba(34,197,94,.15);  color: var(--success); }
.estado-badge--pausado  { background: rgba(245,158,11,.15); color: var(--warning); }
.estado-badge--finalizado{ background: rgba(136,136,170,.15); color: var(--text-muted); }

.live-pulse {
    width: 7px; height: 7px; border-radius: 50%;
    background: var(--success);
    animation: livePulseAnim 1.4s ease-in-out infinite;
    box-shadow: 0 0 0 0 rgba(34,197,94,.5);
}
@keyframes livePulseAnim {
    0%   { box-shadow: 0 0 0 0 rgba(34,197,94,.5); }
    70%  { box-shadow: 0 0 0 7px rgba(34,197,94,0); }
    100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); }
}

/* ================================================================
   PAGINACIÓN
================================================================ */
.pag-wrap {
    display: flex; align-items: center;
    justify-content: center; gap: 4px;
    padding: 16px; flex-wrap: wrap;
}
.pag-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 36px; height: 36px; padding: 0 8px;
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-sm);
    color: var(--text-secondary); font-size: .8rem;
    font-weight: 500; text-decoration: none;
    background: var(--bg-surface);
    transition: all var(--transition-fast);
}
.pag-btn:hover   { border-color: var(--primary); color: var(--primary); }
.pag-btn.active  { background: var(--primary); border-color: var(--primary); color: #fff; font-weight: 700; }
.pag-btn.disabled{ opacity: .35; pointer-events: none; }

/* ================================================================
   VISTA DETALLE (VER COBERTURA)
================================================================ */
.live-detail-header {
    background: linear-gradient(135deg, var(--secondary) 0%, var(--secondary-dark) 100%);
    border-radius: var(--border-radius-xl);
    padding: 28px 28px 24px;
    color: #fff;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}
.live-detail-header::before {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(135deg, rgba(230,57,70,.2) 0%, transparent 60%);
    pointer-events: none;
}

.live-detail-title {
    font-family: var(--font-serif);
    font-size: 1.5rem; font-weight: 800;
    color: #fff; line-height: 1.25;
    margin: 8px 0 6px;
}

.live-detail-meta {
    display: flex; align-items: center; gap: 12px;
    flex-wrap: wrap; font-size: .78rem;
    color: rgba(255,255,255,.65);
    margin-bottom: 16px;
}
.live-detail-meta i { font-size: .75rem; }

.live-detail-stats {
    display: flex; gap: 16px; flex-wrap: wrap;
    padding-top: 16px;
    border-top: 1px solid rgba(255,255,255,.12);
}

.live-detail-stat {
    display: flex; flex-direction: column; gap: 2px;
}
.live-detail-stat__val {
    font-size: 1.3rem; font-weight: 800; color: #fff;
}
.live-detail-stat__lbl {
    font-size: .65rem; color: rgba(255,255,255,.5);
    text-transform: uppercase; letter-spacing: .05em;
}

.live-actions-bar {
    display: flex; gap: 8px; flex-wrap: wrap;
    margin-bottom: 20px; align-items: center;
}

/* ================================================================
   PANEL PUBLICAR UPDATE
================================================================ */
.publish-panel {
    background: var(--bg-surface);
    border: 2px solid var(--primary);
    border-radius: var(--border-radius-xl);
    padding: 20px;
    margin-bottom: 24px;
    box-shadow: 0 4px 20px rgba(230,57,70,.12);
}

.publish-panel__title {
    font-size: .82rem; font-weight: 800;
    color: var(--primary); text-transform: uppercase;
    letter-spacing: .08em;
    display: flex; align-items: center; gap: 6px;
    margin-bottom: 14px;
}

.tipo-selector {
    display: flex; gap: 6px; flex-wrap: wrap;
    margin-bottom: 12px;
}

.tipo-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 12px;
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-full);
    font-size: .72rem; font-weight: 600;
    cursor: pointer; transition: all var(--transition-fast);
    background: var(--bg-surface-2);
    color: var(--text-secondary);
}
.tipo-btn:hover { border-color: var(--primary); color: var(--primary); }
.tipo-btn.selected {
    background: var(--primary); border-color: var(--primary);
    color: #fff;
}

.update-textarea {
    width: 100%; padding: 12px;
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    background: var(--bg-surface-2);
    color: var(--text-primary); font-size: .88rem;
    line-height: 1.6; resize: vertical; min-height: 110px;
    font-family: var(--font-sans);
    transition: border-color var(--transition-fast);
}
.update-textarea:focus { outline: none; border-color: var(--primary); }

.publish-panel__footer {
    display: flex; align-items: center;
    justify-content: space-between; flex-wrap: wrap;
    gap: 10px; margin-top: 12px;
}

/* ================================================================
   TIMELINE DE UPDATES
================================================================ */
.updates-timeline {
    display: flex; flex-direction: column; gap: 0;
}

.update-item {
    display: flex; gap: 14px;
    padding: 16px 0;
    border-bottom: 1px solid var(--border-color);
    position: relative;
    animation: slideInUpdate .3s ease;
}
.update-item:last-child { border-bottom: none; }

@keyframes slideInUpdate {
    from { opacity: 0; transform: translateY(-12px); }
    to   { opacity: 1; transform: translateY(0); }
}

.update-item--destacado {
    background: linear-gradient(135deg, rgba(230,57,70,.04) 0%, transparent 100%);
    border-radius: var(--border-radius-lg);
    padding: 16px;
    border-left: 3px solid var(--primary);
    margin-left: -3px;
    border-bottom: 1px solid var(--border-color);
}

.update-timeline-dot {
    width: 36px; height: 36px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; font-size: .9rem;
    border: 2px solid var(--border-color);
    margin-top: 2px;
}

.update-tipo-texto    { background: rgba(59,130,246,.1);  color: var(--info);    border-color: rgba(59,130,246,.3); }
.update-tipo-breaking { background: rgba(230,57,70,.15);  color: var(--primary); border-color: rgba(230,57,70,.4); }
.update-tipo-alerta   { background: rgba(245,158,11,.15); color: var(--warning); border-color: rgba(245,158,11,.4); }
.update-tipo-imagen   { background: rgba(139,92,246,.1);  color: #8b5cf6;        border-color: rgba(139,92,246,.3); }
.update-tipo-video    { background: rgba(239,68,68,.1);   color: var(--danger);  border-color: rgba(239,68,68,.3); }
.update-tipo-cita     { background: rgba(34,197,94,.1);   color: var(--success); border-color: rgba(34,197,94,.3); }

.update-body { flex: 1; min-width: 0; }

.update-header {
    display: flex; align-items: center;
    justify-content: space-between;
    gap: 8px; margin-bottom: 6px; flex-wrap: wrap;
}

.update-meta {
    display: flex; align-items: center; gap: 8px;
    flex-wrap: wrap;
}

.update-tipo-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; border-radius: var(--border-radius-full);
    font-size: .62rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: .07em;
}

.update-tiempo {
    font-size: .72rem; color: var(--text-muted);
    font-weight: 500;
}

.update-actions {
    display: flex; gap: 4px; opacity: 0;
    transition: opacity var(--transition-fast);
    flex-shrink: 0;
}
.update-item:hover .update-actions { opacity: 1; }

.update-contenido {
    font-size: .88rem; line-height: 1.65;
    color: var(--text-secondary);
    word-break: break-word;
}

.update-contenido blockquote {
    border-left: 3px solid var(--primary);
    padding-left: 12px; margin: 8px 0;
    color: var(--text-muted); font-style: italic;
}

.update-autor {
    display: flex; align-items: center; gap: 5px;
    margin-top: 8px; font-size: .72rem; color: var(--text-muted);
}
.update-autor img {
    width: 18px; height: 18px; border-radius: 50%; object-fit: cover;
}

.destacado-tag {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 7px; border-radius: var(--border-radius-full);
    background: rgba(230,57,70,.15); color: var(--primary);
    font-size: .62rem; font-weight: 700; text-transform: uppercase;
}

/* ================================================================
   FORMULARIO CREAR/EDITAR
================================================================ */
.form-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-xl);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    margin-bottom: 20px;
}

.form-card__header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color);
    background: var(--bg-surface-2);
    display: flex; align-items: center; gap: 10px;
}

.form-card__title {
    font-size: .88rem; font-weight: 700;
    color: var(--text-primary);
}

.form-card__body { padding: 20px; }

.form-group { margin-bottom: 18px; }

.form-label {
    display: block; font-size: .78rem; font-weight: 600;
    color: var(--text-secondary); margin-bottom: 6px;
}
.form-label .req { color: var(--primary); margin-left: 2px; }

.form-input, .form-select, .form-textarea {
    width: 100%; padding: 10px 12px;
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    background: var(--bg-surface-2);
    color: var(--text-primary); font-size: .85rem;
    transition: border-color var(--transition-fast);
    font-family: var(--font-sans);
}
.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none; border-color: var(--primary);
    background: var(--bg-surface);
    box-shadow: 0 0 0 3px rgba(230,57,70,.08);
}
.form-textarea { resize: vertical; min-height: 90px; line-height: 1.6; }

.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }

.form-help {
    font-size: .72rem; color: var(--text-muted);
    margin-top: 4px;
}

.form-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--border-color);
    background: var(--bg-surface-2);
    display: flex; align-items: center;
    justify-content: space-between; gap: 10px;
    flex-wrap: wrap;
}

/* ================================================================
   MODAL
================================================================ */
.modal-bg {
    position: fixed; inset: 0;
    background: var(--bg-overlay);
    z-index: 800;
    display: none;
    align-items: center; justify-content: center;
    padding: 20px;
}
.modal-bg.open { display: flex; }

.modal-box {
    background: var(--bg-surface);
    border-radius: var(--border-radius-xl);
    width: 100%; max-width: 500px;
    box-shadow: var(--shadow-xl);
    animation: modalIn .2s ease;
    overflow: hidden;
}
@keyframes modalIn {
    from { opacity: 0; transform: scale(.94) translateY(-8px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}

.modal-header {
    padding: 18px 20px;
    border-bottom: 1px solid var(--border-color);
    display: flex; align-items: center;
    justify-content: space-between; gap: 10px;
}
.modal-header h3 {
    font-size: .92rem; font-weight: 700;
    color: var(--text-primary);
    display: flex; align-items: center; gap: 8px;
}
.modal-close {
    width: 30px; height: 30px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: var(--text-muted); font-size: 1rem;
    transition: background var(--transition-fast);
    flex-shrink: 0;
}
.modal-close:hover { background: var(--bg-surface-2); color: var(--text-primary); }

.modal-body { padding: 20px; }
.modal-footer {
    padding: 14px 20px;
    border-top: 1px solid var(--border-color);
    display: flex; justify-content: flex-end; gap: 8px;
    background: var(--bg-surface-2);
}

/* ================================================================
   TOAST NOTIFICATIONS
================================================================ */
#toast-container {
    position: fixed; bottom: 20px; right: 20px;
    z-index: 9999; display: flex; flex-direction: column;
    gap: 8px; pointer-events: none;
}
.toast {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 12px 16px;
    background: var(--bg-surface); border-radius: var(--border-radius-lg);
    box-shadow: var(--shadow-xl); border: 1px solid var(--border-color);
    font-size: .82rem; max-width: 320px; pointer-events: all;
    animation: toastIn .25s ease; color: var(--text-primary);
}
@keyframes toastIn {
    from { opacity: 0; transform: translateX(20px); }
    to   { opacity: 1; transform: translateX(0); }
}
.toast--success i { color: var(--success); font-size: 1rem; }
.toast--error   i { color: var(--danger);  font-size: 1rem; }
.toast--info    i { color: var(--info);    font-size: 1rem; }

/* ================================================================
   COBERTURA CARD (lista)
================================================================ */
.blog-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-lg);
    padding: 16px;
    transition: all var(--transition-fast);
    display: flex; gap: 14px; align-items: flex-start;
    box-shadow: var(--shadow-sm);
}
.blog-card:hover { box-shadow: var(--shadow-md); border-color: var(--primary); }
.blog-card--activo { border-left: 4px solid var(--success); }
.blog-card--pausado { border-left: 4px solid var(--warning); }
.blog-card--finalizado { border-left: 4px solid var(--text-muted); opacity: .85; }

.blog-card__img {
    width: 72px; height: 54px;
    border-radius: var(--border-radius-sm);
    object-fit: cover; flex-shrink: 0;
    background: var(--bg-surface-3);
}

.blog-card__body { flex: 1; min-width: 0; }

.blog-card__title {
    font-size: .9rem; font-weight: 700;
    color: var(--text-primary); margin-bottom: 4px;
    text-decoration: none; display: block;
    line-height: 1.3;
}
.blog-card__title:hover { color: var(--primary); }

.blog-card__meta {
    display: flex; align-items: center; gap: 10px;
    font-size: .72rem; color: var(--text-muted);
    flex-wrap: wrap; margin-bottom: 8px;
}

.blog-card__actions {
    display: flex; gap: 6px; flex-wrap: wrap;
    align-items: center;
}

/* ================================================================
   EMPTY STATE
================================================================ */
.empty-state {
    text-align: center; padding: 60px 20px;
    background: var(--bg-surface);
    border-radius: var(--border-radius-xl);
    border: 2px dashed var(--border-color);
}
.empty-state i {
    font-size: 2.5rem; color: var(--text-muted);
    display: block; margin-bottom: 12px; opacity: .5;
}
.empty-state h3 {
    font-family: var(--font-serif); font-size: 1.1rem;
    color: var(--text-muted); margin-bottom: 6px;
}
.empty-state p { font-size: .82rem; color: var(--text-muted); }

/* ================================================================
   RESPONSIVE — MOBILE FIRST
================================================================ */
@media (max-width: 1100px) {
    .stats-grid { grid-template-columns: repeat(3, 1fr); }
}

@media (max-width: 768px) {
    .admin-sidebar { transform: translateX(-100%); }
    .admin-sidebar.open { transform: translateX(0); box-shadow: var(--shadow-xl); }
    .admin-main { margin-left: 0; }
    .admin-topbar__toggle { display: flex; }
    .admin-overlay { display: block; }
    .admin-content { padding: 14px; }

    .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .stat-card__num { font-size: 1.1rem; }
    .stat-card { padding: 12px; }

    .filters-bar { gap: 8px; }
    .search-wrap { min-width: 100%; }
    .filter-sel { flex: 1; }

    .form-grid, .form-grid-3 { grid-template-columns: 1fr; }

    .data-table thead th:nth-child(4),
    .data-table thead th:nth-child(5),
    .data-table tbody td:nth-child(4),
    .data-table tbody td:nth-child(5) { display: none; }

    .live-detail-title { font-size: 1.15rem; }
    .live-detail-header { padding: 18px 16px; }
    .live-detail-stats { gap: 10px; }

    .publish-panel { padding: 14px; }
    .tipo-selector { gap: 4px; }
    .tipo-btn { font-size: .68rem; padding: 5px 9px; }

    .update-actions { opacity: 1; }

    .blog-card__img { display: none; }
    .blog-card { gap: 10px; }

    .modal-box { max-width: 100%; border-radius: var(--border-radius-lg); }
    .admin-topbar { padding: 0 14px; }
}

@media (max-width: 480px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .stat-card__label { display: none; }
    .admin-topbar__title { font-size: .82rem; }
    .btn-p, .btn-s { font-size: .75rem; padding: 7px 12px; }
    .live-detail-title { font-size: 1rem; }
    .update-contenido { font-size: .82rem; }
}
</style>
</head>
<body>

<div class="admin-wrapper">
<div class="admin-overlay" id="adminOverlay" onclick="closeSidebar()"></div>

<!-- ── SIDEBAR ────────────────────────────────────────────────── -->
<!-- El sidebar ya fue incluido via require_once '/sidebar.php' arriba -->

<main class="admin-main">

    <!-- ── TOPBAR ─────────────────────────────────────────────── -->
    <div class="admin-topbar">
        <button class="admin-topbar__toggle" onclick="toggleSidebar()" aria-label="Menú">
            <i class="bi bi-list"></i>
        </button>

        <h1 class="admin-topbar__title">
            <?php if (in_array($accion, ['ver','editar','nuevo'], true)): ?>
            <a href="<?= APP_URL ?>/admin/coberturasenvivo.php"
               style="color:var(--text-muted);text-decoration:none;font-size:.85rem">
                <i class="bi bi-broadcast-pin"></i> Coberturas
            </a>
            <i class="bi bi-chevron-right" style="font-size:.6rem;color:var(--text-muted)"></i>
            <?= e(truncateChars($pageTitle, 45)) ?>
            <?php else: ?>
            <i class="bi bi-broadcast-pin" style="color:var(--primary)"></i>
            Coberturas en Vivo
            <?php endif; ?>
        </h1>

        <div class="admin-topbar__right">
            <?php if ($accion === 'lista'): ?>
            <?php if (can('live_blog', 'crear')): ?>
            <a href="<?= APP_URL ?>/admin/coberturasenvivo.php?accion=nuevo"
               class="btn-p">
                <i class="bi bi-plus-lg"></i>
                <span class="d-none d-sm-inline">Nueva Cobertura</span>
            </a>
            <?php endif; ?>
            <?php elseif ($accion === 'ver' && $blogActual): ?>
            <?php if (can('live_blog', 'editar')): ?>
            <a href="<?= APP_URL ?>/admin/coberturasenvivo.php?accion=editar&id=<?= $blogActual['id'] ?>"
               class="btn-s btn-sm">
                <i class="bi bi-pencil-fill"></i> Editar
            </a>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/live.php?slug=<?= e($blogActual['slug']) ?>"
               target="_blank" class="btn-s btn-sm">
                <i class="bi bi-box-arrow-up-right"></i> Ver público
            </a>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/index.php" target="_blank"
               class="btn-s btn-sm" style="padding:8px 10px">
                <i class="bi bi-globe2"></i>
            </a>
        </div>
    </div><!-- /.admin-topbar -->

    <div class="admin-content">

    <!-- ── FLASH MESSAGES ────────────────────────────────────── -->
    <?php
    $fm = $sessionFlash ?: $flash;
    if (!empty($fm['msg']) || !empty($fm['message'])):
        $fmsg  = $fm['msg'] ?? $fm['message'] ?? '';
        $ftype = $fm['type'] ?? 'info';
    ?>
    <div class="toast-static"
         style="display:flex;align-items:center;gap:10px;
                padding:12px 16px;margin-bottom:16px;
                background:var(--bg-surface);border-radius:var(--border-radius-lg);
                border:1px solid var(--border-color);
                border-left: 4px solid <?= $ftype==='success' ? 'var(--success)' : ($ftype==='error' ? 'var(--danger)' : 'var(--info)') ?>;
                box-shadow:var(--shadow-sm)">
        <i class="bi bi-<?= $ftype==='success' ? 'check-circle-fill' : ($ftype==='error' ? 'exclamation-circle-fill' : 'info-circle-fill') ?>"
           style="color:<?= $ftype==='success' ? 'var(--success)' : ($ftype==='error' ? 'var(--danger)' : 'var(--info)') ?>;font-size:1.1rem;flex-shrink:0"></i>
        <span style="font-size:.85rem;color:var(--text-primary);flex:1"><?= e($fmsg) ?></span>
        <button onclick="this.parentElement.remove()" style="color:var(--text-muted);font-size:.85rem;flex-shrink:0">&times;</button>
    </div>
    <?php endif; ?>

    <?php /* ============================================================
           VISTA: LISTA DE COBERTURAS
           ============================================================ */ ?>
    <?php if ($accion === 'lista'): ?>

    <!-- Stats globales -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-card__icon" style="background:rgba(230,57,70,.1);color:var(--primary)">
                <i class="bi bi-broadcast-pin"></i>
            </div>
            <div>
                <div class="stat-card__num"><?= number_format($statsGlobales['total']) ?></div>
                <div class="stat-card__label">Total</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card__icon" style="background:rgba(34,197,94,.1);color:var(--success)">
                <i class="bi bi-circle-fill"></i>
            </div>
            <div>
                <div class="stat-card__num"><?= number_format($statsGlobales['activos']) ?></div>
                <div class="stat-card__label">Activas</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card__icon" style="background:rgba(245,158,11,.1);color:var(--warning)">
                <i class="bi bi-pause-circle-fill"></i>
            </div>
            <div>
                <div class="stat-card__num"><?= number_format($statsGlobales['pausados']) ?></div>
                <div class="stat-card__label">Pausadas</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card__icon" style="background:rgba(136,136,170,.1);color:var(--text-muted)">
                <i class="bi bi-stop-circle-fill"></i>
            </div>
            <div>
                <div class="stat-card__num"><?= number_format($statsGlobales['finalizados']) ?></div>
                <div class="stat-card__label">Finalizadas</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card__icon" style="background:rgba(59,130,246,.1);color:var(--info)">
                <i class="bi bi-collection-fill"></i>
            </div>
            <div>
                <div class="stat-card__num"><?= formatNumber($statsGlobales['updates']) ?></div>
                <div class="stat-card__label">Updates</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card__icon" style="background:rgba(139,92,246,.1);color:#8b5cf6">
                <i class="bi bi-eye-fill"></i>
            </div>
            <div>
                <div class="stat-card__num"><?= formatNumber($statsGlobales['vistas']) ?></div>
                <div class="stat-card__label">Vistas</div>
            </div>
        </div>
    </div><!-- /.stats-grid -->

    <!-- Filtros -->
    <form method="GET" class="filters-bar">
        <input type="hidden" name="accion" value="lista">
        <div class="search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" name="q" class="search-inp"
                   placeholder="Buscar cobertura..."
                   value="<?= e($fBusq) ?>" autocomplete="off">
        </div>
        <select name="estado" class="filter-sel" onchange="this.form.submit()">
            <option value="">Todos los estados</option>
            <option value="activo"     <?= $fEstado==='activo'     ?'selected':''?>>🔴 Activas</option>
            <option value="pausado"    <?= $fEstado==='pausado'    ?'selected':''?>>⏸ Pausadas</option>
            <option value="finalizado" <?= $fEstado==='finalizado' ?'selected':''?>>⬛ Finalizadas</option>
        </select>
        <select name="cat" class="filter-sel" onchange="this.form.submit()">
            <option value="">Todas las categorías</option>
            <?php foreach ($categorias as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= $fCat===$cat['id']?'selected':''?>>
                <?= e($cat['nombre']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-p btn-sm">
            <i class="bi bi-funnel-fill"></i> Filtrar
        </button>
        <?php if ($fBusq || $fEstado || $fCat): ?>
        <a href="<?= APP_URL ?>/admin/coberturasenvivo.php" class="btn-s btn-sm">
            <i class="bi bi-x-circle"></i> Limpiar
        </a>
        <?php endif; ?>
    </form>

    <!-- Lista de coberturas -->
    <?php if (empty($blogData)): ?>
    <div class="empty-state">
        <i class="bi bi-broadcast-pin"></i>
        <h3>No hay coberturas</h3>
        <p>
            <?= $fBusq || $fEstado || $fCat
                ? 'Ninguna cobertura coincide con los filtros aplicados.'
                : 'Crea tu primera cobertura en vivo para empezar.' ?>
        </p>
        <?php if (can('live_blog', 'crear') && !$fBusq && !$fEstado && !$fCat): ?>
        <a href="<?= APP_URL ?>/admin/coberturasenvivo.php?accion=nuevo"
           class="btn-p" style="margin-top:16px">
            <i class="bi bi-plus-lg"></i> Nueva Cobertura
        </a>
        <?php endif; ?>
    </div>
    <?php else: ?>

    <!-- Tabla desktop -->
    <div class="table-wrap" style="display:block">
        <div style="overflow-x:auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:40%">Cobertura</th>
                    <th>Estado</th>
                    <th>Categoría</th>
                    <th>Updates</th>
                    <th>Vistas</th>
                    <th>Inicio</th>
                    <th style="width:160px;text-align:center">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($blogData as $blog): ?>
            <tr>
                <td>
                    <div style="display:flex;align-items:center;gap:10px">
                        <?php if (!empty($blog['imagen'])): ?>
                        <img src="<?= e(getImageUrl($blog['imagen'])) ?>"
                             alt="" width="48" height="36"
                             style="border-radius:var(--border-radius-sm);object-fit:cover;flex-shrink:0">
                        <?php else: ?>
                        <div style="width:48px;height:36px;border-radius:var(--border-radius-sm);
                                    background:var(--bg-surface-3);display:flex;align-items:center;
                                    justify-content:center;flex-shrink:0;color:var(--text-muted)">
                            <i class="bi bi-broadcast"></i>
                        </div>
                        <?php endif; ?>
                        <div style="min-width:0">
                            <a href="<?= APP_URL ?>/admin/coberturasenvivo.php?accion=ver&id=<?= $blog['id'] ?>"
                               style="font-weight:700;color:var(--text-primary);text-decoration:none;
                                      display:block;font-size:.85rem;line-height:1.3"
                               class="truncate">
                                <?= e($blog['titulo']) ?>
                            </a>
                            <span style="font-size:.7rem;color:var(--text-muted)">
                                <i class="bi bi-person-fill"></i>
                                <?= e($blog['autor_nombre']) ?>
                            </span>
                        </div>
                    </div>
                </td>
                <td>
                    <?php if ($blog['estado'] === 'activo'): ?>
                    <span class="estado-badge estado-badge--activo">
                        <span class="live-pulse"></span> En Vivo
                    </span>
                    <?php elseif ($blog['estado'] === 'pausado'): ?>
                    <span class="estado-badge estado-badge--pausado">
                        <i class="bi bi-pause-fill"></i> Pausado
                    </span>
                    <?php else: ?>
                    <span class="estado-badge estado-badge--finalizado">
                        <i class="bi bi-stop-fill"></i> Finalizado
                    </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($blog['cat_nombre']): ?>
                    <span style="display:inline-flex;align-items:center;gap:5px;
                                  padding:2px 8px;border-radius:var(--border-radius-full);
                                  background:<?= e($blog['cat_color']) ?>18;
                                  color:<?= e($blog['cat_color']) ?>;
                                  font-size:.7rem;font-weight:700">
                        <?= e($blog['cat_nombre']) ?>
                    </span>
                    <?php else: ?>
                    <span style="color:var(--text-muted);font-size:.75rem">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span style="font-weight:700;color:var(--text-primary)">
                        <?= number_format((int)$blog['total_updates']) ?>
                    </span>
                </td>
                <td>
                    <span style="color:var(--text-secondary)">
                        <?= formatNumber((int)$blog['vistas']) ?>
                    </span>
                </td>
                <td style="font-size:.75rem;color:var(--text-muted)">
                    <?= formatDate($blog['fecha_inicio'], 'short') ?>
                </td>
                <td>
                    <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap">
                        <a href="<?= APP_URL ?>/admin/coberturasenvivo.php?accion=ver&id=<?= $blog['id'] ?>"
                           class="btn-s btn-sm" title="Gestionar updates">
                            <i class="bi bi-layout-text-sidebar"></i>
                        </a>
                        <?php if (can('live_blog', 'editar')): ?>
                        <a href="<?= APP_URL ?>/admin/coberturasenvivo.php?accion=editar&id=<?= $blog['id'] ?>"
                           class="btn-s btn-sm" title="Editar">
                            <i class="bi bi-pencil-fill"></i>
                        </a>
                        <?php endif; ?>
                        <a href="<?= APP_URL ?>/live.php?slug=<?= e($blog['slug']) ?>"
                           target="_blank" class="btn-s btn-sm" title="Ver público">
                            <i class="bi bi-box-arrow-up-right"></i>
                        </a>
                        <?php if (can('live_blog', 'eliminar')): ?>
                        <button onclick="confirmarEliminarBlog(<?= $blog['id'] ?>, '<?= e(addslashes($blog['titulo'])) ?>')"
                                class="btn-danger btn-sm" title="Eliminar">
                            <i class="bi bi-trash3-fill"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div><!-- overflow -->
    </div><!-- table-wrap -->

    <!-- Cards mobile (alternativa) -->
    <?php /* Las cards se muestran automáticamente cuando la tabla se oculta en mobile */ ?>

    <!-- Paginación -->
    <?php
    $totalPags = (int)ceil($totalBlogs / $porPag);
    if ($totalPags > 1):
        function buildLivePagUrl(int $p, array $extra = []): string {
            global $fEstado, $fCat, $fBusq;
            $params = array_filter([
                'accion' => 'lista',
                'estado' => $fEstado,
                'cat'    => $fCat ?: '',
                'q'      => $fBusq,
                'pag'    => $p,
            ]);
            return APP_URL . '/admin/coberturasenvivo.php?' . http_build_query($params);
        }
    ?>
    <div class="pag-wrap" style="margin-top:8px">
        <a href="<?= buildLivePagUrl(max(1, $pagActual-1)) ?>"
           class="pag-btn <?= $pagActual<=1?'disabled':''?>">
            <i class="bi bi-chevron-left"></i>
        </a>
        <?php for ($p = max(1,$pagActual-2); $p <= min($totalPags,$pagActual+2); $p++): ?>
        <a href="<?= buildLivePagUrl($p) ?>"
           class="pag-btn <?= $p===$pagActual?'active':''?>"><?= $p ?></a>
        <?php endfor; ?>
        <a href="<?= buildLivePagUrl(min($totalPags,$pagActual+1)) ?>"
           class="pag-btn <?= $pagActual>=$totalPags?'disabled':''?>">
            <i class="bi bi-chevron-right"></i>
        </a>
    </div>
    <?php endif; ?>

    <?php endif; // empty blogData ?>


    <?php /* ============================================================
           VISTA: NUEVA / EDITAR COBERTURA
           ============================================================ */ ?>
    <?php elseif ($accion === 'nuevo' || $accion === 'editar'): ?>

    <div style="max-width:760px">

        <a href="<?= APP_URL ?>/admin/coberturasenvivo.php"
           class="btn-s btn-sm" style="margin-bottom:20px;display:inline-flex">
            <i class="bi bi-arrow-left"></i> Volver a la lista
        </a>

        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="post_accion"
                   value="<?= $accion === 'editar' ? 'editar_blog' : 'crear_blog' ?>">
            <?php if ($accion === 'editar' && $blogActual): ?>
            <input type="hidden" name="blog_id" value="<?= $blogActual['id'] ?>">
            <?php endif; ?>

            <!-- Datos principales -->
            <div class="form-card">
                <div class="form-card__header">
                    <i class="bi bi-broadcast-pin" style="color:var(--primary)"></i>
                    <span class="form-card__title">
                        <?= $accion === 'editar' ? 'Editar cobertura' : 'Nueva cobertura en vivo' ?>
                    </span>
                </div>
                <div class="form-card__body">

                    <div class="form-group">
                        <label class="form-label">
                            Título de la cobertura <span class="req">*</span>
                        </label>
                        <input type="text" name="titulo" class="form-input"
                               placeholder="Ej: Elecciones Presidenciales RD 2028 — Cobertura en Vivo"
                               value="<?= e($blogActual['titulo'] ?? '') ?>"
                               maxlength="200" required autofocus>
                        <span class="form-help">Máximo 200 caracteres. Se generará un slug automáticamente.</span>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Descripción / Bajada</label>
                        <textarea name="descripcion" class="form-textarea"
                                  placeholder="Descripción breve de qué se cubre en este live blog..."
                                  maxlength="1000"><?= e($blogActual['descripcion'] ?? '') ?></textarea>
                        <span class="form-help">Aparecerá debajo del título en la página pública.</span>
                    </div>

                    <div class="form-grid">
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label">Categoría</label>
                            <select name="categoria_id" class="form-select">
                                <option value="">— Sin categoría —</option>
                                <?php foreach ($categorias as $cat): ?>
                                <option value="<?= $cat['id'] ?>"
                                    <?= (int)($blogActual['categoria_id'] ?? 0) === $cat['id'] ? 'selected' : '' ?>>
                                    <?= e($cat['nombre']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label">Estado inicial</label>
                            <select name="estado" class="form-select">
                                <option value="activo"
                                    <?= ($blogActual['estado'] ?? 'activo') === 'activo' ? 'selected' : '' ?>>
                                    🔴 Activo (En Vivo)
                                </option>
                                <option value="pausado"
                                    <?= ($blogActual['estado'] ?? '') === 'pausado' ? 'selected' : '' ?>>
                                    ⏸ Pausado
                                </option>
                                <option value="finalizado"
                                    <?= ($blogActual['estado'] ?? '') === 'finalizado' ? 'selected' : '' ?>>
                                    ⬛ Finalizado
                                </option>
                            </select>
                        </div>
                    </div>

                </div><!-- /.form-card__body -->
            </div><!-- /.form-card -->

            <!-- Imagen de portada -->
            <div class="form-card">
                <div class="form-card__header">
                    <i class="bi bi-image-fill" style="color:#8b5cf6"></i>
                    <span class="form-card__title">Imagen de portada</span>
                </div>
                <div class="form-card__body">
                    <div class="form-group" style="margin-bottom:0">
                        <label class="form-label">URL de la imagen</label>
                        <input type="text" name="imagen" id="imgInput" class="form-input"
                               placeholder="https://... o ruta relativa assets/images/..."
                               value="<?= e($blogActual['imagen'] ?? '') ?>">
                        <span class="form-help">
                            Ingresa la URL de la imagen o selecciona desde el gestor de medios.
                        </span>
                        <div id="imgPreview" style="margin-top:10px;display:<?= !empty($blogActual['imagen'])?'block':'none'?>">
                            <img id="imgPreviewEl"
                                 src="<?= e(!empty($blogActual['imagen']) ? getImageUrl($blogActual['imagen']) : '') ?>"
                                 alt="Preview"
                                 style="max-width:240px;max-height:130px;border-radius:var(--border-radius);
                                        object-fit:cover;border:1px solid var(--border-color)">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer del form -->
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:4px">
                <button type="submit" class="btn-p">
                    <i class="bi bi-<?= $accion === 'editar' ? 'check2-circle' : 'broadcast-pin' ?>"></i>
                    <?= $accion === 'editar' ? 'Guardar cambios' : 'Crear cobertura' ?>
                </button>
                <a href="<?= APP_URL ?>/admin/coberturasenvivo.php<?= $accion==='editar' && $blogActual ? '?accion=ver&id='.$blogActual['id'] : '' ?>"
                   class="btn-s">
                    Cancelar
                </a>
                <?php if ($accion === 'editar' && $blogActual): ?>
                <span style="font-size:.72rem;color:var(--text-muted);margin-left:auto">
                    ID: #<?= $blogActual['id'] ?> ·
                    Slug: <code style="background:var(--bg-surface-2);padding:1px 5px;border-radius:3px"><?= e($blogActual['slug']) ?></code>
                </span>
                <?php endif; ?>
            </div>

        </form>
    </div>


    <?php /* ============================================================
           VISTA: GESTIÓN DE UPDATES (minuto a minuto)
           ============================================================ */ ?>
    <?php elseif ($accion === 'ver' && $blogActual): ?>

    <!-- Header de la cobertura -->
    <div class="live-detail-header">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div style="flex:1;min-width:0">
                <!-- Badge estado -->
                <div style="margin-bottom:8px">
                    <?php if ($blogActual['estado'] === 'activo'): ?>
                    <span class="estado-badge estado-badge--activo" style="font-size:.72rem">
                        <span class="live-pulse"></span> EN VIVO AHORA
                    </span>
                    <?php elseif ($blogActual['estado'] === 'pausado'): ?>
                    <span class="estado-badge estado-badge--pausado" style="font-size:.72rem">
                        <i class="bi bi-pause-fill"></i> PAUSADO
                    </span>
                    <?php else: ?>
                    <span class="estado-badge estado-badge--finalizado" style="font-size:.72rem">
                        <i class="bi bi-stop-fill"></i> FINALIZADO
                    </span>
                    <?php endif; ?>
                    <?php if ($blogActual['cat_nombre']): ?>
                    <span style="margin-left:6px;padding:2px 8px;
                                  background:<?= e($blogActual['cat_color']) ?>25;
                                  color:<?= e($blogActual['cat_color']) ?>;
                                  border-radius:var(--border-radius-full);
                                  font-size:.68rem;font-weight:700">
                        <?= e($blogActual['cat_nombre']) ?>
                    </span>
                    <?php endif; ?>
                </div>

                <h2 class="live-detail-title"><?= e($blogActual['titulo']) ?></h2>

                <div class="live-detail-meta">
                    <span><i class="bi bi-person-fill"></i> <?= e($blogActual['autor_nombre']) ?></span>
                    <span><i class="bi bi-calendar3"></i> <?= formatDate($blogActual['fecha_inicio'], 'full') ?></span>
                    <?php if ($blogActual['fecha_fin']): ?>
                    <span><i class="bi bi-stop-circle"></i> Fin: <?= formatDate($blogActual['fecha_fin'], 'short') ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($blogActual['imagen'])): ?>
            <img src="<?= e(getImageUrl($blogActual['imagen'])) ?>"
                 alt="<?= e($blogActual['titulo']) ?>"
                 style="width:100px;height:70px;object-fit:cover;
                        border-radius:var(--border-radius);
                        border:2px solid rgba(255,255,255,.15);flex-shrink:0">
            <?php endif; ?>
        </div>

        <!-- Stats rápidas -->
        <div class="live-detail-stats">
            <div class="live-detail-stat">
                <span class="live-detail-stat__val" id="totalUpdatesCounter">
                    <?= number_format((int)$blogActual['total_updates']) ?>
                </span>
                <span class="live-detail-stat__lbl">Updates</span>
            </div>
            <div class="live-detail-stat">
                <span class="live-detail-stat__val">
                    <?= formatNumber((int)$blogActual['vistas']) ?>
                </span>
                <span class="live-detail-stat__lbl">Lectores</span>
            </div>
            <div class="live-detail-stat">
                <span class="live-detail-stat__val">
                    <?php
                    if ($blogActual['fecha_inicio'] && $blogActual['estado'] === 'activo') {
                        $diff = time() - strtotime($blogActual['fecha_inicio']);
                        $h = floor($diff / 3600);
                        $m = floor(($diff % 3600) / 60);
                        echo $h > 0 ? "{$h}h {$m}m" : "{$m}m";
                    } elseif ($blogActual['fecha_fin']) {
                        $diff = strtotime($blogActual['fecha_fin']) - strtotime($blogActual['fecha_inicio']);
                        $h = floor($diff / 3600);
                        $m = floor(($diff % 3600) / 60);
                        echo $h > 0 ? "{$h}h {$m}m" : "{$m}m";
                    } else {
                        echo '—';
                    }
                    ?>
                </span>
                <span class="live-detail-stat__lbl">Duración</span>
            </div>
            <div class="live-detail-stat">
                <span class="live-detail-stat__val">
                    <?= $totalUpdPag ?>
                </span>
                <span class="live-detail-stat__lbl">En esta pág.</span>
            </div>
        </div>
    </div><!-- /.live-detail-header -->

    <!-- Acciones de estado y extras -->
    <div class="live-actions-bar">
        <?php if (can('live_blog', 'editar')): ?>
        <!-- Cambiar estado rápido -->
        <?php if ($blogActual['estado'] !== 'activo'): ?>
        <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="post_accion" value="cambiar_estado">
            <input type="hidden" name="blog_id"     value="<?= $blogActual['id'] ?>">
            <input type="hidden" name="estado"      value="activo">
            <button type="submit" class="btn-success">
                <span class="live-pulse" style="background:currentColor"></span>
                Activar (En Vivo)
            </button>
        </form>
        <?php endif; ?>

        <?php if ($blogActual['estado'] === 'activo'): ?>
        <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="post_accion" value="cambiar_estado">
            <input type="hidden" name="blog_id"     value="<?= $blogActual['id'] ?>">
            <input type="hidden" name="estado"      value="pausado">
            <button type="submit" class="btn-warning">
                <i class="bi bi-pause-fill"></i> Pausar
            </button>
        </form>
        <?php endif; ?>

        <?php if ($blogActual['estado'] !== 'finalizado'): ?>
        <button onclick="confirmarFinalizar(<?= $blogActual['id'] ?>)" class="btn-danger">
            <i class="bi bi-stop-fill"></i> Finalizar cobertura
        </button>
        <?php endif; ?>
        <?php endif; // can editar ?>

        <div style="margin-left:auto;display:flex;gap:6px;align-items:center">
            <?php if ($blogActual['estado'] === 'activo'): ?>
            <span id="autoRefreshBadge"
                  style="display:inline-flex;align-items:center;gap:5px;
                         padding:4px 10px;border-radius:var(--border-radius-full);
                         background:rgba(34,197,94,.12);color:var(--success);
                         font-size:.7rem;font-weight:700">
                <span class="live-pulse"></span>
                Auto-refresh: <span id="refreshCountdown">30</span>s
            </span>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/live.php?slug=<?= e($blogActual['slug']) ?>"
               target="_blank" class="btn-s btn-sm">
                <i class="bi bi-eye-fill"></i> Ver público
            </a>
        </div>
    </div><!-- /.live-actions-bar -->

    <div style="display:grid;grid-template-columns:1fr 360px;gap:20px;align-items:start">

    <!-- COLUMNA IZQUIERDA: Publicar + Timeline -->
    <div>

        <!-- Panel publicar update -->
        <?php if (can('live_blog', 'editar') && $blogActual['estado'] !== 'finalizado'): ?>
        <div class="publish-panel">
            <div class="publish-panel__title">
                <i class="bi bi-lightning-charge-fill"></i>
                Publicar nuevo update
            </div>

            <form method="POST" id="publishForm">
                <?= csrfField() ?>
                <input type="hidden" name="post_accion" value="publicar_update">
                <input type="hidden" name="blog_id"     value="<?= $blogActual['id'] ?>">
                <input type="hidden" name="tipo" id="tipoInput" value="texto">

                <!-- Selector de tipo -->
                <div class="tipo-selector">
                    <button type="button" class="tipo-btn selected" data-tipo="texto"
                            onclick="setTipo('texto',this)">
                        <i class="bi bi-text-left"></i> Texto
                    </button>
                    <button type="button" class="tipo-btn" data-tipo="breaking"
                            onclick="setTipo('breaking',this)">
                        <i class="bi bi-exclamation-circle-fill"></i> Breaking
                    </button>
                    <button type="button" class="tipo-btn" data-tipo="alerta"
                            onclick="setTipo('alerta',this)">
                        <i class="bi bi-shield-exclamation"></i> Alerta
                    </button>
                    <button type="button" class="tipo-btn" data-tipo="imagen"
                            onclick="setTipo('imagen',this)">
                        <i class="bi bi-image-fill"></i> Imagen
                    </button>
                    <button type="button" class="tipo-btn" data-tipo="video"
                            onclick="setTipo('video',this)">
                        <i class="bi bi-play-circle-fill"></i> Video
                    </button>
                    <button type="button" class="tipo-btn" data-tipo="cita"
                            onclick="setTipo('cita',this)">
                        <i class="bi bi-chat-quote-fill"></i> Cita
                    </button>
                </div>

                <!-- Textarea principal -->
                <textarea name="contenido" id="updateContenido"
                          class="update-textarea"
                          placeholder="Escribe el update aquí... (para citas usa: «texto»)"
                          required maxlength="3000"></textarea>

                <!-- Campos extra (imagen / video) -->
                <div id="extraImagen" style="display:none;margin-top:10px">
                    <input type="text" name="imagen" class="form-input"
                           placeholder="URL de la imagen para este update..."
                           style="font-size:.82rem">
                </div>
                <div id="extraVideo" style="display:none;margin-top:10px">
                    <input type="text" name="video_url" class="form-input"
                           placeholder="URL del video (YouTube, MP4, Vimeo)..."
                           style="font-size:.82rem">
                </div>

                <div class="publish-panel__footer">
                    <label style="display:flex;align-items:center;gap:7px;
                                   cursor:pointer;font-size:.78rem;color:var(--text-secondary)">
                        <input type="checkbox" name="es_destacado" id="chkDestacado"
                               style="width:16px;height:16px;accent-color:var(--primary)">
                        <i class="bi bi-star-fill" style="color:var(--warning)"></i>
                        Marcar como destacado
                    </label>
                    <div style="display:flex;gap:8px;align-items:center">
                        <span id="charCount" style="font-size:.72rem;color:var(--text-muted)">
                            0 / 3000
                        </span>
                        <button type="submit" class="btn-p" id="publishBtn">
                            <i class="bi bi-send-fill"></i>
                            Publicar update
                        </button>
                    </div>
                </div>
            </form>
        </div><!-- /.publish-panel -->
        <?php endif; ?>

        <!-- Timeline de updates -->
        <div style="background:var(--bg-surface);border:1px solid var(--border-color);
                    border-radius:var(--border-radius-xl);overflow:hidden;
                    box-shadow:var(--shadow-sm)">

            <!-- Header del timeline -->
            <div style="padding:14px 16px;border-bottom:1px solid var(--border-color);
                        background:var(--bg-surface-2);
                        display:flex;align-items:center;justify-content:space-between;gap:10px">
                <span style="font-size:.82rem;font-weight:700;color:var(--text-primary);
                              display:flex;align-items:center;gap:7px">
                    <i class="bi bi-clock-history" style="color:var(--primary)"></i>
                    Minuto a Minuto
                    <span style="background:var(--bg-surface-3);color:var(--text-muted);
                                  padding:1px 7px;border-radius:var(--border-radius-full);
                                  font-size:.68rem">
                        <?= $totalUpdPag ?> updates
                    </span>
                </span>
                <div style="display:flex;gap:6px">
                    <button onclick="recargarUpdates()" class="btn-s btn-sm" title="Recargar">
                        <i class="bi bi-arrow-clockwise" id="reloadIcon"></i>
                    </button>
                </div>
            </div>

            <!-- Lista de updates -->
            <div style="padding:4px 16px 8px" id="updatesContainer">
            <?php if (empty($updates)): ?>
            <div style="text-align:center;padding:40px 20px;color:var(--text-muted)">
                <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:10px;opacity:.4"></i>
                <p style="font-size:.85rem">
                    <?= $blogActual['estado'] === 'finalizado'
                        ? 'Esta cobertura finalizó sin updates registrados.'
                        : 'Aún no hay updates. ¡Publica el primero!' ?>
                </p>
            </div>
            <?php else: ?>
            <div class="updates-timeline" id="updatesList">
            <?php foreach ($updates as $upd): ?>

            <?php
            // Íconos y colores por tipo
            $tipoIcon = match($upd['tipo']) {
                'breaking' => 'exclamation-circle-fill',
                'alerta'   => 'shield-exclamation',
                'imagen'   => 'image-fill',
                'video'    => 'play-circle-fill',
                'cita'     => 'chat-quote-fill',
                default    => 'text-left',
            };
            $tipoBgClass = 'update-tipo-' . $upd['tipo'];
            $tipoLabel = match($upd['tipo']) {
                'breaking' => 'BREAKING',
                'alerta'   => 'ALERTA',
                'imagen'   => 'IMAGEN',
                'video'    => 'VIDEO',
                'cita'     => 'CITA',
                default    => 'UPDATE',
            };
            $tipoBadgeBg = match($upd['tipo']) {
                'breaking' => 'rgba(230,57,70,.15)',
                'alerta'   => 'rgba(245,158,11,.15)',
                'imagen'   => 'rgba(139,92,246,.15)',
                'video'    => 'rgba(239,68,68,.15)',
                'cita'     => 'rgba(34,197,94,.15)',
                default    => 'rgba(59,130,246,.12)',
            };
            $tipoBadgeColor = match($upd['tipo']) {
                'breaking' => 'var(--primary)',
                'alerta'   => 'var(--warning)',
                'imagen'   => '#8b5cf6',
                'video'    => 'var(--danger)',
                'cita'     => 'var(--success)',
                default    => 'var(--info)',
            };
            ?>

            <div class="update-item <?= $upd['es_destacado'] ? 'update-item--destacado' : '' ?>"
                 id="update-<?= $upd['id'] ?>">

                <!-- Dot del tipo -->
                <div class="update-timeline-dot <?= $tipoBgClass ?>">
                    <i class="bi bi-<?= $tipoIcon ?>"></i>
                </div>

                <div class="update-body">
                    <!-- Header del update -->
                    <div class="update-header">
                        <div class="update-meta">
                            <span class="update-tipo-badge"
                                  style="background:<?= $tipoBadgeBg ?>;color:<?= $tipoBadgeColor ?>">
                                <?= $tipoLabel ?>
                            </span>
                            <?php if ($upd['es_destacado']): ?>
                            <span class="destacado-tag">
                                <i class="bi bi-star-fill"></i> Destacado
                            </span>
                            <?php endif; ?>
                            <span class="update-tiempo" title="<?= e($upd['fecha']) ?>">
                                <i class="bi bi-clock"></i>
                                <?= timeAgo($upd['fecha']) ?>
                                &nbsp;·&nbsp;
                                <?= date('H:i', strtotime($upd['fecha'])) ?>
                            </span>
                        </div>

                        <!-- Acciones (visibles en hover) -->
                        <?php if (can('live_blog', 'editar') || can('live_blog', 'eliminar')): ?>
                        <div class="update-actions">
                            <?php if (can('live_blog', 'editar')): ?>
                            <button onclick="abrirEditarUpdate(
                                        <?= $upd['id'] ?>,
                                        '<?= e(addslashes($upd['contenido'])) ?>',
                                        '<?= e($upd['tipo']) ?>',
                                        <?= $upd['es_destacado'] ? 'true' : 'false' ?>
                                    )"
                                    class="btn-s btn-sm" title="Editar update">
                                <i class="bi bi-pencil-fill"></i>
                            </button>
                            <?php endif; ?>
                            <?php if (can('live_blog', 'eliminar')): ?>
                            <button onclick="confirmarEliminarUpdate(<?= $upd['id'] ?>, <?= $blogActual['id'] ?>)"
                                    class="btn-danger btn-sm" title="Eliminar update">
                                <i class="bi bi-trash3-fill"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Contenido del update -->
                    <div class="update-contenido">
                        <?php if ($upd['tipo'] === 'cita'): ?>
                        <blockquote>
                            <?= nl2br(e($upd['contenido'])) ?>
                        </blockquote>
                        <?php else: ?>
                        <?= nl2br(e($upd['contenido'])) ?>
                        <?php endif; ?>
                    </div>

                    <!-- Imagen adjunta al update -->
                    <?php if (!empty($upd['imagen'])): ?>
                    <div style="margin-top:10px">
                        <img src="<?= e(getImageUrl($upd['imagen'])) ?>"
                             alt="Imagen del update"
                             style="max-width:100%;max-height:220px;
                                    object-fit:cover;border-radius:var(--border-radius);
                                    border:1px solid var(--border-color)">
                    </div>
                    <?php endif; ?>

                    <!-- Video adjunto -->
                    <?php if (!empty($upd['video_url'])): ?>
                    <div style="margin-top:10px">
                        <?php if (str_contains($upd['video_url'], 'youtube.com') || str_contains($upd['video_url'], 'youtu.be')): ?>
                        <div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:var(--border-radius)">
                            <iframe src="https://www.youtube.com/embed/<?= extractYoutubeId($upd['video_url']) ?>"
                                    style="position:absolute;top:0;left:0;width:100%;height:100%;border:none"
                                    loading="lazy" allowfullscreen></iframe>
                        </div>
                        <?php else: ?>
                        <a href="<?= e($upd['video_url']) ?>" target="_blank"
                           class="btn-s btn-sm">
                            <i class="bi bi-play-circle-fill"></i>
                            Ver video
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Autor del update -->
                    <div class="update-autor">
                        <img src="<?= e(getImageUrl($upd['autor_avatar'] ?? '', 'avatar')) ?>"
                             alt="<?= e($upd['autor_nombre']) ?>">
                        <span>Por <strong><?= e($upd['autor_nombre']) ?></strong></span>
                    </div>
                </div><!-- /.update-body -->
            </div><!-- /.update-item -->

            <?php endforeach; ?>
            </div><!-- /.updates-timeline -->
            <?php endif; // empty updates ?>
            </div><!-- /#updatesContainer -->

            <!-- Paginación de updates -->
            <?php
            $totalPagsUpd = (int)ceil($totalUpdPag / $porPagUpd);
            if ($totalPagsUpd > 1):
            ?>
            <div class="pag-wrap" style="border-top:1px solid var(--border-color)">
                <?php for ($p = max(1,$pagUpd-2); $p <= min($totalPagsUpd,$pagUpd+2); $p++): ?>
                <a href="<?= APP_URL ?>/admin/coberturasenvivo.php?accion=ver&id=<?= $blogActual['id'] ?>&pag_upd=<?= $p ?>"
                   class="pag-btn <?= $p===$pagUpd?'active':''?>"><?= $p ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>

        </div><!-- /timeline card -->

    </div><!-- /.col-izquierda -->

    <!-- COLUMNA DERECHA: Info + Acciones rápidas -->
    <div style="display:flex;flex-direction:column;gap:16px">

        <!-- Info de la cobertura -->
        <div style="background:var(--bg-surface);border:1px solid var(--border-color);
                    border-radius:var(--border-radius-xl);overflow:hidden;
                    box-shadow:var(--shadow-sm)">
            <div style="padding:12px 16px;border-bottom:1px solid var(--border-color);
                        background:var(--bg-surface-2)">
                <span style="font-size:.78rem;font-weight:700;color:var(--text-primary);
                              display:flex;align-items:center;gap:7px">
                    <i class="bi bi-info-circle-fill" style="color:var(--info)"></i>
                    Información
                </span>
            </div>
            <div style="padding:16px;display:flex;flex-direction:column;gap:10px">
                <div style="display:flex;align-items:flex-start;gap:8px;font-size:.78rem">
                    <i class="bi bi-hash" style="color:var(--text-muted);margin-top:1px;flex-shrink:0"></i>
                    <div>
                        <span style="color:var(--text-muted)">ID</span><br>
                        <strong>#<?= $blogActual['id'] ?></strong>
                    </div>
                </div>
                <div style="display:flex;align-items:flex-start;gap:8px;font-size:.78rem">
                    <i class="bi bi-link-45deg" style="color:var(--text-muted);margin-top:1px;flex-shrink:0"></i>
                    <div style="min-width:0">
                        <span style="color:var(--text-muted)">Slug</span><br>
                        <code style="font-size:.72rem;background:var(--bg-surface-2);
                                     padding:2px 6px;border-radius:3px;word-break:break-all">
                            <?= e($blogActual['slug']) ?>
                        </code>
                    </div>
                </div>
                <div style="display:flex;align-items:flex-start;gap:8px;font-size:.78rem">
                    <i class="bi bi-person-fill" style="color:var(--text-muted);margin-top:1px;flex-shrink:0"></i>
                    <div>
                        <span style="color:var(--text-muted)">Autor</span><br>
                        <strong><?= e($blogActual['autor_nombre']) ?></strong>
                    </div>
                </div>
                <?php if (!empty($blogActual['descripcion'])): ?>
                <div style="display:flex;align-items:flex-start;gap:8px;font-size:.78rem">
                    <i class="bi bi-card-text" style="color:var(--text-muted);margin-top:1px;flex-shrink:0"></i>
                    <div>
                        <span style="color:var(--text-muted)">Descripción</span><br>
                        <span style="color:var(--text-secondary)">
                            <?= e(truncateChars($blogActual['descripcion'], 100)) ?>
                        </span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div><!-- /.info card -->

        <!-- Acciones rápidas de estado -->
        <?php if (can('live_blog', 'editar')): ?>
        <div style="background:var(--bg-surface);border:1px solid var(--border-color);
                    border-radius:var(--border-radius-xl);overflow:hidden;
                    box-shadow:var(--shadow-sm)">
            <div style="padding:12px 16px;border-bottom:1px solid var(--border-color);
                        background:var(--bg-surface-2)">
                <span style="font-size:.78rem;font-weight:700;color:var(--text-primary);
                              display:flex;align-items:center;gap:7px">
                    <i class="bi bi-toggles" style="color:var(--primary)"></i>
                    Cambiar Estado
                </span>
            </div>
            <div style="padding:14px;display:flex;flex-direction:column;gap:8px">

                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="post_accion" value="cambiar_estado">
                    <input type="hidden" name="blog_id"     value="<?= $blogActual['id'] ?>">
                    <input type="hidden" name="estado"      value="activo">
                    <button type="submit"
                            class="btn-success"
                            style="width:100%;justify-content:center"
                            <?= $blogActual['estado']==='activo'?'disabled':''?>>
                        <span class="live-pulse" style="background:currentColor"></span>
                        Poner EN VIVO
                    </button>
                </form>

                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="post_accion" value="cambiar_estado">
                    <input type="hidden" name="blog_id"     value="<?= $blogActual['id'] ?>">
                    <input type="hidden" name="estado"      value="pausado">
                    <button type="submit"
                            class="btn-warning"
                            style="width:100%;justify-content:center"
                            <?= $blogActual['estado']==='pausado'?'disabled':''?>>
                        <i class="bi bi-pause-fill"></i> Pausar cobertura
                    </button>
                </form>

                <button onclick="confirmarFinalizar(<?= $blogActual['id'] ?>)"
                        class="btn-danger"
                        style="width:100%;justify-content:center"
                        <?= $blogActual['estado']==='finalizado'?'disabled':''?>>
                    <i class="bi bi-stop-fill"></i> Finalizar cobertura
                </button>

            </div>
        </div><!-- /.estado card -->
        <?php endif; ?>

        <!-- Tipos de update (leyenda) -->
        <div style="background:var(--bg-surface);border:1px solid var(--border-color);
                    border-radius:var(--border-radius-xl);overflow:hidden;
                    box-shadow:var(--shadow-sm)">
            <div style="padding:12px 16px;border-bottom:1px solid var(--border-color);
                        background:var(--bg-surface-2)">
                <span style="font-size:.78rem;font-weight:700;color:var(--text-primary);
                              display:flex;align-items:center;gap:7px">
                    <i class="bi bi-palette-fill" style="color:#8b5cf6"></i>
                    Tipos de Update
                </span>
            </div>
            <div style="padding:14px;display:flex;flex-direction:column;gap:8px;font-size:.75rem">
                <?php
                $tipos = [
                    ['tipo'=>'texto',    'icon'=>'text-left',             'color'=>'var(--info)',    'bg'=>'rgba(59,130,246,.1)',   'desc'=>'Actualización normal de texto'],
                    ['tipo'=>'breaking', 'icon'=>'exclamation-circle-fill','color'=>'var(--primary)','bg'=>'rgba(230,57,70,.1)',   'desc'=>'Noticia de último momento'],
                    ['tipo'=>'alerta',   'icon'=>'shield-exclamation',    'color'=>'var(--warning)', 'bg'=>'rgba(245,158,11,.1)',  'desc'=>'Aviso importante'],
                    ['tipo'=>'imagen',   'icon'=>'image-fill',            'color'=>'#8b5cf6',        'bg'=>'rgba(139,92,246,.1)',  'desc'=>'Foto o imagen del evento'],
                    ['tipo'=>'video',    'icon'=>'play-circle-fill',      'color'=>'var(--danger)',  'bg'=>'rgba(239,68,68,.1)',   'desc'=>'Clip o video embebido'],
                    ['tipo'=>'cita',     'icon'=>'chat-quote-fill',       'color'=>'var(--success)', 'bg'=>'rgba(34,197,94,.1)',   'desc'=>'Cita textual de una fuente'],
                ];
                foreach ($tipos as $t):
                ?>
                <div style="display:flex;align-items:center;gap:8px">
                    <div style="width:28px;height:28px;border-radius:50%;
                                 background:<?= $t['bg'] ?>;color:<?= $t['color'] ?>;
                                 display:flex;align-items:center;justify-content:center;
                                 font-size:.75rem;flex-shrink:0">
                        <i class="bi bi-<?= $t['icon'] ?>"></i>
                    </div>
                    <div>
                        <strong style="color:var(--text-primary);text-transform:uppercase;
                                        font-size:.65rem;letter-spacing:.05em">
                            <?= $t['tipo'] ?>
                        </strong><br>
                        <span style="color:var(--text-muted)"><?= $t['desc'] ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div><!-- /.leyenda card -->

        <!-- Estadísticas de tipos -->
        <?php
        $statsTipos = db()->fetchAll(
            "SELECT tipo, COUNT(*) AS total, SUM(es_destacado) AS destacados
             FROM live_blog_updates
             WHERE blog_id = ?
             GROUP BY tipo ORDER BY total DESC",
            [$blogActual['id']]
        );
        if (!empty($statsTipos)):
        ?>
        <div style="background:var(--bg-surface);border:1px solid var(--border-color);
                    border-radius:var(--border-radius-xl);overflow:hidden;
                    box-shadow:var(--shadow-sm)">
            <div style="padding:12px 16px;border-bottom:1px solid var(--border-color);
                        background:var(--bg-surface-2)">
                <span style="font-size:.78rem;font-weight:700;color:var(--text-primary);
                              display:flex;align-items:center;gap:7px">
                    <i class="bi bi-bar-chart-fill" style="color:var(--success)"></i>
                    Distribución de Updates
                </span>
            </div>
            <div style="padding:14px;display:flex;flex-direction:column;gap:8px">
            <?php foreach ($statsTipos as $st):
                $pct = $totalUpdPag > 0 ? round(($st['total'] / $blogActual['total_updates']) * 100) : 0;
                $bgColor = match($st['tipo']) {
                    'breaking'=>'var(--primary)','alerta'=>'var(--warning)',
                    'imagen'=>'#8b5cf6','video'=>'var(--danger)',
                    'cita'=>'var(--success)', default=>'var(--info)'
                };
            ?>
            <div>
                <div style="display:flex;justify-content:space-between;
                             align-items:center;margin-bottom:3px">
                    <span style="font-size:.72rem;font-weight:600;
                                  color:var(--text-secondary);text-transform:uppercase">
                        <?= e($st['tipo']) ?>
                    </span>
                    <span style="font-size:.72rem;color:var(--text-muted)">
                        <?= $st['total'] ?> (<?= $pct ?>%)
                    </span>
                </div>
                <div style="height:6px;background:var(--bg-surface-3);
                             border-radius:var(--border-radius-full);overflow:hidden">
                    <div style="height:100%;width:<?= $pct ?>%;background:<?= $bgColor ?>;
                                 border-radius:var(--border-radius-full);
                                 transition:width .5s ease"></div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div><!-- /.stats tipos -->
        <?php endif; ?>

        <!-- Acciones peligrosas -->
        <?php if (can('live_blog', 'eliminar')): ?>
        <div style="background:rgba(239,68,68,.04);border:1px solid rgba(239,68,68,.2);
                    border-radius:var(--border-radius-xl);padding:14px">
            <p style="font-size:.72rem;font-weight:700;color:var(--danger);
                       text-transform:uppercase;margin-bottom:8px">
                <i class="bi bi-exclamation-triangle-fill"></i> Zona peligrosa
            </p>
            <button onclick="confirmarEliminarBlog(<?= $blogActual['id'] ?>, '<?= e(addslashes($blogActual['titulo'])) ?>')"
                    class="btn-danger" style="width:100%;justify-content:center;font-size:.78rem">
                <i class="bi bi-trash3-fill"></i>
                Eliminar esta cobertura y todos sus updates
            </button>
        </div>
        <?php endif; ?>

    </div><!-- /.col-derecha -->
    </div><!-- /.grid 2col -->

    <?php endif; // accion ver ?>

    </div><!-- /.admin-content -->
</main><!-- /.admin-main -->
</div><!-- /.admin-wrapper -->

<!-- ================================================================
     MODAL: EDITAR UPDATE
================================================================ -->
<div class="modal-bg" id="modalEditarUpdate">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="bi bi-pencil-fill" style="color:var(--primary)"></i> Editar Update</h3>
            <button class="modal-close" onclick="cerrarModal('modalEditarUpdate')">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="post_accion" value="editar_update">
            <input type="hidden" name="blog_id"   value="<?= $blogActual['id'] ?? 0 ?>">
            <input type="hidden" name="update_id" id="editUpdateId" value="">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Tipo de update</label>
                    <select name="tipo" id="editUpdateTipo" class="form-select">
                        <option value="texto">Texto</option>
                        <option value="breaking">Breaking</option>
                        <option value="alerta">Alerta</option>
                        <option value="imagen">Imagen</option>
                        <option value="video">Video</option>
                        <option value="cita">Cita</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Contenido <span class="req">*</span></label>
                    <textarea name="contenido" id="editUpdateContenido"
                              class="form-textarea" rows="5"
                              maxlength="3000" required></textarea>
                </div>
                <div class="form-group" style="margin-bottom:0">
                    <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:.82rem">
                        <input type="checkbox" name="es_destacado" id="editUpdateDestacado"
                               style="width:16px;height:16px;accent-color:var(--primary)">
                        <i class="bi bi-star-fill" style="color:var(--warning)"></i>
                        Marcar como destacado
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-s" onclick="cerrarModal('modalEditarUpdate')">
                    Cancelar
                </button>
                <button type="submit" class="btn-p">
                    <i class="bi bi-check2"></i> Guardar cambios
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ================================================================
     MODAL: CONFIRMAR ELIMINAR UPDATE
================================================================ -->
<div class="modal-bg" id="modalElimUpdate">
    <div class="modal-box">
        <div class="modal-header">
            <h3 style="color:var(--danger)">
                <i class="bi bi-exclamation-triangle-fill"></i>
                Eliminar Update
            </h3>
            <button class="modal-close" onclick="cerrarModal('modalElimUpdate')">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="modal-body">
            <p style="font-size:.88rem;color:var(--text-secondary)">
                ¿Estás seguro de que deseas eliminar este update? Esta acción no se puede deshacer.
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-s" onclick="cerrarModal('modalElimUpdate')">Cancelar</button>
            <form method="POST" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="post_accion" value="eliminar_update">
                <input type="hidden" name="blog_id"     id="elimUpdateBlogId"  value="">
                <input type="hidden" name="update_id"   id="elimUpdateId"      value="">
                <button type="submit" class="btn-danger">
                    <i class="bi bi-trash3-fill"></i> Eliminar
                </button>
            </form>
        </div>
    </div>
</div>

<!-- ================================================================
     MODAL: CONFIRMAR FINALIZAR COBERTURA
================================================================ -->
<div class="modal-bg" id="modalFinalizar">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="bi bi-stop-circle-fill" style="color:var(--warning)"></i> Finalizar Cobertura</h3>
            <button class="modal-close" onclick="cerrarModal('modalFinalizar')">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="modal-body">
            <p style="font-size:.88rem;color:var(--text-secondary)">
                Al finalizar la cobertura se registrará la fecha/hora de cierre y no podrá
                publicarse más updates (aunque podrá reactivarse si es necesario).
                <br><br>
                <strong>¿Deseas finalizar la cobertura ahora?</strong>
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-s" onclick="cerrarModal('modalFinalizar')">Cancelar</button>
            <form method="POST" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="post_accion" value="cambiar_estado">
                <input type="hidden" name="blog_id"     id="finalizarBlogId" value="">
                <input type="hidden" name="estado"      value="finalizado">
                <button type="submit" class="btn-warning">
                    <i class="bi bi-stop-fill"></i> Sí, finalizar
                </button>
            </form>
        </div>
    </div>
</div>

<!-- ================================================================
     MODAL: CONFIRMAR ELIMINAR BLOG COMPLETO
================================================================ -->
<div class="modal-bg" id="modalElimBlog">
    <div class="modal-box">
        <div class="modal-header">
            <h3 style="color:var(--danger)">
                <i class="bi bi-exclamation-octagon-fill"></i>
                Eliminar Cobertura
            </h3>
            <button class="modal-close" onclick="cerrarModal('modalElimBlog')">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="modal-body">
            <p style="font-size:.88rem;color:var(--text-secondary);margin-bottom:12px">
                Vas a eliminar <strong id="elimBlogTitulo"></strong>.
                <br><br>
                Esto eliminará <strong>permanentemente</strong> la cobertura y todos sus updates.
                Esta acción no se puede deshacer.
            </p>
            <div style="padding:12px;background:rgba(239,68,68,.08);
                         border-radius:var(--border-radius);border:1px solid rgba(239,68,68,.2);
                         font-size:.8rem;color:var(--danger)">
                <i class="bi bi-exclamation-circle-fill"></i>
                Escribe <strong>ELIMINAR</strong> para confirmar:
                <input type="text" id="confirmElimText"
                       style="margin-top:8px;width:100%;padding:8px;border:1px solid rgba(239,68,68,.3);
                              border-radius:var(--border-radius-sm);background:rgba(239,68,68,.05);
                              color:var(--danger);font-weight:700;font-size:.85rem"
                       placeholder="ELIMINAR">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-s" onclick="cerrarModal('modalElimBlog')">Cancelar</button>
            <form method="POST" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="post_accion" value="eliminar_blog">
                <input type="hidden" name="blog_id"     id="elimBlogId" value="">
                <button type="submit" id="btnElimBlogConfirm"
                        class="btn-danger" disabled>
                    <i class="bi bi-trash3-fill"></i> Eliminar definitivamente
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Toast container -->
<div id="toast-container"></div>

<!-- ================================================================
     JAVASCRIPT
================================================================ -->
<script>
(function() {
'use strict';

// ── Sidebar (móvil) ─────────────────────────────────────────
function toggleSidebar() {
    const sb  = document.getElementById('adminSidebar');
    const ov  = document.getElementById('adminOverlay');
    const open = sb?.classList.toggle('open');
    if (ov) ov.style.display = open ? 'block' : 'none';
    if (sb) sb.setAttribute('aria-hidden', open ? 'false' : 'true');
}
function closeSidebar() {
    const sb = document.getElementById('adminSidebar');
    const ov = document.getElementById('adminOverlay');
    sb?.classList.remove('open');
    if (ov) ov.style.display = 'none';
}
window.toggleSidebar = toggleSidebar;
window.closeSidebar  = closeSidebar;

// ── Modales ──────────────────────────────────────────────────
function abrirModal(id) {
    const m = document.getElementById(id);
    if (m) {
        m.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
}
function cerrarModal(id) {
    const m = document.getElementById(id);
    if (m) {
        m.classList.remove('open');
        document.body.style.overflow = '';
    }
}
window.cerrarModal = cerrarModal;

// Cerrar modal al click fuera
document.addEventListener('click', (e) => {
    document.querySelectorAll('.modal-bg.open').forEach(m => {
        if (e.target === m) cerrarModal(m.id);
    });
});

// ── Toast ───────────────────────────────────────────────────
function showToast(msg, type = 'success', dur = 4000) {
    const icons = { success:'check-circle-fill', error:'exclamation-circle-fill', info:'info-circle-fill' };
    const tc = document.getElementById('toast-container');
    if (!tc) return;
    const t = document.createElement('div');
    t.className = `toast toast--${type}`;
    t.innerHTML = `<i class="bi bi-${icons[type]||icons.info}"></i><span style="flex:1">${msg}</span>
                   <button onclick="this.parentElement.remove()" style="color:var(--text-muted)">&times;</button>`;
    tc.appendChild(t);
    setTimeout(() => { t.style.opacity='0'; t.style.transform='translateX(20px)'; t.style.transition='all .3s'; setTimeout(()=>t.remove(),300); }, dur);
}
window.showToast = showToast;

// ── Selector de tipo de update ────────────────────────────
window.setTipo = function(tipo, btn) {
    document.querySelectorAll('.tipo-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    document.getElementById('tipoInput').value = tipo;

    // Mostrar/ocultar campos extra
    const extraImg = document.getElementById('extraImagen');
    const extraVid = document.getElementById('extraVideo');
    if (extraImg) extraImg.style.display = tipo === 'imagen' ? 'block' : 'none';
    if (extraVid) extraVid.style.display = tipo === 'video'  ? 'block' : 'none';

    // Ajustar placeholder del textarea
    const ta = document.getElementById('updateContenido');
    if (ta) {
        const placeholders = {
            texto:    'Escribe el update aquí...',
            breaking: '🔴 BREAKING: Escribe la noticia de último momento...',
            alerta:   '⚠️ ALERTA: Describe la situación de alerta...',
            imagen:   'Describe la imagen que estás subiendo...',
            video:    'Describe el video o contexto del clip...',
            cita:     'Escribe la cita textual aquí... (ej: «Estamos comprometidos con...» — Nombre Fuente)',
        };
        ta.placeholder = placeholders[tipo] || 'Escribe el update aquí...';
    }
};

// ── Contador de caracteres ────────────────────────────────
const ta = document.getElementById('updateContenido');
const cc = document.getElementById('charCount');
if (ta && cc) {
    ta.addEventListener('input', () => {
        const len = ta.value.length;
        cc.textContent = `${len} / 3000`;
        cc.style.color = len > 2800 ? 'var(--danger)' : len > 2500 ? 'var(--warning)' : 'var(--text-muted)';
    });
}

// ── Confirmar eliminar update ─────────────────────────────
window.confirmarEliminarUpdate = function(updateId, blogId) {
    document.getElementById('elimUpdateId').value    = updateId;
    document.getElementById('elimUpdateBlogId').value = blogId;
    abrirModal('modalElimUpdate');
};

// ── Abrir modal editar update ─────────────────────────────
window.abrirEditarUpdate = function(id, contenido, tipo, destacado) {
    document.getElementById('editUpdateId').value        = id;
    document.getElementById('editUpdateContenido').value = contenido;
    document.getElementById('editUpdateTipo').value      = tipo;
    document.getElementById('editUpdateDestacado').checked = destacado;
    abrirModal('modalEditarUpdate');
};

// ── Confirmar finalizar cobertura ─────────────────────────
window.confirmarFinalizar = function(blogId) {
    document.getElementById('finalizarBlogId').value = blogId;
    abrirModal('modalFinalizar');
};

// ── Confirmar eliminar blog completo ──────────────────────
window.confirmarEliminarBlog = function(blogId, titulo) {
    document.getElementById('elimBlogId').value   = blogId;
    document.getElementById('elimBlogTitulo').textContent = `"${titulo}"`;
    document.getElementById('confirmElimText').value = '';
    document.getElementById('btnElimBlogConfirm').disabled = true;
    abrirModal('modalElimBlog');
};
const confirmInput = document.getElementById('confirmElimText');
const confirmBtn   = document.getElementById('btnElimBlogConfirm');
if (confirmInput && confirmBtn) {
    confirmInput.addEventListener('input', () => {
        confirmBtn.disabled = confirmInput.value.trim() !== 'ELIMINAR';
    });
}

// ── Vista previa de imagen ────────────────────────────────
const imgInput = document.getElementById('imgInput');
if (imgInput) {
    imgInput.addEventListener('input', function() {
        const prev   = document.getElementById('imgPreview');
        const prevEl = document.getElementById('imgPreviewEl');
        const val    = this.value.trim();
        if (val && prev && prevEl) {
            const url = val.startsWith('http') ? val : `<?= APP_URL ?>/` + val;
            prevEl.src = url;
            prev.style.display = 'block';
        } else if (prev) {
            prev.style.display = 'none';
        }
    });
}

// ── Auto-refresh (solo si está activo) ───────────────────
<?php if ($accion === 'ver' && ($blogActual['estado'] ?? '') === 'activo'): ?>
let refreshInterval = 30;
let countdown = refreshInterval;
let autoRefreshTimer;
let lastUpdateId = <?= !empty($updates) ? (int)$updates[0]['id'] : 0 ?>;

function startCountdown() {
    const el = document.getElementById('refreshCountdown');
    clearInterval(autoRefreshTimer);
    countdown = refreshInterval;
    autoRefreshTimer = setInterval(() => {
        countdown--;
        if (el) el.textContent = countdown;
        if (countdown <= 0) {
            countdown = refreshInterval;
            recargarUpdates();
        }
    }, 1000);
}

window.recargarUpdates = async function() {
    const icon = document.getElementById('reloadIcon');
    if (icon) icon.style.animation = 'spin 1s linear infinite';

    try {
        const resp = await fetch('<?= APP_URL ?>/ajax/handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action:        'get_live_updates',
                blog_id:       <?= $blogActual['id'] ?>,
                despues_de_id: 0,
            }),
        });
        const data = await resp.json();
        if (data.success) {
            // Actualizar contador total
            const ctr = document.getElementById('totalUpdatesCounter');
            if (ctr && data.total_updates) {
                ctr.textContent = data.total_updates.toLocaleString();
            }
            // Si hay nuevos updates, recargar la página para mostrarlos
            if (data.updates && data.updates.length > 0) {
                const maxId = Math.max(...data.updates.map(u => u.id));
                if (maxId > lastUpdateId) {
                    lastUpdateId = maxId;
                    showToast(`${data.updates.length} nuevo(s) update(s) disponibles. Recargando...`, 'info', 2000);
                    setTimeout(() => location.reload(), 2000);
                }
            }
        }
    } catch(e) {
        console.warn('Error al verificar updates:', e);
    } finally {
        if (icon) icon.style.animation = '';
        startCountdown();
    }
};

// Keyframe para el icono
const style = document.createElement('style');
style.textContent = '@keyframes spin { from{transform:rotate(0deg)} to{transform:rotate(360deg)} }';
document.head.appendChild(style);

startCountdown();
<?php endif; ?>

// ── Submit del form de publicar: prevenir doble envío ────
const pf = document.getElementById('publishForm');
const pb = document.getElementById('publishBtn');
if (pf && pb) {
    pf.addEventListener('submit', () => {
        pb.disabled = true;
        pb.innerHTML = '<i class="bi bi-hourglass-split"></i> Publicando...';
    });
}

// Aplicar flash toasts si existen
<?php if (!empty($flash['msg'])): ?>
document.addEventListener('DOMContentLoaded', () => {
    showToast('<?= e(addslashes($flash['msg'])) ?>', '<?= e($flash['type']) ?>');
});
<?php endif; ?>

})();
</script>

</body>
</html>