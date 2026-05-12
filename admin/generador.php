<?php
/**
 * ============================================================
 * El Click RD — Admin: Generador de Noticias v2.1
 * ============================================================
 * Archivo  : admin/generador.php
 * Versión  : 2.1.0
 * Acceso   : super_admin, admin
 * FIXES    : CSRF via session, contenido sin límite,
 *            layout overflow, diseño responsivo
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$usuario = currentUser();

// ── CSRF via session (robusto, sin depender del objeto Auth) ─
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['_gen_csrf'])) {
    $_SESSION['_gen_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['_gen_csrf'];

function genVerifyCSRF(string $token): bool {
    return !empty($token)
        && isset($_SESSION['_gen_csrf'])
        && hash_equals($_SESSION['_gen_csrf'], $token);
}

function genHasRole(string $role): bool {
    global $usuario;
    return ($usuario['rol'] ?? '') === $role;
}

// ============================================================
// AJAX HANDLERS
// ============================================================
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    !empty($_GET['ajax']) || !empty($_POST['ajax'])) {

    header('Content-Type: application/json; charset=utf-8');
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = trim($input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '');

    // ── Verificar CSRF en POST ───────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $input['csrf'] ?? $_POST['csrf_token'] ?? '';
        if (!genVerifyCSRF($token)) {
            echo json_encode(['success' => false,
                              'message' => 'Token de seguridad inválido.']);
            exit;
        }
    }

    switch ($action) {

        // ════════════════════════════════════════════════════
        // FUENTES
        // ════════════════════════════════════════════════════

        case 'fuentes_listar':
            $fuentes = db()->fetchAll(
                "SELECT f.*, c.nombre AS cat_nombre
                 FROM fuentes f
                 LEFT JOIN categorias c ON c.id = f.categoria_id
                 ORDER BY f.fecha_creacion DESC"
            );
            echo json_encode(['success' => true, 'fuentes' => $fuentes]);
            exit;

        case 'fuente_guardar':
            $id      = (int)($input['id'] ?? 0);
            $nombre  = trim($input['nombre'] ?? '');
            $url     = trim($input['url'] ?? '');
            $tipo    = in_array($input['tipo'] ?? '', ['rss','atom','web'])
                       ? $input['tipo'] : 'rss';
            $pais    = trim($input['pais'] ?? 'RD');
            $catId   = (int)($input['categoria_id'] ?? 1);
            $autorId = (int)($input['autor_id'] ?? 1);
            $activa  = (int)($input['activa'] ?? 1);

            if (empty($nombre) || empty($url)) {
                echo json_encode(['success' => false,
                                  'message' => 'Nombre y URL son obligatorios.']);
                exit;
            }
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                echo json_encode(['success' => false, 'message' => 'URL no válida.']);
                exit;
            }

            if ($id > 0) {
                $fuente = db()->fetchOne(
                    "SELECT locked FROM fuentes WHERE id=?", [$id]
                );
                if ($fuente && $fuente['locked'] && !genHasRole('super_admin')) {
                    echo json_encode(['success' => false,
                                      'message' => 'No puedes editar fuentes del sistema.']);
                    exit;
                }
                db()->execute(
                    "UPDATE fuentes SET nombre=?,url=?,tipo=?,pais=?,
                     categoria_id=?,autor_id=?,activa=?,fecha_update=NOW()
                     WHERE id=?",
                    [$nombre,$url,$tipo,$pais,$catId,$autorId,$activa,$id]
                );
                $msg = 'Fuente actualizada correctamente.';
                logActividad((int)$usuario['id'],'edit_fuente_gen',
                             'generador',$id,"Editó fuente: $nombre");
            } else {
                $newId = db()->insert(
                    "INSERT INTO fuentes
                        (nombre,url,tipo,pais,categoria_id,autor_id,activa,locked)
                     VALUES (?,?,?,?,?,?,?,0)",
                    [$nombre,$url,$tipo,$pais,$catId,$autorId,$activa]
                );
                $id  = $newId;
                $msg = 'Fuente agregada correctamente.';
                logActividad((int)$usuario['id'],'add_fuente_gen',
                             'generador',$id,"Agregó fuente: $nombre");
            }
            echo json_encode(['success' => true, 'message' => $msg, 'id' => $id]);
            exit;

        case 'fuente_eliminar':
            $id = (int)($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit;
            }
            $fuente = db()->fetchOne(
                "SELECT locked, nombre FROM fuentes WHERE id=?", [$id]
            );
            if (!$fuente) {
                echo json_encode(['success'=>false,'message'=>'Fuente no encontrada.']);
                exit;
            }
            if ($fuente['locked'] && !genHasRole('super_admin')) {
                echo json_encode(['success'=>false,
                                  'message'=>'Solo un super admin puede eliminar fuentes del sistema.']);
                exit;
            }
            db()->execute("DELETE FROM fuentes WHERE id=?", [$id]);
            logActividad((int)$usuario['id'],'delete_fuente_gen','generador',$id,
                         "Eliminó fuente: ".$fuente['nombre']);
            echo json_encode(['success'=>true,'message'=>'Fuente eliminada.']);
            exit;

        case 'fuente_toggle':
            $id = (int)($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit;
            }
            db()->execute("UPDATE fuentes SET activa = NOT activa WHERE id=?", [$id]);
            $nuevo = (int)db()->fetchColumn(
                "SELECT activa FROM fuentes WHERE id=?", [$id]
            );
            echo json_encode(['success'=>true,'activa'=>$nuevo]);
            exit;

        case 'fuente_test':
            $url  = trim($input['url'] ?? '');
            $tipo = trim($input['tipo'] ?? 'rss');
            if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
                echo json_encode(['success'=>false,'message'=>'URL inválida.']);
                exit;
            }
            $resultado = generadorFetchUrl($url, $tipo, 5);
            echo json_encode($resultado);
            exit;

        // ════════════════════════════════════════════════════
        // RECOPILACIÓN SERVER-SIDE
        // ════════════════════════════════════════════════════

        case 'recopilar':
            $fuenteIds  = array_map('intval', $input['fuente_ids'] ?? []);
            $horasRango = (int)($input['horas'] ?? 24);
            $maxPorFeed = min((int)($input['max_por_feed'] ?? 30), 100);
            $soloImagen = $input['solo_imagen'] ?? 'any';
            $idiomaFilt = $input['idioma'] ?? 'es';
            $incluirKw  = trim($input['incluir_kw'] ?? '');
            $excluirKw  = trim($input['excluir_kw'] ?? '');
            $autoLimpiar= (bool)($input['auto_clean'] ?? false);

            if (empty($fuenteIds)) {
                echo json_encode(['success'=>false,
                                  'message'=>'Selecciona al menos una fuente.']);
                exit;
            }

            if ($autoLimpiar) {
                db()->execute(
                    "DELETE FROM noticiasgeneradas
                     WHERE estado='pendiente' AND usuario_id=?",
                    [(int)$usuario['id']]
                );
            }

            $fuentes = db()->fetchAll(
                "SELECT * FROM fuentes WHERE id IN (" .
                implode(',', array_fill(0, count($fuenteIds), '?')) .
                ") AND activa=1",
                $fuenteIds
            );

            if (empty($fuentes)) {
                echo json_encode(['success'=>false,
                                  'message'=>'Ninguna fuente activa encontrada.']);
                exit;
            }

            $fechaLimite = date('Y-m-d H:i:s', strtotime("-{$horasRango} hours"));
            $kwIncluir   = array_filter(array_map('trim', explode(',', $incluirKw)));
            $kwExcluir   = array_filter(array_map('trim', explode(',', $excluirKw)));
            $totalInsert = 0;
            $totalDuplic = 0;
            $log         = [];

            $dedupe   = (bool)Config::get('generador_dedupe', '1');
            $catDef   = (int)Config::get('generador_categoria_defecto', 1);
            $autorDef = (int)Config::get('generador_autor_defecto', 1);

            foreach ($fuentes as $fuente) {
                $resultado = generadorFetchUrl($fuente['url'], $fuente['tipo'], 30);

                if (!$resultado['success']) {
                    $log[] = ['fuente' => $fuente['nombre'], 'status' => 'error',
                              'msg'    => $resultado['message'], 'count' => 0];
                    continue;
                }

                $items      = $resultado['items'] ?? [];
                $insertar   = 0;
                $duplicados = 0;

                foreach ($items as $item) {
                    if ($insertar >= $maxPorFeed) break;

                    if (!empty($item['fecha_pub']) && $item['fecha_pub'] < $fechaLimite)
                        continue;
                    if ($soloImagen === 'yes' && empty($item['imagen'])) continue;
                    if ($soloImagen === 'no'  && !empty($item['imagen'])) continue;

                    if (!empty($kwIncluir)) {
                        $hayMatch = false;
                        foreach ($kwIncluir as $kw) {
                            if (mb_stripos($item['titulo'], $kw) !== false ||
                                mb_stripos($item['resumen'] ?? '', $kw) !== false) {
                                $hayMatch = true; break;
                            }
                        }
                        if (!$hayMatch) continue;
                    }

                    if (!empty($kwExcluir)) {
                        $excluirFlag = false;
                        foreach ($kwExcluir as $kw) {
                            if (mb_stripos($item['titulo'], $kw) !== false) {
                                $excluirFlag = true; break;
                            }
                        }
                        if ($excluirFlag) continue;
                    }

                    if ($dedupe) {
                        $existeUrl = !empty($item['url_original'])
                            ? db()->count(
                                "SELECT COUNT(*) FROM noticiasgeneradas
                                 WHERE url_original=? AND usuario_id=?",
                                [$item['url_original'], (int)$usuario['id']]
                              )
                            : 0;
                        $existeTit = db()->count(
                            "SELECT COUNT(*) FROM noticiasgeneradas
                             WHERE titulo=? AND usuario_id=?
                             AND fecha_recopilacion >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
                            [$item['titulo'], (int)$usuario['id']]
                        );
                        if ($existeUrl > 0 || $existeTit > 0) {
                            $duplicados++; continue;
                        }
                    }

                    $titulo  = mb_substr(trim($item['titulo']), 0, 300);
                    $resumen = mb_substr(trim($item['resumen'] ?? ''), 0, 500);
                    $slug    = generateSlug($titulo, 'noticiasgeneradas');

                    db()->insert(
                        "INSERT INTO noticiasgeneradas
                            (fuente_id, fuente_nombre, fuente_url, titulo, slug,
                             resumen, contenido, imagen, imagen_alt,
                             categoria_id, autor_id, estado,
                             url_original, fecha_publicacion, usuario_id)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,'pendiente',?,?,?)",
                        [
                            $fuente['id'],
                            $fuente['nombre'],
                            $fuente['url'],
                            $titulo,
                            $slug,
                            $resumen,
                            $item['contenido'] ?? '<p>' . $resumen . '</p>',
                            $item['imagen'] ?? null,
                            $item['imagen_alt'] ?? $titulo,
                            $fuente['categoria_id'] ?: $catDef,
                            $fuente['autor_id'] ?: $autorDef,
                            $item['url_original'] ?? null,
                            $item['fecha_pub'] ?? null,
                            (int)$usuario['id'],
                        ]
                    );
                    $insertar++;
                    $totalInsert++;
                }

                $totalDuplic += $duplicados;
                db()->execute(
                    "UPDATE fuentes SET ultima_importacion=NOW(),
                     total_importadas=total_importadas+? WHERE id=?",
                    [$insertar, $fuente['id']]
                );
                $log[] = [
                    'fuente'     => $fuente['nombre'],
                    'status'     => 'ok',
                    'count'      => $insertar,
                    'duplicados' => $duplicados,
                    'msg'        => "✅ {$insertar} noticias importadas" .
                                    ($duplicados
                                        ? ", {$duplicados} duplicadas omitidas" : ''),
                ];
            }

            echo json_encode([
                'success'      => true,
                'total'        => $totalInsert,
                'total_duplic' => $totalDuplic,
                'log'          => $log,
                'message'      => "Recopilación completada: {$totalInsert} noticias nuevas.",
            ]);
            exit;

        // ════════════════════════════════════════════════════
        // NOTICIAS — CRUD
        // ════════════════════════════════════════════════════

        case 'noticias_listar':
            $filtEstado = trim($input['estado'] ?? '');
            $filtFuente = (int)($input['fuente_id'] ?? 0);
            $busqueda   = trim($input['q'] ?? '');
            $pagina     = max(1, (int)($input['pag'] ?? 1));
            $porPagina  = 30;
            $offset     = ($pagina - 1) * $porPagina;

            $where  = ['ng.usuario_id = ?'];
            $params = [(int)$usuario['id']];

            if ($filtEstado && in_array($filtEstado,
                ['pendiente','aprobada','descartada','insertada'])) {
                $where[]  = 'ng.estado = ?';
                $params[] = $filtEstado;
            }
            if ($filtFuente > 0) {
                $where[]  = 'ng.fuente_id = ?';
                $params[] = $filtFuente;
            }
            if ($busqueda) {
                $where[]  = 'ng.titulo LIKE ?';
                $params[] = '%' . $busqueda . '%';
            }

            $whereStr = implode(' AND ', $where);
            $total    = db()->count(
                "SELECT COUNT(*) FROM noticiasgeneradas ng WHERE $whereStr",
                $params
            );
            $noticias = db()->fetchAll(
                "SELECT ng.*, c.nombre AS cat_nombre, u.nombre AS autor_nombre
                 FROM noticiasgeneradas ng
                 LEFT JOIN categorias c ON c.id = ng.categoria_id
                 LEFT JOIN usuarios u ON u.id = ng.autor_id
                 WHERE $whereStr
                 ORDER BY ng.fecha_recopilacion DESC
                 LIMIT $porPagina OFFSET $offset",
                $params
            );

            echo json_encode([
                'success'  => true,
                'noticias' => $noticias,
                'total'    => $total,
                'paginas'  => (int)ceil($total / $porPagina),
                'pagina'   => $pagina,
            ]);
            exit;

        case 'noticia_get':
            $id = (int)($input['id'] ?? 0);
            $n  = db()->fetchOne(
                "SELECT ng.*, c.nombre AS cat_nombre
                 FROM noticiasgeneradas ng
                 LEFT JOIN categorias c ON c.id=ng.categoria_id
                 WHERE ng.id=?",
                [$id]
            );
            if (!$n) {
                echo json_encode(['success'=>false,'message'=>'No encontrada.']); exit;
            }
            echo json_encode(['success'=>true,'noticia'=>$n]);
            exit;

        case 'noticia_editar':
            $id = (int)($input['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit;
            }

            $sets = [];
            $vals = [];

            // ── Contenido: SIN LÍMITE DE CARACTERES ─────────
            if (array_key_exists('contenido', $input)) {
                $sets[] = '`contenido` = ?';
                $vals[] = trim($input['contenido'] ?? '');
            }

            // ── Campos con límites específicos ───────────────
            $camposLimitados = [
                'titulo'           => 300,
                'slug'             => 320,
                'resumen'          => 500,
                'imagen'           => 500,
                'imagen_alt'       => 200,
                'imagen_caption'   => 300,
                'meta_title'       => 200,
                'meta_description' => 300,
                'keywords'         => 300,
                'fuente_nombre'    => 200,
                'fuente_url'       => 500,
                'url_original'     => 500,
            ];
            foreach ($camposLimitados as $campo => $maxLen) {
                if (array_key_exists($campo, $input)) {
                    $sets[] = "`$campo` = ?";
                    $vals[] = mb_substr(trim($input[$campo] ?? ''), 0, $maxLen);
                }
            }

            // ── Campos enteros ────────────────────────────────
            foreach (['categoria_id','autor_id','destacado','breaking',
                      'es_premium','es_opinion','tiempo_lectura'] as $int) {
                if (array_key_exists($int, $input)) {
                    $sets[] = "`$int` = ?";
                    $vals[] = (int)$input[$int];
                }
            }

            if (array_key_exists('fecha_publicacion', $input)) {
                $sets[] = '`fecha_publicacion` = ?';
                $vals[] = $input['fecha_publicacion'] ?: null;
            }
            if (array_key_exists('estado', $input) &&
                in_array($input['estado'],
                    ['pendiente','aprobada','descartada','insertada'])) {
                $sets[] = '`estado` = ?';
                $vals[] = $input['estado'];
            }
            if (empty($sets)) {
                echo json_encode(['success'=>false,'message'=>'Nada que actualizar.']);
                exit;
            }
            $vals[] = $id;
            db()->execute(
                "UPDATE noticiasgeneradas SET " . implode(', ', $sets) . " WHERE id=?",
                $vals
            );
            echo json_encode(['success'=>true,'message'=>'Noticia actualizada.']);
            exit;

        case 'noticia_aprobar':
            $id = (int)($input['id'] ?? 0);
            db()->execute(
                "UPDATE noticiasgeneradas SET estado='aprobada' WHERE id=?", [$id]
            );
            db()->insert(
                "INSERT INTO noticiasaprobadas
                    (noticia_generada_id,titulo,fuente_nombre,accion,usuario_id)
                 SELECT id,titulo,fuente_nombre,'aprobada',?
                 FROM noticiasgeneradas WHERE id=?",
                [(int)$usuario['id'], $id]
            );
            echo json_encode(['success'=>true,'message'=>'Noticia aprobada.']);
            exit;

        case 'noticia_descartar':
            $id = (int)($input['id'] ?? 0);
            db()->execute(
                "UPDATE noticiasgeneradas SET estado='descartada' WHERE id=?", [$id]
            );
            echo json_encode(['success'=>true,'message'=>'Noticia descartada.']);
            exit;

        case 'noticia_eliminar':
            $id = (int)($input['id'] ?? 0);
            db()->execute("DELETE FROM noticiasgeneradas WHERE id=?", [$id]);
            echo json_encode(['success'=>true,'message'=>'Noticia eliminada del buffer.']);
            exit;

        case 'noticias_bulk_estado':
            $ids    = array_map('intval', $input['ids'] ?? []);
            $estado = $input['estado'] ?? '';
            if (empty($ids) ||
                !in_array($estado,
                    ['pendiente','aprobada','descartada','insertada'])) {
                echo json_encode(['success'=>false,'message'=>'Datos inválidos.']); exit;
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            db()->execute(
                "UPDATE noticiasgeneradas SET estado=? WHERE id IN ($ph)",
                array_merge([$estado], $ids)
            );
            echo json_encode(['success'=>true,
                              'message'=>count($ids).' noticias actualizadas.']);
            exit;

        case 'noticias_bulk_eliminar':
            $ids = array_map('intval', $input['ids'] ?? []);
            if (empty($ids)) {
                echo json_encode(['success'=>false,'message'=>'Sin selección.']); exit;
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            db()->execute(
                "DELETE FROM noticiasgeneradas WHERE id IN ($ph)", $ids
            );
            echo json_encode(['success'=>true,
                              'message'=>count($ids).' noticias eliminadas del buffer.']);
            exit;

        case 'noticias_limpiar':
            $uid = (int)$usuario['id'];
            $n   = db()->execute(
                "DELETE FROM noticiasgeneradas
                 WHERE usuario_id=? AND estado NOT IN ('insertada')",
                [$uid]
            );
            echo json_encode(['success'=>true,
                              'message'=>"$n noticias eliminadas del buffer."]);
            exit;

        // ════════════════════════════════════════════════════
        // INSERCIÓN DIRECTA A BD
        // ════════════════════════════════════════════════════

        case 'insertar_noticias':
            $ids         = array_map('intval', $input['ids'] ?? []);
            $estadoIns   = $input['estado_publicacion'] ?? 'borrador';
            $catOverride = (int)($input['cat_override'] ?? 0);
            $autorOver   = (int)($input['autor_override'] ?? 0);
            $insertadas  = [];
            $errores     = [];

            if (!in_array($estadoIns,
                ['publicado','borrador','programado','archivado'])) {
                $estadoIns = 'borrador';
            }
            if (empty($ids)) {
                echo json_encode(['success'=>false,
                                  'message'=>'Selecciona noticias para insertar.']);
                exit;
            }

            $ph     = implode(',', array_fill(0, count($ids), '?'));
            $buffer = db()->fetchAll(
                "SELECT * FROM noticiasgeneradas WHERE id IN ($ph) AND usuario_id=?",
                array_merge($ids, [(int)$usuario['id']])
            );

            foreach ($buffer as $ng) {
                try {
                    $titulo  = mb_substr($ng['titulo'], 0, 300);
                    $slug    = generateSlug($titulo, 'noticias');
                    $catId   = $catOverride ?: (int)$ng['categoria_id'];
                    $autorId = $autorOver   ?: (int)$ng['autor_id'];
                    $fechaPub = $estadoIns === 'publicado'
                                ? ($ng['fecha_publicacion'] ?: date('Y-m-d H:i:s'))
                                : $ng['fecha_publicacion'];

                    $newId = db()->insert(
                        "INSERT INTO noticias
                            (titulo, slug, resumen, contenido,
                             imagen, imagen_alt, imagen_caption,
                             autor_id, categoria_id, estado,
                             destacado, breaking, es_premium, es_opinion,
                             allow_comments, permitir_comentarios,
                             fuente, fuente_url,
                             tiempo_lectura,
                             meta_title, meta_description, keywords,
                             fecha_publicacion, fecha_creacion)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,1,?,?,?,?,?,?,?,NOW())",
                        [
                            $titulo,
                            $slug,
                            mb_substr($ng['resumen'] ?? '', 0, 500),
                            // contenido sin truncar
                            $ng['contenido'] ?: '<p>' . ($ng['resumen'] ?? '') . '</p>',
                            $ng['imagen'],
                            mb_substr($ng['imagen_alt'] ?? $titulo, 0, 200),
                            mb_substr($ng['imagen_caption'] ?? '', 0, 300),
                            $autorId,
                            $catId,
                            $estadoIns,
                            (int)$ng['destacado'],
                            (int)$ng['breaking'],
                            (int)$ng['es_premium'],
                            (int)$ng['es_opinion'],
                            mb_substr($ng['fuente_nombre'] ?? '', 0, 200),
                            mb_substr($ng['fuente_url'] ?? '', 0, 300),
                            max(1, (int)$ng['tiempo_lectura']),
                            mb_substr($ng['meta_title'] ?? '', 0, 200),
                            mb_substr($ng['meta_description'] ?? '', 0, 300),
                            mb_substr($ng['keywords'] ?? '', 0, 300),
                            $fechaPub,
                        ]
                    );

                    db()->execute(
                        "UPDATE noticiasgeneradas SET estado='insertada' WHERE id=?",
                        [$ng['id']]
                    );
                    db()->insert(
                        "INSERT INTO noticiasaprobadas
                            (noticia_generada_id,noticia_id,titulo,
                             fuente_nombre,accion,usuario_id)
                         VALUES (?,?,?,?,'insertada',?)",
                        [$ng['id'],$newId,$titulo,
                         $ng['fuente_nombre'],(int)$usuario['id']]
                    );

                    $insertadas[] = [
                        'ng_id'      => $ng['id'],
                        'noticia_id' => $newId,
                        'titulo'     => $titulo,
                    ];
                    logActividad(
                        (int)$usuario['id'], 'insertar_noticia_generada',
                        'noticias', $newId, "Insertó noticia generada: $titulo"
                    );
                } catch (\Throwable $e) {
                    $errores[] = [
                        'ng_id'  => $ng['id'],
                        'titulo' => $ng['titulo'],
                        'error'  => $e->getMessage(),
                    ];
                }
            }

            echo json_encode([
                'success'    => count($insertadas) > 0,
                'insertadas' => $insertadas,
                'errores'    => $errores,
                'message'    => count($insertadas) . ' noticias insertadas' .
                                (count($errores)
                                    ? ', ' . count($errores) . ' con error.' : '.'),
            ]);
            exit;

        // ════════════════════════════════════════════════════
        // GENERACIÓN SQL
        // ════════════════════════════════════════════════════

        case 'generar_sql':
            $ids         = array_map('intval', $input['ids'] ?? []);
            $sqlScope    = $input['scope'] ?? 'aprobadas';
            $sqlEstado   = $input['sql_estado'] ?? 'borrador';
            $catOverride = (int)($input['cat_override'] ?? 0);
            $autorOver   = (int)($input['autor_override'] ?? 0);
            $useNow      = (bool)($input['use_now_date'] ?? false);
            $inclHeader  = (bool)($input['include_header'] ?? true);

            if (!in_array($sqlEstado,
                ['publicado','borrador','programado','archivado'])) {
                $sqlEstado = 'borrador';
            }

            if ($sqlScope === 'seleccionadas' && !empty($ids)) {
                $ph    = implode(',', array_fill(0, count($ids), '?'));
                $items = db()->fetchAll(
                    "SELECT * FROM noticiasgeneradas
                     WHERE id IN ($ph) AND usuario_id=?",
                    array_merge($ids, [(int)$usuario['id']])
                );
            } elseif ($sqlScope === 'aprobadas') {
                $items = db()->fetchAll(
                    "SELECT * FROM noticiasgeneradas
                     WHERE estado='aprobada' AND usuario_id=?",
                    [(int)$usuario['id']]
                );
            } else {
                $items = db()->fetchAll(
                    "SELECT * FROM noticiasgeneradas
                     WHERE estado IN ('pendiente','aprobada') AND usuario_id=?",
                    [(int)$usuario['id']]
                );
            }

            if (empty($items)) {
                echo json_encode(['success'=>false,
                                  'message'=>'No hay noticias para generar SQL.']);
                exit;
            }

            $siteName = Config::get('generador_sitename', 'El Click RD');
            $now      = date('Y-m-d H:i:s');
            $sql      = '';

            if ($inclHeader) {
                $sql .= "-- ============================================================\n";
                $sql .= "-- {$siteName} — Importación de Noticias\n";
                $sql .= "-- Generado: {$now}\n";
                $sql .= "-- Total de registros: " . count($items) . "\n";
                $sql .= "-- Base de datos: lnuazoql_elclickrdv2\n";
                $sql .= "-- Tabla: noticias\n";
                $sql .= "-- Charset: utf8mb4\n";
                $sql .= "-- ============================================================\n\n";
                $sql .= "SET NAMES utf8mb4;\n";
                $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
            }

            $sql .= "INSERT INTO `noticias`\n";
            $sql .= "(`titulo`,`slug`,`resumen`,`contenido`,`imagen`,`imagen_alt`,\n";
            $sql .= " `imagen_caption`,`autor_id`,`categoria_id`,`estado`,`destacado`,\n";
            $sql .= " `breaking`,`es_premium`,`es_opinion`,`allow_comments`,\n";
            $sql .= " `fuente`,`fuente_url`,`tiempo_lectura`,\n";
            $sql .= " `meta_title`,`meta_description`,`keywords`,\n";
            $sql .= " `fecha_publicacion`,`fecha_creacion`)\n";
            $sql .= "VALUES\n";

            $esc = fn($s) => addslashes(str_replace(
                ["\r\n","\r","\n"], ' ', (string)($s ?? '')
            ));
            $nul = fn($s) => (trim((string)($s ?? '')) !== '')
                ? "'" . addslashes(trim((string)$s)) . "'" : 'NULL';

            $rows = [];
            foreach ($items as $n) {
                $titulo  = mb_substr($n['titulo'], 0, 300);
                $slug    = generateSlug($titulo, 'noticias');
                $catId   = $catOverride ?: (int)$n['categoria_id'];
                $autorId = $autorOver   ?: (int)$n['autor_id'];
                $fechaP  = $useNow ? $now : ($n['fecha_publicacion'] ?: $now);

                $rows[] = sprintf(
                    "('%s','%s',%s,'%s',%s,%s,%s,%d,%d,'%s',%d,%d,%d,%d,1,%s,%s,%d,%s,%s,%s,'%s','%s')",
                    $esc($titulo),
                    $esc($slug),
                    $nul($n['resumen']),
                    // contenido sin truncar
                    $esc($n['contenido'] ?: '<p>' . ($n['resumen'] ?? '') . '</p>'),
                    $nul($n['imagen']),
                    $nul($n['imagen_alt'] ?? $titulo),
                    $nul($n['imagen_caption']),
                    $autorId,
                    $catId,
                    $sqlEstado,
                    (int)$n['destacado'],
                    (int)$n['breaking'],
                    (int)$n['es_premium'],
                    (int)$n['es_opinion'],
                    $nul($n['fuente_nombre']),
                    $nul($n['fuente_url']),
                    max(1,(int)$n['tiempo_lectura']),
                    $nul($n['meta_title']),
                    $nul($n['meta_description']),
                    $nul($n['keywords']),
                    $esc($fechaP),
                    $esc($now)
                );
            }

            $sql .= implode(",\n", $rows) . ";\n\n";
            if ($inclHeader) { $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n"; }

            $sqlId = db()->insert(
                "INSERT INTO sqlgenerados
                    (titulo,total_registros,sql_content,estado,usuario_id)
                 VALUES (?,?,?,'guardado',?)",
                [
                    "{$siteName} — {$now} — " . count($items) . " registros",
                    count($items),
                    $sql,
                    (int)$usuario['id'],
                ]
            );

            logActividad(
                (int)$usuario['id'], 'generar_sql', 'generador', $sqlId,
                "Generó SQL: " . count($items) . " registros"
            );

            echo json_encode([
                'success'          => true,
                'sql'              => $sql,
                'total'            => count($items),
                'sql_historial_id' => $sqlId,
                'message'          => "SQL generado: " . count($items) . " registros.",
            ]);
            exit;

        // ════════════════════════════════════════════════════
        // HISTORIAL SQL
        // ════════════════════════════════════════════════════

        case 'sql_historial':
            $pagina = max(1, (int)($input['pag'] ?? 1));
            $limit  = 20;
            $offset = ($pagina - 1) * $limit;
            $total  = db()->count(
                "SELECT COUNT(*) FROM sqlgenerados WHERE usuario_id=?",
                [(int)$usuario['id']]
            );
            $lista = db()->fetchAll(
                "SELECT id, titulo, total_registros, estado, fecha
                 FROM sqlgenerados WHERE usuario_id=?
                 ORDER BY fecha DESC LIMIT $limit OFFSET $offset",
                [(int)$usuario['id']]
            );
            echo json_encode([
                'success' => true,
                'lista'   => $lista,
                'total'   => $total,
                'paginas' => (int)ceil($total / $limit),
            ]);
            exit;

        case 'sql_get':
            $id  = (int)($input['id'] ?? 0);
            $row = db()->fetchOne(
                "SELECT * FROM sqlgenerados WHERE id=? AND usuario_id=?",
                [$id, (int)$usuario['id']]
            );
            if (!$row) {
                echo json_encode(['success'=>false,'message'=>'No encontrado.']); exit;
            }
            db()->execute(
                "UPDATE sqlgenerados SET estado='utilizado' WHERE id=?", [$id]
            );
            echo json_encode(['success'=>true,'sql'=>$row]);
            exit;

        case 'sql_eliminar':
            $id = (int)($input['id'] ?? 0);
            db()->execute(
                "DELETE FROM sqlgenerados WHERE id=? AND usuario_id=?",
                [$id, (int)$usuario['id']]
            );
            echo json_encode(['success'=>true,'message'=>'SQL eliminado del historial.']);
            exit;

        // ════════════════════════════════════════════════════
        // CONFIGURACIÓN
        // ════════════════════════════════════════════════════

        case 'config_get':
            $keys = [
                'generador_sitename','generador_autor_defecto',
                'generador_categoria_defecto','generador_max_noticias',
                'generador_timeout','generador_dedupe','generador_autoclean',
                'generador_proxy1','generador_proxy2','generador_proxy3',
                'generador_estado_defecto',
            ];
            $cfg = [];
            foreach ($keys as $k) {
                $cfg[str_replace('generador_', '', $k)] = Config::get($k, '');
            }
            echo json_encode(['success'=>true,'config'=>$cfg]);
            exit;

        case 'config_guardar':
            $mapa = [
                'sitename'          => 'generador_sitename',
                'autor_defecto'     => 'generador_autor_defecto',
                'categoria_defecto' => 'generador_categoria_defecto',
                'max_noticias'      => 'generador_max_noticias',
                'timeout'           => 'generador_timeout',
                'dedupe'            => 'generador_dedupe',
                'autoclean'         => 'generador_autoclean',
                'proxy1'            => 'generador_proxy1',
                'proxy2'            => 'generador_proxy2',
                'proxy3'            => 'generador_proxy3',
                'estado_defecto'    => 'generador_estado_defecto',
            ];
            foreach ($mapa as $inputKey => $dbKey) {
                if (array_key_exists($inputKey, $input)) {
                    db()->execute(
                        "INSERT INTO configuracion_global
                            (clave,valor,tipo,grupo)
                         VALUES (?,?,'texto','generador')
                         ON DUPLICATE KEY UPDATE valor=?",
                        [$dbKey,(string)$input[$inputKey],(string)$input[$inputKey]]
                    );
                }
            }
            Config::reload();
            logActividad((int)$usuario['id'],'config_generador','generador',
                         null,'Actualizó config del generador');
            echo json_encode(['success'=>true,
                              'message'=>'Configuración guardada correctamente.']);
            exit;

        // ════════════════════════════════════════════════════
        // DATOS AUXILIARES
        // ════════════════════════════════════════════════════

        case 'get_categorias':
            $cats = db()->fetchAll(
                "SELECT id, nombre, color FROM categorias
                 WHERE activa=1 ORDER BY orden"
            );
            echo json_encode(['success'=>true,'categorias'=>$cats]);
            exit;

        case 'get_autores':
            $autores = db()->fetchAll(
                "SELECT id, nombre FROM usuarios
                 WHERE activo=1
                 AND rol IN ('super_admin','admin','editor','periodista')
                 ORDER BY nombre"
            );
            echo json_encode(['success'=>true,'autores'=>$autores]);
            exit;

        case 'get_stats':
            $uid = (int)$usuario['id'];
            echo json_encode([
                'success'       => true,
                'total_fuentes' => db()->count(
                    "SELECT COUNT(*) FROM fuentes WHERE activa=1"
                ),
                'pendientes'    => db()->count(
                    "SELECT COUNT(*) FROM noticiasgeneradas
                     WHERE estado='pendiente' AND usuario_id=?", [$uid]
                ),
                'aprobadas'     => db()->count(
                    "SELECT COUNT(*) FROM noticiasgeneradas
                     WHERE estado='aprobada' AND usuario_id=?", [$uid]
                ),
                'insertadas'    => db()->count(
                    "SELECT COUNT(*) FROM noticiasgeneradas
                     WHERE estado='insertada' AND usuario_id=?", [$uid]
                ),
                'sql_guardados' => db()->count(
                    "SELECT COUNT(*) FROM sqlgenerados WHERE usuario_id=?", [$uid]
                ),
            ]);
            exit;

        default:
            echo json_encode(['success'=>false,'message'=>'Acción desconocida.']);
            exit;
    }
}

// ============================================================
// FUNCIÓN FETCH RSS / ATOM / WEB
// ============================================================
function generadorFetchUrl(string $url, string $tipo, int $timeout = 15): array
{
    $ctx = curl_init($url);
    curl_setopt_array($ctx, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      =>
            'Mozilla/5.0 (compatible; ElClickRD/2.1; +https://elclickrd.com)',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: es-419,es;q=0.9,en;q=0.8',
        ],
    ]);
    $body = curl_exec($ctx);
    $code = curl_getinfo($ctx, CURLINFO_HTTP_CODE);
    $err  = curl_error($ctx);
    curl_close($ctx);

    if ($body === false || $code < 200 || $code >= 400) {
        return ['success'=>false,'message'=>"Error HTTP {$code}: {$err}"];
    }

    $items = [];

    if (in_array($tipo, ['rss','atom'])) {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string(
            $body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR
        );
        if ($xml === false) {
            return ['success'=>false,'message'=>'XML inválido o no se pudo parsear.'];
        }

        // ── RSS ──────────────────────────────────────────────
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $ns    = $item->getNamespaces(true);
                $media = isset($ns['media'])
                         ? $item->children($ns['media']) : null;
                $encImg = '';

                // Imagen: media:content
                if ($media && isset($media->content)) {
                    $a = $media->content->attributes();
                    if (!empty($a['url'])) $encImg = (string)$a['url'];
                }
                // Imagen: media:thumbnail
                if (!$encImg && $media && isset($media->thumbnail)) {
                    $a = $media->thumbnail->attributes();
                    if (!empty($a['url'])) $encImg = (string)$a['url'];
                }
                // Imagen: enclosure
                if (!$encImg && isset($item->enclosure)) {
                    $ea = $item->enclosure->attributes();
                    if (!empty($ea['url']) &&
                        str_contains((string)($ea['type']??''), 'image')) {
                        $encImg = (string)$ea['url'];
                    }
                }

                // Contenido completo: content:encoded
                $fullContent = '';
                if (isset($ns['content'])) {
                    $contentNs = $item->children($ns['content']);
                    if (isset($contentNs->encoded)) {
                        $fullContent = (string)$contentNs->encoded;
                    }
                }

                // Imagen desde contenido
                if (!$encImg) {
                    $searchIn = $fullContent ?: (string)($item->description ?? '');
                    if (preg_match(
                        '/<img[^>]+src=["\']([^"\']+)["\']/', $searchIn, $m
                    )) {
                        $encImg = $m[1];
                    }
                }

                $fechaPub = null;
                if (!empty($item->pubDate)) {
                    $ts = strtotime((string)$item->pubDate);
                    if ($ts) $fechaPub = date('Y-m-d H:i:s', $ts);
                }

                $descRaw = $fullContent ?: (string)($item->description ?? '');
                $resumen = mb_substr(trim(strip_tags($descRaw)), 0, 500);
                // Contenido: usar HTML completo si hay content:encoded
                $contenido = $fullContent
                    ? trim($fullContent)
                    : '<p>' . $resumen . '</p>';

                $items[] = [
                    'titulo'       => trim((string)($item->title ?? '')),
                    'resumen'      => $resumen,
                    'contenido'    => $contenido,
                    'imagen'       => $encImg ?: null,
                    'imagen_alt'   => trim((string)($item->title ?? '')),
                    'url_original' => trim((string)($item->link ?? '')),
                    'fecha_pub'    => $fechaPub,
                ];
            }
        }
        // ── Atom ─────────────────────────────────────────────
        elseif (isset($xml->entry)) {
            foreach ($xml->entry as $entry) {
                $ns     = $entry->getNamespaces(true);
                $encImg = '';

                if (isset($ns['media'])) {
                    $media = $entry->children($ns['media']);
                    if (isset($media->thumbnail)) {
                        $a = $media->thumbnail->attributes();
                        if (!empty($a['url'])) $encImg = (string)$a['url'];
                    }
                    if (!$encImg && isset($media->content)) {
                        $a = $media->content->attributes();
                        if (!empty($a['url'])) $encImg = (string)$a['url'];
                    }
                }

                $link = '';
                foreach ($entry->link as $lnk) {
                    $la = $lnk->attributes();
                    if ((string)($la['rel']??'') !== 'enclosure') {
                        $link = (string)($la['href'] ?? '');
                        break;
                    }
                }

                $fechaPub = null;
                foreach (['published','updated'] as $dateField) {
                    if (!empty($entry->$dateField)) {
                        $ts = strtotime((string)$entry->$dateField);
                        if ($ts) { $fechaPub = date('Y-m-d H:i:s', $ts); break; }
                    }
                }

                $contenidoRaw = (string)($entry->content ?? $entry->summary ?? '');
                $resumen      = mb_substr(strip_tags($contenidoRaw), 0, 500);
                $contenido    = trim($contenidoRaw) ?: '<p>' . $resumen . '</p>';

                if (!$encImg && preg_match(
                    '/<img[^>]+src=["\']([^"\']+)["\']/', $contenidoRaw, $m
                )) {
                    $encImg = $m[1];
                }

                $items[] = [
                    'titulo'       => trim((string)($entry->title ?? '')),
                    'resumen'      => $resumen,
                    'contenido'    => $contenido,
                    'imagen'       => $encImg ?: null,
                    'imagen_alt'   => trim((string)($entry->title ?? '')),
                    'url_original' => $link,
                    'fecha_pub'    => $fechaPub,
                ];
            }
        }
    } else {
        // ── Web scraping ─────────────────────────────────────
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $body);
        $xpath = new \DOMXPath($doc);

        $getOg = function(string $property) use ($xpath): string {
            $nodes = $xpath->query("//meta[@property='$property']/@content");
            return $nodes && $nodes->length
                ? trim($nodes->item(0)->nodeValue) : '';
        };

        $titles = $xpath->query(
            "//h2[@class] | //h3[@class] | //article//h2 | //article//h3"
        );
        if ($titles && $titles->length > 0) {
            foreach ($titles as $t) {
                $txt = trim($t->textContent);
                if (mb_strlen($txt) < 10) continue;
                $items[] = [
                    'titulo'       => mb_substr($txt, 0, 300),
                    'resumen'      => mb_substr($txt, 0, 300),
                    'contenido'    => '<p>' . mb_substr($txt, 0, 300) . '</p>',
                    'imagen'       => $getOg('og:image') ?: null,
                    'imagen_alt'   => $txt,
                    'url_original' => $url,
                    'fecha_pub'    => date('Y-m-d H:i:s'),
                ];
                if (count($items) >= 30) break;
            }
        } else {
            $titulo = $getOg('og:title');
            if ($titulo) {
                $items[] = [
                    'titulo'       => mb_substr($titulo, 0, 300),
                    'resumen'      => mb_substr($getOg('og:description'), 0, 500),
                    'contenido'    => '<p>' . $getOg('og:description') . '</p>',
                    'imagen'       => $getOg('og:image') ?: null,
                    'imagen_alt'   => $titulo,
                    'url_original' => $url,
                    'fecha_pub'    => date('Y-m-d H:i:s'),
                ];
            }
        }
    }

    $items = array_values(array_filter(
        $items, fn($i) => !empty($i['titulo']) && mb_strlen($i['titulo']) > 5
    ));

    return [
        'success' => true,
        'items'   => $items,
        'total'   => count($items),
        'message' => "OK — " . count($items) . " ítems encontrados",
    ];
}

// ============================================================
// DATOS PARA LA VISTA
// ============================================================
$categorias = db()->fetchAll(
    "SELECT id, nombre, color FROM categorias WHERE activa=1 ORDER BY orden"
);
$autores = db()->fetchAll(
    "SELECT id, nombre FROM usuarios
     WHERE activo=1 AND rol IN ('super_admin','admin','editor','periodista')
     ORDER BY nombre"
);
$cfgGen = [
    'sitename'          => Config::get('generador_sitename',    'El Click RD'),
    'autor_defecto'     => Config::get('generador_autor_defecto', '1'),
    'categoria_defecto' => Config::get('generador_categoria_defecto', '1'),
    'max_noticias'      => Config::get('generador_max_noticias', '500'),
    'timeout'           => Config::get('generador_timeout',     '10'),
    'dedupe'            => Config::get('generador_dedupe',      '1'),
    'autoclean'         => Config::get('generador_autoclean',   '0'),
    'proxy1'            => Config::get('generador_proxy1',
                            'https://api.allorigins.win/get?url='),
    'proxy2'            => Config::get('generador_proxy2',
                            'https://corsproxy.io/?'),
    'proxy3'            => Config::get('generador_proxy3',
                            'https://thingproxy.freeboard.io/fetch/'),
    'estado_defecto'    => Config::get('generador_estado_defecto', 'publicado'),
];

$currentFile = 'generador.php';
$pageTitle   = 'Generador de Noticias — Panel Admin';

// Sidebar badges
try {
    $sidebarBadges = [
        'usuarios_inactivos' => db()->count(
            "SELECT COUNT(*) FROM usuarios WHERE activo=0"
        ),
        'noticias_pendientes' => db()->count(
            "SELECT COUNT(*) FROM noticiasgeneradas
             WHERE estado='pendiente' AND usuario_id=?",
            [(int)$usuario['id']]
        ),
    ];
} catch (\Throwable $e) {
    $sidebarBadges = ['usuarios_inactivos' => 0, 'noticias_pendientes' => 0];
}
?>
<!DOCTYPE html>
<html lang="es"
      data-theme="<?= e(Config::get('apariencia_modo_oscuro','auto')) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap"
      rel="stylesheet">
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet"
      href="<?= APP_URL ?>/assets/css/style.css?v=<?= APP_VERSION ?>">
<style>
/* ============================================================
   ADMIN BASE LAYOUT
============================================================ */
*,*::before,*::after{box-sizing:border-box}
body{padding-bottom:0;background:var(--bg-body);margin:0}
.admin-wrapper{display:flex;min-height:100vh}

/* Sidebar admin */
.admin-sidebar{
    width:260px;background:#0f1f33;
    position:fixed;top:0;left:0;height:100vh;
    overflow-y:auto;overflow-x:hidden;
    z-index:var(--z-header,1000);
    transition:transform .3s ease;
    display:flex;flex-direction:column;
}
.admin-sidebar::-webkit-scrollbar{width:4px}
.admin-sidebar::-webkit-scrollbar-thumb{background:rgba(255,255,255,.1);border-radius:2px}
.admin-sidebar__logo{
    padding:22px 20px 14px;
    border-bottom:1px solid rgba(255,255,255,.07);flex-shrink:0
}
.admin-sidebar__logo a{display:flex;align-items:center;gap:10px;text-decoration:none}
.admin-sidebar__logo-icon{
    width:36px;height:36px;background:var(--primary);
    border-radius:10px;display:flex;align-items:center;
    justify-content:center;color:#fff;font-size:1rem;flex-shrink:0
}
.admin-sidebar__logo-text{
    font-family:var(--font-serif,'Georgia',serif);
    font-size:1rem;font-weight:800;color:#fff;line-height:1.1
}
.admin-sidebar__logo-sub{
    font-size:.62rem;color:rgba(255,255,255,.35);
    text-transform:uppercase;letter-spacing:.06em
}
.admin-sidebar__user{
    padding:12px 20px;border-bottom:1px solid rgba(255,255,255,.07);
    display:flex;align-items:center;gap:10px;flex-shrink:0
}
.admin-sidebar__user img{
    width:34px;height:34px;border-radius:50%;
    object-fit:cover;border:2px solid rgba(255,255,255,.15)
}
.admin-sidebar__user-name{
    font-size:.8rem;font-weight:600;
    color:rgba(255,255,255,.9);display:block;line-height:1.2
}
.admin-sidebar__user-role{font-size:.66rem;color:rgba(255,255,255,.4)}
.admin-nav{flex:1;padding:10px 0}
.admin-nav__section{
    padding:14px 20px 5px;font-size:.6rem;font-weight:700;
    text-transform:uppercase;letter-spacing:.1em;
    color:rgba(255,255,255,.22)
}
.admin-nav__item{
    display:flex;align-items:center;gap:10px;padding:9px 20px;
    color:rgba(255,255,255,.6);font-size:.81rem;font-weight:500;
    text-decoration:none;transition:all .15s;position:relative;
    border:none;background:none;width:100%;cursor:pointer;
    text-align:left
}
.admin-nav__item:hover{
    background:rgba(255,255,255,.06);color:rgba(255,255,255,.9)
}
.admin-nav__item.active{
    background:rgba(230,57,70,.18);color:#fff;font-weight:600
}
.admin-nav__item.active::before{
    content:'';position:absolute;left:0;top:0;bottom:0;
    width:3px;background:var(--primary);border-radius:0 3px 3px 0
}
.admin-nav__item i{
    width:18px;text-align:center;font-size:.88rem;flex-shrink:0
}
.admin-nav__badge{
    margin-left:auto;background:var(--primary);color:#fff;
    font-size:.58rem;font-weight:700;padding:2px 6px;
    border-radius:20px;min-width:18px;text-align:center
}
.admin-sidebar__footer{
    padding:14px 20px;border-top:1px solid rgba(255,255,255,.07);flex-shrink:0
}
.admin-main{
    margin-left:260px;flex:1;min-height:100vh;
    display:flex;flex-direction:column;min-width:0
}
.admin-topbar{
    background:var(--bg-surface);border-bottom:1px solid var(--border-color);
    padding:0 24px;height:60px;display:flex;align-items:center;gap:12px;
    position:sticky;top:0;z-index:100;box-shadow:var(--shadow-sm);
    flex-shrink:0
}
.admin-topbar__toggle{
    display:none;color:var(--text-muted);font-size:1.2rem;
    padding:6px;border-radius:var(--border-radius-sm);
    background:none;border:none;cursor:pointer
}
.admin-topbar__title{
    font-weight:700;font-size:.95rem;color:var(--text-primary);
    margin-right:auto;display:flex;align-items:center;gap:8px
}
.admin-content{padding:0;flex:1;overflow:hidden}

@media(max-width:900px){
    .admin-sidebar{transform:translateX(-100%)}
    .admin-sidebar.open{transform:translateX(0)}
    .admin-main{margin-left:0}
    .admin-topbar__toggle{display:flex}
    .admin-overlay{display:block!important}
}
.admin-overlay{
    display:none;position:fixed;inset:0;
    background:rgba(0,0,0,.5);z-index:999
}

/* ============================================================
   GENERADOR — LAYOUT SPA
============================================================ */
.gen-wrapper{
    display:flex;
    height:calc(100vh - 60px); /* compensar topbar */
    overflow:hidden;
}
.gen-sidebar{
    width:240px;background:var(--bg-surface);
    border-right:1px solid var(--border-color);
    display:flex;flex-direction:column;
    flex-shrink:0;overflow-y:auto;overflow-x:hidden
}
.gen-sidebar::-webkit-scrollbar{width:4px}
.gen-sidebar::-webkit-scrollbar-thumb{
    background:var(--border-color);border-radius:2px
}
.gen-sidebar-logo{
    padding:18px 16px 12px;
    border-bottom:1px solid var(--border-color)
}
.gen-sidebar-logo .db-pill{
    display:inline-block;
    background:rgba(230,57,70,.1);color:var(--primary);
    font-size:.65rem;font-weight:700;padding:2px 7px;
    border-radius:20px;margin-top:4px;
    font-family:'JetBrains Mono',monospace
}
.gen-nav{flex:1;padding:8px 0}
.gen-nav-item{
    display:flex;align-items:center;gap:10px;
    padding:10px 16px;cursor:pointer;
    color:var(--text-secondary);font-size:.82rem;font-weight:500;
    border:none;background:none;width:100%;text-align:left;
    transition:all .15s;position:relative
}
.gen-nav-item:hover{background:var(--bg-surface-2);color:var(--text-primary)}
.gen-nav-item.active{
    background:rgba(230,57,70,.08);
    color:var(--primary);font-weight:700
}
.gen-nav-item.active::before{
    content:'';position:absolute;left:0;top:0;bottom:0;
    width:3px;background:var(--primary)
}
.gen-nav-item .badge{
    margin-left:auto;background:var(--primary);color:#fff;
    font-size:.6rem;font-weight:700;padding:2px 6px;
    border-radius:20px;min-width:18px;text-align:center
}
.gen-nav-item .badge.yellow{background:#f59e0b}
.gen-nav-item .badge.green{background:#22c55e}
.gen-sidebar-footer{
    padding:12px 16px;border-top:1px solid var(--border-color);
    font-size:.64rem;color:var(--text-muted);line-height:1.6
}

/* Área principal del generador */
.gen-main{
    flex:1;min-width:0; /* CRÍTICO: evita overflow horizontal */
    overflow-y:auto;overflow-x:hidden;
    display:flex;flex-direction:column
}

/* Header interno del generador */
.gen-header{
    background:var(--bg-surface);
    border-bottom:1px solid var(--border-color);
    padding:0 20px;min-height:50px;
    display:flex;align-items:center;
    gap:10px;flex-shrink:0;flex-wrap:wrap;
    position:sticky;top:0;z-index:10
}
.gen-breadcrumb{
    display:flex;align-items:center;gap:6px;
    font-size:.78rem;color:var(--text-muted);
    white-space:nowrap
}
.gen-breadcrumb-sep{color:var(--border-color)}
.gen-breadcrumb-current{font-weight:700;color:var(--text-primary)}
.gen-stats{
    display:flex;gap:6px;margin-left:auto;
    align-items:center;flex-wrap:wrap;padding:6px 0
}
.stat-pill{
    display:flex;align-items:center;gap:5px;
    padding:3px 10px;background:var(--bg-surface-2);
    border-radius:20px;font-size:.7rem;font-weight:600;
    color:var(--text-secondary);border:1px solid var(--border-color);
    white-space:nowrap
}
.stat-pill .dot{
    width:6px;height:6px;border-radius:50%;
    background:var(--primary);flex-shrink:0
}
.stat-pill.green .dot{background:#22c55e}
.stat-pill.yellow .dot{background:#f59e0b}

/* Cuerpo del generador */
.gen-body{padding:24px;flex:1}
.gen-page{display:none}
.gen-page.active{display:block}

/* ── Paneles ─────────────────────────────────────────────── */
.panel{
    background:var(--bg-surface);
    border:1px solid var(--border-color);
    border-radius:var(--border-radius-lg,10px);
    margin-bottom:20px;overflow:hidden
}
.panel-header{
    padding:14px 18px;border-bottom:1px solid var(--border-color);
    display:flex;align-items:center;justify-content:space-between;
    flex-wrap:wrap;gap:8px
}
.panel-header h2{
    font-size:.9rem;font-weight:700;margin:0;color:var(--text-primary)
}
.panel-header .sub{font-size:.73rem;color:var(--text-muted);margin-top:2px}
.panel-body{padding:18px}
.panel-body.flush{padding:0}

/* ── Formularios ─────────────────────────────────────────── */
.field{display:flex;flex-direction:column;gap:5px}
.field-label{
    font-size:.77rem;font-weight:600;color:var(--text-secondary)
}
.field-hint{font-size:.7rem;color:var(--text-muted);margin-top:2px}
.input,.select,.textarea{
    width:100%;padding:8px 11px;
    background:var(--bg-surface-2);
    border:1px solid var(--border-color);
    border-radius:var(--border-radius,6px);
    color:var(--text-primary);font-size:.82rem;
    transition:border-color .15s;font-family:inherit
}
.input:focus,.select:focus,.textarea:focus{
    outline:none;border-color:var(--primary);
    box-shadow:0 0 0 3px rgba(230,57,70,.1)
}
.input-row{display:flex;gap:8px}
.textarea{resize:vertical;min-height:80px}

/* Grids responsivos */
.form-grid-2{
    display:grid;grid-template-columns:1fr 1fr;gap:14px
}
.form-grid-3{
    display:grid;grid-template-columns:repeat(3,1fr);gap:14px
}
/* form-grid-4: auto-fill para ser más responsivo */
.form-grid-4{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(200px,1fr));
    gap:14px
}

@media(max-width:1100px){
    .form-grid-3{grid-template-columns:1fr 1fr}
}
@media(max-width:860px){
    .gen-sidebar{width:200px}
    .form-grid-4{grid-template-columns:1fr 1fr}
    .form-grid-3{grid-template-columns:1fr}
    .form-grid-2{grid-template-columns:1fr}
    .sql-kpis{grid-template-columns:1fr 1fr}
    .gen-stats .stat-pill span:last-child{display:none}
}
@media(max-width:660px){
    .gen-sidebar{display:none}
    .gen-wrapper{height:auto;min-height:calc(100vh - 60px)}
    .gen-main{overflow-y:unset}
    .form-grid-4{grid-template-columns:1fr}
}

/* ── Botones ─────────────────────────────────────────────── */
.btn{
    display:inline-flex;align-items:center;gap:6px;
    padding:8px 15px;border-radius:var(--border-radius,6px);
    font-size:.8rem;font-weight:600;cursor:pointer;
    border:none;transition:all .15s;white-space:nowrap;
    line-height:1.2
}
.btn:disabled{opacity:.5;cursor:not-allowed}
.btn-primary{background:var(--primary);color:#fff}
.btn-primary:hover:not(:disabled){background:#c0392b}
.btn-blue{background:#3b82f6;color:#fff}
.btn-blue:hover:not(:disabled){background:#2563eb}
.btn-green{background:#22c55e;color:#fff}
.btn-green:hover:not(:disabled){background:#16a34a}
.btn-yellow{background:#f59e0b;color:#fff}
.btn-yellow:hover:not(:disabled){background:#d97706}
.btn-danger{background:#ef4444;color:#fff}
.btn-danger:hover:not(:disabled){background:#dc2626}
.btn-ghost{
    background:var(--bg-surface-2);color:var(--text-secondary);
    border:1px solid var(--border-color)
}
.btn-ghost:hover:not(:disabled){
    background:var(--bg-surface-3);color:var(--text-primary)
}
.btn-xl{padding:11px 26px;font-size:.88rem}
.btn-group{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.pulse-btn{animation:pulse-btn 2.5s infinite}
@keyframes pulse-btn{
    0%,100%{box-shadow:0 0 0 0 rgba(230,57,70,.4)}
    50%{box-shadow:0 0 0 8px rgba(230,57,70,0)}
}

/* ── Tags / Badges ───────────────────────────────────────── */
.tag{
    display:inline-flex;align-items:center;gap:3px;
    padding:2px 8px;border-radius:20px;font-size:.67rem;font-weight:700
}
.tag-rss{background:rgba(245,158,11,.15);color:#f59e0b}
.tag-atom{background:rgba(59,130,246,.15);color:#3b82f6}
.tag-web{background:rgba(139,92,246,.15);color:#8b5cf6}
.tag-active{background:rgba(34,197,94,.15);color:#22c55e}
.tag-inactive{background:rgba(107,114,128,.15);color:#6b7280}
.tag-locked{background:rgba(245,158,11,.1);color:#f59e0b}
.tag-approved{background:rgba(34,197,94,.15);color:#22c55e}
.tag-pending{background:rgba(245,158,11,.15);color:#f59e0b}
.tag-discarded{background:rgba(239,68,68,.15);color:#ef4444}
.tag-insertada{background:rgba(59,130,246,.15);color:#3b82f6}

/* ── Tabla ───────────────────────────────────────────────── */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.data-table{
    width:100%;border-collapse:collapse;
    font-size:.8rem;min-width:600px
}
.data-table th{
    padding:10px 14px;text-align:left;
    font-size:.68rem;font-weight:700;text-transform:uppercase;
    letter-spacing:.05em;color:var(--text-muted);
    border-bottom:1px solid var(--border-color);white-space:nowrap
}
.data-table td{
    padding:10px 14px;
    border-bottom:1px solid rgba(128,128,128,.1);
    vertical-align:middle
}
.data-table tr:last-child td{border-bottom:none}
.data-table tr:hover td{background:var(--bg-surface-2)}
.action-btn{
    background:none;border:none;cursor:pointer;
    padding:5px 7px;border-radius:6px;
    color:var(--text-muted);font-size:.85rem;
    transition:all .15s
}
.action-btn:hover{background:var(--bg-surface-3);color:var(--text-primary)}
.action-btn.danger:hover{background:rgba(239,68,68,.1);color:#ef4444}

/* ── Toggles ─────────────────────────────────────────────── */
.toggle{
    position:relative;display:inline-block;
    width:38px;height:22px;flex-shrink:0
}
.toggle input{opacity:0;width:0;height:0;position:absolute}
.toggle-slider{
    position:absolute;cursor:pointer;inset:0;
    background:var(--bg-surface-3);border-radius:22px;transition:.3s
}
.toggle-slider:before{
    content:'';position:absolute;
    width:16px;height:16px;left:3px;bottom:3px;
    background:#fff;border-radius:50%;transition:.3s
}
.toggle input:checked+.toggle-slider{background:var(--primary)}
.toggle input:checked+.toggle-slider:before{transform:translateX(16px)}
.toggle-row{
    display:flex;align-items:center;gap:10px;
    font-size:.82rem;color:var(--text-secondary)
}

/* ── Segmented ───────────────────────────────────────────── */
.seg{
    display:flex;background:var(--bg-surface-2);
    border:1px solid var(--border-color);
    border-radius:var(--border-radius,6px);overflow:hidden;
    flex-wrap:nowrap
}
.seg button{
    flex:1;padding:6px 10px;background:none;border:none;
    font-size:.73rem;font-weight:600;color:var(--text-muted);
    cursor:pointer;transition:all .15s;white-space:nowrap
}
.seg button.active{background:var(--primary);color:#fff}
.seg button:hover:not(.active){
    background:var(--bg-surface-3);color:var(--text-primary)
}

/* ── Noticias Grid/List ──────────────────────────────────── */
.cards-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(280px,1fr));
    gap:16px
}
.news-card{
    background:var(--bg-surface);
    border:1px solid var(--border-color);
    border-radius:var(--border-radius-lg,10px);
    overflow:hidden;transition:all .2s;position:relative
}
.news-card:hover{
    border-color:var(--primary);
    box-shadow:0 4px 20px rgba(0,0,0,.12)
}
.news-card.approved{border-color:rgba(34,197,94,.4)}
.news-card.discarded{opacity:.5}
.news-card.insertada{border-color:rgba(59,130,246,.4)}
.news-card__img{
    height:130px;overflow:hidden;background:var(--bg-surface-2)
}
.news-card__img img{width:100%;height:100%;object-fit:cover}
.news-card__img-placeholder{
    display:flex;align-items:center;justify-content:center;
    height:100%;font-size:2rem;color:var(--text-muted)
}
.news-card__body{padding:12px}
.news-card__title{
    font-size:.84rem;font-weight:700;line-height:1.4;
    margin-bottom:8px;color:var(--text-primary);
    display:-webkit-box;-webkit-line-clamp:3;
    -webkit-box-orient:vertical;overflow:hidden
}
.news-card__meta{
    display:flex;align-items:center;gap:6px;
    flex-wrap:wrap;margin-bottom:8px
}
.news-card__source{font-size:.7rem;color:var(--text-muted)}
.news-card__date{font-size:.67rem;color:var(--text-muted);margin-left:auto}
.news-card__actions{
    display:flex;gap:5px;padding:8px 12px;
    border-top:1px solid var(--border-color);
    background:var(--bg-surface-2);flex-wrap:wrap
}
.news-card__checkbox{
    position:absolute;top:8px;left:8px;
    width:17px;height:17px;cursor:pointer;
    accent-color:var(--primary)
}
.news-list-item{
    display:flex;gap:12px;padding:12px 16px;
    border-bottom:1px solid var(--border-color);
    align-items:flex-start;transition:background .15s
}
.news-list-item:hover{background:var(--bg-surface-2)}
.news-list-item__thumb{
    width:76px;height:56px;border-radius:6px;
    overflow:hidden;flex-shrink:0;background:var(--bg-surface-3)
}
.news-list-item__thumb img{width:100%;height:100%;object-fit:cover}
.news-list-item__body{flex:1;min-width:0}
.news-list-item__title{
    font-size:.84rem;font-weight:700;color:var(--text-primary);
    margin-bottom:4px;display:-webkit-box;-webkit-line-clamp:2;
    -webkit-box-orient:vertical;overflow:hidden
}
.news-list-item__meta{
    display:flex;align-items:center;gap:8px;
    flex-wrap:wrap;font-size:.71rem;color:var(--text-muted)
}
.news-list-item__actions{
    display:flex;gap:5px;flex-shrink:0;align-items:flex-start
}

/* ── SQL Output ──────────────────────────────────────────── */
.sql-output{
    width:100%;
    font-family:'JetBrains Mono',monospace;
    font-size:.72rem;line-height:1.5;
    background:#0d1117;color:#c9d1d9;
    border:1px solid var(--border-color);
    border-radius:var(--border-radius-lg,10px);
    padding:16px;resize:vertical;
    min-height:300px;tab-size:2
}
.sql-kpis{
    display:grid;grid-template-columns:repeat(4,1fr);
    gap:12px;margin-bottom:20px
}
.kpi-card{
    background:var(--bg-surface);border:1px solid var(--border-color);
    border-radius:var(--border-radius-lg,10px);
    padding:16px;text-align:center
}
.kpi-card .kpi-val{
    font-size:1.7rem;font-weight:800;
    color:var(--primary);line-height:1
}
.kpi-card .kpi-lbl{font-size:.71rem;color:var(--text-muted);margin-top:4px}

/* ── SQL Historial ───────────────────────────────────────── */
.sql-hist-item{
    display:flex;align-items:center;gap:12px;
    padding:11px 16px;border-bottom:1px solid var(--border-color);
    font-size:.8rem
}
.sql-hist-item:hover{background:var(--bg-surface-2)}
.sql-hist-item:last-child{border-bottom:none}
.sql-hist-badge{
    padding:3px 8px;border-radius:20px;
    font-size:.64rem;font-weight:700;flex-shrink:0
}
.sql-hist-badge.guardado{background:rgba(245,158,11,.15);color:#f59e0b}
.sql-hist-badge.utilizado{background:rgba(34,197,94,.15);color:#22c55e}

/* ── Recopilación ────────────────────────────────────────── */
.collect-sources-list{
    display:flex;flex-direction:column;gap:6px;
    max-height:280px;overflow-y:auto;padding:2px
}
.collect-src-row{
    display:flex;align-items:center;gap:10px;
    padding:9px 12px;border:1px solid var(--border-color);
    border-radius:var(--border-radius,6px);cursor:pointer;
    transition:all .15s;background:var(--bg-surface);
    user-select:none
}
.collect-src-row:hover{
    border-color:var(--primary);background:var(--bg-surface-2)
}
.collect-src-row.selected{
    border-color:var(--primary);
    background:rgba(230,57,70,.05)
}
.collect-src-row input[type=checkbox]{
    accent-color:var(--primary);width:15px;height:15px;flex-shrink:0
}
.src-name{font-size:.82rem;font-weight:700;color:var(--text-primary)}
.src-url{
    font-size:.67rem;color:var(--text-muted);
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
    max-width:200px
}
.progress-bar{
    height:6px;background:var(--bg-surface-2);
    border-radius:6px;overflow:hidden;margin-top:16px;display:none
}
.progress-fill{
    height:100%;background:var(--primary);
    border-radius:6px;width:0;transition:width .4s
}
.import-log{
    margin-top:12px;font-size:.77rem;
    display:flex;flex-direction:column;gap:4px;
    max-height:160px;overflow-y:auto
}
.log-line{
    padding:4px 10px;border-radius:6px;
    background:var(--bg-surface-2)
}
.log-line.error{background:rgba(239,68,68,.1);color:#ef4444}
.log-line.ok{background:rgba(34,197,94,.07);color:var(--text-secondary)}
.advanced-toggle{
    background:none;border:none;color:var(--primary);
    font-size:.77rem;font-weight:600;cursor:pointer;
    padding:4px 0;display:flex;align-items:center;gap:6px
}
.advanced-panel{
    margin-top:12px;padding:14px;
    background:var(--bg-surface-2);
    border-radius:var(--border-radius,6px);
    border:1px solid var(--border-color)
}

/* ── Inserción directa ───────────────────────────────────── */
.insert-panel{
    background:rgba(34,197,94,.04);
    border:1px solid rgba(34,197,94,.25);
    border-radius:var(--border-radius-lg,10px);
    padding:20px;margin-top:20px
}
.insert-panel__title{
    font-size:.9rem;font-weight:700;color:#22c55e;
    margin-bottom:14px;display:flex;align-items:center;gap:8px
}
.insert-panel__grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(200px,1fr));
    gap:14px;margin-bottom:16px
}

/* ── Modales ─────────────────────────────────────────────── */
.modal-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,.55);
    z-index:1050;display:none;align-items:center;
    justify-content:center;padding:16px
}
.modal-overlay.open{display:flex}
.modal{
    background:var(--bg-surface);
    border-radius:var(--border-radius-xl,14px);
    border:1px solid var(--border-color);
    box-shadow:0 20px 60px rgba(0,0,0,.35);
    width:100%;max-height:90vh;overflow-y:auto;
    display:flex;flex-direction:column
}
.modal-header{
    padding:18px 22px;border-bottom:1px solid var(--border-color);
    display:flex;align-items:center;justify-content:space-between;
    flex-shrink:0;position:sticky;top:0;
    background:var(--bg-surface);z-index:1
}
.modal-title{
    font-size:.95rem;font-weight:700;color:var(--text-primary)
}
.modal-close{
    background:none;border:none;color:var(--text-muted);
    font-size:1.1rem;cursor:pointer;padding:5px;
    border-radius:6px;transition:all .15s;line-height:1
}
.modal-close:hover{
    background:var(--bg-surface-2);color:var(--text-primary)
}
.modal-body{padding:22px;flex:1}
.modal-footer{
    padding:14px 22px;border-top:1px solid var(--border-color);
    display:flex;justify-content:flex-end;gap:10px;
    flex-shrink:0;position:sticky;bottom:0;
    background:var(--bg-surface)
}
.form-section{margin-bottom:20px}
.form-section-title{
    font-size:.68rem;font-weight:700;text-transform:uppercase;
    letter-spacing:.08em;color:var(--text-muted);
    margin-bottom:12px;padding-bottom:6px;
    border-bottom:1px solid var(--border-color)
}

/* ── Toast ───────────────────────────────────────────────── */
.toast-container{
    position:fixed;bottom:22px;right:22px;z-index:2000;
    display:flex;flex-direction:column;gap:8px;pointer-events:none
}
.toast{
    display:flex;align-items:flex-start;gap:10px;
    background:var(--bg-surface);border:1px solid var(--border-color);
    border-radius:var(--border-radius-lg,10px);
    padding:12px 16px;box-shadow:0 8px 32px rgba(0,0,0,.25);
    min-width:240px;max-width:360px;pointer-events:all;
    animation:toastIn .3s ease;font-size:.82rem
}
.toast.out{animation:toastOut .3s ease forwards}
.toast-icon{font-size:1rem;flex-shrink:0;margin-top:1px}
.toast-title{font-weight:700;font-size:.79rem;margin-bottom:2px}
.toast-msg{color:var(--text-muted);font-size:.76rem;line-height:1.4}
.toast.success{border-left:3px solid #22c55e}
.toast.success .toast-icon{color:#22c55e}
.toast.error{border-left:3px solid #ef4444}
.toast.error .toast-icon{color:#ef4444}
.toast.warning{border-left:3px solid #f59e0b}
.toast.warning .toast-icon{color:#f59e0b}
.toast.info{border-left:3px solid #3b82f6}
.toast.info .toast-icon{color:#3b82f6}
@keyframes toastIn{
    from{opacity:0;transform:translateX(40px)}to{opacity:1;transform:none}
}
@keyframes toastOut{to{opacity:0;transform:translateX(40px)}}

/* ── Loader ──────────────────────────────────────────────── */
.loader-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,.6);
    z-index:3000;display:none;align-items:center;
    justify-content:center;flex-direction:column;gap:14px
}
.loader-overlay.visible{display:flex}
.spinner{
    width:42px;height:42px;
    border:4px solid rgba(255,255,255,.15);
    border-top-color:var(--primary);
    border-radius:50%;animation:spin .8s linear infinite
}
.loader-text{color:#fff;font-size:.84rem;font-weight:600}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Utilidades ──────────────────────────────────────────── */
.empty-state{
    text-align:center;padding:50px 20px;color:var(--text-muted)
}
.empty-state .icon{font-size:2.8rem;margin-bottom:10px}
.empty-state h3{
    font-size:.95rem;font-weight:700;
    color:var(--text-secondary);margin-bottom:5px
}
.empty-state p{font-size:.82rem}
.text-mono{font-family:'JetBrains Mono',monospace;font-size:.78rem}
.text-xs{font-size:.71rem}
.text-muted{color:var(--text-muted)}
.text-faint{color:var(--text-muted);font-size:.71rem}
.flex{display:flex}.gap-8{gap:8px}.mt-16{margin-top:16px}
.mb-16{margin-bottom:16px}

.help-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(240px,1fr));
    gap:14px
}
.help-card{
    background:var(--bg-surface-2);
    border:1px solid var(--border-color);
    border-radius:var(--border-radius-lg,10px);padding:14px
}
.help-card h4{
    font-size:.82rem;font-weight:700;
    margin-bottom:6px;color:var(--text-primary)
}
.help-card p{font-size:.75rem;color:var(--text-muted);line-height:1.6}
.kbd{
    display:inline-block;padding:1px 6px;
    background:var(--bg-surface-3);
    border:1px solid var(--border-color);
    border-radius:4px;font-family:'JetBrains Mono',monospace;font-size:.68rem
}
.counter-bar{
    display:flex;align-items:center;gap:10px;
    font-size:.77rem;color:var(--text-muted);flex-wrap:wrap
}
.filter-bar{
    display:flex;gap:8px;align-items:center;flex-wrap:wrap
}
</style>
</head>
<body>

<div class="admin-wrapper">

    <!-- ══ SIDEBAR ADMIN ══════════════════════════════════════ -->
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <!-- ══ CONTENIDO PRINCIPAL ═══════════════════════════════ -->
    <div class="admin-main">

        <!-- Topbar -->
        <div class="admin-topbar">
            <button class="admin-topbar__toggle"
                    onclick="toggleSidebar()"
                    aria-label="Menú">
                <i class="bi bi-list"></i>
            </button>
            <span class="admin-topbar__title">
                <i class="bi bi-robot"
                   style="color:var(--primary);font-size:1rem"></i>
                Generador de Noticias
            </span>
            <a href="<?= APP_URL ?>/admin/dashboard.php"
               class="btn btn-ghost"
               style="font-size:.75rem;padding:6px 12px">
                <i class="bi bi-arrow-left"></i>
                <span>Panel Admin</span>
            </a>
        </div>

        <!-- Generador SPA -->
        <div class="admin-content">
        <div class="gen-wrapper">

            <!-- ════════════════════════════════
                 SIDEBAR DEL GENERADOR
            ════════════════════════════════ -->
            <aside class="gen-sidebar">
                <div class="gen-sidebar-logo">
                    <div style="font-size:.68rem;font-weight:700;
                                text-transform:uppercase;letter-spacing:.08em;
                                color:var(--text-muted)">El Click RD</div>
                    <div style="font-size:.85rem;font-weight:800;
                                color:var(--text-primary);margin-top:2px">
                        Generador
                    </div>
                    <div style="margin-top:6px">
                        <span class="db-pill">lnuazoql_elclickrdv2</span>
                    </div>
                </div>

                <nav class="gen-nav">
                    <button class="gen-nav-item active"
                            data-section="fuentes">
                        <i class="bi bi-broadcast"></i>
                        <span>Fuentes</span>
                        <span class="badge" id="badge-fuentes">0</span>
                    </button>
                    <button class="gen-nav-item"
                            data-section="recopilar">
                        <i class="bi bi-search"></i>
                        <span>Recopilar</span>
                    </button>
                    <button class="gen-nav-item"
                            data-section="noticias">
                        <i class="bi bi-newspaper"></i>
                        <span>Noticias</span>
                        <span class="badge yellow"
                              id="badge-noticias">0</span>
                    </button>
                    <button class="gen-nav-item"
                            data-section="sql">
                        <i class="bi bi-database-fill-gear"></i>
                        <span>SQL Export</span>
                        <span class="badge green"
                              id="badge-aprobadas">0</span>
                    </button>
                    <button class="gen-nav-item"
                            data-section="config">
                        <i class="bi bi-sliders"></i>
                        <span>Configuración</span>
                    </button>
                </nav>

                <div class="gen-sidebar-footer">
                    Target DB: <strong>lnuazoql_elclickrdv2</strong><br>
                    Tabla: <strong>noticias</strong><br>
                    Usuario: <?= e($usuario['nombre'] ?? '') ?>
                </div>
            </aside>

            <!-- ════════════════════════════════
                 ÁREA PRINCIPAL
            ════════════════════════════════ -->
            <div class="gen-main" id="gen-main">

                <!-- Header interno -->
                <div class="gen-header">
                    <div class="gen-breadcrumb">
                        <span>El Click RD</span>
                        <span class="gen-breadcrumb-sep">/</span>
                        <span class="gen-breadcrumb-current"
                              id="breadcrumb-cur">Fuentes</span>
                    </div>
                    <div class="gen-stats">
                        <div class="stat-pill">
                            <span class="dot"></span>
                            <span id="hdr-fuentes">0</span>
                            <span> fuentes</span>
                        </div>
                        <div class="stat-pill yellow">
                            <span class="dot"
                                  style="background:#f59e0b"></span>
                            <span id="hdr-buffer">0</span>
                            <span> buffer</span>
                        </div>
                        <div class="stat-pill green">
                            <span class="dot"></span>
                            <span id="hdr-aprobadas">0</span>
                            <span> aprobadas</span>
                        </div>
                        <button class="btn btn-ghost"
                                style="padding:3px 10px;font-size:.72rem"
                                onclick="openModal('modal-help')">
                            <i class="bi bi-question-circle"></i>
                            <span>Ayuda</span>
                        </button>
                    </div>
                </div>

                <!-- ════ PÁGINAS ════ -->
                <div class="gen-body">

                <!-- ╔══════════════════════════════════════════
                     ║ FUENTES
                     ╚════════════════════════════════════════ -->
                <div class="gen-page active" id="page-fuentes">
                    <div style="display:flex;align-items:flex-start;
                                justify-content:space-between;
                                margin-bottom:20px;flex-wrap:wrap;gap:10px">
                        <div>
                            <h1 style="font-size:1.15rem;font-weight:800;
                                       margin:0;display:flex;
                                       align-items:center;gap:8px">
                                <i class="bi bi-broadcast"
                                   style="color:var(--primary)"></i>
                                Gestión de Fuentes
                            </h1>
                            <p style="font-size:.8rem;color:var(--text-muted);
                                      margin:4px 0 0">
                                Configura los periódicos digitales y feeds
                                desde los que recopilarás noticias.
                            </p>
                        </div>
                    </div>

                    <!-- Formulario fuente -->
                    <div class="panel mb-16">
                        <div class="panel-header">
                            <div>
                                <h2 id="src-form-title">
                                    Agregar nueva fuente
                                </h2>
                                <div class="sub">
                                    RSS, Atom o página HTML — hasta 100 fuentes
                                </div>
                            </div>
                        </div>
                        <div class="panel-body">
                            <input type="hidden"
                                   id="src-edit-id" value="0">
                            <!-- Fila 1 -->
                            <div class="form-grid-4"
                                 style="margin-bottom:14px">
                                <div class="field">
                                    <label class="field-label">
                                        Nombre del periódico
                                    </label>
                                    <input class="input" id="src-name"
                                           placeholder="Ej: Diario Libre" />
                                </div>
                                <div class="field">
                                    <label class="field-label">
                                        URL de la fuente
                                    </label>
                                    <input class="input" id="src-url"
                                           placeholder="https://ejemplo.com/rss.xml" />
                                </div>
                                <div class="field">
                                    <label class="field-label">Tipo</label>
                                    <select class="select" id="src-type">
                                        <option value="rss">RSS / XML</option>
                                        <option value="atom">Atom</option>
                                        <option value="web">Página Web</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label class="field-label">
                                        País / Región
                                    </label>
                                    <select class="select" id="src-pais">
                                        <option value="RD">República Dominicana</option>
                                        <option value="ES">España</option>
                                        <option value="US">Estados Unidos</option>
                                        <option value="LATAM">Latinoamérica</option>
                                        <option value="INT">Internacional</option>
                                        <option value="MX">México</option>
                                        <option value="CO">Colombia</option>
                                        <option value="VE">Venezuela</option>
                                        <option value="PR">Puerto Rico</option>
                                        <option value="AR">Argentina</option>
                                    </select>
                                </div>
                            </div>
                            <!-- Fila 2 -->
                            <div class="form-grid-4">
                                <div class="field">
                                    <label class="field-label">
                                        Categoría por defecto
                                    </label>
                                    <select class="select"
                                            id="src-categoria">
                                        <?php foreach ($categorias as $cat): ?>
                                        <option value="<?= $cat['id'] ?>">
                                            <?= e($cat['nombre']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label class="field-label">
                                        Autor por defecto
                                    </label>
                                    <select class="select" id="src-autor">
                                        <?php foreach ($autores as $au): ?>
                                        <option value="<?= $au['id'] ?>">
                                            <?= e($au['nombre']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label class="field-label">
                                        Estado
                                    </label>
                                    <div class="toggle-row"
                                         style="margin-top:8px">
                                        <label class="toggle">
                                            <input type="checkbox"
                                                   id="src-activa"
                                                   checked>
                                            <span class="toggle-slider"></span>
                                        </label>
                                        <label for="src-activa">
                                            Activa
                                        </label>
                                    </div>
                                </div>
                                <div class="field">
                                    <label class="field-label"
                                           style="visibility:hidden">
                                        .
                                    </label>
                                    <div class="btn-group">
                                        <button class="btn btn-blue"
                                                id="btn-save-fuente">
                                            <i class="bi bi-plus-lg"></i>
                                            Agregar Fuente
                                        </button>
                                        <button class="btn btn-ghost"
                                                id="btn-cancel-edit-fuente"
                                                style="display:none"
                                                onclick="cancelarEditFuente()">
                                            <i class="bi bi-x"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tabla fuentes -->
                    <div class="panel">
                        <div class="panel-header">
                            <div>
                                <h2>Fuentes registradas</h2>
                                <div class="sub"
                                     id="sources-count-text">
                                    Cargando...
                                </div>
                            </div>
                            <div class="btn-group">
                                <button class="btn btn-ghost"
                                        id="btn-test-all">
                                    <i class="bi bi-wifi"></i>
                                    Probar todas
                                </button>
                            </div>
                        </div>
                        <div class="panel-body flush">
                            <div class="table-wrap">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Nombre</th>
                                            <th>URL</th>
                                            <th>Tipo</th>
                                            <th>Categoría</th>
                                            <th>Estado</th>
                                            <th>Última imp.</th>
                                            <th style="text-align:right">
                                                Acciones
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody id="sources-tbody">
                                        <tr>
                                            <td colspan="7"
                                                style="text-align:center;
                                                       padding:30px;
                                                       color:var(--text-muted)">
                                                Cargando fuentes...
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div><!-- /page-fuentes -->

                <!-- ╔══════════════════════════════════════════
                     ║ RECOPILAR
                     ╚════════════════════════════════════════ -->
                <div class="gen-page" id="page-recopilar">
                    <div style="margin-bottom:20px">
                        <h1 style="font-size:1.15rem;font-weight:800;
                                   margin:0;display:flex;
                                   align-items:center;gap:8px">
                            <i class="bi bi-search"
                               style="color:var(--primary)"></i>
                            Panel de Recopilación
                        </h1>
                        <p style="font-size:.8rem;color:var(--text-muted);
                                  margin:4px 0 0">
                            El servidor realiza el fetch directamente
                            a los feeds RSS/Atom/Web, sin proxies CORS.
                        </p>
                    </div>

                    <div class="form-grid-2" style="margin-bottom:20px">
                        <!-- Parámetros -->
                        <div class="panel">
                            <div class="panel-header">
                                <h2>⚙️ Parámetros</h2>
                            </div>
                            <div class="panel-body">
                                <div class="form-grid-2"
                                     style="margin-bottom:16px">
                                    <div class="field">
                                        <label class="field-label">
                                            Rango temporal
                                        </label>
                                        <div class="seg" id="seg-horas">
                                            <button data-val="24"
                                                    class="active">24h</button>
                                            <button data-val="48">48h</button>
                                            <button data-val="72">72h</button>
                                            <button data-val="168">7d</button>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="field-label">
                                            Máx. por feed
                                        </label>
                                        <input class="input" type="number"
                                               id="param-max-feed"
                                               value="30" min="1" max="100" />
                                        <div class="field-hint">
                                            Máximo 100 por feed
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="field-label">
                                            Imagen
                                        </label>
                                        <div class="seg" id="seg-img">
                                            <button data-val="any"
                                                    class="active">
                                                Indiferente
                                            </button>
                                            <button data-val="yes">Sí</button>
                                            <button data-val="no">No</button>
                                        </div>
                                    </div>
                                    <div class="field">
                                        <label class="field-label">
                                            Idioma
                                        </label>
                                        <div class="seg" id="seg-lang">
                                            <button data-val="es"
                                                    class="active">
                                                Español
                                            </button>
                                            <button data-val="any">
                                                Cualquiera
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <button class="advanced-toggle"
                                        id="advanced-toggle">
                                    <span id="adv-chev">▶</span>
                                    Filtros avanzados
                                </button>
                                <div class="advanced-panel"
                                     id="advanced-panel"
                                     style="display:none">
                                    <div class="form-grid-2">
                                        <div class="field">
                                            <label class="field-label">
                                                Incluir palabras clave
                                            </label>
                                            <input class="input"
                                                   id="param-include"
                                                   placeholder="economía, política..." />
                                            <div class="field-hint">
                                                Separadas por coma
                                            </div>
                                        </div>
                                        <div class="field">
                                            <label class="field-label">
                                                Excluir palabras clave
                                            </label>
                                            <input class="input"
                                                   id="param-exclude"
                                                   placeholder="rumor, viral..." />
                                            <div class="field-hint">
                                                Separadas por coma
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Fuentes -->
                        <div class="panel">
                            <div class="panel-header">
                                <div>
                                    <h2>📡 Fuentes</h2>
                                    <div class="sub"
                                         id="collect-src-sub">
                                        0 seleccionadas
                                    </div>
                                </div>
                                <div class="btn-group">
                                    <button class="btn btn-ghost"
                                            style="font-size:.71rem;padding:5px 10px"
                                            id="btn-sel-all-src">
                                        Todas
                                    </button>
                                    <button class="btn btn-ghost"
                                            style="font-size:.71rem;padding:5px 10px"
                                            id="btn-desel-all-src">
                                        Ninguna
                                    </button>
                                </div>
                            </div>
                            <div class="panel-body">
                                <div class="collect-sources-list"
                                     id="collect-sources-list">
                                    <div style="text-align:center;
                                                padding:20px;
                                                color:var(--text-muted)">
                                        Cargando...
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-body"
                             style="text-align:center;padding:24px">
                            <button class="btn btn-primary btn-xl pulse-btn"
                                    id="btn-collect">
                                🚀 INICIAR RECOPILACIÓN
                            </button>
                            <div class="progress-bar"
                                 id="progress-bar">
                                <div class="progress-fill"
                                     id="progress-fill"></div>
                            </div>
                            <div class="import-log"
                                 id="import-log"></div>
                        </div>
                    </div>
                </div><!-- /page-recopilar -->

                <!-- ╔══════════════════════════════════════════
                     ║ NOTICIAS
                     ╚════════════════════════════════════════ -->
                <div class="gen-page" id="page-noticias">
                    <div style="margin-bottom:16px">
                        <h1 style="font-size:1.15rem;font-weight:800;
                                   margin:0;display:flex;
                                   align-items:center;gap:8px">
                            <i class="bi bi-newspaper"
                               style="color:var(--primary)"></i>
                            Noticias recopiladas
                        </h1>
                        <p style="font-size:.8rem;color:var(--text-muted);
                                  margin:4px 0 0">
                            Revisa, edita, aprueba e inserta las noticias
                            directamente a la base de datos.
                        </p>
                    </div>

                    <!-- Filtros -->
                    <div class="panel mb-16">
                        <div class="panel-body"
                             style="padding:12px 16px">
                            <div class="filter-bar">
                                <input class="input"
                                       id="news-search"
                                       placeholder="🔍 Buscar por título..."
                                       style="max-width:260px" />
                                <select class="select"
                                        id="news-filter-estado"
                                        style="max-width:160px">
                                    <option value="">
                                        Todos los estados
                                    </option>
                                    <option value="pendiente">
                                        ⏳ Pendientes
                                    </option>
                                    <option value="aprobada">
                                        ✅ Aprobadas
                                    </option>
                                    <option value="descartada">
                                        🗑️ Descartadas
                                    </option>
                                    <option value="insertada">
                                        📥 Insertadas
                                    </option>
                                </select>
                                <select class="select"
                                        id="news-filter-fuente"
                                        style="max-width:180px">
                                    <option value="0">
                                        Todas las fuentes
                                    </option>
                                </select>
                                <div class="seg" id="seg-view"
                                     style="margin-left:auto">
                                    <button data-val="cards"
                                            class="active">⊞</button>
                                    <button data-val="list">☰</button>
                                </div>
                            </div>
                            <div style="display:flex;gap:8px;
                                        align-items:center;
                                        flex-wrap:wrap;
                                        margin-top:10px">
                                <div class="counter-bar"
                                     id="news-counter">
                                    0 noticias
                                </div>
                                <div style="margin-left:auto;
                                            display:flex;gap:6px">
                                    <button class="btn btn-ghost"
                                            style="font-size:.72rem;padding:5px 10px"
                                            id="btn-sel-all-news">
                                        ☑ Todas
                                    </button>
                                    <button class="btn btn-ghost"
                                            style="font-size:.72rem;padding:5px 10px"
                                            id="btn-desel-all-news">
                                        ☐ Ninguna
                                    </button>
                                </div>
                            </div>
                            <!-- Bulk actions -->
                            <div id="bulk-actions"
                                 style="display:none;margin-top:10px;
                                        padding:10px;
                                        background:rgba(230,57,70,.05);
                                        border:1px solid rgba(230,57,70,.2);
                                        border-radius:var(--border-radius)">
                                <span style="font-size:.77rem;font-weight:700;
                                             color:var(--primary);
                                             margin-right:8px">
                                    Acciones seleccionadas:
                                </span>
                                <div class="btn-group"
                                     style="display:inline-flex">
                                    <button class="btn btn-ghost"
                                            style="font-size:.71rem"
                                            onclick="bulkEstado('aprobada')">
                                        ✅ Aprobar
                                    </button>
                                    <button class="btn btn-ghost"
                                            style="font-size:.71rem"
                                            onclick="bulkEstado('descartada')">
                                        🗑️ Descartar
                                    </button>
                                    <button class="btn btn-ghost"
                                            style="font-size:.71rem"
                                            onclick="bulkEstado('pendiente')">
                                        ⏳ Pendiente
                                    </button>
                                    <button class="btn btn-danger"
                                            style="font-size:.71rem"
                                            onclick="bulkEliminar()">
                                        🗑 Eliminar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contenedor noticias -->
                    <div id="news-container"></div>

                    <!-- Paginación -->
                    <div id="news-pagination"
                         style="display:flex;justify-content:center;
                                gap:6px;margin-top:20px;flex-wrap:wrap">
                    </div>

                    <!-- Panel inserción directa -->
                    <div class="insert-panel" id="insert-panel">
                        <div class="insert-panel__title">
                            <i class="bi bi-database-fill-add"></i>
                            Insertar noticias directamente a la BD
                        </div>
                        <p style="font-size:.78rem;color:var(--text-secondary);
                                  margin-bottom:16px">
                            Selecciona las noticias arriba y configura las
                            opciones. Se insertarán en la tabla
                            <code style="background:var(--bg-surface-2);
                                         padding:1px 6px;border-radius:4px;
                                         font-family:'JetBrains Mono',monospace">
                                noticias
                            </code>
                            sin phpMyAdmin.
                        </p>
                        <div class="insert-panel__grid">
                            <div class="field">
                                <label class="field-label">
                                    Estado de publicación
                                </label>
                                <select class="select" id="ins-estado">
                                    <option value="publicado">✅ Publicado</option>
                                    <option value="borrador" selected>
                                        📝 Borrador
                                    </option>
                                    <option value="programado">
                                        🕐 Programado
                                    </option>
                                    <option value="archivado">
                                        📦 Archivado
                                    </option>
                                </select>
                            </div>
                            <div class="field">
                                <label class="field-label">
                                    Categoría (override)
                                </label>
                                <select class="select" id="ins-categoria">
                                    <option value="0">
                                        — Usar la de cada noticia —
                                    </option>
                                    <?php foreach ($categorias as $cat): ?>
                                    <option value="<?= $cat['id'] ?>">
                                        <?= e($cat['nombre']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label class="field-label">
                                    Autor (override)
                                </label>
                                <select class="select" id="ins-autor">
                                    <option value="0">
                                        — Usar el de cada noticia —
                                    </option>
                                    <?php foreach ($autores as $au): ?>
                                    <option value="<?= $au['id'] ?>">
                                        <?= e($au['nombre']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="btn-group">
                            <button class="btn btn-green btn-xl"
                                    id="btn-insertar-seleccionadas">
                                <i class="bi bi-database-fill-add"></i>
                                Insertar Seleccionadas
                            </button>
                            <button class="btn btn-blue btn-xl"
                                    id="btn-insertar-aprobadas">
                                <i class="bi bi-check2-all"></i>
                                Insertar Todas Aprobadas
                            </button>
                            <button class="btn btn-ghost"
                                    onclick="navigate('sql')">
                                <i class="bi bi-code-square"></i>
                                Exportar SQL
                            </button>
                        </div>
                        <div id="ins-resultado"
                             style="margin-top:12px;font-size:.8rem"></div>
                    </div>
                </div><!-- /page-noticias -->

                <!-- ╔══════════════════════════════════════════
                     ║ SQL EXPORT
                     ╚════════════════════════════════════════ -->
                <div class="gen-page" id="page-sql">
                    <div style="margin-bottom:20px">
                        <h1 style="font-size:1.15rem;font-weight:800;
                                   margin:0;display:flex;
                                   align-items:center;gap:8px">
                            <i class="bi bi-database-fill-gear"
                               style="color:var(--primary)"></i>
                            Exportar SQL
                        </h1>
                        <p style="font-size:.8rem;color:var(--text-muted);
                                  margin:4px 0 0">
                            Genera el INSERT INTO listo para phpMyAdmin/MySQL.
                        </p>
                    </div>

                    <!-- KPIs -->
                    <div class="sql-kpis">
                        <div class="kpi-card">
                            <div class="kpi-val" id="kpi-total">0</div>
                            <div class="kpi-lbl">Total buffer</div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-val" id="kpi-aprobadas"
                                 style="color:#22c55e">0</div>
                            <div class="kpi-lbl">Aprobadas</div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-val" id="kpi-pendientes"
                                 style="color:#f59e0b">0</div>
                            <div class="kpi-lbl">Pendientes</div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-val" id="kpi-insertadas"
                                 style="color:#3b82f6">0</div>
                            <div class="kpi-lbl">Insertadas</div>
                        </div>
                    </div>

                    <!-- Opciones SQL -->
                    <div class="panel mb-16">
                        <div class="panel-header">
                            <h2>⚙️ Opciones del SQL</h2>
                        </div>
                        <div class="panel-body">
                            <div class="form-grid-3"
                                 style="margin-bottom:16px">
                                <div class="field">
                                    <label class="field-label">
                                        Incluir en el SQL
                                    </label>
                                    <div class="seg" id="sql-scope">
                                        <button data-val="aprobadas"
                                                class="active">
                                            ✅ Aprobadas
                                        </button>
                                        <button data-val="todas">
                                            📋 Todas
                                        </button>
                                        <button data-val="seleccionadas">
                                            ☑ Selec.
                                        </button>
                                    </div>
                                </div>
                                <div class="field">
                                    <label class="field-label">
                                        Estado en BD
                                    </label>
                                    <select class="select" id="sql-estado">
                                        <option value="publicado">
                                            ✅ Publicado
                                        </option>
                                        <option value="borrador" selected>
                                            📝 Borrador
                                        </option>
                                        <option value="programado">
                                            🕐 Programado
                                        </option>
                                        <option value="archivado">
                                            📦 Archivado
                                        </option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label class="field-label">
                                        Fecha de publicación
                                    </label>
                                    <div class="seg" id="sql-fecha">
                                        <button data-val="original"
                                                class="active">
                                            Original
                                        </button>
                                        <button data-val="ahora">
                                            Ahora
                                        </button>
                                    </div>
                                </div>
                                <div class="field">
                                    <label class="field-label">
                                        Categoría (override)
                                    </label>
                                    <select class="select"
                                            id="sql-cat-override">
                                        <option value="0">
                                            — Usar la de cada noticia —
                                        </option>
                                        <?php foreach ($categorias as $cat): ?>
                                        <option value="<?= $cat['id'] ?>">
                                            <?= e($cat['nombre']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label class="field-label">
                                        Autor (override)
                                    </label>
                                    <select class="select"
                                            id="sql-autor-override">
                                        <option value="0">
                                            — Usar el de cada noticia —
                                        </option>
                                        <?php foreach ($autores as $au): ?>
                                        <option value="<?= $au['id'] ?>">
                                            <?= e($au['nombre']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label class="field-label">
                                        Opciones
                                    </label>
                                    <div style="display:flex;
                                                flex-direction:column;gap:8px">
                                        <div class="toggle-row">
                                            <label class="toggle">
                                                <input type="checkbox"
                                                       id="sql-include-header"
                                                       checked>
                                                <span class="toggle-slider"></span>
                                            </label>
                                            <label for="sql-include-header">
                                                Encabezado SQL
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="btn-group">
                                <button class="btn btn-primary btn-xl"
                                        id="btn-generate-sql">
                                    <i class="bi bi-code-slash"></i>
                                    Generar SQL
                                </button>
                                <button class="btn btn-ghost"
                                        id="btn-copy-sql">
                                    <i class="bi bi-clipboard"></i>
                                    Copiar
                                </button>
                                <button class="btn btn-ghost"
                                        id="btn-download-sql">
                                    <i class="bi bi-download"></i>
                                    Descargar .sql
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Output SQL -->
                    <div class="panel mb-16">
                        <div class="panel-header">
                            <div>
                                <h2>Resultado SQL</h2>
                                <div class="sub"
                                     id="sql-regen-info">
                                    Genera primero
                                </div>
                            </div>
                            <button class="btn btn-ghost"
                                    style="font-size:.74rem"
                                    id="btn-regen-sql">
                                🔄 Regenerar
                            </button>
                        </div>
                        <div class="panel-body">
                            <textarea class="sql-output"
                                      id="sql-output"
                                      readonly
                                      placeholder="-- El SQL generado aparecerá aquí...
-- Haz clic en 'Generar SQL' para comenzar."></textarea>
                        </div>
                    </div>

                    <!-- Historial SQL -->
                    <div class="panel">
                        <div class="panel-header">
                            <div>
                                <h2>📋 Historial de SQL generados</h2>
                                <div class="sub"
                                     id="sql-hist-info">
                                    Últimos 20 scripts
                                </div>
                            </div>
                            <button class="btn btn-ghost"
                                    style="font-size:.74rem"
                                    onclick="cargarHistorialSql()">
                                🔄 Actualizar
                            </button>
                        </div>
                        <div class="panel-body flush">
                            <div id="sql-historial-container">
                                <div style="text-align:center;padding:28px;
                                            color:var(--text-muted)">
                                    Cargando historial...
                                </div>
                            </div>
                        </div>
                    </div>
                </div><!-- /page-sql -->

                <!-- ╔══════════════════════════════════════════
                     ║ CONFIGURACIÓN
                     ╚════════════════════════════════════════ -->
                <div class="gen-page" id="page-config">
                    <div style="margin-bottom:20px">
                        <h1 style="font-size:1.15rem;font-weight:800;
                                   margin:0;display:flex;
                                   align-items:center;gap:8px">
                            <i class="bi bi-sliders"
                               style="color:var(--primary)"></i>
                            Configuración del Generador
                        </h1>
                        <p style="font-size:.8rem;color:var(--text-muted);
                                  margin:4px 0 0">
                            Parámetros del sistema de recopilación.
                            Se guardan en la base de datos.
                        </p>
                    </div>

                    <!-- General -->
                    <div class="panel mb-16">
                        <div class="panel-header">
                            <h2>🌐 Configuración general</h2>
                        </div>
                        <div class="panel-body">
                            <div class="form-grid-2">
                                <div class="field">
                                    <label class="field-label">
                                        Nombre del sitio (headers SQL)
                                    </label>
                                    <input class="input"
                                           id="cfg-sitename"
                                           value="<?= e($cfgGen['sitename']) ?>" />
                                </div>
                                <div class="field">
                                    <label class="field-label">
                                        Estado por defecto al insertar
                                    </label>
                                    <select class="select"
                                            id="cfg-estado-defecto">
                                        <option value="publicado"
                                            <?= $cfgGen['estado_defecto']==='publicado'?'selected':'' ?>>
                                            ✅ Publicado
                                        </option>
                                        <option value="borrador"
                                            <?= $cfgGen['estado_defecto']==='borrador'?'selected':'' ?>>
                                            📝 Borrador
                                        </option>
                                        <option value="programado"
                                            <?= $cfgGen['estado_defecto']==='programado'?'selected':'' ?>>
                                            🕐 Programado
                                        </option>
                                        <option value="archivado"
                                            <?= $cfgGen['estado_defecto']==='archivado'?'selected':'' ?>>
                                            📦 Archivado
                                        </option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label class="field-label">
                                        Autor por defecto
                                    </label>
                                    <select class="select" id="cfg-autor">
                                        <?php foreach ($autores as $au): ?>
                                        <option value="<?= $au['id'] ?>"
                                            <?= $cfgGen['autor_defecto']==$au['id']?'selected':'' ?>>
                                            <?= e($au['nombre']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label class="field-label">
                                        Categoría por defecto
                                    </label>
                                    <select class="select"
                                            id="cfg-categoria">
                                        <?php foreach ($categorias as $cat): ?>
                                        <option value="<?= $cat['id'] ?>"
                                            <?= $cfgGen['categoria_defecto']==$cat['id']?'selected':'' ?>>
                                            <?= e($cat['nombre']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Parámetros recopilación -->
                    <div class="panel mb-16">
                        <div class="panel-header">
                            <h2>🔧 Parámetros de recopilación</h2>
                        </div>
                        <div class="panel-body">
                            <div class="form-grid-2">
                                <div class="field">
                                    <label class="field-label">
                                        Timeout ·
                                        <span id="cfg-timeout-val">
                                            <?= e($cfgGen['timeout']) ?>s
                                        </span>
                                    </label>
                                    <input type="range" id="cfg-timeout"
                                           min="5" max="60"
                                           value="<?= (int)$cfgGen['timeout'] ?>"
                                           style="width:100%" />
                                </div>
                                <div class="field">
                                    <label class="field-label">
                                        Máximo en buffer
                                    </label>
                                    <input class="input" type="number"
                                           id="cfg-max"
                                           value="<?= (int)$cfgGen['max_noticias'] ?>"
                                           min="50" max="5000" />
                                </div>
                                <div class="toggle-row">
                                    <label class="toggle">
                                        <input type="checkbox"
                                               id="cfg-dedupe"
                                            <?= $cfgGen['dedupe']?'checked':'' ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                    <label for="cfg-dedupe">
                                        Filtrar duplicados automáticamente
                                    </label>
                                </div>
                                <div class="toggle-row">
                                    <label class="toggle">
                                        <input type="checkbox"
                                               id="cfg-autoclean"
                                            <?= $cfgGen['autoclean']?'checked':'' ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                    <label for="cfg-autoclean">
                                        Auto-limpiar pendientes al recopilar
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- CORS Proxies -->
                    <div class="panel mb-16">
                        <div class="panel-header">
                            <div>
                                <h2>🔀 CORS Proxies (referencia)</h2>
                                <div class="sub">
                                    PHP no los necesita; guardados por
                                    compatibilidad con versiones HTML.
                                </div>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div class="form-grid-3">
                                <div class="field">
                                    <label class="field-label">
                                        Proxy primario
                                    </label>
                                    <input class="input text-mono"
                                           id="cfg-proxy1"
                                           value="<?= e($cfgGen['proxy1']) ?>" />
                                </div>
                                <div class="field">
                                    <label class="field-label">
                                        Proxy secundario
                                    </label>
                                    <input class="input text-mono"
                                           id="cfg-proxy2"
                                           value="<?= e($cfgGen['proxy2']) ?>" />
                                </div>
                                <div class="field">
                                    <label class="field-label">
                                        Proxy terciario
                                    </label>
                                    <input class="input text-mono"
                                           id="cfg-proxy3"
                                           value="<?= e($cfgGen['proxy3']) ?>" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Guardar / Export -->
                    <div class="panel mb-16">
                        <div class="panel-header">
                            <h2>💾 Guardar / Exportar</h2>
                        </div>
                        <div class="panel-body">
                            <div class="btn-group">
                                <button class="btn btn-primary"
                                        id="btn-save-cfg">
                                    💾 Guardar configuración
                                </button>
                                <button class="btn btn-ghost"
                                        id="btn-export-cfg">
                                    📥 Exportar JSON
                                </button>
                                <button class="btn btn-ghost"
                                        id="btn-import-cfg">
                                    📤 Importar JSON
                                </button>
                                <input type="file" id="file-import-cfg"
                                       accept=".json" style="display:none" />
                            </div>
                        </div>
                    </div>

                    <!-- Zona peligrosa -->
                    <div class="panel"
                         style="border-color:rgba(239,68,68,.3)">
                        <div class="panel-header">
                            <div>
                                <h2 style="color:#ef4444">
                                    ⚠️ Zona peligrosa
                                </h2>
                                <div class="sub">
                                    Acciones irreversibles
                                </div>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div class="btn-group">
                                <button class="btn btn-danger"
                                        id="btn-limpiar-buffer">
                                    🗑️ Limpiar buffer de noticias
                                </button>
                                <button class="btn btn-danger"
                                        id="btn-limpiar-sql-hist">
                                    🗑️ Limpiar historial SQL
                                </button>
                            </div>
                        </div>
                    </div>
                </div><!-- /page-config -->

                </div><!-- /gen-body -->
            </div><!-- /gen-main -->
        </div><!-- /gen-wrapper -->
        </div><!-- /admin-content -->
    </div><!-- /admin-main -->
</div><!-- /admin-wrapper -->

<!-- ════════════════════════════════════════════════════════
     MODAL: EDITAR NOTICIA
════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-edit">
<div class="modal" style="max-width:920px">
    <div class="modal-header">
        <div class="modal-title">
            <i class="bi bi-pencil-square"
               style="color:var(--primary)"></i>
            Editar noticia
        </div>
        <button class="modal-close"
                onclick="closeModal('modal-edit')">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <div class="modal-body">
        <input type="hidden" id="ed-id" value="0">

        <!-- Contenido principal -->
        <div class="form-section">
            <div class="form-section-title">
                Contenido principal
            </div>
            <div class="form-grid-2" style="gap:14px">
                <!-- Título: span 2 -->
                <div class="field" style="grid-column:span 2">
                    <label class="field-label">
                        Título
                        <span id="cnt-titulo"
                              class="text-faint">0/300</span>
                    </label>
                    <input class="input" id="ed-titulo"
                           maxlength="300"
                           oninput="document.getElementById('cnt-titulo').textContent=this.value.length+'/300'" />
                </div>
                <!-- Slug: span 2 -->
                <div class="field" style="grid-column:span 2">
                    <label class="field-label">
                        Slug
                        <span id="cnt-slug"
                              class="text-faint">0/320</span>
                    </label>
                    <div class="input-row">
                        <input class="input text-mono" id="ed-slug"
                               maxlength="320"
                               oninput="document.getElementById('cnt-slug').textContent=this.value.length+'/320'" />
                        <button class="btn btn-ghost"
                                onclick="regenSlug()"
                                title="Regenerar desde título">
                            🔄
                        </button>
                    </div>
                </div>
                <!-- Resumen: span 2 -->
                <div class="field" style="grid-column:span 2">
                    <label class="field-label">
                        Resumen
                        <span id="cnt-resumen"
                              class="text-faint">0/500</span>
                    </label>
                    <textarea class="textarea" id="ed-resumen"
                              rows="3" maxlength="500"
                              oninput="document.getElementById('cnt-resumen').textContent=this.value.length+'/500'"></textarea>
                </div>
                <!-- Contenido: span 2 — SIN LÍMITE -->
                <div class="field" style="grid-column:span 2">
                    <label class="field-label">
                        Contenido
                        <span class="text-faint"
                              style="font-weight:400">
                            (sin límite de caracteres)
                        </span>
                    </label>
                    <textarea class="textarea"
                              id="ed-contenido"
                              rows="12"
                              style="min-height:220px;font-size:.82rem;line-height:1.6"></textarea>
                </div>
                <!-- Imagen -->
                <div class="field">
                    <label class="field-label">
                        Imagen URL
                    </label>
                    <input class="input text-mono"
                           id="ed-imagen" />
                </div>
                <div class="field">
                    <label class="field-label">
                        Alt de imagen
                    </label>
                    <input class="input" id="ed-imagen-alt"
                           maxlength="200" />
                </div>
                <!-- Categoría y autor -->
                <div class="field">
                    <label class="field-label">Categoría</label>
                    <select class="select" id="ed-categoria">
                        <?php foreach ($categorias as $cat): ?>
                        <option value="<?= $cat['id'] ?>">
                            <?= e($cat['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field-label">Autor</label>
                    <select class="select" id="ed-autor">
                        <?php foreach ($autores as $au): ?>
                        <option value="<?= $au['id'] ?>">
                            <?= e($au['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Opciones -->
        <div class="form-section">
            <div class="form-section-title">Opciones</div>
            <div style="display:grid;
                        grid-template-columns:repeat(auto-fill,minmax(140px,1fr));
                        gap:12px">
                <div class="toggle-row">
                    <label class="toggle">
                        <input type="checkbox" id="ed-destacado">
                        <span class="toggle-slider"></span>
                    </label>
                    <label>Destacado</label>
                </div>
                <div class="toggle-row">
                    <label class="toggle">
                        <input type="checkbox" id="ed-breaking">
                        <span class="toggle-slider"></span>
                    </label>
                    <label>Breaking</label>
                </div>
                <div class="toggle-row">
                    <label class="toggle">
                        <input type="checkbox" id="ed-premium">
                        <span class="toggle-slider"></span>
                    </label>
                    <label>Premium</label>
                </div>
                <div class="toggle-row">
                    <label class="toggle">
                        <input type="checkbox" id="ed-opinion">
                        <span class="toggle-slider"></span>
                    </label>
                    <label>Opinión</label>
                </div>
            </div>
        </div>

        <!-- Origen y SEO -->
        <div class="form-section">
            <div class="form-section-title">Origen y SEO</div>
            <div class="form-grid-2">
                <div class="field">
                    <label class="field-label">
                        Fuente (nombre)
                    </label>
                    <input class="input" id="ed-fuente"
                           maxlength="200" />
                </div>
                <div class="field">
                    <label class="field-label">
                        URL original
                    </label>
                    <input class="input text-mono"
                           id="ed-fuente-url" maxlength="500" />
                </div>
                <div class="field">
                    <label class="field-label">
                        Meta title
                        <span id="cnt-meta-title"
                              class="text-faint">0/200</span>
                    </label>
                    <input class="input" id="ed-meta-title"
                           maxlength="200"
                           oninput="document.getElementById('cnt-meta-title').textContent=this.value.length+'/200'" />
                </div>
                <div class="field">
                    <label class="field-label">
                        Meta description
                        <span id="cnt-meta-desc"
                              class="text-faint">0/300</span>
                    </label>
                    <textarea class="textarea" id="ed-meta-desc"
                              maxlength="300" rows="2"
                              oninput="document.getElementById('cnt-meta-desc').textContent=this.value.length+'/300'"></textarea>
                </div>
                <div class="field">
                    <label class="field-label">
                        Keywords (separadas por coma)
                    </label>
                    <input class="input" id="ed-keywords"
                           maxlength="300" />
                </div>
                <div class="field">
                    <label class="field-label">
                        Fecha de publicación
                    </label>
                    <input class="input" type="datetime-local"
                           id="ed-fecha" />
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-ghost"
                onclick="closeModal('modal-edit')">
            Cancelar
        </button>
        <button class="btn btn-blue" id="btn-save-edit">
            <i class="bi bi-floppy-fill"></i>
            Guardar cambios
        </button>
    </div>
</div>
</div>

<!-- ════════════════════════════════════════════════════════
     MODAL: VER SQL HISTORIAL
════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-sql-hist">
<div class="modal" style="max-width:900px">
    <div class="modal-header">
        <div class="modal-title"
             id="sql-hist-modal-title">SQL Guardado</div>
        <button class="modal-close"
                onclick="closeModal('modal-sql-hist')">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <div class="modal-body">
        <textarea class="sql-output" id="sql-hist-output"
                  readonly
                  style="min-height:400px"></textarea>
    </div>
    <div class="modal-footer">
        <button class="btn btn-ghost"
                id="btn-copy-hist-sql">
            <i class="bi bi-clipboard"></i> Copiar
        </button>
        <button class="btn btn-ghost"
                id="btn-download-hist-sql">
            <i class="bi bi-download"></i> Descargar
        </button>
        <button class="btn btn-ghost"
                onclick="closeModal('modal-sql-hist')">
            Cerrar
        </button>
    </div>
</div>
</div>

<!-- ════════════════════════════════════════════════════════
     MODAL: AYUDA
════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-help">
<div class="modal" style="max-width:700px">
    <div class="modal-header">
        <div class="modal-title">📘 Guía rápida</div>
        <button class="modal-close"
                onclick="closeModal('modal-help')">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <div class="modal-body">
        <div class="help-grid">
            <div class="help-card">
                <h4>📡 1. Configura fuentes</h4>
                <p>Agrega periódicos digitales (RSS, Atom o web).
                   Las fuentes precargadas ya están listas.</p>
            </div>
            <div class="help-card">
                <h4>🔍 2. Recopila</h4>
                <p>Elige rango temporal y fuentes. El servidor PHP
                   hace el fetch directo, sin proxies CORS.</p>
            </div>
            <div class="help-card">
                <h4>📰 3. Revisa y edita</h4>
                <p>En Noticias, edita títulos, contenido completo
                   (sin límite) y metadata. Aprueba las que quieras
                   publicar.</p>
            </div>
            <div class="help-card">
                <h4>📥 4. Inserta a BD</h4>
                <p>Usa el panel verde para insertar directamente
                   sin phpMyAdmin. Elige estado, categoría y autor.</p>
            </div>
            <div class="help-card">
                <h4>🗄️ 5. Exporta SQL</h4>
                <p>Genera INSERT INTO para phpMyAdmin. El historial
                   guarda todos tus scripts.</p>
            </div>
            <div class="help-card">
                <h4>⌨️ Atajos de teclado</h4>
                <p>
                    <span class="kbd">1</span>–<span class="kbd">5</span>
                    cambian sección ·
                    <span class="kbd">Esc</span> cierra modales ·
                    <span class="kbd">Ctrl</span>+<span class="kbd">G</span>
                    genera SQL
                </p>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-primary"
                onclick="closeModal('modal-help')">
            Entendido
        </button>
    </div>
</div>
</div>

<!-- ════════════════════════════════════════════════════════
     MODAL: CONFIRM
════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-confirm">
<div class="modal" style="max-width:420px">
    <div class="modal-header">
        <div class="modal-title"
             id="confirm-title">¿Estás seguro?</div>
        <button class="modal-close"
                onclick="closeModal('modal-confirm')">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <div class="modal-body">
        <p id="confirm-msg"
           style="color:var(--text-primary);line-height:1.6;
                  margin:0"></p>
    </div>
    <div class="modal-footer">
        <button class="btn btn-ghost"
                onclick="closeModal('modal-confirm')">
            Cancelar
        </button>
        <button class="btn btn-primary" id="btn-confirm-yes">
            Sí, continuar
        </button>
    </div>
</div>
</div>

<!-- Toast container -->
<div class="toast-container" id="toast-container"></div>

<!-- Loader overlay -->
<div class="loader-overlay" id="loader">
    <div class="spinner"></div>
    <div class="loader-text" id="loader-text">Procesando...</div>
</div>

<!-- ════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════ -->
<script>
/* ============================================================
   ESTADO GLOBAL
============================================================ */
const CSRF    = '<?= $csrfToken ?>';
const APP_URL = '<?= APP_URL ?>';
const GEN_URL = APP_URL + '/admin/generador.php';

const state = {
    seccion        : 'fuentes',
    fuentes        : [],
    noticias       : [],
    totalNoticias  : 0,
    paginaNoticias : 1,
    viewMode       : 'cards',
    selectedNews   : new Set(),
    confirmCallback: null,
    filtroBusqueda : '',
    filtroEstado   : '',
    filtroFuente   : 0,
    currentHistSql : null,
};

/* ============================================================
   AJAX HELPER
============================================================ */
async function api(action, data = {}) {
    try {
        const r = await fetch(GEN_URL, {
            method : 'POST',
            headers: {
                'Content-Type'    : 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ action, csrf: CSRF, ...data }),
        });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    } catch (e) {
        return { success: false, message: e.message };
    }
}

/* ============================================================
   UI HELPERS
============================================================ */
function toast(msg, type = 'info', title = '') {
    const icons = {
        success: 'bi-check-circle-fill',
        error  : 'bi-x-circle-fill',
        warning: 'bi-exclamation-triangle-fill',
        info   : 'bi-info-circle-fill',
    };
    const titles = {
        success:'Éxito', error:'Error',
        warning:'Aviso', info:'Info'
    };
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.innerHTML = `
        <i class="bi ${icons[type]||icons.info} toast-icon"></i>
        <div>
            <div class="toast-title">${title||titles[type]}</div>
            <div class="toast-msg">${escHtml(msg)}</div>
        </div>`;
    document.getElementById('toast-container').appendChild(el);
    setTimeout(() => {
        el.classList.add('out');
        setTimeout(() => el.remove(), 320);
    }, 3500);
}

function showLoader(txt = 'Procesando...') {
    document.getElementById('loader-text').textContent = txt;
    document.getElementById('loader').classList.add('visible');
}
function hideLoader() {
    document.getElementById('loader').classList.remove('visible');
}
function openModal(id) {
    document.getElementById(id).classList.add('open');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}
function confirm2(title, msg, callback) {
    document.getElementById('confirm-title').textContent = title;
    document.getElementById('confirm-msg').textContent   = msg;
    state.confirmCallback = callback;
    openModal('modal-confirm');
}

/* ── Log de importación (definición ÚNICA) ── */
function addLogLine(msg, type = 'ok') {
    const log = document.getElementById('import-log');
    if (!log) return;
    const div = document.createElement('div');
    div.className = `log-line ${type}`;
    div.textContent = msg;
    log.appendChild(div);
    log.scrollTop = log.scrollHeight;
}

/* ============================================================
   NAVEGACIÓN
============================================================ */
function navigate(sec) {
    state.seccion = sec;
    document.querySelectorAll('.gen-nav-item').forEach(
        b => b.classList.toggle('active', b.dataset.section === sec)
    );
    document.querySelectorAll('.gen-page').forEach(
        p => p.classList.remove('active')
    );
    const pg = document.getElementById('page-' + sec);
    if (pg) pg.classList.add('active');

    const titles = {
        fuentes  :'Fuentes', recopilar:'Recopilar',
        noticias :'Noticias', sql:'SQL Export',
        config   :'Configuración',
    };
    document.getElementById('breadcrumb-cur').textContent =
        titles[sec] || sec;

    if (sec === 'noticias')  cargarNoticias();
    if (sec === 'sql')       { actualizarStats(); cargarHistorialSql(); }
    if (sec === 'recopilar') renderCollectSources();

    document.getElementById('gen-main').scrollTop = 0;
}

/* ============================================================
   ESTADÍSTICAS
============================================================ */
async function actualizarStats() {
    const r = await api('get_stats');
    if (!r.success) return;
    const buf = (r.pendientes||0) + (r.aprobadas||0);
    document.getElementById('hdr-fuentes').textContent   = r.total_fuentes;
    document.getElementById('hdr-buffer').textContent    = buf;
    document.getElementById('hdr-aprobadas').textContent = r.aprobadas;
    document.getElementById('badge-fuentes').textContent = r.total_fuentes;
    document.getElementById('badge-noticias').textContent= buf;
    document.getElementById('badge-aprobadas').textContent = r.aprobadas;
    // KPIs SQL
    document.getElementById('kpi-total').textContent =
        (r.pendientes||0)+(r.aprobadas||0)+(r.insertadas||0);
    document.getElementById('kpi-aprobadas').textContent = r.aprobadas;
    document.getElementById('kpi-pendientes').textContent= r.pendientes;
    document.getElementById('kpi-insertadas').textContent= r.insertadas;
}

/* ============================================================
   FUENTES
============================================================ */
async function cargarFuentes() {
    const r = await api('fuentes_listar');
    if (!r.success) { toast('Error cargando fuentes','error'); return; }
    state.fuentes = r.fuentes || [];
    renderFuentes();
    actualizarStats();
}

function renderFuentes() {
    const tbody = document.getElementById('sources-tbody');
    document.getElementById('sources-count-text').textContent =
        state.fuentes.length + ' fuentes';

    if (!state.fuentes.length) {
        tbody.innerHTML = `<tr><td colspan="7"
            style="text-align:center;padding:30px;
                   color:var(--text-muted)">
            No hay fuentes. Agrega la primera arriba.</td></tr>`;
        return;
    }

    tbody.innerHTML = state.fuentes.map(f => `
        <tr>
            <td>
                <strong>${escHtml(f.nombre)}</strong>
                ${f.locked==1
                    ? '<span class="tag tag-locked"'
                      +' title="Fuente del sistema" style="margin-left:4px">'
                      +'🔒</span>' : ''}
            </td>
            <td>
                <span class="text-mono text-xs text-muted"
                      title="${escHtml(f.url)}">
                    ${escHtml(f.url.length > 45
                        ? f.url.slice(0,45)+'…' : f.url)}
                </span>
            </td>
            <td>
                <span class="tag tag-${f.tipo}">
                    ${f.tipo.toUpperCase()}
                </span>
            </td>
            <td style="font-size:.77rem">
                ${escHtml(f.cat_nombre||'—')}
            </td>
            <td>
                <span class="tag ${f.activa==1
                    ?'tag-active':'tag-inactive'}">
                    ${f.activa==1?'● Activa':'○ Inactiva'}
                </span>
            </td>
            <td style="font-size:.71rem;color:var(--text-muted)">
                ${f.ultima_importacion
                    ? formatDate(f.ultima_importacion) : '—'}
            </td>
            <td style="text-align:right;white-space:nowrap">
                <button class="action-btn"
                        title="Probar conexión"
                        onclick="testFuente(${f.id},
                            '${escHtml(f.url)}','${f.tipo}')">
                    <i class="bi bi-wifi"></i>
                </button>
                <button class="action-btn"
                        title="Toggle activa"
                        onclick="toggleFuente(${f.id})">
                    <i class="bi bi-${f.activa==1
                        ?'toggle-on':'toggle-off'}"></i>
                </button>
                <button class="action-btn"
                        title="Editar"
                        onclick="editFuente(${f.id})">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="action-btn danger"
                        title="Eliminar"
                        onclick="eliminarFuente(${f.id},
                            '${escHtml(f.nombre)}')">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>`).join('');
}

async function guardarFuente() {
    const id     = parseInt(document.getElementById('src-edit-id').value)||0;
    const nombre = document.getElementById('src-name').value.trim();
    const url    = document.getElementById('src-url').value.trim();
    if (!nombre || !url) {
        toast('Nombre y URL son obligatorios.','warning'); return;
    }
    showLoader(id ? 'Actualizando fuente...' : 'Guardando fuente...');
    const r = await api('fuente_guardar', {
        id, nombre, url,
        tipo        : document.getElementById('src-type').value,
        pais        : document.getElementById('src-pais').value,
        categoria_id: parseInt(document.getElementById('src-categoria').value),
        autor_id    : parseInt(document.getElementById('src-autor').value),
        activa      : document.getElementById('src-activa').checked ? 1 : 0,
    });
    hideLoader();
    if (r.success) {
        toast(r.message,'success');
        cancelarEditFuente();
        cargarFuentes();
    } else {
        toast(r.message,'error');
    }
}

function editFuente(id) {
    const f = state.fuentes.find(x => x.id == id);
    if (!f) return;
    document.getElementById('src-edit-id').value   = f.id;
    document.getElementById('src-name').value      = f.nombre;
    document.getElementById('src-url').value       = f.url;
    document.getElementById('src-type').value      = f.tipo;
    document.getElementById('src-pais').value      = f.pais;
    document.getElementById('src-categoria').value = f.categoria_id;
    document.getElementById('src-autor').value     = f.autor_id;
    document.getElementById('src-activa').checked  = f.activa == 1;
    document.getElementById('src-form-title').textContent =
        'Editar fuente';
    document.getElementById('btn-save-fuente').innerHTML =
        '<i class="bi bi-save"></i> Guardar cambios';
    document.getElementById('btn-cancel-edit-fuente')
        .style.display = 'inline-flex';
    document.getElementById('src-name').focus();
    document.getElementById('page-fuentes')
        .scrollIntoView({behavior:'smooth'});
}

function cancelarEditFuente() {
    document.getElementById('src-edit-id').value  = '0';
    document.getElementById('src-name').value     = '';
    document.getElementById('src-url').value      = '';
    document.getElementById('src-type').value     = 'rss';
    document.getElementById('src-pais').value     = 'RD';
    document.getElementById('src-activa').checked = true;
    document.getElementById('src-form-title').textContent =
        'Agregar nueva fuente';
    document.getElementById('btn-save-fuente').innerHTML =
        '<i class="bi bi-plus-lg"></i> Agregar Fuente';
    document.getElementById('btn-cancel-edit-fuente')
        .style.display = 'none';
}

async function toggleFuente(id) {
    const r = await api('fuente_toggle', { id });
    if (r.success) {
        const f = state.fuentes.find(x => x.id == id);
        if (f) f.activa = r.activa;
        renderFuentes();
        toast(r.activa ? 'Fuente activada' : 'Fuente desactivada','info');
    }
}

async function eliminarFuente(id, nombre) {
    confirm2('Eliminar fuente',
        `¿Eliminar "${nombre}"? Esta acción no se puede deshacer.`,
        async () => {
            const r = await api('fuente_eliminar', { id });
            if (r.success) {
                toast(r.message,'success');
                cargarFuentes();
            } else {
                toast(r.message,'error');
            }
        }
    );
}

async function testFuente(id, url, tipo) {
    showLoader('Probando conexión...');
    const r = await api('fuente_test', { url, tipo });
    hideLoader();
    if (r.success) {
        toast(`✅ Conexión OK — ${r.total} ítems encontrados`,'success');
    } else {
        toast(`❌ Error: ${r.message}`,'error');
    }
}

async function probarTodas() {
    navigate('recopilar');
    for (const f of state.fuentes.filter(x => x.activa == 1)) {
        showLoader(`Probando: ${f.nombre}...`);
        const r = await api('fuente_test', { url:f.url, tipo:f.tipo });
        addLogLine(
            r.success
                ? `✅ ${f.nombre}: ${r.total} ítems`
                : `❌ ${f.nombre}: ${r.message}`,
            r.success ? 'ok' : 'error'
        );
    }
    hideLoader();
}

/* ============================================================
   RECOPILAR
============================================================ */
function renderCollectSources() {
    const list    = document.getElementById('collect-sources-list');
    const fuentes = state.fuentes.filter(f => f.activa == 1);

    document.getElementById('collect-src-sub').textContent =
        `0 de ${fuentes.length} seleccionadas`;

    if (!fuentes.length) {
        list.innerHTML = `<div style="text-align:center;padding:20px;
            color:var(--text-muted)">
            No hay fuentes activas. Configúralas primero.</div>`;
        return;
    }

    list.innerHTML = fuentes.map(f => `
        <label class="collect-src-row" id="csrc-${f.id}">
            <input type="checkbox" class="csrc-chk"
                   value="${f.id}"
                   onchange="updateCollectCount(this)">
            <div style="flex:1;min-width:0">
                <div class="src-name">${escHtml(f.nombre)}</div>
                <div class="src-url">${escHtml(f.url)}</div>
            </div>
            <span class="tag tag-${f.tipo}">
                ${f.tipo.toUpperCase()}
            </span>
        </label>`).join('');
}

function updateCollectCount(chk) {
    // Toggle visual selected class
    if (chk) {
        const row = chk.closest('.collect-src-row');
        if (row) row.classList.toggle('selected', chk.checked);
    }
    const checked = document.querySelectorAll('.csrc-chk:checked').length;
    const total   = state.fuentes.filter(f => f.activa == 1).length;
    document.getElementById('collect-src-sub').textContent =
        `${checked} de ${total} seleccionadas`;
}

function getSegVal(segId) {
    const btn = document.querySelector(`#${segId} button.active`);
    return btn ? btn.dataset.val : '';
}

async function iniciarRecopilacion() {
    const ids = [...document.querySelectorAll('.csrc-chk:checked')]
                    .map(x => parseInt(x.value));
    if (!ids.length) {
        toast('Selecciona al menos una fuente.','warning'); return;
    }

    document.getElementById('import-log').innerHTML = '';
    const pb  = document.getElementById('progress-bar');
    const pf  = document.getElementById('progress-fill');
    pb.style.display = 'block';
    pf.style.width   = '10%';

    const btn = document.getElementById('btn-collect');
    btn.disabled    = true;
    btn.textContent = '⏳ Recopilando...';

    const params = {
        fuente_ids  : ids,
        horas       : parseInt(getSegVal('seg-horas')) || 24,
        max_por_feed: parseInt(
                        document.getElementById('param-max-feed').value
                      ) || 30,
        solo_imagen : getSegVal('seg-img'),
        idioma      : getSegVal('seg-lang'),
        incluir_kw  : document.getElementById('param-include').value,
        excluir_kw  : document.getElementById('param-exclude').value,
        auto_clean  : document.getElementById('cfg-autoclean')
                        ?.checked || false,
    };

    pf.style.width = '40%';
    addLogLine('🚀 Iniciando recopilación server-side...','ok');

    const r = await api('recopilar', params);
    pf.style.width = '100%';

    if (r.success) {
        (r.log || []).forEach(l => addLogLine(l.msg, l.status));
        addLogLine(
            `✅ Completado: ${r.total} noticias nuevas` +
            (r.total_duplic
                ? `, ${r.total_duplic} duplicadas omitidas` : ''),
            'ok'
        );
        toast(r.message,'success');
        actualizarStats();
    } else {
        addLogLine('❌ ' + r.message,'error');
        toast(r.message,'error');
    }

    setTimeout(() => { pb.style.display = 'none'; }, 1500);
    btn.disabled    = false;
    btn.innerHTML   = '🚀 INICIAR RECOPILACIÓN';
}

/* ============================================================
   NOTICIAS
============================================================ */
async function cargarNoticias() {
    const r = await api('noticias_listar', {
        estado    : state.filtroEstado,
        fuente_id : state.filtroFuente,
        q         : state.filtroBusqueda,
        pag       : state.paginaNoticias,
    });
    if (!r.success) { toast('Error cargando noticias','error'); return; }
    state.noticias      = r.noticias || [];
    state.totalNoticias = r.total || 0;
    renderNoticias();
    renderPaginacionNoticias(r.paginas || 1);
    actualizarStats();
}

function renderNoticias() {
    const container = document.getElementById('news-container');
    const n   = state.noticias.length;
    const sel = state.selectedNews.size;

    document.getElementById('news-counter').innerHTML =
        `Mostrando <strong>${n}</strong> de
         <strong>${state.totalNoticias}</strong>` +
        (sel
            ? ` · <strong style="color:var(--primary)">
                ${sel} seleccionadas</strong>` : '');

    document.getElementById('bulk-actions').style.display =
        sel > 0 ? 'block' : 'none';

    if (!n) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="icon">📰</div>
                <h3>Sin noticias en el buffer</h3>
                <p>Ve a <strong>Recopilar</strong> para importar noticias.</p>
                <button class="btn btn-primary mt-16"
                        onclick="navigate('recopilar')">
                    🔍 Ir a Recopilar
                </button>
            </div>`;
        return;
    }
    state.viewMode === 'cards' ? renderCards() : renderList();
}

function renderCards() {
    const container = document.getElementById('news-container');
    const grid = document.createElement('div');
    grid.className = 'cards-grid';

    state.noticias.forEach(n => {
        const card = document.createElement('div');
        const ec   = n.estado === 'aprobada'   ? 'approved'
                   : n.estado === 'descartada' ? 'discarded'
                   : n.estado === 'insertada'  ? 'insertada' : '';
        card.className  = `news-card ${ec}`;
        card.dataset.id = n.id;
        card.innerHTML  = `
            <input type="checkbox" class="news-card__checkbox news-chk"
                   value="${n.id}"
                   ${state.selectedNews.has(n.id)?'checked':''}
                   onchange="toggleSeleccionNoticia(${n.id},this.checked)">
            <div class="news-card__img">
                ${n.imagen
                    ? `<img src="${escHtml(n.imagen)}"
                            alt="${escHtml(n.titulo)}"
                            onerror="this.parentElement.innerHTML=
                                '<div class=news-card__img-placeholder>🖼️</div>'">`
                    : '<div class="news-card__img-placeholder">🖼️</div>'}
            </div>
            <div class="news-card__body">
                <div class="news-card__meta">
                    ${estadoBadge(n.estado)}
                    <span class="news-card__source">
                        ${escHtml(n.fuente_nombre||'—')}
                    </span>
                    <span class="news-card__date">
                        ${n.fecha_publicacion
                            ? formatDate(n.fecha_publicacion) : '—'}
                    </span>
                </div>
                <div class="news-card__title">
                    ${escHtml(n.titulo)}
                </div>
                <div style="font-size:.71rem;color:var(--text-muted);
                            margin-top:4px">
                    📂 ${escHtml(n.cat_nombre||'—')}
                </div>
            </div>
            <div class="news-card__actions">
                <button class="btn btn-ghost"
                        style="font-size:.7rem;padding:4px 8px"
                        onclick="abrirEditar(${n.id})">
                    ✏️ Editar
                </button>
                <button class="btn btn-green"
                        style="font-size:.7rem;padding:4px 8px"
                        onclick="aprobarNoticia(${n.id})">
                    ✅
                </button>
                <button class="btn btn-ghost"
                        style="font-size:.7rem;padding:4px 8px"
                        onclick="descartarNoticia(${n.id})">
                    🗑️
                </button>
                <button class="action-btn danger"
                        onclick="eliminarNoticiaBuffer(${n.id})"
                        title="Eliminar del buffer">
                    <i class="bi bi-x-circle"></i>
                </button>
            </div>`;
        grid.appendChild(card);
    });

    container.innerHTML = '';
    container.appendChild(grid);
}

function renderList() {
    const container = document.getElementById('news-container');
    const wrap = document.createElement('div');
    wrap.style.background   = 'var(--bg-surface)';
    wrap.style.border       = '1px solid var(--border-color)';
    wrap.style.borderRadius = 'var(--border-radius-lg)';
    wrap.style.overflow     = 'hidden';

    state.noticias.forEach(n => {
        const item = document.createElement('div');
        item.className = 'news-list-item';
        item.innerHTML = `
            <input type="checkbox" class="news-chk"
                   value="${n.id}"
                   style="margin-top:4px;accent-color:var(--primary);
                          flex-shrink:0"
                   ${state.selectedNews.has(n.id)?'checked':''}
                   onchange="toggleSeleccionNoticia(${n.id},this.checked)">
            <div class="news-list-item__thumb">
                ${n.imagen
                    ? `<img src="${escHtml(n.imagen)}" alt=""
                            onerror="this.style.display='none'">`
                    : ''}
            </div>
            <div class="news-list-item__body">
                <div class="news-list-item__title">
                    ${escHtml(n.titulo)}
                </div>
                <div class="news-list-item__meta">
                    ${estadoBadge(n.estado)}
                    <span>${escHtml(n.fuente_nombre||'—')}</span>
                    <span>📂 ${escHtml(n.cat_nombre||'—')}</span>
                    <span>${n.fecha_publicacion
                        ? formatDate(n.fecha_publicacion) : '—'}</span>
                </div>
            </div>
            <div class="news-list-item__actions">
                <button class="action-btn" title="Editar"
                        onclick="abrirEditar(${n.id})">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="action-btn"
                        title="Aprobar"
                        style="color:#22c55e"
                        onclick="aprobarNoticia(${n.id})">
                    <i class="bi bi-check-lg"></i>
                </button>
                <button class="action-btn"
                        title="Descartar"
                        onclick="descartarNoticia(${n.id})">
                    <i class="bi bi-trash"></i>
                </button>
            </div>`;
        wrap.appendChild(item);
    });

    container.innerHTML = '';
    container.appendChild(wrap);
}

function renderPaginacionNoticias(totalPags) {
    const div = document.getElementById('news-pagination');
    div.innerHTML = '';
    if (totalPags <= 1) return;
    for (let i = 1; i <= totalPags; i++) {
        const btn = document.createElement('button');
        btn.className = `btn ${i === state.paginaNoticias
            ? 'btn-primary' : 'btn-ghost'}`;
        btn.style.cssText =
            'min-width:36px;padding:5px 10px;font-size:.77rem';
        btn.textContent = i;
        btn.onclick = () => {
            state.paginaNoticias = i;
            cargarNoticias();
        };
        div.appendChild(btn);
    }
}

function estadoBadge(estado) {
    const map = {
        aprobada  : ['tag-approved',  '✅ Aprobada'],
        pendiente : ['tag-pending',   '⏳ Pendiente'],
        descartada: ['tag-discarded', '🗑️ Descartada'],
        insertada : ['tag-insertada', '📥 Insertada'],
    };
    const [cls, lbl] = map[estado] || ['tag-pending', estado];
    return `<span class="tag ${cls}">${lbl}</span>`;
}

function toggleSeleccionNoticia(id, checked) {
    if (checked) state.selectedNews.add(id);
    else         state.selectedNews.delete(id);
    renderNoticias();
}

async function aprobarNoticia(id) {
    const r = await api('noticia_aprobar', { id });
    if (r.success) {
        toast('Noticia aprobada.','success');
        cargarNoticias();
    } else {
        toast(r.message,'error');
    }
}

async function descartarNoticia(id) {
    const r = await api('noticia_descartar', { id });
    if (r.success) {
        toast('Noticia descartada.','info');
        cargarNoticias();
    } else {
        toast(r.message,'error');
    }
}

async function eliminarNoticiaBuffer(id) {
    confirm2(
        'Eliminar noticia',
        '¿Eliminar esta noticia del buffer? No afecta la tabla noticias.',
        async () => {
            const r = await api('noticia_eliminar', { id });
            if (r.success) {
                toast(r.message,'success');
                cargarNoticias();
            }
        }
    );
}

async function bulkEstado(estado) {
    const ids = [...state.selectedNews];
    if (!ids.length) { toast('Sin selección.','warning'); return; }
    const r = await api('noticias_bulk_estado', { ids, estado });
    if (r.success) {
        toast(r.message,'success');
        state.selectedNews.clear();
        cargarNoticias();
    } else {
        toast(r.message,'error');
    }
}

async function bulkEliminar() {
    const ids = [...state.selectedNews];
    if (!ids.length) { toast('Sin selección.','warning'); return; }
    confirm2(
        'Eliminar noticias',
        `¿Eliminar ${ids.length} noticias del buffer?`,
        async () => {
            const r = await api('noticias_bulk_eliminar', { ids });
            if (r.success) {
                toast(r.message,'success');
                state.selectedNews.clear();
                cargarNoticias();
            }
        }
    );
}

/* ── Editar noticia ─────────────────────────────────────── */
async function abrirEditar(id) {
    showLoader('Cargando noticia...');
    const r = await api('noticia_get', { id });
    hideLoader();
    if (!r.success) { toast('No encontrada.','error'); return; }
    const n = r.noticia;

    document.getElementById('ed-id').value        = n.id;
    document.getElementById('ed-titulo').value     = n.titulo      || '';
    document.getElementById('ed-slug').value       = n.slug        || '';
    document.getElementById('ed-resumen').value    = n.resumen     || '';
    // Contenido: sin truncar, sin límite
    document.getElementById('ed-contenido').value  = n.contenido   || '';
    document.getElementById('ed-imagen').value     = n.imagen      || '';
    document.getElementById('ed-imagen-alt').value = n.imagen_alt  || '';
    document.getElementById('ed-categoria').value  = n.categoria_id;
    document.getElementById('ed-autor').value      = n.autor_id;
    document.getElementById('ed-destacado').checked= n.destacado  == 1;
    document.getElementById('ed-breaking').checked = n.breaking    == 1;
    document.getElementById('ed-premium').checked  = n.es_premium  == 1;
    document.getElementById('ed-opinion').checked  = n.es_opinion  == 1;
    document.getElementById('ed-fuente').value     = n.fuente_nombre || '';
    document.getElementById('ed-fuente-url').value = n.fuente_url    || '';
    document.getElementById('ed-meta-title').value = n.meta_title    || '';
    document.getElementById('ed-meta-desc').value  = n.meta_description || '';
    document.getElementById('ed-keywords').value   = n.keywords       || '';

    if (n.fecha_publicacion) {
        document.getElementById('ed-fecha').value =
            n.fecha_publicacion.replace(' ','T').slice(0,16);
    } else {
        document.getElementById('ed-fecha').value = '';
    }

    // Actualizar contadores
    document.getElementById('cnt-titulo').textContent =
        (n.titulo||'').length + '/300';
    document.getElementById('cnt-resumen').textContent =
        (n.resumen||'').length + '/500';
    document.getElementById('cnt-slug').textContent =
        (n.slug||'').length + '/320';

    openModal('modal-edit');
}

function regenSlug() {
    const titulo = document.getElementById('ed-titulo').value;
    document.getElementById('ed-slug').value = slugify(titulo);
    document.getElementById('cnt-slug').textContent =
        document.getElementById('ed-slug').value.length + '/320';
}

async function guardarEdicion() {
    const id = parseInt(document.getElementById('ed-id').value);
    if (!id) return;
    showLoader('Guardando...');
    const r = await api('noticia_editar', {
        id,
        titulo           : document.getElementById('ed-titulo').value,
        slug             : document.getElementById('ed-slug').value,
        resumen          : document.getElementById('ed-resumen').value,
        // contenido sin límite — se envía completo
        contenido        : document.getElementById('ed-contenido').value,
        imagen           : document.getElementById('ed-imagen').value,
        imagen_alt       : document.getElementById('ed-imagen-alt').value,
        categoria_id     : parseInt(document.getElementById('ed-categoria').value),
        autor_id         : parseInt(document.getElementById('ed-autor').value),
        destacado        : document.getElementById('ed-destacado').checked?1:0,
        breaking         : document.getElementById('ed-breaking').checked?1:0,
        es_premium       : document.getElementById('ed-premium').checked?1:0,
        es_opinion       : document.getElementById('ed-opinion').checked?1:0,
        fuente_nombre    : document.getElementById('ed-fuente').value,
        fuente_url       : document.getElementById('ed-fuente-url').value,
        meta_title       : document.getElementById('ed-meta-title').value,
        meta_description : document.getElementById('ed-meta-desc').value,
        keywords         : document.getElementById('ed-keywords').value,
        fecha_publicacion: document.getElementById('ed-fecha').value || null,
    });
    hideLoader();
    if (r.success) {
        toast('Noticia actualizada.','success');
        closeModal('modal-edit');
        cargarNoticias();
    } else {
        toast(r.message,'error');
    }
}

/* ── Inserción directa ─────────────────────────────────── */
async function insertarNoticias(soloSeleccionadas) {
    let ids = [];
    if (soloSeleccionadas) {
        ids = [...state.selectedNews];
        if (!ids.length) {
            toast('Selecciona noticias primero.','warning');
            return;
        }
    }

    const estado = document.getElementById('ins-estado').value;
    const cat    = parseInt(document.getElementById('ins-categoria').value);
    const autor  = parseInt(document.getElementById('ins-autor').value);
    const label  = soloSeleccionadas
        ? `${ids.length} noticias seleccionadas`
        : 'todas las aprobadas';

    confirm2(
        'Insertar a la base de datos',
        `¿Insertar ${label} con estado "${estado}"? `
        + 'Esta acción creará registros reales en la tabla noticias.',
        async () => {
            showLoader('Insertando noticias en la BD...');
            const r = await api('insertar_noticias', {
                ids               : soloSeleccionadas ? ids : [],
                estado_publicacion: estado,
                cat_override      : cat,
                autor_override    : autor,
            });
            hideLoader();

            const res = document.getElementById('ins-resultado');
            if (r.success) {
                res.innerHTML = `
                    <div style="color:#22c55e;font-weight:700">
                        ✅ ${escHtml(r.message)}
                        ${(r.insertadas||[]).map(x =>
                            `<div style="font-size:.71rem;
                                         color:var(--text-muted);
                                         margin-top:2px">
                                #${x.noticia_id} — ${escHtml(x.titulo)}
                            </div>`
                        ).join('')}
                    </div>`;
                if (r.errores?.length) {
                    res.innerHTML += r.errores.map(e =>
                        `<div style="color:#ef4444;font-size:.71rem">
                            ❌ ${escHtml(e.titulo)}: ${escHtml(e.error)}
                        </div>`
                    ).join('');
                }
                toast(r.message,'success');
                state.selectedNews.clear();
                cargarNoticias();
            } else {
                res.innerHTML = `<div style="color:#ef4444">
                    ❌ ${escHtml(r.message)}</div>`;
                toast(r.message,'error');
            }
        }
    );
}

/* ============================================================
   SQL EXPORT
============================================================ */
async function generarSql() {
    const scope    = getSegVal('sql-scope');
    const fecha    = getSegVal('sql-fecha');
    const estado   = document.getElementById('sql-estado').value;
    const catOvr   = parseInt(document.getElementById('sql-cat-override').value);
    const autorOvr = parseInt(document.getElementById('sql-autor-override').value);
    const header   = document.getElementById('sql-include-header').checked;
    const ids      = [...state.selectedNews];

    if (scope === 'seleccionadas' && !ids.length) {
        toast('Selecciona noticias primero en la sección Noticias.',
              'warning');
        return;
    }

    showLoader('Generando SQL...');
    const r = await api('generar_sql', {
        ids,
        scope,
        sql_estado    : estado,
        cat_override  : catOvr,
        autor_override: autorOvr,
        use_now_date  : fecha === 'ahora',
        include_header: header,
    });
    hideLoader();

    if (r.success) {
        document.getElementById('sql-output').value = r.sql;
        document.getElementById('sql-regen-info').textContent =
            `${r.total} registros · historial #${r.sql_historial_id}`;
        toast(`SQL generado: ${r.total} registros.`,'success');
        cargarHistorialSql();
    } else {
        toast(r.message,'error');
    }
}

function copiarSql() {
    const txt = document.getElementById('sql-output').value;
    if (!txt) { toast('Genera el SQL primero.','warning'); return; }
    navigator.clipboard.writeText(txt)
        .then(() => {
            toast('SQL copiado al portapapeles.','success');
            const btn = document.getElementById('btn-copy-sql');
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check-lg"></i> ¡Copiado!';
            setTimeout(() => btn.innerHTML = orig, 1600);
        })
        .catch(() => toast('No se pudo copiar.','error'));
}

function descargarSql() {
    const txt = document.getElementById('sql-output').value;
    if (!txt) { toast('Genera el SQL primero.','warning'); return; }
    const d   = new Date();
    const pad = n => String(n).padStart(2,'0');
    const fn  = `elclickrd_noticias_${d.getFullYear()}-`
              + `${pad(d.getMonth()+1)}-${pad(d.getDate())}_`
              + `${pad(d.getHours())}-${pad(d.getMinutes())}.sql`;
    const a   = document.createElement('a');
    a.href    = URL.createObjectURL(
                    new Blob([txt], {type:'text/sql'})
                );
    a.download = fn;
    a.click();
    URL.revokeObjectURL(a.href);
    toast(`Descargado: ${fn}`,'success');
}

async function cargarHistorialSql() {
    const r = await api('sql_historial', { pag: 1 });
    const c = document.getElementById('sql-historial-container');
    document.getElementById('sql-hist-info').textContent =
        `${r.total || 0} scripts guardados`;

    if (!r.success || !r.lista?.length) {
        c.innerHTML = `
            <div class="empty-state" style="padding:28px">
                <div class="icon">📋</div>
                <h3>Sin historial</h3>
                <p>Los SQL que generes aparecerán aquí.</p>
            </div>`;
        return;
    }

    c.innerHTML = r.lista.map(s => `
        <div class="sql-hist-item">
            <span class="sql-hist-badge ${s.estado}">
                ${s.estado}
            </span>
            <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:.8rem;
                            overflow:hidden;text-overflow:ellipsis;
                            white-space:nowrap">
                    ${escHtml(s.titulo)}
                </div>
                <div style="font-size:.69rem;color:var(--text-muted)">
                    ${s.total_registros} registros ·
                    ${formatDate(s.fecha)}
                </div>
            </div>
            <div class="btn-group">
                <button class="btn btn-ghost"
                        style="font-size:.7rem;padding:4px 8px"
                        onclick="verSqlHistorial(${s.id},
                            '${escHtml(s.titulo)}')">
                    <i class="bi bi-eye"></i> Ver
                </button>
                <button class="action-btn danger"
                        title="Eliminar"
                        onclick="eliminarSqlHistorial(${s.id})">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>`).join('');
}

async function verSqlHistorial(id, titulo) {
    showLoader('Cargando SQL...');
    const r = await api('sql_get', { id });
    hideLoader();
    if (!r.success) { toast('No encontrado.','error'); return; }
    state.currentHistSql = r.sql;
    document.getElementById('sql-hist-modal-title').textContent = titulo;
    document.getElementById('sql-hist-output').value =
        r.sql.sql_content || '';
    openModal('modal-sql-hist');
}

async function eliminarSqlHistorial(id) {
    confirm2('Eliminar SQL',
        '¿Eliminar este SQL del historial?',
        async () => {
            const r = await api('sql_eliminar', { id });
            if (r.success) {
                toast(r.message,'success');
                cargarHistorialSql();
            }
        }
    );
}

/* ============================================================
   CONFIGURACIÓN
============================================================ */
async function guardarConfig() {
    showLoader('Guardando configuración...');
    const r = await api('config_guardar', {
        sitename         : document.getElementById('cfg-sitename').value,
        autor_defecto    : parseInt(document.getElementById('cfg-autor').value),
        categoria_defecto: parseInt(
                             document.getElementById('cfg-categoria').value),
        max_noticias     : parseInt(document.getElementById('cfg-max').value),
        timeout          : parseInt(
                             document.getElementById('cfg-timeout').value),
        dedupe           : document.getElementById('cfg-dedupe').checked
                             ? '1' : '0',
        autoclean        : document.getElementById('cfg-autoclean').checked
                             ? '1' : '0',
        proxy1           : document.getElementById('cfg-proxy1').value,
        proxy2           : document.getElementById('cfg-proxy2').value,
        proxy3           : document.getElementById('cfg-proxy3').value,
        estado_defecto   : document.getElementById('cfg-estado-defecto').value,
    });
    hideLoader();
    if (r.success) toast(r.message,'success');
    else           toast(r.message,'error');
}

function exportarConfig() {
    const cfg = {
        sitename         : document.getElementById('cfg-sitename').value,
        autor_defecto    : document.getElementById('cfg-autor').value,
        categoria_defecto: document.getElementById('cfg-categoria').value,
        max_noticias     : document.getElementById('cfg-max').value,
        timeout          : document.getElementById('cfg-timeout').value,
        dedupe           : document.getElementById('cfg-dedupe').checked,
        autoclean        : document.getElementById('cfg-autoclean').checked,
        proxy1           : document.getElementById('cfg-proxy1').value,
        proxy2           : document.getElementById('cfg-proxy2').value,
        proxy3           : document.getElementById('cfg-proxy3').value,
        estado_defecto   : document.getElementById('cfg-estado-defecto').value,
        exportedAt       : new Date().toISOString(),
    };
    const a  = document.createElement('a');
    a.href   = URL.createObjectURL(
                   new Blob([JSON.stringify(cfg,null,2)],
                   {type:'application/json'})
               );
    a.download = `elclickrd_gen_config_${Date.now()}.json`;
    a.click();
    URL.revokeObjectURL(a.href);
    toast('Configuración exportada.','success');
}

function importarConfig(file) {
    const reader = new FileReader();
    reader.onload = e => {
        try {
            const d = JSON.parse(e.target.result);
            if (d.sitename)    document.getElementById('cfg-sitename').value = d.sitename;
            if (d.proxy1)      document.getElementById('cfg-proxy1').value   = d.proxy1;
            if (d.proxy2)      document.getElementById('cfg-proxy2').value   = d.proxy2;
            if (d.proxy3)      document.getElementById('cfg-proxy3').value   = d.proxy3;
            if (d.timeout)     document.getElementById('cfg-timeout').value  = d.timeout;
            if (d.max_noticias)document.getElementById('cfg-max').value =
                                   d.max_noticias;
            if (d.dedupe    !== undefined)
                document.getElementById('cfg-dedupe').checked    = !!d.dedupe;
            if (d.autoclean !== undefined)
                document.getElementById('cfg-autoclean').checked = !!d.autoclean;
            if (d.estado_defecto)
                document.getElementById('cfg-estado-defecto').value =
                    d.estado_defecto;
            toast('Configuración importada. Guarda para aplicar.','success');
        } catch {
            toast('JSON inválido.','error');
        }
    };
    reader.readAsText(file);
}

/* ============================================================
   UTILIDADES
============================================================ */
function escHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#39;');
}

function slugify(str) {
    return (str || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g,'')
        .replace(/[^a-z0-9\s-]/g,'')
        .replace(/[\s_]+/g,'-')
        .replace(/-+/g,'-')
        .replace(/^-+|-+$/g,'')
        .substring(0,320);
}

function formatDate(dt) {
    if (!dt) return '—';
    try {
        const d = new Date(dt.replace(' ','T'));
        return d.toLocaleDateString('es-DO', {
            day:'2-digit', month:'short', year:'numeric'
        });
    } catch { return dt; }
}

/* Admin sidebar */
function toggleSidebar() {
    document.querySelector('.admin-sidebar')
        ?.classList.toggle('open');
    document.getElementById('adminOverlay')
        ?.classList.toggle('active');
}
function closeSidebar() {
    document.querySelector('.admin-sidebar')
        ?.classList.remove('open');
    document.getElementById('adminOverlay')
        ?.classList.remove('active');
}

/* ============================================================
   EVENTOS
============================================================ */
function bindEvents() {

    /* ── Navegación generador ─── */
    document.querySelectorAll('.gen-nav-item').forEach(btn => {
        btn.addEventListener('click', () => navigate(btn.dataset.section));
    });

    /* ── Fuentes ─── */
    document.getElementById('btn-save-fuente')
        .addEventListener('click', guardarFuente);
    document.getElementById('btn-test-all')
        .addEventListener('click', probarTodas);

    /* ── Recopilar: segmented ─── */
    document.querySelectorAll(
        '#seg-horas button, #seg-img button, #seg-lang button'
    ).forEach(b => {
        b.addEventListener('click', () => {
            b.closest('.seg').querySelectorAll('button')
                .forEach(x => x.classList.remove('active'));
            b.classList.add('active');
        });
    });

    document.getElementById('btn-sel-all-src')
        .addEventListener('click', () => {
            document.querySelectorAll('.csrc-chk').forEach(c => {
                c.checked = true;
                c.closest('.collect-src-row')
                 ?.classList.add('selected');
            });
            updateCollectCount();
        });
    document.getElementById('btn-desel-all-src')
        .addEventListener('click', () => {
            document.querySelectorAll('.csrc-chk').forEach(c => {
                c.checked = false;
                c.closest('.collect-src-row')
                 ?.classList.remove('selected');
            });
            updateCollectCount();
        });
    document.getElementById('btn-collect')
        .addEventListener('click', iniciarRecopilacion);
    document.getElementById('advanced-toggle')
        .addEventListener('click', () => {
            const p    = document.getElementById('advanced-panel');
            const chev = document.getElementById('adv-chev');
            const open = p.style.display !== 'none';
            p.style.display = open ? 'none' : 'block';
            chev.textContent = open ? '▶' : '▼';
        });

    /* ── Noticias: filtros ─── */
    document.getElementById('news-search')
        .addEventListener('input', e => {
            state.filtroBusqueda = e.target.value;
            state.paginaNoticias = 1;
            cargarNoticias();
        });
    document.getElementById('news-filter-estado')
        .addEventListener('change', e => {
            state.filtroEstado   = e.target.value;
            state.paginaNoticias = 1;
            cargarNoticias();
        });
    document.getElementById('news-filter-fuente')
        .addEventListener('change', e => {
            state.filtroFuente   = parseInt(e.target.value);
            state.paginaNoticias = 1;
            cargarNoticias();
        });

    /* ── Noticias: vista ─── */
    document.querySelectorAll('#seg-view button').forEach(b => {
        b.addEventListener('click', () => {
            document.querySelectorAll('#seg-view button')
                .forEach(x => x.classList.remove('active'));
            b.classList.add('active');
            state.viewMode = b.dataset.val;
            renderNoticias();
        });
    });

    /* ── Noticias: selección ─── */
    document.getElementById('btn-sel-all-news')
        .addEventListener('click', () => {
            state.noticias.forEach(n => state.selectedNews.add(n.id));
            renderNoticias();
        });
    document.getElementById('btn-desel-all-news')
        .addEventListener('click', () => {
            state.selectedNews.clear();
            renderNoticias();
        });

    /* ── Inserción ─── */
    document.getElementById('btn-insertar-seleccionadas')
        .addEventListener('click', () => insertarNoticias(true));
    document.getElementById('btn-insertar-aprobadas')
        .addEventListener('click', () => insertarNoticias(false));

    /* ── Modal editar ─── */
    document.getElementById('btn-save-edit')
        .addEventListener('click', guardarEdicion);

    /* ── SQL: segmented ─── */
    document.querySelectorAll('#sql-scope button, #sql-fecha button')
        .forEach(b => {
            b.addEventListener('click', () => {
                b.closest('.seg').querySelectorAll('button')
                    .forEach(x => x.classList.remove('active'));
                b.classList.add('active');
            });
        });

    document.getElementById('btn-generate-sql')
        .addEventListener('click', generarSql);
    document.getElementById('btn-regen-sql')
        .addEventListener('click', generarSql);
    document.getElementById('btn-copy-sql')
        .addEventListener('click', copiarSql);
    document.getElementById('btn-download-sql')
        .addEventListener('click', descargarSql);

    /* ── Modal historial SQL ─── */
    document.getElementById('btn-copy-hist-sql')
        .addEventListener('click', () => {
            const txt = document.getElementById('sql-hist-output').value;
            navigator.clipboard.writeText(txt)
                .then(() => toast('SQL copiado.','success'))
                .catch(() => toast('No se pudo copiar.','error'));
        });
    document.getElementById('btn-download-hist-sql')
        .addEventListener('click', () => {
            const txt = document.getElementById('sql-hist-output').value;
            if (!txt) return;
            const a  = document.createElement('a');
            a.href   = URL.createObjectURL(
                           new Blob([txt],{type:'text/sql'})
                       );
            a.download = `elclickrd_hist_sql_${Date.now()}.sql`;
            a.click();
            URL.revokeObjectURL(a.href);
        });

    /* ── Configuración ─── */
    document.getElementById('cfg-timeout')
        .addEventListener('input', e => {
            document.getElementById('cfg-timeout-val').textContent =
                e.target.value + 's';
        });
    document.getElementById('btn-save-cfg')
        .addEventListener('click', guardarConfig);
    document.getElementById('btn-export-cfg')
        .addEventListener('click', exportarConfig);
    document.getElementById('btn-import-cfg')
        .addEventListener('click', () => {
            document.getElementById('file-import-cfg').click();
        });
    document.getElementById('file-import-cfg')
        .addEventListener('change', e => {
            if (e.target.files[0]) importarConfig(e.target.files[0]);
        });

    /* ── Zona peligrosa ─── */
    document.getElementById('btn-limpiar-buffer')
        .addEventListener('click', () => {
            confirm2(
                'Limpiar buffer',
                '¿Eliminar todas las noticias del buffer '
                + '(pendientes, aprobadas y descartadas)?',
                async () => {
                    const r = await api('noticias_limpiar');
                    if (r.success) {
                        toast(r.message,'success');
                        cargarNoticias();
                        actualizarStats();
                    }
                }
            );
        });
    document.getElementById('btn-limpiar-sql-hist')
        .addEventListener('click', () => {
            confirm2(
                'Limpiar historial SQL',
                '¿Eliminar TODO el historial de SQL generados?',
                async () => {
                    const hist = await api('sql_historial', { pag:1 });
                    for (const s of (hist.lista || [])) {
                        await api('sql_eliminar', { id: s.id });
                    }
                    toast('Historial SQL eliminado.','success');
                    cargarHistorialSql();
                }
            );
        });

    /* ── Modal confirm ─── */
    document.getElementById('btn-confirm-yes')
        .addEventListener('click', () => {
            if (state.confirmCallback) state.confirmCallback();
            state.confirmCallback = null;
            closeModal('modal-confirm');
        });

    /* ── Cerrar modales clickando overlay ─── */
    document.querySelectorAll('.modal-overlay').forEach(o => {
        o.addEventListener('click', e => {
            if (e.target === o) o.classList.remove('open');
        });
    });

    /* ── Atajos de teclado ─── */
    document.addEventListener('keydown', e => {
        if (e.target.matches('input,textarea,select')) return;
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.open')
                .forEach(m => m.classList.remove('open'));
        }
        if (e.ctrlKey && e.key.toLowerCase() === 'g') {
            e.preventDefault();
            navigate('sql');
            setTimeout(generarSql, 100);
        }
        const secciones = {
            '1':'fuentes','2':'recopilar',
            '3':'noticias','4':'sql','5':'config'
        };
        if (secciones[e.key]) navigate(secciones[e.key]);
    });

    /* ── Poblar filtro fuentes en Noticias ─── */
    api('fuentes_listar').then(r => {
        if (!r.success) return;
        const sel = document.getElementById('news-filter-fuente');
        // Limpiar opciones previas excepto la primera
        while (sel.options.length > 1) sel.remove(1);
        r.fuentes.forEach(f => {
            const opt = document.createElement('option');
            opt.value       = f.id;
            opt.textContent = f.nombre;
            sel.appendChild(opt);
        });
    });
}

/* ============================================================
   INICIALIZACIÓN
============================================================ */
async function init() {
    await cargarFuentes();
    bindEvents();
    navigate('fuentes');
    toast(
        `Generador listo · ${state.fuentes.length} fuentes cargadas`,
        'info',
        'El Click RD'
    );
}

init();
</script>
</body>
</html>