<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Admin: Gestión de Usuarios
 * ============================================================
 * Archivo : admin/usuarios.php
 * Tabla   : usuarios
 * Versión : 2.0.0
 * ============================================================
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$auth->requireAdmin();
$yo = currentUser();

// ── Acción y parámetros ───────────────────────────────────────
$action  = trim($_GET['action'] ?? 'lista');
$uid     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ============================================================
// AJAX — Manejador POST/JSON
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $ajaxAc = $input['action'] ?? ($_POST['action'] ?? '');

    if (!$auth->verifyCSRF($input['csrf'] ?? ($_POST['csrf_token'] ?? ''))) {
        echo json_encode(['success'=>false,'message'=>'Token CSRF inválido.']); exit;
    }

    switch ($ajaxAc) {

        // ── Cambiar estado activo ────────────────────────────
        case 'toggle_activo':
            $id = (int)($input['id'] ?? 0);
            if (!$id || $id === (int)$yo['id']) {
                echo json_encode(['success'=>false,'message'=>'No puedes modificarte a ti mismo.']); exit;
            }
            try {
                db()->execute("UPDATE usuarios SET activo = NOT activo WHERE id = ?", [$id]);
                $nuevo = (int)db()->fetchColumn("SELECT activo FROM usuarios WHERE id = ?", [$id]);
                logActividad((int)$yo['id'],'toggle_activo_usuario','usuarios',$id,"Estado activo → $nuevo");
                echo json_encode(['success'=>true,'valor'=>$nuevo,'message'=>$nuevo?'Usuario activado.':'Usuario desactivado.']);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
            }
            exit;

        // ── Toggle premium ───────────────────────────────────
        case 'toggle_premium':
            $id = (int)($input['id'] ?? 0);
            if (!$id) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            try {
                db()->execute("UPDATE usuarios SET premium = NOT premium, es_premium = NOT es_premium WHERE id = ?", [$id]);
                $nuevo = (int)db()->fetchColumn("SELECT premium FROM usuarios WHERE id = ?", [$id]);
                logActividad((int)$yo['id'],'toggle_premium_usuario','usuarios',$id,"Premium → $nuevo");
                echo json_encode(['success'=>true,'valor'=>$nuevo,'message'=>$nuevo?'Premium activado.':'Premium desactivado.']);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
            }
            exit;

        // ── Toggle verificado ────────────────────────────────
        case 'toggle_verificado':
            $id = (int)($input['id'] ?? 0);
            if (!$id) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            try {
                db()->execute("UPDATE usuarios SET verificado = NOT verificado WHERE id = ?", [$id]);
                $nuevo = (int)db()->fetchColumn("SELECT verificado FROM usuarios WHERE id = ?", [$id]);
                logActividad((int)$yo['id'],'toggle_verificado_usuario','usuarios',$id,"Verificado → $nuevo");
                echo json_encode(['success'=>true,'valor'=>$nuevo,'message'=>$nuevo?'Usuario verificado.':'Verificación removida.']);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
            }
            exit;

        // ── Cambiar rol ──────────────────────────────────────
        case 'cambiar_rol':
            $id  = (int)($input['id'] ?? 0);
            $rol = trim($input['rol'] ?? '');
            $rolesPermitidos = ['super_admin','admin','editor','periodista','user'];
            if (!$id || !in_array($rol, $rolesPermitidos)) {
                echo json_encode(['success'=>false,'message'=>'Datos inválidos.']); exit;
            }
            if ($id === (int)$yo['id'] && $rol !== $yo['rol']) {
                echo json_encode(['success'=>false,'message'=>'No puedes cambiar tu propio rol.']); exit;
            }
            // Solo super_admin puede asignar super_admin
            if ($rol === 'super_admin' && $yo['rol'] !== 'super_admin') {
                echo json_encode(['success'=>false,'message'=>'Sin permiso para asignar este rol.']); exit;
            }
            try {
                db()->execute("UPDATE usuarios SET rol = ? WHERE id = ?", [$rol, $id]);
                logActividad((int)$yo['id'],'cambiar_rol_usuario','usuarios',$id,"Rol → $rol");
                echo json_encode(['success'=>true,'message'=>"Rol cambiado a $rol."]);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
            }
            exit;

        // ── Eliminar usuario ─────────────────────────────────
        case 'eliminar':
            $id = (int)($input['id'] ?? 0);
            if (!$id) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            if ($id === (int)$yo['id']) {
                echo json_encode(['success'=>false,'message'=>'No puedes eliminarte a ti mismo.']); exit;
            }
            // Solo super_admin puede eliminar otros admins
            $target = db()->fetchOne("SELECT rol FROM usuarios WHERE id = ?", [$id]);
            if (!$target) { echo json_encode(['success'=>false,'message'=>'Usuario no encontrado.']); exit; }
            if (in_array($target['rol'],['super_admin','admin']) && $yo['rol'] !== 'super_admin') {
                echo json_encode(['success'=>false,'message'=>'Sin permiso para eliminar este usuario.']); exit;
            }
            try {
                // Anonimizar en lugar de borrar para mantener integridad referencial
                db()->execute(
                    "UPDATE usuarios SET
                        nombre = 'Usuario Eliminado',
                        email  = CONCAT('deleted_',id,'@eliminado.local'),
                        avatar = NULL,
                        bio    = NULL,
                        activo = 0,
                        rol    = 'user'
                     WHERE id = ?",
                    [$id]
                );
                logActividad((int)$yo['id'],'eliminar_usuario','usuarios',$id,"Eliminó usuario #$id");
                echo json_encode(['success'=>true,'message'=>'Usuario eliminado correctamente.']);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
            }
            exit;

        // ── Resetear password ────────────────────────────────
        case 'reset_password':
            $id          = (int)($input['id'] ?? 0);
            $nuevaPass   = trim($input['password'] ?? '');
            if (!$id || mb_strlen($nuevaPass) < 8) {
                echo json_encode(['success'=>false,'message'=>'La contraseña debe tener al menos 8 caracteres.']); exit;
            }
            try {
                $hash = password_hash($nuevaPass, PASSWORD_BCRYPT, ['cost'=>12]);
                db()->execute("UPDATE usuarios SET password = ?, login_intentos = 0, bloqueado_hasta = NULL WHERE id = ?", [$hash, $id]);
                logActividad((int)$yo['id'],'reset_password_usuario','usuarios',$id,"Reseteó contraseña de usuario #$id");
                echo json_encode(['success'=>true,'message'=>'Contraseña actualizada correctamente.']);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
            }
            exit;

        // ── Desbloquear usuario ──────────────────────────────
        case 'desbloquear':
            $id = (int)($input['id'] ?? 0);
            if (!$id) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            try {
                db()->execute("UPDATE usuarios SET login_intentos = 0, bloqueado_hasta = NULL WHERE id = ?", [$id]);
                logActividad((int)$yo['id'],'desbloquear_usuario','usuarios',$id,"Desbloqueó usuario #$id");
                echo json_encode(['success'=>true,'message'=>'Usuario desbloqueado.']);
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
            }
            exit;

        // ── Guardar usuario (crear/editar) ───────────────────
        case 'guardar_usuario':
            $editId   = (int)($input['id'] ?? 0);
            $nombre   = trim($input['nombre'] ?? '');
            $email    = trim($input['email']  ?? '');
            $rol      = trim($input['rol']    ?? 'user');
            $activo   = (int)($input['activo'] ?? 1);
            $verificado = (int)($input['verificado'] ?? 0);
            $premium  = (int)($input['premium'] ?? 0);
            $bio      = trim($input['bio']     ?? '');
            $ciudad   = trim($input['ciudad']  ?? '');
            $website  = trim($input['website'] ?? '');
            $twitter  = trim($input['twitter'] ?? '');
            $instagram= trim($input['instagram']?? '');
            $facebook = trim($input['facebook'] ?? '');
            $password = trim($input['password'] ?? '');
            $rolesOk  = ['super_admin','admin','editor','periodista','user'];

            if (mb_strlen($nombre) < 2) { echo json_encode(['success'=>false,'message'=>'El nombre es obligatorio (mín. 2 caracteres).']); exit; }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success'=>false,'message'=>'Email inválido.']); exit; }
            if (!in_array($rol, $rolesOk)) { echo json_encode(['success'=>false,'message'=>'Rol inválido.']); exit; }
            if ($rol === 'super_admin' && $yo['rol'] !== 'super_admin') {
                echo json_encode(['success'=>false,'message'=>'Sin permiso para asignar este rol.']); exit;
            }
            // Verificar email único
            $existe = (int)db()->fetchColumn(
                "SELECT COUNT(*) FROM usuarios WHERE email = ? AND id != ?",
                [$email, $editId]
            );
            if ($existe > 0) { echo json_encode(['success'=>false,'message'=>'El email ya está registrado.']); exit; }

            try {
                if ($editId) {
                    // EDITAR
                    $passClause = '';
                    $passParams = [];
                    if (!empty($password)) {
                        if (mb_strlen($password) < 8) {
                            echo json_encode(['success'=>false,'message'=>'La contraseña debe tener al menos 8 caracteres.']); exit;
                        }
                        $passClause = ', password = ?';
                        $passParams = [password_hash($password, PASSWORD_BCRYPT, ['cost'=>12])];
                    }
                    db()->execute(
                        "UPDATE usuarios SET
                            nombre=?,email=?,rol=?,activo=?,verificado=?,premium=?,es_premium=?,
                            bio=?,ciudad=?,website=?,twitter=?,instagram=?,facebook=?
                            $passClause
                         WHERE id=?",
                        array_merge(
                            [$nombre,$email,$rol,$activo,$verificado,$premium,$premium,
                             $bio,$ciudad,$website,$twitter,$instagram,$facebook],
                            $passParams,
                            [$editId]
                        )
                    );
                    logActividad((int)$yo['id'],'edit_usuario','usuarios',$editId,"Editó usuario: $nombre");
                    echo json_encode(['success'=>true,'message'=>'Usuario actualizado.','id'=>$editId]);
                } else {
                    // CREAR
                    if (mb_strlen($password) < 8) {
                        echo json_encode(['success'=>false,'message'=>'La contraseña es obligatoria (mín. 8 caracteres).']); exit;
                    }
                    $slug  = generateSlug($nombre, 'usuarios');
                    $hash  = password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);
                    $newId = db()->insert(
                        "INSERT INTO usuarios
                            (nombre,email,password,rol,activo,verificado,premium,es_premium,
                             bio,ciudad,website,twitter,instagram,facebook,
                             slug_perfil,email_verificado,fecha_registro)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW())",
                        [$nombre,$email,$hash,$rol,$activo,$verificado,$premium,$premium,
                         $bio,$ciudad,$website,$twitter,$instagram,$facebook,$slug]
                    );
                    logActividad((int)$yo['id'],'create_usuario','usuarios',$newId,"Creó usuario: $nombre");
                    echo json_encode(['success'=>true,'message'=>'Usuario creado correctamente.','id'=>$newId]);
                }
            } catch (\Throwable $e) {
                echo json_encode(['success'=>false,'message'=>'Error BD: '.$e->getMessage()]);
            }
            exit;

        // ── Enviar email de bienvenida ────────────────────────
        case 'enviar_bienvenida':
            $id = (int)($input['id'] ?? 0);
            if (!$id) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            // En producción aquí iría el envío real de correo
            logActividad((int)$yo['id'],'enviar_bienvenida','usuarios',$id,"Envió email bienvenida a usuario #$id");
            echo json_encode(['success'=>true,'message'=>'Email de bienvenida enviado (funcionalidad de email requerida).']);
            exit;

        // ── Obtener datos de usuario para modal ───────────────
        case 'get_usuario':
            $id = (int)($input['id'] ?? 0);
            if (!$id) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            $u = db()->fetchOne(
                "SELECT id,nombre,email,rol,activo,verificado,premium,bio,ciudad,
                        website,twitter,instagram,facebook,avatar,fecha_registro,
                        ultimo_acceso,reputacion,nivel,total_seguidores,total_seguidos,
                        login_intentos,bloqueado_hasta,newsletter,es_premium,
                        (SELECT COUNT(*) FROM noticias WHERE autor_id = usuarios.id) AS total_noticias,
                        (SELECT COUNT(*) FROM comentarios WHERE usuario_id = usuarios.id) AS total_comentarios
                 FROM usuarios WHERE id = ? LIMIT 1",
                [$id]
            );
            if (!$u) { echo json_encode(['success'=>false,'message'=>'Usuario no encontrado.']); exit; }
            echo json_encode(['success'=>true,'usuario'=>$u]);
            exit;

        default:
            echo json_encode(['success'=>false,'message'=>'Acción desconocida.']); exit;
    }
}

// ============================================================
// LISTA DE USUARIOS — Filtros y paginación
// ============================================================
$filtroRol      = trim($_GET['rol']      ?? '');
$filtroEstado   = trim($_GET['estado']   ?? '');
$filtroPremium  = trim($_GET['premium']  ?? '');
$filtroVerif    = trim($_GET['verif']    ?? '');
$filtroBusqueda = trim($_GET['q']        ?? '');
$ordenar        = trim($_GET['orden']    ?? 'reciente');
$porPagina      = 20;
$pagActual      = max(1, (int)($_GET['pag'] ?? 1));
$offset         = ($pagActual - 1) * $porPagina;

// Construir WHERE
$where  = ['1=1'];
$params = [];

$rolesValidos = ['super_admin','admin','editor','periodista','user'];
if (!empty($filtroRol) && in_array($filtroRol, $rolesValidos)) {
    $where[]  = 'u.rol = ?';
    $params[] = $filtroRol;
}
if ($filtroEstado === 'activo') {
    $where[] = 'u.activo = 1';
} elseif ($filtroEstado === 'inactivo') {
    $where[] = 'u.activo = 0';
} elseif ($filtroEstado === 'bloqueado') {
    $where[] = 'u.bloqueado_hasta > NOW()';
}
if ($filtroPremium === '1')  $where[] = 'u.premium = 1';
if ($filtroPremium === '0')  $where[] = 'u.premium = 0';
if ($filtroVerif   === '1')  $where[] = 'u.verificado = 1';
if ($filtroVerif   === '0')  $where[] = 'u.verificado = 0';
if (!empty($filtroBusqueda)) {
    $where[]  = '(u.nombre LIKE ? OR u.email LIKE ? OR u.ciudad LIKE ?)';
    $b = '%'.$filtroBusqueda.'%';
    $params[] = $b; $params[] = $b; $params[] = $b;
}

$whereStr = implode(' AND ', $where);

$orderBy = match($ordenar) {
    'nombre_asc'  => 'u.nombre ASC',
    'nombre_desc' => 'u.nombre DESC',
    'activo_desc' => 'u.ultimo_acceso DESC',
    'reputacion'  => 'u.reputacion DESC',
    default       => 'u.fecha_registro DESC',
};

$totalUsuarios = (int)db()->fetchColumn(
    "SELECT COUNT(*) FROM usuarios u WHERE $whereStr",
    $params
);
$totalPaginas = (int)ceil($totalUsuarios / $porPagina);

$usuarios = db()->fetchAll(
    "SELECT u.id, u.nombre, u.email, u.rol, u.avatar, u.activo, u.verificado,
            u.premium, u.es_premium, u.reputacion, u.nivel, u.ciudad,
            u.fecha_registro, u.ultimo_acceso, u.login_intentos, u.bloqueado_hasta,
            u.newsletter, u.total_seguidores,
            (SELECT COUNT(*) FROM noticias WHERE autor_id = u.id) AS total_noticias,
            (SELECT COUNT(*) FROM comentarios WHERE usuario_id = u.id) AS total_coments
     FROM usuarios u
     WHERE $whereStr
     ORDER BY $orderBy
     LIMIT ? OFFSET ?",
    array_merge($params, [$porPagina, $offset])
);

// Stats rápidas
$statsRoles = db()->fetchAll("SELECT rol, COUNT(*) AS total FROM usuarios GROUP BY rol", []);
$statsMap   = array_column($statsRoles, 'total', 'rol');
$statsTotal = array_sum($statsMap);
$statsActivos  = (int)db()->fetchColumn("SELECT COUNT(*) FROM usuarios WHERE activo=1");
$statsPremium  = (int)db()->fetchColumn("SELECT COUNT(*) FROM usuarios WHERE premium=1");
$statsHoy      = (int)db()->fetchColumn("SELECT COUNT(*) FROM usuarios WHERE DATE(fecha_registro) = CURDATE()");

// URL paginación
function usuPagUrl(int $pag): string {
    global $filtroRol, $filtroEstado, $filtroPremium, $filtroVerif, $filtroBusqueda, $ordenar;
    $p = array_filter([
        'rol'=>$filtroRol,'estado'=>$filtroEstado,'premium'=>$filtroPremium,
        'verif'=>$filtroVerif,'q'=>$filtroBusqueda,'orden'=>$ordenar!=='reciente'?$ordenar:'',
    ]);
    return APP_URL.'/admin/usuarios.php?'.http_build_query(array_merge($p,['pag'=>$pag]));
}

// Helpers
function rolLabel(string $rol): string {
    return match($rol) {
        'super_admin' => '⚡ Super Admin',
        'admin'       => '🛡️ Admin',
        'editor'      => '✍️ Editor',
        'periodista'  => '📰 Periodista',
        default       => '👤 Usuario',
    };
}
function rolColor(string $rol): string {
    return match($rol) {
        'super_admin' => '#e63946',
        'admin'       => '#7c3aed',
        'editor'      => '#0d6efd',
        'periodista'  => '#059669',
        default       => 'var(--text-muted)',
    };
}
function rolBg(string $rol): string {
    return match($rol) {
        'super_admin' => 'rgba(230,57,70,.12)',
        'admin'       => 'rgba(124,58,237,.12)',
        'editor'      => 'rgba(13,110,253,.12)',
        'periodista'  => 'rgba(5,150,105,.12)',
        default       => 'var(--bg-surface-2)',
    };
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios — Panel Admin</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= APP_VERSION ?>">
    <style>
        /* ══════════════════════════════════════
           BASE ADMIN LAYOUT
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
        .stat-card{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--border-radius-xl);padding:16px;display:flex;align-items:center;gap:12px;transition:all var(--transition-fast);text-decoration:none}
        .stat-card:hover{border-color:var(--primary);transform:translateY(-2px)}
        .stat-card.active-filter{border-color:var(--primary);background:rgba(230,57,70,.04)}
        .stat-icon{width:42px;height:42px;border-radius:var(--border-radius-lg);display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
        .stat-num{font-size:1.4rem;font-weight:900;color:var(--text-primary);line-height:1}
        .stat-lbl{font-size:.7rem;color:var(--text-muted);margin-top:2px}

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

        /* Avatar */
        .user-avatar{width:40px;height:40px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid var(--border-color)}
        .user-mini{display:flex;align-items:center;gap:10px}
        .user-name{font-weight:700;color:var(--text-primary);font-size:.83rem}
        .user-email{font-size:.7rem;color:var(--text-muted)}

        /* Rol badge */
        .rol-badge{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:var(--border-radius-full);font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}

        /* Toggle */
        .tgl{position:relative;display:inline-block;width:34px;height:18px}
        .tgl input{opacity:0;width:0;height:0}
        .tgl-slider{position:absolute;cursor:pointer;inset:0;background:var(--bg-surface-3);border-radius:18px;transition:.3s}
        .tgl-slider::before{content:'';position:absolute;width:12px;height:12px;border-radius:50%;background:#fff;left:3px;top:3px;transition:.3s}
        .tgl input:checked + .tgl-slider{background:var(--primary)}
        .tgl input:checked + .tgl-slider::before{transform:translateX(16px)}

        /* Acciones */
        .row-acts{display:flex;gap:4px}
        .act-btn{display:flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:var(--border-radius-sm);font-size:.8rem;text-decoration:none;border:none;cursor:pointer;transition:all var(--transition-fast)}
        .act-edit{color:var(--info);background:rgba(59,130,246,.1)}
        .act-del{color:var(--danger);background:rgba(239,68,68,.1)}
        .act-key{color:var(--warning);background:rgba(245,158,11,.1)}
        .act-view{color:var(--success);background:rgba(34,197,94,.1)}
        .act-unlock{color:#7c3aed;background:rgba(124,58,237,.1)}
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
        .btn-p{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;background:var(--primary);color:#fff;border:none;border-radius:var(--border-radius-lg);font-size:.83rem;font-weight:700;cursor:pointer;transition:all var(--transition-fast);text-decoration:none}
        .btn-p:hover{background:var(--primary-dark);transform:translateY(-1px)}
        .btn-s{display:inline-flex;align-items:center;gap:7px;padding:10px 16px;background:var(--bg-surface-2);color:var(--text-secondary);border:1px solid var(--border-color);border-radius:var(--border-radius-lg);font-size:.83rem;font-weight:600;cursor:pointer;transition:all var(--transition-fast);text-decoration:none}
        .btn-s:hover{background:var(--bg-surface-3)}
        .btn-danger{display:inline-flex;align-items:center;gap:7px;padding:10px 16px;background:var(--danger);color:#fff;border:none;border-radius:var(--border-radius-lg);font-size:.83rem;font-weight:700;cursor:pointer}

        /* Modal */
        .modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:1100;display:none;align-items:center;justify-content:center;padding:16px}
        .modal-bg.open{display:flex}
        .modal-panel{background:var(--bg-surface);border-radius:var(--border-radius-xl);width:100%;max-width:600px;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-xl);display:flex;flex-direction:column}
        .modal-panel::-webkit-scrollbar{width:4px}
        .modal-panel::-webkit-scrollbar-thumb{background:var(--border-color)}
        .modal-header{padding:18px 22px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:var(--bg-surface);z-index:1}
        .modal-title{font-size:.95rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px}
        .modal-close{width:32px;height:32px;border-radius:50%;border:none;background:var(--bg-surface-2);color:var(--text-muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1rem;transition:all var(--transition-fast)}
        .modal-close:hover{background:var(--danger);color:#fff}
        .modal-body{padding:22px}
        .modal-footer{padding:14px 22px;border-top:1px solid var(--border-color);display:flex;gap:10px;justify-content:flex-end}

        /* Form */
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        .form-group{margin-bottom:14px}
        .form-label{display:block;font-size:.8rem;font-weight:600;color:var(--text-secondary);margin-bottom:5px}
        .form-label span{color:var(--danger)}
        .form-ctrl{width:100%;padding:9px 12px;border:1px solid var(--border-color);border-radius:var(--border-radius);background:var(--bg-body);color:var(--text-primary);font-size:.875rem}
        .form-ctrl:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(230,57,70,.08)}
        textarea.form-ctrl{resize:vertical;min-height:80px}
        .form-hint{font-size:.7rem;color:var(--text-muted);margin-top:3px}

        /* Profile card modal */
        .uprofile-card{background:linear-gradient(135deg,var(--secondary-dark),#1a0a2e);border-radius:var(--border-radius-xl);padding:20px;margin-bottom:16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
        .uprofile-avatar{width:64px;height:64px;border-radius:50%;object-fit:cover;border:3px solid rgba(255,255,255,.2);flex-shrink:0}
        .uprofile-name{font-size:1rem;font-weight:800;color:#fff;line-height:1.2}
        .uprofile-email{font-size:.78rem;color:rgba(255,255,255,.6);margin-top:2px}
        .uprofile-stats{display:flex;gap:16px;margin-top:10px;flex-wrap:wrap}
        .uprofile-stat-num{font-size:1.1rem;font-weight:900;color:#fff}
        .uprofile-stat-lbl{font-size:.65rem;color:rgba(255,255,255,.5);text-transform:uppercase}

        /* Confirm modal */
        .confirm-modal{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1200;display:none;align-items:center;justify-content:center;padding:20px}
        .confirm-modal.open{display:flex}
        .confirm-box{background:var(--bg-surface);border-radius:var(--border-radius-xl);padding:28px;max-width:400px;width:100%;box-shadow:var(--shadow-xl)}
        .confirm-title{font-size:1.05rem;font-weight:700;margin-bottom:8px}
        .confirm-body{font-size:.875rem;color:var(--text-secondary);margin-bottom:20px}
        .confirm-acts{display:flex;gap:10px;justify-content:flex-end}

        /* Toast */
        #toast-cnt{position:fixed;bottom:24px;right:24px;display:flex;flex-direction:column;gap:8px;z-index:9999}
        .toast{padding:12px 18px;border-radius:var(--border-radius-lg);background:var(--secondary-dark);color:#fff;font-size:.85rem;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shadow-xl);animation:toastIn .3s ease;min-width:260px}
        .toast.success{border-left:4px solid var(--success)}
        .toast.error{border-left:4px solid var(--danger)}
        .toast.info{border-left:4px solid var(--info)}
        @keyframes toastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

        /* Status dot */
        .status-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;display:inline-block}
        .status-dot.on{background:var(--success)}
        .status-dot.off{background:var(--text-muted)}
        .status-dot.blocked{background:var(--danger)}

        /* Responsive */
        @media(max-width:1200px){.stats-grid{grid-template-columns:repeat(3,1fr)}}
        @media(max-width:768px){
            .admin-sidebar{transform:translateX(-100%)}
            .admin-sidebar.open{transform:translateX(0);box-shadow:var(--shadow-xl)}
            .admin-main{margin-left:0}
            .admin-topbar__toggle{display:flex}
            .admin-content{padding:14px}
            .admin-overlay{display:block}
            .stats-grid{grid-template-columns:repeat(2,1fr)}
            .filters-bar{flex-direction:column;align-items:stretch}
            .form-row{grid-template-columns:1fr}
        }
        @media(max-width:480px){.stats-grid{grid-template-columns:1fr 1fr}}
    </style>
</head>
<body>
<div class="admin-wrapper">

<?php require_once __DIR__ . '/sidebar.php'; ?>
<div class="admin-overlay" id="adminOverlay" onclick="closeSidebar()"></div>

<main class="admin-main">
    <!-- Topbar -->
    <div class="admin-topbar">
        <button class="admin-topbar__toggle" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
        <h1 class="admin-topbar__title">
            <i class="bi bi-people-fill" style="color:var(--primary);margin-right:6px"></i>
            Gestión de Usuarios
        </h1>
        <div class="admin-topbar__right">
            <button class="btn-p" onclick="abrirModalUsuario()">
                <i class="bi bi-person-plus-fill"></i>
                <span class="d-none d-sm-inline">Nuevo Usuario</span>
            </button>
            <a href="<?= APP_URL ?>/index.php" target="_blank" class="btn-s" style="padding:8px 12px">
                <i class="bi bi-box-arrow-up-right"></i>
            </a>
        </div>
    </div>

    <div class="admin-content">

        <!-- Stats rápidas -->
        <div class="stats-grid">
            <?php
            $statsConfig = [
                '' => ['label'=>'Total','color'=>'rgba(59,130,246,.1)','tc'=>'var(--info)','icon'=>'bi-people'],
                'activos' => ['label'=>'Activos','color'=>'rgba(34,197,94,.1)','tc'=>'var(--success)','icon'=>'bi-person-check'],
                'premium' => ['label'=>'Premium','color'=>'rgba(245,158,11,.1)','tc'=>'var(--warning)','icon'=>'bi-star-fill'],
            ];
            $rolStatsConfig = [
                'admin'     => ['label'=>'Admins','color'=>'rgba(124,58,237,.1)','tc'=>'#7c3aed','icon'=>'bi-shield-fill'],
                'periodista'=> ['label'=>'Periodistas','color'=>'rgba(5,150,105,.1)','tc'=>'#059669','icon'=>'bi-newspaper'],
            ];
            ?>
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(59,130,246,.1);color:var(--info)"><i class="bi bi-people"></i></div>
                <div><div class="stat-num"><?= number_format($statsTotal) ?></div><div class="stat-lbl">Total Usuarios</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(34,197,94,.1);color:var(--success)"><i class="bi bi-person-check"></i></div>
                <div><div class="stat-num"><?= number_format($statsActivos) ?></div><div class="stat-lbl">Activos</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(245,158,11,.1);color:var(--warning)"><i class="bi bi-star-fill"></i></div>
                <div><div class="stat-num"><?= number_format($statsPremium) ?></div><div class="stat-lbl">Premium</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(124,58,237,.1);color:#7c3aed"><i class="bi bi-shield-fill"></i></div>
                <div><div class="stat-num"><?= number_format(($statsMap['admin']??0) + ($statsMap['super_admin']??0)) ?></div><div class="stat-lbl">Admins</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(230,57,70,.1);color:var(--primary)"><i class="bi bi-person-plus"></i></div>
                <div><div class="stat-num"><?= number_format($statsHoy) ?></div><div class="stat-lbl">Hoy</div></div>
            </div>
        </div>

        <!-- Filtros -->
        <form method="GET" class="filters-bar">
            <div class="search-wrap">
                <i class="bi bi-search"></i>
                <input type="text" name="q" class="search-inp"
                       placeholder="Buscar por nombre, email, ciudad..."
                       value="<?= e($filtroBusqueda) ?>">
            </div>
            <select name="rol" class="filter-sel" onchange="this.form.submit()">
                <option value="">Todos los roles</option>
                <option value="super_admin" <?= $filtroRol==='super_admin'?'selected':''?>>⚡ Super Admin</option>
                <option value="admin"       <?= $filtroRol==='admin'      ?'selected':''?>>🛡️ Admin</option>
                <option value="editor"      <?= $filtroRol==='editor'     ?'selected':''?>>✍️ Editor</option>
                <option value="periodista"  <?= $filtroRol==='periodista' ?'selected':''?>>📰 Periodista</option>
                <option value="user"        <?= $filtroRol==='user'       ?'selected':''?>>👤 Usuario</option>
            </select>
            <select name="estado" class="filter-sel" onchange="this.form.submit()">
                <option value="">Estado: todos</option>
                <option value="activo"    <?= $filtroEstado==='activo'   ?'selected':''?>>✅ Activos</option>
                <option value="inactivo"  <?= $filtroEstado==='inactivo' ?'selected':''?>>❌ Inactivos</option>
                <option value="bloqueado" <?= $filtroEstado==='bloqueado'?'selected':''?>>🔒 Bloqueados</option>
            </select>
            <select name="premium" class="filter-sel" onchange="this.form.submit()">
                <option value="">Premium: todos</option>
                <option value="1" <?= $filtroPremium==='1'?'selected':''?>>⭐ Sí</option>
                <option value="0" <?= $filtroPremium==='0'?'selected':''?>>No</option>
            </select>
            <select name="verif" class="filter-sel" onchange="this.form.submit()">
                <option value="">Verificado: todos</option>
                <option value="1" <?= $filtroVerif==='1'?'selected':''?>>✔️ Sí</option>
                <option value="0" <?= $filtroVerif==='0'?'selected':''?>>No</option>
            </select>
            <select name="orden" class="filter-sel" onchange="this.form.submit()">
                <option value="reciente"    <?= $ordenar==='reciente'   ?'selected':''?>>Más recientes</option>
                <option value="nombre_asc"  <?= $ordenar==='nombre_asc' ?'selected':''?>>Nombre A-Z</option>
                <option value="activo_desc" <?= $ordenar==='activo_desc'?'selected':''?>>Último acceso</option>
                <option value="reputacion"  <?= $ordenar==='reputacion' ?'selected':''?>>Reputación</option>
            </select>
            <button type="submit" class="btn-p" style="padding:9px 14px;font-size:.82rem">
                <i class="bi bi-funnel-fill"></i> Filtrar
            </button>
            <?php if ($filtroBusqueda||$filtroRol||$filtroEstado||$filtroPremium||$filtroVerif): ?>
            <a href="<?= APP_URL ?>/admin/usuarios.php" class="btn-s" style="padding:9px 10px;font-size:.82rem">
                <i class="bi bi-x-lg"></i>
            </a>
            <?php endif; ?>
        </form>

        <!-- Tabla -->
        <div class="table-wrap">
            <?php if (empty($usuarios)): ?>
            <div style="padding:60px 20px;text-align:center">
                <i class="bi bi-person-slash" style="font-size:3rem;color:var(--text-muted)"></i>
                <div style="font-size:1rem;font-weight:700;color:var(--text-primary);margin:10px 0 8px">
                    No se encontraron usuarios
                </div>
                <div style="font-size:.85rem;color:var(--text-muted);margin-bottom:16px">
                    <?= $filtroBusqueda ? 'Sin resultados para la búsqueda.' : 'Crea el primer usuario.' ?>
                </div>
                <button class="btn-p" onclick="abrirModalUsuario()">
                    <i class="bi bi-person-plus-fill"></i> Nuevo Usuario
                </button>
            </div>
            <?php else: ?>
            <div style="overflow-x:auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Rol</th>
                        <th>Actividad</th>
                        <th>Estado</th>
                        <th>Premium</th>
                        <th>Verificado</th>
                        <th>Registro</th>
                        <th>Último acceso</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($usuarios as $u):
                    $bloqueado   = !empty($u['bloqueado_hasta']) && strtotime($u['bloqueado_hasta']) > time();
                    $avatarSrc   = !empty($u['avatar'])
                        ? (str_starts_with($u['avatar'],'http') ? $u['avatar'] : APP_URL.'/'.$u['avatar'])
                        : APP_URL.'/assets/images/default-avatar.png';
                    $esMismoUser = (int)$u['id'] === (int)$yo['id'];
                ?>
                <tr id="urow-<?= (int)$u['id'] ?>" <?= $esMismoUser ? 'style="background:rgba(230,57,70,.04)"' : '' ?>>
                    <td style="min-width:220px">
                        <div class="user-mini">
                            <img class="user-avatar"
                                 src="<?= e($avatarSrc) ?>"
                                 alt="<?= e($u['nombre']) ?>"
                                 loading="lazy"
                                 onerror="this.src='<?= APP_URL ?>/assets/images/default-avatar.png'">
                            <div>
                                <div class="user-name">
                                    <?= e($u['nombre']) ?>
                                    <?php if ($esMismoUser): ?>
                                    <span style="font-size:.65rem;background:rgba(230,57,70,.1);color:var(--primary);padding:1px 5px;border-radius:4px;margin-left:4px">Tú</span>
                                    <?php endif; ?>
                                    <?php if ($u['verificado']): ?>
                                    <i class="bi bi-patch-check-fill" style="color:var(--info);font-size:.8rem;margin-left:2px"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="user-email"><?= e($u['email']) ?></div>
                                <?php if (!empty($u['ciudad'])): ?>
                                <div style="font-size:.67rem;color:var(--text-muted);margin-top:1px">
                                    <i class="bi bi-geo-alt"></i> <?= e($u['ciudad']) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <select class="rol-select"
                                data-id="<?= (int)$u['id'] ?>"
                                onchange="cambiarRol(this)"
                                <?= $esMismoUser ? 'disabled title="No puedes cambiar tu propio rol"' : '' ?>
                                style="padding:4px 8px;border-radius:6px;border:1px solid var(--border-color);
                                       font-size:.72rem;font-weight:700;background:<?= rolBg($u['rol']) ?>;
                                       color:<?= rolColor($u['rol']) ?>;cursor:pointer">
                            <option value="super_admin" <?= $u['rol']==='super_admin'?'selected':''?>>⚡ Super Admin</option>
                            <option value="admin"       <?= $u['rol']==='admin'      ?'selected':''?>>🛡️ Admin</option>
                            <option value="editor"      <?= $u['rol']==='editor'     ?'selected':''?>>✍️ Editor</option>
                            <option value="periodista"  <?= $u['rol']==='periodista' ?'selected':''?>>📰 Periodista</option>
                            <option value="user"        <?= $u['rol']==='user'       ?'selected':''?>>👤 Usuario</option>
                        </select>
                    </td>
                    <td>
                        <div style="font-size:.75rem;color:var(--text-secondary)">
                            <div><i class="bi bi-newspaper" style="color:var(--primary)"></i> <?= (int)$u['total_noticias'] ?> noticias</div>
                            <div><i class="bi bi-chat-dots" style="color:var(--info)"></i> <?= (int)$u['total_coments'] ?> comentarios</div>
                            <?php if ($u['total_seguidores'] > 0): ?>
                            <div><i class="bi bi-people" style="color:var(--success)"></i> <?= (int)$u['total_seguidores'] ?> seguidores</div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div style="display:flex;align-items:center;gap:6px">
                            <?php if ($bloqueado): ?>
                            <span class="status-dot blocked"></span>
                            <span style="font-size:.75rem;color:var(--danger);font-weight:600">Bloqueado</span>
                            <?php elseif ($u['activo']): ?>
                            <span class="status-dot on"></span>
                            <span style="font-size:.75rem;color:var(--success)">Activo</span>
                            <?php else: ?>
                            <span class="status-dot off"></span>
                            <span style="font-size:.75rem;color:var(--text-muted)">Inactivo</span>
                            <?php endif; ?>
                        </div>
                        <label class="tgl" style="margin-top:6px" <?= $esMismoUser ? 'title="No puedes desactivarte"' : '' ?>>
                            <input type="checkbox"
                                   <?= $u['activo'] ? 'checked' : '' ?>
                                   <?= $esMismoUser ? 'disabled' : '' ?>
                                   onchange="toggleActivo(<?= (int)$u['id'] ?>,this)">
                            <span class="tgl-slider"></span>
                        </label>
                        <?php if ($u['login_intentos'] >= 3): ?>
                        <div style="font-size:.65rem;color:var(--warning);margin-top:2px">
                            <i class="bi bi-exclamation-triangle"></i> <?= (int)$u['login_intentos'] ?> intentos
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <label class="tgl">
                            <input type="checkbox"
                                   <?= ($u['premium']||$u['es_premium']) ? 'checked' : '' ?>
                                   onchange="togglePremium(<?= (int)$u['id'] ?>,this)">
                            <span class="tgl-slider" style="<?= ($u['premium']||$u['es_premium'])
                                ? 'background:rgba(245,158,11,.9)' : '' ?>"></span>
                        </label>
                    </td>
                    <td>
                        <label class="tgl">
                            <input type="checkbox"
                                   <?= $u['verificado'] ? 'checked' : '' ?>
                                   onchange="toggleVerif(<?= (int)$u['id'] ?>,this)">
                            <span class="tgl-slider" style="<?= $u['verificado']
                                ? 'background:var(--info)' : '' ?>"></span>
                        </label>
                    </td>
                    <td style="white-space:nowrap;font-size:.78rem;color:var(--text-muted)">
                        <?= date('d/m/Y', strtotime($u['fecha_registro'])) ?>
                    </td>
                    <td style="white-space:nowrap;font-size:.78rem;color:var(--text-muted)">
                        <?= !empty($u['ultimo_acceso'])
                            ? timeAgo($u['ultimo_acceso'])
                            : '<span style="color:var(--text-muted);font-style:italic">Nunca</span>' ?>
                    </td>
                    <td>
                        <div class="row-acts">
                            <button onclick="verPerfil(<?= (int)$u['id'] ?>)"
                                    class="act-btn act-view" title="Ver perfil">
                                <i class="bi bi-eye-fill"></i>
                            </button>
                            <button onclick="editarUsuario(<?= (int)$u['id'] ?>)"
                                    class="act-btn act-edit" title="Editar">
                                <i class="bi bi-pencil-fill"></i>
                            </button>
                            <button onclick="abrirResetPass(<?= (int)$u['id'] ?>,'<?= e(addslashes($u['nombre'])) ?>')"
                                    class="act-btn act-key" title="Cambiar contraseña">
                                <i class="bi bi-key-fill"></i>
                            </button>
                            <?php if ($bloqueado || $u['login_intentos'] >= 3): ?>
                            <button onclick="desbloquear(<?= (int)$u['id'] ?>)"
                                    class="act-btn act-unlock" title="Desbloquear">
                                <i class="bi bi-unlock-fill"></i>
                            </button>
                            <?php endif; ?>
                            <?php if (!$esMismoUser): ?>
                            <button onclick="confirmarEliminar(<?= (int)$u['id'] ?>,'<?= e(addslashes($u['nombre'])) ?>')"
                                    class="act-btn act-del" title="Eliminar">
                                <i class="bi bi-trash-fill"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <!-- Paginación -->
            <?php if ($totalPaginas > 1 || $totalUsuarios > 0): ?>
            <div class="pag-wrap">
                <div class="pag-info">
                    Mostrando <?= number_format(min($offset+1,$totalUsuarios)) ?>–<?= number_format(min($offset+$porPagina,$totalUsuarios)) ?>
                    de <strong><?= number_format($totalUsuarios) ?></strong> usuarios
                </div>
                <div class="pag-links">
                    <a href="<?= usuPagUrl($pagActual-1) ?>" class="pag-btn <?= $pagActual<=1?'disabled':'' ?>">&laquo;</a>
                    <?php for ($p=max(1,$pagActual-3); $p<=min($totalPaginas,$pagActual+3); $p++): ?>
                    <a href="<?= usuPagUrl($p) ?>" class="pag-btn <?= $p===$pagActual?'active':'' ?>"><?= $p ?></a>
                    <?php endfor; ?>
                    <a href="<?= usuPagUrl($pagActual+1) ?>" class="pag-btn <?= $pagActual>=$totalPaginas?'disabled':'' ?>">&raquo;</a>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; // empty users ?>
        </div>

    </div><!-- /admin-content -->
</main>
</div><!-- /admin-wrapper -->

<!-- ══════════════════════════════════════════
     MODAL: CREAR / EDITAR USUARIO
══════════════════════════════════════════ -->
<div class="modal-bg" id="userModal">
    <div class="modal-panel">
        <div class="modal-header">
            <div class="modal-title" id="userModalTitle">
                <i class="bi bi-person-plus-fill" style="color:var(--primary)"></i>
                Nuevo Usuario
            </div>
            <button class="modal-close" onclick="cerrarModal('userModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="userId" value="0">

            <!-- Perfil preview -->
            <div id="uProfileCard" style="display:none" class="uprofile-card">
                <img id="uProfileAvatar"
                     src="<?= APP_URL ?>/assets/images/default-avatar.png"
                     class="uprofile-avatar" alt="Avatar"
                     onerror="this.src='<?= APP_URL ?>/assets/images/default-avatar.png'">
                <div>
                    <div class="uprofile-name" id="uProfileName">Nuevo Usuario</div>
                    <div class="uprofile-email" id="uProfileEmail">sin email</div>
                    <div class="uprofile-stats" id="uProfileStats"></div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label" for="uNombre">Nombre completo <span>*</span></label>
                    <input type="text" id="uNombre" class="form-ctrl"
                           placeholder="Nombre y apellidos..." maxlength="100">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="uEmail">Email <span>*</span></label>
                    <input type="email" id="uEmail" class="form-ctrl"
                           placeholder="correo@ejemplo.com">
                </div>
                <div class="form-group">
                    <label class="form-label" for="uRol">Rol <span>*</span></label>
                    <select id="uRol" class="form-ctrl">
                        <option value="user">👤 Usuario</option>
                        <option value="periodista">📰 Periodista</option>
                        <option value="editor">✍️ Editor</option>
                        <option value="admin">🛡️ Admin</option>
                        <?php if ($yo['rol'] === 'super_admin'): ?>
                        <option value="super_admin">⚡ Super Admin</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="uPassword">
                        Contraseña <span id="passRequired">*</span>
                    </label>
                    <div style="position:relative">
                        <input type="password" id="uPassword" class="form-ctrl"
                               placeholder="Mínimo 8 caracteres"
                               style="padding-right:40px">
                        <button type="button"
                                onclick="togglePassVis('uPassword',this)"
                                style="position:absolute;right:8px;top:50%;transform:translateY(-50%);
                                       background:none;border:none;cursor:pointer;color:var(--text-muted);
                                       font-size:.9rem">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="form-hint" id="passStrength"></div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="uCiudad">Ciudad</label>
                    <input type="text" id="uCiudad" class="form-ctrl"
                           placeholder="Ciudad del usuario..." maxlength="100">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="uBio">Biografía</label>
                <textarea id="uBio" class="form-ctrl" rows="2"
                          placeholder="Breve descripción del usuario..." maxlength="500"></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="uWebsite">Website</label>
                    <input type="url" id="uWebsite" class="form-ctrl"
                           placeholder="https://...">
                </div>
                <div class="form-group">
                    <label class="form-label" for="uTwitter">Twitter</label>
                    <input type="text" id="uTwitter" class="form-ctrl"
                           placeholder="@usuario">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="uInstagram">Instagram</label>
                    <input type="text" id="uInstagram" class="form-ctrl"
                           placeholder="@usuario">
                </div>
                <div class="form-group">
                    <label class="form-label" for="uFacebook">Facebook</label>
                    <input type="text" id="uFacebook" class="form-ctrl"
                           placeholder="URL o usuario">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;padding:12px;
                        background:var(--bg-surface-2);border-radius:var(--border-radius-lg)">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.82rem">
                    <label class="tgl">
                        <input type="checkbox" id="uActivo" checked>
                        <span class="tgl-slider"></span>
                    </label>
                    Activo
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.82rem">
                    <label class="tgl">
                        <input type="checkbox" id="uVerificado">
                        <span class="tgl-slider"></span>
                    </label>
                    Verificado
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.82rem">
                    <label class="tgl">
                        <input type="checkbox" id="uPremium">
                        <span class="tgl-slider"></span>
                    </label>
                    Premium
                </label>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-s" onclick="cerrarModal('userModal')">
                <i class="bi bi-x-lg"></i> Cancelar
            </button>
            <button class="btn-p" onclick="guardarUsuario()" id="btnGuardarUser">
                <i class="bi bi-check-lg"></i> <span id="btnUserLabel">Crear Usuario</span>
            </button>
        </div>
    </div>
</div>

<!-- Modal: Reset contraseña -->
<div class="modal-bg" id="resetPassModal">
    <div class="modal-panel" style="max-width:420px">
        <div class="modal-header">
            <div class="modal-title">
                <i class="bi bi-key-fill" style="color:var(--warning)"></i>
                Cambiar Contraseña
            </div>
            <button class="modal-close" onclick="cerrarModal('resetPassModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="resetUserId" value="0">
            <div style="padding:10px 14px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);
                        border-radius:var(--border-radius-lg);margin-bottom:16px;font-size:.82rem;color:var(--warning)">
                <i class="bi bi-exclamation-triangle-fill"></i>
                Cambiando contraseña del usuario: <strong id="resetUserName"></strong>
            </div>
            <div class="form-group">
                <label class="form-label">Nueva Contraseña <span>*</span></label>
                <div style="position:relative">
                    <input type="password" id="newPassword" class="form-ctrl"
                           placeholder="Mínimo 8 caracteres"
                           style="padding-right:40px">
                    <button type="button"
                            onclick="togglePassVis('newPassword',this)"
                            style="position:absolute;right:8px;top:50%;transform:translateY(-50%);
                                   background:none;border:none;cursor:pointer;color:var(--text-muted)">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Confirmar Contraseña <span>*</span></label>
                <input type="password" id="confirmPassword" class="form-ctrl"
                       placeholder="Repite la contraseña">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-s" onclick="cerrarModal('resetPassModal')">Cancelar</button>
            <button class="btn-p" style="background:var(--warning);border-color:var(--warning)"
                    onclick="ejecutarResetPass()">
                <i class="bi bi-key-fill"></i> Actualizar
            </button>
        </div>
    </div>
</div>

<!-- Modal: Ver perfil rápido -->
<div class="modal-bg" id="perfilModal">
    <div class="modal-panel" style="max-width:480px">
        <div class="modal-header">
            <div class="modal-title">
                <i class="bi bi-person-circle" style="color:var(--info)"></i>
                Perfil del Usuario
            </div>
            <button class="modal-close" onclick="cerrarModal('perfilModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body" id="perfilModalBody">
            <div style="text-align:center;padding:40px"><i class="bi bi-arrow-repeat" style="font-size:2rem;color:var(--text-muted);animation:spin .8s linear infinite"></i></div>
        </div>
        <div class="modal-footer">
            <a id="perfilVerLink" href="#" target="_blank" class="btn-s">
                <i class="bi bi-box-arrow-up-right"></i> Ver perfil público
            </a>
            <button class="btn-s" onclick="cerrarModal('perfilModal')">Cerrar</button>
        </div>
    </div>
</div>

<!-- Modal confirmar eliminación -->
<div class="confirm-modal" id="confirmModal">
    <div class="confirm-box">
        <div class="confirm-title">⚠️ Confirmar eliminación</div>
        <div class="confirm-body" id="confirmBody">¿Estás seguro?</div>
        <div class="confirm-acts">
            <button class="btn-s" onclick="cerrarModal('confirmModal')">Cancelar</button>
            <button class="btn-danger" onclick="ejecutarEliminar()">
                <i class="bi bi-trash-fill"></i> Eliminar
            </button>
        </div>
    </div>
</div>

<div id="toast-cnt"></div>
<input type="hidden" id="csrfField" value="<?= csrfToken() ?>">

<style>
@keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}
</style>

<script>
/* ════════════════════════════════════════════
   UTILS
════════════════════════════════════════════ */
const csrf = () => document.getElementById('csrfField')?.value ?? '';

function toggleSidebar(){ document.getElementById('adminSidebar').classList.toggle('open'); }
function closeSidebar() { document.getElementById('adminSidebar').classList.remove('open'); }

function showToast(msg, type='success', ms=4000){
    const c = document.getElementById('toast-cnt');
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    const i = {success:'bi-check-circle-fill',error:'bi-x-circle-fill',info:'bi-info-circle-fill'};
    t.innerHTML = `<i class="bi ${i[type]||'bi-info-circle-fill'}"></i>${msg}`;
    c.appendChild(t);
    setTimeout(()=>{t.style.opacity='0';t.style.transition='.3s';},ms-300);
    setTimeout(()=>t.remove(),ms);
}

async function post(data){
    const r = await fetch('<?= APP_URL ?>/admin/usuarios.php',{
        method:'POST',
        headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body:JSON.stringify({...data,csrf:csrf()})
    });
    return r.json();
}

function cerrarModal(id){
    document.getElementById(id)?.classList.remove('open');
}

document.addEventListener('keydown', e => {
    if(e.key==='Escape')
        document.querySelectorAll('.modal-bg.open,.confirm-modal.open').forEach(m=>m.classList.remove('open'));
});

/* ════════════════════════════════════════════
   TOGGLES
════════════════════════════════════════════ */
async function toggleActivo(id, cb){
    const d = await post({action:'toggle_activo',id}).catch(()=>({success:false}));
    if(!d.success){ cb.checked=!cb.checked; showToast(d.message||'Error','error'); return; }
    const row = document.getElementById('urow-'+id);
    if(row){
        const dot = row.querySelector('.status-dot');
        const lbl = dot?.nextElementSibling;
        if(dot){ dot.className = 'status-dot '+(d.valor?'on':'off'); }
        if(lbl){ lbl.textContent = d.valor?'Activo':'Inactivo'; lbl.style.color = d.valor?'var(--success)':'var(--text-muted)'; }
    }
    showToast(d.message,'success');
}

async function togglePremium(id, cb){
    const d = await post({action:'toggle_premium',id}).catch(()=>({success:false}));
    if(!d.success){ cb.checked=!cb.checked; showToast(d.message||'Error','error'); return; }
    cb.nextElementSibling.style.background = d.valor ? 'rgba(245,158,11,.9)' : '';
    showToast(d.message,'success');
}

async function toggleVerif(id, cb){
    const d = await post({action:'toggle_verificado',id}).catch(()=>({success:false}));
    if(!d.success){ cb.checked=!cb.checked; showToast(d.message||'Error','error'); return; }
    cb.nextElementSibling.style.background = d.valor ? 'var(--info)' : '';
    showToast(d.message,'info');
}

async function cambiarRol(sel){
    const id  = sel.dataset.id;
    const rol = sel.value;
    const d   = await post({action:'cambiar_rol',id:parseInt(id),rol}).catch(()=>({success:false}));
    if(!d.success){ showToast(d.message||'Error','error'); location.reload(); return; }
    showToast(d.message,'success');
}

/* ════════════════════════════════════════════
   MODAL USUARIO
════════════════════════════════════════════ */
function abrirModalUsuario(){
    document.getElementById('userId').value    = '0';
    document.getElementById('uNombre').value   = '';
    document.getElementById('uEmail').value    = '';
    document.getElementById('uPassword').value = '';
    document.getElementById('uRol').value      = 'user';
    document.getElementById('uBio').value      = '';
    document.getElementById('uCiudad').value   = '';
    document.getElementById('uWebsite').value  = '';
    document.getElementById('uTwitter').value  = '';
    document.getElementById('uInstagram').value= '';
    document.getElementById('uFacebook').value = '';
    document.getElementById('uActivo').checked    = true;
    document.getElementById('uVerificado').checked= false;
    document.getElementById('uPremium').checked   = false;
    document.getElementById('uProfileCard').style.display='none';
    document.getElementById('userModalTitle').innerHTML =
        '<i class="bi bi-person-plus-fill" style="color:var(--primary)"></i> Nuevo Usuario';
    document.getElementById('btnUserLabel').textContent = 'Crear Usuario';
    document.getElementById('passRequired').style.display='inline';
    document.getElementById('userModal').classList.add('open');
}

async function editarUsuario(id){
    const d = await post({action:'get_usuario',id}).catch(()=>({success:false}));
    if(!d.success){ showToast(d.message||'Error al cargar usuario','error'); return; }
    const u = d.usuario;

    document.getElementById('userId').value     = u.id;
    document.getElementById('uNombre').value    = u.nombre   || '';
    document.getElementById('uEmail').value     = u.email    || '';
    document.getElementById('uPassword').value  = '';
    document.getElementById('uRol').value       = u.rol      || 'user';
    document.getElementById('uBio').value       = u.bio      || '';
    document.getElementById('uCiudad').value    = u.ciudad   || '';
    document.getElementById('uWebsite').value   = u.website  || '';
    document.getElementById('uTwitter').value   = u.twitter  || '';
    document.getElementById('uInstagram').value = u.instagram|| '';
    document.getElementById('uFacebook').value  = u.facebook || '';
    document.getElementById('uActivo').checked    = !!parseInt(u.activo);
    document.getElementById('uVerificado').checked= !!parseInt(u.verificado);
    document.getElementById('uPremium').checked   = !!parseInt(u.premium);

    // Mostrar perfil card
    const card     = document.getElementById('uProfileCard');
    const avatarEl = document.getElementById('uProfileAvatar');
    const nameEl   = document.getElementById('uProfileName');
    const emailEl  = document.getElementById('uProfileEmail');
    const statsEl  = document.getElementById('uProfileStats');
    if(card){ card.style.display='flex'; }
    if(avatarEl && u.avatar) avatarEl.src = u.avatar.startsWith('http') ? u.avatar : '<?= APP_URL ?>/' + u.avatar;
    if(nameEl) nameEl.textContent = u.nombre;
    if(emailEl) emailEl.textContent = u.email;
    if(statsEl) statsEl.innerHTML = `
        <div><div class="uprofile-stat-num">${u.total_noticias||0}</div><div class="uprofile-stat-lbl">Artículos</div></div>
        <div><div class="uprofile-stat-num">${u.total_comentarios||0}</div><div class="uprofile-stat-lbl">Comentarios</div></div>
        <div><div class="uprofile-stat-num">${u.total_seguidores||0}</div><div class="uprofile-stat-lbl">Seguidores</div></div>
        <div><div class="uprofile-stat-num">${u.reputacion||0}</div><div class="uprofile-stat-lbl">Reputación</div></div>
    `;

    document.getElementById('passRequired').style.display='none';
    document.getElementById('userModalTitle').innerHTML =
        '<i class="bi bi-pencil-fill" style="color:var(--info)"></i> Editar Usuario';
    document.getElementById('btnUserLabel').textContent = 'Actualizar';
    document.getElementById('userModal').classList.add('open');
}

async function guardarUsuario(){
    const id     = parseInt(document.getElementById('userId').value)||0;
    const nombre = document.getElementById('uNombre').value.trim();
    const email  = document.getElementById('uEmail').value.trim();
    const pass   = document.getElementById('uPassword').value.trim();

    if(!nombre){ showToast('El nombre es obligatorio.','error'); return; }
    if(!email)  { showToast('El email es obligatorio.','error'); return; }
    if(!id && !pass){ showToast('La contraseña es obligatoria para nuevos usuarios.','error'); return; }

    const btn      = document.getElementById('btnGuardarUser');
    const origText = btn.innerHTML;
    btn.disabled   = true;
    btn.innerHTML  = '<i class="bi bi-arrow-repeat" style="animation:spin .8s linear infinite"></i> Guardando...';

    try {
        const d = await post({
            action:    'guardar_usuario',
            id,
            nombre,
            email,
            password:  pass,
            rol:       document.getElementById('uRol').value,
            activo:    document.getElementById('uActivo').checked?1:0,
            verificado:document.getElementById('uVerificado').checked?1:0,
            premium:   document.getElementById('uPremium').checked?1:0,
            bio:       document.getElementById('uBio').value.trim(),
            ciudad:    document.getElementById('uCiudad').value.trim(),
            website:   document.getElementById('uWebsite').value.trim(),
            twitter:   document.getElementById('uTwitter').value.trim(),
            instagram: document.getElementById('uInstagram').value.trim(),
            facebook:  document.getElementById('uFacebook').value.trim(),
        });
        if(d.success){
            showToast('✅ '+d.message,'success');
            cerrarModal('userModal');
            setTimeout(()=>location.reload(),1200);
        } else {
            showToast('❌ '+d.message,'error');
        }
    } catch {
        showToast('Error de conexión.','error');
    } finally {
        btn.disabled  = false;
        btn.innerHTML = origText;
    }
}

/* ════════════════════════════════════════════
   RESET PASS
════════════════════════════════════════════ */
function abrirResetPass(id, nombre){
    document.getElementById('resetUserId').value   = id;
    document.getElementById('resetUserName').textContent = nombre;
    document.getElementById('newPassword').value    = '';
    document.getElementById('confirmPassword').value= '';
    document.getElementById('resetPassModal').classList.add('open');
}

async function ejecutarResetPass(){
    const id    = parseInt(document.getElementById('resetUserId').value)||0;
    const pass  = document.getElementById('newPassword').value.trim();
    const conf  = document.getElementById('confirmPassword').value.trim();
    if(pass.length < 8){ showToast('Mínimo 8 caracteres.','error'); return; }
    if(pass !== conf)  { showToast('Las contraseñas no coinciden.','error'); return; }
    const d = await post({action:'reset_password',id,password:pass}).catch(()=>({success:false}));
    if(d.success){ showToast('✅ '+d.message,'success'); cerrarModal('resetPassModal'); }
    else { showToast('❌ '+d.message,'error'); }
}

/* ════════════════════════════════════════════
   VER PERFIL
════════════════════════════════════════════ */
async function verPerfil(id){
    document.getElementById('perfilModal').classList.add('open');
    document.getElementById('perfilModalBody').innerHTML =
        '<div style="text-align:center;padding:40px"><i class="bi bi-arrow-repeat" style="font-size:2rem;color:var(--text-muted);animation:spin .8s linear infinite"></i></div>';
    const d = await post({action:'get_usuario',id}).catch(()=>({success:false}));
    if(!d.success){ document.getElementById('perfilModalBody').innerHTML='<p style="color:var(--danger);text-align:center">Error al cargar.</p>'; return; }
    const u = d.usuario;
    const av = u.avatar ? (u.avatar.startsWith('http')?u.avatar:'<?= APP_URL ?>/'+u.avatar) : '<?= APP_URL ?>/assets/images/default-avatar.png';
    document.getElementById('perfilVerLink').href = '<?= APP_URL ?>/perfil.php?slug=' + (u.slug_perfil||u.id);
    document.getElementById('perfilModalBody').innerHTML = `
        <div class="uprofile-card" style="flex-direction:column;align-items:center;text-align:center">
            <img src="${av}" class="uprofile-avatar" style="width:80px;height:80px" alt="${u.nombre}" onerror="this.src='<?= APP_URL ?>/assets/images/default-avatar.png'">
            <div style="color:#fff">
                <div style="font-size:1.1rem;font-weight:800">${u.nombre}</div>
                <div style="font-size:.8rem;opacity:.7">${u.email}</div>
                <div style="margin-top:8px">
                    <span style="background:rgba(255,255,255,.15);padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700">${u.rol.replace('_',' ')}</span>
                </div>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px">
            ${[['total_noticias','Artículos','bi-newspaper','var(--primary)'],
               ['total_comentarios','Comentarios','bi-chat-dots','var(--info)'],
               ['total_seguidores','Seguidores','bi-people','var(--success)'],
               ['reputacion','Reputación','bi-star-fill','var(--warning)']].map(([k,l,ic,c])=>
                `<div style="background:var(--bg-surface-2);border-radius:var(--border-radius-lg);padding:14px;text-align:center">
                    <i class="bi ${ic}" style="color:${c};font-size:1.2rem;margin-bottom:4px;display:block"></i>
                    <div style="font-size:1.2rem;font-weight:900;color:var(--text-primary)">${u[k]||0}</div>
                    <div style="font-size:.72rem;color:var(--text-muted)">${l}</div>
                </div>`).join('')}
        </div>
        ${u.bio?`<div style="margin-top:14px;padding:12px;background:var(--bg-surface-2);border-radius:var(--border-radius-lg);font-size:.83rem;color:var(--text-secondary);line-height:1.6">${u.bio}</div>`:''}
        <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
            ${u.website?`<a href="${u.website}" target="_blank" style="display:flex;align-items:center;gap:5px;padding:6px 12px;border:1px solid var(--border-color);border-radius:var(--border-radius-full);font-size:.75rem;text-decoration:none;color:var(--text-muted)"><i class="bi bi-globe"></i>Web</a>`:''}
            ${u.twitter?`<a href="https://twitter.com/${u.twitter}" target="_blank" style="display:flex;align-items:center;gap:5px;padding:6px 12px;border:1px solid var(--border-color);border-radius:var(--border-radius-full);font-size:.75rem;text-decoration:none;color:var(--text-muted)"><i class="bi bi-twitter-x"></i>Twitter</a>`:''}
            ${u.instagram?`<a href="https://instagram.com/${u.instagram}" target="_blank" style="display:flex;align-items:center;gap:5px;padding:6px 12px;border:1px solid var(--border-color);border-radius:var(--border-radius-full);font-size:.75rem;text-decoration:none;color:var(--text-muted)"><i class="bi bi-instagram"></i>Instagram</a>`:''}
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:14px;font-size:.78rem;color:var(--text-muted)">
            <div>📅 Registro: <strong>${new Date(u.fecha_registro).toLocaleDateString('es-DO')}</strong></div>
            <div>🕐 Último acceso: <strong>${u.ultimo_acceso?new Date(u.ultimo_acceso).toLocaleDateString('es-DO'):'Nunca'}</strong></div>
            <div>📧 Email ${u.email_verificado?'✅ verificado':'❌ no verificado'}</div>
            <div>🏆 Nivel: <strong>${u.nivel||1}</strong></div>
        </div>
    `;
}

/* ════════════════════════════════════════════
   ELIMINAR
════════════════════════════════════════════ */
let _deleteId = null;

function confirmarEliminar(id, nombre){
    _deleteId = id;
    document.getElementById('confirmBody').innerHTML =
        `¿Eliminar/anonimizar al usuario <strong>"${nombre}"</strong>?<br>
         <small style="color:var(--danger)">Sus publicaciones y comentarios serán anonimizados.</small>`;
    document.getElementById('confirmModal').classList.add('open');
}

async function ejecutarEliminar(){
    if(!_deleteId) return;
    const id = _deleteId;
    cerrarModal('confirmModal');
    _deleteId = null;
    const d = await post({action:'eliminar',id}).catch(()=>({success:false}));
    if(d.success){
        const row = document.getElementById('urow-'+id);
        if(row){ row.style.opacity='0'; row.style.transition='.3s'; setTimeout(()=>row.remove(),300); }
        showToast('🗑️ '+d.message,'success');
    } else {
        showToast('❌ '+d.message,'error');
    }
}

/* ════════════════════════════════════════════
   DESBLOQUEAR
════════════════════════════════════════════ */
async function desbloquear(id){
    const d = await post({action:'desbloquear',id}).catch(()=>({success:false}));
    if(d.success){ showToast('🔓 '+d.message,'success'); setTimeout(()=>location.reload(),1500); }
    else showToast('❌ '+d.message,'error');
}

/* ════════════════════════════════════════════
   HELPER: ver/ocultar contraseña
════════════════════════════════════════════ */
function togglePassVis(fieldId, btn){
    const inp = document.getElementById(fieldId);
    const ic  = btn.querySelector('i');
    if(!inp) return;
    if(inp.type==='password'){
        inp.type='text';
        if(ic) ic.className='bi bi-eye-slash';
    } else {
        inp.type='password';
        if(ic) ic.className='bi bi-eye';
    }
}

/* Indicador de fortaleza de contraseña */
document.addEventListener('DOMContentLoaded',()=>{
    const passEl = document.getElementById('uPassword');
    const strEl  = document.getElementById('passStrength');
    if(passEl && strEl){
        passEl.addEventListener('input',()=>{
            const v = passEl.value;
            if(!v){ strEl.textContent=''; return; }
            let score = 0;
            if(v.length>=8) score++;
            if(v.length>=12) score++;
            if(/[A-Z]/.test(v)) score++;
            if(/[0-9]/.test(v)) score++;
            if(/[^A-Za-z0-9]/.test(v)) score++;
            const labels = ['','Muy débil','Débil','Regular','Fuerte','Muy fuerte'];
            const colors = ['','#ef4444','#f97316','#eab308','#22c55e','#16a34a'];
            strEl.textContent = labels[score] || '';
            strEl.style.color = colors[score] || '';
        });
    }
});
</script>
</body>
</html>