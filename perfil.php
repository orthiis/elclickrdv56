<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Perfil de Usuario v3.0
 * ============================================================
 * Archivo : perfil.php
 * Diseño  : Idéntico al sistema visual del Dashboard
 * ============================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$auth->requireLogin();
$usuarioActual = currentUser();

// ── ¿Ver perfil de otro usuario? ──────────────────────────────
$userId     = cleanInt($_GET['id'] ?? 0);
$esMiPerfil = true;

function getPerfilExtendido(int $uid): ?array {
    return db()->fetchOne(
        "SELECT u.*,
                (SELECT COUNT(*) FROM noticias
                 WHERE autor_id=u.id AND estado='publicado')  AS total_noticias,
                (SELECT COUNT(*) FROM comentarios
                 WHERE usuario_id=u.id AND aprobado=1)        AS total_comentarios,
                (SELECT COUNT(*) FROM favoritos
                 WHERE usuario_id=u.id)                       AS total_favoritos,
                (SELECT COUNT(*) FROM notificaciones
                 WHERE usuario_id=u.id AND leida=0)           AS notif_no_leidas
         FROM usuarios u WHERE u.id=? AND u.activo=1 LIMIT 1",
        [$uid]
    );
}

if ($userId > 0 && $userId !== (int)$usuarioActual['id']) {
    $perfil = getPerfilExtendido($userId);
    if (!$perfil) {
        setFlashMessage('error','Usuario no encontrado.');
        header('Location:'.APP_URL.'/index.php'); exit;
    }
    $esMiPerfil = false;
} else {
    $perfil = getPerfilExtendido((int)$usuarioActual['id']);
}
if (!$perfil) { header('Location:'.APP_URL.'/index.php'); exit; }

$esPeriodista = in_array($perfil['rol'],['super_admin','admin','editor','periodista'],true);

// ── Tabs ───────────────────────────────────────────────────────
$tabsPublicas = ['actividad','noticias'];
$tabsPrivadas = ['guardados','comentarios','siguiendo','seguidores',
                 'notificaciones','estadisticas','premium',
                 'configuracion','privacidad','preferencias'];
$tabActiva = cleanInput($_GET['tab'] ?? 'actividad');
if (!in_array($tabActiva, array_merge($tabsPublicas,$tabsPrivadas), true)) $tabActiva='actividad';
if (!$esMiPerfil && !in_array($tabActiva,$tabsPublicas,true)) $tabActiva='actividad';

// ── POST ────────────────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD']==='POST' && $esMiPerfil) {
    if (!$auth->verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[]='Token inválido.';
    } else {
        $action = cleanInput($_POST['action'] ?? '');

        if ($action === 'update_profile') {
            $nombre    = cleanInput($_POST['nombre']    ?? '',100);
            $bio       = cleanInput($_POST['bio']       ?? '',500);
            $website   = cleanInput($_POST['website']   ?? '',255);
            $twitter   = cleanInput($_POST['twitter']   ?? '',100);
            $facebook  = cleanInput($_POST['facebook']  ?? '',100);
            $instagram = cleanInput($_POST['instagram'] ?? '',100);
            $linkedin  = cleanInput($_POST['linkedin']  ?? '',100);
            $ciudad    = cleanInput($_POST['ciudad']    ?? '',100);
            $telefono  = cleanInput($_POST['telefono']  ?? '',20);
            if (empty($nombre)) { $errors[]='El nombre es requerido.'; }
            else {
                db()->execute(
                    "UPDATE usuarios SET nombre=?,bio=?,website=?,twitter=?,
                     facebook=?,instagram=?,linkedin=?,ciudad=?,telefono=? WHERE id=?",
                    [$nombre,$bio,$website,$twitter,$facebook,$instagram,
                     $linkedin,$ciudad,$telefono,$usuarioActual['id']]
                );
                if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error']===UPLOAD_ERR_OK) {
                    $up = uploadImage($_FILES['avatar'],'avatars','avatar');
                    if ($up['success']) {
                        if (!empty($perfil['avatar'])) deleteImage($perfil['avatar']);
                        db()->execute("UPDATE usuarios SET avatar=? WHERE id=?",
                            [$up['path'],$usuarioActual['id']]);
                    }
                }
                if (!empty($_FILES['cover_image']['name']) && $_FILES['cover_image']['error']===UPLOAD_ERR_OK) {
                    $up2 = uploadImage($_FILES['cover_image'],'covers','cover');
                    if ($up2['success']) {
                        if (!empty($perfil['cover_image'])) deleteImage($perfil['cover_image']);
                        db()->execute("UPDATE usuarios SET cover_image=? WHERE id=?",
                            [$up2['path'],$usuarioActual['id']]);
                    }
                }
                if (empty($errors)) {
                    logActividad((int)$usuarioActual['id'],'update_profile','usuarios',(int)$usuarioActual['id'],'Actualizó su perfil');
                    setFlashMessage('success','Perfil actualizado correctamente.');
                    header('Location:'.APP_URL.'/perfil.php?tab=configuracion'); exit;
                }
            }
        }
        elseif ($action==='change_password') {
            $r=$auth->cambiarPassword((int)$usuarioActual['id'],
                $_POST['password_actual']??'',
                $_POST['password_nueva']??'',
                $_POST['password_confirmar']??'');
            if ($r['success']) { setFlashMessage('success',$r['message']); header('Location:'.APP_URL.'/perfil.php?tab=privacidad'); exit; }
            $errors[]=$r['message']; $tabActiva='privacidad';
        }
        elseif ($action==='toggle_2fa') {
            $auth->toggle2FA((int)$usuarioActual['id'],(bool)($_POST['activar']??false));
            setFlashMessage('success',($_POST['activar']??false)?'2FA activado.':'2FA desactivado.');
            header('Location:'.APP_URL.'/perfil.php?tab=privacidad'); exit;
        }
        elseif ($action==='close_session') {
            $auth->cerrarSesionRemota((int)$usuarioActual['id'],cleanInt($_POST['session_id']??0));
            setFlashMessage('success','Sesión cerrada.'); header('Location:'.APP_URL.'/perfil.php?tab=privacidad'); exit;
        }
        elseif ($action==='close_all_sessions') {
            $auth->cerrarTodasLasSesiones((int)$usuarioActual['id']);
            setFlashMessage('success','Todas las sesiones cerradas.'); header('Location:'.APP_URL.'/perfil.php?tab=privacidad'); exit;
        }
        elseif ($action==='update_preferences') {
            $tema = in_array($_POST['tema']??'',['auto','light','dark'])?$_POST['tema']:'auto';
            $fs   = in_array($_POST['font_size']??'',['small','medium','large'])?$_POST['font_size']:'medium';
            db()->execute("UPDATE usuarios SET tema=?,font_size=?,newsletter=?,
                notif_comentarios=?,notif_seguidores=?,notif_noticias=?,notif_sistema=? WHERE id=?",
                [$tema,$fs,isset($_POST['newsletter'])?1:0,
                 isset($_POST['notif_comentarios'])?1:0,
                 isset($_POST['notif_seguidores'])?1:0,
                 isset($_POST['notif_noticias'])?1:0,
                 isset($_POST['notif_sistema'])?1:0,
                 $usuarioActual['id']]);
            setFlashMessage('success','Preferencias guardadas.'); header('Location:'.APP_URL.'/perfil.php?tab=preferencias'); exit;
        }
        elseif ($action==='update_privacy') {
            db()->execute("UPDATE usuarios SET perfil_publico=?,perfil_publico_email=?,perfil_publico_stats=? WHERE id=?",
                [isset($_POST['perfil_publico'])?1:0,
                 isset($_POST['perfil_publico_email'])?1:0,
                 isset($_POST['perfil_publico_stats'])?1:0,
                 $usuarioActual['id']]);
            setFlashMessage('success','Privacidad actualizada.'); header('Location:'.APP_URL.'/perfil.php?tab=privacidad'); exit;
        }
        elseif ($action==='mark_notif_read') {
            $nid=cleanInt($_POST['notif_id']??0);
            if ($nid>0) db()->execute("UPDATE notificaciones SET leida=1 WHERE id=? AND usuario_id=?",[$nid,$usuarioActual['id']]);
            else db()->execute("UPDATE notificaciones SET leida=1 WHERE usuario_id=?",[$usuarioActual['id']]);
            setFlashMessage('success','Marcadas como leídas.'); header('Location:'.APP_URL.'/perfil.php?tab=notificaciones'); exit;
        }
        elseif ($action==='delete_notif') {
            $nid=cleanInt($_POST['notif_id']??0);
            if ($nid>0) db()->execute("DELETE FROM notificaciones WHERE id=? AND usuario_id=?",[$nid,$usuarioActual['id']]);
            header('Location:'.APP_URL.'/perfil.php?tab=notificaciones'); exit;
        }
        elseif ($action==='delete_all_notif') {
            db()->execute("DELETE FROM notificaciones WHERE usuario_id=?",[$usuarioActual['id']]);
            setFlashMessage('success','Bandeja vaciada.'); header('Location:'.APP_URL.'/perfil.php?tab=notificaciones'); exit;
        }
        elseif ($action==='delete_avatar') {
            if (!empty($perfil['avatar'])) { deleteImage($perfil['avatar']); db()->execute("UPDATE usuarios SET avatar=NULL WHERE id=?",[$usuarioActual['id']]); }
            setFlashMessage('success','Avatar eliminado.'); header('Location:'.APP_URL.'/perfil.php?tab=configuracion'); exit;
        }
        elseif ($action==='delete_cover') {
            if (!empty($perfil['cover_image'])) { deleteImage($perfil['cover_image']); db()->execute("UPDATE usuarios SET cover_image=NULL WHERE id=?",[$usuarioActual['id']]); }
            setFlashMessage('success','Portada eliminada.'); header('Location:'.APP_URL.'/perfil.php?tab=configuracion'); exit;
        }
    }
}

// ── Cargar datos por tab ────────────────────────────────────────
$tabData=[];
switch($tabActiva) {
    case 'guardados':
        $pg=max(1,cleanInt($_GET['pagina']??1));
        $tot=db()->count("SELECT COUNT(*) FROM favoritos f INNER JOIN noticias n ON n.id=f.noticia_id WHERE f.usuario_id=? AND n.estado='publicado'",[$perfil['id']]);
        $pag=paginate($tot,$pg,12);
        $tabData=['favoritos'=>getFavoritosUsuario((int)$perfil['id'],12,$pag['offset']),'total'=>$tot,'pagination'=>$pag];
        break;
    case 'comentarios':
        $pg=max(1,cleanInt($_GET['pagina']??1));
        $tot=db()->count("SELECT COUNT(*) FROM comentarios WHERE usuario_id=? AND aprobado=1",[$perfil['id']]);
        $pag=paginate($tot,$pg,15);
        $tabData=['comentarios'=>db()->fetchAll("SELECT co.id,co.comentario,co.fecha,co.likes,co.dislikes,n.titulo AS noticia_titulo,n.slug AS noticia_slug,c.nombre AS cat_nombre,c.color AS cat_color FROM comentarios co INNER JOIN noticias n ON n.id=co.noticia_id INNER JOIN categorias c ON c.id=n.categoria_id WHERE co.usuario_id=? AND co.aprobado=1 ORDER BY co.fecha DESC LIMIT ? OFFSET ?",[$perfil['id'],$pag['limit'],$pag['offset']]),'total'=>$tot,'pagination'=>$pag];
        break;
    case 'siguiendo':
        $tabData=['siguiendo'=>db()->fetchAll("SELECT u.id,u.nombre,u.avatar,u.rol,u.verificado,u.slug_perfil,u.ciudad,u.reputacion,u.total_seguidores,su.fecha FROM seguidores_usuarios su INNER JOIN usuarios u ON u.id=su.seguido_id WHERE su.seguidor_id=? AND u.activo=1 ORDER BY su.fecha DESC LIMIT 60",[$perfil['id']])];
        break;
    case 'seguidores':
        $tabData=['seguidores'=>db()->fetchAll("SELECT u.id,u.nombre,u.avatar,u.rol,u.verificado,u.slug_perfil,u.ciudad,u.reputacion,u.total_seguidores,su.fecha FROM seguidores_usuarios su INNER JOIN usuarios u ON u.id=su.seguidor_id WHERE su.seguido_id=? AND u.activo=1 ORDER BY su.fecha DESC LIMIT 60",[$perfil['id']])];
        break;
    case 'noticias':
        $pg=max(1,cleanInt($_GET['pagina']??1));
        $fe=cleanInput($_GET['estado']??'todos');
        $we=$esMiPerfil?match($fe){'publicado','borrador','revision'=>"AND n.estado='$fe'",default=>''}:"AND n.estado='publicado'";
        $tot=db()->count("SELECT COUNT(*) FROM noticias n WHERE n.autor_id=? $we",[$perfil['id']]);
        $pag=paginate($tot,$pg,10);
        $tabData=['noticias'=>db()->fetchAll("SELECT n.id,n.titulo,n.slug,n.imagen,n.estado,n.vistas,n.likes,n.fecha_publicacion,n.fecha_creacion,n.es_premium,n.destacado,n.breaking,c.nombre AS cat_nombre,c.color AS cat_color,(SELECT COUNT(*) FROM comentarios WHERE noticia_id=n.id AND aprobado=1) AS tc,(SELECT COUNT(*) FROM favoritos WHERE noticia_id=n.id) AS tf FROM noticias n INNER JOIN categorias c ON c.id=n.categoria_id WHERE n.autor_id=? $we ORDER BY n.fecha_creacion DESC LIMIT ? OFFSET ?",[$perfil['id'],$pag['limit'],$pag['offset']]),'total'=>$tot,'pagination'=>$pag,'filtro'=>$fe];
        break;
    case 'notificaciones':
        $ft=cleanInput($_GET['tipo']??'todas');
        if (!in_array($ft,['todas','sistema','seguidor','comentario','noticia','premium'],true)) $ft='todas';
        $wn=$ft!=='todas'?"AND tipo=?":'';
        $pn=$ft!=='todas'?[$perfil['id'],$ft]:[$perfil['id']];
        $tabData=['notificaciones'=>db()->fetchAll("SELECT * FROM notificaciones WHERE usuario_id=? $wn ORDER BY fecha DESC LIMIT 60",$pn),'no_leidas'=>(int)($perfil['notif_no_leidas']??0),'filtro'=>$ft];
        break;
    case 'estadisticas':
        $spm=$esPeriodista?db()->fetchAll("SELECT DATE_FORMAT(fecha_publicacion,'%Y-%m') AS mes,COUNT(*) AS noticias,SUM(vistas) AS vistas,SUM(likes) AS likes FROM noticias WHERE autor_id=? AND estado='publicado' AND fecha_publicacion>=NOW()-INTERVAL 6 MONTH GROUP BY mes ORDER BY mes ASC",[$perfil['id']]):[];
        $topN=db()->fetchAll("SELECT n.id,n.titulo,n.slug,n.vistas,n.likes,n.fecha_publicacion,c.nombre AS cat_nombre,c.color AS cat_color,(SELECT COUNT(*) FROM comentarios WHERE noticia_id=n.id AND aprobado=1) AS tc FROM noticias n INNER JOIN categorias c ON c.id=n.categoria_id WHERE n.autor_id=? AND n.estado='publicado' ORDER BY n.vistas DESC LIMIT 5",[$perfil['id']]);
        $catD=db()->fetchAll("SELECT c.nombre,c.color,COUNT(n.id) AS tn,SUM(n.vistas) AS tv FROM noticias n INNER JOIN categorias c ON c.id=n.categoria_id WHERE n.autor_id=? AND n.estado='publicado' GROUP BY c.id ORDER BY tv DESC LIMIT 5",[$perfil['id']]);
        $tabData=['statsPorMes'=>$spm,'topNoticias'=>$topN,'catDistrib'=>$catD];
        break;
    case 'premium':
        $tabData=['suscripcion'=>db()->fetchOne("SELECT * FROM suscripciones_premium WHERE usuario_id=? AND estado='activa' ORDER BY fecha_inicio DESC LIMIT 1",[$perfil['id']]),'historial'=>db()->fetchAll("SELECT * FROM suscripciones_premium WHERE usuario_id=? ORDER BY fecha_creacion DESC LIMIT 10",[$perfil['id']]),'precio_mes'=>Config::get('premium_precio_mensual','4.99'),'precio_anio'=>Config::get('premium_precio_anual','39.99')];
        break;
    case 'privacidad':
        $tabData=['two_fa'=>(bool)($perfil['two_factor_activo']??0),'sesiones'=>$auth->getSesionesActivas((int)$usuarioActual['id'])];
        break;
    case 'preferencias':
        $tabData=['cats_seguidas'=>db()->fetchAll("SELECT c.id,c.nombre,c.color,c.icono,c.slug FROM seguir_categorias sc INNER JOIN categorias c ON c.id=sc.categoria_id WHERE sc.usuario_id=? ORDER BY c.nombre ASC",[$perfil['id']]),'todas_cats'=>db()->fetchAll("SELECT id,nombre,color,icono,slug FROM categorias WHERE activa=1 ORDER BY nombre ASC")];
        break;
    case 'actividad': default:
        $vistas=db()->fetchAll("SELECT n.id,n.titulo,n.slug,n.imagen,n.fecha_publicacion,MAX(vl.fecha) AS fv,c.nombre AS cat_nombre,c.color AS cat_color FROM visitas_log vl INNER JOIN noticias n ON n.id=vl.noticia_id INNER JOIN categorias c ON c.id=n.categoria_id WHERE vl.ip=? AND n.estado='publicado' GROUP BY n.id ORDER BY fv DESC LIMIT 6",[getClientIp()]);
        $ultnot=$esPeriodista?db()->fetchAll("SELECT n.titulo,n.slug,n.vistas,n.fecha_publicacion,c.nombre AS cat_nombre,c.color AS cat_color FROM noticias n INNER JOIN categorias c ON c.id=n.categoria_id WHERE n.autor_id=? AND n.estado='publicado' ORDER BY n.fecha_publicacion DESC LIMIT 5",[$perfil['id']]):[];
        $tabData=['vistas'=>$vistas,'insignias'=>getInsigniasUsuario((int)$perfil['id']),'ultimasNoticias'=>$ultnot,'stats'=>['noticias'=>$perfil['total_noticias']??0,'comentarios'=>$perfil['total_comentarios']??0,'favoritos'=>$perfil['total_favoritos']??0,'seguidores'=>$perfil['total_seguidores']??0,'siguiendo'=>$perfil['total_seguidos']??0,'reputacion'=>$perfil['reputacion']??0]];
        break;
}

$sigueAlPerfil = !$esMiPerfil && sigueA((int)$usuarioActual['id'],(int)$perfil['id']);
$insigniasPerfil = getInsigniasUsuario((int)$perfil['id']);

// Completitud
$camposComp=['nombre','bio','avatar','ciudad','website','twitter'];
$complPct=(int)round(array_sum(array_map(fn($c)=>!empty($perfil[$c])?1:0,$camposComp))/count($camposComp)*100);

// XP / Nivel
$nivelesXP=[0,100,300,600,1000,1500,2200,3000,4000,5500];
$nivel=(int)max(1,min($perfil['nivel']??1,10));
$xp=(int)($perfil['reputacion']??0);
$xpNext=$nivelesXP[$nivel]??9999;
$xpBase=$nivelesXP[$nivel-1]??0;
$xpPct=$xpNext>$xpBase?min(100,(int)round(($xp-$xpBase)/($xpNext-$xpBase)*100)):100;

$pageTitle = e($perfil['nombre']).' — '.Config::get('site_nombre',APP_NAME);
$bodyClass = 'page-perfil';
$flashMsg  = getFlashMessage();

require_once __DIR__.'/includes/header.php';
?>

<!-- ===============================================================
     ESTILOS PERFIL — Sistema visual idéntico al Dashboard
     =============================================================== -->
<style>
/* ════════════════════════════════════════════════════════════
   VARIABLES LOCALES (heredan del sistema global)
════════════════════════════════════════════════════════════ */
.perfil-page { background: var(--bg-body); min-height: 100vh; }

/* ════════════════════════════════════════════════════════════
   COVER / PORTADA
════════════════════════════════════════════════════════════ */
.pf-cover {
    position: relative;
    height: 220px;
    background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary) 60%, #7c3aed 100%);
    overflow: hidden;
}
.pf-cover__img   { width:100%;height:100%;object-fit:cover;object-position:center; }
.pf-cover__mask  { position:absolute;inset:0;background:linear-gradient(to bottom,transparent 30%,rgba(0,0,0,.55) 100%); }
.pf-cover__btns  { position:absolute;top:14px;right:16px;display:flex;gap:8px;z-index:2; }
.pf-cover__btn   {
    display:inline-flex;align-items:center;gap:6px;
    padding:7px 14px;border-radius:var(--border-radius-full);
    background:rgba(0,0,0,.45);backdrop-filter:blur(10px);
    border:1px solid rgba(255,255,255,.2);color:#fff;
    font-size:.75rem;font-weight:700;cursor:pointer;
    text-decoration:none;transition:all .2s;
}
.pf-cover__btn:hover { background:rgba(0,0,0,.65); }

/* ════════════════════════════════════════════════════════════
   BARRA DE INFO DEL PERFIL
════════════════════════════════════════════════════════════ */
.pf-infobar {
    background: var(--bg-surface);
    border-bottom: 1px solid var(--border-color);
    box-shadow: var(--shadow-sm);
}
.pf-infobar__inner {
    max-width: 1200px; margin: 0 auto;
    padding: 0 24px;
    display: flex; gap: 20px; align-items: flex-end; flex-wrap: wrap;
}

/* Avatar */
.pf-avatar-wrap {
    position: relative;
    margin-top: -55px;
    flex-shrink: 0; z-index: 5;
}
.pf-avatar {
    width: 110px; height: 110px; border-radius: 50%;
    border: 4px solid var(--bg-surface);
    object-fit: cover; background: var(--bg-surface-2);
    display: block;
    box-shadow: var(--shadow-md);
}
.pf-avatar-verify {
    position:absolute;bottom:6px;right:4px;
    width:26px;height:26px;border-radius:50%;
    background:var(--info);border:3px solid var(--bg-surface);
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:.65rem;
}
.pf-avatar-edit-btn {
    position:absolute;inset:0;border-radius:50%;
    background:rgba(0,0,0,.45);
    display:flex;align-items:center;justify-content:center;
    opacity:0;transition:.2s;cursor:pointer;
    color:#fff;font-size:1.3rem;border:none;
}
.pf-avatar-wrap:hover .pf-avatar-edit-btn { opacity:1; }

/* Info */
.pf-info { flex:1; padding: 14px 0 16px; min-width: 200px; }
.pf-name {
    font-family: var(--font-serif, serif);
    font-size: 1.35rem; font-weight: 800;
    color: var(--text-primary); line-height: 1.2;
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.pf-badge {
    display:inline-flex;align-items:center;gap:4px;
    padding:3px 10px;border-radius:var(--border-radius-full);
    font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
    font-family:var(--font-sans,sans-serif);
}
.pf-bio {
    font-size:.85rem;color:var(--text-secondary);
    margin:6px 0 8px;line-height:1.55;max-width:520px;
}
.pf-meta {
    display:flex;flex-wrap:wrap;gap:12px;
    font-size:.75rem;color:var(--text-muted);
}
.pf-meta span { display:flex;align-items:center;gap:4px; }
.pf-meta i { color:var(--primary);font-size:.75rem; }
.pf-socials { display:flex;gap:7px;margin-top:10px;flex-wrap:wrap; }
.pf-social-a {
    width:32px;height:32px;border-radius:50%;
    border:1px solid var(--border-color);
    display:flex;align-items:center;justify-content:center;
    color:var(--text-muted);font-size:.85rem;
    text-decoration:none;transition:.2s;
    background:var(--bg-surface-2);
}
.pf-social-a:hover { border-color:var(--primary);color:var(--primary);background:rgba(230,57,70,.08); }

/* Actions */
.pf-actions { padding:14px 0 16px;display:flex;gap:8px;flex-shrink:0;align-items:flex-start;flex-wrap:wrap; }

/* XP Bar */
.pf-xp {
    width:100%;max-width:1200px;margin:0 auto;
    padding:6px 24px 14px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;
}
.pf-xp__lbl { font-size:.72rem;color:var(--text-muted);white-space:nowrap; }
.pf-xp__bar { flex:1;max-width:280px;height:6px;background:var(--bg-surface-3);border-radius:3px;overflow:hidden;min-width:80px; }
.pf-xp__fill { height:100%;border-radius:3px;background:linear-gradient(90deg,var(--primary),#7c3aed);transition:width 1.2s ease; }
.pf-xp__pts  { font-size:.72rem;font-weight:700;color:var(--primary);white-space:nowrap; }

/* ════════════════════════════════════════════════════════════
   STATS BAR (debajo del perfil)
════════════════════════════════════════════════════════════ */
.pf-statsbar {
    background:var(--bg-surface);
    border-bottom:1px solid var(--border-color);
    border-top:1px solid var(--border-color);
}
.pf-statsbar__inner {
    max-width:1200px;margin:0 auto;padding:0 24px;
    display:flex;
}
.pf-statitem {
    flex:1;text-align:center;padding:14px 8px;
    border-right:1px solid var(--border-color);
    text-decoration:none;transition:background .15s;
}
.pf-statitem:last-child { border-right:none; }
.pf-statitem:hover { background:var(--bg-surface-2); }
.pf-statitem__val {
    font-size:1.2rem;font-weight:900;
    color:var(--text-primary);line-height:1;
}
.pf-statitem__lbl {
    font-size:.67rem;color:var(--text-muted);
    text-transform:uppercase;letter-spacing:.05em;margin-top:3px;
}

/* ════════════════════════════════════════════════════════════
   TABS DE NAVEGACIÓN
════════════════════════════════════════════════════════════ */
.pf-tabs-wrap {
    background:var(--bg-surface);
    border-bottom:1px solid var(--border-color);
    position:sticky;top:var(--topbar-h, 62px);z-index:90;
    box-shadow:var(--shadow-sm);
}
.pf-tabs {
    max-width:1200px;margin:0 auto;padding:0 24px;
    display:flex;overflow-x:auto;scrollbar-width:none;
    gap:0;
}
.pf-tabs::-webkit-scrollbar { display:none; }
.pf-tab {
    display:inline-flex;align-items:center;gap:6px;
    padding:0 16px;height:50px;
    font-size:.8rem;font-weight:600;
    color:var(--text-muted);text-decoration:none;
    border-bottom:3px solid transparent;
    white-space:nowrap;transition:all .18s;
    position:relative;
}
.pf-tab:hover { color:var(--primary);background:rgba(230,57,70,.03); }
.pf-tab.active {
    color:var(--primary);
    border-bottom-color:var(--primary);
}
.pf-tab__badge {
    min-width:17px;height:17px;padding:0 4px;
    background:var(--primary);color:#fff;
    border-radius:var(--border-radius-full);
    font-size:.62rem;font-weight:700;
    display:inline-flex;align-items:center;justify-content:center;
}

/* ════════════════════════════════════════════════════════════
   CONTENIDO PRINCIPAL (mismo que admin-content)
════════════════════════════════════════════════════════════ */
.pf-content {
    max-width:1200px;margin:0 auto;
    padding:24px 24px 80px;
}
.pf-grid { display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start; }
@media(max-width:920px){ .pf-grid { grid-template-columns:1fr; } }

/* ════════════════════════════════════════════════════════════
   ADMIN-CARD (idéntico al dashboard)
════════════════════════════════════════════════════════════ */
.pf-card {
    background:var(--bg-surface);
    border:1px solid var(--border-color);
    border-radius:var(--border-radius-xl);
    box-shadow:var(--shadow-sm);
    overflow:hidden;
    margin-bottom:18px;
}
.pf-card:last-child { margin-bottom:0; }
.pf-card__head {
    padding:14px 20px;
    border-bottom:1px solid var(--border-color);
    display:flex;align-items:center;justify-content:space-between;gap:12px;
}
.pf-card__title {
    font-size:.875rem;font-weight:700;
    color:var(--text-primary);
    display:flex;align-items:center;gap:8px;
}
.pf-card__title i { color:var(--primary); }
.pf-card__action {
    font-size:.75rem;color:var(--primary);font-weight:600;
    text-decoration:none;display:flex;align-items:center;gap:4px;
    transition:opacity .15s;
}
.pf-card__action:hover { opacity:.7; }
.pf-card__body { padding:20px; }
.pf-card__body--p0 { padding:0; }

/* ════════════════════════════════════════════════════════════
   STAT-CARD (idéntico al dashboard)
════════════════════════════════════════════════════════════ */
.pf-kpis {
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(150px,1fr));
    gap:12px;margin-bottom:18px;
}
.pf-kpi {
    background:var(--bg-surface);
    border:1px solid var(--border-color);
    border-radius:var(--border-radius-lg);
    padding:14px 16px;
    box-shadow:var(--shadow-sm);
    position:relative;overflow:hidden;
    transition:all .2s;
    display:flex;flex-direction:column;gap:6px;
}
.pf-kpi::before {
    content:'';position:absolute;top:0;left:0;right:0;height:3px;
    background:var(--kpi-color,var(--primary));
}
.pf-kpi:hover { transform:translateY(-2px);box-shadow:var(--shadow-md); }
.pf-kpi__head { display:flex;align-items:center;justify-content:space-between;margin-bottom:2px; }
.pf-kpi__icon {
    width:34px;height:34px;border-radius:var(--border-radius);
    display:flex;align-items:center;justify-content:center;font-size:1rem;
}
.pf-kpi__trend {
    font-size:.62rem;font-weight:700;padding:2px 6px;
    border-radius:var(--border-radius-full);
    background:var(--bg-surface-3);color:var(--text-muted);
}
.pf-kpi__val { font-size:1.4rem;font-weight:900;color:var(--text-primary);line-height:1; }
.pf-kpi__lbl { font-size:.7rem;color:var(--text-muted);font-weight:500; }

/* ════════════════════════════════════════════════════════════
   LISTAS DE NOTICIAS
════════════════════════════════════════════════════════════ */
.pf-news-list { display:flex;flex-direction:column; }
.pf-news-item {
    display:flex;gap:12px;align-items:flex-start;
    padding:12px 0;border-bottom:1px solid var(--border-color);
}
.pf-news-item:last-child { border-bottom:none; }
.pf-news-img {
    width:72px;height:54px;flex-shrink:0;
    border-radius:var(--border-radius);object-fit:cover;
    background:var(--bg-surface-2);
}
.pf-news-body { flex:1;min-width:0; }
.pf-news-cat  { font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;text-decoration:none;display:block;margin-bottom:3px; }
.pf-news-ttl  { font-size:.84rem;font-weight:700;color:var(--text-primary);line-height:1.4;text-decoration:none;display:block; }
.pf-news-ttl:hover { color:var(--primary); }
.pf-news-meta { display:flex;gap:10px;flex-wrap:wrap;font-size:.7rem;color:var(--text-muted);margin-top:4px; }

/* ════════════════════════════════════════════════════════════
   MANAGE NEWS CARDS (tab noticias)
════════════════════════════════════════════════════════════ */
.pf-mnews {
    display:grid;grid-template-columns:90px 1fr;
    background:var(--bg-surface);
    border:1px solid var(--border-color);
    border-radius:var(--border-radius-lg);
    overflow:hidden;
    box-shadow:var(--shadow-sm);
    transition:.18s;margin-bottom:10px;
}
.pf-mnews:hover { border-color:var(--primary);box-shadow:var(--shadow); }
.pf-mnews__img { width:90px;height:74px;object-fit:cover;background:var(--bg-surface-2); }
.pf-mnews__body { padding:10px 14px;display:flex;flex-direction:column;gap:5px; }
.pf-mnews__ttl { font-size:.83rem;font-weight:700;color:var(--text-primary);text-decoration:none;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden; }
.pf-mnews__ttl:hover { color:var(--primary); }
.pf-mnews__stats { display:flex;gap:12px;font-size:.7rem;color:var(--text-muted);flex-wrap:wrap; }
.pf-mnews__actions { display:flex;gap:6px;flex-wrap:wrap;margin-top:2px; }

/* estado badges */
.badge-pub  { background:rgba(34,197,94,.12);color:var(--success); }
.badge-bor  { background:rgba(245,158,11,.12);color:var(--warning); }
.badge-rev  { background:rgba(99,102,241,.12);color:#6366f1; }
.badge-est  { display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:var(--border-radius-full);font-size:.65rem;font-weight:700;text-transform:uppercase; }

/* ════════════════════════════════════════════════════════════
   NOTIFICACIONES
════════════════════════════════════════════════════════════ */
.pf-notif { display:flex;flex-direction:column; }
.pf-notif-item {
    display:flex;gap:12px;align-items:flex-start;
    padding:12px 20px;border-bottom:1px solid var(--border-color);
    transition:background .15s;position:relative;
}
.pf-notif-item:last-child { border-bottom:none; }
.pf-notif-item.unread { background:rgba(230,57,70,.03); }
.pf-notif-item.unread::before { content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--primary);border-radius:0 2px 2px 0; }
.pf-notif-icon { width:38px;height:38px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.9rem; }
.pf-notif-body { flex:1;min-width:0; }
.pf-notif-msg  { font-size:.83rem;color:var(--text-primary);line-height:1.5; }
.pf-notif-time { font-size:.7rem;color:var(--text-muted);margin-top:2px; }
.pf-notif-acts { display:flex;gap:8px;margin-top:5px; }
.pf-notif-dot  { position:absolute;top:18px;right:16px;width:8px;height:8px;border-radius:50%;background:var(--primary); }

/* filtros notificaciones */
.pf-notif-filters { display:flex;gap:6px;flex-wrap:wrap;padding:12px 20px;border-bottom:1px solid var(--border-color); }
.pf-nf-btn {
    padding:4px 12px;border-radius:var(--border-radius-full);
    border:1px solid var(--border-color);
    background:transparent;color:var(--text-muted);
    font-size:.75rem;font-weight:600;cursor:pointer;
    text-decoration:none;transition:.15s;
}
.pf-nf-btn:hover,.pf-nf-btn.active { background:var(--primary);color:#fff;border-color:var(--primary); }

/* ════════════════════════════════════════════════════════════
   USERS GRID (siguiendo/seguidores)
════════════════════════════════════════════════════════════ */
.pf-users-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:12px; }
.pf-user-card {
    background:var(--bg-surface);
    border:1px solid var(--border-color);
    border-radius:var(--border-radius-lg);
    padding:18px 14px;text-align:center;
    box-shadow:var(--shadow-sm);transition:.2s;
}
.pf-user-card:hover { border-color:var(--primary);transform:translateY(-2px);box-shadow:var(--shadow-md); }
.pf-user-av { width:60px;height:60px;border-radius:50%;object-fit:cover;background:var(--bg-surface-2);margin:0 auto 8px;border:3px solid var(--border-color);display:block; }
.pf-user-name { font-size:.85rem;font-weight:700;color:var(--text-primary);text-decoration:none;display:block;margin-bottom:2px; }
.pf-user-name:hover { color:var(--primary); }
.pf-user-role { font-size:.7rem;color:var(--text-muted);text-transform:capitalize; }
.pf-user-meta { font-size:.7rem;color:var(--text-muted);margin-top:6px;display:flex;align-items:center;justify-content:center;gap:4px; }

/* ════════════════════════════════════════════════════════════
   FORMULARIOS DE CONFIGURACIÓN
════════════════════════════════════════════════════════════ */
.pf-form-grid { display:grid;grid-template-columns:1fr 1fr;gap:14px; }
@media(max-width:600px){ .pf-form-grid { grid-template-columns:1fr; } }
.pf-full { grid-column:1/-1; }
.pf-field { display:flex;flex-direction:column;gap:5px; }
.pf-label {
    font-size:.75rem;font-weight:700;color:var(--text-secondary);
    text-transform:uppercase;letter-spacing:.04em;
    display:flex;align-items:center;gap:5px;
}
.pf-label i { color:var(--primary); }
.pf-input,
.pf-textarea,
.pf-select {
    width:100%;padding:10px 14px;
    background:var(--bg-surface-2);
    border:1px solid var(--border-color);
    border-radius:var(--border-radius);
    color:var(--text-primary);font-size:.875rem;
    transition:border-color .18s,box-shadow .18s;
    font-family:inherit;
}
.pf-input:focus,.pf-textarea:focus,.pf-select:focus {
    outline:none;border-color:var(--primary);
    box-shadow:0 0 0 3px rgba(230,57,70,.1);
    background:var(--bg-surface);
}
.pf-textarea { resize:vertical;min-height:88px; }
.pf-helper { font-size:.7rem;color:var(--text-muted);margin-top:1px; }

/* upload zones */
.pf-upload-label {
    display:inline-flex;align-items:center;gap:7px;
    padding:8px 16px;border:1px dashed var(--border-color);
    border-radius:var(--border-radius-full);background:var(--bg-surface-2);
    font-size:.8rem;font-weight:600;color:var(--text-secondary);
    cursor:pointer;transition:.2s;
}
.pf-upload-label:hover { border-color:var(--primary);color:var(--primary);background:rgba(230,57,70,.05); }
.pf-avatar-prev { width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid var(--border-color);background:var(--bg-surface-2); }
.pf-cover-prev  { width:100%;height:100px;object-fit:cover;border-radius:var(--border-radius-lg);background:linear-gradient(135deg,var(--primary),#7c3aed);margin-bottom:10px; }

/* toggle switch */
.pf-toggle-row {
    display:flex;align-items:center;justify-content:space-between;
    padding:12px 0;border-bottom:1px solid var(--border-color);
}
.pf-toggle-row:last-child { border-bottom:none; }
.pf-toggle-info { flex:1;padding-right:16px; }
.pf-toggle-lbl  { font-size:.84rem;font-weight:600;color:var(--text-primary);display:flex;align-items:center;gap:7px; }
.pf-toggle-lbl i { color:var(--primary); }
.pf-toggle-desc { font-size:.72rem;color:var(--text-muted);margin-top:2px; }
.pf-switch { position:relative;width:42px;height:22px;flex-shrink:0; }
.pf-switch input { opacity:0;width:0;height:0; }
.pf-switch-track {
    position:absolute;inset:0;background:var(--bg-surface-3);
    border:1px solid var(--border-color);
    border-radius:11px;cursor:pointer;transition:.2s;
}
.pf-switch-track::after {
    content:'';position:absolute;top:2px;left:2px;
    width:16px;height:16px;border-radius:50%;
    background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);
    transition:transform .2s;
}
.pf-switch input:checked+.pf-switch-track { background:var(--primary);border-color:var(--primary); }
.pf-switch input:checked+.pf-switch-track::after { transform:translateX(20px); }

/* sesiones */
.pf-session {
    display:flex;gap:12px;align-items:flex-start;
    padding:12px 0;border-bottom:1px solid var(--border-color);
}
.pf-session:last-child { border-bottom:none; }
.pf-session-icon {
    width:42px;height:42px;border-radius:var(--border-radius);
    background:var(--bg-surface-2);border:1px solid var(--border-color);
    display:flex;align-items:center;justify-content:center;
    font-size:1.1rem;color:var(--text-muted);flex-shrink:0;
}
.pf-session-icon.active { color:var(--success); }
.pf-session-dev  { font-size:.84rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:6px; }
.pf-session-meta { font-size:.72rem;color:var(--text-muted);margin-top:3px; }

/* Premium */
.pf-premium-hero {
    border-radius:var(--border-radius-xl);
    background:linear-gradient(135deg,#92400e,#f59e0b 50%,#ef4444);
    padding:28px 24px;color:#fff;text-align:center;
    position:relative;overflow:hidden;margin-bottom:18px;
}
.pf-premium-hero::before {
    content:'';position:absolute;inset:0;
    background:url("data:image/svg+xml,%3Csvg width='40' height='40' viewBox='0 0 40 40' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Ccircle cx='20' cy='20' r='15'/%3E%3C/g%3E%3C/svg%3E") repeat;
}
.pf-plan-grid { display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px; }
@media(max-width:560px){ .pf-plan-grid { grid-template-columns:1fr; } }
.pf-plan {
    background:var(--bg-surface);border:2px solid var(--border-color);
    border-radius:var(--border-radius-xl);padding:20px;text-align:center;
    position:relative;cursor:pointer;transition:.2s;
}
.pf-plan:hover,.pf-plan.hot { border-color:var(--primary);box-shadow:0 0 0 3px rgba(230,57,70,.1); }
.pf-plan__tag {
    position:absolute;top:-11px;left:50%;transform:translateX(-50%);
    background:var(--primary);color:#fff;
    font-size:.65rem;font-weight:800;padding:2px 12px;
    border-radius:var(--border-radius-full);text-transform:uppercase;letter-spacing:.05em;
}
.pf-plan__price { font-size:2rem;font-weight:900;color:var(--text-primary);line-height:1;margin:10px 0 4px; }
.pf-plan__per   { font-size:.75rem;color:var(--text-muted); }
.pf-plan__save  { font-size:.72rem;color:var(--success);font-weight:700;margin-top:4px; }

/* Completitud */
.pf-completeness {
    background:var(--bg-surface);border:1px solid var(--border-color);
    border-radius:var(--border-radius-lg);padding:14px 16px;
    box-shadow:var(--shadow-sm);margin-bottom:14px;
}
.pf-comp-bar { height:7px;background:var(--bg-surface-3);border-radius:4px;overflow:hidden;margin:6px 0 10px; }
.pf-comp-fill { height:100%;border-radius:4px;background:linear-gradient(90deg,var(--primary),#7c3aed);transition:width 1s ease; }
.pf-comp-tips { display:flex;flex-wrap:wrap;gap:5px; }
.pf-comp-tip  { font-size:.68rem;padding:2px 8px;border-radius:var(--border-radius-full);display:inline-flex;align-items:center;gap:3px; }
.tip-done { background:rgba(34,197,94,.1);color:var(--success); }
.tip-todo { background:rgba(245,158,11,.1);color:var(--warning); }

/* Estadísticas / charts */
.pf-chart-wrap { position:relative;height:200px;margin-bottom:14px; }
.pf-cat-bar { margin-bottom:12px; }
.pf-cat-bar__head { display:flex;justify-content:space-between;font-size:.78rem;margin-bottom:4px; }
.pf-cat-bar__name { font-weight:600;color:var(--text-primary); }
.pf-cat-bar__val  { color:var(--text-muted); }
.pf-cat-bar__track { height:7px;background:var(--bg-surface-3);border-radius:4px;overflow:hidden; }
.pf-cat-bar__fill  { height:100%;border-radius:4px;transition:width .8s ease; }

/* URL pública */
.pf-url-box {
    display:flex;border:1px solid var(--border-color);
    border-radius:var(--border-radius);overflow:hidden;
    background:var(--bg-surface-2);
}
.pf-url-prefix {
    padding:10px 12px;background:var(--bg-surface-3);
    border-right:1px solid var(--border-color);
    font-size:.73rem;color:var(--text-muted);white-space:nowrap;
}
.pf-url-input { flex:1;padding:10px 12px;background:transparent;border:none;color:var(--text-primary);font-size:.82rem;outline:none; }
.pf-url-copy {
    padding:8px 14px;background:var(--primary);color:#fff;
    border:none;cursor:pointer;font-size:.75rem;font-weight:700;
    display:flex;align-items:center;gap:4px;transition:.15s;
}
.pf-url-copy:hover { background:var(--primary-dark); }

/* Insignias */
.pf-badges { display:flex;flex-wrap:wrap;gap:8px; }
.pf-badge-item {
    display:inline-flex;align-items:center;gap:7px;
    padding:6px 12px;border-radius:var(--border-radius-full);
    font-size:.77rem;font-weight:700;
    border:1px solid;cursor:default;transition:.18s;
}
.pf-badge-item:hover { transform:translateY(-2px); }
.pf-badge-ico {
    width:26px;height:26px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;font-size:.8rem;
}

/* categorías */
.pf-cats { display:flex;flex-wrap:wrap;gap:7px; }
.pf-cat-tag {
    display:inline-flex;align-items:center;gap:5px;
    padding:5px 12px;border-radius:var(--border-radius-full);
    border:1px solid;font-size:.77rem;font-weight:600;
    text-decoration:none;transition:.18s;cursor:pointer;
}
.pf-cat-tag:hover { transform:translateY(-1px);box-shadow:var(--shadow-sm); }

/* empty states */
.pf-empty { text-align:center;padding:44px 16px;color:var(--text-muted); }
.pf-empty__ico { font-size:2.8rem;opacity:.25;margin-bottom:12px; }
.pf-empty__ttl { font-size:.95rem;font-weight:700;color:var(--text-secondary);margin-bottom:6px; }
.pf-empty p    { font-size:.82rem; }

/* danger zone */
.pf-danger {
    border:1px solid rgba(239,68,68,.25);border-radius:var(--border-radius-xl);
    padding:18px;background:rgba(239,68,68,.03);
}
.pf-danger__ttl { font-size:.84rem;font-weight:700;color:var(--danger);display:flex;align-items:center;gap:6px;margin-bottom:12px; }

/* 2FA card */
.pf-2fa {
    border-radius:var(--border-radius-xl);padding:22px;
    border:1px solid var(--border-color);background:var(--bg-surface);
}
.pf-2fa.on { border-color:rgba(34,197,94,.3); }
.pf-2fa.off{ border-color:rgba(245,158,11,.25); }
.pf-2fa-status {
    display:inline-flex;align-items:center;gap:6px;
    padding:5px 14px;border-radius:var(--border-radius-full);
    font-size:.78rem;font-weight:700;margin:8px 0 14px;
}
.pf-2fa-status.on  { background:rgba(34,197,94,.12);color:var(--success); }
.pf-2fa-status.off { background:rgba(245,158,11,.1);color:var(--warning); }

/* Responsive */
@media(max-width:600px){
    .pf-cover  { height:150px; }
    .pf-avatar { width:82px;height:82px; }
    .pf-avatar-wrap { margin-top:-40px; }
    .pf-infobar__inner,.pf-xp,.pf-statsbar__inner,.pf-tabs { padding-left:14px;padding-right:14px; }
    .pf-content  { padding:14px 14px 60px; }
    .pf-tab      { padding:0 10px;font-size:.74rem; }
    .pf-form-grid{ grid-template-columns:1fr; }
    .pf-kpis     { grid-template-columns:repeat(2,1fr); }
    .pf-users-grid{ grid-template-columns:repeat(2,1fr); }
}
</style>

<!-- ── Flash message ────────────────────────────────────────── -->
<?php if($flashMsg): ?>
<div class="flash-message flash-<?=e($flashMsg['type'])?>" id="flashMsg" role="alert">
    <div class="flash-inner">
        <i class="bi bi-<?=$flashMsg['type']==='success'?'check-circle-fill':'exclamation-circle-fill'?>"></i>
        <span><?=e($flashMsg['message'])?></span>
        <button class="flash-close" onclick="this.closest('.flash-message').remove()"><i class="bi bi-x-lg"></i></button>
    </div>
</div>
<?php endif; ?>

<div class="perfil-page">

<!-- ════════════════════════════════════════════════════
     COVER
════════════════════════════════════════════════════ -->
<div class="pf-cover">
    <?php if(!empty($perfil['cover_image'])): ?>
    <img src="<?=e(getImageUrl($perfil['cover_image'],'cover'))?>" alt="Portada" class="pf-cover__img">
    <?php endif; ?>
    <div class="pf-cover__mask"></div>
    <?php if($esMiPerfil): ?>
    <div class="pf-cover__btns">
        <label for="coverInput" class="pf-cover__btn" style="cursor:pointer">
            <i class="bi bi-camera-fill"></i>
            <?=empty($perfil['cover_image'])?'Añadir portada':'Cambiar portada'?>
        </label>
        <?php if(!empty($perfil['cover_image'])): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar portada?')">
            <?=csrfField()?>
            <input type="hidden" name="action" value="delete_cover">
            <button class="pf-cover__btn" type="submit"><i class="bi bi-trash3-fill"></i></button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ════════════════════════════════════════════════════
     INFO BAR
════════════════════════════════════════════════════ -->
<div class="pf-infobar">
    <div class="pf-infobar__inner">

        <!-- Avatar -->
        <div class="pf-avatar-wrap">
            <img src="<?=e(getImageUrl($perfil['avatar']??'','avatar'))?>"
                 alt="<?=e($perfil['nombre'])?>"
                 class="pf-avatar" id="pfAvatarImg">
            <?php if($perfil['verificado']??false): ?>
            <div class="pf-avatar-verify" title="Periodista verificado">
                <i class="bi bi-check-lg"></i>
            </div>
            <?php endif; ?>
            <?php if($esMiPerfil): ?>
            <label for="avatarInput" class="pf-avatar-edit-btn"><i class="bi bi-camera-fill"></i></label>
            <?php endif; ?>
        </div>

        <!-- Datos -->
        <div class="pf-info">
            <div class="pf-name">
                <?=e($perfil['nombre'])?>
                <?php if($perfil['verificado']??false): ?>
                <span style="display:inline-flex;align-items:center;gap:3px;font-size:.7rem;color:var(--info);background:rgba(59,130,246,.1);padding:2px 8px;border-radius:var(--border-radius-full);font-family:var(--font-sans)"><i class="bi bi-patch-check-fill"></i>Verificado</span>
                <?php endif; ?>
                <?php
                $rolCfg=[
                    'super_admin'=>['bg'=>'rgba(239,68,68,.12)','c'=>'var(--danger)'],
                    'admin'      =>['bg'=>'rgba(239,68,68,.1)', 'c'=>'var(--primary)'],
                    'editor'     =>['bg'=>'rgba(99,102,241,.12)','c'=>'#6366f1'],
                    'periodista' =>['bg'=>'rgba(14,165,233,.12)','c'=>'var(--info)'],
                    'premium'    =>['bg'=>'rgba(245,158,11,.12)','c'=>'var(--warning)'],
                    'user'       =>['bg'=>'rgba(107,114,128,.1)','c'=>'var(--text-muted)'],
                ];
                $rc=$rolCfg[$perfil['rol']]??$rolCfg['user'];
                ?>
                <span class="pf-badge" style="background:<?=$rc['bg']?>;color:<?=$rc['c']?>"><?=e($perfil['rol'])?></span>
                <?php if(!empty($perfil['premium'])||!empty($perfil['es_premium'])): ?>
                <span class="pf-badge" style="background:rgba(245,158,11,.12);color:var(--warning)"><i class="bi bi-star-fill" style="font-size:.6rem"></i>PREMIUM</span>
                <?php endif; ?>
            </div>

            <?php if(!empty($perfil['bio'])): ?>
            <p class="pf-bio"><?=e($perfil['bio'])?></p>
            <?php endif; ?>

            <div class="pf-meta">
                <?php if(!empty($perfil['ciudad'])): ?>
                <span><i class="bi bi-geo-alt-fill"></i><?=e($perfil['ciudad'])?></span>
                <?php endif; ?>
                <span><i class="bi bi-calendar3"></i>Miembro desde <?=formatDate($perfil['fecha_registro'],'short')?></span>
                <?php if(!empty($perfil['ultimo_acceso'])): ?>
                <span><i class="bi bi-clock-fill"></i><?=timeAgo($perfil['ultimo_acceso'])?></span>
                <?php endif; ?>
                <span><i class="bi bi-trophy-fill" style="color:var(--warning)"></i>Nivel <?=(int)($perfil['nivel']??1)?> · <?=number_format((int)($perfil['reputacion']??0))?> XP</span>
            </div>

            <!-- Redes sociales -->
            <?php
            $redes=['twitter'=>['i'=>'bi-twitter-x','u'=>'https://twitter.com/%s'],
                    'instagram'=>['i'=>'bi-instagram','u'=>'https://instagram.com/%s'],
                    'facebook'=>['i'=>'bi-facebook','u'=>'https://facebook.com/%s'],
                    'linkedin'=>['i'=>'bi-linkedin','u'=>'https://linkedin.com/in/%s'],
                    'website'=>['i'=>'bi-globe2','u'=>'%s']];
            $hayRedes=false; foreach($redes as $k=>$_r) if(!empty($perfil[$k])){$hayRedes=true;break;}
            ?>
            <?php if($hayRedes): ?>
            <div class="pf-socials">
                <?php foreach($redes as $k=>$r): ?>
                <?php if(!empty($perfil[$k])): ?>
                <a href="<?=sprintf($r['u'],e($perfil[$k]))?>" target="_blank" rel="noopener" class="pf-social-a" title="<?=ucfirst($k)?>"><i class="bi <?=$r['i']?>"></i></a>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Insignias (top 4) -->
            <?php if(!empty($insigniasPerfil)): ?>
            <div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:10px">
                <?php foreach(array_slice($insigniasPerfil,0,4) as $ins): ?>
                <span title="<?=e($ins['nombre'])?>: <?=e($ins['descripcion'])?>"
                      style="display:inline-flex;align-items:center;gap:4px;
                             padding:3px 9px;border-radius:var(--border-radius-full);
                             font-size:.68rem;font-weight:700;
                             background:<?=e($ins['color'])?>18;color:<?=e($ins['color'])?>;
                             border:1px solid <?=e($ins['color'])?>33">
                    <i class="bi <?=e($ins['icono'])?>"></i><?=e($ins['nombre'])?>
                </span>
                <?php endforeach; ?>
                <?php if(count($insigniasPerfil)>4): ?>
                <span style="font-size:.68rem;color:var(--text-muted);align-self:center">+<?=count($insigniasPerfil)-4?> más</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Acciones derecha -->
        <div class="pf-actions">
            <?php if($esMiPerfil): ?>
            <a href="?tab=configuracion" class="btn-p" style="font-size:.82rem;padding:9px 18px"><i class="bi bi-pencil-fill"></i>Editar</a>
            <?php if(isAdmin()): ?>
            <a href="<?=APP_URL?>/admin/dashboard.php" class="btn-s" style="font-size:.82rem;padding:9px 18px"><i class="bi bi-speedometer2"></i>Admin</a>
            <?php endif; ?>
            <?php else: ?>
            <button onclick="pfToggleFollow(<?=(int)$perfil['id']?>,this)" id="followBtn"
                style="display:inline-flex;align-items:center;gap:7px;
                       padding:9px 20px;border-radius:var(--border-radius-full);
                       font-size:.82rem;font-weight:700;cursor:pointer;border:1px solid;
                       background:<?=$sigueAlPerfil?'var(--bg-surface-2)':'var(--primary)'?>;
                       color:<?=$sigueAlPerfil?'var(--text-primary)':'#fff'?>;
                       border-color:<?=$sigueAlPerfil?'var(--border-color)':'var(--primary)'?>;
                       transition:.18s">
                <i class="bi bi-person-<?=$sigueAlPerfil?'check-fill':'plus-fill'?>"></i>
                <?=$sigueAlPerfil?'Siguiendo':'Seguir'?>
            </button>
            <?php endif; ?>
        </div>

    </div>

    <!-- XP bar -->
    <div class="pf-xp">
        <span class="pf-xp__lbl">Nivel <?=$nivel?></span>
        <div class="pf-xp__bar"><div class="pf-xp__fill" id="pfXpFill" style="width:0%"></div></div>
        <span class="pf-xp__pts"><?=number_format($xp)?> / <?=number_format($xpNext)?> XP</span>
    </div>
</div>

<!-- Stats bar -->
<div class="pf-statsbar">
    <div class="pf-statsbar__inner">
        <?php
        $sb=[
            ['n'=>$perfil['total_noticias']??0,   'l'=>'Artículos', 't'=>'noticias'],
            ['n'=>$perfil['total_comentarios']??0, 'l'=>'Comentarios','t'=>'comentarios'],
            ['n'=>$perfil['total_favoritos']??0,   'l'=>'Guardados',  't'=>'guardados'],
            ['n'=>$perfil['total_seguidores']??0,  'l'=>'Seguidores', 't'=>'seguidores'],
            ['n'=>$perfil['total_seguidos']??0,    'l'=>'Siguiendo',  't'=>'siguiendo'],
            ['n'=>$perfil['reputacion']??0,        'l'=>'Reputación', 't'=>'estadisticas'],
        ];
        foreach($sb as $s):
        ?>
        <a href="?tab=<?=$s['t']?><?=!$esMiPerfil?'&id='.(int)$perfil['id']:''?>" class="pf-statitem">
            <div class="pf-statitem__val"><?=$s['n']>9999?number_format($s['n']/1000,1).'k':number_format((int)$s['n'])?></div>
            <div class="pf-statitem__lbl"><?=$s['l']?></div>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- ════════════════════════════════════════════════════
     TABS
════════════════════════════════════════════════════ -->
<nav class="pf-tabs-wrap">
    <div class="pf-tabs">
        <a href="?tab=actividad<?=!$esMiPerfil?'&id='.(int)$perfil['id']:''?>" class="pf-tab <?=$tabActiva==='actividad'?'active':''?>"><i class="bi bi-house-fill"></i>Actividad</a>
        <?php if($esPeriodista): ?>
        <a href="?tab=noticias<?=!$esMiPerfil?'&id='.(int)$perfil['id']:''?>" class="pf-tab <?=$tabActiva==='noticias'?'active':''?>"><i class="bi bi-newspaper"></i>Artículos</a>
        <?php endif; ?>
        <?php if($esMiPerfil): ?>
        <a href="?tab=guardados" class="pf-tab <?=$tabActiva==='guardados'?'active':''?>"><i class="bi bi-bookmark-heart-fill"></i>Guardados</a>
        <a href="?tab=comentarios" class="pf-tab <?=$tabActiva==='comentarios'?'active':''?>"><i class="bi bi-chat-dots-fill"></i>Comentarios</a>
        <?php endif; ?>
        <a href="?tab=siguiendo<?=!$esMiPerfil?'&id='.(int)$perfil['id']:''?>" class="pf-tab <?=$tabActiva==='siguiendo'?'active':''?>"><i class="bi bi-person-check-fill"></i>Siguiendo</a>
        <a href="?tab=seguidores<?=!$esMiPerfil?'&id='.(int)$perfil['id']:''?>" class="pf-tab <?=$tabActiva==='seguidores'?'active':''?>"><i class="bi bi-people-fill"></i>Seguidores</a>
        <?php if($esMiPerfil): ?>
        <a href="?tab=notificaciones" class="pf-tab <?=$tabActiva==='notificaciones'?'active':''?>">
            <i class="bi bi-bell-fill"></i>Notificaciones
            <?php $nl=(int)($perfil['notif_no_leidas']??0); if($nl>0): ?>
            <span class="pf-tab__badge"><?=$nl>9?'9+':$nl?></span>
            <?php endif; ?>
        </a>
        <?php if($esPeriodista): ?>
        <a href="?tab=estadisticas" class="pf-tab <?=$tabActiva==='estadisticas'?'active':''?>"><i class="bi bi-bar-chart-fill"></i>Estadísticas</a>
        <?php endif; ?>
        <a href="?tab=premium" class="pf-tab <?=$tabActiva==='premium'?'active':''?>"><i class="bi bi-star-fill" style="color:var(--warning)"></i>Premium</a>
        <a href="?tab=preferencias" class="pf-tab <?=$tabActiva==='preferencias'?'active':''?>"><i class="bi bi-sliders"></i>Preferencias</a>
        <a href="?tab=privacidad" class="pf-tab <?=$tabActiva==='privacidad'?'active':''?>"><i class="bi bi-shield-fill-check"></i>Privacidad</a>
        <a href="?tab=configuracion" class="pf-tab <?=$tabActiva==='configuracion'?'active':''?>"><i class="bi bi-gear-fill"></i>Configuración</a>
        <?php endif; ?>
    </div>
</nav>

<!-- ════════════════════════════════════════════════════
     CONTENIDO
════════════════════════════════════════════════════ -->
<div class="pf-content">

    <!-- Errores -->
    <?php if(!empty($errors)): ?>
    <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);
                border-radius:var(--border-radius-lg);padding:12px 16px;
                margin-bottom:18px;font-size:.84rem;color:var(--danger)">
        <?php foreach($errors as $e_): ?><div style="display:flex;align-items:center;gap:7px;margin-bottom:2px"><i class="bi bi-exclamation-circle-fill"></i><?=e($e_)?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>


<!-- ══════════════════ TAB: ACTIVIDAD ══════════════════════ -->
<?php if($tabActiva==='actividad'): ?>
<div class="pf-grid">
<div>

    <!-- Completitud (solo dueño y <100%) -->
    <?php if($esMiPerfil && $complPct<100): ?>
    <div class="pf-completeness">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:.82rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:6px"><i class="bi bi-person-badge-fill" style="color:var(--primary)"></i>Completitud del perfil</span>
            <span style="font-size:.85rem;font-weight:900;color:var(--primary)"><?=$complPct?>%</span>
        </div>
        <div class="pf-comp-bar"><div class="pf-comp-fill" id="compFill" style="width:0%"></div></div>
        <div class="pf-comp-tips">
            <?php foreach(['nombre'=>'Nombre','bio'=>'Biografía','avatar'=>'Foto','ciudad'=>'Ciudad','website'=>'Sitio web','twitter'=>'Twitter'] as $c=>$lbl): ?>
            <span class="pf-comp-tip <?=!empty($perfil[$c])?'tip-done':'tip-todo'?>">
                <i class="bi bi-<?=!empty($perfil[$c])?'check-circle-fill':'circle'?>"></i><?=$lbl?>
            </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="pf-kpis">
        <?php
        $kpiList=[
            ['icon'=>'bi-newspaper',          'color'=>'var(--primary)', 'bg'=>'rgba(230,57,70,.1)',   'n'=>$tabData['stats']['noticias'],    'l'=>'Artículos'],
            ['icon'=>'bi-chat-dots-fill',      'color'=>'var(--info)',    'bg'=>'rgba(59,130,246,.1)',  'n'=>$tabData['stats']['comentarios'], 'l'=>'Comentarios'],
            ['icon'=>'bi-bookmark-heart-fill', 'color'=>'var(--success)', 'bg'=>'rgba(34,197,94,.1)',   'n'=>$tabData['stats']['favoritos'],   'l'=>'Guardados'],
            ['icon'=>'bi-people-fill',         'color'=>'#8b5cf6',        'bg'=>'rgba(139,92,246,.1)',  'n'=>$tabData['stats']['seguidores'],  'l'=>'Seguidores'],
            ['icon'=>'bi-person-check-fill',   'color'=>'var(--warning)', 'bg'=>'rgba(245,158,11,.1)',  'n'=>$tabData['stats']['siguiendo'],   'l'=>'Siguiendo'],
            ['icon'=>'bi-trophy-fill',         'color'=>'#f97316',        'bg'=>'rgba(249,115,22,.1)',  'n'=>$tabData['stats']['reputacion'],  'l'=>'Reputación'],
        ];
        foreach($kpiList as $k):
        ?>
        <div class="pf-kpi" style="--kpi-color:<?=$k['color']?>">
            <div class="pf-kpi__head">
                <div class="pf-kpi__icon" style="background:<?=$k['bg']?>;color:<?=$k['color']?>"><i class="bi <?=$k['icon']?>"></i></div>
            </div>
            <div class="pf-kpi__val"><?=number_format((int)$k['n'])?></div>
            <div class="pf-kpi__lbl"><?=$k['l']?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Últimas publicaciones (periodistas) -->
    <?php if($esPeriodista && !empty($tabData['ultimasNoticias'])): ?>
    <div class="pf-card">
        <div class="pf-card__head">
            <div class="pf-card__title"><i class="bi bi-newspaper"></i>Últimas publicaciones</div>
            <a href="?tab=noticias" class="pf-card__action">Ver todas <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="pf-card__body">
            <div class="pf-news-list">
                <?php foreach($tabData['ultimasNoticias'] as $nn): ?>
                <div class="pf-news-item">
                    <div class="pf-news-body">
                        <span class="pf-news-cat" style="color:<?=e($nn['cat_color'])?>"><?=e($nn['cat_nombre'])?></span>
                        <a href="<?=APP_URL?>/noticia.php?slug=<?=e($nn['slug'])?>" class="pf-news-ttl"><?=e(truncateChars($nn['titulo'],70))?></a>
                        <div class="pf-news-meta">
                            <span><i class="bi bi-eye"></i><?=number_format((int)$nn['vistas'])?></span>
                            <span><i class="bi bi-clock"></i><?=timeAgo($nn['fecha_publicacion'])?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Leídas recientemente -->
    <?php if(!empty($tabData['vistas'])): ?>
    <div class="pf-card">
        <div class="pf-card__head">
            <div class="pf-card__title"><i class="bi bi-clock-history"></i>Leídas recientemente</div>
        </div>
        <div class="pf-card__body pf-card__body--p0">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr))">
                <?php foreach($tabData['vistas'] as $v): ?>
                <div class="pf-news-item" style="padding:12px 20px">
                    <img src="<?=e(getImageUrl($v['imagen']??'','noticia'))?>" alt="" class="pf-news-img" loading="lazy">
                    <div class="pf-news-body">
                        <span class="pf-news-cat" style="color:<?=e($v['cat_color'])?>"><?=e($v['cat_nombre'])?></span>
                        <a href="<?=APP_URL?>/noticia.php?slug=<?=e($v['slug'])?>" class="pf-news-ttl"><?=e(truncateChars($v['titulo'],60))?></a>
                        <div class="pf-news-meta"><span><?=timeAgo($v['fv'])?></span></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Sidebar actividad -->
<aside>

    <!-- URL pública -->
    <?php if(!empty($perfil['slug_perfil'])): ?>
    <div class="pf-card">
        <div class="pf-card__head"><div class="pf-card__title"><i class="bi bi-link-45deg"></i>URL de mi perfil</div></div>
        <div class="pf-card__body">
            <div class="pf-url-box">
                <span class="pf-url-prefix"><?=APP_URL?>/</span>
                <input class="pf-url-input" id="pfUrlInput" type="text" value="perfil.php?id=<?=(int)$perfil['id']?>" readonly>
                <button class="pf-url-copy" onclick="pfCopyUrl()"><i class="bi bi-clipboard"></i>Copiar</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Insignias -->
    <div class="pf-card">
        <div class="pf-card__head"><div class="pf-card__title"><i class="bi bi-award-fill"></i>Insignias</div></div>
        <div class="pf-card__body">
            <?php if(!empty($tabData['insignias'])): ?>
            <div class="pf-badges">
                <?php foreach($tabData['insignias'] as $ins): ?>
                <div class="pf-badge-item"
                     style="background:<?=e($ins['color'])?>14;color:<?=e($ins['color'])?>;border-color:<?=e($ins['color'])?>33"
                     title="<?=e($ins['descripcion'])?>">
                    <span class="pf-badge-ico" style="background:<?=e($ins['color'])?>22"><i class="bi <?=e($ins['icono'])?>"></i></span>
                    <?=e($ins['nombre'])?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="pf-empty" style="padding:20px 0">
                <div class="pf-empty__ico">🏆</div>
                <div class="pf-empty__ttl">Sin insignias aún</div>
                <p>Participa más para desbloquearlas.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Info del perfil -->
    <div class="pf-card">
        <div class="pf-card__head"><div class="pf-card__title"><i class="bi bi-person-circle"></i><?=$esMiPerfil?'Mi perfil':'Sobre este usuario'?></div></div>
        <div class="pf-card__body">
            <ul style="list-style:none;display:flex;flex-direction:column;gap:8px">
                <?php if($esMiPerfil||!empty($perfil['perfil_publico_email'])): ?>
                <li style="display:flex;gap:8px;align-items:center;font-size:.8rem;color:var(--text-secondary)">
                    <i class="bi bi-envelope-fill" style="color:var(--primary);width:16px"></i>
                    <?=e($perfil['email'])?>
                </li>
                <?php endif; ?>
                <?php if(!empty($perfil['ciudad'])): ?>
                <li style="display:flex;gap:8px;align-items:center;font-size:.8rem;color:var(--text-secondary)">
                    <i class="bi bi-geo-alt-fill" style="color:var(--primary);width:16px"></i>
                    <?=e($perfil['ciudad'])?>
                </li>
                <?php endif; ?>
                <?php if(!empty($perfil['website'])): ?>
                <li style="display:flex;gap:8px;align-items:center;font-size:.8rem">
                    <i class="bi bi-globe2" style="color:var(--primary);width:16px"></i>
                    <a href="<?=e($perfil['website'])?>" target="_blank" rel="noopener" style="color:var(--primary);text-decoration:none"><?=e($perfil['website'])?></a>
                </li>
                <?php endif; ?>
                <li style="display:flex;gap:8px;align-items:center;font-size:.8rem;color:var(--text-secondary)">
                    <i class="bi bi-calendar3" style="color:var(--primary);width:16px"></i>
                    Desde <?=formatDate($perfil['fecha_registro'],'short')?>
                </li>
            </ul>
        </div>
    </div>

</aside>
</div>


<!-- ══════════════════ TAB: NOTICIAS ══════════════════════ -->
<?php elseif($tabActiva==='noticias'): ?>
<div>
    <?php if($esMiPerfil): ?>
    <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:18px;align-items:center">
        <span style="font-size:.78rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-right:6px">Filtrar:</span>
        <?php foreach(['todos'=>'Todos','publicado'=>'Publicados','borrador'=>'Borradores','revision'=>'En revisión'] as $fk=>$fl): ?>
        <a href="?tab=noticias&estado=<?=$fk?>"
           style="padding:6px 14px;border-radius:var(--border-radius-full);
                  font-size:.78rem;font-weight:600;text-decoration:none;
                  background:<?=($tabData['filtro']??'todos')===$fk?'var(--primary)':'var(--bg-surface)'?>;
                  color:<?=($tabData['filtro']??'todos')===$fk?'#fff':'var(--text-muted)'?>;
                  border:1px solid <?=($tabData['filtro']??'todos')===$fk?'var(--primary)':'var(--border-color)'?>">
            <?=$fl?>
        </a>
        <?php endforeach; ?>
        <?php if($esMiPerfil && isAdmin()): ?>
        <a href="<?=APP_URL?>/admin/noticias.php?action=nueva" class="btn-p" style="margin-left:auto;font-size:.8rem;padding:7px 16px"><i class="bi bi-plus-lg"></i>Nueva</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if(empty($tabData['noticias'])): ?>
    <div class="pf-card"><div class="pf-card__body">
        <div class="pf-empty"><div class="pf-empty__ico">📰</div><div class="pf-empty__ttl">Sin artículos</div><p>No hay artículos con este filtro.</p></div>
    </div></div>
    <?php else: ?>
    <?php foreach($tabData['noticias'] as $nn): ?>
    <div class="pf-mnews">
        <img src="<?=e(getImageUrl($nn['imagen']??'','noticia'))?>" alt="" class="pf-mnews__img" loading="lazy">
        <div class="pf-mnews__body">
            <a href="<?=APP_URL?>/noticia.php?slug=<?=e($nn['slug'])?>" class="pf-mnews__ttl"><?=e(truncateChars($nn['titulo'],80))?></a>
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                <span class="badge-est badge-<?=e($nn['estado'])==='publicado'?'pub':(e($nn['estado'])==='borrador'?'bor':'rev')?>"><?=e($nn['estado'])?></span>
                <?php if(!empty($nn['es_premium'])): ?><span style="font-size:.65rem;font-weight:700;color:var(--warning);background:rgba(245,158,11,.1);padding:2px 7px;border-radius:var(--border-radius-full)"><i class="bi bi-star-fill"></i> PREMIUM</span><?php endif; ?>
                <?php if(!empty($nn['destacado'])): ?><span style="font-size:.65rem;font-weight:700;color:#8b5cf6;background:rgba(139,92,246,.1);padding:2px 7px;border-radius:var(--border-radius-full)"><i class="bi bi-pin-fill"></i> Destacado</span><?php endif; ?>
            </div>
            <div class="pf-mnews__stats">
                <span><i class="bi bi-eye"></i><?=number_format((int)$nn['vistas'])?></span>
                <span><i class="bi bi-hand-thumbs-up"></i><?=(int)$nn['likes']?></span>
                <span><i class="bi bi-chat-dots"></i><?=(int)$nn['tc']?></span>
                <span><i class="bi bi-bookmark"></i><?=(int)$nn['tf']?></span>
                <span><i class="bi bi-clock"></i><?=timeAgo($nn['fecha_publicacion']??$nn['fecha_creacion'])?></span>
            </div>
            <?php if($esMiPerfil): ?>
            <div class="pf-mnews__actions">
                <a href="<?=APP_URL?>/noticia.php?slug=<?=e($nn['slug'])?>" target="_blank"
                   style="padding:4px 10px;border-radius:var(--border-radius-sm);background:var(--bg-surface-2);border:1px solid var(--border-color);font-size:.72rem;font-weight:600;color:var(--text-muted);text-decoration:none;display:flex;align-items:center;gap:4px"><i class="bi bi-eye"></i>Ver</a>
                <a href="<?=APP_URL?>/admin/noticias.php?edit=<?=(int)$nn['id']?>"
                   style="padding:4px 10px;border-radius:var(--border-radius-sm);background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.2);font-size:.72rem;font-weight:600;color:#6366f1;text-decoration:none;display:flex;align-items:center;gap:4px"><i class="bi bi-pencil-fill"></i>Editar</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if(($tabData['pagination']['total_pages']??1)>1): ?>
    <div style="display:flex;justify-content:center;gap:6px;margin-top:20px;flex-wrap:wrap">
        <?php for($p=1;$p<=($tabData['pagination']['total_pages']);$p++): ?>
        <a href="?tab=noticias&estado=<?=$tabData['filtro']?>&pagina=<?=$p?>"
           style="width:34px;height:34px;display:flex;align-items:center;justify-content:center;border-radius:var(--border-radius);border:1px solid var(--border-color);font-size:.8rem;font-weight:700;text-decoration:none;background:<?=$p===($tabData['pagination']['current_page']??1)?'var(--primary)':'var(--bg-surface)'?>;color:<?=$p===($tabData['pagination']['current_page']??1)?'#fff':'var(--text-muted)'?>">
            <?=$p?>
        </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>


<!-- ══════════════════ TAB: GUARDADOS ══════════════════════ -->
<?php elseif($tabActiva==='guardados'): ?>
<div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h2 style="font-size:.95rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:7px">
            <i class="bi bi-bookmark-heart-fill" style="color:var(--primary)"></i>Guardados
            <span style="font-size:.78rem;color:var(--text-muted);font-weight:400">(<?=number_format($tabData['total']??0)?>)</span>
        </h2>
    </div>
    <?php if(empty($tabData['favoritos'])): ?>
    <div class="pf-card"><div class="pf-card__body"><div class="pf-empty"><div class="pf-empty__ico">🔖</div><div class="pf-empty__ttl">Sin guardados</div><p>Guarda noticias para leerlas después.</p><a href="<?=APP_URL?>/index.php" class="btn-p" style="margin-top:14px;font-size:.8rem"><i class="bi bi-newspaper"></i>Explorar</a></div></div></div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:14px">
        <?php foreach($tabData['favoritos'] as $fav): ?>
        <article class="news-card" style="position:relative">
            <button onclick="PDApp?.toggleFav?.(<?=(int)$fav['id']?>,this)"
                    style="position:absolute;top:10px;right:10px;z-index:5;
                           width:30px;height:30px;border-radius:50%;
                           background:rgba(0,0,0,.5);border:none;
                           color:var(--danger);font-size:.85rem;cursor:pointer;
                           display:flex;align-items:center;justify-content:center">
                <i class="bi bi-bookmark-fill"></i>
            </button>
            <?php if(!empty($fav['imagen'])): ?>
            <div class="news-card__img-wrap">
                <a href="<?=APP_URL?>/noticia.php?slug=<?=e($fav['slug'])?>">
                    <img src="<?=e(getImageUrl($fav['imagen'],'noticia'))?>" alt="<?=e($fav['titulo'])?>" class="news-card__img" loading="lazy">
                </a>
            </div>
            <?php endif; ?>
            <div class="news-card__body">
                <a class="news-card__cat" style="color:<?=e($fav['cat_color']??'var(--primary)')?>"><?=e($fav['cat_nombre']??'')?></a>
                <h3 class="news-card__title"><a href="<?=APP_URL?>/noticia.php?slug=<?=e($fav['slug'])?>"><?=e(truncateChars($fav['titulo'],65))?></a></h3>
                <div class="news-card__meta">
                    <span><i class="bi bi-eye"></i><?=number_format((int)$fav['vistas'])?></span>
                    <span><i class="bi bi-clock"></i><?=timeAgo($fav['fecha_publicacion'])?></span>
                </div>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>


<!-- ══════════════════ TAB: COMENTARIOS ══════════════════════ -->
<?php elseif($tabActiva==='comentarios'): ?>
<div>
    <h2 style="font-size:.95rem;font-weight:700;color:var(--text-primary);margin-bottom:16px;display:flex;align-items:center;gap:7px">
        <i class="bi bi-chat-dots-fill" style="color:var(--primary)"></i>Mis comentarios
        <span style="font-size:.78rem;color:var(--text-muted);font-weight:400">(<?=number_format($tabData['total']??0)?>)</span>
    </h2>
    <?php if(empty($tabData['comentarios'])): ?>
    <div class="pf-card"><div class="pf-card__body"><div class="pf-empty"><div class="pf-empty__ico">💬</div><div class="pf-empty__ttl">Sin comentarios</div><p>Participa en las conversaciones.</p></div></div></div>
    <?php else: ?>
    <div class="pf-card">
        <div class="pf-card__body pf-card__body--p0">
            <?php foreach($tabData['comentarios'] as $com): ?>
            <div style="padding:14px 20px;border-bottom:1px solid var(--border-color)">
                <div style="display:flex;align-items:flex-start;gap:4px">
                    <i class="bi bi-quote" style="color:var(--primary);font-size:1.2rem;flex-shrink:0;margin-top:2px"></i>
                    <p style="font-size:.85rem;color:var(--text-primary);line-height:1.6;margin:0 0 7px;font-style:italic"><?=e(truncateChars($com['comentario'],200))?></p>
                </div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <span style="font-size:.68rem;font-weight:800;color:<?=e($com['cat_color'])?>;text-transform:uppercase"><?=e($com['cat_nombre'])?></span>
                    <a href="<?=APP_URL?>/noticia.php?slug=<?=e($com['noticia_slug'])?>" style="font-size:.78rem;color:var(--text-secondary);text-decoration:none;font-weight:600"><?=e(truncateChars($com['noticia_titulo'],55))?></a>
                    <span style="margin-left:auto;display:flex;gap:10px;font-size:.7rem;color:var(--text-muted)">
                        <span><i class="bi bi-clock"></i><?=timeAgo($com['fecha'])?></span>
                        <span><i class="bi bi-hand-thumbs-up"></i><?=(int)$com['likes']?></span>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>


<!-- ══════════════════ TAB: SIGUIENDO / SEGUIDORES ══════════ -->
<?php elseif($tabActiva==='siguiendo'||$tabActiva==='seguidores'): ?>
<?php
$esSiguiendo=$tabActiva==='siguiendo';
$lista=$esSiguiendo?($tabData['siguiendo']??[]):($tabData['seguidores']??[]);
?>
<div>
    <h2 style="font-size:.95rem;font-weight:700;color:var(--text-primary);margin-bottom:16px;display:flex;align-items:center;gap:7px">
        <i class="bi bi-<?=$esSiguiendo?'person-check-fill':'people-fill'?>" style="color:var(--primary)"></i>
        <?=$esSiguiendo?'Siguiendo':'Seguidores'?>
        <span style="font-size:.78rem;color:var(--text-muted);font-weight:400">(<?=count($lista)?>)</span>
    </h2>
    <?php if(empty($lista)): ?>
    <div class="pf-card"><div class="pf-card__body"><div class="pf-empty"><div class="pf-empty__ico">👥</div><div class="pf-empty__ttl"><?=$esSiguiendo?'No sigues a nadie aún':'Sin seguidores aún'?></div><p><?=$esSiguiendo?'Sigue a periodistas y lectores.':'Publica contenido para ganar seguidores.'?></p></div></div></div>
    <?php else: ?>
    <div class="pf-users-grid">
        <?php foreach($lista as $uu): ?>
        <div class="pf-user-card">
            <img src="<?=e(getImageUrl($uu['avatar']??'','avatar'))?>" alt="<?=e($uu['nombre'])?>" class="pf-user-av" loading="lazy">
            <a href="<?=APP_URL?>/perfil.php?id=<?=(int)$uu['id']?>" class="pf-user-name"><?=e($uu['nombre'])?><?php if($uu['verificado']): ?><i class="bi bi-patch-check-fill" style="color:var(--info);font-size:.7rem"></i><?php endif; ?></a>
            <div class="pf-user-role"><?=e($uu['rol'])?></div>
            <?php if(!empty($uu['ciudad'])): ?><div class="pf-user-meta"><i class="bi bi-geo-alt"></i><?=e($uu['ciudad'])?></div><?php endif; ?>
            <div class="pf-user-meta" style="margin-top:5px"><i class="bi bi-trophy" style="color:var(--warning)"></i><?=number_format((int)$uu['reputacion'])?> XP</div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>


<!-- ══════════════════ TAB: NOTIFICACIONES ══════════════════ -->
<?php elseif($tabActiva==='notificaciones'): ?>
<div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
        <h2 style="font-size:.95rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:7px">
            <i class="bi bi-bell-fill" style="color:var(--primary)"></i>Notificaciones
            <?php if($tabData['no_leidas']>0): ?>
            <span style="background:var(--primary);color:#fff;font-size:.68rem;font-weight:800;padding:2px 8px;border-radius:var(--border-radius-full)"><?=$tabData['no_leidas']?> sin leer</span>
            <?php endif; ?>
        </h2>
        <?php if(!empty($tabData['notificaciones'])): ?>
        <div style="display:flex;gap:7px">
            <form method="POST"><<?=csrfField()?>><input type="hidden" name="action" value="mark_notif_read"><input type="hidden" name="notif_id" value="0">
                <button type="submit" class="btn-s" style="font-size:.76rem;padding:7px 12px"><i class="bi bi-check-all"></i>Todo leído</button>
            </form>
            <form method="POST" onsubmit="return confirm('¿Vaciar todas?')"><<?=csrfField()?>><input type="hidden" name="action" value="delete_all_notif">
                <button type="submit" style="padding:7px 12px;border-radius:var(--border-radius-lg);background:rgba(239,68,68,.08);color:var(--danger);border:1px solid rgba(239,68,68,.2);font-size:.76rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:5px"><i class="bi bi-trash3"></i>Vaciar</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <div class="pf-card">
        <div class="pf-notif-filters">
            <?php foreach(['todas'=>'Todas','sistema'=>'Sistema','seguidor'=>'Seguidores','comentario'=>'Comentarios','noticia'=>'Noticias','premium'=>'Premium'] as $fk=>$fl): ?>
            <a href="?tab=notificaciones&tipo=<?=$fk?>" class="pf-nf-btn <?=($tabData['filtro']??'todas')===$fk?'active':''?>"><?=$fl?></a>
            <?php endforeach; ?>
        </div>

        <?php if(empty($tabData['notificaciones'])): ?>
        <div class="pf-card__body"><div class="pf-empty"><div class="pf-empty__ico">🔔</div><div class="pf-empty__ttl">Bandeja vacía</div><p>No tienes notificaciones.</p></div></div>
        <?php else: ?>
        <div class="pf-notif">
            <?php
            $niMap=[
                'sistema'    =>['i'=>'bi-gear-fill',        'c'=>'var(--text-muted)','bg'=>'var(--bg-surface-2)'],
                'seguidor'   =>['i'=>'bi-person-plus-fill', 'c'=>'#8b5cf6',          'bg'=>'rgba(139,92,246,.1)'],
                'comentario' =>['i'=>'bi-chat-dots-fill',   'c'=>'var(--info)',       'bg'=>'rgba(59,130,246,.1)'],
                'noticia'    =>['i'=>'bi-newspaper',        'c'=>'var(--primary)',    'bg'=>'rgba(230,57,70,.1)'],
                'premium'    =>['i'=>'bi-star-fill',        'c'=>'var(--warning)',    'bg'=>'rgba(245,158,11,.1)'],
            ];
            foreach($tabData['notificaciones'] as $notif):
                $ni=$niMap[$notif['tipo']]??$niMap['sistema'];
            ?>
            <div class="pf-notif-item <?=!$notif['leida']?'unread':''?>">
                <div class="pf-notif-icon" style="background:<?=$ni['bg']?>;color:<?=$ni['c']?>"><i class="bi <?=$ni['i']?>"></i></div>
                <div class="pf-notif-body">
                    <div class="pf-notif-msg"><?=!empty($notif['url'])?'<a href="'.e(APP_URL.$notif['url']).'" style="color:inherit;text-decoration:none">'.e($notif['mensaje']).'</a>':e($notif['mensaje'])?></div>
                    <div class="pf-notif-time"><i class="bi bi-clock"></i><?=timeAgo($notif['fecha'])?></div>
                    <div class="pf-notif-acts">
                        <?php if(!$notif['leida']): ?>
                        <form method="POST" style="display:inline"><?=csrfField()?><input type="hidden" name="action" value="mark_notif_read"><input type="hidden" name="notif_id" value="<?=(int)$notif['id']?>"><button type="submit" style="background:none;border:none;cursor:pointer;font-size:.72rem;color:var(--primary);font-weight:600">Marcar leída</button></form>
                        <?php endif; ?>
                        <form method="POST" style="display:inline"><?=csrfField()?><input type="hidden" name="action" value="delete_notif"><input type="hidden" name="notif_id" value="<?=(int)$notif['id']?>"><button type="submit" style="background:none;border:none;cursor:pointer;font-size:.72rem;color:var(--text-muted)"><i class="bi bi-x-lg"></i></button></form>
                    </div>
                </div>
                <?php if(!$notif['leida']): ?><div class="pf-notif-dot"></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>


<!-- ══════════════════ TAB: ESTADÍSTICAS ══════════════════════ -->
<?php elseif($tabActiva==='estadisticas'): ?>
<div>
    <h2 style="font-size:.95rem;font-weight:700;color:var(--text-primary);margin-bottom:16px;display:flex;align-items:center;gap:7px"><i class="bi bi-bar-chart-fill" style="color:var(--primary)"></i>Mis estadísticas</h2>

    <!-- KPIs -->
    <div class="pf-kpis" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">
        <?php
        $tvistas=(int)db()->count("SELECT SUM(vistas) FROM noticias WHERE autor_id=?",[$perfil['id']]);
        $kpiStat=[
            ['icon'=>'bi-newspaper',    'color'=>'var(--primary)', 'bg'=>'rgba(230,57,70,.1)',  'n'=>$perfil['total_noticias']??0,    'l'=>'Artículos'],
            ['icon'=>'bi-eye-fill',     'color'=>'var(--info)',    'bg'=>'rgba(59,130,246,.1)', 'n'=>$tvistas,                         'l'=>'Vistas totales'],
            ['icon'=>'bi-chat-dots-fill','color'=>'var(--success)','bg'=>'rgba(34,197,94,.1)',  'n'=>$perfil['total_comentarios']??0, 'l'=>'Comentarios'],
            ['icon'=>'bi-people-fill',  'color'=>'#8b5cf6',       'bg'=>'rgba(139,92,246,.1)', 'n'=>$perfil['total_seguidores']??0,  'l'=>'Seguidores'],
            ['icon'=>'bi-bookmark-fill','color'=>'var(--warning)', 'bg'=>'rgba(245,158,11,.1)', 'n'=>$perfil['total_favoritos']??0,   'l'=>'Guardados rec.'],
            ['icon'=>'bi-trophy-fill',  'color'=>'#f97316',       'bg'=>'rgba(249,115,22,.1)', 'n'=>$perfil['reputacion']??0,        'l'=>'Reputación'],
        ];
        foreach($kpiStat as $k):
        ?>
        <div class="pf-kpi" style="--kpi-color:<?=$k['color']?>">
            <div class="pf-kpi__head"><div class="pf-kpi__icon" style="background:<?=$k['bg']?>;color:<?=$k['color']?>"><i class="bi <?=$k['icon']?>"></i></div></div>
            <div class="pf-kpi__val"><?=number_format((int)$k['n'])?></div>
            <div class="pf-kpi__lbl"><?=$k['l']?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px">

    <!-- Gráfica actividad -->
    <?php if(!empty($tabData['statsPorMes'])): ?>
    <div class="pf-card" style="grid-column:1/-1">
        <div class="pf-card__head"><div class="pf-card__title"><i class="bi bi-graph-up-arrow"></i>Actividad últimos 6 meses</div></div>
        <div class="pf-card__body"><div class="pf-chart-wrap"><canvas id="actChart"></canvas></div></div>
    </div>
    <?php endif; ?>

    <!-- Top noticias -->
    <?php if(!empty($tabData['topNoticias'])): ?>
    <div class="pf-card">
        <div class="pf-card__head"><div class="pf-card__title"><i class="bi bi-fire" style="color:var(--danger)"></i>Top artículos</div></div>
        <div class="pf-card__body pf-card__body--p0">
            <?php foreach($tabData['topNoticias'] as $idx=>$tn): ?>
            <div style="display:flex;gap:12px;padding:11px 16px;border-bottom:1px solid var(--border-color);align-items:center">
                <span style="font-size:1.1rem;font-weight:900;color:<?=$idx===0?'var(--warning)':($idx===1?'var(--text-muted)':'var(--bg-surface-3)')?>;width:20px;text-align:center;flex-shrink:0"><?=$idx+1?></span>
                <div style="flex:1;min-width:0">
                    <a href="<?=APP_URL?>/noticia.php?slug=<?=e($tn['slug'])?>" style="font-size:.8rem;font-weight:700;color:var(--text-primary);text-decoration:none;display:block;line-height:1.4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=e(truncateChars($tn['titulo'],55))?></a>
                    <div style="font-size:.68rem;color:var(--text-muted);margin-top:2px;display:flex;gap:8px">
                        <span style="color:<?=e($tn['cat_color'])?>;font-weight:700"><?=e($tn['cat_nombre'])?></span>
                        <span><i class="bi bi-chat-dots"></i><?=(int)$tn['tc']?></span>
                    </div>
                </div>
                <span style="font-size:.9rem;font-weight:900;color:var(--primary);flex-shrink:0"><?=number_format((int)$tn['vistas'])?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Distribución categorías -->
    <?php if(!empty($tabData['catDistrib'])): ?>
    <div class="pf-card">
        <div class="pf-card__head"><div class="pf-card__title"><i class="bi bi-grid-3x3-gap-fill"></i>Por categoría</div></div>
        <div class="pf-card__body">
            <?php $maxV=max(array_column($tabData['catDistrib'],'tv')?:[1]); ?>
            <?php foreach($tabData['catDistrib'] as $cd): ?>
            <div class="pf-cat-bar">
                <div class="pf-cat-bar__head">
                    <span class="pf-cat-bar__name"><?=e($cd['nombre'])?></span>
                    <span class="pf-cat-bar__val"><?=number_format((int)$cd['tv'])?> vistas · <?=(int)$cd['tn']?> arts.</span>
                </div>
                <div class="pf-cat-bar__track">
                    <div class="pf-cat-bar__fill" style="width:<?=min(100,(int)round($cd['tv']/$maxV*100))?>%;background:<?=e($cd['color'])?>"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    </div>
</div>


<!-- ══════════════════ TAB: PREMIUM ══════════════════════ -->
<?php elseif($tabActiva==='premium'): ?>
<div style="max-width:680px;margin:0 auto">

    <?php if(!empty($tabData['suscripcion'])): ?>
    <div class="pf-premium-hero">
        <i class="bi bi-star-fill" style="font-size:2.2rem;display:block;margin-bottom:10px;position:relative;z-index:1"></i>
        <h2 style="font-size:1.4rem;font-weight:900;margin:0 0 6px;position:relative;z-index:1">¡Eres usuario Premium!</h2>
        <p style="opacity:.85;margin:0 0 14px;position:relative;z-index:1">Plan <?=e(ucfirst($tabData['suscripcion']['plan']))?> · Activo hasta <?=!empty($tabData['suscripcion']['fecha_fin'])?formatDate($tabData['suscripcion']['fecha_fin'],'short'):'Vitalicio'?></p>
        <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;position:relative;z-index:1">
            <?php foreach(['Sin anuncios','Contenido exclusivo','Descarga artículos'] as $b): ?>
            <span style="background:rgba(255,255,255,.2);padding:5px 14px;border-radius:var(--border-radius-full);font-size:.78rem;font-weight:700"><i class="bi bi-check-circle-fill"></i> <?=$b?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if(!empty($tabData['historial'])): ?>
    <div class="pf-card">
        <div class="pf-card__head"><div class="pf-card__title"><i class="bi bi-receipt"></i>Historial de pagos</div></div>
        <div class="pf-card__body pf-card__body--p0">
            <table style="width:100%;border-collapse:collapse;font-size:.82rem">
                <thead><tr style="border-bottom:1px solid var(--border-color)">
                    <?php foreach(['Plan','Monto','Estado','Fecha'] as $h): ?>
                    <th style="padding:10px 16px;text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);font-weight:700"><?=$h?></th>
                    <?php endforeach; ?>
                </tr></thead>
                <tbody>
                    <?php foreach($tabData['historial'] as $hh): ?>
                    <tr style="border-bottom:1px solid var(--border-color)">
                        <td style="padding:10px 16px;font-weight:600;color:var(--text-primary)"><?=e(ucfirst($hh['plan']))?></td>
                        <td style="padding:10px 16px">$<?=number_format((float)($hh['monto']??0),2)?></td>
                        <td style="padding:10px 16px"><span style="font-size:.68rem;font-weight:800;padding:2px 8px;border-radius:var(--border-radius-full);background:<?=$hh['estado']==='activa'?'rgba(34,197,94,.12)':'var(--bg-surface-3)'?>;color:<?=$hh['estado']==='activa'?'var(--success)':'var(--text-muted)'?>"><?=e(ucfirst($hh['estado']))?></span></td>
                        <td style="padding:10px 16px;color:var(--text-muted)"><?=formatDate($hh['fecha_creacion']??$hh['fecha_inicio'],'short')?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="pf-premium-hero">
        <i class="bi bi-star-fill" style="font-size:2.4rem;display:block;margin-bottom:10px;position:relative;z-index:1"></i>
        <h2 style="font-size:1.5rem;font-weight:900;margin:0 0 8px;position:relative;z-index:1">Desbloquea Premium</h2>
        <p style="opacity:.85;margin:0;position:relative;z-index:1">Accede a todo el contenido exclusivo sin límites</p>
    </div>
    <div class="pf-card" style="margin-bottom:16px">
        <div class="pf-card__head"><div class="pf-card__title"><i class="bi bi-gem"></i>¿Qué incluye?</div></div>
        <div class="pf-card__body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <?php foreach(['bi-ban'=>'Sin publicidad','bi-lock-open-fill'=>'Contenido exclusivo','bi-download'=>'Descarga en PDF','bi-bell-fill'=>'Alertas prioritarias','bi-archive-fill'=>'Archivo completo','bi-person-badge-fill'=>'Insignia Premium','bi-bookmark-heart-fill'=>'Guardados ilimitados','bi-chat-square-dots'=>'Comentarios prioritarios'] as $ic=>$bt): ?>
                <div style="display:flex;align-items:center;gap:8px;font-size:.82rem;font-weight:600;color:var(--text-primary)">
                    <i class="bi <?=$ic?>" style="color:var(--primary);font-size:.9rem;width:18px"></i><?=$bt?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="pf-plan-grid">
        <div class="pf-plan">
            <p style="font-size:.72rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;margin:0 0 6px">Mensual</p>
            <div class="pf-plan__price">$<?=e($tabData['precio_mes'])?></div>
            <div class="pf-plan__per">/ mes</div>
            <a href="<?=APP_URL?>/premium.php?plan=mensual" class="btn-p" style="width:100%;margin-top:14px;display:block;text-align:center;text-decoration:none">Suscribirme</a>
        </div>
        <div class="pf-plan hot">
            <div class="pf-plan__tag">Más popular</div>
            <p style="font-size:.72rem;color:var(--primary);font-weight:700;text-transform:uppercase;margin:0 0 6px">Anual</p>
            <div class="pf-plan__price">$<?=e($tabData['precio_anio'])?></div>
            <div class="pf-plan__per">/ año</div>
            <div class="pf-plan__save">Ahorra ~33%</div>
            <a href="<?=APP_URL?>/premium.php?plan=anual" class="btn-p" style="width:100%;margin-top:12px;display:block;text-align:center;text-decoration:none">Suscribirme</a>
        </div>
    </div>
    <?php endif; ?>
</div>


<!-- ══════════════════ TAB: CONFIGURACIÓN ══════════════════ -->
<?php elseif($tabActiva==='configuracion'): ?>
<div style="max-width:760px;margin:0 auto">
<form method="POST" action="?tab=configuracion" enctype="multipart/form-data" novalidate>
    <?=csrfField()?>
    <input type="hidden" name="action" value="update_profile">

    <!-- Portada -->
    <div class="pf-card">
        <div class="pf-card__head"><div class="pf-card__title"><i class="bi bi-image-fill"></i>Imagen de portada</div></div>
        <div class="pf-card__body">
            <?php if(!empty($perfil['cover_image'])): ?>
            <img src="<?=e(getImageUrl($perfil['cover_image'],'cover'))?>" id="coverPrev" class="pf-cover-prev" alt="Portada">
            <?php else: ?>
            <div id="coverPrev" class="pf-cover-prev" style="display:none"></div>
            <?php endif; ?>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                <label for="coverInput" class="pf-upload-label"><i class="bi bi-cloud-upload-fill"></i><?=empty($perfil['cover_image'])?'Subir portada':'Cambiar portada'?></label>
                <span class="pf-helper">JPG/PNG/WebP · Máx. 2MB · 1200×300px recomendado</span>
            </div>
        </div>
    </div>

    <!-- Avatar -->
    <div class="pf-card">
        <div class="pf-card__head"><div class="pf-card__title"><i class="bi bi-person-circle"></i>Foto de perfil</div></div>
        <div class="pf-card__body">
            <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap">
                <img id="avatarPrev" src="<?=e(getImageUrl($perfil['avatar']??'','avatar'))?>" alt="Avatar" class="pf-avatar-prev">
                <div>
                    <label for="avatarInput" class="pf-upload-label" style="margin-bottom:8px;display:inline-flex"><i class="bi bi-cloud-upload-fill"></i>Cambiar foto</label>
                    <?php if(!empty($perfil['avatar'])): ?>
                    <div style="margin-top:8px">
                        <button type="button" onclick="if(confirm('¿Eliminar foto?'))document.getElementById('delAvForm').submit()"
                                style="background:none;border:none;cursor:pointer;color:var(--danger);font-size:.78rem;font-weight:600;display:flex;align-items:center;gap:4px">
                            <i class="bi bi-trash3"></i>Eliminar foto
                        </button>
                    </div>
                    <?php endif; ?>
                    <p class="pf-helper" style="margin-top:6px">JPG/PNG/WebP · Máx. 1MB</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Información personal -->
    <div class="pf-card">
        <div class="pf-card__head"><div class="pf-card__title"><i class="bi bi-person-fill"></i>Información personal</div></div>
        <div class="pf-card__body">
            <div class="pf-form-grid">
                <div class="pf-field pf-full">
                    <label class="pf-label" for="cfgNombre"><i class="bi bi-type"></i>Nombre completo <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="cfgNombre" name="nombre" class="pf-input" value="<?=e($perfil['nombre'])?>" maxlength="100" required>
                </div>
                <div class="pf-field pf-full">
                    <label class="pf-label" for="cfgBio"><i class="bi bi-card-text"></i>Biografía</label>
                    <textarea id="cfgBio" name="bio" class="pf-textarea" maxlength="500" oninput="document.getElementById('bioC').textContent=this.value.length" placeholder="Cuéntanos algo sobre ti..."><?=e($perfil['bio']??'')?></textarea>
                    <span class="pf-helper"><span id="bioC"><?=mb_strlen($perfil['bio']??'')?></span>/500 caracteres</span>
                </div>
                <div class="pf-field">
                    <label class="pf-label" for="cfgCiudad"><i class="bi bi-geo-alt-fill"></i>Ciudad</label>
                    <input type="text" id="cfgCiudad" name="ciudad" class="pf-input" value="<?=e($perfil['ciudad']??'')?>" maxlength="100" placeholder="Santo Domingo, RD">
                </div>
                <div class="pf-field">
                    <label class="pf-label" for="cfgTel"><i class="bi bi-telephone-fill"></i>Teléfono</label>
                    <input type="tel" id="cfgTel" name="telefono" class="pf-input" value="<?=e($perfil['telefono']??'')?>" maxlength="20" placeholder="+1 809 000-0000">
                </div>
            </div>
        </div>
    </div>

    <!-- Email (solo lectura) -->
    <div class="pf-card">
        <div class="pf-card__head">
            <div class="pf-card__title"><i class="bi bi-envelope-fill"></i>Correo electrónico</div>
            <span style="font-size:.72rem;color:var(--text-muted)"><i class="bi bi-lock-fill"></i>No editable</span>
        </div>
        <div class="pf-card__body">
            <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--bg-surface-2);border-radius:var(--border-radius);border:1px solid var(--border-color)">
                <i class="bi bi-envelope" style="color:var(--text-muted)"></i>
                <span style="font-size:.87rem;color:var(--text-primary);flex:1"><?=e($perfil['email'])?></span>
                <?php if($perfil['email_verificado']??0): ?>
                <span style="font-size:.7rem;font-weight:800;color:var(--success);background:rgba(34,197,94,.1);padding:2px 8px;border-radius:var(--border-radius-full)"><i class="bi bi-check-circle-fill"></i> Verificado</span>
                <?php else: ?>
                <a href="<?=APP_URL?>/verificar-email.php" style="font-size:.72rem;font-weight:800;color:var(--warning);text-decoration:none"><i class="bi bi-exclamation-circle"></i> Verificar</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Redes sociales -->
    <div class="pf-card">
        <div class="pf-card__head"><div class="pf-card__title"><i class="bi bi-share-fill"></i>Redes sociales y web</div></div>
        <div class="pf-card__body">
            <div class="pf-form-grid">
                <div class="pf-field">
                    <label class="pf-label" for="cfgWeb"><i class="bi bi-globe2"></i>Sitio web</label>
                    <input type="url" id="cfgWeb" name="website" class="pf-input" value="<?=e($perfil['website']??'')?>" placeholder="https://tuweb.com">
                </div>
                <div class="pf-field">
                    <label class="pf-label" for="cfgTw"><i class="bi bi-twitter-x"></i>Twitter / X</label>
                    <div style="position:relative"><span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.8rem">@</span><input type="text" id="cfgTw" name="twitter" class="pf-input" style="padding-left:28px" value="<?=e($perfil['twitter']??'')?>" placeholder="usuario"></div>
                </div>
                <div class="pf-field">
                    <label class="pf-label" for="cfgIg"><i class="bi bi-instagram"></i>Instagram</label>
                    <div style="position:relative"><span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.8rem">@</span><input type="text" id="cfgIg" name="instagram" class="pf-input" style="padding-left:28px" value="<?=e($perfil['instagram']??'')?>" placeholder="usuario"></div>
                </div>
                <div class="pf-field">
                    <label class="pf-label" for="cfgFb"><i class="bi bi-facebook"></i>Facebook</label>
                    <input type="text" id="cfgFb" name="facebook" class="pf-input" value="<?=e($perfil['facebook']??'')?>" placeholder="usuario o URL">
                </div>
                <div class="pf-field">
                    <label class="pf-label" for="cfgLi"><i class="bi bi-linkedin"></i>LinkedIn</label>
                    <input type="text" id="cfgLi" name="linkedin" class="pf-input" value="<?=e($perfil['linkedin']??'')?>" placeholder="usuario de LinkedIn">
                </div>
            </div>
        </div>
    </div>

    <!-- Botones -->
    <div style="display:flex;justify-content:flex-end;gap:10px;padding:4px 0 16px">
        <a href="?tab=actividad" class="btn-s">Cancelar</a>
        <button type="submit" class="btn-p"><i class="bi bi-cloud-check-fill"></i>Guardar cambios</button>
    </div>
</form>
</div>


<!-- ══════════════════ TAB: PRIVACIDAD ══════════════════════ -->
<?php elseif($tabActiva==='privacidad'): ?>
<div style="max-width:700px;margin:0 auto">

    <!-- Visibilidad -->
    <div class="pf-card">
        <div class="pf-card__head"><div class="pf-card__title"><i class="bi bi-eye-fill"></i>Visibilidad del perfil</div></div>
        <div class="pf-card__body">
            <form method="POST" action="?tab=privacidad"><?=csrfField()?><input type="hidden" name="action" value="update_privacy">
                <?php foreach([
                    ['k'=>'perfil_publico','i'=>'bi-globe2','l'=>'Perfil público','d'=>'Otros usuarios pueden ver tu perfil'],
                    ['k'=>'perfil_publico_email','i'=>'bi-envelope-fill','l'=>'Mostrar email','d'=>'Tu correo aparece en el perfil público'],
                    ['k'=>'perfil_publico_stats','i'=>'bi-bar-chart-fill','l'=>'Mostrar estadísticas','d'=>'Contadores de noticias, seguidores y reputación visibles'],
                ] as $opt): ?>
                <div class="pf-toggle-row">
                    <div class="pf-toggle-info">
                        <div class="pf-toggle-lbl"><i class="bi <?=$opt['i']?>"></i><?=$opt['l']?></div>
                        <div class="pf-toggle-desc"><?=$opt['d']?></div>
                    </div>
                    <label class="pf-switch">
                        <input type="checkbox" name="<?=$opt['k']?>"
                               <?=!empty($perfil[$opt['k']])?'checked':''?>
                               onchange="this.form.submit()">
                        <span class="pf-switch-track"></span>
                    </label>
                </div>
                <?php endforeach; ?>
            </form>
        </div>
    </div>

    <!-- Cambiar contraseña -->
    <div class="pf-card">
        <div class="pf-card__head"><div class="pf-card__title"><i class="bi bi-key-fill"></i>Cambiar contraseña</div></div>
        <div class="pf-card__body">
            <form method="POST" action="?tab=privacidad" novalidate><?=csrfField()?><input type="hidden" name="action" value="change_password">
                <div class="pf-form-grid">
                    <div class="pf-field pf-full">
                        <label class="pf-label" for="pwCur"><i class="bi bi-lock-fill"></i>Contraseña actual</label>
                        <input type="password" id="pwCur" name="password_actual" class="pf-input" autocomplete="current-password" placeholder="Tu contraseña actual" required>
                    </div>
                    <div class="pf-field">
                        <label class="pf-label" for="pwNew"><i class="bi bi-lock-fill"></i>Nueva contraseña</label>
                        <input type="password" id="pwNew" name="password_nueva" class="pf-input" autocomplete="new-password" placeholder="Mín. 8 caracteres" required oninput="pfPwStrength(this)">
                        <div style="height:5px;border-radius:3px;background:var(--bg-surface-3);margin-top:5px;overflow:hidden"><div id="pwStr" style="height:100%;border-radius:3px;width:0;transition:.3s"></div></div>
                        <span id="pwStrLbl" class="pf-helper"></span>
                    </div>
                    <div class="pf-field">
                        <label class="pf-label" for="pwCnf"><i class="bi bi-lock-fill"></i>Confirmar contraseña</label>
                        <input type="password" id="pwCnf" name="password_confirmar" class="pf-input" autocomplete="new-password" placeholder="Repite la contraseña" required>
                    </div>
                </div>
                <div style="margin-top:14px">
                    <button type="submit" class="btn-p"><i class="bi bi-shield-lock-fill"></i>Actualizar contraseña</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 2FA -->
    <div class="pf-card">
        <div class="pf-card__head"><div class="pf-card__title"><i class="bi bi-phone-fill"></i>Verificación en dos pasos (2FA)</div></div>
        <div class="pf-card__body">
            <div class="pf-2fa <?=$tabData['two_fa']?'on':'off'?>">
                <div style="display:flex;gap:14px;align-items:flex-start">
                    <div style="width:46px;height:46px;border-radius:var(--border-radius-lg);flex-shrink:0;background:<?=$tabData['two_fa']?'rgba(34,197,94,.1)':'rgba(245,158,11,.1)'?>;display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:<?=$tabData['two_fa']?'var(--success)':'var(--warning)'?>">
                        <i class="bi bi-shield-<?=$tabData['two_fa']?'fill-check':'exclamation'?>"></i>
                    </div>
                    <div style="flex:1">
                        <div style="font-size:.9rem;font-weight:700;color:var(--text-primary);margin-bottom:4px"><?=$tabData['two_fa']?'2FA activado — cuenta protegida':'Activa la verificación en dos pasos'?></div>
                        <div style="font-size:.8rem;color:var(--text-muted);line-height:1.5"><?=$tabData['two_fa']?'Recibirás un código en tu correo cada vez que inicies sesión.':'Añade seguridad extra. Recibirás un código por email en cada inicio de sesión.'?></div>
                        <div class="pf-2fa-status <?=$tabData['two_fa']?'on':'off'?>"><i class="bi bi-<?=$tabData['two_fa']?'check-circle-fill':'exclamation-circle-fill'?>"></i><?=$tabData['two_fa']?'Activado':'Desactivado'?></div>
                        <form method="POST" action="?tab=privacidad"><?=csrfField()?><input type="hidden" name="action" value="toggle_2fa"><input type="hidden" name="activar" value="<?=$tabData['two_fa']?'0':'1'?>">
                            <button type="submit" <?=$tabData['two_fa']?'style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:var(--danger);padding:8px 16px;border-radius:var(--border-radius-full);font-size:.8rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:5px"':''?> class="<?=$tabData['two_fa']?'':'btn-p'?>">
                                <i class="bi bi-<?=$tabData['two_fa']?'x-circle':'shield-fill-check'?>"></i><?=$tabData['two_fa']?'Desactivar 2FA':'Activar 2FA'?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sesiones -->
    <div class="pf-card">
        <div class="pf-card__head">
            <div class="pf-card__title"><i class="bi bi-display-fill"></i>Sesiones activas</div>
            <?php if(count($tabData['sesiones']??[])>1): ?>
            <form method="POST" action="?tab=privacidad" onsubmit="return confirm('¿Cerrar todas excepto la actual?')"><?=csrfField()?><input type="hidden" name="action" value="close_all_sessions">
                <button type="submit" style="font-size:.75rem;font-weight:600;color:var(--danger);background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.18);padding:5px 12px;border-radius:var(--border-radius);cursor:pointer"><i class="bi bi-x-circle"></i>Cerrar todas</button>
            </form>
            <?php endif; ?>
        </div>
        <div class="pf-card__body">
            <?php if(empty($tabData['sesiones'])): ?>
            <p style="color:var(--text-muted);font-size:.83rem">No hay sesiones activas.</p>
            <?php else: ?>
            <?php foreach($tabData['sesiones'] as $ses):
                $esActual=($ses['session_id']??'')===session_id();
                $diIco=match(strtolower($ses['dispositivo']??'')){
                    'mobile','smartphone'=>'bi-phone-fill',
                    'tablet'=>'bi-tablet-fill',
                    default=>'bi-laptop-fill'};
            ?>
            <div class="pf-session">
                <div class="pf-session-icon <?=$esActual?'active':''?>"><i class="bi <?=$diIco?>"></i></div>
                <div style="flex:1">
                    <div class="pf-session-dev">
                        <?=e($ses['dispositivo']??'Desconocido')?>
                        <?php if($esActual): ?><span style="font-size:.68rem;font-weight:800;color:var(--success);background:rgba(34,197,94,.1);padding:2px 8px;border-radius:var(--border-radius-full)">Actual</span><?php endif; ?>
                    </div>
                    <div class="pf-session-meta"><i class="bi bi-geo-alt"></i><?=e($ses['ubicacion']??$ses['ip']??'N/A')?> · <i class="bi bi-clock"></i><?=timeAgo($ses['ultima_act']??$ses['fecha_inicio']??'now')?></div>
                </div>
                <?php if(!$esActual): ?>
                <form method="POST" action="?tab=privacidad"><?=csrfField()?><input type="hidden" name="action" value="close_session"><input type="hidden" name="session_id" value="<?=(int)$ses['id']?>">
                    <button type="submit" style="padding:5px 10px;border-radius:var(--border-radius);border:1px solid var(--border-color);background:var(--bg-surface-2);font-size:.75rem;color:var(--text-muted);cursor:pointer;transition:.15s" onmouseover="this.style.borderColor='var(--danger)';this.style.color='var(--danger)'" onmouseout="this.style.borderColor='var(--border-color)';this.style.color='var(--text-muted)'"><i class="bi bi-x-lg"></i></button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Danger Zone -->
    <div class="pf-danger">
        <div class="pf-danger__ttl"><i class="bi bi-exclamation-triangle-fill"></i>Zona de peligro</div>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
            <div>
                <div style="font-size:.85rem;font-weight:700;color:var(--text-primary)">Exportar mis datos</div>
                <div style="font-size:.74rem;color:var(--text-muted)">Descarga toda tu información personal</div>
            </div>
            <button onclick="alert('Próximamente')" class="btn-s" style="font-size:.78rem;padding:7px 14px"><i class="bi bi-download"></i>Exportar</button>
        </div>
    </div>
</div>


<!-- ══════════════════ TAB: PREFERENCIAS ══════════════════ -->
<?php elseif($tabActiva==='preferencias'): ?>
<div style="max-width:700px;margin:0 auto">
<form method="POST" action="?tab=preferencias" id="prefsForm"><?=csrfField()?><input type="hidden" name="action" value="update_preferences">

    <!-- Apariencia -->
    <div class="pf-card">
        <div class="pf-card__head"><div class="pf-card__title"><i class="bi bi-palette-fill"></i>Apariencia</div></div>
        <div class="pf-card__body">

            <div class="pf-field" style="margin-bottom:18px">
                <label class="pf-label"><i class="bi bi-moon-stars-fill"></i>Tema</label>
                <div style="display:flex;gap:8px;margin-top:6px">
                    <?php foreach(['auto'=>['bi-circle-half','Automático'],'light'=>['bi-sun-fill','Claro'],'dark'=>['bi-moon-fill','Oscuro']] as $tk=>[$ti,$tl]): ?>
                    <label style="flex:1;cursor:pointer">
                        <input type="radio" name="tema" value="<?=$tk?>" <?=($perfil['tema']??'auto')===$tk?'checked':''?> style="display:none" onchange="pfRadioStyle(this,'tema')">
                        <span class="pf-input pfRadioBtn" data-group="tema" data-val="<?=$tk?>"
                              style="display:flex;align-items:center;justify-content:center;gap:6px;
                                     cursor:pointer;text-align:center;font-weight:600;font-size:.8rem;
                                     border-color:<?=($perfil['tema']??'auto')===$tk?'var(--primary)':'var(--border-color)'?>;
                                     background:<?=($perfil['tema']??'auto')===$tk?'rgba(230,57,70,.06)':'var(--bg-surface-2)'?>;
                                     color:<?=($perfil['tema']??'auto')===$tk?'var(--primary)':'var(--text-muted)'?>">
                            <i class="bi <?=$ti?>"></i><?=$tl?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="pf-field">
                <label class="pf-label"><i class="bi bi-type"></i>Tamaño de texto</label>
                <div style="display:flex;gap:8px;margin-top:6px">
                    <?php foreach(['small'=>['Pequeño','.78rem'],'medium'=>['Normal','.88rem'],'large'=>['Grande','1rem']] as $fk=>[$fl,$fz]): ?>
                    <label style="flex:1;cursor:pointer">
                        <input type="radio" name="font_size" value="<?=$fk?>" <?=($perfil['font_size']??'medium')===$fk?'checked':''?> style="display:none" onchange="pfRadioStyle(this,'font_size')">
                        <span class="pf-input pfRadioBtn" data-group="font_size" data-val="<?=$fk?>"
                              style="display:flex;align-items:center;justify-content:center;
                                     cursor:pointer;text-align:center;font-weight:600;
                                     font-size:<?=$fz?>;
                                     border-color:<?=($perfil['font_size']??'medium')===$fk?'var(--primary)':'var(--border-color)'?>;
                                     background:<?=($perfil['font_size']??'medium')===$fk?'rgba(230,57,70,.06)':'var(--bg-surface-2)'?>;
                                     color:<?=($perfil['font_size']??'medium')===$fk?'var(--primary)':'var(--text-muted)'?>">
                            <?=$fl?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Notificaciones -->
    <div class="pf-card">
        <div class="pf-card__head"><div class="pf-card__title"><i class="bi bi-bell-fill"></i>Notificaciones y newsletter</div></div>
        <div class="pf-card__body">
            <?php foreach([
                ['k'=>'notif_comentarios','i'=>'bi-chat-dots-fill','l'=>'Comentarios en mis artículos','d'=>'Cuando alguien comenta tu contenido'],
                ['k'=>'notif_seguidores', 'i'=>'bi-person-plus-fill','l'=>'Nuevos seguidores','d'=>'Cuando alguien te empieza a seguir'],
                ['k'=>'notif_noticias',   'i'=>'bi-newspaper',      'l'=>'Noticias de categorías seguidas','d'=>'Novedades de tus categorías de interés'],
                ['k'=>'notif_sistema',    'i'=>'bi-gear-fill',       'l'=>'Notificaciones del sistema','d'=>'Actualizaciones, mantenimiento y avisos'],
                ['k'=>'newsletter',       'i'=>'bi-envelope-paper-fill','l'=>'Newsletter por email','d'=>'Recibe las noticias más importantes en tu correo'],
            ] as $opt): ?>
            <div class="pf-toggle-row">
                <div class="pf-toggle-info">
                    <div class="pf-toggle-lbl"><i class="bi <?=$opt['i']?>"></i><?=$opt['l']?></div>
                    <div class="pf-toggle-desc"><?=$opt['d']?></div>
                </div>
                <label class="pf-switch">
                    <input type="checkbox" name="<?=$opt['k']?>" <?=!empty($perfil[$opt['k']])?'checked':''?>>
                    <span class="pf-switch-track"></span>
                </label>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Categorías seguidas -->
    <div class="pf-card">
        <div class="pf-card__head"><div class="pf-card__title"><i class="bi bi-bookmarks-fill"></i>Categorías seguidas</div></div>
        <div class="pf-card__body">
            <p style="font-size:.8rem;color:var(--text-muted);margin-bottom:12px">Haz clic para seguir o dejar de seguir una categoría.</p>
            <?php $cIds=array_column($tabData['cats_seguidas']??[],'id'); ?>
            <div class="pf-cats">
                <?php foreach($tabData['todas_cats']??[] as $cat):
                    $seg=in_array($cat['id'],$cIds); ?>
                <a href="#" onclick="pfToggleCat(event,<?=(int)$cat['id']?>,this)"
                   class="pf-cat-tag"
                   style="background:<?=$seg?$cat['color'].'18':'var(--bg-surface-2)'?>;
                          color:<?=$seg?$cat['color']:'var(--text-muted)'?>;
                          border-color:<?=$seg?$cat['color'].'44':'var(--border-color)'?>">
                    <i class="bi <?=e($cat['icono'])?>"></i><?=e($cat['nombre'])?>
                    <?php if($seg): ?><i class="bi bi-check-circle-fill" style="font-size:.65rem"></i><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px;padding:4px 0 16px">
        <a href="?tab=actividad" class="btn-s">Cancelar</a>
        <button type="submit" class="btn-p"><i class="bi bi-check2-circle"></i>Guardar preferencias</button>
    </div>
</form>
</div>

<?php endif; // fin tabs ?>
</div><!-- /.pf-content -->
</div><!-- /.perfil-page -->

<!-- Forms ocultos -->
<form id="delAvForm" method="POST" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete_avatar"></form>
<input type="file" id="avatarInput" name="avatar" accept="image/*" style="display:none" onchange="pfPrevAvatar(this)">
<input type="file" id="coverInput" name="cover_image" accept="image/*" style="display:none" onchange="pfPrevCover(this)">

<!-- ════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════ -->
<script>
(function(){
'use strict';

// ── XP / Completitud animados al cargar ───────────────
window.addEventListener('load',function(){
    var xf=document.getElementById('pfXpFill');
    if(xf) setTimeout(function(){xf.style.width='<?=$xpPct?>%';},400);
    var cf=document.getElementById('compFill');
    if(cf) setTimeout(function(){cf.style.width='<?=$complPct?>%';},500);
});

// ── Preview avatar ────────────────────────────────────
window.pfPrevAvatar=function(input){
    if(!input.files||!input.files[0])return;
    var r=new FileReader();
    r.onload=function(e){
        var src=e.target.result;
        ['pfAvatarImg','avatarPrev'].forEach(function(id){
            var el=document.getElementById(id);
            if(el) el.src=src;
        });
    };
    r.readAsDataURL(input.files[0]);
    // adjuntar al form principal
    var form=document.querySelector('form[enctype]');
    if(form){
        var dt=new DataTransfer();
        dt.items.add(input.files[0]);
        var fi=form.querySelector('input[name="avatar"]');
        if(!fi){ fi=document.createElement('input'); fi.type='file'; fi.name='avatar'; fi.style.display='none'; form.appendChild(fi); }
        fi.files=dt.files;
    }
};

// ── Preview cover ─────────────────────────────────────
window.pfPrevCover=function(input){
    if(!input.files||!input.files[0])return;
    var r=new FileReader();
    r.onload=function(e){
        var src=e.target.result;
        var cp=document.getElementById('coverPrev');
        if(cp){ cp.src=src; cp.style.display='block'; cp.tagName==='DIV'?(cp.style.background='url('+src+') center/cover'):null; }
        // cover del header
        var hc=document.querySelector('.pf-cover__img');
        if(hc) hc.src=src;
    };
    r.readAsDataURL(input.files[0]);
    // adjuntar al form principal
    var form=document.querySelector('form[enctype]');
    if(form){
        var dt=new DataTransfer();
        dt.items.add(input.files[0]);
        var fi=form.querySelector('input[name="cover_image"]');
        if(!fi){ fi=document.createElement('input'); fi.type='file'; fi.name='cover_image'; fi.style.display='none'; form.appendChild(fi); }
        fi.files=dt.files;
    }
};

// ── Copiar URL del perfil ─────────────────────────────
window.pfCopyUrl=function(){
    var inp=document.getElementById('pfUrlInput');
    if(!inp)return;
    var full='<?=APP_URL?>/'+inp.value;
    navigator.clipboard?navigator.clipboard.writeText(full).then(function(){
        if(typeof PDApp!=='undefined') PDApp.showToast('URL copiada','success',2000);
    }):null;
};

// ── Seguir usuario ────────────────────────────────────
window.pfToggleFollow=async function(userId,btn){
    if(!window.APP?.userId){ window.location.href='<?=APP_URL?>/login.php'; return; }
    try{
        var res=await fetch('<?=APP_URL?>/ajax/handler.php',{
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-Token':window.APP?.csrfToken??'','X-Requested-With':'XMLHttpRequest'},
            body:JSON.stringify({action:'toggle_follow',user_id:userId})
        });
        var data=await res.json();
        if(data.success){
            var f=data.action==='followed';
            btn.innerHTML='<i class="bi bi-person-'+(f?'check-fill':'plus-fill')+'"></i>'+(f?'Siguiendo':'Seguir');
            btn.style.background=f?'var(--bg-surface-2)':'var(--primary)';
            btn.style.color=f?'var(--text-primary)':'#fff';
            btn.style.borderColor=f?'var(--border-color)':'var(--primary)';
            if(typeof PDApp!=='undefined') PDApp.showToast(data.message,'success',2000);
        }
    }catch(e){console.error(e);}
};

// ── Seguir categoría ──────────────────────────────────
window.pfToggleCat=async function(e,catId,el){
    e.preventDefault();
    try{
        var res=await fetch('<?=APP_URL?>/ajax/handler.php',{
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-Token':window.APP?.csrfToken??'','X-Requested-With':'XMLHttpRequest'},
            body:JSON.stringify({action:'toggle_follow_cat',cat_id:catId})
        });
        var data=await res.json();
        if(data.success && typeof PDApp!=='undefined') PDApp.showToast(data.message,'success',1800);
        // visual feedback
        if(data.success){
            var isNow=data.action==='followed';
            var ic=el.querySelector('.bi-check-circle-fill');
            if(isNow&&!ic){ var ni=document.createElement('i'); ni.className='bi bi-check-circle-fill'; ni.style.fontSize='.65rem'; el.appendChild(ni); }
            else if(!isNow&&ic){ ic.remove(); }
        }
    }catch(e){console.error(e);}
};

// ── Fuerza de contraseña ──────────────────────────────
window.pfPwStrength=function(inp){
    var v=inp.value,s=0;
    if(v.length>=8)s++;if(v.length>=12)s++;
    if(/[A-Z]/.test(v))s++;if(/[0-9]/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;
    var cfgs=[['0%','',''],['20%','#ef4444','Muy débil'],['40%','#f97316','Débil'],['60%','#f59e0b','Aceptable'],['80%','#22c55e','Fuerte'],['100%','#059669','Muy fuerte']];
    var c=cfgs[Math.min(s,5)];
    var bar=document.getElementById('pwStr'),lbl=document.getElementById('pwStrLbl');
    if(bar){bar.style.width=c[0];bar.style.background=c[1];}
    if(lbl){lbl.textContent=c[2];lbl.style.color=c[1];}
};

// ── Radio buttons visuales ────────────────────────────
window.pfRadioStyle=function(radio,group){
    document.querySelectorAll('.pfRadioBtn[data-group="'+group+'"]').forEach(function(sp){
        var active=sp.dataset.val===radio.value;
        sp.style.borderColor=active?'var(--primary)':'var(--border-color)';
        sp.style.background=active?'rgba(230,57,70,.06)':'var(--bg-surface-2)';
        sp.style.color=active?'var(--primary)':'var(--text-muted)';
    });
};

// ── Auto-cerrar flash ─────────────────────────────────
var fl=document.getElementById('flashMsg');
if(fl) setTimeout(function(){fl.style.transition='all .4s';fl.style.opacity='0';fl.style.transform='translateY(-8px)';setTimeout(function(){fl.remove();},400);},4500);

// ── Scroll activo en tab visible ──────────────────────
var at=document.querySelector('.pf-tab.active');
if(at) at.scrollIntoView({behavior:'smooth',block:'nearest',inline:'center'});

// ── Animación entrada cards ───────────────────────────
if('IntersectionObserver' in window){
    var obs=new IntersectionObserver(function(entries){
        entries.forEach(function(en){
            if(en.isIntersecting){
                en.target.style.opacity='1';
                en.target.style.transform='translateY(0)';
                obs.unobserve(en.target);
            }
        });
    },{threshold:.06});
    document.querySelectorAll('.pf-card,.pf-kpi,.pf-user-card,.pf-mnews').forEach(function(el){
        el.style.opacity='0';
        el.style.transform='translateY(14px)';
        el.style.transition='opacity .35s ease,transform .35s ease';
        obs.observe(el);
    });
}

// ── Chart estadísticas ────────────────────────────────
<?php if($tabActiva==='estadisticas'&&!empty($tabData['statsPorMes'])): ?>
if(typeof Chart!=='undefined'){
    var ctx=document.getElementById('actChart');
    if(ctx){
        var isDk=document.documentElement.getAttribute('data-theme')==='dark';
        var tick=isDk?'#606080':'#8888aa';
        var grid=isDk?'rgba(255,255,255,.05)':'rgba(0,0,0,.05)';
        new Chart(ctx,{
            type:'bar',
            data:{
                labels:<?=json_encode(array_column($tabData['statsPorMes'],'mes'))?>,
                datasets:[
                    {label:'Vistas',data:<?=json_encode(array_column($tabData['statsPorMes'],'vistas'))?>,
                     backgroundColor:'rgba(230,57,70,.6)',borderColor:'#e63946',borderWidth:1,borderRadius:4,yAxisID:'y'},
                    {label:'Artículos',data:<?=json_encode(array_column($tabData['statsPorMes'],'noticias'))?>,
                     type:'line',backgroundColor:'rgba(99,102,241,.15)',borderColor:'#6366f1',borderWidth:2,
                     pointRadius:4,pointBackgroundColor:'#6366f1',tension:.4,fill:true,yAxisID:'y1'}
                ]
            },
            options:{responsive:true,maintainAspectRatio:false,
                interaction:{mode:'index',intersect:false},
                plugins:{legend:{labels:{color:tick,font:{size:11}}},tooltip:{backgroundColor:'var(--bg-surface-2)',titleColor:'var(--text-primary)',bodyColor:'var(--text-secondary)'}},
                scales:{
                    x:{ticks:{color:tick,font:{size:10}},grid:{color:grid}},
                    y:{type:'linear',position:'left',ticks:{color:tick,font:{size:10}},grid:{color:grid}},
                    y1:{type:'linear',position:'right',ticks:{color:'#6366f1',font:{size:10}},grid:{drawOnChartArea:false}}
                }}
        });
    }
}
<?php endif; ?>

})();
</script>

<?php require_once __DIR__.'/includes/footer.php'; ?>