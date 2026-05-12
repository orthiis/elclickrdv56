<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Admin: Moderación de Comentarios
 * ============================================================
 * Archivo  : admin/comentarios.php
 * Versión  : 2.1.0
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$auth->requireAdmin();
$usuario = currentUser();

// ============================================================
// MANEJADOR AJAX
// ============================================================
$isAjaxReq = (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
);

if ($isAjaxReq) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $raw   = file_get_contents('php://input');
    $input = [];
    if (!empty($raw)) {
        $dec = json_decode($raw, true);
        $input = (json_last_error() === JSON_ERROR_NONE) ? $dec : [];
    }
    $input  = array_merge($_POST, $input ?? []);
    $accion = trim($input['accion'] ?? $_GET['accion'] ?? '');
    $id     = (int)($input['id'] ?? $_GET['id'] ?? 0);

    // Verificar CSRF en escritura
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tok = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$auth->verifyCSRF($tok)) {
            echo json_encode(['success' => false, 'message' => 'Token CSRF inválido.']);
            exit;
        }
    }

    switch ($accion) {

        // ── VER ───────────────────────────────────────────────
        case 'ver':
            if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            $com = db()->fetchOne(
                "SELECT co.*, u.nombre AS u_nombre, u.email AS u_email,
                        u.avatar AS u_avatar, u.premium AS u_premium, u.rol AS u_rol,
                        n.titulo AS n_titulo, n.slug AS n_slug,
                        p.comentario AS p_texto,
                        pu.nombre    AS p_autor
                 FROM comentarios co
                 INNER JOIN usuarios u  ON u.id  = co.usuario_id
                 INNER JOIN noticias n  ON n.id  = co.noticia_id
                 LEFT  JOIN comentarios p  ON p.id  = co.padre_id
                 LEFT  JOIN usuarios    pu ON pu.id = p.usuario_id
                 WHERE co.id = ? LIMIT 1",
                [$id]
            );
            if (!$com) { echo json_encode(['success'=>false,'message'=>'No encontrado.']); exit; }
            $com['total_respuestas'] = (int)db()->count("SELECT COUNT(*) FROM comentarios WHERE padre_id = ?", [$id]);
            $com['u_avatar_url']     = getImageUrl($com['u_avatar'] ?? '', 'avatar');
            echo json_encode(['success'=>true,'data'=>$com]);
            exit;

        // ── APROBAR ───────────────────────────────────────────
        case 'aprobar':
            if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            db()->execute("UPDATE comentarios SET aprobado = 1 WHERE id = ?", [$id]);
            logActividad((int)$usuario['id'], 'aprobar_comentario', 'comentarios', $id, "Aprobó #$id");
            echo json_encode(['success'=>true,'message'=>'Comentario aprobado.']);
            exit;

        // ── DESPUBLICAR ───────────────────────────────────────
        case 'despublicar':
            if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            db()->execute("UPDATE comentarios SET aprobado = 0 WHERE id = ?", [$id]);
            logActividad((int)$usuario['id'], 'despublicar_comentario', 'comentarios', $id, "Despublicó #$id");
            echo json_encode(['success'=>true,'message'=>'Comentario despublicado.']);
            exit;

        // ── EDITAR ────────────────────────────────────────────
        case 'editar':
            if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            $nuevo = trim($input['comentario'] ?? '');
            if (mb_strlen($nuevo) < 3)  { echo json_encode(['success'=>false,'message'=>'Mínimo 3 caracteres.']); exit; }
            if (mb_strlen($nuevo) > 2000){ echo json_encode(['success'=>false,'message'=>'Máximo 2000 caracteres.']); exit; }
            db()->execute(
                "UPDATE comentarios SET comentario = ?, editado = 1, fecha_edicion = NOW() WHERE id = ?",
                [$nuevo, $id]
            );
            logActividad((int)$usuario['id'], 'editar_comentario', 'comentarios', $id, "Editó #$id");
            echo json_encode(['success'=>true,'message'=>'Comentario editado.','texto'=>$nuevo]);
            exit;

        // ── RESPONDER ─────────────────────────────────────────
        case 'responder':
            if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            $txt = trim($input['respuesta'] ?? '');
            if (mb_strlen($txt) < 3) { echo json_encode(['success'=>false,'message'=>'Mínimo 3 caracteres.']); exit; }
            $padre = db()->fetchOne("SELECT noticia_id FROM comentarios WHERE id = ? LIMIT 1", [$id]);
            if (!$padre) { echo json_encode(['success'=>false,'message'=>'Comentario padre no existe.']); exit; }
            $newId = db()->insert(
                "INSERT INTO comentarios (noticia_id, usuario_id, padre_id, comentario, aprobado, fecha)
                 VALUES (?, ?, ?, ?, 1, NOW())",
                [(int)$padre['noticia_id'], (int)$usuario['id'], $id, $txt]
            );
            logActividad((int)$usuario['id'], 'responder_comentario', 'comentarios', $newId, "Respondió a #$id");
            echo json_encode(['success'=>true,'message'=>'Respuesta publicada.','id'=>$newId]);
            exit;

        // ── ELIMINAR ──────────────────────────────────────────
        case 'eliminar':
            if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
            db()->execute("DELETE FROM comentarios WHERE id = ?", [$id]);
            logActividad((int)$usuario['id'], 'eliminar_comentario', 'comentarios', $id, "Eliminó #$id");
            echo json_encode(['success'=>true,'message'=>'Comentario eliminado.']);
            exit;

        // ── BULK ──────────────────────────────────────────────
        case 'bulk':
            $ids     = array_filter(array_map('intval', $input['ids'] ?? []));
            $bulkAct = trim($input['bulk_accion'] ?? '');
            if (empty($ids)) { echo json_encode(['success'=>false,'message'=>'Sin selección.']); exit; }
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $cnt = count($ids);
            switch ($bulkAct) {
                case 'aprobar':
                    db()->execute("UPDATE comentarios SET aprobado = 1 WHERE id IN ($ph)", $ids);
                    $msg = "$cnt comentario(s) aprobado(s)."; break;
                case 'rechazar':
                    db()->execute("UPDATE comentarios SET aprobado = 0 WHERE id IN ($ph)", $ids);
                    $msg = "$cnt comentario(s) rechazado(s)."; break;
                case 'spam':
                    db()->execute("UPDATE comentarios SET aprobado = 0, reportado = 1 WHERE id IN ($ph)", $ids);
                    $msg = "$cnt marcado(s) como spam."; break;
                case 'eliminar':
                    db()->execute("DELETE FROM comentarios WHERE id IN ($ph)", $ids);
                    $msg = "$cnt comentario(s) eliminado(s)."; break;
                default:
                    echo json_encode(['success'=>false,'message'=>'Acción inválida.']); exit;
            }
            logActividad((int)$usuario['id'], "bulk_{$bulkAct}", 'comentarios', null, "$msg");
            echo json_encode(['success'=>true,'message'=>$msg]);
            exit;

        default:
            echo json_encode(['success'=>false,'message'=>'Acción desconocida.']);
            exit;
    }
}

// ============================================================
// ESTADÍSTICAS
// ============================================================
$statsTotal     = (int)db()->count("SELECT COUNT(*) FROM comentarios");
$statsAprobados = (int)db()->count("SELECT COUNT(*) FROM comentarios WHERE aprobado = 1");
$statsPendientes= (int)db()->count("SELECT COUNT(*) FROM comentarios WHERE aprobado = 0 AND reportado = 0");
$statsReportados= (int)db()->count("SELECT COUNT(*) FROM comentarios WHERE reportado = 1");
$statsHoy       = (int)db()->count("SELECT COUNT(*) FROM comentarios WHERE DATE(fecha) = CURDATE()");
$statsSemana    = (int)db()->count("SELECT COUNT(*) FROM comentarios WHERE fecha >= NOW() - INTERVAL 7 DAY");
$statsSpam      = (int)db()->count("SELECT COUNT(*) FROM comentarios WHERE reportes >= 3");
$statsSemAnt    = (int)db()->count("SELECT COUNT(*) FROM comentarios WHERE fecha >= NOW() - INTERVAL 14 DAY AND fecha < NOW() - INTERVAL 7 DAY");
$tendencia      = $statsSemAnt > 0 ? round((($statsSemana - $statsSemAnt) / $statsSemAnt) * 100, 1) : 0;

// ============================================================
// GRÁFICO ACTIVIDAD 14 DÍAS
// ============================================================
$actRaw = db()->fetchAll(
    "SELECT DATE(fecha) AS dia,
            COUNT(*) AS total,
            SUM(CASE WHEN aprobado=1 THEN 1 ELSE 0 END) AS aprobados,
            SUM(CASE WHEN aprobado=0 THEN 1 ELSE 0 END) AS pendientes
     FROM comentarios
     WHERE fecha >= NOW() - INTERVAL 14 DAY
     GROUP BY DATE(fecha) ORDER BY dia ASC",
    []
);
$actMap = [];
foreach ($actRaw as $r) $actMap[$r['dia']] = $r;
$chartLabels = $chartAprov = $chartPend = $chartTotal = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chartLabels[] = date('d/m', strtotime($d));
    $chartAprov[]  = (int)($actMap[$d]['aprobados']  ?? 0);
    $chartPend[]   = (int)($actMap[$d]['pendientes'] ?? 0);
    $chartTotal[]  = (int)($actMap[$d]['total']      ?? 0);
}

// ============================================================
// TOP NOTICIAS COMENTADAS (30 días)
// ============================================================
$topNoticias = db()->fetchAll(
    "SELECT n.id, n.titulo, n.slug,
            c.nombre AS cat_nombre, c.color AS cat_color,
            COUNT(co.id) AS total_com,
            SUM(CASE WHEN co.aprobado=1 THEN 1 ELSE 0 END) AS aprobados_com
     FROM noticias n
     INNER JOIN comentarios co ON co.noticia_id = n.id
     LEFT  JOIN categorias  c  ON c.id = n.categoria_id
     WHERE n.estado = 'publicado' AND co.fecha >= NOW() - INTERVAL 30 DAY
     GROUP BY n.id ORDER BY total_com DESC LIMIT 5",
    []
);

// ============================================================
// TOP COMENTARISTAS
// ============================================================
$topComent = db()->fetchAll(
    "SELECT u.id, u.nombre, u.avatar, u.premium,
            COUNT(co.id)  AS total_com,
            SUM(co.likes) AS total_likes,
            SUM(CASE WHEN co.aprobado=1 THEN 1 ELSE 0 END) AS com_aprobados,
            SUM(CASE WHEN co.reportado=1 THEN 1 ELSE 0 END) AS com_reportados,
            MAX(co.fecha) AS ultimo
     FROM usuarios u
     INNER JOIN comentarios co ON co.usuario_id = u.id
     GROUP BY u.id ORDER BY total_com DESC LIMIT 8",
    []
);

// ============================================================
// FILTROS Y PAGINACIÓN
// ============================================================
$fEstado = trim($_GET['estado'] ?? '');
$fBusq   = trim($_GET['q']      ?? '');
$fTipo   = trim($_GET['tipo']   ?? '');
$fOrden  = trim($_GET['orden']  ?? 'fecha_desc');
$fDesde  = trim($_GET['desde']  ?? '');
$fHasta  = trim($_GET['hasta']  ?? '');
$porPag  = 25;
$pagAct  = max(1, (int)($_GET['pag'] ?? 1));
$offset  = ($pagAct - 1) * $porPag;

$where = ['1=1']; $params = [];

if ($fEstado === 'pendiente')  { $where[] = 'co.aprobado = 0 AND co.reportado = 0'; }
elseif ($fEstado === 'aprobado')  { $where[] = 'co.aprobado = 1'; }
elseif ($fEstado === 'reportado') { $where[] = 'co.reportado = 1'; }
elseif ($fEstado === 'spam')      { $where[] = 'co.reportes >= 3'; }

if (!empty($fBusq)) {
    $b = '%'.$fBusq.'%';
    $where[]  = '(co.comentario LIKE ? OR u.nombre LIKE ? OR n.titulo LIKE ?)';
    $params   = array_merge($params, [$b,$b,$b]);
}
if ($fTipo === 'respuesta')  { $where[] = 'co.padre_id IS NOT NULL'; }
elseif ($fTipo === 'principal') { $where[] = 'co.padre_id IS NULL'; }
if (!empty($fDesde) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDesde)) { $where[] = 'DATE(co.fecha) >= ?'; $params[] = $fDesde; }
if (!empty($fHasta) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fHasta)) { $where[] = 'DATE(co.fecha) <= ?'; $params[] = $fHasta; }

$orderMap = [
    'fecha_desc'=>'co.fecha DESC','fecha_asc'=>'co.fecha ASC',
    'votos_desc'=>'(co.votos_pos+co.likes) DESC','reportes_desc'=>'co.reportes DESC',
];
$orderBy  = $orderMap[$fOrden] ?? 'co.fecha DESC';
$whereSQL = implode(' AND ', $where);

$totalCom = (int)db()->count(
    "SELECT COUNT(co.id) FROM comentarios co
     INNER JOIN usuarios u ON u.id = co.usuario_id
     INNER JOIN noticias n ON n.id = co.noticia_id
     WHERE $whereSQL",
    $params
);
$pQuery   = array_merge($params, [$porPag, $offset]);
$coms     = db()->fetchAll(
    "SELECT co.id, co.comentario, co.aprobado, co.reportado, co.editado,
            co.votos_pos, co.votos_neg, co.likes, co.dislikes, co.reportes,
            co.padre_id, co.fecha, co.fecha_edicion,
            u.id AS uid, u.nombre AS u_nombre, u.avatar AS u_avatar, u.premium AS u_premium,
            n.id AS nid, n.titulo AS n_titulo, n.slug AS n_slug
     FROM comentarios co
     INNER JOIN usuarios u ON u.id = co.usuario_id
     INNER JOIN noticias n ON n.id = co.noticia_id
     WHERE $whereSQL ORDER BY $orderBy LIMIT ? OFFSET ?",
    $pQuery
);
$totalPags = (int)ceil($totalCom / $porPag);
$qpBase    = array_filter(['estado'=>$fEstado,'q'=>$fBusq,'tipo'=>$fTipo,'orden'=>$fOrden!=='fecha_desc'?$fOrden:'','desde'=>$fDesde,'hasta'=>$fHasta]);

$pageTitle = 'Comentarios — Panel Admin';
require_once __DIR__ . '/sidebar.php';
?>
<!DOCTYPE html>
<html lang="es" data-theme="<?= e(Config::get('apariencia_modo_oscuro','auto')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= APP_VERSION ?>">
<style>
/* ====================================================================
   ADMIN BASE LAYOUT  (idéntico a noticias.php / anuncios.php)
==================================================================== */
body{padding-bottom:0;background:var(--bg-body)}
.admin-wrapper{display:flex;min-height:100vh}

/* ── SIDEBAR — SIEMPRE OSCURO sin importar el tema ── */
.admin-sidebar{
    width:260px;background:var(--secondary-dark);
    position:fixed;top:0;left:0;height:100vh;overflow-y:auto;
    z-index:var(--z-header);transition:transform var(--transition-base);
    display:flex;flex-direction:column
}
/* Forzar fondo oscuro para TODOS los temas */
[data-theme] .admin-sidebar{background:#0f1f33!important}

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
.admin-sidebar__footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,.07);margin-top:auto}
.admin-nav{flex:1;padding:12px 0;overflow-y:auto}
.admin-nav__section{padding:14px 20px 6px;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.25)}
.admin-nav__item{display:flex;align-items:center;gap:10px;padding:10px 20px;color:rgba(255,255,255,.6);font-size:.82rem;font-weight:500;text-decoration:none;transition:all var(--transition-fast);position:relative}
.admin-nav__item:hover{background:rgba(255,255,255,.06);color:rgba(255,255,255,.9)}
.admin-nav__item.active{background:rgba(230,57,70,.18);color:#fff;font-weight:600}
.admin-nav__item.active::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--primary);border-radius:0 3px 3px 0}
.admin-nav__item i{width:18px;text-align:center;font-size:.9rem}
.admin-nav__badge{margin-left:auto;background:var(--danger);color:#fff;font-size:.6rem;font-weight:800;min-width:18px;height:18px;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 5px}

/* ── MAIN ── */
.admin-main{margin-left:260px;flex:1;min-height:100vh;display:flex;flex-direction:column}
.admin-topbar{background:var(--bg-surface);border-bottom:1px solid var(--border-color);padding:0 28px;height:62px;display:flex;align-items:center;gap:16px;position:sticky;top:0;z-index:var(--z-sticky);box-shadow:var(--shadow-sm)}
.admin-topbar__toggle{display:none;color:var(--text-muted);font-size:1.2rem;padding:6px;border-radius:var(--border-radius-sm);background:none;border:none;cursor:pointer;transition:all var(--transition-fast)}
.admin-topbar__toggle:hover{background:var(--bg-surface-2)}
.admin-topbar__title{font-family:var(--font-serif);font-size:1.1rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px}
.admin-topbar__right{margin-left:auto;display:flex;align-items:center;gap:10px}
.admin-content{padding:28px;flex:1}
.topbar-ico-btn{display:flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:var(--border-radius);background:var(--bg-surface-2);border:1px solid var(--border-color);color:var(--text-muted);cursor:pointer;transition:all var(--transition-fast);text-decoration:none}
.topbar-ico-btn:hover{background:var(--bg-surface-3);color:var(--text-primary)}

/* ── OVERLAY MÓVIL ── */
.admin-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:calc(var(--z-header) - 1);opacity:0;pointer-events:none;transition:opacity .3s}
.admin-overlay.show{opacity:1;pointer-events:auto}

/* ====================================================================
   CARDS DE ESTADÍSTICAS
==================================================================== */
.stats-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:24px}
.sc{background:var(--bg-surface);border:1.5px solid var(--border-color);border-radius:var(--border-radius-xl);padding:16px 14px 12px;display:flex;flex-direction:column;gap:6px;text-decoration:none;transition:all .2s;position:relative;overflow:hidden}
.sc::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;background:var(--cc,var(--primary));transform:scaleX(0);transition:transform .25s}
.sc:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
.sc:hover::after,.sc.on::after{transform:scaleX(1)}
.sc.on{border-color:var(--cc,var(--primary))}
.sc__ico{width:36px;height:36px;border-radius:var(--border-radius);display:flex;align-items:center;justify-content:center;font-size:1.1rem;background:color-mix(in srgb,var(--cc,var(--primary)) 12%,transparent);color:var(--cc,var(--primary))}
.sc__val{font-size:1.65rem;font-weight:900;color:var(--text-primary);line-height:1}
.sc__lbl{font-size:.7rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.05em}
.sc__sub{font-size:.68rem;color:var(--text-muted)}
.tu{color:var(--success)}.td{color:var(--danger)}.tn{color:var(--text-muted)}

/* ====================================================================
   ADMIN CARD
==================================================================== */
.acard{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--border-radius-xl);box-shadow:var(--shadow-sm);overflow:hidden;margin-bottom:20px}
.acard__hdr{padding:14px 20px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;gap:12px}
.acard__ttl{font-size:.875rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px}
.acard__lnk{font-size:.75rem;color:var(--primary);font-weight:600;text-decoration:none;display:flex;align-items:center;gap:4px}
.acard__lnk:hover{opacity:.75;color:var(--primary)}
.acard__body{padding:16px 20px}

/* ====================================================================
   GRID SUPERIOR
==================================================================== */
.top-grid{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:20px}

/* ====================================================================
   TABS FILTRO
==================================================================== */
.ftabs{display:flex;gap:6px;flex-wrap:wrap;padding:14px 20px 0;border-bottom:1px solid var(--border-color)}
.ftab{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:1.5px solid var(--border-color);border-radius:var(--border-radius-full) var(--border-radius-full) 0 0;font-size:.8rem;font-weight:600;cursor:pointer;text-decoration:none;color:var(--text-secondary);background:var(--bg-surface);transition:all .2s;margin-bottom:-1px}
.ftab:hover{border-color:var(--primary);color:var(--primary)}
.ftab.on{background:var(--primary);border-color:var(--primary);color:#fff}
.ftab .cnt{background:rgba(255,255,255,.25);padding:1px 7px;border-radius:999px;font-size:.66rem;font-weight:800;min-width:18px;text-align:center}
.ftab:not(.on) .cnt{background:var(--bg-surface-2);color:var(--text-muted)}

/* ====================================================================
   BARRA BÚSQUEDA / FILTROS
==================================================================== */
.fbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:14px 20px}
.si-wrap{position:relative;flex:1;min-width:180px}
.si-wrap i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.85rem;pointer-events:none}
.si{width:100%;padding:9px 12px 9px 32px;border:1.5px solid var(--border-color);border-radius:var(--border-radius-lg);font-size:.83rem;background:var(--bg-surface);color:var(--text-primary);transition:border-color .2s}
.si:focus{outline:none;border-color:var(--primary)}
.fsel{padding:9px 12px;border:1.5px solid var(--border-color);border-radius:var(--border-radius-lg);font-size:.82rem;background:var(--bg-surface);color:var(--text-secondary);cursor:pointer}
.fsel:focus{outline:none;border-color:var(--primary)}
.fdate{padding:9px 12px;border:1.5px solid var(--border-color);border-radius:var(--border-radius-lg);font-size:.82rem;background:var(--bg-surface);color:var(--text-secondary)}
.fdate:focus{outline:none;border-color:var(--primary)}
.btn-prim{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;background:var(--primary);color:#fff;border:none;border-radius:var(--border-radius-lg);font-size:.82rem;font-weight:700;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-prim:hover{background:var(--primary-dark);transform:translateY(-1px)}
.btn-sec{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border:1.5px solid var(--border-color);border-radius:var(--border-radius-lg);font-size:.82rem;font-weight:600;background:transparent;color:var(--text-muted);text-decoration:none;cursor:pointer;transition:all .2s}
.btn-sec:hover{background:var(--bg-surface-2);color:var(--text-primary)}

/* ── BULK BAR ── */
.bbar{display:none;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 14px;background:rgba(59,130,246,.07);border:1.5px solid rgba(59,130,246,.2);border-radius:var(--border-radius-lg);margin:0 20px 14px}
.bbar.show{display:flex}
.bbar span{font-size:.82rem;font-weight:700;color:var(--info)}
.bulk-btn{padding:6px 12px;border-radius:var(--border-radius-sm);border:none;font-size:.78rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:all .15s}
.bulk-btn:hover{transform:translateY(-1px)}
.bb-green{background:rgba(34,197,94,.15);color:var(--success)}
.bb-yellow{background:rgba(245,158,11,.15);color:var(--warning)}
.bb-purple{background:rgba(139,92,246,.15);color:#8b5cf6}
.bb-red{background:rgba(239,68,68,.15);color:var(--danger)}
.bb-gray{background:var(--bg-surface-2);border:1px solid var(--border-color);color:var(--text-muted)}

/* ====================================================================
   TABLA
==================================================================== */
.tbl-wrap{overflow-x:auto}
.atbl{width:100%;border-collapse:collapse;font-size:.82rem}
.atbl thead th{padding:10px 14px;text-align:left;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);background:var(--bg-surface-2);border-bottom:1.5px solid var(--border-color);white-space:nowrap}
.atbl tbody td{padding:11px 14px;border-bottom:1px solid var(--border-color);vertical-align:middle;color:var(--text-secondary)}
.atbl tbody tr:hover td{background:var(--bg-surface-2)}
.atbl tbody tr:last-child td{border-bottom:none}

/* badges */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:.67rem;font-weight:700}
.b-green{background:rgba(34,197,94,.12);color:var(--success)}
.b-yellow{background:rgba(245,158,11,.12);color:var(--warning)}
.b-red{background:rgba(239,68,68,.12);color:var(--danger)}
.b-purple{background:rgba(139,92,246,.12);color:#8b5cf6}
.b-gray{background:var(--bg-surface-3);color:var(--text-muted)}

/* comment text */
.ctxt{max-width:270px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.45;color:var(--text-primary)}
.creply{display:inline-flex;align-items:center;gap:3px;font-size:.66rem;color:var(--text-muted);margin-bottom:2px}

/* action buttons */
.abtn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:var(--border-radius-sm);border:1px solid var(--border-color);background:var(--bg-surface);cursor:pointer;font-size:.82rem;transition:all .15s;text-decoration:none}
.abtn:hover{transform:translateY(-1px);box-shadow:var(--shadow-sm)}
.ab-view {color:var(--info);   border-color:rgba(59,130,246,.25)}.ab-view:hover {background:rgba(59,130,246,.1)}
.ab-appr {color:var(--success);border-color:rgba(34,197,94,.25)} .ab-appr:hover {background:rgba(34,197,94,.1)}
.ab-unp  {color:var(--warning);border-color:rgba(245,158,11,.25)}.ab-unp:hover  {background:rgba(245,158,11,.1)}
.ab-edit {color:var(--primary);border-color:rgba(230,57,70,.25)} .ab-edit:hover {background:rgba(230,57,70,.1)}
.ab-rep  {color:var(--success);border-color:rgba(34,197,94,.25)} .ab-rep:hover  {background:rgba(34,197,94,.1)}
.ab-del  {color:var(--danger); border-color:rgba(239,68,68,.25)} .ab-del:hover  {background:rgba(239,68,68,.1)}

/* ====================================================================
   PAGINACIÓN
==================================================================== */
.pag{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-top:1px solid var(--border-color);flex-wrap:wrap;gap:10px}
.pag__info{font-size:.78rem;color:var(--text-muted)}
.pag__pages{display:flex;gap:4px}
.pg{min-width:32px;height:32px;padding:0 8px;display:flex;align-items:center;justify-content:center;border:1.5px solid var(--border-color);border-radius:var(--border-radius-sm);font-size:.78rem;font-weight:600;text-decoration:none;color:var(--text-secondary);background:var(--bg-surface);transition:all .15s}
.pg:hover{border-color:var(--primary);color:var(--primary)}
.pg.on{background:var(--primary);border-color:var(--primary);color:#fff}
.pg.off{opacity:.4;pointer-events:none}

/* ====================================================================
   TOP COMENTARISTAS
==================================================================== */
.tcg{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:6px}
.tci{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border-color)}
.tci:last-child{border-bottom:none}
.tci__rank{font-size:.9rem;font-weight:900;width:22px;text-align:center;flex-shrink:0}
.tci__av{width:34px;height:34px;border-radius:50%;object-fit:cover;border:2px solid var(--border-color);flex-shrink:0}
.tci__info{flex:1;overflow:hidden}
.tci__name{font-size:.8rem;font-weight:700;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tci__meta{font-size:.68rem;color:var(--text-muted)}
.tci__cnt{font-size:.88rem;font-weight:900;color:var(--primary);flex-shrink:0}

/* ====================================================================
   MODALES  —  PATRÓN IGUAL A noticias.php / anuncios.php
==================================================================== */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.58);z-index:9999;align-items:center;justify-content:center;padding:16px}
.modal-overlay.visible{display:flex}
.modal-box{background:var(--bg-surface);border-radius:var(--border-radius-xl);box-shadow:0 24px 80px rgba(0,0,0,.28);max-width:580px;width:100%;max-height:90vh;overflow-y:auto;animation:mIn .2s ease}
@keyframes mIn{from{opacity:0;transform:scale(.95) translateY(12px)}to{opacity:1;transform:scale(1) translateY(0)}}
.modal-box.sm{max-width:420px}
.mhdr{padding:18px 22px 14px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:10px}
.mhdr h3{font-size:.95rem;font-weight:700;color:var(--text-primary);flex:1;margin:0}
.mclose{width:28px;height:28px;border-radius:var(--border-radius-sm);border:none;background:var(--bg-surface-2);color:var(--text-muted);cursor:pointer;font-size:.8rem;display:flex;align-items:center;justify-content:center;transition:all .15s}
.mclose:hover{background:var(--bg-surface-3);color:var(--text-primary)}
.mbody{padding:20px 22px}
.mftr{padding:14px 22px;border-top:1px solid var(--border-color);display:flex;gap:10px;justify-content:flex-end}
.mlbl{font-size:.7rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
.mval{font-size:.85rem;color:var(--text-secondary);line-height:1.6}
.mquote{padding:10px 14px;background:var(--bg-surface-2);border-radius:var(--border-radius-lg);border-left:3px solid var(--border-color);font-style:italic;font-size:.83rem;color:var(--text-secondary);margin-bottom:14px;line-height:1.6}
.mtextarea{width:100%;min-height:110px;padding:10px 12px;border:1.5px solid var(--border-color);border-radius:var(--border-radius-lg);font-size:.85rem;line-height:1.6;background:var(--bg-surface);color:var(--text-primary);resize:vertical;font-family:var(--font-sans)}
.mtextarea:focus{outline:none;border-color:var(--primary)}
.mactions{display:flex;gap:8px;justify-content:flex-end;padding:14px 22px;border-top:1px solid var(--border-color)}
.btn-danger{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;background:var(--danger);color:#fff;border:none;border-radius:var(--border-radius-lg);font-size:.83rem;font-weight:700;cursor:pointer;transition:all .2s}
.btn-danger:hover{background:#dc2626}
.btn-success{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;background:var(--success);color:#fff;border:none;border-radius:var(--border-radius-lg);font-size:.83rem;font-weight:700;cursor:pointer;transition:all .2s}
.btn-success:hover{background:#16a34a}

/* ====================================================================
   TOAST
==================================================================== */
#toast-container{position:fixed;bottom:24px;right:24px;z-index:99999;display:flex;flex-direction:column;gap:8px}
.toast{padding:12px 18px;border-radius:var(--border-radius-lg);font-size:.83rem;font-weight:600;color:#fff;box-shadow:var(--shadow-xl);display:flex;align-items:center;gap:8px;animation:tIn .25s ease;max-width:340px}
@keyframes tIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}
.t-ok{background:var(--success)}.t-err{background:var(--danger)}.t-inf{background:var(--info)}

/* ====================================================================
   EMPTY STATE
==================================================================== */
.empty-st{padding:60px 20px;text-align:center}
.empty-st i{font-size:3rem;color:var(--text-muted);display:block;margin-bottom:12px}
.empty-st h3{font-size:.95rem;font-weight:700;color:var(--text-primary);margin-bottom:6px}
.empty-st p{font-size:.83rem;color:var(--text-muted)}

/* ====================================================================
   RESPONSIVE
==================================================================== */
@media(max-width:1280px){
    .stats-grid{grid-template-columns:repeat(3,1fr)}
    .top-grid{grid-template-columns:1fr}
}
@media(max-width:1024px){
    .stats-grid{grid-template-columns:repeat(3,1fr)}
}
@media(max-width:768px){
    .admin-sidebar{transform:translateX(-100%)}
    .admin-sidebar.open{transform:translateX(0);box-shadow:0 0 40px rgba(0,0,0,.4)}
    .admin-main{margin-left:0}
    .admin-topbar{padding:0 16px}
    .admin-topbar__toggle{display:flex}
    .admin-content{padding:14px}
    .stats-grid{grid-template-columns:repeat(2,1fr);gap:10px}
    .fbar{flex-direction:column;align-items:stretch}
    .si-wrap{min-width:unset}
    .ftabs{gap:4px}
    .ftab{padding:7px 10px;font-size:.74rem}
    #toast-container{bottom:70px;right:10px;left:10px}
    .toast{max-width:100%}
    .modal-box{max-height:95vh}
    .top-grid{grid-template-columns:1fr}
}
@media(max-width:480px){
    .stats-grid{grid-template-columns:repeat(2,1fr);gap:8px}
    .sc{padding:12px 10px 10px}
    .sc__val{font-size:1.4rem}
    .admin-content{padding:10px}
}
</style>
</head>
<body>
<div class="admin-wrapper">

<!-- OVERLAY MÓVIL -->
<div class="admin-overlay" id="adminOverlay" onclick="closeSidebar()"></div>

<!-- MAIN -->
<main class="admin-main" id="adminMain">

<!-- ── TOPBAR ──────────────────────────────────────────────── -->
<div class="admin-topbar">
    <button class="admin-topbar__toggle" onclick="toggleSidebar()" aria-label="Menú">
        <i class="bi bi-list"></i>
    </button>
    <h1 class="admin-topbar__title">
        <i class="bi bi-chat-dots-fill" style="color:var(--primary)"></i>
        <span>Moderación de Comentarios</span>
    </h1>
    <div class="admin-topbar__right">
        <button class="topbar-ico-btn" onclick="toggleDark()" title="Cambiar tema" id="darkBtn">
            <i class="bi bi-moon-stars-fill" id="darkIco"></i>
        </button>
        <a href="<?= APP_URL ?>/index.php" target="_blank" rel="noopener"
           class="topbar-ico-btn" title="Ver sitio">
            <i class="bi bi-box-arrow-up-right"></i>
        </a>
    </div>
</div>

<div class="admin-content">

<!-- ================================================================
     FLASH MESSAGE
================================================================ -->
<?php $flash = getFlashMessage(); if ($flash): ?>
<div style="padding:13px 18px;border-radius:var(--border-radius-lg);margin-bottom:20px;
            display:flex;align-items:center;gap:10px;
            background:<?= $flash['type']==='success'?'rgba(34,197,94,.1)':'rgba(239,68,68,.1)' ?>;
            border:1px solid <?= $flash['type']==='success'?'rgba(34,197,94,.3)':'rgba(239,68,68,.3)' ?>;
            color:<?= $flash['type']==='success'?'var(--success)':'var(--danger)' ?>">
    <i class="bi bi-<?= $flash['type']==='success'?'check-circle-fill':'exclamation-circle-fill' ?>"></i>
    <?= e($flash['message']) ?>
</div>
<?php endif; ?>

<!-- ================================================================
     BLOQUE 1 — TARJETAS ESTADÍSTICAS (6)
================================================================ -->
<div class="stats-grid">

    <!-- Total -->
    <a href="<?= APP_URL ?>/admin/comentarios.php"
       class="sc <?= $fEstado===''?'on':'' ?>" style="--cc:var(--info)">
        <div class="sc__ico"><i class="bi bi-chat-fill"></i></div>
        <div class="sc__val"><?= formatNumber($statsTotal) ?></div>
        <div class="sc__lbl">Total</div>
        <div class="sc__sub tn">Todos los comentarios</div>
    </a>

    <!-- Aprobados -->
    <a href="<?= APP_URL ?>/admin/comentarios.php?estado=aprobado"
       class="sc <?= $fEstado==='aprobado'?'on':'' ?>" style="--cc:var(--success)">
        <div class="sc__ico"><i class="bi bi-check-circle-fill"></i></div>
        <div class="sc__val"><?= formatNumber($statsAprobados) ?></div>
        <div class="sc__lbl">Aprobados</div>
        <div class="sc__sub tu">
            <?= $statsTotal>0?round(($statsAprobados/$statsTotal)*100,1):0 ?>% del total
        </div>
    </a>

    <!-- Pendientes -->
    <a href="<?= APP_URL ?>/admin/comentarios.php?estado=pendiente"
       class="sc <?= $fEstado==='pendiente'?'on':'' ?>" style="--cc:var(--warning)">
        <div class="sc__ico"><i class="bi bi-clock-fill"></i></div>
        <div class="sc__val"><?= formatNumber($statsPendientes) ?></div>
        <div class="sc__lbl">Pendientes</div>
        <div class="sc__sub <?= $statsPendientes>0?'td':'tn' ?>">
            <?= $statsPendientes>0?'Requieren revisión':'Sin pendientes' ?>
        </div>
    </a>

    <!-- Reportados -->
    <a href="<?= APP_URL ?>/admin/comentarios.php?estado=reportado"
       class="sc <?= $fEstado==='reportado'?'on':'' ?>" style="--cc:var(--danger)">
        <div class="sc__ico"><i class="bi bi-flag-fill"></i></div>
        <div class="sc__val"><?= formatNumber($statsReportados) ?></div>
        <div class="sc__lbl">Reportados</div>
        <div class="sc__sub <?= $statsReportados>0?'td':'tn' ?>">
            <?= $statsReportados>0?'Atención requerida':'Sin reportes' ?>
        </div>
    </a>

    <!-- Hoy -->
    <a href="<?= APP_URL ?>/admin/comentarios.php?desde=<?= date('Y-m-d') ?>&hasta=<?= date('Y-m-d') ?>"
       class="sc" style="--cc:var(--primary)">
        <div class="sc__ico"><i class="bi bi-calendar-check-fill"></i></div>
        <div class="sc__val"><?= formatNumber($statsHoy) ?></div>
        <div class="sc__lbl">Hoy</div>
        <div class="sc__sub tn"><i class="bi bi-clock"></i> <?= date('d/m/Y') ?></div>
    </a>

    <!-- Esta semana -->
    <a href="<?= APP_URL ?>/admin/comentarios.php?desde=<?= date('Y-m-d',strtotime('-7 days')) ?>&hasta=<?= date('Y-m-d') ?>"
       class="sc" style="--cc:#8b5cf6">
        <div class="sc__ico"><i class="bi bi-calendar-week-fill"></i></div>
        <div class="sc__val"><?= formatNumber($statsSemana) ?></div>
        <div class="sc__lbl">Esta semana</div>
        <div class="sc__sub <?= $tendencia>0?'tu':($tendencia<0?'td':'tn') ?>">
            <?php if ($tendencia>0): ?>
                <i class="bi bi-arrow-up-short"></i>+<?= $tendencia ?>% vs sem. anterior
            <?php elseif ($tendencia<0): ?>
                <i class="bi bi-arrow-down-short"></i><?= $tendencia ?>% vs sem. anterior
            <?php else: ?>
                <i class="bi bi-dash"></i> Sin variación
            <?php endif; ?>
        </div>
    </a>

</div>

<!-- ================================================================
     BLOQUE 2 — ACTIVIDAD CHART + TOP NOTICIAS
================================================================ -->
<div class="top-grid">

    <!-- Gráfico actividad 14 días -->
    <div class="acard" style="margin-bottom:0">
        <div class="acard__hdr">
            <span class="acard__ttl">
                <i class="bi bi-activity" style="color:var(--primary)"></i>
                Actividad — últimos 14 días
            </span>
            <div style="display:flex;gap:14px;align-items:center">
                <span style="display:flex;align-items:center;gap:5px;font-size:.72rem;color:var(--success)">
                    <span style="width:10px;height:10px;border-radius:50%;background:var(--success);display:inline-block"></span>Aprobados
                </span>
                <span style="display:flex;align-items:center;gap:5px;font-size:.72rem;color:var(--warning)">
                    <span style="width:10px;height:10px;border-radius:50%;background:var(--warning);display:inline-block"></span>Pendientes
                </span>
            </div>
        </div>
        <div class="acard__body">
            <canvas id="chartAct" height="130"></canvas>
        </div>
    </div>

    <!-- Top noticias comentadas -->
    <div class="acard" style="margin-bottom:0">
        <div class="acard__hdr">
            <span class="acard__ttl">
                <i class="bi bi-trophy-fill" style="color:var(--warning)"></i>
                Top noticias comentadas
            </span>
            <span style="font-size:.7rem;color:var(--text-muted)">30 días</span>
        </div>
        <div class="acard__body" style="padding:8px 16px">
            <?php if (empty($topNoticias)): ?>
            <div style="text-align:center;padding:24px;color:var(--text-muted);font-size:.82rem">
                <i class="bi bi-chat-square" style="font-size:2rem;display:block;margin-bottom:8px"></i>
                Sin actividad reciente
            </div>
            <?php else: ?>
            <?php foreach ($topNoticias as $idx => $tn): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border-color)">
                <span style="font-size:.88rem;font-weight:900;width:20px;text-align:center;
                             color:<?= $idx===0?'var(--warning)':($idx===1?'#9ca3af':($idx===2?'#cd7f32':'var(--text-muted)')) ?>">
                    <?= $idx+1 ?>
                </span>
                <div style="flex:1;overflow:hidden">
                    <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($tn['n_slug']??$tn['slug']??'') ?>"
                       target="_blank"
                       style="font-size:.78rem;font-weight:700;color:var(--text-primary);text-decoration:none;
                              display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                        <?= e(truncateChars($tn['n_titulo']??$tn['titulo']??'—', 46)) ?>
                    </a>
                    <div style="font-size:.67rem;margin-top:2px">
                        <?php if (!empty($tn['cat_nombre'])): ?>
                        <span style="color:<?= e($tn['cat_color']??'#888') ?>;font-weight:700">
                            <?= e($tn['cat_nombre']) ?>
                        </span>
                        <span style="color:var(--text-muted)"> · </span>
                        <?php endif; ?>
                        <span style="color:var(--text-muted)"><?= formatNumber((int)($tn['aprobados_com']??0)) ?> aprobados</span>
                    </div>
                </div>
                <div style="text-align:right;flex-shrink:0">
                    <div style="font-size:.9rem;font-weight:900;color:var(--primary)"><?= formatNumber((int)($tn['total_com']??0)) ?></div>
                    <div style="font-size:.62rem;color:var(--text-muted)">coments.</div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- ================================================================
     BLOQUE 3 — TABLA DE COMENTARIOS
================================================================ -->
<div class="acard">

    <!-- HEADER -->
    <div class="acard__hdr">
        <span class="acard__ttl">
            <i class="bi bi-list-ul" style="color:var(--primary)"></i>
            Lista de comentarios
            <?php if ($totalCom>0): ?>
            <span style="background:var(--bg-surface-2);color:var(--text-muted);
                         font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:999px">
                <?= formatNumber($totalCom) ?>
            </span>
            <?php endif; ?>
        </span>
        <button onclick="exportCSV()"
                style="padding:7px 14px;border:1px solid var(--border-color);
                       border-radius:var(--border-radius-lg);background:transparent;
                       font-size:.78rem;font-weight:600;cursor:pointer;
                       color:var(--text-muted);display:inline-flex;align-items:center;gap:6px">
            <i class="bi bi-download"></i> Exportar
        </button>
    </div>

    <!-- TABS FILTRO -->
    <div class="ftabs">
        <?php
        $tabs = [
            ''         => ['Todos',     'bi-chat-fill',             $statsTotal],
            'pendiente'=> ['Pendientes','bi-clock-fill',            $statsPendientes],
            'aprobado' => ['Aprobados', 'bi-check-circle-fill',     $statsAprobados],
            'reportado'=> ['Reportados','bi-flag-fill',             $statsReportados],
            'spam'     => ['Spam',      'bi-shield-exclamation',    $statsSpam],
        ];
        foreach ($tabs as $val => $info):
            $href = APP_URL.'/admin/comentarios.php'.($val?"?estado=$val":'').(!empty($fBusq)?($val?'&':'?').'q='.urlencode($fBusq):'');
        ?>
        <a href="<?= $href ?>"
           class="ftab <?= $fEstado===$val?'on':'' ?>">
            <i class="bi <?= $info[1] ?>"></i>
            <?= $info[0] ?>
            <span class="cnt"><?= formatNumber($info[2]) ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- BARRA BÚSQUEDA / FILTROS -->
    <form method="GET" action="<?= APP_URL ?>/admin/comentarios.php" id="fForm">
        <?php if ($fEstado): ?><input type="hidden" name="estado" value="<?= e($fEstado) ?>"><?php endif; ?>
        <div class="fbar">
            <div class="si-wrap">
                <i class="bi bi-search"></i>
                <input type="text" name="q" id="siInput" class="si"
                       placeholder="Buscar en comentario, usuario o noticia..."
                       value="<?= e($fBusq) ?>">
            </div>
            <select name="tipo" class="fsel" onchange="this.form.submit()">
                <option value="">Tipo: Todos</option>
                <option value="principal" <?= $fTipo==='principal'?'selected':'' ?>>Principal</option>
                <option value="respuesta" <?= $fTipo==='respuesta'?'selected':'' ?>>Respuesta</option>
            </select>
            <select name="orden" class="fsel" onchange="this.form.submit()">
                <option value="fecha_desc"    <?= $fOrden==='fecha_desc'?'selected':'' ?>>Más recientes</option>
                <option value="fecha_asc"     <?= $fOrden==='fecha_asc'?'selected':'' ?>>Más antiguos</option>
                <option value="votos_desc"    <?= $fOrden==='votos_desc'?'selected':'' ?>>Más votados</option>
                <option value="reportes_desc" <?= $fOrden==='reportes_desc'?'selected':'' ?>>Más reportados</option>
            </select>
            <input type="date" name="desde" class="fdate" value="<?= e($fDesde) ?>"
                   max="<?= date('Y-m-d') ?>" title="Desde" onchange="this.form.submit()">
            <input type="date" name="hasta" class="fdate" value="<?= e($fHasta) ?>"
                   max="<?= date('Y-m-d') ?>" title="Hasta" onchange="this.form.submit()">
            <button type="submit" class="btn-prim">
                <i class="bi bi-funnel-fill"></i> Filtrar
            </button>
            <?php if ($fBusq||$fEstado||$fTipo||$fDesde||$fHasta): ?>
            <a href="<?= APP_URL ?>/admin/comentarios.php" class="btn-sec">
                <i class="bi bi-x-lg"></i> Limpiar
            </a>
            <?php endif; ?>
        </div>
    </form>

    <!-- BULK BAR -->
    <div class="bbar" id="bulkBar">
        <span id="bulkLbl">0 seleccionados</span>
        <button class="bulk-btn bb-green"  onclick="doBulk('aprobar')">
            <i class="bi bi-check-lg"></i> Aprobar
        </button>
        <button class="bulk-btn bb-yellow" onclick="doBulk('rechazar')">
            <i class="bi bi-eye-slash-fill"></i> Rechazar
        </button>
        <button class="bulk-btn bb-purple" onclick="doBulk('spam')">
            <i class="bi bi-shield-exclamation"></i> Spam
        </button>
        <button class="bulk-btn bb-red"    onclick="doBulk('eliminar')">
            <i class="bi bi-trash-fill"></i> Eliminar
        </button>
        <button class="bulk-btn bb-gray"   onclick="clearSel()">
            Cancelar
        </button>
    </div>

    <!-- TABLA -->
    <div class="tbl-wrap">
        <?php if (empty($coms)): ?>
        <div class="empty-st">
            <i class="bi bi-chat-square-text"></i>
            <h3>No se encontraron comentarios</h3>
            <p><?= ($fBusq||$fEstado||$fTipo||$fDesde||$fHasta)?'Prueba con otros filtros.':'Aún no hay comentarios.' ?></p>
        </div>
        <?php else: ?>
        <table class="atbl" id="comTable">
            <thead>
                <tr>
                    <th style="width:34px">
                        <input type="checkbox" id="chkAll" onchange="selAll(this)"
                               style="cursor:pointer;width:15px;height:15px;accent-color:var(--primary)">
                    </th>
                    <th style="width:36px">#</th>
                    <th style="min-width:210px">Comentario</th>
                    <th style="min-width:120px">Usuario</th>
                    <th style="min-width:140px">Noticia</th>
                    <th style="min-width:90px">Estado</th>
                    <th style="min-width:70px">Votos</th>
                    <th style="min-width:88px">Fecha</th>
                    <th style="min-width:158px;text-align:center">Acciones</th>
                </tr>
            </thead>
            <tbody id="comBody">
            <?php foreach ($coms as $c):
                $isAprov  = (bool)$c['aprobado'];
                $isRep    = (bool)$c['reportado'];
                $isSpam   = (int)$c['reportes'] >= 3;
                $isReply  = $c['padre_id'] !== null;
                $vPos     = (int)($c['votos_pos']??0)+(int)($c['likes']??0);
                $vNeg     = (int)($c['votos_neg']??0)+(int)($c['dislikes']??0);
            ?>
            <tr id="row-<?= (int)$c['id'] ?>"
                style="<?= $isRep?'background:rgba(239,68,68,.025)':'' ?>">

                <!-- checkbox -->
                <td>
                    <input type="checkbox" class="rowchk" value="<?= (int)$c['id'] ?>"
                           onchange="updBulk()"
                           style="cursor:pointer;width:15px;height:15px;accent-color:var(--primary)">
                </td>

                <!-- ID -->
                <td style="font-size:.7rem;font-weight:700;color:var(--text-muted)">
                    <?= (int)$c['id'] ?>
                </td>

                <!-- COMENTARIO -->
                <td style="max-width:270px">
                    <?php if ($isReply): ?>
                    <div class="creply"><i class="bi bi-arrow-return-right"></i> Respuesta</div>
                    <?php endif; ?>
                    <?php if ($isSpam): ?>
                    <span class="badge b-purple" style="margin-bottom:3px">
                        <i class="bi bi-shield-exclamation"></i> SPAM
                    </span>
                    <?php endif; ?>
                    <div class="ctxt" title="<?= e($c['comentario']) ?>">
                        <?= e(truncateChars($c['comentario'], 110)) ?>
                    </div>
                    <?php if ($c['editado']): ?>
                    <div style="font-size:.63rem;color:var(--text-muted);margin-top:2px">
                        <i class="bi bi-pencil-square"></i> Editado
                    </div>
                    <?php endif; ?>
                </td>

                <!-- USUARIO -->
                <td>
                    <div style="display:flex;align-items:center;gap:7px">
                        <img src="<?= e(getImageUrl($c['u_avatar']??'','avatar')) ?>"
                             alt="<?= e($c['u_nombre']) ?>"
                             style="width:28px;height:28px;border-radius:50%;
                                    object-fit:cover;border:1px solid var(--border-color)">
                        <div>
                            <div style="font-size:.77rem;font-weight:700;color:var(--text-primary);
                                        max-width:95px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                <?= e($c['u_nombre']) ?>
                            </div>
                            <?php if ($c['u_premium']): ?>
                            <span style="font-size:.61rem;color:var(--warning);font-weight:700">⭐ Premium</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>

                <!-- NOTICIA -->
                <td style="max-width:150px">
                    <a href="<?= APP_URL ?>/noticia.php?slug=<?= e($c['n_slug']) ?>"
                       target="_blank" rel="noopener"
                       style="font-size:.76rem;color:var(--primary);text-decoration:none;font-weight:600;
                              display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                        <?= e(truncateChars($c['n_titulo'], 52)) ?>
                    </a>
                </td>

                <!-- ESTADO -->
                <td>
                    <?php if ($isSpam): ?>
                    <span class="badge b-purple"><i class="bi bi-shield-exclamation"></i> Spam</span>
                    <?php elseif ($isRep): ?>
                    <span class="badge b-red"><i class="bi bi-flag-fill"></i> Reportado<?= (int)$c['reportes']>0?' ('.(int)$c['reportes'].')':'' ?></span>
                    <?php elseif ($isAprov): ?>
                    <span class="badge b-green"><i class="bi bi-check-circle-fill"></i> Aprobado</span>
                    <?php else: ?>
                    <span class="badge b-yellow"><i class="bi bi-clock-fill"></i> Pendiente</span>
                    <?php endif; ?>
                </td>

                <!-- VOTOS -->
                <td>
                    <div style="font-size:.75rem;color:var(--success);font-weight:700">
                        <i class="bi bi-hand-thumbs-up-fill"></i> <?= formatNumber($vPos) ?>
                    </div>
                    <div style="font-size:.75rem;color:var(--danger)">
                        <i class="bi bi-hand-thumbs-down-fill"></i> <?= formatNumber($vNeg) ?>
                    </div>
                </td>

                <!-- FECHA -->
                <td>
                    <div style="font-size:.74rem;color:var(--text-muted)"><?= timeAgo($c['fecha']) ?></div>
                    <div style="font-size:.63rem;color:var(--text-muted)"><?= date('d/m/Y',strtotime($c['fecha'])) ?></div>
                </td>

                <!-- ACCIONES
                     !! Usamos data-* para pasar texto — evita problemas de escaping !! -->
                <td>
                    <div style="display:flex;gap:4px;align-items:center;justify-content:center;flex-wrap:wrap">

                        <!-- VER -->
                        <button class="abtn ab-view"
                                data-id="<?= (int)$c['id'] ?>"
                                onclick="abrirVer(this)"
                                title="Ver detalle">
                            <i class="bi bi-eye-fill"></i>
                        </button>

                        <!-- APROBAR / DESPUBLICAR -->
                        <?php if (!$isAprov): ?>
                        <button class="abtn ab-appr"
                                data-id="<?= (int)$c['id'] ?>"
                                onclick="doAprobar(this)"
                                title="Aprobar comentario">
                            <i class="bi bi-check-lg"></i>
                        </button>
                        <?php else: ?>
                        <button class="abtn ab-unp"
                                data-id="<?= (int)$c['id'] ?>"
                                onclick="doDespu(this)"
                                title="Despublicar comentario">
                            <i class="bi bi-eye-slash-fill"></i>
                        </button>
                        <?php endif; ?>

                        <!-- EDITAR
                             data-txt contiene el texto completo, e() lo escapa para HTML -->
                        <button class="abtn ab-edit"
                                data-id="<?= (int)$c['id'] ?>"
                                data-txt="<?= e($c['comentario']) ?>"
                                onclick="abrirEditar(this)"
                                title="Editar comentario">
                            <i class="bi bi-pencil-fill"></i>
                        </button>

                        <!-- RESPONDER
                             data-txt: texto truncado del comentario original
                             data-nid: noticia_id -->
                        <button class="abtn ab-rep"
                                data-id="<?= (int)$c['id'] ?>"
                                data-nid="<?= (int)$c['nid'] ?>"
                                data-txt="<?= e(truncateChars($c['comentario'], 80)) ?>"
                                onclick="abrirResponder(this)"
                                title="Responder comentario">
                            <i class="bi bi-reply-fill"></i>
                        </button>

                        <!-- ELIMINAR -->
                        <button class="abtn ab-del"
                                data-id="<?= (int)$c['id'] ?>"
                                onclick="abrirEliminar(this)"
                                title="Eliminar comentario">
                            <i class="bi bi-trash-fill"></i>
                        </button>

                    </div>
                </td>

            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- PAGINACIÓN -->
    <?php if ($totalPags > 1): ?>
    <div class="pag">
        <div class="pag__info">
            Mostrando <strong><?= formatNumber($offset+1) ?></strong>–<strong><?= formatNumber(min($offset+$porPag,$totalCom)) ?></strong>
            de <strong><?= formatNumber($totalCom) ?></strong> comentarios
        </div>
        <div class="pag__pages">
            <?php
            $bu    = APP_URL.'/admin/comentarios.php';
            $qpArr = array_merge($qpBase, $fEstado?['estado'=>$fEstado]:[]);
            $prev  = $bu.'?'.http_build_query(array_merge($qpArr,['pag'=>$pagAct-1]));
            $next  = $bu.'?'.http_build_query(array_merge($qpArr,['pag'=>$pagAct+1]));
            ?>
            <a href="<?= $pagAct<=1?'#':$prev ?>" class="pg <?= $pagAct<=1?'off':'' ?>">
                <i class="bi bi-chevron-left"></i>
            </a>
            <?php
            $s = max(1,$pagAct-2); $e = min($totalPags,$pagAct+2);
            if ($s>1): ?><a href="<?= $bu.'?'.http_build_query(array_merge($qpArr,['pag'=>1])) ?>" class="pg">1</a><?php
            if ($s>2): ?><span class="pg off">…</span><?php endif; endif;
            for ($p=$s;$p<=$e;$p++): ?>
            <a href="<?= $bu.'?'.http_build_query(array_merge($qpArr,['pag'=>$p])) ?>"
               class="pg <?= $p===$pagAct?'on':'' ?>"><?= $p ?></a>
            <?php endfor;
            if ($e<$totalPags):
            if ($e<$totalPags-1): ?><span class="pg off">…</span><?php endif; ?>
            <a href="<?= $bu.'?'.http_build_query(array_merge($qpArr,['pag'=>$totalPags])) ?>" class="pg"><?= $totalPags ?></a>
            <?php endif; ?>
            <a href="<?= $pagAct>=$totalPags?'#':$next ?>" class="pg <?= $pagAct>=$totalPags?'off':'' ?>">
                <i class="bi bi-chevron-right"></i>
            </a>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /.acard tabla -->

<!-- ================================================================
     BLOQUE 4 — TOP COMENTARISTAS
================================================================ -->
<div class="acard">
    <div class="acard__hdr">
        <span class="acard__ttl">
            <i class="bi bi-people-fill" style="color:#8b5cf6"></i>
            Top comentaristas
        </span>
        <span style="font-size:.72rem;color:var(--text-muted)">Por total de comentarios</span>
    </div>
    <div class="acard__body" style="padding:10px 16px">
        <?php if (empty($topComent)): ?>
        <div class="empty-st" style="padding:30px 0">
            <i class="bi bi-people"></i>
            <h3>Sin datos</h3>
            <p>Aún no hay suficiente actividad.</p>
        </div>
        <?php else: ?>
        <div class="tcg">
            <?php foreach ($topComent as $idx => $tc): ?>
            <div class="tci">
                <span class="tci__rank"
                      style="color:<?= $idx===0?'var(--warning)':($idx===1?'#9ca3af':($idx===2?'#cd7f32':'var(--text-muted)')) ?>">
                    <?= $idx+1 ?>
                </span>
                <img class="tci__av"
                     src="<?= e(getImageUrl($tc['avatar']??'','avatar')) ?>"
                     alt="<?= e($tc['nombre']) ?>" loading="lazy">
                <div class="tci__info">
                    <div class="tci__name">
                        <?= e(truncateChars($tc['nombre'],22)) ?>
                        <?php if ($tc['premium']): ?><span style="font-size:.6rem;color:var(--warning)">⭐</span><?php endif; ?>
                    </div>
                    <div class="tci__meta">
                        <span style="color:var(--success)"><?= formatNumber((int)$tc['com_aprobados']) ?> aprobados</span>
                        <?php if ((int)$tc['total_likes']>0): ?>
                        &nbsp;·&nbsp;<i class="bi bi-hand-thumbs-up-fill" style="color:var(--info)"></i> <?= formatNumber((int)$tc['total_likes']) ?>
                        <?php endif; ?>
                        <?php if ((int)$tc['com_reportados']>0): ?>
                        &nbsp;·&nbsp;<span style="color:var(--danger)"><i class="bi bi-flag-fill"></i> <?= (int)$tc['com_reportados'] ?></span>
                        <?php endif; ?>
                        <div style="font-size:.61rem;margin-top:1px">Último: <?= timeAgo($tc['ultimo']) ?></div>
                    </div>
                </div>
                <div class="tci__cnt"><?= formatNumber((int)$tc['total_com']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

</div><!-- /.admin-content -->
</main>

<!-- ====================================================================
     MODAL VER DETALLE
==================================================================== -->
<div class="modal-overlay" id="mVer">
    <div class="modal-box" id="mVerBox">
        <div class="mhdr">
            <i class="bi bi-chat-square-text-fill" style="color:var(--info);font-size:1rem"></i>
            <h3>Detalle del comentario</h3>
            <button class="mclose" onclick="cerrarModal('mVer')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="mbody" id="mVerBody">
            <div style="text-align:center;padding:30px;color:var(--text-muted)">
                <i class="bi bi-arrow-clockwise" style="font-size:2rem;display:block;margin-bottom:8px;animation:spin 1s linear infinite"></i>
                Cargando...
            </div>
        </div>
        <div class="mactions" id="mVerActs">
            <button class="btn-sec" onclick="cerrarModal('mVer')">Cerrar</button>
            <button class="btn-prim" id="mVerBtnAprov" style="display:none" onclick="doAprobarDesdeVer()">
                <i class="bi bi-check-lg"></i> Aprobar
            </button>
        </div>
    </div>
</div>

<!-- ====================================================================
     MODAL EDITAR
==================================================================== -->
<div class="modal-overlay" id="mEditar">
    <div class="modal-box">
        <div class="mhdr">
            <i class="bi bi-pencil-square" style="color:var(--primary);font-size:1rem"></i>
            <h3>Editar comentario <span id="mEId" style="color:var(--primary)">#—</span></h3>
            <button class="mclose" onclick="cerrarModal('mEditar')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="mbody">
            <div class="mlbl">Texto original</div>
            <div class="mquote" id="mEOrig">—</div>
            <div class="mlbl">Nuevo texto <span style="color:var(--danger)">*</span></div>
            <textarea class="mtextarea" id="mETxt" maxlength="2000"
                      placeholder="Escribe el nuevo texto del comentario..."></textarea>
            <div style="display:flex;justify-content:space-between;margin-top:5px;
                        font-size:.72rem;color:var(--text-muted)">
                <span>Mínimo 3 · Máximo 2000 caracteres</span>
                <span><span id="mECnt">0</span>/2000</span>
            </div>
        </div>
        <div class="mactions">
            <button class="btn-sec" onclick="cerrarModal('mEditar')">Cancelar</button>
            <button class="btn-prim" id="mEBtn" onclick="guardarEdicion()">
                <i class="bi bi-floppy-fill"></i> Guardar
            </button>
        </div>
    </div>
</div>

<!-- ====================================================================
     MODAL RESPONDER
==================================================================== -->
<div class="modal-overlay" id="mResponder">
    <div class="modal-box">
        <div class="mhdr">
            <i class="bi bi-reply-fill" style="color:var(--success);font-size:1rem"></i>
            <h3>Responder comentario <span id="mRId" style="color:var(--success)">#—</span></h3>
            <button class="mclose" onclick="cerrarModal('mResponder')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="mbody">
            <div class="mlbl">Comentario original</div>
            <div class="mquote" id="mROrig" style="border-left-color:var(--success)">—</div>
            <div class="mlbl">Tu respuesta (como administrador) <span style="color:var(--danger)">*</span></div>
            <textarea class="mtextarea" id="mRTxt" maxlength="2000"
                      placeholder="Escribe tu respuesta..."></textarea>
            <div style="display:flex;justify-content:space-between;margin-top:5px;
                        font-size:.72rem;color:var(--text-muted)">
                <span>Mínimo 3 caracteres</span>
                <span><span id="mRCnt">0</span>/2000</span>
            </div>
            <div style="margin-top:12px;padding:10px;background:rgba(34,197,94,.08);
                        border-radius:var(--border-radius-lg);font-size:.78rem;color:var(--success);
                        display:flex;align-items:center;gap:8px">
                <i class="bi bi-info-circle-fill"></i>
                La respuesta se publicará automáticamente aprobada.
            </div>
        </div>
        <div class="mactions">
            <button class="btn-sec" onclick="cerrarModal('mResponder')">Cancelar</button>
            <button class="btn-success" id="mRBtn" onclick="publicarRespuesta()">
                <i class="bi bi-send-fill"></i> Publicar respuesta
            </button>
        </div>
    </div>
</div>

<!-- ====================================================================
     MODAL ELIMINAR
==================================================================== -->
<div class="modal-overlay" id="mEliminar">
    <div class="modal-box sm">
        <div class="mhdr">
            <i class="bi bi-trash-fill" style="color:var(--danger);font-size:1rem"></i>
            <h3>Confirmar eliminación</h3>
            <button class="mclose" onclick="cerrarModal('mEliminar')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="mbody" style="text-align:center;padding:24px 22px">
            <div style="width:60px;height:60px;border-radius:50%;background:rgba(239,68,68,.1);
                        color:var(--danger);display:flex;align-items:center;justify-content:center;
                        font-size:1.8rem;margin:0 auto 16px">
                <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
            <p style="font-size:.88rem;color:var(--text-secondary);line-height:1.6">
                ¿Eliminar el comentario <strong>#<span id="mDelId">—</span></strong>?<br>
                <span style="font-size:.78rem;color:var(--danger)">
                    También se eliminarán sus respuestas. Esta acción no se puede deshacer.
                </span>
            </p>
        </div>
        <div class="mactions">
            <button class="btn-sec" onclick="cerrarModal('mEliminar')">Cancelar</button>
            <button class="btn-danger" id="mDelBtn" onclick="ejecutarEliminar()">
                <i class="bi bi-trash-fill"></i> Sí, eliminar
            </button>
        </div>
    </div>
</div>

<!-- CSRF oculto para JS -->
<input type="hidden" id="_csrf" value="<?= csrfToken() ?>">

<!-- Toast container -->
<div id="toast-container"></div>

<!-- ====================================================================
     SCRIPTS
==================================================================== -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
/* ================================================================
   PERIÓDICO DIGITAL PRO v2.0 — admin/comentarios.php — JS
   Versión 2.1 — Modales con data-* attributes
================================================================ */
'use strict';

/* ── helpers ─────────────────────────────────────────────────── */
const csrf   = () => document.getElementById('_csrf').value;
const APP    = '<?= APP_URL ?>';

function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

/* ── Toast ───────────────────────────────────────────────────── */
function toast(msg, type='ok', ms=3500){
    const c = document.getElementById('toast-container');
    const t = document.createElement('div');
    const ico = {ok:'bi-check-circle-fill',err:'bi-exclamation-circle-fill',inf:'bi-info-circle-fill'};
    t.className = `toast t-${type}`;
    t.innerHTML = `<i class="bi ${ico[type]||'bi-info-circle-fill'}"></i>${esc(msg)}`;
    c.appendChild(t);
    setTimeout(()=>{ t.style.cssText='opacity:0;transform:translateX(20px);transition:.3s'; },ms-350);
    setTimeout(()=>t.remove(), ms);
}

/* ── API request ─────────────────────────────────────────────── */
async function api(accion, data={}){
    try{
        const r = await fetch(location.href, {
            method:'POST',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({ accion, csrf_token:csrf(), ...data })
        });
        if(!r.ok) throw new Error('HTTP '+r.status);
        return await r.json();
    }catch(e){
        console.error('[comentarios]',e);
        return {success:false,message:'Error de conexión.'};
    }
}

/* ── Sidebar ─────────────────────────────────────────────────── */
function toggleSidebar(){
    document.getElementById('adminSidebar').classList.toggle('open');
    document.getElementById('adminOverlay').classList.toggle('show');
    document.body.style.overflow = document.getElementById('adminSidebar').classList.contains('open')?'hidden':'';
}
function closeSidebar(){
    document.getElementById('adminSidebar').classList.remove('open');
    document.getElementById('adminOverlay').classList.remove('show');
    document.body.style.overflow='';
}
document.addEventListener('keydown', e=>{ if(e.key==='Escape'){ closeSidebar(); cerrarTodos(); } });

/* ── Dark mode ───────────────────────────────────────────────── */
function toggleDark(){
    const html  = document.documentElement;
    const ico   = document.getElementById('darkIco');
    const curr  = html.getAttribute('data-theme')||'auto';
    const nxt   = {auto:'oscuro',oscuro:'claro',claro:'auto'}[curr]||'auto';
    html.setAttribute('data-theme', nxt);
    const icos  = {oscuro:'bi-moon-stars-fill',claro:'bi-sun-fill',auto:'bi-circle-half'};
    if(ico) ico.className = 'bi '+icos[nxt];
    // guardar preferencia
    fetch(APP+'/ajax/handler.php',{
        method:'POST',
        headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
        body:JSON.stringify({action:'set_tema',tema:nxt,csrf_token:csrf()})
    }).catch(()=>{});
}
// sincronizar ícono al cargar
(()=>{
    const t = document.documentElement.getAttribute('data-theme')||'auto';
    const i = document.getElementById('darkIco');
    if(i) i.className='bi '+({oscuro:'bi-moon-stars-fill',claro:'bi-sun-fill',auto:'bi-circle-half'}[t]||'bi-moon-stars-fill');
})();

/* ================================================================
   MODAL HELPERS
================================================================ */
function abrirModal(id){  document.getElementById(id).classList.add('visible');    }
function cerrarModal(id){ document.getElementById(id).classList.remove('visible'); }
function cerrarTodos(){
    ['mVer','mEditar','mResponder','mEliminar'].forEach(id=>{
        document.getElementById(id)?.classList.remove('visible');
    });
}
// Cerrar al clic en el fondo del overlay
['mVer','mEditar','mResponder','mEliminar'].forEach(id=>{
    document.getElementById(id)?.addEventListener('click', function(e){
        if(e.target===this) cerrarModal(id);
    });
});

/* ================================================================
   SELECCIÓN MÚLTIPLE
================================================================ */
let selIds = new Set();

function selAll(cb){
    document.querySelectorAll('.rowchk').forEach(c=>{
        c.checked = cb.checked;
        const v = parseInt(c.value);
        cb.checked ? selIds.add(v) : selIds.delete(v);
    });
    updBulk();
}
function updBulk(){
    selIds.clear();
    document.querySelectorAll('.rowchk:checked').forEach(c=>selIds.add(parseInt(c.value)));
    const n = selIds.size;
    const bb = document.getElementById('bulkBar');
    const bl = document.getElementById('bulkLbl');
    bb.classList.toggle('show', n>0);
    if(bl) bl.textContent = `${n} comentario${n!==1?'s':''} seleccionado${n!==1?'s':''}`;
    const chkAll = document.getElementById('chkAll');
    const total  = document.querySelectorAll('.rowchk').length;
    if(chkAll){ chkAll.indeterminate = n>0 && n<total; chkAll.checked = n===total && total>0; }
}
function clearSel(){
    selIds.clear();
    document.querySelectorAll('.rowchk').forEach(c=>c.checked=false);
    const ca = document.getElementById('chkAll');
    if(ca){ ca.checked=false; ca.indeterminate=false; }
    document.getElementById('bulkBar').classList.remove('show');
}
async function doBulk(accion){
    if(!selIds.size) return;
    if(accion==='eliminar' && !confirm(`¿Eliminar ${selIds.size} comentario(s)?`)) return;
    const ids  = Array.from(selIds);
    const resp = await api('bulk',{ids, bulk_accion:accion});
    if(resp.success){
        toast(resp.message,'ok');
        if(accion==='eliminar'){
            ids.forEach(id=>{ const r=document.getElementById('row-'+id); if(r){r.style.transition='.3s';r.style.opacity='0';setTimeout(()=>r.remove(),320);} });
        } else {
            setTimeout(()=>location.reload(),700);
        }
        clearSel();
    } else { toast(resp.message||'Error.','err'); }
}

/* ================================================================
   ACCIÓN: VER DETALLE
================================================================ */
let _verId = 0;
async function abrirVer(btn){
    _verId = parseInt(btn.dataset.id);
    const body = document.getElementById('mVerBody');
    const bAprov = document.getElementById('mVerBtnAprov');
    body.innerHTML = `<div style="text-align:center;padding:30px;color:var(--text-muted)">
        <i class="bi bi-arrow-clockwise" style="font-size:2rem;display:block;margin-bottom:8px;animation:spin 1s linear infinite"></i>
        Cargando...</div>`;
    bAprov.style.display='none';
    abrirModal('mVer');

    // Petición GET directa (no AJAX POST, sino parámetro en URL)
    const r = await fetch(`${location.pathname}?accion=ver&id=${_verId}`,{
        headers:{'X-Requested-With':'XMLHttpRequest'}
    }).then(x=>x.json()).catch(()=>({success:false}));

    if(!r.success){
        body.innerHTML=`<p style="color:var(--danger);padding:20px">Error al cargar el comentario.</p>`;
        return;
    }
    const d = r.data;
    const aprov = d.aprobado==1;
    const vPos  = (parseInt(d.votos_pos||0)+parseInt(d.likes||0));
    const vNeg  = (parseInt(d.votos_neg||0)+parseInt(d.dislikes||0));

    body.innerHTML = `
    <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:16px">
        <img src="${esc(d.u_avatar_url||'')}" alt="${esc(d.u_nombre)}"
             style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid var(--border-color);flex-shrink:0">
        <div style="flex:1">
            <div style="font-weight:700;font-size:.9rem;color:var(--text-primary)">${esc(d.u_nombre)}</div>
            <div style="font-size:.72rem;color:var(--text-muted)">${esc(d.u_email||'')}</div>
        </div>
        <div>${aprov
            ?'<span class="badge b-green"><i class="bi bi-check-circle-fill"></i> Aprobado</span>'
            :'<span class="badge b-yellow"><i class="bi bi-clock-fill"></i> Pendiente</span>'}</div>
    </div>
    ${d.p_texto?`<div class="mlbl">En respuesta a</div>
    <div class="mquote"><strong>${esc(d.p_autor||'Alguien')}:</strong> ${esc(d.p_texto)}</div>`:''}
    <div class="mlbl">Comentario</div>
    <div class="mquote" style="border-left-color:var(--primary);font-style:normal;background:var(--bg-surface-2)">${esc(d.comentario)}</div>
    <div class="mlbl">Noticia</div>
    <div style="margin-bottom:16px">
        <a href="${APP}/noticia.php?slug=${esc(d.n_slug)}" target="_blank"
           style="color:var(--primary);text-decoration:none;font-weight:600;font-size:.85rem">
            ${esc(d.n_titulo)} <i class="bi bi-box-arrow-up-right" style="font-size:.72rem"></i>
        </a>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px">
        <div style="text-align:center;padding:10px;background:var(--bg-surface-2);border-radius:var(--border-radius-lg)">
            <div style="font-size:1.1rem;font-weight:900;color:var(--success)">${vPos}</div>
            <div style="font-size:.68rem;color:var(--text-muted)">Votos +</div>
        </div>
        <div style="text-align:center;padding:10px;background:var(--bg-surface-2);border-radius:var(--border-radius-lg)">
            <div style="font-size:1.1rem;font-weight:900;color:var(--danger)">${vNeg}</div>
            <div style="font-size:.68rem;color:var(--text-muted)">Votos -</div>
        </div>
        <div style="text-align:center;padding:10px;background:var(--bg-surface-2);border-radius:var(--border-radius-lg)">
            <div style="font-size:1.1rem;font-weight:900;color:var(--info)">${parseInt(d.total_respuestas||0)}</div>
            <div style="font-size:.68rem;color:var(--text-muted)">Respuestas</div>
        </div>
    </div>
    <div style="font-size:.7rem;color:var(--text-muted);display:flex;gap:14px;flex-wrap:wrap">
        <span><i class="bi bi-calendar"></i> ${esc(d.fecha||'')}</span>
        ${d.editado==1?`<span><i class="bi bi-pencil-square"></i> Editado: ${esc(d.fecha_edicion||'')}</span>`:''}
        ${parseInt(d.reportes||0)>0?`<span style="color:var(--danger)"><i class="bi bi-flag-fill"></i> ${d.reportes} reportes</span>`:''}
    </div>`;

    if(!aprov){
        bAprov.style.display='inline-flex';
    }
}
function cerrarModalVer(){ cerrarModal('mVer'); }
async function doAprobarDesdeVer(){
    const r = await api('aprobar',{id:_verId});
    if(r.success){ toast(r.message,'ok'); cerrarModal('mVer'); setTimeout(()=>location.reload(),600); }
    else toast(r.message,'err');
}

/* ================================================================
   ACCIÓN: APROBAR (directo desde tabla)
================================================================ */
async function doAprobar(btn){
    const id = parseInt(btn.dataset.id);
    btn.disabled=true;
    const r = await api('aprobar',{id});
    btn.disabled=false;
    if(r.success){
        toast(r.message,'ok');
        // Cambiar badge estado en la fila
        const row = document.getElementById('row-'+id);
        if(row){
            const badges = row.querySelectorAll('.badge');
            badges.forEach(b=>{ b.className='badge b-green'; b.innerHTML='<i class="bi bi-check-circle-fill"></i> Aprobado'; });
        }
        // Cambiar el botón aprobar → despublicar
        btn.className='abtn ab-unp';
        btn.title='Despublicar comentario';
        btn.innerHTML='<i class="bi bi-eye-slash-fill"></i>';
        btn.removeAttribute('onclick');
        btn.addEventListener('click', ()=>doDespu(btn), {once:false});
        btn.onclick = function(){ doDespu(this); };
    } else { toast(r.message,'err'); }
}

/* ================================================================
   ACCIÓN: DESPUBLICAR (directo desde tabla)
================================================================ */
async function doDespu(btn){
    const id = parseInt(btn.dataset.id);
    btn.disabled=true;
    const r = await api('despublicar',{id});
    btn.disabled=false;
    if(r.success){
        toast(r.message,'inf');
        const row = document.getElementById('row-'+id);
        if(row){
            const badges = row.querySelectorAll('.badge');
            badges.forEach(b=>{ b.className='badge b-yellow'; b.innerHTML='<i class="bi bi-clock-fill"></i> Pendiente'; });
        }
        btn.className='abtn ab-appr';
        btn.title='Aprobar comentario';
        btn.innerHTML='<i class="bi bi-check-lg"></i>';
        btn.onclick = function(){ doAprobar(this); };
    } else { toast(r.message,'err'); }
}

/* ================================================================
   ACCIÓN: EDITAR — abre modal leyendo data-* del botón
================================================================ */
let _editId = 0;
function abrirEditar(btn){
    /* ✅  data-id  y  data-txt los lee el browser desde el atributo HTML
       El browser decodifica automáticamente las entidades HTML (&amp; → &, &quot; → ", etc.)
       así que dataset.txt ya llega con el texto limpio, sin doble codificación */
    _editId = parseInt(btn.dataset.id);
    const txt = btn.dataset.txt;   // texto limpio decodificado por el browser

    document.getElementById('mEId').textContent   = '#'+_editId;
    document.getElementById('mEOrig').textContent = txt;

    const ta = document.getElementById('mETxt');
    ta.value = txt;
    document.getElementById('mECnt').textContent = txt.length;

    abrirModal('mEditar');
    requestAnimationFrame(()=>ta.focus());
}

async function guardarEdicion(){
    const txt = document.getElementById('mETxt').value.trim();
    if(txt.length<3){ toast('Mínimo 3 caracteres.','err'); return; }
    const btn = document.getElementById('mEBtn');
    btn.disabled=true; btn.textContent='Guardando…';

    const r = await api('editar',{id:_editId, comentario:txt});

    btn.disabled=false;
    btn.innerHTML='<i class="bi bi-floppy-fill"></i> Guardar';

    if(r.success){
        toast(r.message,'ok');
        // Actualizar texto en la fila
        const row = document.getElementById('row-'+_editId);
        if(row){
            const cell = row.querySelector('.ctxt');
            if(cell){ cell.textContent = txt.length>110?txt.substring(0,110)+'…':txt; cell.title=txt; }
        }
        // Actualizar data-txt del botón editar de esa fila
        const btnEdit = row?.querySelector('.ab-edit');
        if(btnEdit) btnEdit.dataset.txt = txt;

        cerrarModal('mEditar');
    } else { toast(r.message||'Error al guardar.','err'); }
}

/* Contador de caracteres textarea editar */
document.getElementById('mETxt')?.addEventListener('input', function(){
    document.getElementById('mECnt').textContent = this.value.length;
});

/* ================================================================
   ACCIÓN: RESPONDER — abre modal leyendo data-* del botón
================================================================ */
let _repId=0, _repNid=0;
function abrirResponder(btn){
    /* ✅  Igual que editar: data-txt se decodifica automáticamente por el browser */
    _repId  = parseInt(btn.dataset.id);
    _repNid = parseInt(btn.dataset.nid);
    const txt = btn.dataset.txt;  // texto limpio

    document.getElementById('mRId').textContent   = '#'+_repId;
    document.getElementById('mROrig').textContent = txt;

    const ta = document.getElementById('mRTxt');
    ta.value = '';
    document.getElementById('mRCnt').textContent = '0';

    abrirModal('mResponder');
    requestAnimationFrame(()=>ta.focus());
}

async function publicarRespuesta(){
    const txt = document.getElementById('mRTxt').value.trim();
    if(txt.length<3){ toast('Mínimo 3 caracteres.','err'); return; }
    const btn = document.getElementById('mRBtn');
    btn.disabled=true; btn.textContent='Publicando…';

    const r = await api('responder',{id:_repId, respuesta:txt, noticia_id:_repNid});

    btn.disabled=false;
    btn.innerHTML='<i class="bi bi-send-fill"></i> Publicar respuesta';

    if(r.success){
        toast(r.message,'ok');
        cerrarModal('mResponder');
        setTimeout(()=>location.reload(),700);
    } else { toast(r.message||'Error.','err'); }
}

/* Contador de caracteres textarea responder */
document.getElementById('mRTxt')?.addEventListener('input', function(){
    document.getElementById('mRCnt').textContent = this.value.length;
});

/* ================================================================
   ACCIÓN: ELIMINAR
================================================================ */
let _delId=0;
function abrirEliminar(btn){
    _delId = parseInt(btn.dataset.id);
    document.getElementById('mDelId').textContent = _delId;
    abrirModal('mEliminar');
}
async function ejecutarEliminar(){
    const btn = document.getElementById('mDelBtn');
    btn.disabled=true; btn.textContent='Eliminando…';

    const r = await api('eliminar',{id:_delId});

    btn.disabled=false;
    btn.innerHTML='<i class="bi bi-trash-fill"></i> Sí, eliminar';

    if(r.success){
        toast(r.message,'ok');
        const row = document.getElementById('row-'+_delId);
        if(row){
            row.style.transition='.35s';
            row.style.opacity='0';
            row.style.transform='translateX(30px)';
            setTimeout(()=>row.remove(),380);
        }
        cerrarModal('mEliminar');
    } else { toast(r.message||'Error.','err'); }
}

/* ================================================================
   EXPORTAR CSV
================================================================ */
function exportCSV(){
    const rows = document.querySelectorAll('#comBody tr');
    if(!rows.length){ toast('No hay datos para exportar.','inf'); return; }
    const hdr = ['ID','Comentario','Usuario','Noticia','Estado','Votos+','Votos-','Fecha'];
    const lines = [hdr.join(',')];
    rows.forEach(row=>{
        const td = row.querySelectorAll('td');
        if(td.length<8) return;
        const id     = td[1]?.textContent?.trim()||'';
        const texto  = '"'+(td[2]?.querySelector('.ctxt')?.textContent?.trim()||'').replace(/"/g,'""')+'"';
        const user   = '"'+(td[3]?.textContent?.trim()||'').replace(/"/g,'""')+'"';
        const noticia= '"'+(td[4]?.textContent?.trim()||'').replace(/"/g,'""')+'"';
        const estado = td[5]?.textContent?.trim()||'';
        const vp     = td[6]?.querySelector('div:first-child')?.textContent?.replace(/\D/g,'')||'0';
        const vn     = td[6]?.querySelector('div:last-child')?.textContent?.replace(/\D/g,'')||'0';
        const fecha  = td[7]?.textContent?.trim()||'';
        lines.push([id,texto,user,noticia,estado,vp,vn,fecha].join(','));
    });
    const blob = new Blob(['\uFEFF'+lines.join('\n')],{type:'text/csv;charset=utf-8;'});
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href=url; a.download='comentarios-'+new Date().toISOString().split('T')[0]+'.csv';
    document.body.appendChild(a); a.click();
    setTimeout(()=>{ URL.revokeObjectURL(url); a.remove(); },500);
    toast('Exportación iniciada.','ok');
}

/* ================================================================
   BUSCADOR INSTANTÁNEO (local, filtro visual)
================================================================ */
document.getElementById('siInput')?.addEventListener('input', function(){
    const q = this.value.toLowerCase();
    document.querySelectorAll('#comBody tr').forEach(row=>{
        row.style.display = (!q || row.textContent.toLowerCase().includes(q)) ? '' : 'none';
    });
});
document.getElementById('siInput')?.addEventListener('keydown', function(e){
    if(e.key==='Enter') document.getElementById('fForm').submit();
});

/* ================================================================
   CHART — Actividad 14 días
================================================================ */
(function(){
    const ctx = document.getElementById('chartAct');
    if(!ctx) return;
    const labels = <?= json_encode($chartLabels) ?>;
    const aprov  = <?= json_encode($chartAprov) ?>;
    const pend   = <?= json_encode($chartPend) ?>;
    const total  = <?= json_encode($chartTotal) ?>;
    const isDark = document.documentElement.getAttribute('data-theme')==='oscuro';
    const gc     = isDark?'rgba(255,255,255,.06)':'rgba(0,0,0,.05)';
    const tc     = isDark?'rgba(255,255,255,.45)':'rgba(0,0,0,.45)';

    new Chart(ctx,{
        type:'bar',
        data:{
            labels,
            datasets:[
                {label:'Aprobados',data:aprov,backgroundColor:'rgba(34,197,94,.72)',borderRadius:4,borderSkipped:false,order:2},
                {label:'Pendientes',data:pend,backgroundColor:'rgba(245,158,11,.65)',borderRadius:4,borderSkipped:false,order:3},
                {label:'Total',data:total,type:'line',borderColor:'rgba(230,57,70,.85)',
                 backgroundColor:'rgba(230,57,70,.07)',pointBackgroundColor:'#e63946',
                 pointRadius:3,pointHoverRadius:5,borderWidth:2,tension:.4,fill:true,order:1},
            ]
        },
        options:{
            responsive:true,maintainAspectRatio:true,
            interaction:{mode:'index',intersect:false},
            plugins:{
                legend:{position:'top',align:'end',labels:{font:{size:11,weight:'600'},color:tc,boxWidth:10,boxHeight:10,usePointStyle:true,pointStyle:'circle'}},
                tooltip:{backgroundColor:'rgba(15,31,51,.95)',titleColor:'#fff',bodyColor:'rgba(255,255,255,.8)',padding:12,cornerRadius:10,
                         callbacks:{label:c=>` ${c.dataset.label}: ${c.raw}`}}
            },
            scales:{
                x:{grid:{display:false},ticks:{color:tc,font:{size:10}}},
                y:{beginAtZero:true,grid:{color:gc},ticks:{color:tc,font:{size:10},precision:0,callback:v=>Number.isInteger(v)?v:null}}
            }
        }
    });
})();

/* ── animación spin para loading ────────────────────────────── */
const s=document.createElement('style');
s.textContent='@keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}';
document.head.appendChild(s);
</script>
</body>
</html>