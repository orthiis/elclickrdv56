<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Admin: Precio de Combustibles
 * ============================================================
 * Archivo  : admin/combustibles.php
 * Versión  : 1.1.0 (corregido)
 * Módulo   : Gestión de Precios de Combustibles
 * Acceso   : super_admin, admin, autor
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// ── Protección: admin, super_admin o autor ────────────────────
if (!isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}
$usuario = currentUser();
if (!in_array($usuario['rol'] ?? '', ['super_admin','admin','editor','periodista'], true)) {
    header('Location: ' . APP_URL . '/admin/dashboard.php?error=sin_permiso');
    exit;
}

// ── CSRF — Mismo patrón que admin/encuestas.php ───────────────
$csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrfToken;

function combVerifyCsrf(string $token): bool {
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// ── Tab activa ────────────────────────────────────────────────
$tabActiva   = cleanInput($_GET['tab'] ?? 'semanas');
$tabsValidas = ['semanas', 'precios', 'tipos'];
if (!in_array($tabActiva, $tabsValidas, true)) {
    $tabActiva = 'semanas';
}

// ── Flash message ─────────────────────────────────────────────
$flash = getFlashMessage();

// ============================================================
// PROCESAMIENTO POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!combVerifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Token de seguridad inválido. Recarga la página.');
        header('Location: ' . APP_URL . '/admin/combustibles.php?tab=' . $tabActiva);
        exit;
    }

    $postAccion = cleanInput($_POST['post_accion'] ?? '');

    // ─────────────────────────────────────────────────────────
    // ACCIÓN: Crear nueva semana
    // ─────────────────────────────────────────────────────────
    if ($postAccion === 'crear_semana') {

        $fechaVigencia = cleanInput($_POST['fecha_vigencia']  ?? '');
        $semanaInicio  = cleanInput($_POST['semana_inicio']   ?? '');
        $semanaFin     = cleanInput($_POST['semana_fin']      ?? '');
        $titulo        = cleanInput($_POST['titulo']          ?? '', 200);
        $notaSemanal   = cleanInput($_POST['nota_semanal']    ?? '', 1000);
        $fuente        = cleanInput($_POST['fuente']          ?? '', 200);
        $publicado     = isset($_POST['publicado'])     ? 1 : 0;
        $marcarActual  = isset($_POST['marcar_actual']) ? 1 : 0;

        // Auto-generar título
        if (empty($titulo) && !empty($semanaInicio) && !empty($semanaFin)) {
            $meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',
                      5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',
                      9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
            $tsDes = strtotime($semanaInicio);
            $tsHas = strtotime($semanaFin);
            $titulo = sprintf('Precios semana del %d al %d de %s %s',
                (int)date('d', $tsDes), (int)date('d', $tsHas),
                $meses[(int)date('n', $tsHas)], date('Y', $tsHas));
        }

        // Validaciones
        $errores = [];
        if (empty($fechaVigencia)) $errores[] = 'La fecha de vigencia es requerida.';
        if (empty($semanaInicio))  $errores[] = 'La fecha de inicio es requerida.';
        if (empty($semanaFin))     $errores[] = 'La fecha de fin es requerida.';

        if (empty($errores)) {
            $existe = (int) db()->fetchColumn(
                "SELECT COUNT(*) FROM combustibles_semanas WHERE semana_inicio = ?",
                [$semanaInicio]
            );
            if ($existe > 0) {
                $errores[] = "Ya existe una semana para el período iniciado el {$semanaInicio}.";
            }
        }

        if (!empty($errores)) {
            setFlashMessage('error', implode(' | ', $errores));
            header('Location: ' . APP_URL . '/admin/combustibles.php?tab=semanas');
            exit;
        }

        $nuevaId = 0;
        $errDB   = '';

        try {
            // Si se marca como actual, desmarcar las demás
            if ($marcarActual) {
                db()->execute("UPDATE combustibles_semanas SET es_actual = 0");
            }

            // Insertar semana
            db()->execute(
                "INSERT INTO combustibles_semanas
                    (titulo, fecha_vigencia, semana_inicio, semana_fin,
                     publicado, es_actual, nota_semanal, fuente,
                     autor_id, fecha_publicacion)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $titulo,
                    $fechaVigencia,
                    $semanaInicio,
                    $semanaFin,
                    $publicado,
                    $marcarActual ? 1 : 0,
                    $notaSemanal ?: null,
                    $fuente ?: 'Ministerio de Industria, Comercio y Mipymes',
                    (int)$usuario['id'],
                    $publicado ? date('Y-m-d H:i:s') : null,
                ]
            );
            $nuevaId = (int) db()->lastInsertId();

        } catch (\Throwable $e) {
            $errDB = $e->getMessage();
        }

        if ($errDB || !$nuevaId) {
            setFlashMessage('error', 'Error al crear la semana: ' . $errDB);
            header('Location: ' . APP_URL . '/admin/combustibles.php?tab=semanas');
            exit;
        }

        // Crear registros de precio iniciales copiando semana anterior
        $tipos = getCombustiblesTipos(true);
        $semanaAnt = db()->fetchOne(
            "SELECT semana_inicio FROM combustibles_semanas
             WHERE semana_inicio < ? AND id != ?
             ORDER BY semana_inicio DESC LIMIT 1",
            [$semanaInicio, $nuevaId]
        );
        $creados = 0;
        foreach ($tipos as $tipo) {
            $precioAnt = null;
            if ($semanaAnt) {
                $pa = db()->fetchColumn(
                    "SELECT precio FROM combustibles_precios
                     WHERE combustible_id = ? AND semana_inicio = ? LIMIT 1",
                    [(int)$tipo['id'], $semanaAnt['semana_inicio']]
                );
                if ($pa !== false) $precioAnt = (float)$pa;
            }
            try {
                db()->execute(
                    "INSERT IGNORE INTO combustibles_precios
                        (combustible_id, precio, precio_anterior, variacion,
                         variacion_pct, semana_inicio, semana_fin, fecha_vigencia,
                         publicado, fuente, autor_id)
                     VALUES (?, ?, ?, 0.00, 0.00, ?, ?, ?, ?, ?, ?)",
                    [
                        (int)$tipo['id'],
                        $precioAnt ?? 0.00,
                        $precioAnt,
                        $semanaInicio,
                        $semanaFin,
                        $fechaVigencia,
                        $publicado,
                        $fuente ?: 'Ministerio de Industria, Comercio y Mipymes',
                        (int)$usuario['id'],
                    ]
                );
                $creados++;
            } catch (\Throwable $ignored) {}
        }

        // Log actividad
        if (function_exists('logActividad')) {
            logActividad((int)$usuario['id'], 'crear', 'combustibles_semanas',
                $nuevaId, "Creó semana: {$titulo}");
        }

        setFlashMessage('success',
            "✅ Semana <strong>" . htmlspecialchars($titulo) . "</strong> creada. "
            . "{$creados} registros de precio generados."
        );
        header('Location: ' . APP_URL . '/admin/combustibles.php?tab=precios&semana_id=' . $nuevaId);
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // ACCIÓN: Guardar precios de una semana
    // ─────────────────────────────────────────────────────────
    if ($postAccion === 'guardar_precios') {
        $semanaId      = cleanInt($_POST['semana_id']     ?? 0);
        $publicarAhora = isset($_POST['publicar_ahora'])  ? 1 : 0;
        $marcarActual  = isset($_POST['marcar_actual'])   ? 1 : 0;
        $precios       = $_POST['precios']                ?? [];
        $notas         = $_POST['notas']                  ?? [];

        if (!$semanaId) {
            setFlashMessage('error', 'Semana no válida.');
            header('Location: ' . APP_URL . '/admin/combustibles.php?tab=precios');
            exit;
        }

        $semana = db()->fetchOne(
            "SELECT * FROM combustibles_semanas WHERE id = ? LIMIT 1",
            [$semanaId]
        );
        if (!$semana) {
            setFlashMessage('error', 'Semana no encontrada.');
            header('Location: ' . APP_URL . '/admin/combustibles.php?tab=precios');
            exit;
        }

        // Si se marca como actual, limpiar bandera global
        if ($marcarActual) {
            db()->execute("UPDATE combustibles_semanas SET es_actual = 0");
        }

        // Actualizar estado de la semana
        db()->execute(
            "UPDATE combustibles_semanas
             SET publicado = ?, es_actual = ?,
                 fecha_publicacion = ?, fecha_update = NOW()
             WHERE id = ?",
            [
                $publicarAhora,
                $marcarActual ? 1 : 0,
                $publicarAhora ? date('Y-m-d H:i:s') : $semana['fecha_publicacion'],
                $semanaId,
            ]
        );

        $actualizados = 0;
        foreach ($precios as $combustibleId => $nuevoPrecio) {
            $combustibleId = (int)$combustibleId;
            $nuevoPrecio   = (float)str_replace(',', '.', (string)($nuevoPrecio ?? 0));
            $nota          = cleanInput($notas[$combustibleId] ?? '', 300);

            if ($nuevoPrecio <= 0) continue;

            // Obtener precio de la semana anterior para este combustible
            $precioAntBD = db()->fetchColumn(
                "SELECT cp.precio
                 FROM combustibles_precios cp
                 INNER JOIN combustibles_semanas cs ON cs.semana_inicio = cp.semana_inicio
                 WHERE cp.combustible_id = ? AND cs.semana_inicio < ?
                 ORDER BY cs.semana_inicio DESC LIMIT 1",
                [$combustibleId, $semana['semana_inicio']]
            );
            $precioAnterior = ($precioAntBD !== false) ? (float)$precioAntBD : null;
            $variacion    = ($precioAnterior !== null)
                ? round($nuevoPrecio - $precioAnterior, 2) : 0.00;
            $variacionPct = ($precioAnterior !== null && $precioAnterior > 0)
                ? round(($variacion / $precioAnterior) * 100, 2) : 0.00;

            $existe = (int)db()->fetchColumn(
                "SELECT COUNT(*) FROM combustibles_precios
                 WHERE combustible_id = ? AND semana_inicio = ?",
                [$combustibleId, $semana['semana_inicio']]
            );

            try {
                if ($existe > 0) {
                    db()->execute(
                        "UPDATE combustibles_precios
                         SET precio = ?, precio_anterior = ?, variacion = ?,
                             variacion_pct = ?, publicado = ?, nota = ?,
                             autor_id = ?, fecha_update = NOW()
                         WHERE combustible_id = ? AND semana_inicio = ?",
                        [$nuevoPrecio, $precioAnterior, $variacion, $variacionPct,
                         $publicarAhora, $nota ?: null, (int)$usuario['id'],
                         $combustibleId, $semana['semana_inicio']]
                    );
                } else {
                    db()->execute(
                        "INSERT INTO combustibles_precios
                            (combustible_id, precio, precio_anterior, variacion,
                             variacion_pct, semana_inicio, semana_fin, fecha_vigencia,
                             publicado, nota, autor_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$combustibleId, $nuevoPrecio, $precioAnterior,
                         $variacion, $variacionPct,
                         $semana['semana_inicio'], $semana['semana_fin'],
                         $semana['fecha_vigencia'], $publicarAhora,
                         $nota ?: null, (int)$usuario['id']]
                    );
                }
                $actualizados++;
            } catch (\Throwable $ignored) {}
        }

        if (function_exists('logActividad')) {
            logActividad((int)$usuario['id'], 'editar', 'combustibles_semanas',
                $semanaId, "Actualizó {$actualizados} precios en semana #{$semanaId}");
        }

        setFlashMessage('success',
            "✅ <strong>{$actualizados}</strong> precios actualizados."
            . ($publicarAhora ? ' Semana <strong>publicada</strong>.' : '')
            . ($marcarActual  ? ' Marcada como <strong>semana actual</strong>.' : '')
        );
        header('Location: ' . APP_URL . '/admin/combustibles.php?tab=precios&semana_id=' . $semanaId);
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // ACCIÓN AJAX: Toggle publicado / es_actual de semana
    // ─────────────────────────────────────────────────────────
    if ($postAccion === 'toggle_semana') {
        header('Content-Type: application/json; charset=utf-8');
        $semanaId = cleanInt($_POST['semana_id'] ?? 0);
        $campo    = cleanInput($_POST['campo']   ?? '');

        if (!$semanaId || !in_array($campo, ['publicado','es_actual'], true)) {
            echo json_encode(['success'=>false,'message'=>'Parámetros inválidos']);
            exit;
        }

        try {
            if ($campo === 'es_actual') {
                db()->execute("UPDATE combustibles_semanas SET es_actual = 0");
                db()->execute(
                    "UPDATE combustibles_semanas SET es_actual = 1 WHERE id = ?",
                    [$semanaId]
                );
                $nuevoValor = 1;
            } else {
                $actual = (int)db()->fetchColumn(
                    "SELECT publicado FROM combustibles_semanas WHERE id = ? LIMIT 1",
                    [$semanaId]
                );
                $nuevoValor = $actual ? 0 : 1;
                db()->execute(
                    "UPDATE combustibles_semanas SET publicado = ?, fecha_update = NOW() WHERE id = ?",
                    [$nuevoValor, $semanaId]
                );
                // Sincronizar publicado en precios
                $si = db()->fetchColumn(
                    "SELECT semana_inicio FROM combustibles_semanas WHERE id = ? LIMIT 1",
                    [$semanaId]
                );
                if ($si) {
                    db()->execute(
                        "UPDATE combustibles_precios SET publicado = ? WHERE semana_inicio = ?",
                        [$nuevoValor, $si]
                    );
                }
            }
            echo json_encode(['success'=>true,'nuevoValor'=>$nuevoValor,
                              'message'=>'Estado actualizado correctamente.']);
        } catch (\Throwable $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // ACCIÓN: Eliminar semana
    // ─────────────────────────────────────────────────────────
    if ($postAccion === 'eliminar_semana') {
        $semanaId = cleanInt($_POST['semana_id'] ?? 0);
        if (!$semanaId) {
            setFlashMessage('error', 'ID de semana inválido.');
        } else {
            $sem = db()->fetchOne(
                "SELECT titulo, semana_inicio, es_actual
                 FROM combustibles_semanas WHERE id = ? LIMIT 1",
                [$semanaId]
            );
            if (!$sem) {
                setFlashMessage('error', 'Semana no encontrada.');
            } elseif ($sem['es_actual']) {
                setFlashMessage('error', 'No puedes eliminar la semana actual. Marca otra como actual primero.');
            } else {
                try {
                    db()->execute(
                        "DELETE FROM combustibles_precios WHERE semana_inicio = ?",
                        [$sem['semana_inicio']]
                    );
                    db()->execute(
                        "DELETE FROM combustibles_semanas WHERE id = ?",
                        [$semanaId]
                    );
                    if (function_exists('logActividad')) {
                        logActividad((int)$usuario['id'], 'eliminar',
                            'combustibles_semanas', $semanaId,
                            'Eliminó semana: ' . ($sem['titulo'] ?? ''));
                    }
                    setFlashMessage('success', '✅ Semana eliminada correctamente.');
                } catch (\Throwable $e) {
                    setFlashMessage('error', 'No se pudo eliminar: ' . $e->getMessage());
                }
            }
        }
        header('Location: ' . APP_URL . '/admin/combustibles.php?tab=semanas');
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // ACCIÓN: Crear / Editar tipo de combustible
    // ─────────────────────────────────────────────────────────
    if (in_array($postAccion, ['crear_tipo','editar_tipo'], true)) {
        $tipoId      = cleanInt($_POST['tipo_id']       ?? 0);
        $nombre      = cleanInput($_POST['nombre']      ?? '', 100);
        $slug        = cleanInput($_POST['slug']        ?? '', 120);
        $icono       = cleanInput($_POST['icono']       ?? '', 50);
        $color       = cleanInput($_POST['color']       ?? '#e63946', 7);
        $unidad      = cleanInput($_POST['unidad']      ?? 'galón', 20);
        $descripcion = cleanInput($_POST['descripcion'] ?? '', 500);
        $orden       = cleanInt($_POST['orden']         ?? 0);
        $activo      = isset($_POST['activo'])          ? 1 : 0;

        if (empty($slug) && !empty($nombre)) {
            $slug = mb_strtolower($nombre, 'UTF-8');
            $slug = preg_replace('/[áàäâã]/u','a',$slug);
            $slug = preg_replace('/[éèëê]/u', 'e',$slug);
            $slug = preg_replace('/[íìïî]/u', 'i',$slug);
            $slug = preg_replace('/[óòöôõ]/u','o',$slug);
            $slug = preg_replace('/[úùüû]/u', 'u',$slug);
            $slug = preg_replace('/[ñ]/u',    'n',$slug);
            $slug = preg_replace('/[^a-z0-9\s-]/u','',$slug);
            $slug = trim(preg_replace('/[\s-]+/','- ',$slug),'-');
            $slug = str_replace(' ','-',$slug);
        }

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#e63946';
        if (!in_array($unidad, ['galón','m³'], true)) $unidad = 'galón';

        $errores = [];
        if (empty($nombre)) $errores[] = 'El nombre es requerido.';
        if (empty($slug))   $errores[] = 'El slug es requerido.';

        if (empty($errores)) {
            $existeSlug = (int)db()->fetchColumn(
                "SELECT COUNT(*) FROM combustibles_tipos WHERE slug = ? AND id != ?",
                [$slug, $tipoId]
            );
            if ($existeSlug > 0) $errores[] = "El slug '{$slug}' ya está en uso.";
        }

        if (!empty($errores)) {
            setFlashMessage('error', implode(' | ', $errores));
        } else {
            try {
                if ($postAccion === 'crear_tipo') {
                    db()->execute(
                        "INSERT INTO combustibles_tipos
                            (nombre, slug, icono, color, unidad, descripcion, orden, activo)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        [$nombre, $slug, $icono ?: 'bi-fuel-pump',
                         $color, $unidad, $descripcion ?: null, $orden, $activo]
                    );
                    setFlashMessage('success', "✅ Tipo <strong>{$nombre}</strong> creado.");
                } elseif ($tipoId > 0) {
                    db()->execute(
                        "UPDATE combustibles_tipos
                         SET nombre = ?, slug = ?, icono = ?, color = ?,
                             unidad = ?, descripcion = ?, orden = ?, activo = ?
                         WHERE id = ?",
                        [$nombre, $slug, $icono ?: 'bi-fuel-pump',
                         $color, $unidad, $descripcion ?: null, $orden, $activo, $tipoId]
                    );
                    setFlashMessage('success', "✅ Tipo <strong>{$nombre}</strong> actualizado.");
                }
            } catch (\Throwable $e) {
                setFlashMessage('error', 'Error al guardar tipo: ' . $e->getMessage());
            }
        }
        header('Location: ' . APP_URL . '/admin/combustibles.php?tab=tipos');
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // ACCIÓN AJAX: Toggle activo de tipo
    // ─────────────────────────────────────────────────────────
    if ($postAccion === 'toggle_tipo') {
        header('Content-Type: application/json; charset=utf-8');
        $tipoId = cleanInt($_POST['tipo_id'] ?? 0);
        if (!$tipoId) { echo json_encode(['success'=>false,'message'=>'ID inválido']); exit; }
        try {
            $actual = (int)db()->fetchColumn(
                "SELECT activo FROM combustibles_tipos WHERE id = ? LIMIT 1",
                [$tipoId]
            );
            $nuevo = $actual ? 0 : 1;
            db()->execute("UPDATE combustibles_tipos SET activo = ? WHERE id = ?", [$nuevo, $tipoId]);
            echo json_encode(['success'=>true,'nuevoValor'=>$nuevo]);
        } catch (\Throwable $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // ACCIÓN AJAX: Eliminar tipo (solo si no tiene precios)
    // ─────────────────────────────────────────────────────────
    if ($postAccion === 'eliminar_tipo') {
        header('Content-Type: application/json; charset=utf-8');
        $tipoId = cleanInt($_POST['tipo_id'] ?? 0);
        if (!$tipoId) { echo json_encode(['success'=>false,'message'=>'ID inválido']); exit; }
        $tienePrecios = (int)db()->fetchColumn(
            "SELECT COUNT(*) FROM combustibles_precios WHERE combustible_id = ?",
            [$tipoId]
        );
        if ($tienePrecios > 0) {
            echo json_encode(['success'=>false,
                'message'=>'No se puede eliminar: tiene historial de precios. Solo desactívalo.']);
            exit;
        }
        try {
            db()->execute("DELETE FROM combustibles_tipos WHERE id = ?", [$tipoId]);
            echo json_encode(['success'=>true,'message'=>'Tipo eliminado.']);
        } catch (\Throwable $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }
}

// ============================================================
// DATOS PARA LAS VISTAS
// ============================================================

// ── Tab Semanas ───────────────────────────────────────────────
$filtroEstadoSem = cleanInput($_GET['estado'] ?? '');
$filtroBusqSem   = cleanInput($_GET['q']      ?? '');
$pagSemanas      = max(1, cleanInt($_GET['pag'] ?? 1));
$perPageSem      = 12;
$totalSemanasCnt = countCombustiblesSemanasAdmin($filtroEstadoSem, $filtroBusqSem);
$paginSemanas    = paginate($totalSemanasCnt, $pagSemanas, $perPageSem);
$listaSemanas    = getCombustiblesSemanasAdmin(
    $perPageSem, $paginSemanas['offset'], $filtroEstadoSem, $filtroBusqSem
);

// ── Tab Precios ───────────────────────────────────────────────
$semanaIdPrecios = cleanInt($_GET['semana_id'] ?? 0);
$listaSemanasSel = getCombustiblesSemanasAdmin(100, 0);

if (!$semanaIdPrecios && !empty($listaSemanasSel)) {
    // Default: semana actual o la más reciente
    $semActual = db()->fetchOne(
        "SELECT id FROM combustibles_semanas WHERE es_actual = 1 LIMIT 1"
    );
    $semanaIdPrecios = $semActual ? (int)$semActual['id'] : (int)$listaSemanasSel[0]['id'];
}

$semanaEditando  = null;
$preciosEditando = [];
if ($semanaIdPrecios) {
    $semanaEditando = db()->fetchOne(
        "SELECT * FROM combustibles_semanas WHERE id = ? LIMIT 1",
        [$semanaIdPrecios]
    );
    if ($semanaEditando) {
        $preciosEditando = db()->fetchAll(
            "SELECT cp.*, ct.nombre AS tipo_nombre, ct.icono, ct.color,
                    ct.unidad, ct.orden, ct.slug AS tipo_slug
             FROM combustibles_precios cp
             INNER JOIN combustibles_tipos ct ON ct.id = cp.combustible_id
             WHERE cp.semana_inicio = ?
             ORDER BY ct.orden ASC",
            [$semanaEditando['semana_inicio']]
        );
        if (empty($preciosEditando)) {
            foreach (getCombustiblesTipos(true) as $t) {
                $preciosEditando[] = array_merge($t, [
                    'combustible_id'  => $t['id'],
                    'precio'          => '',
                    'precio_anterior' => '',
                    'variacion'       => null,
                    'variacion_pct'   => null,
                    'nota'            => '',
                    'tipo_nombre'     => $t['nombre'],
                    'tipo_slug'       => $t['slug'],
                ]);
            }
        }
    }
}

// ── Tab Tipos ─────────────────────────────────────────────────
$listaTipos = getCombustiblesTipos(false);

// ── Estadísticas del panel ────────────────────────────────────
$statsAdmin = [
    'total_semanas'  => (int)db()->fetchColumn("SELECT COUNT(*) FROM combustibles_semanas"),
    'publicadas'     => (int)db()->fetchColumn("SELECT COUNT(*) FROM combustibles_semanas WHERE publicado = 1"),
    'total_tipos'    => (int)db()->fetchColumn("SELECT COUNT(*) FROM combustibles_tipos WHERE activo = 1"),
    'total_precios'  => (int)db()->fetchColumn("SELECT COUNT(*) FROM combustibles_precios"),
    'ult_vigencia'   => db()->fetchColumn(
        "SELECT MAX(fecha_vigencia) FROM combustibles_semanas WHERE publicado = 1"
    ),
];

// ── Próximo viernes para el modal ────────────────────────────
$proximoViernes = getProximoViernes();
$semanasPV      = getLunesYDomingoDeViernes($proximoViernes);

// ── Semana vigente actual ─────────────────────────────────────
$semanaVigente = db()->fetchOne(
    "SELECT id, titulo, fecha_vigencia, es_actual, publicado
     FROM combustibles_semanas WHERE es_actual = 1 LIMIT 1"
);

// ── Título de página ──────────────────────────────────────────
$pageTitle = 'Precio de Combustibles — Panel Admin';

// ============================================================
// SIDEBAR — Se incluye ANTES del DOCTYPE (patrón del sistema)
// ============================================================
require_once __DIR__ . '/sidebar.php';
?>
<!DOCTYPE html>
<html lang="es"
      data-theme="<?= e(Config::get('apariencia_modo_oscuro', 'auto')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Playfair+Display:wght@700;800&display=swap"
      rel="stylesheet">
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet"
      href="<?= APP_URL ?>/assets/css/style.css?v=<?= APP_VERSION ?>">
<style>
/* ============================================================
   ADMIN BASE LAYOUT — idéntico a admin/encuestas.php
   ============================================================ */
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
    font-size: .82rem; font-weight: 600;
    color: rgba(255,255,255,.9); display: block; line-height: 1.2;
}
.admin-sidebar__user-role {
    font-size: .68rem; color: rgba(255,255,255,.4);
}
.admin-nav { flex: 1; padding: 12px 0; overflow-y: auto; }
.admin-nav__section {
    padding: 14px 20px 6px; font-size: .62rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .1em;
    color: rgba(255,255,255,.25);
}
.admin-nav__item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 20px; font-size: .82rem; font-weight: 500;
    color: rgba(255,255,255,.6); text-decoration: none;
    transition: all .2s ease; position: relative;
}
.admin-nav__item:hover, .admin-nav__item.active {
    color: #fff; background: rgba(255,255,255,.06);
}
.admin-nav__item.active::before {
    content: ''; position: absolute; left: 0; top: 0; bottom: 0;
    width: 3px; background: var(--primary); border-radius: 0 3px 3px 0;
}
.admin-nav__item i { width: 18px; text-align: center; font-size: .9rem; }
.admin-nav__badge {
    margin-left: auto; font-size: .6rem; font-weight: 800;
    padding: 1px 6px; border-radius: 20px; background: var(--primary); color: #fff;
}
.admin-sidebar__footer {
    padding: 8px 0; border-top: 1px solid rgba(255,255,255,.07); flex-shrink: 0;
}
.admin-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.5);
    z-index: calc(var(--z-header) - 1);
    display: none; backdrop-filter: blur(2px);
}
.admin-overlay.open { display: block; }

.admin-content {
    flex: 1; margin-left: 260px;
    display: flex; flex-direction: column; min-height: 100vh;
}
@media (max-width: 991px) {
    .admin-sidebar   { transform: translateX(-100%); }
    .admin-sidebar.open { transform: translateX(0); box-shadow: 4px 0 20px rgba(0,0,0,.3); }
    .admin-content   { margin-left: 0; }
}

/* ============================================================
   TOPBAR
   ============================================================ */
.admin-topbar {
    background: var(--bg-surface);
    border-bottom: 1px solid var(--border-color);
    padding: 14px 24px;
    display: flex; align-items: center;
    justify-content: space-between;
    gap: 12px; position: sticky; top: 0;
    z-index: 100; box-shadow: var(--shadow-sm);
    flex-wrap: wrap;
}
.admin-topbar__left {
    display: flex; align-items: center; gap: 12px; min-width: 0;
}
.topbar-menu-btn {
    width: 36px; height: 36px; border: none;
    background: var(--bg-surface-2); border-radius: 9px;
    cursor: pointer; display: flex; align-items: center;
    justify-content: center; color: var(--text-primary);
    font-size: 1.1rem; transition: all .2s ease; flex-shrink: 0;
}
.topbar-menu-btn:hover { background: var(--primary); color: #fff; }
.admin-topbar__icon {
    width: 38px; height: 38px;
    background: linear-gradient(135deg, #0f2027, #2c5364);
    border-radius: 10px; display: flex; align-items: center;
    justify-content: center; color: #f59e0b; font-size: 1.1rem; flex-shrink: 0;
}
.admin-topbar__title {
    font-size: .95rem; font-weight: 800; color: var(--text-primary); line-height: 1.2;
}
.admin-topbar__sub {
    font-size: .68rem; color: var(--text-muted); font-weight: 400;
}
.admin-topbar__actions {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}

/* ============================================================
   MAIN CONTENT
   ============================================================ */
.admin-main { flex: 1; padding: 24px; }
@media (max-width: 640px) {
    .admin-main    { padding: 14px; }
    .admin-topbar  { padding: 10px 14px; }
}

/* ============================================================
   FLASH MESSAGES
   ============================================================ */
.flash-admin {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 14px 18px; border-radius: var(--border-radius-lg);
    margin-bottom: 20px; font-size: .875rem;
    border-left: 4px solid transparent; line-height: 1.5;
}
.flash-admin--success {
    background: rgba(34,197,94,.08); border-color: #22c55e; color: #166534;
}
.flash-admin--error {
    background: rgba(239,68,68,.08); border-color: #ef4444; color: #991b1b;
}
.flash-admin--warning {
    background: rgba(245,158,11,.08); border-color: #f59e0b; color: #92400e;
}
.flash-admin--info {
    background: rgba(13,110,253,.08); border-color: #0d6efd; color: #1e40af;
}
[data-theme="dark"] .flash-admin--success { color: #86efac; }
[data-theme="dark"] .flash-admin--error   { color: #fca5a5; }
[data-theme="dark"] .flash-admin--warning { color: #fde68a; }
[data-theme="dark"] .flash-admin--info    { color: #93c5fd; }

/* ============================================================
   STATS RÁPIDAS
   ============================================================ */
.comb-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
    gap: 14px; margin-bottom: 24px;
}
.comb-stat {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-lg);
    padding: 16px 18px;
    border-top: 3px solid var(--sc, var(--primary));
    transition: box-shadow .2s ease;
}
.comb-stat:hover { box-shadow: var(--shadow-sm); }
.comb-stat__val {
    font-size: 1.65rem; font-weight: 900;
    color: var(--text-primary); display: block;
    font-variant-numeric: tabular-nums; line-height: 1;
    margin-bottom: 5px;
}
.comb-stat__lbl {
    font-size: .68rem; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: .07em;
}
.comb-stat__date {
    font-size: .7rem; color: var(--text-muted);
    margin-top: 2px; display: block;
}

/* ============================================================
   TABS
   ============================================================ */
.comb-tabs {
    display: flex; gap: 2px;
    background: var(--bg-surface-2);
    padding: 4px; border-radius: var(--border-radius-lg);
    margin-bottom: 22px; flex-wrap: wrap;
}
.comb-tab {
    flex: 1; min-width: 110px;
    display: flex; align-items: center;
    justify-content: center; gap: 7px;
    padding: 10px 16px;
    border-radius: calc(var(--border-radius-lg) - 4px);
    border: none; cursor: pointer;
    font-size: .82rem; font-weight: 700;
    color: var(--text-muted); background: transparent;
    text-decoration: none; transition: all .2s ease;
}
.comb-tab.active {
    background: var(--bg-surface);
    color: var(--primary);
    box-shadow: 0 1px 6px rgba(0,0,0,.1);
}
.comb-tab:hover:not(.active) { color: var(--text-primary); }
.comb-tab-badge {
    background: var(--primary); color: #fff;
    font-size: .6rem; font-weight: 800;
    padding: 1px 6px; border-radius: 20px;
}
.comb-tab-badge--gray {
    background: var(--bg-surface-2); color: var(--text-muted);
    border: 1px solid var(--border-color);
}

/* ============================================================
   PANEL / CARD
   ============================================================ */
.comb-panel {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-lg);
    overflow: hidden;
}
.comb-panel__head {
    padding: 16px 22px; border-bottom: 1px solid var(--border-color);
    display: flex; align-items: center;
    justify-content: space-between; flex-wrap: wrap; gap: 12px;
    background: var(--bg-surface-2);
}
.comb-panel__title {
    font-size: .95rem; font-weight: 800;
    color: var(--text-primary);
    display: flex; align-items: center; gap: 8px;
}
.comb-panel__body { padding: 22px; }

/* ============================================================
   TABLA ADMIN
   ============================================================ */
.cadm-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
.cadm-table th {
    background: var(--bg-surface-2); padding: 10px 14px;
    text-align: left; font-size: .7rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .07em;
    color: var(--text-muted); border-bottom: 2px solid var(--border-color);
    white-space: nowrap;
}
.cadm-table td {
    padding: 13px 14px; border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}
.cadm-table tr:last-child td { border-bottom: none; }
.cadm-table tr:hover td { background: var(--bg-surface-2); }
.table-wrap { overflow-x: auto; }

/* ============================================================
   FORM CONTROLS
   ============================================================ */
.fc-label {
    display: block; font-size: .73rem; font-weight: 700;
    color: var(--text-secondary); margin-bottom: 5px;
    text-transform: uppercase; letter-spacing: .05em;
}
.fc-input {
    width: 100%; padding: 9px 12px;
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    background: var(--bg-body); color: var(--text-primary);
    font-size: .875rem; font-family: var(--font-sans);
    transition: border-color .2s, box-shadow .2s;
}
.fc-input:focus {
    outline: none; border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(230,57,70,.12);
}
.fc-input--price {
    font-size: 1.05rem; font-weight: 800;
    color: var(--primary); font-variant-numeric: tabular-nums;
}
.fc-select {
    width: 100%; padding: 9px 12px;
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    background: var(--bg-body); color: var(--text-primary);
    font-size: .875rem; cursor: pointer;
}
.fc-select:focus { outline: none; border-color: var(--primary); }
.fc-textarea {
    width: 100%; padding: 9px 12px;
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    background: var(--bg-body); color: var(--text-primary);
    font-size: .875rem; resize: vertical; min-height: 70px;
    font-family: var(--font-sans);
}
.fc-textarea:focus { outline: none; border-color: var(--primary); }
.fc-help { font-size: .68rem; color: var(--text-muted); margin-top: 4px; display: block; }
.fc-row { margin-bottom: 14px; }
.fc-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 480px) { .fc-grid-2 { grid-template-columns: 1fr; } }

/* ============================================================
   BOTONES
   ============================================================ */
.btn-adm {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; border-radius: var(--border-radius-full);
    font-size: .82rem; font-weight: 700; cursor: pointer;
    border: none; transition: all .2s ease; text-decoration: none;
    white-space: nowrap;
}
.btn-adm--primary  { background: var(--primary); color: #fff; }
.btn-adm--primary:hover { opacity: .88; color: #fff; }
.btn-adm--success  { background: #22c55e; color: #fff; }
.btn-adm--success:hover { opacity: .88; }
.btn-adm--ghost {
    background: transparent; color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-adm--ghost:hover { background: var(--bg-surface-2); color: var(--text-primary); }
.btn-adm--danger { background: transparent; color: #ef4444; border: 1px solid rgba(239,68,68,.3); }
.btn-adm--danger:hover { background: #ef4444; color: #fff; }
.btn-adm--sm { padding: 6px 14px; font-size: .75rem; }
.btn-adm:disabled { opacity: .45; cursor: not-allowed; pointer-events: none; }

/* ============================================================
   BADGES
   ============================================================ */
.badge-adm {
    display: inline-flex; align-items: center; gap: 3px;
    padding: 2px 9px; border-radius: var(--border-radius-full);
    font-size: .65rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: .05em;
}
.badge-adm--pub   { background: rgba(34,197,94,.15); color: #22c55e; }
.badge-adm--draft { background: rgba(245,158,11,.15); color: #f59e0b; }
.badge-adm--act   { background: rgba(13,110,253,.15); color: #0d6efd; }
.badge-adm--muted { background: var(--bg-surface-2); color: var(--text-muted);
                    border: 1px solid var(--border-color); }

/* ============================================================
   TOGGLE SWITCH
   ============================================================ */
.toggle-sw { position: relative; width: 44px; height: 24px; display: inline-block; flex-shrink: 0; }
.toggle-sw input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
    position: absolute; inset: 0;
    background: var(--border-color); border-radius: 34px;
    cursor: pointer; transition: .25s ease;
}
.toggle-slider::before {
    content: ''; position: absolute;
    width: 18px; height: 18px; left: 3px; bottom: 3px;
    background: #fff; border-radius: 50%; transition: .25s ease;
}
.toggle-sw input:checked + .toggle-slider { background: var(--primary); }
.toggle-sw input:checked + .toggle-slider::before { transform: translateX(20px); }

/* ============================================================
   MODALES
   ============================================================ */
.cadm-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.6);
    z-index: 1060; display: none;
    align-items: center; justify-content: center;
    padding: 16px; backdrop-filter: blur(4px);
}
.cadm-overlay.open { display: flex; }
.cadm-modal {
    background: var(--bg-surface);
    border-radius: var(--border-radius-xl);
    width: 100%; max-width: 580px; max-height: 90vh;
    overflow: hidden; display: flex; flex-direction: column;
    box-shadow: 0 24px 64px rgba(0,0,0,.35);
    animation: modalIn .22s ease;
}
@keyframes modalIn {
    from { opacity: 0; transform: scale(.96) translateY(10px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}
.cadm-modal--lg { max-width: 740px; }
.cadm-modal__head {
    padding: 18px 22px 14px; border-bottom: 1px solid var(--border-color);
    display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
}
.cadm-modal__title {
    font-size: 1rem; font-weight: 800; color: var(--text-primary);
    display: flex; align-items: center; gap: 8px;
}
.cadm-modal__close {
    width: 30px; height: 30px; border-radius: 50%;
    border: none; background: var(--bg-surface-2);
    cursor: pointer; display: flex; align-items: center;
    justify-content: center; color: var(--text-muted);
    font-size: .85rem; transition: all .2s;
}
.cadm-modal__close:hover { background: var(--primary); color: #fff; }
.cadm-modal__body { padding: 20px 22px; overflow-y: auto; flex: 1; }
.cadm-modal__foot {
    padding: 14px 22px; border-top: 1px solid var(--border-color);
    display: flex; justify-content: flex-end; gap: 10px;
    flex-shrink: 0; background: var(--bg-surface-2);
}

/* ============================================================
   GRILLA DE PRECIOS
   ============================================================ */
.precio-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
    gap: 14px;
}
.precio-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius-lg);
    padding: 18px; position: relative;
    border-left: 4px solid var(--pc, var(--primary));
    transition: box-shadow .2s ease;
}
.precio-card:hover { box-shadow: var(--shadow-sm); }
.precio-card__head {
    display: flex; align-items: center; gap: 10px; margin-bottom: 14px;
}
.precio-card__ico {
    width: 38px; height: 38px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; flex-shrink: 0;
}
.precio-card__name { font-size: .875rem; font-weight: 700; color: var(--text-primary); line-height: 1.3; }
.precio-card__unit { font-size: .65rem; color: var(--text-muted); }
.precio-var-preview {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: .72rem; font-weight: 700;
    padding: 3px 9px; border-radius: var(--border-radius-full);
    margin-top: 8px; transition: all .2s;
}
.ant-display {
    font-size: .72rem; color: var(--text-muted);
    margin-bottom: 8px; padding: 6px 10px;
    background: var(--bg-surface-2); border-radius: var(--border-radius-sm);
}

/* ============================================================
   FILTRO BAR
   ============================================================ */
.filter-bar {
    display: flex; gap: 8px; flex-wrap: wrap;
    align-items: center; padding: 12px 18px;
    border-bottom: 1px solid var(--border-color);
    background: var(--bg-surface);
}
.filter-bar .fc-input, .filter-bar .fc-select {
    max-width: 220px; padding: 7px 11px; font-size: .82rem;
}

/* ============================================================
   SEMANA SELECTOR (TAB PRECIOS)
   ============================================================ */
.semana-selector {
    padding: 14px 22px; border-bottom: 1px solid var(--border-color);
    background: var(--bg-surface-2);
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
}
.semana-info-bar {
    padding: 12px 22px; border-bottom: 1px solid var(--border-color);
    background: linear-gradient(135deg, rgba(245,158,11,.04), transparent);
    display: flex; flex-wrap: wrap; gap: 20px;
    align-items: center; justify-content: space-between;
}
.semana-info-item span {
    font-size: .62rem; text-transform: uppercase;
    letter-spacing: .07em; color: var(--text-muted); display: block;
}
.semana-info-item strong {
    font-size: .82rem; font-weight: 700; color: var(--text-primary);
}

/* ============================================================
   EMPTY STATE
   ============================================================ */
.empty-state {
    text-align: center; padding: 60px 20px; color: var(--text-muted);
}
.empty-state i {
    font-size: 2.8rem; opacity: .25;
    display: block; margin-bottom: 14px;
}
.empty-state h3 {
    font-family: var(--font-serif);
    font-size: 1.05rem; margin-bottom: 8px; color: var(--text-secondary);
}

/* ============================================================
   TIPOS — Color Circle
   ============================================================ */
.color-circle {
    width: 22px; height: 22px; border-radius: 50%;
    border: 2px solid var(--border-color); display: inline-block; flex-shrink: 0;
}

/* ============================================================
   PAGINACIÓN
   ============================================================ */
.pag-bar {
    padding: 14px 20px; border-top: 1px solid var(--border-color);
    display: flex; justify-content: center; gap: 5px; flex-wrap: wrap;
}
.pag-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 34px; height: 34px; padding: 0 10px;
    border-radius: var(--border-radius-full);
    font-size: .8rem; font-weight: 700; text-decoration: none;
    transition: all .2s ease;
    border: 1px solid var(--border-color); color: var(--text-secondary);
    background: transparent;
}
.pag-btn--active { background: var(--primary); color: #fff; border-color: var(--primary); }
.pag-btn:hover:not(.pag-btn--active) { background: var(--bg-surface-2); color: var(--text-primary); }

@media (max-width: 768px) {
    .precio-grid { grid-template-columns: 1fr; }
    .comb-stats  { grid-template-columns: 1fr 1fr; }
    .comb-tabs   { flex-direction: column; }
}
</style>
</head>
<body>
<div class="admin-wrapper">
    <div class="admin-content">

        <!-- ════════════════════════════════════════════
             TOPBAR
        ════════════════════════════════════════════ -->
        <header class="admin-topbar">
            <div class="admin-topbar__left">
                <button class="topbar-menu-btn d-lg-none"
                        id="sidebarToggle"
                        aria-label="Abrir menú">
                    <i class="bi bi-list"></i>
                </button>
                <div class="admin-topbar__icon">
                    <i class="bi bi-fuel-pump-fill"></i>
                </div>
                <div>
                    <div class="admin-topbar__title">Precio de Combustibles</div>
                    <div class="admin-topbar__sub">
                        Módulo de gestión de precios semanales
                        <?php if ($semanaVigente): ?>
                        — <span style="color:#f59e0b;font-weight:700">
                            <?= e($semanaVigente['titulo'] ?? '') ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="admin-topbar__actions">
                <a href="<?= APP_URL ?>/combustibles.php"
                   target="_blank"
                   class="btn-adm btn-adm--ghost btn-adm--sm">
                    <i class="bi bi-eye-fill"></i>
                    <span class="d-none d-sm-inline">Ver página</span>
                </a>
                <button type="button"
                        onclick="abrirModal('modalNuevaSemana')"
                        class="btn-adm btn-adm--primary btn-adm--sm">
                    <i class="bi bi-plus-circle-fill"></i>
                    <span class="d-none d-sm-inline">Nueva Semana</span>
                </button>
            </div>
        </header>

        <!-- ════════════════════════════════════════════
             CONTENIDO PRINCIPAL
        ════════════════════════════════════════════ -->
        <main class="admin-main">

            <!-- Flash messages -->
            <?php if ($flash && !empty($flash['type'])): ?>
            <div class="flash-admin flash-admin--<?= e($flash['type']) ?>"
                 role="alert">
                <i class="bi bi-<?= match($flash['type']) {
                    'success' => 'check-circle-fill',
                    'error'   => 'exclamation-circle-fill',
                    'warning' => 'exclamation-triangle-fill',
                    default   => 'info-circle-fill'
                } ?>" style="flex-shrink:0;font-size:1rem"></i>
                <span><?= $flash['msg'] ?></span>
            </div>
            <?php endif; ?>

            <!-- ── Stats rápidas ─────────────────────────── -->
            <div class="comb-stats">
                <div class="comb-stat" style="--sc:var(--primary)">
                    <span class="comb-stat__val"
                          id="statSemanas">
                        <?= $statsAdmin['total_semanas'] ?>
                    </span>
                    <span class="comb-stat__lbl">Total semanas</span>
                </div>
                <div class="comb-stat" style="--sc:#22c55e">
                    <span class="comb-stat__val"
                          style="color:#22c55e"
                          id="statPublicadas">
                        <?= $statsAdmin['publicadas'] ?>
                    </span>
                    <span class="comb-stat__lbl">Publicadas</span>
                </div>
                <div class="comb-stat" style="--sc:#f59e0b">
                    <span class="comb-stat__val"
                          style="color:#f59e0b"
                          id="statTipos">
                        <?= $statsAdmin['total_tipos'] ?>
                    </span>
                    <span class="comb-stat__lbl">Tipos activos</span>
                </div>
                <div class="comb-stat" style="--sc:#6b7280">
                    <span class="comb-stat__val"
                          style="color:var(--text-muted)"
                          id="statRegistros">
                        <?= $statsAdmin['total_precios'] ?>
                    </span>
                    <span class="comb-stat__lbl">Registros precio</span>
                    <?php if ($statsAdmin['ult_vigencia']): ?>
                    <span class="comb-stat__date">
                        Último: <?= formatFechaEsp($statsAdmin['ult_vigencia'], 'medio') ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Tabs ──────────────────────────────────── -->
            <div class="comb-tabs" role="tablist">
                <a href="?tab=semanas"
                   class="comb-tab <?= $tabActiva === 'semanas' ? 'active' : '' ?>"
                   role="tab"
                   aria-selected="<?= $tabActiva === 'semanas' ? 'true' : 'false' ?>">
                    <i class="bi bi-calendar-week-fill"></i>
                    Semanas
                    <?php if ($statsAdmin['total_semanas'] > 0): ?>
                    <span class="comb-tab-badge">
                        <?= $statsAdmin['total_semanas'] ?>
                    </span>
                    <?php endif; ?>
                </a>
                <a href="?tab=precios<?= $semanaIdPrecios ? '&semana_id=' . $semanaIdPrecios : '' ?>"
                   class="comb-tab <?= $tabActiva === 'precios' ? 'active' : '' ?>"
                   role="tab"
                   aria-selected="<?= $tabActiva === 'precios' ? 'true' : 'false' ?>">
                    <i class="bi bi-currency-dollar"></i>
                    Editar Precios
                </a>
                <a href="?tab=tipos"
                   class="comb-tab <?= $tabActiva === 'tipos' ? 'active' : '' ?>"
                   role="tab"
                   aria-selected="<?= $tabActiva === 'tipos' ? 'true' : 'false' ?>">
                    <i class="bi bi-fuel-pump"></i>
                    Tipos
                    <span class="comb-tab-badge comb-tab-badge--gray">
                        <?= $statsAdmin['total_tipos'] ?>
                    </span>
                </a>
            </div>

            <!-- ════════════════════════════════════════════
                 TAB 1: SEMANAS
            ════════════════════════════════════════════ -->
            <?php if ($tabActiva === 'semanas'): ?>
            <div class="comb-panel" role="tabpanel"
                 aria-label="Gestión de Semanas">

                <div class="comb-panel__head">
                    <div class="comb-panel__title">
                        <i class="bi bi-calendar3"
                           style="color:var(--primary)"></i>
                        Gestión de Semanas
                        <span style="font-size:.72rem;color:var(--text-muted);font-weight:400">
                            (<?= $totalSemanasCnt ?> total)
                        </span>
                    </div>
                    <button type="button"
                            onclick="abrirModal('modalNuevaSemana')"
                            class="btn-adm btn-adm--primary btn-adm--sm">
                        <i class="bi bi-plus-circle-fill"></i>
                        Nueva Semana
                    </button>
                </div>

                <!-- Filtros -->
                <form method="GET" action="<?= APP_URL ?>/admin/combustibles.php">
                    <input type="hidden" name="tab" value="semanas">
                    <div class="filter-bar">
                        <input type="search"
                               name="q"
                               class="fc-input"
                               style="max-width:260px"
                               placeholder="🔍 Buscar por título o fecha..."
                               value="<?= e($filtroBusqSem) ?>">
                        <select name="estado"
                                class="fc-select"
                                style="max-width:170px"
                                onchange="this.form.submit()">
                            <option value="">Todos los estados</option>
                            <option value="publicado"
                                    <?= $filtroEstadoSem === 'publicado' ? 'selected' : '' ?>>
                                ✅ Publicadas
                            </option>
                            <option value="borrador"
                                    <?= $filtroEstadoSem === 'borrador' ? 'selected' : '' ?>>
                                📝 Borradores
                            </option>
                        </select>
                        <button type="submit"
                                class="btn-adm btn-adm--ghost btn-adm--sm">
                            <i class="bi bi-search"></i> Filtrar
                        </button>
                        <?php if ($filtroBusqSem || $filtroEstadoSem): ?>
                        <a href="?tab=semanas"
                           class="btn-adm btn-adm--ghost btn-adm--sm">
                            <i class="bi bi-x-circle"></i> Limpiar
                        </a>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Tabla de semanas -->
                <?php if (empty($listaSemanas)): ?>
                <div class="empty-state">
                    <i class="bi bi-calendar-x"></i>
                    <h3>No hay semanas registradas</h3>
                    <p style="font-size:.875rem">
                        Crea la primera semana con "Nueva Semana".
                    </p>
                    <button type="button"
                            onclick="abrirModal('modalNuevaSemana')"
                            class="btn-adm btn-adm--primary"
                            style="margin-top:16px">
                        <i class="bi bi-plus-circle-fill"></i>
                        Nueva Semana
                    </button>
                </div>
                <?php else: ?>
                <div class="table-wrap">
                <table class="cadm-table"
                       aria-label="Lista de semanas de combustibles">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Semana / Período</th>
                            <th>Vigencia</th>
                            <th>Estado</th>
                            <th>Actual</th>
                            <th>Precios</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($listaSemanas as $sem):
                        $cntPrecSem = (int)db()->fetchColumn(
                            "SELECT COUNT(*) FROM combustibles_precios WHERE semana_inicio = ?",
                            [$sem['semana_inicio']]
                        );
                        $precOk  = $cntPrecSem >= 7;
                        $precWrn = $cntPrecSem > 0 && !$precOk;
                        $precCol = $precOk ? '#22c55e' : ($precWrn ? '#f59e0b' : '#ef4444');
                        $precIco = $precOk ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill';
                    ?>
                    <tr id="sem-row-<?= (int)$sem['id'] ?>">
                        <td style="font-size:.72rem;color:var(--text-muted);font-weight:800">
                            #<?= (int)$sem['id'] ?>
                        </td>
                        <td>
                            <div style="font-weight:700;color:var(--text-primary);
                                         font-size:.875rem;line-height:1.3">
                                <?= e($sem['titulo'] ?? 'Sin título') ?>
                            </div>
                            <div style="font-size:.7rem;color:var(--text-muted);margin-top:2px">
                                <?= formatFechaEsp($sem['semana_inicio'], 'semana') ?>
                                —
                                <?= formatFechaEsp($sem['semana_fin'], 'semana') ?>
                                <?= e(date('Y', strtotime($sem['semana_inicio']))) ?>
                            </div>
                            <?php if (!empty($sem['nota_semanal'])): ?>
                            <div style="font-size:.68rem;color:var(--text-muted);
                                         font-style:italic;margin-top:3px">
                                <?= e(mb_substr($sem['nota_semanal'], 0, 55)) ?>
                                <?= mb_strlen($sem['nota_semanal']) > 55 ? '…' : '' ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:5px;
                                         font-size:.82rem;color:var(--text-secondary)">
                                <i class="bi bi-calendar-check"
                                   style="color:var(--primary)"></i>
                                <?= formatFechaEsp($sem['fecha_vigencia'], 'medio') ?>
                            </div>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px">
                                <label class="toggle-sw"
                                       title="<?= $sem['publicado'] ? 'Publicada — clic para despublicar' : 'Borrador — clic para publicar' ?>">
                                    <input type="checkbox"
                                           <?= $sem['publicado'] ? 'checked' : '' ?>
                                           onchange="toggleSemana(<?= (int)$sem['id'] ?>, 'publicado', this)"
                                           aria-label="Publicar semana">
                                    <span class="toggle-slider"></span>
                                </label>
                                <span id="pub-lbl-<?= (int)$sem['id'] ?>"
                                      class="badge-adm badge-adm--<?= $sem['publicado'] ? 'pub' : 'draft' ?>">
                                    <?= $sem['publicado'] ? 'Publicada' : 'Borrador' ?>
                                </span>
                            </div>
                        </td>
                        <td>
                            <?php if ($sem['es_actual']): ?>
                            <span class="badge-adm badge-adm--act">
                                <i class="bi bi-star-fill"></i>
                                ACTUAL
                            </span>
                            <?php else: ?>
                            <button type="button"
                                    id="btn-act-<?= (int)$sem['id'] ?>"
                                    onclick="marcarActual(<?= (int)$sem['id'] ?>)"
                                    class="btn-adm btn-adm--ghost btn-adm--sm"
                                    title="Marcar como semana actual">
                                <i class="bi bi-star"></i>
                                Marcar
                            </button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:5px;
                                         font-size:.78rem">
                                <i class="bi <?= $precIco ?>"
                                   style="color:<?= $precCol ?>"></i>
                                <span style="font-weight:700;color:<?= $precCol ?>">
                                    <?= $cntPrecSem ?>/7
                                </span>
                                <span style="color:var(--text-muted)">precios</span>
                            </div>
                        </td>
                        <td>
                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                                <a href="?tab=precios&semana_id=<?= (int)$sem['id'] ?>"
                                   class="btn-adm btn-adm--ghost btn-adm--sm"
                                   title="Editar precios de esta semana">
                                    <i class="bi bi-pencil-fill"
                                       style="color:var(--primary)"></i>
                                    Editar
                                </a>
                                <?php if (!$sem['es_actual']): ?>
                                <button type="button"
                                        onclick="eliminarSemana(<?= (int)$sem['id'] ?>,
                                                '<?= e(addslashes($sem['titulo'] ?? '')) ?>')"
                                        class="btn-adm btn-adm--danger btn-adm--sm"
                                        title="Eliminar semana">
                                    <i class="bi bi-trash3-fill"></i>
                                </button>
                                <?php else: ?>
                                <button class="btn-adm btn-adm--ghost btn-adm--sm"
                                        disabled
                                        title="No puedes eliminar la semana actual"
                                        style="opacity:.35;cursor:not-allowed">
                                    <i class="bi bi-lock-fill"
                                       style="font-size:.7rem"></i>
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
                <?php if ($paginSemanas['totalPaginas'] > 1): ?>
                <div class="pag-bar">
                    <?php if ($paginSemanas['hasPrev']): ?>
                    <a href="?tab=semanas&pag=<?= $pagSemanas - 1 ?>&q=<?= urlencode($filtroBusqSem) ?>&estado=<?= e($filtroEstadoSem) ?>"
                       class="pag-btn">
                        <i class="bi bi-chevron-left"></i> Ant.
                    </a>
                    <?php endif; ?>
                    <?php for ($p = 1; $p <= min($paginSemanas['totalPaginas'], 7); $p++): ?>
                    <a href="?tab=semanas&pag=<?= $p ?>&q=<?= urlencode($filtroBusqSem) ?>&estado=<?= e($filtroEstadoSem) ?>"
                       class="pag-btn <?= $p === $pagSemanas ? 'pag-btn--active' : '' ?>">
                        <?= $p ?>
                    </a>
                    <?php endfor; ?>
                    <?php if ($paginSemanas['hasNext']): ?>
                    <a href="?tab=semanas&pag=<?= $pagSemanas + 1 ?>&q=<?= urlencode($filtroBusqSem) ?>&estado=<?= e($filtroEstadoSem) ?>"
                       class="pag-btn">
                        Sig. <i class="bi bi-chevron-right"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php endif; /* fin listaSemanas vacío vs no vacío */ ?>

            </div><!-- /.comb-panel semanas -->
            <?php endif; ?>

            <!-- ════════════════════════════════════════════
                 TAB 2: EDITAR PRECIOS
            ════════════════════════════════════════════ -->
            <?php if ($tabActiva === 'precios'): ?>
            <div class="comb-panel" role="tabpanel"
                 aria-label="Editar precios">

                <div class="comb-panel__head">
                    <div class="comb-panel__title">
                        <i class="bi bi-currency-dollar"
                           style="color:#22c55e"></i>
                        Editar Precios de la Semana
                    </div>
                </div>

                <!-- Selector de semana -->
                <div class="semana-selector">
                    <label class="fc-label" style="margin:0;white-space:nowrap;
                                                    font-size:.78rem;color:var(--text-muted)">
                        <i class="bi bi-calendar3"
                           style="color:var(--primary)"></i>
                        Semana:
                    </label>
                    <form method="GET"
                          action="<?= APP_URL ?>/admin/combustibles.php"
                          style="display:flex;gap:8px;align-items:center;flex:1;flex-wrap:wrap">
                        <input type="hidden" name="tab" value="precios">
                        <select name="semana_id"
                                class="fc-select"
                                style="flex:1;max-width:440px;font-size:.82rem"
                                onchange="this.form.submit()">
                            <?php if (empty($listaSemanasSel)): ?>
                            <option value="">Sin semanas — Crea una primero</option>
                            <?php else: ?>
                            <?php foreach ($listaSemanasSel as $sl): ?>
                            <option value="<?= (int)$sl['id'] ?>"
                                    <?= (int)$sl['id'] === $semanaIdPrecios ? 'selected' : '' ?>>
                                <?= e($sl['titulo'] ?? 'Semana ' . $sl['semana_inicio']) ?>
                                <?= $sl['es_actual'] ? ' ⭐ ACTUAL' : '' ?>
                                <?= !$sl['publicado'] ? ' [Borrador]' : '' ?>
                            </option>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <?php if (empty($listaSemanasSel)): ?>
                        <button type="button"
                                onclick="abrirModal('modalNuevaSemana')"
                                class="btn-adm btn-adm--primary btn-adm--sm">
                            <i class="bi bi-plus-circle-fill"></i>
                            Crear semana
                        </button>
                        <?php endif; ?>
                    </form>
                </div>

                <?php if ($semanaEditando): ?>

                <!-- Info de la semana -->
                <div class="semana-info-bar">
                    <div style="display:flex;flex-wrap:wrap;gap:20px">
                        <div class="semana-info-item">
                            <span>Vigencia</span>
                            <strong>
                                <?= formatFechaEsp($semanaEditando['fecha_vigencia']) ?>
                            </strong>
                        </div>
                        <div class="semana-info-item">
                            <span>Estado</span>
                            <strong>
                                <span class="badge-adm badge-adm--<?= $semanaEditando['publicado'] ? 'pub' : 'draft' ?>">
                                    <?= $semanaEditando['publicado'] ? 'Publicada' : 'Borrador' ?>
                                </span>
                                <?php if ($semanaEditando['es_actual']): ?>
                                <span class="badge-adm badge-adm--act" style="margin-left:4px">
                                    <i class="bi bi-star-fill"></i> ACTUAL
                                </span>
                                <?php endif; ?>
                            </strong>
                        </div>
                        <div class="semana-info-item">
                            <span>Período</span>
                            <strong>
                                <?= formatFechaEsp($semanaEditando['semana_inicio'], 'semana') ?>
                                al
                                <?= formatFechaEsp($semanaEditando['semana_fin'], 'semana') ?>
                                <?= e(date('Y', strtotime($semanaEditando['semana_inicio']))) ?>
                            </strong>
                        </div>
                        <div class="semana-info-item">
                            <span>Fuente</span>
                            <strong style="font-size:.72rem">
                                <?= e(mb_substr($semanaEditando['fuente'] ?? '', 0, 45)) ?>
                            </strong>
                        </div>
                    </div>
                    <button type="button"
                            onclick="recalcularTodas()"
                            class="btn-adm btn-adm--ghost btn-adm--sm">
                        <i class="bi bi-arrow-repeat"></i>
                        Recalcular variaciones
                    </button>
                </div>

                <!-- Formulario de precios -->
                <?php if (!empty($preciosEditando)): ?>
                <form method="POST"
                      action="<?= APP_URL ?>/admin/combustibles.php?tab=precios&semana_id=<?= (int)$semanaEditando['id'] ?>"
                      id="formPrecios"
                      novalidate>
                    <input type="hidden" name="csrf_token"
                           value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="post_accion"
                           value="guardar_precios">
                    <input type="hidden" name="semana_id"
                           value="<?= (int)$semanaEditando['id'] ?>">

                    <div class="comb-panel__body">

                        <p style="font-size:.82rem;color:var(--text-muted);
                                   margin-bottom:20px;
                                   padding:10px 14px;
                                   background:var(--bg-surface-2);
                                   border-radius:var(--border-radius);
                                   border-left:3px solid var(--primary)">
                            <i class="bi bi-info-circle"
                               style="color:var(--primary)"></i>
                            Ingresa el precio actual de cada combustible en
                            <strong>RD$</strong>. La variación se calcula
                            automáticamente al escribir comparando con la
                            semana anterior.
                        </p>

                        <div class="precio-grid">
                        <?php foreach ($preciosEditando as $pe):
                            $subio  = !empty($pe['variacion']) && ($pe['variacion'] > 0);
                            $bajo   = !empty($pe['variacion']) && ($pe['variacion'] < 0);
                            $igual  = empty($pe['variacion']) || ($pe['variacion'] == 0);
                            $varAbs = abs((float)($pe['variacion'] ?? 0));
                            $varPct = abs((float)($pe['variacion_pct'] ?? 0));
                            $precioVal = !empty($pe['precio']) ? number_format((float)$pe['precio'], 2, '.', '') : '';
                        ?>
                        <div class="precio-card"
                             style="--pc:<?= e($pe['color']) ?>"
                             id="pcard-<?= (int)$pe['combustible_id'] ?>">
                            <div class="precio-card__head">
                                <div class="precio-card__ico"
                                     style="background:<?= e($pe['color']) ?>18;
                                            border:1px solid <?= e($pe['color']) ?>30">
                                    <i class="bi <?= e($pe['icono']) ?>"
                                       style="color:<?= e($pe['color']) ?>"></i>
                                </div>
                                <div>
                                    <div class="precio-card__name">
                                        <?= e($pe['tipo_nombre']) ?>
                                    </div>
                                    <div class="precio-card__unit">
                                        RD$ por <?= e($pe['unidad'] ?? 'galón') ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Precio anterior (referencia) -->
                            <div class="ant-display"
                                 id="ant-display-<?= (int)$pe['combustible_id'] ?>">
                                <i class="bi bi-clock-history"
                                   style="opacity:.6"></i>
                                Semana anterior:
                                <strong>
                                    <?= !empty($pe['precio_anterior'])
                                        ? 'RD$ ' . number_format((float)$pe['precio_anterior'], 2)
                                        : 'No disponible' ?>
                                </strong>
                            </div>

                            <!-- Input precio -->
                            <div class="fc-row">
                                <label class="fc-label"
                                       for="precio_<?= (int)$pe['combustible_id'] ?>">
                                    Precio actual (RD$)
                                    <span style="color:var(--primary)">*</span>
                                </label>
                                <input type="number"
                                       id="precio_<?= (int)$pe['combustible_id'] ?>"
                                       name="precios[<?= (int)$pe['combustible_id'] ?>]"
                                       class="fc-input fc-input--price"
                                       value="<?= $precioVal ?>"
                                       step="0.01" min="0.01" max="9999.99"
                                       placeholder="0.00"
                                       required
                                       data-cid="<?= (int)$pe['combustible_id'] ?>"
                                       data-anterior="<?= e((string)($pe['precio_anterior'] ?? '')) ?>"
                                       oninput="calcVariacion(this)"
                                       aria-label="Precio de <?= e($pe['tipo_nombre']) ?>">
                            </div>

                            <!-- Variación en tiempo real -->
                            <div id="var-prev-<?= (int)$pe['combustible_id'] ?>"
                                 class="precio-var-preview"
                                 style="<?= $subio
                                    ? 'background:rgba(239,68,68,.12);color:#ef4444'
                                    : ($bajo
                                        ? 'background:rgba(34,197,94,.12);color:#22c55e'
                                        : 'background:var(--bg-surface-2);color:var(--text-muted)') ?>">
                                <?php if ($subio): ?>
                                    <i class="bi bi-arrow-up-short"></i>
                                    Subió RD$ <?= number_format($varAbs, 2) ?>
                                    (+<?= number_format($varPct, 2) ?>%)
                                <?php elseif ($bajo): ?>
                                    <i class="bi bi-arrow-down-short"></i>
                                    Bajó RD$ <?= number_format($varAbs, 2) ?>
                                    (-<?= number_format($varPct, 2) ?>%)
                                <?php else: ?>
                                    <i class="bi bi-dash"></i>
                                    <?= !empty($precioVal) ? 'Sin cambio' : 'Ingresa precio' ?>
                                <?php endif; ?>
                            </div>

                            <!-- Nota -->
                            <div class="fc-row" style="margin-top:10px">
                                <label class="fc-label"
                                       for="nota_<?= (int)$pe['combustible_id'] ?>">
                                    Nota <span style="font-weight:400;color:var(--text-muted)">(opcional)</span>
                                </label>
                                <input type="text"
                                       id="nota_<?= (int)$pe['combustible_id'] ?>"
                                       name="notas[<?= (int)$pe['combustible_id'] ?>]"
                                       class="fc-input"
                                       style="font-size:.78rem"
                                       value="<?= e($pe['nota'] ?? '') ?>"
                                       maxlength="200"
                                       placeholder="Ej: Baja por precios internacionales…">
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>

                        <!-- Opciones de publicación -->
                        <div style="margin-top:24px;padding:18px;
                                     background:var(--bg-surface-2);
                                     border-radius:var(--border-radius-lg);
                                     border:1px solid var(--border-color)">
                            <div style="font-size:.82rem;font-weight:800;
                                         color:var(--text-primary);margin-bottom:14px">
                                <i class="bi bi-toggles"
                                   style="color:var(--primary)"></i>
                                Opciones de publicación
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:20px">
                                <label style="display:flex;align-items:center;gap:8px;
                                               cursor:pointer;user-select:none">
                                    <input type="checkbox"
                                           name="publicar_ahora"
                                           id="chkPublicar"
                                           <?= $semanaEditando['publicado'] ? 'checked' : '' ?>
                                           style="width:16px;height:16px;
                                                  accent-color:var(--primary);cursor:pointer">
                                    <span>
                                        <strong style="font-size:.875rem;color:var(--text-primary)">
                                            Publicar esta semana
                                        </strong>
                                        <br>
                                        <small style="color:var(--text-muted);font-size:.72rem">
                                            Hace los precios visibles en el sitio web
                                        </small>
                                    </span>
                                </label>
                                <label style="display:flex;align-items:center;gap:8px;
                                               cursor:pointer;user-select:none">
                                    <input type="checkbox"
                                           name="marcar_actual"
                                           id="chkActual"
                                           <?= $semanaEditando['es_actual'] ? 'checked' : '' ?>
                                           style="width:16px;height:16px;
                                                  accent-color:#f59e0b;cursor:pointer">
                                    <span>
                                        <strong style="font-size:.875rem;color:var(--text-primary)">
                                            Marcar como semana actual
                                        </strong>
                                        <br>
                                        <small style="color:var(--text-muted);font-size:.72rem">
                                            Muestra estos precios en el widget del sidebar
                                        </small>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <!-- Botones -->
                        <div style="margin-top:20px;display:flex;
                                     justify-content:flex-end;gap:10px;
                                     flex-wrap:wrap">
                            <a href="?tab=semanas"
                               class="btn-adm btn-adm--ghost">
                                <i class="bi bi-arrow-left"></i>
                                Cancelar
                            </a>
                            <button type="submit"
                                    id="btnGuardar"
                                    class="btn-adm btn-adm--success"
                                    style="min-width:190px">
                                <i class="bi bi-check-circle-fill"></i>
                                Guardar precios
                            </button>
                        </div>
                    </div><!-- /.comb-panel__body -->
                </form>

                <?php else: ?>
                <div class="empty-state" style="padding:50px 20px">
                    <i class="bi bi-exclamation-triangle"></i>
                    <h3>No hay precios para mostrar</h3>
                    <p>Selecciona una semana del listado superior.</p>
                </div>
                <?php endif; /* preciosEditando */?>

                <?php else: /* no semanaEditando */ ?>
                <div class="empty-state">
                    <i class="bi bi-calendar-plus"></i>
                    <h3>No hay semanas creadas</h3>
                    <p style="font-size:.875rem">
                        Crea la primera semana para registrar precios.
                    </p>
                    <button type="button"
                            onclick="abrirModal('modalNuevaSemana')"
                            class="btn-adm btn-adm--primary"
                            style="margin-top:16px">
                        <i class="bi bi-plus-circle-fill"></i>
                        Nueva Semana
                    </button>
                </div>
                <?php endif; ?>

            </div><!-- /.comb-panel precios -->
            <?php endif; ?>

            <!-- ════════════════════════════════════════════
                 TAB 3: TIPOS DE COMBUSTIBLE
            ════════════════════════════════════════════ -->
            <?php if ($tabActiva === 'tipos'): ?>
            <div class="comb-panel" role="tabpanel"
                 aria-label="Tipos de combustible">

                <div class="comb-panel__head">
                    <div class="comb-panel__title">
                        <i class="bi bi-fuel-pump"
                           style="color:#f59e0b"></i>
                        Tipos de Combustible
                        <span style="font-size:.72rem;color:var(--text-muted);font-weight:400">
                            (<?= count($listaTipos) ?> registrados)
                        </span>
                    </div>
                    <button type="button"
                            onclick="abrirModalTipo()"
                            class="btn-adm btn-adm--primary btn-adm--sm">
                        <i class="bi bi-plus-circle-fill"></i>
                        Nuevo Tipo
                    </button>
                </div>

                <?php if (empty($listaTipos)): ?>
                <div class="empty-state">
                    <i class="bi bi-fuel-pump"></i>
                    <h3>No hay tipos registrados</h3>
                    <p>Crea el primer tipo de combustible.</p>
                </div>
                <?php else: ?>
                <div class="table-wrap">
                <table class="cadm-table"
                       aria-label="Lista de tipos de combustible">
                    <thead>
                        <tr>
                            <th>Ord.</th>
                            <th>Tipo de Combustible</th>
                            <th>Color / Icono</th>
                            <th>Unidad</th>
                            <th>Semanas</th>
                            <th>Activo</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($listaTipos as $tipo):
                        $numSemTipo = (int)db()->fetchColumn(
                            "SELECT COUNT(DISTINCT semana_inicio) FROM combustibles_precios WHERE combustible_id = ?",
                            [$tipo['id']]
                        );
                    ?>
                    <tr id="tipo-row-<?= (int)$tipo['id'] ?>">
                        <td>
                            <span style="width:26px;height:26px;
                                          display:inline-flex;align-items:center;
                                          justify-content:center;background:var(--bg-surface-2);
                                          border-radius:6px;font-size:.8rem;
                                          font-weight:800;color:var(--text-muted)">
                                <?= (int)$tipo['orden'] ?>
                            </span>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px">
                                <div style="width:38px;height:38px;
                                             border-radius:10px;
                                             background:<?= e($tipo['color']) ?>18;
                                             border:1px solid <?= e($tipo['color']) ?>30;
                                             display:flex;align-items:center;
                                             justify-content:center;flex-shrink:0">
                                    <i class="bi <?= e($tipo['icono']) ?>"
                                       style="color:<?= e($tipo['color']) ?>;font-size:.95rem"></i>
                                </div>
                                <div>
                                    <div style="font-weight:700;color:var(--text-primary);
                                                 font-size:.875rem">
                                        <?= e($tipo['nombre']) ?>
                                    </div>
                                    <div style="font-size:.68rem;color:var(--text-muted);
                                                 font-family:monospace">
                                        <?= e($tipo['slug']) ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px">
                                <span class="color-circle"
                                      style="background:<?= e($tipo['color']) ?>"></span>
                                <div>
                                    <code style="font-size:.72rem;color:var(--text-muted)">
                                        <?= e($tipo['color']) ?>
                                    </code>
                                    <div style="font-size:.65rem;color:var(--text-muted);
                                                 font-family:monospace">
                                        <?= e($tipo['icono']) ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge-adm badge-adm--muted">
                                <?= e($tipo['unidad']) ?>
                            </span>
                        </td>
                        <td>
                            <span style="font-weight:700;color:var(--text-secondary);
                                          font-size:.82rem">
                                <?= $numSemTipo ?>
                            </span>
                            <span style="font-size:.7rem;color:var(--text-muted)">
                                semanas
                            </span>
                        </td>
                        <td>
                            <label class="toggle-sw">
                                <input type="checkbox"
                                       <?= $tipo['activo'] ? 'checked' : '' ?>
                                       onchange="toggleTipo(<?= (int)$tipo['id'] ?>, this)"
                                       aria-label="Activar/desactivar tipo">
                                <span class="toggle-slider"></span>
                            </label>
                        </td>
                        <td>
                            <div style="display:flex;gap:6px">
                                <button type="button"
                                        onclick="editarTipo(<?= htmlspecialchars(json_encode($tipo, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"
                                        class="btn-adm btn-adm--ghost btn-adm--sm"
                                        title="Editar tipo">
                                    <i class="bi bi-pencil-fill"
                                       style="color:var(--primary)"></i>
                                </button>
                                <?php if ($numSemTipo === 0): ?>
                                <button type="button"
                                        onclick="eliminarTipo(<?= (int)$tipo['id'] ?>,
                                                '<?= e(addslashes($tipo['nombre'])) ?>')"
                                        class="btn-adm btn-adm--danger btn-adm--sm"
                                        title="Eliminar tipo">
                                    <i class="bi bi-trash3-fill"></i>
                                </button>
                                <?php else: ?>
                                <button class="btn-adm btn-adm--ghost btn-adm--sm"
                                        disabled
                                        style="opacity:.35;cursor:not-allowed"
                                        title="Tiene <?= $numSemTipo ?> semanas de precios. Solo puedes desactivarlo.">
                                    <i class="bi bi-lock-fill"
                                       style="font-size:.7rem"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <div style="padding:12px 18px;
                             border-top:1px solid var(--border-color);
                             background:var(--bg-surface-2);
                             font-size:.75rem;color:var(--text-muted)">
                    <i class="bi bi-lock-fill"></i>
                    Los tipos con historial de precios no se pueden eliminar, solo desactivar.
                </div>
                <?php endif; ?>

            </div><!-- /.comb-panel tipos -->
            <?php endif; ?>

        </main><!-- /.admin-main -->
    </div><!-- /.admin-content -->
</div><!-- /.admin-wrapper -->

<!-- ============================================================
     MODAL: NUEVA SEMANA
     ============================================================ -->
<div class="cadm-overlay"
     id="modalNuevaSemana"
     role="dialog"
     aria-modal="true"
     aria-labelledby="titleNuevaSemana"
     onclick="if(event.target===this) cerrarModal('modalNuevaSemana')">
    <div class="cadm-modal">
        <div class="cadm-modal__head">
            <div class="cadm-modal__title">
                <i class="bi bi-calendar-plus-fill"
                   style="color:var(--primary)"></i>
                <span id="titleNuevaSemana">Nueva Semana de Precios</span>
            </div>
            <button type="button"
                    class="cadm-modal__close"
                    onclick="cerrarModal('modalNuevaSemana')"
                    aria-label="Cerrar modal">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="cadm-modal__body">
            <form method="POST"
                  action="<?= APP_URL ?>/admin/combustibles.php"
                  id="formNuevaSemana"
                  novalidate>
                <input type="hidden" name="csrf_token"
                       value="<?= e($csrfToken) ?>">
                <input type="hidden" name="post_accion"
                       value="crear_semana">
                <input type="hidden" name="tab_redirect"
                       value="semanas">

                <!-- Título -->
                <div class="fc-row">
                    <label class="fc-label" for="ns_titulo">
                        Título
                        <span style="font-weight:400;color:var(--text-muted)">
                            (se genera automáticamente si está vacío)
                        </span>
                    </label>
                    <input type="text"
                           id="ns_titulo"
                           name="titulo"
                           class="fc-input"
                           maxlength="200"
                           placeholder="Ej: Precios semana del 12 al 18 de Mayo 2026">
                </div>

                <!-- Fecha de vigencia -->
                <div class="fc-row">
                    <label class="fc-label" for="ns_fecha_vigencia">
                        Fecha de vigencia (viernes)
                        <span style="color:var(--primary)">*</span>
                    </label>
                    <input type="date"
                           id="ns_fecha_vigencia"
                           name="fecha_vigencia"
                           class="fc-input"
                           value="<?= e($proximoViernes) ?>"
                           required
                           oninput="calcSemana(this.value)">
                    <span class="fc-help">
                        <i class="bi bi-info-circle"></i>
                        Al seleccionar el viernes, el lunes y domingo de la semana
                        se calculan automáticamente.
                    </span>
                </div>

                <!-- Inicio / Fin semana -->
                <div class="fc-grid-2 fc-row">
                    <div>
                        <label class="fc-label" for="ns_semana_inicio">
                            Inicio semana (lunes) *
                        </label>
                        <input type="date"
                               id="ns_semana_inicio"
                               name="semana_inicio"
                               class="fc-input"
                               value="<?= e($semanasPV['inicio']) ?>"
                               required>
                    </div>
                    <div>
                        <label class="fc-label" for="ns_semana_fin">
                            Fin semana (domingo) *
                        </label>
                        <input type="date"
                               id="ns_semana_fin"
                               name="semana_fin"
                               class="fc-input"
                               value="<?= e($semanasPV['fin']) ?>"
                               required>
                    </div>
                </div>

                <!-- Fuente -->
                <div class="fc-row">
                    <label class="fc-label" for="ns_fuente">
                        Fuente oficial
                    </label>
                    <input type="text"
                           id="ns_fuente"
                           name="fuente"
                           class="fc-input"
                           value="Ministerio de Industria, Comercio y Mipymes"
                           maxlength="200">
                </div>

                <!-- Nota -->
                <div class="fc-row">
                    <label class="fc-label" for="ns_nota">
                        Nota semanal
                        <span style="font-weight:400;color:var(--text-muted)">(opcional)</span>
                    </label>
                    <textarea id="ns_nota"
                              name="nota_semanal"
                              class="fc-textarea"
                              rows="2"
                              maxlength="500"
                              placeholder="Ej: Reducción por baja en precios internacionales del petróleo..."></textarea>
                </div>

                <!-- Opciones -->
                <div style="padding:14px;
                             background:var(--bg-surface-2);
                             border-radius:var(--border-radius);
                             border:1px solid var(--border-color)">
                    <div style="font-size:.75rem;font-weight:700;
                                 color:var(--text-muted);text-transform:uppercase;
                                 letter-spacing:.06em;margin-bottom:12px">
                        Opciones de publicación
                    </div>
                    <div style="display:flex;flex-direction:column;gap:12px">
                        <label style="display:flex;align-items:flex-start;
                                       gap:10px;cursor:pointer">
                            <input type="checkbox"
                                   name="publicado"
                                   id="ns_publicado"
                                   style="width:16px;height:16px;
                                          accent-color:var(--primary);
                                          cursor:pointer;margin-top:2px">
                            <span>
                                <strong style="font-size:.875rem;color:var(--text-primary)">
                                    Publicar inmediatamente
                                </strong>
                                <br>
                                <small style="color:var(--text-muted);font-size:.72rem">
                                    Los precios serán visibles en el sitio al crear la semana
                                </small>
                            </span>
                        </label>
                        <label style="display:flex;align-items:flex-start;
                                       gap:10px;cursor:pointer">
                            <input type="checkbox"
                                   name="marcar_actual"
                                   id="ns_marcar_actual"
                                   style="width:16px;height:16px;
                                          accent-color:#f59e0b;
                                          cursor:pointer;margin-top:2px">
                            <span>
                                <strong style="font-size:.875rem;color:var(--text-primary)">
                                    Marcar como semana actual
                                </strong>
                                <br>
                                <small style="color:var(--text-muted);font-size:.72rem">
                                    Aparecerá en el widget del sidebar del sitio web
                                </small>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Aviso auto-precios -->
                <div style="margin-top:12px;
                             padding:10px 13px;
                             background:rgba(13,110,253,.07);
                             border:1px solid rgba(13,110,253,.15);
                             border-radius:var(--border-radius);
                             font-size:.78rem;
                             color:var(--text-secondary)">
                    <i class="bi bi-lightbulb-fill"
                       style="color:#f59e0b"></i>
                    Se generarán automáticamente
                    <strong><?= count($listaTipos) ?> registros de precio</strong>
                    copiando los valores de la semana anterior como punto de partida.
                    Podrás editarlos después.
                </div>

            </form>
        </div>
        <div class="cadm-modal__foot">
            <button type="button"
                    onclick="cerrarModal('modalNuevaSemana')"
                    class="btn-adm btn-adm--ghost">
                <i class="bi bi-x-circle"></i>
                Cancelar
            </button>
            <button type="button"
                    id="btnCrearSemana"
                    onclick="submitNuevaSemana()"
                    class="btn-adm btn-adm--primary">
                <i class="bi bi-calendar-check-fill"></i>
                Crear Semana
            </button>
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL: TIPO DE COMBUSTIBLE (Crear / Editar)
     ============================================================ -->
<div class="cadm-overlay"
     id="modalTipo"
     role="dialog"
     aria-modal="true"
     aria-labelledby="titleModalTipo"
     onclick="if(event.target===this) cerrarModal('modalTipo')">
    <div class="cadm-modal">
        <div class="cadm-modal__head">
            <div class="cadm-modal__title">
                <i class="bi bi-fuel-pump"
                   style="color:#f59e0b"></i>
                <span id="titleModalTipo">Nuevo Tipo de Combustible</span>
            </div>
            <button type="button"
                    class="cadm-modal__close"
                    onclick="cerrarModal('modalTipo')"
                    aria-label="Cerrar modal">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="cadm-modal__body">
            <form method="POST"
                  action="<?= APP_URL ?>/admin/combustibles.php?tab=tipos"
                  id="formTipo"
                  novalidate>
                <input type="hidden" name="csrf_token"
                       value="<?= e($csrfToken) ?>">
                <input type="hidden" name="post_accion"
                       id="tipo_accion" value="crear_tipo">
                <input type="hidden" name="tipo_id"
                       id="tipo_id_hidden" value="0">

                <!-- Nombre -->
                <div class="fc-row">
                    <label class="fc-label" for="t_nombre">
                        Nombre <span style="color:var(--primary)">*</span>
                    </label>
                    <input type="text"
                           id="t_nombre"
                           name="nombre"
                           class="fc-input"
                           maxlength="100"
                           placeholder="Ej: Gasolina Premium"
                           required
                           oninput="autoSlugTipo(this.value)">
                </div>

                <!-- Slug -->
                <div class="fc-row">
                    <label class="fc-label" for="t_slug">
                        Slug (URL) <span style="color:var(--primary)">*</span>
                    </label>
                    <input type="text"
                           id="t_slug"
                           name="slug"
                           class="fc-input"
                           maxlength="120"
                           placeholder="gasolina-premium"
                           style="font-family:monospace;font-size:.82rem"
                           required>
                    <span class="fc-help">
                        Solo letras, números y guiones. Se auto-genera al escribir el nombre.
                    </span>
                </div>

                <!-- Icono + Color -->
                <div class="fc-grid-2 fc-row">
                    <div>
                        <label class="fc-label" for="t_icono">
                            Clase Bootstrap Icons
                        </label>
                        <input type="text"
                               id="t_icono"
                               name="icono"
                               class="fc-input"
                               value="bi-fuel-pump"
                               maxlength="50"
                               placeholder="bi-fuel-pump"
                               oninput="prevIcono(this.value)">
                        <div style="display:flex;align-items:center;
                                     gap:8px;margin-top:8px">
                            <i id="iconPreview"
                               class="bi bi-fuel-pump"
                               style="font-size:1.4rem;color:var(--primary)"></i>
                            <span style="font-size:.7rem;color:var(--text-muted)">
                                Vista previa del ícono
                            </span>
                        </div>
                    </div>
                    <div>
                        <label class="fc-label" for="t_color">
                            Color (HEX)
                        </label>
                        <div style="display:flex;align-items:center;gap:8px">
                            <input type="color"
                                   id="t_colorpicker"
                                   value="#e63946"
                                   style="width:38px;height:38px;
                                          border:none;padding:0;
                                          border-radius:50%;cursor:pointer;
                                          background:none"
                                   oninput="document.getElementById('t_color').value=this.value">
                            <input type="text"
                                   id="t_color"
                                   name="color"
                                   class="fc-input"
                                   value="#e63946"
                                   maxlength="7"
                                   placeholder="#e63946"
                                   style="font-family:monospace;font-size:.82rem"
                                   oninput="document.getElementById('t_colorpicker').value=this.value">
                        </div>
                    </div>
                </div>

                <!-- Unidad + Orden -->
                <div class="fc-grid-2 fc-row">
                    <div>
                        <label class="fc-label" for="t_unidad">Unidad</label>
                        <select id="t_unidad" name="unidad" class="fc-select">
                            <option value="galón">galón</option>
                            <option value="m³">m³</option>
                        </select>
                    </div>
                    <div>
                        <label class="fc-label" for="t_orden">Orden</label>
                        <input type="number"
                               id="t_orden"
                               name="orden"
                               class="fc-input"
                               value="<?= count($listaTipos) + 1 ?>"
                               min="0" max="99">
                    </div>
                </div>

                <!-- Descripción -->
                <div class="fc-row">
                    <label class="fc-label" for="t_descripcion">
                        Descripción
                        <span style="font-weight:400;color:var(--text-muted)">(opcional)</span>
                    </label>
                    <textarea id="t_descripcion"
                              name="descripcion"
                              class="fc-textarea"
                              rows="2"
                              maxlength="400"
                              placeholder="Breve descripción del combustible..."></textarea>
                </div>

                <!-- Activo -->
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox"
                           id="t_activo"
                           name="activo"
                           checked
                           style="width:16px;height:16px;
                                  accent-color:var(--primary)">
                    <span>
                        <strong style="color:var(--text-primary);font-size:.875rem">
                            Activo
                        </strong>
                        <small style="color:var(--text-muted);font-size:.72rem">
                            — Solo los activos aparecen en el sitio
                        </small>
                    </span>
                </label>

            </form>
        </div>
        <div class="cadm-modal__foot">
            <button type="button"
                    onclick="cerrarModal('modalTipo')"
                    class="btn-adm btn-adm--ghost">
                <i class="bi bi-x-circle"></i>
                Cancelar
            </button>
            <button type="button"
                    onclick="document.getElementById('formTipo').submit()"
                    id="btnTipoSubmit"
                    class="btn-adm btn-adm--primary">
                <i class="bi bi-check-circle-fill"></i>
                <span id="btnTipoLabel">Crear Tipo</span>
            </button>
        </div>
    </div>
</div>

<!-- ============================================================
     SCRIPTS
     ============================================================ -->
<script>
(function () {
    'use strict';

    // ── Sidebar toggle móvil (usa el overlay de sidebar.php) ──
    const sidebarEl  = document.querySelector('.admin-sidebar');
    const overlayEl  = document.getElementById('adminOverlay');
    const toggleBtn  = document.getElementById('sidebarToggle');

    function openSidebar () {
        if (sidebarEl)  sidebarEl.classList.add('open');
        if (overlayEl)  overlayEl.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeSidebar () {
        if (sidebarEl)  sidebarEl.classList.remove('open');
        if (overlayEl)  overlayEl.classList.remove('open');
        document.body.style.overflow = '';
    }
    if (toggleBtn)  toggleBtn.addEventListener('click', openSidebar);
    if (overlayEl)  overlayEl.addEventListener('click', closeSidebar);

    // ── Modales ───────────────────────────────────────────────
    window.abrirModal = function (id) {
        const el = document.getElementById(id);
        if (el) { el.classList.add('open'); document.body.style.overflow = 'hidden'; }
    };
    window.cerrarModal = function (id) {
        const el = document.getElementById(id);
        if (el) { el.classList.remove('open'); document.body.style.overflow = ''; }
    };
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.cadm-overlay.open')
                    .forEach(m => { m.classList.remove('open'); });
            document.body.style.overflow = '';
        }
    });

    // ── Toast notification ────────────────────────────────────
    window.showToast = function (msg, type = 'info', dur = 3200) {
        let cont = document.getElementById('_toast_cont');
        if (!cont) {
            cont = document.createElement('div');
            cont.id = '_toast_cont';
            cont.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;' +
                'display:flex;flex-direction:column;gap:8px;max-width:320px';
            document.body.appendChild(cont);
        }
        const colors = { success:'#22c55e', error:'#ef4444', warning:'#f59e0b', info:'#0d6efd' };
        const icons  = {
            success:'bi-check-circle-fill', error:'bi-exclamation-circle-fill',
            warning:'bi-exclamation-triangle-fill', info:'bi-info-circle-fill'
        };
        const t = document.createElement('div');
        t.style.cssText = 'background:var(--bg-surface);border:1px solid var(--border-color);' +
            `border-left:4px solid ${colors[type]||colors.info};border-radius:10px;` +
            'padding:11px 15px;display:flex;align-items:center;gap:10px;' +
            'font-size:.82rem;color:var(--text-primary);' +
            'box-shadow:0 4px 20px rgba(0,0,0,.15);' +
            'animation:toastIn .22s ease';
        t.innerHTML = `<i class="bi ${icons[type]||icons.info}" ` +
            `style="color:${colors[type]||colors.info};font-size:.95rem;flex-shrink:0"></i>` +
            `<span style="flex:1">${msg}</span>`;
        cont.appendChild(t);
        setTimeout(() => {
            t.style.transition = '.3s ease';
            t.style.opacity    = '0';
            t.style.transform  = 'translateX(10px)';
            setTimeout(() => t.remove(), 300);
        }, dur);
    };

    // ── POST AJAX helper ──────────────────────────────────────
    async function postAjax (data) {
        const fd = new FormData();
        fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
        for (const [k, v] of Object.entries(data)) fd.append(k, v);
        const r = await fetch(window.location.pathname, {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        return r.json();
    }

    // ── Animación contador stats ──────────────────────────────
    document.querySelectorAll('.comb-stat__val').forEach(el => {
        const target = parseInt(el.textContent.trim()) || 0;
        if (target <= 0) return;
        let cur = 0;
        const step = Math.max(1, Math.ceil(target / 18));
        const t = setInterval(() => {
            cur = Math.min(cur + step, target);
            el.textContent = cur;
            if (cur >= target) clearInterval(t);
        }, 38);
    });

    // ── Nueva semana: calcular lunes/domingo al seleccionar viernes ──
    window.calcSemana = function (viernes) {
        if (!viernes) return;
        const dt  = new Date(viernes + 'T00:00:00');
        const dow = dt.getDay(); // 0=Dom 5=Vie
        const diffLunes = (dow === 0) ? -6 : (1 - dow);
        const lunes   = new Date(dt);
        lunes.setDate(dt.getDate() + diffLunes);
        const domingo = new Date(lunes);
        domingo.setDate(lunes.getDate() + 6);
        const fmt = d => d.toISOString().slice(0, 10);
        document.getElementById('ns_semana_inicio').value = fmt(lunes);
        document.getElementById('ns_semana_fin').value    = fmt(domingo);
    };

    // ── Submit nueva semana con validación ────────────────────
    window.submitNuevaSemana = function () {
        const form = document.getElementById('formNuevaSemana');
        if (!form) return;
        // Validación manual de campos requeridos
        const vigencia = document.getElementById('ns_fecha_vigencia').value;
        const inicio   = document.getElementById('ns_semana_inicio').value;
        const fin      = document.getElementById('ns_semana_fin').value;
        if (!vigencia) {
            showToast('La fecha de vigencia es requerida.', 'error');
            document.getElementById('ns_fecha_vigencia').focus();
            return;
        }
        if (!inicio) {
            showToast('La fecha de inicio de semana es requerida.', 'error');
            document.getElementById('ns_semana_inicio').focus();
            return;
        }
        if (!fin) {
            showToast('La fecha de fin de semana es requerida.', 'error');
            document.getElementById('ns_semana_fin').focus();
            return;
        }
        const btn = document.getElementById('btnCrearSemana');
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Creando semana...';
        form.submit();
    };

    // ── Toggle semana (publicado / es_actual) via AJAX ────────
    window.toggleSemana = async function (id, campo, checkbox) {
        try {
            const r = await postAjax({ post_accion: 'toggle_semana', semana_id: id, campo });
            if (r.success) {
                if (campo === 'publicado') {
                    const lbl = document.getElementById(`pub-lbl-${id}`);
                    if (lbl) {
                        lbl.className = `badge-adm badge-adm--${r.nuevoValor ? 'pub' : 'draft'}`;
                        lbl.textContent = r.nuevoValor ? 'Publicada' : 'Borrador';
                    }
                }
                showToast(r.message || 'Actualizado.', 'success', 2000);
            } else {
                checkbox.checked = !checkbox.checked;
                showToast(r.message || 'Error al actualizar.', 'error');
            }
        } catch {
            checkbox.checked = !checkbox.checked;
            showToast('Error de conexión.', 'error');
        }
    };

    window.marcarActual = async function (id) {
        const btn = document.getElementById(`btn-act-${id}`);
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i>'; }
        try {
            const r = await postAjax({ post_accion: 'toggle_semana', semana_id: id, campo: 'es_actual' });
            if (r.success) {
                showToast('Semana marcada como actual ⭐', 'success');
                setTimeout(() => location.reload(), 1400);
            } else {
                showToast(r.message || 'Error.', 'error');
                if (btn) { btn.disabled = false;
                           btn.innerHTML = '<i class="bi bi-star"></i> Marcar'; }
            }
        } catch {
            showToast('Error de conexión.', 'error');
            if (btn) { btn.disabled = false;
                       btn.innerHTML = '<i class="bi bi-star"></i> Marcar'; }
        }
    };

    // ── Eliminar semana ───────────────────────────────────────
    window.eliminarSemana = function (id, titulo) {
        if (!confirm(`¿Eliminar la semana "${titulo}"?\n\n` +
                     'Esta acción eliminará TODOS los precios de esa semana.\n' +
                     'Esta operación NO se puede deshacer.')) return;
        const f = document.createElement('form');
        f.method = 'POST';
        f.action = '<?= APP_URL ?>/admin/combustibles.php';
        const campos = {
            csrf_token:  <?= json_encode($csrfToken) ?>,
            post_accion: 'eliminar_semana',
            semana_id:   String(id),
        };
        for (const [k, v] of Object.entries(campos)) {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = k; inp.value = v;
            f.appendChild(inp);
        }
        document.body.appendChild(f);
        f.submit();
    };

    // ── Calcular variación en tiempo real ────────────────────
    window.calcVariacion = function (input) {
        const cid      = input.getAttribute('data-cid');
        const nuevo    = parseFloat(input.value) || 0;
        const anterior = parseFloat(input.getAttribute('data-anterior')) || 0;
        const prevEl   = document.getElementById(`var-prev-${cid}`);
        if (!prevEl) return;

        if (nuevo <= 0) {
            prevEl.innerHTML = '<i class="bi bi-dash"></i> Ingresa el precio';
            prevEl.style.cssText = 'background:var(--bg-surface-2);color:var(--text-muted)';
            return;
        }
        if (!anterior) {
            prevEl.innerHTML = '<i class="bi bi-info-circle"></i> Sin semana anterior';
            prevEl.style.cssText = 'background:var(--bg-surface-2);color:var(--text-muted)';
            return;
        }

        const diff    = nuevo - anterior;
        const pct     = (diff / anterior) * 100;
        const absDiff = Math.abs(diff).toFixed(2);
        const absPct  = Math.abs(pct).toFixed(2);

        if (diff > 0.005) {
            prevEl.innerHTML = `<i class="bi bi-arrow-up-short"></i> Subió RD$ ${absDiff} (+${absPct}%)`;
            prevEl.style.cssText = 'background:rgba(239,68,68,.12);color:#ef4444';
        } else if (diff < -0.005) {
            prevEl.innerHTML = `<i class="bi bi-arrow-down-short"></i> Bajó RD$ ${absDiff} (-${absPct}%)`;
            prevEl.style.cssText = 'background:rgba(34,197,94,.12);color:#22c55e';
        } else {
            prevEl.innerHTML = '<i class="bi bi-dash"></i> Sin cambio';
            prevEl.style.cssText = 'background:var(--bg-surface-2);color:var(--text-muted)';
        }
    };

    window.recalcularTodas = function () {
        document.querySelectorAll('[data-cid]').forEach(input => {
            if (input.value) calcVariacion(input);
        });
        showToast('Variaciones recalculadas.', 'info', 1800);
    };

    // Submit form precios con loading
    const formPre = document.getElementById('formPrecios');
    if (formPre) {
        formPre.addEventListener('submit', function () {
            const btn = document.getElementById('btnGuardar');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Guardando precios...';
            }
        });
    }

    // ── Toggle activo de tipo ─────────────────────────────────
    window.toggleTipo = async function (id, checkbox) {
        try {
            const r = await postAjax({ post_accion: 'toggle_tipo', tipo_id: id });
            if (r.success) {
                showToast('Estado del tipo actualizado.', 'success', 1800);
            } else {
                checkbox.checked = !checkbox.checked;
                showToast(r.message || 'Error.', 'error');
            }
        } catch {
            checkbox.checked = !checkbox.checked;
            showToast('Error de conexión.', 'error');
        }
    };

    // ── Eliminar tipo ─────────────────────────────────────────
    window.eliminarTipo = async function (id, nombre) {
        if (!confirm(`¿Eliminar el tipo "${nombre}"?\nEsta acción es irreversible.`)) return;
        try {
            const r = await postAjax({ post_accion: 'eliminar_tipo', tipo_id: id });
            if (r.success) {
                showToast(`Tipo "${nombre}" eliminado.`, 'success');
                const row = document.getElementById(`tipo-row-${id}`);
                if (row) row.style.opacity = '0';
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast(r.message || 'No se pudo eliminar.', 'error');
            }
        } catch {
            showToast('Error de conexión.', 'error');
        }
    };

    // ── Modal de tipos: Nuevo ─────────────────────────────────
    window.abrirModalTipo = function () {
        document.getElementById('titleModalTipo').textContent = 'Nuevo Tipo de Combustible';
        document.getElementById('btnTipoLabel').textContent   = 'Crear Tipo';
        document.getElementById('tipo_accion').value          = 'crear_tipo';
        document.getElementById('tipo_id_hidden').value       = '0';
        document.getElementById('t_nombre').value      = '';
        document.getElementById('t_slug').value        = '';
        document.getElementById('t_icono').value       = 'bi-fuel-pump';
        document.getElementById('t_color').value       = '#e63946';
        document.getElementById('t_colorpicker').value = '#e63946';
        document.getElementById('t_unidad').value      = 'galón';
        document.getElementById('t_orden').value       = '<?= count($listaTipos) + 1 ?>';
        document.getElementById('t_descripcion').value = '';
        document.getElementById('t_activo').checked    = true;
        document.getElementById('iconPreview').className = 'bi bi-fuel-pump';
        abrirModal('modalTipo');
    };

    // ── Modal de tipos: Editar ────────────────────────────────
    window.editarTipo = function (tipo) {
        document.getElementById('titleModalTipo').textContent = 'Editar: ' + tipo.nombre;
        document.getElementById('btnTipoLabel').textContent   = 'Guardar cambios';
        document.getElementById('tipo_accion').value          = 'editar_tipo';
        document.getElementById('tipo_id_hidden').value       = tipo.id;
        document.getElementById('t_nombre').value      = tipo.nombre || '';
        document.getElementById('t_slug').value        = tipo.slug   || '';
        document.getElementById('t_icono').value       = tipo.icono  || 'bi-fuel-pump';
        document.getElementById('t_color').value       = tipo.color  || '#e63946';
        document.getElementById('t_colorpicker').value = tipo.color  || '#e63946';
        document.getElementById('t_unidad').value      = tipo.unidad || 'galón';
        document.getElementById('t_orden').value       = tipo.orden  ?? 0;
        document.getElementById('t_descripcion').value = tipo.descripcion || '';
        document.getElementById('t_activo').checked    = (tipo.activo == 1);
        document.getElementById('iconPreview').className = 'bi ' + (tipo.icono || 'bi-fuel-pump');
        abrirModal('modalTipo');
    };

    window.prevIcono = function (val) {
        const el = document.getElementById('iconPreview');
        if (el) el.className = 'bi ' + val.trim();
    };

    window.autoSlugTipo = function (nombre) {
        const slugEl = document.getElementById('t_slug');
        if (!slugEl) return;
        let s = nombre.toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s-]/g, '')
            .trim()
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-');
        slugEl.value = s;
    };

    // ── Estilos de animación toast ────────────────────────────
    const styleEl = document.createElement('style');
    styleEl.textContent = `
        @keyframes toastIn {
            from { opacity:0; transform:translateX(15px); }
            to   { opacity:1; transform:translateX(0); }
        }
    `;
    document.head.appendChild(styleEl);

})();
</script>

</body>
</html>