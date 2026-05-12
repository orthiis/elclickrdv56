<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Admin: Encuestas
 * ============================================================
 * Archivo  : admin/encuestas.php
 * Versión  : 2.0.0
 * ============================================================
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// ── Protección ────────────────────────────────────────────────
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$usuario = currentUser();

// ── CSRF local (mismo patrón que coberturasenvivo.php) ────────
$csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrfToken;

function verifyCsrfLocal(string $token): bool {
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// ── Parámetros ────────────────────────────────────────────────
$action       = trim($_GET['action'] ?? 'lista');
$encId        = (int)($_GET['id']    ?? 0);
$pagina       = max(1, (int)($_GET['pag']    ?? 1));
$filtroEstado = trim($_GET['estado'] ?? '');
$filtroQ      = trim($_GET['q']      ?? '');
$perPage      = 20;

// ── Helper: generar slug único ────────────────────────────────
function slugEncuesta(string $pregunta, int $excludeId = 0): string
{
    $slug = mb_strtolower($pregunta, 'UTF-8');
    $slug = preg_replace('/[áàäâã]/u', 'a', $slug);
    $slug = preg_replace('/[éèëê]/u',  'e', $slug);
    $slug = preg_replace('/[íìïî]/u',  'i', $slug);
    $slug = preg_replace('/[óòöôõ]/u', 'o', $slug);
    $slug = preg_replace('/[úùüû]/u',  'u', $slug);
    $slug = preg_replace('/[ñ]/u',     'n', $slug);
    $slug = preg_replace('/[^a-z0-9\s-]/u', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug = mb_substr($slug, 0, 200);

    $base = $slug;
    $i    = 0;
    while (true) {
        $exists = db()->fetchOne(
            "SELECT id FROM encuestas WHERE slug = ? AND id != ? LIMIT 1",
            [$slug, $excludeId]
        );
        if (!$exists) break;
        $slug = $base . '-' . (++$i);
    }
    return $slug;
}

// ── Procesar POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfLocal($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Token de seguridad inválido. Recarga la página.');
        header('Location: ' . APP_URL . '/admin/encuestas.php');
        exit;
    }

    $postAction = trim($_POST['post_action'] ?? '');

    // ── CREAR ─────────────────────────────────────────────────
    if ($postAction === 'crear') {
        $pregunta    = trim($_POST['pregunta']    ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $tipo        = in_array($_POST['tipo'] ?? '', ['unica','multiple'])
                       ? $_POST['tipo'] : 'unica';
        $activa      = isset($_POST['activa']) ? 1 : 0;
        $mostrar     = in_array($_POST['mostrar_resultados'] ?? '',
                                ['siempre','despues_votar','nunca'])
                       ? $_POST['mostrar_resultados'] : 'despues_votar';
        $catId       = (int)($_POST['categoria_id'] ?? 0) ?: null;
        $color       = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '')
                       ? $_POST['color'] : '#e63946';
        $esStan      = isset($_POST['es_standalone'])      ? 1 : 0;
        $puedeC      = isset($_POST['puede_cambiar_voto']) ? 1 : 0;
        $fechaC      = !empty($_POST['fecha_cierre'])      ? $_POST['fecha_cierre'] : null;
        $opciones    = array_filter(
                           array_map('trim', $_POST['opciones'] ?? []),
                           fn($o) => $o !== ''
                       );

        if (mb_strlen($pregunta) < 5) {
            setFlashMessage('error', 'La pregunta debe tener al menos 5 caracteres.');
        } elseif (count($opciones) < 2) {
            setFlashMessage('error', 'Debes añadir al menos 2 opciones de respuesta.');
        } else {
            try {
                $slug  = slugEncuesta($pregunta);
                $newId = db()->insert(
                    "INSERT INTO encuestas
                       (autor_id, categoria_id, pregunta, slug, descripcion,
                        tipo, activa, mostrar_resultados, color,
                        puede_cambiar_voto, es_standalone, total_votos,
                        fecha_cierre, fecha_fin, fecha_creacion)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,?,NOW())",
                    [
                        (int)$usuario['id'], $catId, $pregunta, $slug,
                        $descripcion, $tipo, $activa, $mostrar, $color,
                        $puedeC, $esStan, $fechaC, $fechaC
                    ]
                );
                foreach (array_values($opciones) as $i => $op) {
                    db()->insert(
                        "INSERT INTO encuesta_opciones
                           (encuesta_id, opcion, votos, orden)
                         VALUES (?,?,0,?)",
                        [$newId, $op, $i + 1]
                    );
                }
                logActividad(
                    (int)$usuario['id'], 'crear_encuesta', 'encuestas',
                    $newId, "Creó encuesta: $pregunta"
                );
                setFlashMessage('success', 'Encuesta creada correctamente.');
                header('Location: ' . APP_URL . '/admin/encuestas.php?action=editar&id=' . $newId);
                exit;
            } catch (\Throwable $e) {
                setFlashMessage('error', 'Error de base de datos: ' . $e->getMessage());
            }
        }
        header('Location: ' . APP_URL . '/admin/encuestas.php?action=nueva');
        exit;
    }

    // ── EDITAR ────────────────────────────────────────────────
    if ($postAction === 'editar') {
        $editId      = (int)($_POST['encuesta_id'] ?? 0);
        $pregunta    = trim($_POST['pregunta']      ?? '');
        $descripcion = trim($_POST['descripcion']   ?? '');
        $tipo        = in_array($_POST['tipo'] ?? '', ['unica','multiple'])
                       ? $_POST['tipo'] : 'unica';
        $activa      = isset($_POST['activa']) ? 1 : 0;
        $mostrar     = in_array($_POST['mostrar_resultados'] ?? '',
                                ['siempre','despues_votar','nunca'])
                       ? $_POST['mostrar_resultados'] : 'despues_votar';
        $catId       = (int)($_POST['categoria_id'] ?? 0) ?: null;
        $color       = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '')
                       ? $_POST['color'] : '#e63946';
        $esStan      = isset($_POST['es_standalone'])      ? 1 : 0;
        $puedeC      = isset($_POST['puede_cambiar_voto']) ? 1 : 0;
        $fechaC      = !empty($_POST['fecha_cierre'])      ? $_POST['fecha_cierre'] : null;
        $opciones    = array_filter(
                           array_map('trim', $_POST['opciones'] ?? []),
                           fn($o) => $o !== ''
                       );

        if (mb_strlen($pregunta) < 5) {
            setFlashMessage('error', 'La pregunta debe tener al menos 5 caracteres.');
            header('Location: ' . APP_URL . '/admin/encuestas.php?action=editar&id=' . $editId);
            exit;
        }
        if (count($opciones) < 2) {
            setFlashMessage('error', 'Debes añadir al menos 2 opciones.');
            header('Location: ' . APP_URL . '/admin/encuestas.php?action=editar&id=' . $editId);
            exit;
        }

        try {
            db()->execute(
                "UPDATE encuestas SET
                   categoria_id = ?, pregunta = ?, descripcion = ?,
                   tipo = ?, activa = ?, mostrar_resultados = ?,
                   color = ?, puede_cambiar_voto = ?, es_standalone = ?,
                   fecha_cierre = ?, fecha_fin = ?
                 WHERE id = ?",
                [
                    $catId, $pregunta, $descripcion,
                    $tipo, $activa, $mostrar,
                    $color, $puedeC, $esStan,
                    $fechaC, $fechaC, $editId
                ]
            );
            // Sincronizar opciones
            db()->execute(
                "DELETE FROM encuesta_opciones WHERE encuesta_id = ?",
                [$editId]
            );
            foreach (array_values($opciones) as $i => $op) {
                db()->insert(
                    "INSERT INTO encuesta_opciones
                       (encuesta_id, opcion, votos, orden)
                     VALUES (?,?,0,?)",
                    [$editId, $op, $i + 1]
                );
            }
            logActividad(
                (int)$usuario['id'], 'editar_encuesta', 'encuestas',
                $editId, "Editó encuesta #$editId"
            );
            setFlashMessage('success', 'Encuesta actualizada correctamente.');
        } catch (\Throwable $e) {
            setFlashMessage('error', 'Error: ' . $e->getMessage());
        }
        header('Location: ' . APP_URL . '/admin/encuestas.php?action=editar&id=' . $editId);
        exit;
    }

    // ── ELIMINAR ──────────────────────────────────────────────
    if ($postAction === 'eliminar') {
        $delId = (int)($_POST['del_id'] ?? 0);
        if ($delId > 0) {
            db()->execute("DELETE FROM encuesta_votos    WHERE encuesta_id = ?", [$delId]);
            db()->execute("DELETE FROM encuesta_opciones WHERE encuesta_id = ?", [$delId]);
            db()->execute("DELETE FROM encuestas         WHERE id = ?",           [$delId]);
            logActividad(
                (int)$usuario['id'], 'eliminar_encuesta', 'encuestas',
                $delId, "Eliminó encuesta #$delId"
            );
            setFlashMessage('success', 'Encuesta eliminada correctamente.');
        }
        header('Location: ' . APP_URL . '/admin/encuestas.php');
        exit;
    }

    // ── TOGGLE ACTIVA ─────────────────────────────────────────
    if ($postAction === 'toggle_activa') {
        $tId = (int)($_POST['toggle_id'] ?? 0);
        if ($tId > 0) {
            db()->execute(
                "UPDATE encuestas SET activa = 1 - activa WHERE id = ?",
                [$tId]
            );
        }
        header('Location: ' . APP_URL . '/admin/encuestas.php');
        exit;
    }

    // ── RESETEAR VOTOS ────────────────────────────────────────
    if ($postAction === 'resetear_votos') {
        $rId = (int)($_POST['reset_id'] ?? 0);
        if ($rId > 0) {
            db()->execute("DELETE FROM encuesta_votos WHERE encuesta_id = ?",               [$rId]);
            db()->execute("UPDATE encuesta_opciones SET votos = 0 WHERE encuesta_id = ?",   [$rId]);
            db()->execute("UPDATE encuestas SET total_votos = 0 WHERE id = ?",              [$rId]);
            logActividad(
                (int)$usuario['id'], 'resetear_votos', 'encuestas',
                $rId, "Reseteó votos de encuesta #$rId"
            );
            setFlashMessage('success', 'Votos reseteados correctamente.');
        }
        header('Location: ' . APP_URL . '/admin/encuestas.php?action=editar&id=' . $rId);
        exit;
    }

    // ── EXPORTAR CSV ──────────────────────────────────────────
    if ($postAction === 'exportar_csv') {
        $expId = (int)($_POST['export_id'] ?? 0);
        if ($expId > 0) {
            $enc  = db()->fetchOne("SELECT * FROM encuestas WHERE id = ? LIMIT 1", [$expId]);
            $opts = db()->fetchAll(
                "SELECT * FROM encuesta_opciones WHERE encuesta_id = ? ORDER BY orden",
                [$expId]
            );
            if ($enc) {
                header('Content-Type: text/csv; charset=UTF-8');
                header('Content-Disposition: attachment; filename="encuesta_' . $expId . '_resultados.csv"');
                header('Pragma: no-cache');
                $out = fopen('php://output', 'w');
                fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
                fputcsv($out, ['Encuesta', 'ID', 'Total Votos', 'Fecha Creación']);
                fputcsv($out, [
                    $enc['pregunta'], $enc['id'],
                    $enc['total_votos'], $enc['fecha_creacion']
                ]);
                fputcsv($out, []);
                fputcsv($out, ['Opción', 'Votos', 'Porcentaje']);
                $tv = (int)$enc['total_votos'];
                foreach ($opts as $op) {
                    $pct = $tv > 0 ? round(($op['votos'] / $tv) * 100, 2) : 0;
                    fputcsv($out, [$op['opcion'], $op['votos'], $pct . '%']);
                }
                fclose($out);
                exit;
            }
        }
        header('Location: ' . APP_URL . '/admin/encuestas.php');
        exit;
    }

    // ── ACCIÓN BULK ───────────────────────────────────────────
    if ($postAction === 'bulk') {
        $bulkAction = trim($_POST['bulk_action'] ?? '');
        $bulkIds    = array_filter(array_map('intval', $_POST['ids'] ?? []));
        if (!empty($bulkIds) && in_array($bulkAction, ['activar','desactivar','eliminar'])) {
            $ph = implode(',', array_fill(0, count($bulkIds), '?'));
            if ($bulkAction === 'activar') {
                db()->execute("UPDATE encuestas SET activa=1 WHERE id IN ($ph)", $bulkIds);
            }
            if ($bulkAction === 'desactivar') {
                db()->execute("UPDATE encuestas SET activa=0 WHERE id IN ($ph)", $bulkIds);
            }
            if ($bulkAction === 'eliminar') {
                db()->execute("DELETE FROM encuesta_votos    WHERE encuesta_id IN ($ph)", $bulkIds);
                db()->execute("DELETE FROM encuesta_opciones WHERE encuesta_id IN ($ph)", $bulkIds);
                db()->execute("DELETE FROM encuestas         WHERE id IN ($ph)",           $bulkIds);
            }
            setFlashMessage('success', 'Acción aplicada a ' . count($bulkIds) . ' encuesta(s).');
        }
        header('Location: ' . APP_URL . '/admin/encuestas.php');
        exit;
    }

    // Fallback redirect
    header('Location: ' . APP_URL . '/admin/encuestas.php');
    exit;
}

// ── Cargar datos según acción ─────────────────────────────────
$encuesta     = null;
$encuestas    = [];
$totalPaginas = 1;
$statsAdmin   = [];
$categorias   = getCategorias();
$total        = 0;

if (in_array($action, ['nueva', 'editar'])) {
    if ($action === 'editar' && $encId > 0) {
        $encuesta = db()->fetchOne(
            "SELECT e.*, c.nombre AS cat_nombre
             FROM encuestas e
             LEFT JOIN categorias c ON c.id = e.categoria_id
             WHERE e.id = ? LIMIT 1",
            [$encId]
        );
        if (!$encuesta) {
            setFlashMessage('error', 'Encuesta no encontrada.');
            header('Location: ' . APP_URL . '/admin/encuestas.php');
            exit;
        }
        $encuesta['opciones'] = db()->fetchAll(
            "SELECT id, opcion, votos, orden
             FROM encuesta_opciones
             WHERE encuesta_id = ?
             ORDER BY orden ASC",
            [$encId]
        );
    }
} else {
    // Lista con filtros
    $where  = ['1=1'];
    $params = [];
    if ($filtroEstado === 'activa')   { $where[] = 'e.activa = 1'; }
    if ($filtroEstado === 'inactiva') { $where[] = 'e.activa = 0'; }
    if ($filtroEstado === 'cerrada')  {
        $where[] = 'e.fecha_cierre IS NOT NULL AND e.fecha_cierre < NOW()';
    }
    if ($filtroQ !== '') {
        $where[]  = 'e.pregunta LIKE ?';
        $params[] = "%$filtroQ%";
    }

    $wSQL         = implode(' AND ', $where);
    $total        = (int)db()->count(
        "SELECT COUNT(*) FROM encuestas e WHERE $wSQL", $params
    );
    $totalPaginas = max(1, (int)ceil($total / $perPage));
    $pagina       = min($pagina, $totalPaginas);
    $offset       = ($pagina - 1) * $perPage;

    $encuestas = db()->fetchAll(
        "SELECT e.*,
                u.nombre AS autor_nombre,
                c.nombre AS cat_nombre,
                c.color  AS cat_color,
                (SELECT COUNT(*)
                 FROM encuesta_opciones eo
                 WHERE eo.encuesta_id = e.id) AS num_opciones
         FROM encuestas e
         LEFT JOIN usuarios   u ON u.id = e.autor_id
         LEFT JOIN categorias c ON c.id = e.categoria_id
         WHERE $wSQL
         ORDER BY e.fecha_creacion DESC
         LIMIT ? OFFSET ?",
        array_merge($params, [$perPage, $offset])
    );

    // Stats
    $statsAdmin = [
        'total'       => (int)db()->count("SELECT COUNT(*) FROM encuestas"),
        'activas'     => (int)db()->count("SELECT COUNT(*) FROM encuestas WHERE activa = 1"),
        'cerradas'    => (int)db()->count(
            "SELECT COUNT(*) FROM encuestas
             WHERE activa = 0 OR (fecha_cierre IS NOT NULL AND fecha_cierre < NOW())"
        ),
        'total_votos' => (int)(db()->fetchColumn(
            "SELECT COALESCE(SUM(total_votos),0) FROM encuestas"
        ) ?? 0),
        'hoy'         => (int)db()->count(
            "SELECT COUNT(*) FROM encuesta_votos WHERE fecha >= CURDATE()"
        ),
    ];
}

// Flash message del sistema
$flash = getFlashMessage();

$pageTitle = match($action) {
    'nueva'  => 'Nueva Encuesta — Panel Admin',
    'editar' => 'Editar Encuesta — Panel Admin',
    default  => 'Gestión de Encuestas — Panel Admin',
};

// ── Incluir sidebar ANTES del DOCTYPE (patrón correcto del sistema) ──
require_once __DIR__ . '/sidebar.php';
?>
<!DOCTYPE html>
<html lang="es" data-theme="<?= e(Config::get('apariencia_modo_oscuro', 'auto')) ?>">
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
/* ════════════════════════════════════════════════════
   ADMIN BASE LAYOUT
════════════════════════════════════════════════════ */
body { padding-bottom: 0; background: var(--bg-body); }
.admin-wrapper { display: flex; min-height: 100vh; }

.admin-sidebar {
    width: 260px; background: var(--secondary-dark);
    position: fixed; top: 0; left: 0;
    height: 100vh; overflow-y: auto;
    z-index: var(--z-header);
    transition: transform var(--transition-base);
    display: flex; flex-direction: column;
}
[data-theme] .admin-sidebar { background: #0f1f33 !important; }
.admin-sidebar::-webkit-scrollbar { width: 4px; }
.admin-sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); }

.admin-sidebar__logo {
    padding: 24px 20px 16px;
    border-bottom: 1px solid rgba(255,255,255,.07);
    flex-shrink: 0;
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
    text-transform: uppercase; letter-spacing: .06em;
}
.admin-sidebar__user {
    padding: 14px 20px;
    border-bottom: 1px solid rgba(255,255,255,.07);
    display: flex; align-items: center; gap: 10px; flex-shrink: 0;
}
.admin-sidebar__user img {
    width: 36px; height: 36px; border-radius: 50%;
    object-fit: cover; border: 2px solid rgba(255,255,255,.15);
}
.admin-sidebar__user-name {
    font-size: .82rem; font-weight: 700;
    color: #fff; display: block;
}
.admin-sidebar__user-role {
    font-size: .68rem; color: rgba(255,255,255,.4); display: block;
}
.admin-nav { flex: 1; padding: 12px 0; overflow-y: auto; }
.admin-nav__section {
    padding: 14px 20px 5px;
    font-size: .62rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: .1em;
    color: rgba(255,255,255,.25);
}
.admin-nav__item {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 20px; font-size: .82rem; font-weight: 500;
    color: rgba(255,255,255,.6); text-decoration: none;
    transition: all .15s ease; position: relative;
    border: none; background: none; width: 100%; cursor: pointer;
}
.admin-nav__item:hover, .admin-nav__item.active {
    color: #fff; background: rgba(255,255,255,.08);
}
.admin-nav__item.active::before {
    content: '';
    position: absolute; left: 0; top: 0; bottom: 0;
    width: 3px; background: var(--primary); border-radius: 0 2px 2px 0;
}
.admin-nav__item i { font-size: 1rem; flex-shrink: 0; }
.admin-nav__badge {
    background: var(--primary); color: #fff;
    font-size: .6rem; font-weight: 800;
    min-width: 18px; height: 18px;
    padding: 0 5px;
    border-radius: 9px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    flex-shrink: 0;
    margin-left: auto;
}
.admin-sidebar__footer {
    padding: 8px 0; border-top: 1px solid rgba(255,255,255,.07);
    flex-shrink: 0;
}
.admin-main { margin-left: 260px; flex: 1; min-height: 100vh; display: flex; flex-direction: column; }
.admin-topbar {
    background: var(--bg-surface);
    border-bottom: 1px solid var(--border-color);
    padding: 0 28px; height: 62px;
    display: flex; align-items: center; gap: 16px;
    position: sticky; top: 0; z-index: var(--z-sticky);
    box-shadow: var(--shadow-sm);
}
.admin-topbar__toggle {
    display: none; color: var(--text-muted); font-size: 1.2rem;
    padding: 6px; border-radius: var(--border-radius-sm);
    background: none; border: none; cursor: pointer;
}
.admin-topbar__title {
    font-family: var(--font-serif); font-size: 1.1rem;
    font-weight: 700; color: var(--text-primary);
}
.admin-topbar__right { margin-left: auto; display: flex; align-items: center; gap: 10px; }
.admin-content { padding: 28px; flex: 1; }
.admin-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.55); z-index: calc(var(--z-header) - 1);
    opacity: 0; pointer-events: none; transition: opacity .3s;
}
.admin-overlay.show { display: block; opacity: 1; pointer-events: auto; }

/* ════════════════════════════════════════════════════
   BOTONES
════════════════════════════════════════════════════ */
.btn-p {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; background: var(--primary); color: #fff;
    border-radius: var(--border-radius-lg); font-size: .83rem;
    font-weight: 700; text-decoration: none; border: none;
    cursor: pointer; transition: all .2s;
}
.btn-p:hover { background: var(--primary-dark); color: #fff; }
.btn-s {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; background: var(--bg-surface-2);
    color: var(--text-secondary); border-radius: var(--border-radius-lg);
    font-size: .83rem; font-weight: 600; text-decoration: none;
    border: 1px solid var(--border-color); cursor: pointer; transition: all .2s;
}
.btn-s:hover { background: var(--bg-surface-3); color: var(--text-primary); }
.btn-danger {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; background: var(--danger); color: #fff;
    border-radius: var(--border-radius-lg); font-size: .83rem;
    font-weight: 700; border: none; cursor: pointer; transition: all .2s;
}
.btn-danger:hover { opacity: .85; }

/* ════════════════════════════════════════════════════
   STATS CARDS
════════════════════════════════════════════════════ */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px; margin-bottom: 20px;
}
.stat-card {
    background: var(--bg-surface); border: 1px solid var(--border-color);
    border-radius: var(--border-radius-lg); padding: 14px 16px;
    display: flex; flex-direction: column; gap: 6px;
    position: relative; overflow: hidden; transition: all .2s;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0;
    height: 3px; background: var(--card-color, var(--primary));
}
.stat-card__header { display: flex; align-items: center; justify-content: space-between; }
.stat-card__icon {
    width: 34px; height: 34px; border-radius: var(--border-radius);
    display: flex; align-items: center; justify-content: center; font-size: 1rem;
}
.stat-card__value { font-size: 1.4rem; font-weight: 900; color: var(--text-primary); line-height: 1; }
.stat-card__label { font-size: .72rem; color: var(--text-muted); font-weight: 500; }
@media (max-width: 1100px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 768px)  { .stats-grid { grid-template-columns: repeat(2, 1fr); } }

/* ════════════════════════════════════════════════════
   FILTROS BAR
════════════════════════════════════════════════════ */
.filters-bar {
    background: var(--bg-surface); border: 1px solid var(--border-color);
    border-radius: var(--border-radius-lg); padding: 14px 16px;
    display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap; margin-bottom: 20px; box-shadow: var(--shadow-sm);
}
.search-wrap { position: relative; flex: 1; min-width: 180px; }
.search-wrap i {
    position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
    color: var(--text-muted); font-size: .85rem; pointer-events: none;
}
.search-inp {
    width: 100%; padding: 8px 10px 8px 32px;
    border: 1px solid var(--border-color); border-radius: var(--border-radius);
    background: var(--bg-surface-2); color: var(--text-primary); font-size: .82rem;
}
.search-inp:focus { outline: none; border-color: var(--primary); }
.filter-sel {
    padding: 8px 10px; border: 1px solid var(--border-color);
    border-radius: var(--border-radius); background: var(--bg-surface-2);
    color: var(--text-primary); font-size: .82rem; cursor: pointer; min-width: 140px;
}

/* ════════════════════════════════════════════════════
   TABLA
════════════════════════════════════════════════════ */
.data-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
.data-table th {
    padding: 10px 14px; text-align: left;
    font-size: .72rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .05em;
    color: var(--text-muted); border-bottom: 2px solid var(--border-color);
    white-space: nowrap; background: var(--bg-surface-2);
}
.data-table td {
    padding: 12px 14px; border-bottom: 1px solid var(--border-color);
    color: var(--text-secondary); vertical-align: middle;
}
.data-table tr:hover td { background: var(--bg-surface-2); }
.data-table tr:last-child td { border-bottom: none; }
.table-wrap {
    background: var(--bg-surface); border-radius: var(--border-radius-xl);
    border: 1px solid var(--border-color); overflow: hidden; box-shadow: var(--shadow-sm);
}
.table-actions {
    padding: 14px 18px; border-top: 1px solid var(--border-color);
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
}

/* ════════════════════════════════════════════════════
   BADGES
════════════════════════════════════════════════════ */
.badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px; border-radius: var(--border-radius-full);
    font-size: .7rem; font-weight: 700;
}
.badge-success { background: rgba(34,197,94,.12);  color: var(--success); }
.badge-danger  { background: rgba(239,68,68,.12);  color: var(--danger); }
.badge-warning { background: rgba(245,158,11,.12); color: var(--warning); }
.badge-info    { background: rgba(59,130,246,.12); color: var(--info); }
.badge-muted   { background: var(--bg-surface-3);  color: var(--text-muted); }

/* ── Mini barra de progreso ───────────────────────────────── */
.mini-bar {
    height: 6px; background: var(--bg-surface-3);
    border-radius: 3px; overflow: hidden; min-width: 60px;
}
.mini-bar-fill {
    height: 100%; border-radius: 3px;
    background: var(--primary); transition: width .4s ease;
}

/* ════════════════════════════════════════════════════
   FORMULARIO
════════════════════════════════════════════════════ */
.form-layout {
    display: grid; grid-template-columns: 1fr 340px;
    gap: 20px; align-items: start;
}
@media (max-width: 1100px) { .form-layout { grid-template-columns: 1fr; } }
.form-card {
    background: var(--bg-surface); border: 1px solid var(--border-color);
    border-radius: var(--border-radius-xl); overflow: hidden;
    box-shadow: var(--shadow-sm); margin-bottom: 16px;
}
.form-card__header {
    padding: 14px 20px; border-bottom: 1px solid var(--border-color);
    display: flex; align-items: center; gap: 8px;
    font-size: .875rem; font-weight: 700; color: var(--text-primary);
}
.form-card__body { padding: 18px 20px; }
.form-group { margin-bottom: 16px; }
.form-group:last-child { margin-bottom: 0; }
.form-label { display: block; font-size: .8rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 5px; }
.form-control {
    width: 100%; padding: 9px 12px;
    border: 1px solid var(--border-color); border-radius: var(--border-radius);
    background: var(--bg-body); color: var(--text-primary);
    font-size: .85rem; transition: border-color .2s; box-sizing: border-box;
}
.form-control:focus { outline: none; border-color: var(--primary); }
textarea.form-control { resize: vertical; min-height: 80px; }
.form-helper { font-size: .72rem; color: var(--text-muted); margin-top: 4px; }

/* ── Selector de tipo ─────────────────────────────────────── */
.tipo-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 4px; }
.tipo-opt {
    padding: 12px; border: 2px solid var(--border-color);
    border-radius: var(--border-radius-lg); cursor: pointer;
    transition: all .2s; text-align: center;
}
.tipo-opt:hover { border-color: var(--primary); }
.tipo-opt.selected { border-color: var(--primary); background: rgba(230,57,70,.05); }
.tipo-opt input { display: none; }
.tipo-opt-icon { font-size: 1.5rem; display: block; margin-bottom: 6px; }
.tipo-opt-label { font-size: .8rem; font-weight: 700; color: var(--text-primary); }
.tipo-opt-sub { font-size: .7rem; color: var(--text-muted); margin-top: 2px; }

/* ── Opciones dinámicas ───────────────────────────────────── */
.options-list { display: flex; flex-direction: column; gap: 8px; margin-top: 8px; }
.option-item { display: flex; align-items: center; gap: 8px; }
.option-item input[type="text"] {
    flex: 1; padding: 8px 12px;
    border: 1px solid var(--border-color); border-radius: var(--border-radius);
    background: var(--bg-body); color: var(--text-primary); font-size: .84rem;
}
.option-item input[type="text"]:focus { outline: none; border-color: var(--primary); }
.option-drag { cursor: grab; color: var(--text-muted); font-size: 1rem; padding: 4px; }
.option-remove {
    width: 28px; height: 28px; display: flex; align-items: center;
    justify-content: center; border-radius: 50%; border: none;
    background: rgba(239,68,68,.1); color: var(--danger);
    cursor: pointer; transition: all .2s; flex-shrink: 0;
}
.option-remove:hover { background: var(--danger); color: #fff; }

/* ── Color swatches ───────────────────────────────────────── */
.color-swatches { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
.color-swatch {
    width: 28px; height: 28px; border-radius: 50%; cursor: pointer;
    border: 3px solid transparent; transition: all .2s; flex-shrink: 0;
}
.color-swatch:hover, .color-swatch.active {
    border-color: var(--text-primary); transform: scale(1.15);
}

/* ════════════════════════════════════════════════════
   MODAL
════════════════════════════════════════════════════ */
.modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.6);
    z-index: 1000; display: none;
    align-items: center; justify-content: center; padding: 20px;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: var(--bg-surface); border-radius: var(--border-radius-xl);
    padding: 28px; max-width: 520px; width: 100%;
    box-shadow: var(--shadow-xl); max-height: 90vh; overflow-y: auto;
}
.modal-title {
    font-size: 1.1rem; font-weight: 700; color: var(--text-primary);
    margin-bottom: 6px; display: flex; align-items: center; gap: 8px;
}
.modal-body {
    font-size: .875rem; color: var(--text-secondary); margin-bottom: 20px;
}
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; }

/* ── Resultados modal ─────────────────────────────────────── */
.result-bar-row {
    display: flex; align-items: center; gap: 10px; margin-bottom: 12px;
}
.result-bar-label { flex: 1; font-size: .83rem; color: var(--text-primary); font-weight: 500; }
.result-bar-wrap {
    flex: 2; height: 20px; background: var(--bg-surface-3);
    border-radius: 10px; overflow: hidden; position: relative;
}
.result-bar-fill { height: 100%; border-radius: 10px; transition: width .8s ease; }
.result-bar-pct { font-size: .78rem; font-weight: 800; min-width: 44px; text-align: right; color: var(--primary); }
.result-bar-cnt { font-size: .7rem; color: var(--text-muted); min-width: 50px; text-align: right; }

/* ════════════════════════════════════════════════════
   TOAST
════════════════════════════════════════════════════ */
.toast-container {
    position: fixed; bottom: 24px; right: 24px;
    z-index: 9999; display: flex; flex-direction: column; gap: 10px;
}
.toast {
    padding: 12px 18px; border-radius: var(--border-radius-lg);
    font-size: .84rem; font-weight: 600;
    display: flex; align-items: center; gap: 10px;
    box-shadow: var(--shadow-xl);
    animation: toastIn .3s ease;
    min-width: 260px; max-width: 360px;
}
.toast.success { background: var(--success); color: #fff; }
.toast.error   { background: var(--danger);  color: #fff; }
.toast.info    { background: var(--info);    color: #fff; }

/* ════════════════════════════════════════════════════
   PAGINACIÓN
════════════════════════════════════════════════════ */
.pagination { display: flex; align-items: center; gap: 6px; }
.page-btn {
    padding: 6px 12px; border-radius: var(--border-radius-sm);
    border: 1px solid var(--border-color); font-size: .78rem;
    font-weight: 600; text-decoration: none;
    color: var(--text-secondary); background: var(--bg-surface); transition: all .2s;
}
.page-btn:hover { border-color: var(--primary); color: var(--primary); }
.page-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.page-btn.disabled { opacity: .4; pointer-events: none; }

/* ── Bulk bar ─────────────────────────────────────────────── */
.bulk-bar {
    display: none; align-items: center; gap: 10px;
    padding: 10px 16px; background: var(--bg-surface-2);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-lg); margin-bottom: 12px;
}
.bulk-bar.visible { display: flex; }

/* ════════════════════════════════════════════════════
   RESPONSIVE
════════════════════════════════════════════════════ */
@media (max-width: 768px) {
    .admin-sidebar { transform: translateX(-100%); }
    .admin-sidebar.open { transform: translateX(0); box-shadow: var(--shadow-xl); }
    .admin-main { margin-left: 0; }
    .admin-topbar__toggle { display: flex; }
    .admin-content { padding: 16px; }
}

/* ════════════════════════════════════════════════════
   KEYFRAMES — deben ir DENTRO de <style>
════════════════════════════════════════════════════ */
@keyframes toastIn {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}
@keyframes spin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}
</style>
</head>
<body>
<div class="admin-wrapper">

<!-- El sidebar ya fue incluido con require_once ANTES del DOCTYPE -->
<div class="admin-overlay" id="adminOverlay" onclick="closeSidebar()"></div>

<main class="admin-main">

    <!-- Topbar -->
    <div class="admin-topbar">
        <button class="admin-topbar__toggle" onclick="toggleSidebar()">
            <i class="bi bi-list"></i>
        </button>
        <h1 class="admin-topbar__title">
            <i class="bi bi-bar-chart-fill" style="color:var(--primary);margin-right:6px"></i>
            <?php if ($action === 'nueva'): ?>
                Nueva Encuesta
            <?php elseif ($action === 'editar'): ?>
                Editar Encuesta
            <?php else: ?>
                Gestión de Encuestas
            <?php endif; ?>
        </h1>
        <div class="admin-topbar__right">
            <?php if ($action === 'lista'): ?>
            <a href="<?= APP_URL ?>/admin/encuestas.php?action=nueva" class="btn-p">
                <i class="bi bi-plus-lg"></i>
                <span class="d-none d-sm-inline">Nueva encuesta</span>
            </a>
            <?php else: ?>
            <a href="<?= APP_URL ?>/admin/encuestas.php" class="btn-s">
                <i class="bi bi-arrow-left"></i> Volver
            </a>
            <?php if ($encuesta && !empty($encuesta['slug'])): ?>
            <a href="<?= APP_URL ?>/encuestas.php?slug=<?= e($encuesta['slug']) ?>"
               target="_blank" class="btn-s">
                <i class="bi bi-eye"></i> Ver pública
            </a>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="admin-content">

        <!-- Flash message -->
        <?php if ($flash): ?>
        <div style="padding:12px 16px;border-radius:var(--border-radius-lg);
                    margin-bottom:20px;display:flex;align-items:center;gap:8px;
                    font-size:.875rem;font-weight:600;
                    background:<?= $flash['type'] === 'success' ? 'rgba(34,197,94,.1)' : 'rgba(239,68,68,.1)' ?>;
                    border:1px solid <?= $flash['type'] === 'success' ? 'rgba(34,197,94,.3)' : 'rgba(239,68,68,.3)' ?>;
                    color:<?= $flash['type'] === 'success' ? 'var(--success)' : 'var(--danger)' ?>">
            <i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill' ?>"></i>
            <?= e($flash['message']) ?>
        </div>
        <?php endif; ?>

        <?php if (in_array($action, ['nueva', 'editar'])): ?>
        <!-- ════════════════════════════════════════════════════
             FORMULARIO CREAR / EDITAR
             ════════════════════════════════════════════════════ -->
        <form method="POST"
              action="<?= APP_URL ?>/admin/encuestas.php"
              id="encuestaForm">
            <input type="hidden" name="csrf_token"  value="<?= e($csrfToken) ?>">
            <input type="hidden" name="post_action" value="<?= $action === 'editar' ? 'editar' : 'crear' ?>">
            <?php if ($action === 'editar'): ?>
            <input type="hidden" name="encuesta_id" value="<?= (int)$encId ?>">
            <?php endif; ?>

            <div class="form-layout">

                <!-- ── Columna principal ─────────────────────── -->
                <div>

                    <!-- Pregunta -->
                    <div class="form-card">
                        <div class="form-card__header">
                            <i class="bi bi-question-circle-fill" style="color:var(--primary)"></i>
                            Pregunta de la encuesta
                        </div>
                        <div class="form-card__body">
                            <div class="form-group">
                                <label class="form-label" for="pregunta">
                                    Pregunta <span style="color:var(--danger)">*</span>
                                </label>
                                <input type="text"
                                       id="pregunta"
                                       name="pregunta"
                                       class="form-control"
                                       placeholder="¿Cuál es tu opinión sobre...?"
                                       value="<?= e($encuesta['pregunta'] ?? '') ?>"
                                       maxlength="300"
                                       required>
                                <div class="form-helper">
                                    Máximo 300 caracteres.
                                    <span id="charCount"
                                          style="color:var(--primary);font-weight:600">
                                        <?= mb_strlen($encuesta['pregunta'] ?? '') ?>/300
                                    </span>
                                </div>
                            </div>
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label" for="descripcion">
                                    Descripción / contexto
                                    <span style="font-weight:400;color:var(--text-muted)">(opcional)</span>
                                </label>
                                <textarea id="descripcion"
                                          name="descripcion"
                                          class="form-control"
                                          rows="3"
                                          placeholder="Añade contexto para los usuarios..."><?= e($encuesta['descripcion'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Opciones de respuesta -->
                    <div class="form-card">
                        <div class="form-card__header" style="justify-content:space-between">
                            <div style="display:flex;align-items:center;gap:8px">
                                <i class="bi bi-list-ul" style="color:var(--info)"></i>
                                Opciones de respuesta
                                <span id="optsCount"
                                      style="font-size:.72rem;background:var(--bg-surface-3);
                                             padding:2px 8px;border-radius:10px;color:var(--text-muted)">
                                    0 opciones
                                </span>
                            </div>
                            <button type="button"
                                    onclick="addOption()"
                                    style="padding:5px 12px;font-size:.75rem;font-weight:700;
                                           background:rgba(34,197,94,.1);color:var(--success);
                                           border:1px solid rgba(34,197,94,.3);
                                           border-radius:var(--border-radius-full);cursor:pointer">
                                <i class="bi bi-plus-circle-fill"></i> Añadir opción
                            </button>
                        </div>
                        <div class="form-card__body">
                            <div class="options-list" id="optionsList">
                                <?php
                                $optsExistentes = $encuesta['opciones'] ?? [];
                                if (empty($optsExistentes)) {
                                    $optsExistentes = [
                                        ['opcion' => '', 'votos' => 0],
                                        ['opcion' => '', 'votos' => 0],
                                    ];
                                }
                                foreach ($optsExistentes as $i => $op):
                                ?>
                                <div class="option-item" data-index="<?= $i ?>">
                                    <span class="option-drag" title="Reordenar">
                                        <i class="bi bi-grip-vertical"></i>
                                    </span>
                                    <span style="font-size:.75rem;font-weight:700;
                                                 color:var(--text-muted);min-width:20px;
                                                 text-align:center">
                                        <?= $i + 1 ?>
                                    </span>
                                    <input type="text"
                                           name="opciones[]"
                                           value="<?= e($op['opcion']) ?>"
                                           placeholder="Opción de respuesta..."
                                           maxlength="200">
                                    <?php if ($action === 'editar' && (int)($op['votos'] ?? 0) > 0): ?>
                                    <span style="font-size:.7rem;color:var(--text-muted);
                                                 min-width:60px;text-align:right;white-space:nowrap">
                                        <?= formatNumber((int)$op['votos']) ?> votos
                                    </span>
                                    <?php endif; ?>
                                    <button type="button"
                                            class="option-remove"
                                            onclick="removeOption(this)"
                                            title="Eliminar">
                                        <i class="bi bi-x"></i>
                                    </button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div style="margin-top:12px">
                                <button type="button"
                                        onclick="addOption()"
                                        style="width:100%;padding:10px;
                                               border:2px dashed var(--border-color);
                                               border-radius:var(--border-radius-lg);
                                               background:transparent;color:var(--text-muted);
                                               font-size:.82rem;cursor:pointer;transition:all .2s"
                                        onmouseover="this.style.borderColor='var(--primary)';this.style.color='var(--primary)'"
                                        onmouseout="this.style.borderColor='var(--border-color)';this.style.color='var(--text-muted)'">
                                    <i class="bi bi-plus-circle"></i> Añadir otra opción
                                </button>
                            </div>
                            <p class="form-helper" style="margin-top:8px">
                                <i class="bi bi-info-circle"></i>
                                Mínimo 2 opciones requeridas. Máximo recomendado: 8.
                                <?php if ($action === 'editar'): ?>
                                <strong style="color:var(--warning)">
                                    Atención: editar opciones reseteará sus conteos a 0.
                                </strong>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <!-- Tipo de encuesta -->
                    <div class="form-card">
                        <div class="form-card__header">
                            <i class="bi bi-toggles" style="color:var(--warning)"></i>
                            Tipo de respuesta
                        </div>
                        <div class="form-card__body">
                            <div class="tipo-grid">
                                <label class="tipo-opt <?= ($encuesta['tipo'] ?? 'unica') === 'unica' ? 'selected' : '' ?>"
                                       onclick="selectTipo(this)">
                                    <input type="radio" name="tipo" value="unica"
                                           <?= ($encuesta['tipo'] ?? 'unica') === 'unica' ? 'checked' : '' ?>>
                                    <span class="tipo-opt-icon">🔘</span>
                                    <span class="tipo-opt-label">Única respuesta</span>
                                    <span class="tipo-opt-sub">El usuario elige una sola opción</span>
                                </label>
                                <label class="tipo-opt <?= ($encuesta['tipo'] ?? '') === 'multiple' ? 'selected' : '' ?>"
                                       onclick="selectTipo(this)">
                                    <input type="radio" name="tipo" value="multiple"
                                           <?= ($encuesta['tipo'] ?? '') === 'multiple' ? 'checked' : '' ?>>
                                    <span class="tipo-opt-icon">☑️</span>
                                    <span class="tipo-opt-label">Múltiple respuesta</span>
                                    <span class="tipo-opt-sub">El usuario puede elegir varias</span>
                                </label>
                            </div>
                        </div>
                    </div>

                </div><!-- /columna principal -->

                <!-- ── Columna lateral ───────────────────────── -->
                <div>

                    <!-- Configuración -->
                    <div class="form-card">
                        <div class="form-card__header">
                            <i class="bi bi-gear-fill" style="color:var(--success)"></i>
                            Configuración
                        </div>
                        <div class="form-card__body">
                            <div class="form-group">
                                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                                    <input type="checkbox" name="activa" value="1"
                                           <?= ($encuesta['activa'] ?? 1) ? 'checked' : '' ?>
                                           style="accent-color:var(--primary);width:16px;height:16px">
                                    <span style="font-size:.84rem;font-weight:600;color:var(--text-primary)">
                                        Encuesta activa
                                    </span>
                                </label>
                            </div>
                            <div class="form-group">
                                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                                    <input type="checkbox" name="es_standalone" value="1"
                                           <?= ($encuesta['es_standalone'] ?? 1) ? 'checked' : '' ?>
                                           style="accent-color:var(--primary);width:16px;height:16px">
                                    <span style="font-size:.84rem;font-weight:600;color:var(--text-primary)">
                                        Página propia en /encuestas
                                    </span>
                                </label>
                                <span class="form-helper">
                                    Aparece en la sección pública de encuestas.
                                </span>
                            </div>
                            <div class="form-group">
                                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                                    <input type="checkbox" name="puede_cambiar_voto" value="1"
                                           <?= ($encuesta['puede_cambiar_voto'] ?? 1) ? 'checked' : '' ?>
                                           style="accent-color:var(--primary);width:16px;height:16px">
                                    <span style="font-size:.84rem;font-weight:600;color:var(--text-primary)">
                                        Permitir cambiar voto
                                    </span>
                                </label>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="mostrar_resultados">
                                    Mostrar resultados
                                </label>
                                <select id="mostrar_resultados"
                                        name="mostrar_resultados"
                                        class="form-control">
                                    <option value="despues_votar"
                                            <?= ($encuesta['mostrar_resultados'] ?? 'despues_votar') === 'despues_votar' ? 'selected' : '' ?>>
                                        📊 Después de votar
                                    </option>
                                    <option value="siempre"
                                            <?= ($encuesta['mostrar_resultados'] ?? '') === 'siempre' ? 'selected' : '' ?>>
                                        👁️ Siempre (incluso antes de votar)
                                    </option>
                                    <option value="nunca"
                                            <?= ($encuesta['mostrar_resultados'] ?? '') === 'nunca' ? 'selected' : '' ?>>
                                        🔒 Solo cuando cierre
                                    </option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label" for="fecha_cierre">
                                    Fecha de cierre
                                    <span style="font-weight:400;color:var(--text-muted)">(opcional)</span>
                                </label>
                                <input type="datetime-local"
                                       id="fecha_cierre"
                                       name="fecha_cierre"
                                       class="form-control"
                                       value="<?= !empty($encuesta['fecha_cierre'])
                                               ? date('Y-m-d\TH:i', strtotime($encuesta['fecha_cierre']))
                                               : '' ?>">
                                <span class="form-helper">Vacío = sin cierre automático.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Categoría y color -->
                    <div class="form-card">
                        <div class="form-card__header">
                            <i class="bi bi-palette-fill" style="color:var(--accent)"></i>
                            Categoría y apariencia
                        </div>
                        <div class="form-card__body">
                            <div class="form-group">
                                <label class="form-label" for="categoria_id">Categoría</label>
                                <select id="categoria_id" name="categoria_id" class="form-control">
                                    <option value="0">Sin categoría</option>
                                    <?php foreach ($categorias as $cat): ?>
                                    <option value="<?= (int)$cat['id'] ?>"
                                            <?= (($encuesta['categoria_id'] ?? 0) == $cat['id']) ? 'selected' : '' ?>>
                                        <?= e($cat['nombre']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom:0">
                                <label class="form-label">Color de la encuesta</label>
                                <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                                    <input type="color"
                                           id="colorPicker"
                                           name="color"
                                           value="<?= e($encuesta['color'] ?? '#e63946') ?>"
                                           style="width:40px;height:36px;
                                                  border:1px solid var(--border-color);
                                                  border-radius:var(--border-radius);
                                                  cursor:pointer;padding:2px">
                                    <input type="text"
                                           id="colorHex"
                                           value="<?= e($encuesta['color'] ?? '#e63946') ?>"
                                           maxlength="7"
                                           style="width:90px;padding:8px 10px;
                                                  border:1px solid var(--border-color);
                                                  border-radius:var(--border-radius);
                                                  background:var(--bg-body);color:var(--text-primary);
                                                  font-size:.83rem;font-family:monospace">
                                </div>
                                <div class="color-swatches">
                                    <?php
                                    $swatches = [
                                        '#e63946','#3b82f6','#8b5cf6','#10b981',
                                        '#f59e0b','#ef4444','#06b6d4','#ec4899',
                                        '#6366f1','#14b8a6',
                                    ];
                                    $colorActual = $encuesta['color'] ?? '#e63946';
                                    foreach ($swatches as $sw):
                                    ?>
                                    <span class="color-swatch <?= $colorActual === $sw ? 'active' : '' ?>"
                                          style="background:<?= $sw ?>"
                                          onclick="setColor('<?= $sw ?>')"
                                          title="<?= $sw ?>"></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Acciones -->
                    <div class="form-card">
                        <div class="form-card__body">
                            <button type="submit" class="btn-p" style="width:100%;justify-content:center">
                                <i class="bi bi-<?= $action === 'editar' ? 'save' : 'plus-circle' ?>-fill"></i>
                                <?= $action === 'editar' ? 'Guardar cambios' : 'Crear encuesta' ?>
                            </button>

                            <?php if ($action === 'editar' && $encuesta): ?>
                            <div style="margin-top:10px;display:flex;gap:8px">
                                <button type="button"
                                        onclick="verResultados(<?= (int)$encId ?>)"
                                        class="btn-s"
                                        style="flex:1;justify-content:center">
                                    <i class="bi bi-bar-chart-fill" style="color:var(--primary)"></i>
                                    Ver resultados
                                </button>
                            </div>
                            <!-- ⚠️ IMPORTANTE: estos botones usan type="button" y llaman
                                 a forms independientes que están FUERA del form principal,
                                 evitando el bug de forms anidados -->
                            <button type="button"
                                    style="margin-top:8px;width:100%;padding:9px;
                                           display:flex;align-items:center;justify-content:center;
                                           gap:6px;background:var(--bg-surface-2);
                                           border:1px solid var(--warning);border-radius:var(--border-radius-lg);
                                           color:var(--warning);font-size:.83rem;font-weight:600;
                                           cursor:pointer;transition:all .2s"
                                    onclick="confirmarResetearVotos()">
                                <i class="bi bi-arrow-clockwise"></i> Resetear votos
                            </button>
                            <button type="button"
                                    style="margin-top:8px;width:100%;padding:9px;
                                           display:flex;align-items:center;justify-content:center;
                                           gap:6px;background:var(--bg-surface-2);
                                           border:1px solid var(--border-color);border-radius:var(--border-radius-lg);
                                           color:var(--text-secondary);font-size:.83rem;font-weight:600;
                                           cursor:pointer;transition:all .2s"
                                    onclick="document.getElementById('formExportCSV').submit()">
                                <i class="bi bi-download" style="color:var(--success)"></i>
                                Exportar resultados CSV
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Vista previa -->
                    <div class="form-card">
                        <div class="form-card__header">
                            <i class="bi bi-eye" style="color:var(--info)"></i>
                            Vista previa
                        </div>
                        <div class="form-card__body" style="padding:16px">
                            <div id="previewCard"
                                 style="border-radius:var(--border-radius-lg);
                                        overflow:hidden;border:1px solid var(--border-color)">
                                <div id="previewHeader"
                                     style="height:5px;background:<?= e($encuesta['color'] ?? '#e63946') ?>">
                                </div>
                                <div style="padding:14px">
                                    <div style="font-size:.75rem;font-weight:700;
                                                color:<?= e($encuesta['color'] ?? '#e63946') ?>;
                                                margin-bottom:6px" id="previewAccent">
                                        <i class="bi bi-bar-chart-fill"></i> Encuesta
                                    </div>
                                    <p id="previewQuestion"
                                       style="font-size:.85rem;font-weight:700;
                                              color:var(--text-primary);
                                              line-height:1.3;margin-bottom:10px">
                                        <?= e($encuesta['pregunta'] ?? 'Tu pregunta aquí...') ?>
                                    </p>
                                    <div id="previewOptions"
                                         style="font-size:.78rem;color:var(--text-muted)">
                                        Opciones aparecerán aquí...
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div><!-- /columna lateral -->
            </div><!-- /form-layout -->
        </form>

        <?php else: ?>
        <!-- ════════════════════════════════════════════════════
             LISTA DE ENCUESTAS
             ════════════════════════════════════════════════════ -->

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card" style="--card-color:var(--primary)">
                <div class="stat-card__header">
                    <div class="stat-card__icon"
                         style="background:rgba(230,57,70,.12);color:var(--primary)">
                        <i class="bi bi-bar-chart-fill"></i>
                    </div>
                    <span style="font-size:.65rem;font-weight:700;padding:2px 7px;
                                 border-radius:var(--border-radius-full);
                                 background:var(--bg-surface-3);color:var(--text-muted)">Total</span>
                </div>
                <div class="stat-card__value"><?= formatNumber($statsAdmin['total']) ?></div>
                <div class="stat-card__label">Encuestas creadas</div>
            </div>
            <div class="stat-card" style="--card-color:var(--success)">
                <div class="stat-card__header">
                    <div class="stat-card__icon"
                         style="background:rgba(34,197,94,.12);color:var(--success)">
                        <i class="bi bi-play-circle-fill"></i>
                    </div>
                    <span class="badge badge-success">● Activas</span>
                </div>
                <div class="stat-card__value"><?= formatNumber($statsAdmin['activas']) ?></div>
                <div class="stat-card__label">Encuestas activas</div>
            </div>
            <div class="stat-card" style="--card-color:var(--text-muted)">
                <div class="stat-card__header">
                    <div class="stat-card__icon"
                         style="background:var(--bg-surface-3);color:var(--text-muted)">
                        <i class="bi bi-lock-fill"></i>
                    </div>
                </div>
                <div class="stat-card__value"><?= formatNumber($statsAdmin['cerradas']) ?></div>
                <div class="stat-card__label">Cerradas / Inactivas</div>
            </div>
            <div class="stat-card" style="--card-color:var(--info)">
                <div class="stat-card__header">
                    <div class="stat-card__icon"
                         style="background:rgba(59,130,246,.12);color:var(--info)">
                        <i class="bi bi-people-fill"></i>
                    </div>
                </div>
                <div class="stat-card__value"><?= formatNumber($statsAdmin['total_votos']) ?></div>
                <div class="stat-card__label">Votos acumulados</div>
            </div>
            <div class="stat-card" style="--card-color:var(--warning)">
                <div class="stat-card__header">
                    <div class="stat-card__icon"
                         style="background:rgba(245,158,11,.12);color:var(--warning)">
                        <i class="bi bi-calendar-check-fill"></i>
                    </div>
                </div>
                <div class="stat-card__value"><?= formatNumber($statsAdmin['hoy']) ?></div>
                <div class="stat-card__label">Votos hoy</div>
            </div>
        </div>

        <!-- Filtros -->
        <form method="get" action="<?= APP_URL ?>/admin/encuestas.php" class="filters-bar">
            <div class="search-wrap">
                <i class="bi bi-search"></i>
                <input type="text" name="q" class="search-inp"
                       value="<?= e($filtroQ) ?>"
                       placeholder="Buscar encuesta...">
            </div>
            <select name="estado" class="filter-sel" onchange="this.form.submit()">
                <option value=""         <?= $filtroEstado === ''        ? 'selected' : '' ?>>Todos los estados</option>
                <option value="activa"   <?= $filtroEstado === 'activa'   ? 'selected' : '' ?>>✅ Activas</option>
                <option value="inactiva" <?= $filtroEstado === 'inactiva' ? 'selected' : '' ?>>⏸ Inactivas</option>
                <option value="cerrada"  <?= $filtroEstado === 'cerrada'  ? 'selected' : '' ?>>🔒 Cerradas</option>
            </select>
            <button type="submit" class="btn-p">
                <i class="bi bi-funnel"></i> Filtrar
            </button>
            <?php if ($filtroQ || $filtroEstado): ?>
            <a href="<?= APP_URL ?>/admin/encuestas.php" class="btn-s">
                <i class="bi bi-x-circle"></i> Limpiar
            </a>
            <?php endif; ?>
        </form>

        <!-- Bulk bar -->
        <div class="bulk-bar" id="bulkBar">
            <i class="bi bi-check2-square" style="color:var(--primary)"></i>
            <span id="bulkCount"
                  style="font-size:.85rem;font-weight:600;color:var(--text-primary)">
                0 seleccionadas
            </span>
            <form method="POST" id="bulkForm" style="display:flex;gap:8px;margin-left:auto">
                <input type="hidden" name="csrf_token"    value="<?= e($csrfToken) ?>">
                <input type="hidden" name="post_action"   value="bulk">
                <input type="hidden" name="bulk_action"   id="bulkActionInput" value="">
                <button type="button" onclick="doBulk('activar')"
                        style="padding:5px 12px;background:rgba(34,197,94,.1);
                               color:var(--success);border:1px solid rgba(34,197,94,.3);
                               border-radius:var(--border-radius-sm);
                               font-size:.75rem;font-weight:700;cursor:pointer">
                    <i class="bi bi-play-fill"></i> Activar
                </button>
                <button type="button" onclick="doBulk('desactivar')"
                        style="padding:5px 12px;background:rgba(245,158,11,.1);
                               color:var(--warning);border:1px solid rgba(245,158,11,.3);
                               border-radius:var(--border-radius-sm);
                               font-size:.75rem;font-weight:700;cursor:pointer">
                    <i class="bi bi-pause-fill"></i> Desactivar
                </button>
                <button type="button" onclick="doBulk('eliminar')"
                        style="padding:5px 12px;background:rgba(239,68,68,.1);
                               color:var(--danger);border:1px solid rgba(239,68,68,.3);
                               border-radius:var(--border-radius-sm);
                               font-size:.75rem;font-weight:700;cursor:pointer">
                    <i class="bi bi-trash3-fill"></i> Eliminar
                </button>
            </form>
        </div>

        <!-- Tabla o vacío -->
        <?php if (empty($encuestas)): ?>
        <div style="text-align:center;padding:60px 20px;
                    background:var(--bg-surface);
                    border-radius:var(--border-radius-xl);
                    border:2px dashed var(--border-color)">
            <i class="bi bi-bar-chart"
               style="font-size:2.5rem;color:var(--text-muted);
                      display:block;margin-bottom:12px;opacity:.4"></i>
            <h3 style="font-family:var(--font-serif);font-size:1.1rem;
                        color:var(--text-muted);margin-bottom:8px">
                No hay encuestas todavía
            </h3>
            <a href="<?= APP_URL ?>/admin/encuestas.php?action=nueva"
               class="btn-p" style="margin-top:12px">
                <i class="bi bi-plus-circle-fill"></i> Crear primera encuesta
            </a>
        </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="data-table" id="encuestasTable">
                <thead>
                    <tr>
                        <th style="width:36px">
                            <input type="checkbox" id="selAll"
                                   onclick="toggleAll(this)"
                                   style="accent-color:var(--primary)">
                        </th>
                        <th style="width:4px"></th>
                        <th>Pregunta</th>
                        <th>Estado</th>
                        <th>Tipo</th>
                        <th>Opciones</th>
                        <th>Votos</th>
                        <th>Cierre</th>
                        <th>Creada</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($encuestas as $enc):
                        $cerradaEnc = !$enc['activa']
                            || (!empty($enc['fecha_cierre'])
                                && $enc['fecha_cierre'] < date('Y-m-d H:i:s'));
                    ?>
                    <tr id="row-<?= (int)$enc['id'] ?>">
                        <td>
                            <input type="checkbox" class="row-chk"
                                   value="<?= (int)$enc['id'] ?>"
                                   onclick="updateBulk()"
                                   style="accent-color:var(--primary)">
                        </td>
                        <td>
                            <span style="display:block;width:4px;height:40px;
                                         background:<?= e($enc['color'] ?? '#e63946') ?>;
                                         border-radius:2px"></span>
                        </td>
                        <td style="max-width:300px">
                            <div style="font-weight:600;color:var(--text-primary);
                                        font-size:.84rem;line-height:1.3;margin-bottom:4px">
                                <?= e(truncateChars($enc['pregunta'], 80)) ?>
                            </div>
                            <?php if (!empty($enc['cat_nombre'])): ?>
                            <span style="font-size:.68rem;font-weight:600;
                                         color:<?= e($enc['cat_color'] ?? 'var(--text-muted)') ?>;
                                         background:<?= e($enc['cat_color'] ?? '#888') ?>22;
                                         padding:2px 7px;border-radius:10px">
                                <?= e($enc['cat_nombre']) ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($enc['activa'] && !$cerradaEnc): ?>
                            <span class="badge badge-success">● Activa</span>
                            <?php elseif (!empty($enc['fecha_cierre']) && $enc['fecha_cierre'] < date('Y-m-d H:i:s')): ?>
                            <span class="badge badge-muted">🔒 Cerrada</span>
                            <?php else: ?>
                            <span class="badge badge-muted">⏸ Inactiva</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $enc['tipo'] === 'multiple' ? 'badge-info' : 'badge-muted' ?>">
                                <?= $enc['tipo'] === 'multiple' ? '☑ Múltiple' : '○ Única' ?>
                            </span>
                        </td>
                        <td style="text-align:center">
                            <strong><?= (int)$enc['num_opciones'] ?></strong>
                        </td>
                        <td>
                            <div style="font-weight:700;color:var(--text-primary);font-size:.9rem">
                                <?= formatNumber((int)$enc['total_votos']) ?>
                            </div>
                            <?php if ((int)$enc['total_votos'] > 0 && $statsAdmin['total_votos'] > 0): ?>
                            <div class="mini-bar" style="margin-top:3px;width:80px">
                                <div class="mini-bar-fill"
                                     style="width:<?= min(100, round(($enc['total_votos'] / $statsAdmin['total_votos']) * 100)) ?>%;
                                            background:<?= e($enc['color'] ?? 'var(--primary)') ?>">
                                </div>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.75rem;color:var(--text-muted);white-space:nowrap">
                            <?= !empty($enc['fecha_cierre'])
                                ? formatDate($enc['fecha_cierre'], 'short')
                                : '—' ?>
                        </td>
                        <td style="font-size:.75rem;color:var(--text-muted);white-space:nowrap">
                            <?= timeAgo($enc['fecha_creacion']) ?>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:4px;flex-wrap:nowrap">
                                <!-- Editar -->
                                <a href="<?= APP_URL ?>/admin/encuestas.php?action=editar&id=<?= (int)$enc['id'] ?>"
                                   title="Editar"
                                   style="padding:5px 8px;border-radius:var(--border-radius-sm);
                                          background:rgba(59,130,246,.1);color:var(--info);
                                          text-decoration:none;font-size:.78rem">
                                    <i class="bi bi-pencil-fill"></i>
                                </a>
                                <!-- Ver resultados -->
                                <button onclick="verResultados(<?= (int)$enc['id'] ?>)"
                                        title="Ver resultados"
                                        style="padding:5px 8px;border-radius:var(--border-radius-sm);
                                               background:rgba(139,92,246,.1);color:#7c3aed;
                                               border:none;cursor:pointer;font-size:.78rem">
                                    <i class="bi bi-bar-chart-fill"></i>
                                </button>
                                <!-- Toggle activo -->
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="csrf_token"  value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="post_action" value="toggle_activa">
                                    <input type="hidden" name="toggle_id"   value="<?= (int)$enc['id'] ?>">
                                    <button type="submit"
                                            title="<?= $enc['activa'] ? 'Desactivar' : 'Activar' ?>"
                                            style="padding:5px 8px;border-radius:var(--border-radius-sm);
                                                   background:<?= $enc['activa'] ? 'rgba(245,158,11,.1)' : 'rgba(34,197,94,.1)' ?>;
                                                   color:<?= $enc['activa'] ? 'var(--warning)' : 'var(--success)' ?>;
                                                   border:none;cursor:pointer;font-size:.78rem">
                                        <i class="bi bi-<?= $enc['activa'] ? 'pause' : 'play' ?>-fill"></i>
                                    </button>
                                </form>
                                <!-- Ver pública -->
                                <?php if (!empty($enc['es_standalone']) && !empty($enc['slug'])): ?>
                                <a href="<?= APP_URL ?>/encuestas.php?slug=<?= e($enc['slug']) ?>"
                                   target="_blank"
                                   title="Ver pública"
                                   style="padding:5px 8px;border-radius:var(--border-radius-sm);
                                          background:var(--bg-surface-2);color:var(--text-muted);
                                          text-decoration:none;font-size:.78rem">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                                <?php endif; ?>
                                <!-- Eliminar -->
                                <button onclick="confirmarEliminar(<?= (int)$enc['id'] ?>, '<?= e(addslashes(truncateChars($enc['pregunta'], 50))) ?>')"
                                        title="Eliminar"
                                        style="padding:5px 8px;border-radius:var(--border-radius-sm);
                                               background:rgba(239,68,68,.1);color:var(--danger);
                                               border:none;cursor:pointer;font-size:.78rem">
                                    <i class="bi bi-trash3-fill"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Paginación -->
            <?php if ($totalPaginas > 1): ?>
            <div class="table-actions">
                <span style="font-size:.78rem;color:var(--text-muted)">
                    Mostrando <?= count($encuestas) ?> de <?= $total ?> encuestas
                </span>
                <nav class="pagination">
                    <a href="?pag=<?= $pagina-1 ?>&q=<?= urlencode($filtroQ) ?>&estado=<?= urlencode($filtroEstado) ?>"
                       class="page-btn <?= $pagina <= 1 ? 'disabled' : '' ?>">‹</a>
                    <?php for ($p = max(1, $pagina - 3); $p <= min($totalPaginas, $pagina + 3); $p++): ?>
                    <a href="?pag=<?= $p ?>&q=<?= urlencode($filtroQ) ?>&estado=<?= urlencode($filtroEstado) ?>"
                       class="page-btn <?= $p === $pagina ? 'active' : '' ?>">
                        <?= $p ?>
                    </a>
                    <?php endfor; ?>
                    <a href="?pag=<?= $pagina+1 ?>&q=<?= urlencode($filtroQ) ?>&estado=<?= urlencode($filtroEstado) ?>"
                       class="page-btn <?= $pagina >= $totalPaginas ? 'disabled' : '' ?>">›</a>
                </nav>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php endif; // fin if action lista ?>

    </div><!-- /admin-content -->
</main>

<!-- ════════════════════════════════════════════════════════════
     MODAL: ELIMINAR
════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalEliminar">
    <div class="modal-box">
        <div class="modal-title">
            <i class="bi bi-exclamation-triangle-fill" style="color:var(--danger)"></i>
            Eliminar encuesta
        </div>
        <div class="modal-body">
            ¿Seguro que deseas eliminar la encuesta
            <strong id="modalElimPregunta"></strong>?<br>
            Se eliminarán todos sus votos y opciones.
            Esta acción <strong>no se puede deshacer</strong>.
        </div>
        <div class="modal-actions">
            <button onclick="cerrarModal('modalEliminar')" class="btn-s">
                Cancelar
            </button>
            <form method="POST" id="formEliminar" style="display:inline">
                <input type="hidden" name="csrf_token"  value="<?= e($csrfToken) ?>">
                <input type="hidden" name="post_action" value="eliminar">
                <input type="hidden" name="del_id"      id="delId" value="">
                <button type="submit" class="btn-danger">
                    <i class="bi bi-trash3-fill"></i> Eliminar definitivamente
                </button>
            </form>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL: RESULTADOS
════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalResultados">
    <div class="modal-box" style="max-width:600px">
        <div class="modal-title" id="resultModalTitle">
            <i class="bi bi-bar-chart-fill" style="color:var(--primary)"></i>
            Resultados
        </div>
        <div id="resultModalBody" style="margin-bottom:0">
            <div style="text-align:center;padding:30px">
                <div style="width:32px;height:32px;border:3px solid var(--primary);
                             border-top-color:transparent;border-radius:50%;
                             animation:spin .7s linear infinite;margin:0 auto"></div>
                <p style="margin-top:12px;color:var(--text-muted)">Cargando...</p>
            </div>
        </div>
        <div class="modal-actions"
             style="border-top:1px solid var(--border-color);
                    padding-top:16px;margin-top:16px">
            <button onclick="cerrarModal('modalResultados')" class="btn-s">
                Cerrar
            </button>
        </div>
    </div>
</div>

<!-- Toast container -->
<div class="toast-container" id="toastContainer"></div>

<?php if ($action === 'editar' && $encuesta): ?>
<!-- ════════════════════════════════════════════════════════════
     FORMS INDEPENDIENTES — fuera del form principal para evitar
     el bug de HTML de forms anidados (HTML no permite <form> dentro de <form>)
════════════════════════════════════════════════════════════ -->
<form method="POST"
      id="formResetVotos"
      action="<?= APP_URL ?>/admin/encuestas.php"
      style="display:none">
    <input type="hidden" name="csrf_token"  value="<?= e($csrfToken) ?>">
    <input type="hidden" name="post_action" value="resetear_votos">
    <input type="hidden" name="reset_id"   value="<?= (int)$encId ?>">
</form>

<form method="POST"
      id="formExportCSV"
      action="<?= APP_URL ?>/admin/encuestas.php"
      style="display:none">
    <input type="hidden" name="csrf_token"  value="<?= e($csrfToken) ?>">
    <input type="hidden" name="post_action" value="exportar_csv">
    <input type="hidden" name="export_id"  value="<?= (int)$encId ?>">
</form>
<?php endif; ?>

</div><!-- /admin-wrapper -->

<!-- ════════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════════ -->
<script>
'use strict';

const APP_URL  = '<?= APP_URL ?>';
const CSRF_VAL = '<?= e($csrfToken) ?>';

/* ── Sidebar ───────────────────────────────────────────────── */
function toggleSidebar() {
    const sb = document.getElementById('adminSidebar');
    const ov = document.getElementById('adminOverlay');
    if (!sb || !ov) return;
    const isOpen = sb.classList.toggle('open');
    if (isOpen) { ov.classList.add('show'); }
    else         { ov.classList.remove('show'); }
    document.body.style.overflow = isOpen ? 'hidden' : '';
}
function closeSidebar() {
    const sb = document.getElementById('adminSidebar');
    const ov = document.getElementById('adminOverlay');
    if (sb) sb.classList.remove('open');
    if (ov) ov.classList.remove('show');
    document.body.style.overflow = '';
}

/* ── Toast ─────────────────────────────────────────────────── */
function showToast(msg, type = 'success', dur = 3500) {
    const icons = {
        success: 'check-circle-fill',
        error:   'exclamation-circle-fill',
        info:    'info-circle-fill',
    };
    const tc = document.getElementById('toastContainer');
    if (!tc) return;
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.innerHTML = `<i class="bi bi-${icons[type] || icons.info}"></i><span>${msg}</span>`;
    tc.appendChild(t);
    setTimeout(() => {
        t.style.opacity    = '0';
        t.style.transition = 'opacity .3s';
        setTimeout(() => t.remove(), 300);
    }, dur);
}

/* ── Modales ───────────────────────────────────────────────── */
function abrirModal(id) {
    const m = document.getElementById(id);
    if (m) { m.classList.add('open'); document.body.style.overflow = 'hidden'; }
}
function cerrarModal(id) {
    const m = document.getElementById(id);
    if (m) { m.classList.remove('open'); document.body.style.overflow = ''; }
}
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) cerrarModal(m.id); });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open')
                .forEach(m => cerrarModal(m.id));
    }
});

/* ── Confirmar eliminar ────────────────────────────────────── */
function confirmarEliminar(id, pregunta) {
    document.getElementById('delId').value = id;
    document.getElementById('modalElimPregunta').textContent = pregunta;
    abrirModal('modalEliminar');
}

/* ── Ver resultados ────────────────────────────────────────── */
async function verResultados(encuestaId) {
    const body  = document.getElementById('resultModalBody');
    const title = document.getElementById('resultModalTitle');

    body.innerHTML = `<div style="text-align:center;padding:30px">
        <div style="width:32px;height:32px;border:3px solid var(--primary);
                    border-top-color:transparent;border-radius:50%;
                    animation:spin .7s linear infinite;margin:0 auto"></div>
        <p style="margin-top:12px;color:var(--text-muted)">Cargando resultados...</p>
    </div>`;
    abrirModal('modalResultados');

    try {
        const res  = await fetch(APP_URL + '/ajax/handler.php', {
            method:  'POST',
            headers: {
                'Content-Type':     'application/json',
                'X-CSRF-Token':     CSRF_VAL,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                action:      'get_poll_results',
                encuesta_id: encuestaId,
            }),
        });
        const data = await res.json();

        if (data.success) {
            title.innerHTML = `<i class="bi bi-bar-chart-fill" style="color:var(--primary)"></i>
                               Resultados — ${data.pregunta}`;
            const tv = data.total_votos || 0;
            let html = `<div style="margin-bottom:16px">
                <span style="font-size:.84rem;color:var(--text-muted)">
                    <i class="bi bi-people-fill"></i>
                    <strong>${tv.toLocaleString()}</strong> votos en total
                </span>
            </div>`;
            (data.opciones || []).forEach(op => {
                const pct = tv > 0
                    ? Math.round((op.votos / tv) * 100 * 10) / 10
                    : 0;
                html += `<div class="result-bar-row">
                    <div class="result-bar-label">${op.opcion}</div>
                    <div class="result-bar-wrap">
                        <div class="result-bar-fill"
                             style="width:${pct}%;background:var(--primary)"></div>
                    </div>
                    <div class="result-bar-pct">${pct}%</div>
                    <div class="result-bar-cnt">${op.votos.toLocaleString()}</div>
                </div>`;
            });
            body.innerHTML = html;
        } else {
            body.innerHTML = `<p style="color:var(--danger)">
                ${data.message || 'Error al cargar resultados'}
            </p>`;
        }
    } catch (err) {
        body.innerHTML = `<p style="color:var(--danger)">Error de conexión</p>`;
        console.error(err);
    }
}

/* ── Bulk selection ────────────────────────────────────────── */
function toggleAll(cb) {
    document.querySelectorAll('.row-chk').forEach(c => c.checked = cb.checked);
    updateBulk();
}
function updateBulk() {
    const sel = document.querySelectorAll('.row-chk:checked').length;
    const bar = document.getElementById('bulkBar');
    const cnt = document.getElementById('bulkCount');
    if (bar) bar.classList.toggle('visible', sel > 0);
    if (cnt) cnt.textContent = sel + ' seleccionada' + (sel !== 1 ? 's' : '');
}
function doBulk(action) {
    const ids = Array.from(document.querySelectorAll('.row-chk:checked'))
                     .map(c => c.value);
    if (ids.length === 0) { showToast('Selecciona al menos una encuesta', 'error'); return; }
    if (action === 'eliminar' && !confirm(`¿Eliminar ${ids.length} encuesta(s)?`)) return;

    document.getElementById('bulkActionInput').value = action;
    const form = document.getElementById('bulkForm');
    // Limpiar ids anteriores
    form.querySelectorAll('input[name="ids[]"]').forEach(i => i.remove());
    ids.forEach(id => {
        const inp  = document.createElement('input');
        inp.type   = 'hidden';
        inp.name   = 'ids[]';
        inp.value  = id;
        form.appendChild(inp);
    });
    form.submit();
}

/* ── Resetear votos (form independiente) ───────────────────── */
function confirmarResetearVotos() {
    if (confirm('¿Resetear todos los votos de esta encuesta?\nEsta acción no se puede deshacer.')) {
        const f = document.getElementById('formResetVotos');
        if (f) f.submit();
    }
}

<?php if (in_array($action, ['nueva', 'editar'])): ?>
/* ── Opciones dinámicas ────────────────────────────────────── */
let optCount = document.querySelectorAll('.option-item').length;

function addOption() {
    const list = document.getElementById('optionsList');
    const idx  = ++optCount;
    const num  = list.children.length + 1;
    const div  = document.createElement('div');
    div.className    = 'option-item';
    div.dataset.index = idx;
    div.innerHTML = `
        <span class="option-drag" title="Reordenar">
            <i class="bi bi-grip-vertical"></i>
        </span>
        <span style="font-size:.75rem;font-weight:700;color:var(--text-muted);
                     min-width:20px;text-align:center">${num}</span>
        <input type="text" name="opciones[]"
               placeholder="Opción de respuesta..."
               maxlength="200"
               oninput="updatePreview()">
        <button type="button" class="option-remove"
                onclick="removeOption(this)" title="Eliminar">
            <i class="bi bi-x"></i>
        </button>`;
    list.appendChild(div);
    div.querySelector('input').focus();
    updateOptNums();
    updatePreview();
}

function removeOption(btn) {
    const items = document.querySelectorAll('.option-item');
    if (items.length <= 2) {
        showToast('Mínimo 2 opciones requeridas', 'error');
        return;
    }
    btn.closest('.option-item').remove();
    updateOptNums();
    updatePreview();
}

function updateOptNums() {
    document.querySelectorAll('.option-item').forEach((item, i) => {
        const numSpan = item.querySelectorAll('span')[1];
        if (numSpan) numSpan.textContent = i + 1;
    });
    const cnt = document.getElementById('optsCount');
    const n   = document.querySelectorAll('.option-item').length;
    if (cnt) cnt.textContent = n + ' opci' + (n !== 1 ? 'ones' : 'ón');
}

/* ── Tipo de encuesta ─────────────────────────────────────── */
function selectTipo(label) {
    document.querySelectorAll('.tipo-opt').forEach(l => l.classList.remove('selected'));
    label.classList.add('selected');
    const inp = label.querySelector('input');
    if (inp) inp.checked = true;
}

/* ── Color picker ─────────────────────────────────────────── */
function setColor(hex) {
    const picker = document.getElementById('colorPicker');
    const hexInp = document.getElementById('colorHex');
    if (picker) picker.value = hex;
    if (hexInp) hexInp.value = hex;
    document.querySelectorAll('.color-swatch').forEach(s => {
        s.classList.toggle('active', s.title === hex);
    });
    updatePreview();
}

const colorPicker = document.getElementById('colorPicker');
const colorHex    = document.getElementById('colorHex');
if (colorPicker) {
    colorPicker.addEventListener('input', function () {
        if (colorHex) colorHex.value = this.value;
        updatePreview();
    });
}
if (colorHex) {
    colorHex.addEventListener('input', function () {
        if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
            if (colorPicker) colorPicker.value = this.value;
            updatePreview();
        }
    });
}

/* ── Vista previa ─────────────────────────────────────────── */
function updatePreview() {
    const color   = document.getElementById('colorPicker')?.value || '#e63946';
    const q       = document.getElementById('pregunta')?.value    || 'Tu pregunta aquí...';
    const opts    = Array.from(document.querySelectorAll('input[name="opciones[]"]'))
                         .map(i => i.value).filter(Boolean);

    const header  = document.getElementById('previewHeader');
    const accent  = document.getElementById('previewAccent');
    const pq      = document.getElementById('previewQuestion');
    const po      = document.getElementById('previewOptions');

    if (header) header.style.background = color;
    if (accent) accent.style.color      = color;
    if (pq)     pq.textContent          = q.slice(0, 80);

    if (po) {
        if (opts.length > 0) {
            po.innerHTML = opts.slice(0, 3).map(o => `
                <div style="padding:6px 8px;border:1px solid var(--border-color);
                            border-radius:6px;margin-bottom:5px;
                            font-size:.75rem;color:var(--text-primary)">
                    ${o.slice(0, 50)}
                </div>`).join('')
                + (opts.length > 3
                    ? `<div style="font-size:.7rem;color:var(--text-muted)">
                           +${opts.length - 3} más...
                       </div>` : '');
        } else {
            po.innerHTML = '<span style="color:var(--text-muted);font-size:.75rem">Opciones aparecerán aquí...</span>';
        }
    }
}

/* ── Contador de caracteres ───────────────────────────────── */
const pregInput = document.getElementById('pregunta');
const charCount = document.getElementById('charCount');
if (pregInput && charCount) {
    pregInput.addEventListener('input', () => {
        charCount.textContent = pregInput.value.length + '/300';
        updatePreview();
    });
}

/* ── Inicializar al cargar ────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    updateOptNums();
    updatePreview();
    document.querySelectorAll('input[name="opciones[]"]').forEach(i => {
        i.addEventListener('input', updatePreview);
    });
});

<?php endif; ?>
</script>

</body>
</html>