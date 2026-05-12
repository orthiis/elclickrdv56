<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Admin: Gestor Multimedia
 * ============================================================
 * Archivo : admin/media.php
 * Tablas  : videos, podcasts + filesystem assets/images/
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

// ── Tab activo ────────────────────────────────────────────────
$tab = trim($_GET['tab'] ?? 'imagenes');
if (!in_array($tab, ['imagenes','videos','podcasts','youtube'])) $tab = 'imagenes';

// ============================================================
// AJAX — Manejador unificado
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');

    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $ajaxAc  = $input['action'] ?? ($_POST['action'] ?? '');
    $csrfOk  = $auth->verifyCSRF($input['csrf'] ?? ($_POST['csrf_token'] ?? ''));

    if (!$csrfOk) {
        echo json_encode(['success'=>false,'message'=>'Token CSRF inválido.']); exit;
    }

    // ── IMÁGENES ────────────────────────────────────────────
    if ($ajaxAc === 'subir_imagen') {
        if (!isset($_FILES['archivo'])) {
            echo json_encode(['success'=>false,'message'=>'No se recibió archivo.']); exit;
        }
        $subdir = trim($_POST['carpeta'] ?? 'galeria');
        $subdir = preg_replace('/[^a-z0-9_\-]/', '', strtolower($subdir)) ?: 'galeria';
        $result = uploadImage($_FILES['archivo'], $subdir, 'media');
        if ($result['success']) {
            logActividad((int)$usuario['id'], 'upload_imagen', 'media', null,
                "Subió imagen: {$result['filename']} en /$subdir");
            echo json_encode([
                'success'  => true,
                'message'  => 'Imagen subida correctamente.',
                'filename' => $result['filename'],
                'path'     => $result['path'],
                'url'      => $result['url'],
                'carpeta'  => $subdir,
            ]);
        } else {
            echo json_encode(['success'=>false,'message'=>$result['error'] ?? 'Error al subir.']);
        }
        exit;
    }

    if ($ajaxAc === 'eliminar_imagen') {
        $path = trim($input['path'] ?? '');
        if (empty($path) || str_contains($path, '..')) {
            echo json_encode(['success'=>false,'message'=>'Ruta inválida.']); exit;
        }
        $fullPath = ROOT_PATH . '/' . ltrim($path, '/');
        if (!file_exists($fullPath) || !is_file($fullPath)) {
            echo json_encode(['success'=>false,'message'=>'Archivo no encontrado.']); exit;
        }
        // Verificar que está dentro de assets/images
        $realFull   = realpath($fullPath);
        $realAssets = realpath(ROOT_PATH . '/assets/images');
        if (!$realFull || !$realAssets || !str_starts_with($realFull, $realAssets)) {
            echo json_encode(['success'=>false,'message'=>'Ruta no permitida.']); exit;
        }
        if (@unlink($fullPath)) {
            logActividad((int)$usuario['id'], 'delete_imagen', 'media', null, "Eliminó imagen: $path");
            echo json_encode(['success'=>true,'message'=>'Imagen eliminada.']);
        } else {
            echo json_encode(['success'=>false,'message'=>'No se pudo eliminar el archivo.']);
        }
        exit;
    }

    if ($ajaxAc === 'renombrar_imagen') {
        $path       = trim($input['path'] ?? '');
        $nuevoNombre= trim($input['nuevo_nombre'] ?? '');
        if (empty($path) || empty($nuevoNombre) || str_contains($path,'..') || str_contains($nuevoNombre,'..')) {
            echo json_encode(['success'=>false,'message'=>'Datos inválidos.']); exit;
        }
        $nuevoNombre = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($nuevoNombre, PATHINFO_FILENAME));
        $ext         = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $dir         = dirname(ROOT_PATH . '/' . ltrim($path, '/'));
        $nuevoPath   = $dir . '/' . $nuevoNombre . '.' . $ext;
        $oldFull     = ROOT_PATH . '/' . ltrim($path, '/');
        if (!file_exists($oldFull)) {
            echo json_encode(['success'=>false,'message'=>'Archivo no encontrado.']); exit;
        }
        if (file_exists($nuevoPath)) {
            echo json_encode(['success'=>false,'message'=>'Ya existe un archivo con ese nombre.']); exit;
        }
        if (@rename($oldFull, $nuevoPath)) {
            $relativeNew = 'assets/images/' . basename(dirname($path)) . '/' . $nuevoNombre . '.' . $ext;
            echo json_encode([
                'success' => true,
                'message' => 'Archivo renombrado.',
                'nueva_url' => APP_URL . '/' . $relativeNew,
                'nuevo_path' => $relativeNew,
            ]);
        } else {
            echo json_encode(['success'=>false,'message'=>'No se pudo renombrar.']);
        }
        exit;
    }

    // ── VIDEOS (tabla videos) ────────────────────────────────
    if ($ajaxAc === 'guardar_video') {
        $id          = (int)($input['id'] ?? 0);
        $titulo      = trim($input['titulo'] ?? '');
        $descripcion = trim($input['descripcion'] ?? '');
        $tipo        = trim($input['tipo'] ?? 'youtube');
        $url         = trim($input['url'] ?? '');
        $thumbnail   = trim($input['thumbnail'] ?? '');
        $duracion    = (int)($input['duracion'] ?? 0);
        $catId       = (int)($input['categoria_id'] ?? 0) ?: null;
        $noticiaId   = (int)($input['noticia_id'] ?? 0) ?: null;
        $destacado   = (int)($input['destacado'] ?? 0);
        $autoplay    = (int)($input['autoplay'] ?? 0);
        $activo      = (int)($input['activo'] ?? 1);
        $fechaPub    = trim($input['fecha_publicacion'] ?? '') ?: null;

        if (mb_strlen($titulo) < 3) {
            echo json_encode(['success'=>false,'message'=>'El título es obligatorio (mín. 3 caracteres).']); exit;
        }
        if (empty($url)) {
            echo json_encode(['success'=>false,'message'=>'La URL/ID del video es obligatoria.']); exit;
        }
        if (!in_array($tipo, ['youtube','mp4','embed','vimeo'])) {
            echo json_encode(['success'=>false,'message'=>'Tipo de video inválido.']); exit;
        }

        // Auto-extraer thumbnail de YouTube si no se proporcionó
        if (empty($thumbnail) && $tipo === 'youtube') {
            $ytId = extraerYoutubeId($url);
            if ($ytId) $thumbnail = "https://img.youtube.com/vi/$ytId/maxresdefault.jpg";
        }

        try {
            $slug = generateSlug($titulo, 'videos', $id ?: 0);
            if ($id) {
                db()->execute(
                    "UPDATE videos SET titulo=?,slug=?,descripcion=?,tipo=?,url=?,thumbnail=?,
                     duracion=?,categoria_id=?,noticia_id=?,destacado=?,autoplay=?,activo=?,
                     fecha_publicacion=? WHERE id=?",
                    [$titulo,$slug,$descripcion,$tipo,$url,$thumbnail,
                     $duracion,$catId,$noticiaId,$destacado,$autoplay,$activo,$fechaPub,$id]
                );
                logActividad((int)$usuario['id'],'edit_video','videos',$id,"Editó video: $titulo");
                echo json_encode(['success'=>true,'message'=>'Video actualizado.','id'=>$id]);
            } else {
                $newId = db()->insert(
                    "INSERT INTO videos (titulo,slug,descripcion,tipo,url,thumbnail,duracion,
                     categoria_id,autor_id,noticia_id,destacado,autoplay,activo,
                     fecha_publicacion,fecha_creacion)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
                    [$titulo,$slug,$descripcion,$tipo,$url,$thumbnail,
                     $duracion,$catId,(int)$usuario['id'],$noticiaId,
                     $destacado,$autoplay,$activo,$fechaPub]
                );
                logActividad((int)$usuario['id'],'create_video','videos',$newId,"Creó video: $titulo");
                echo json_encode(['success'=>true,'message'=>'Video creado.','id'=>$newId]);
            }
        } catch (\Throwable $e) {
            echo json_encode(['success'=>false,'message'=>'Error BD: '.$e->getMessage()]);
        }
        exit;
    }

    if ($ajaxAc === 'eliminar_video') {
        $id = (int)($input['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
        try {
            $v = db()->fetchOne("SELECT thumbnail,tipo FROM videos WHERE id=?", [$id]);
            db()->execute("DELETE FROM videos WHERE id=?", [$id]);
            // Borrar thumbnail local si es mp4/embed
            if ($v && in_array($v['tipo'],['mp4','embed']) && !empty($v['thumbnail'])) {
                deleteImage($v['thumbnail']);
            }
            logActividad((int)$usuario['id'],'delete_video','videos',$id,"Eliminó video #$id");
            echo json_encode(['success'=>true,'message'=>'Video eliminado.']);
        } catch (\Throwable $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    if ($ajaxAc === 'toggle_video') {
        $id    = (int)($input['id'] ?? 0);
        $campo = trim($input['campo'] ?? '');
        if (!$id || !in_array($campo, ['activo','destacado','autoplay'])) {
            echo json_encode(['success'=>false,'message'=>'Datos inválidos.']); exit;
        }
        db()->execute("UPDATE videos SET `$campo` = NOT `$campo` WHERE id=?", [$id]);
        $nuevo = (int)db()->fetchColumn("SELECT `$campo` FROM videos WHERE id=?", [$id]);
        echo json_encode(['success'=>true,'valor'=>$nuevo]);
        exit;
    }

    // ── PODCASTS (tabla podcasts) ────────────────────────────
    if ($ajaxAc === 'guardar_podcast') {
        $id          = (int)($input['id'] ?? 0);
        $titulo      = trim($input['titulo'] ?? '');
        $descripcion = trim($input['descripcion'] ?? '');
        $urlAudio    = trim($input['url_audio'] ?? '');
        $thumbnail   = trim($input['thumbnail'] ?? '');
        $duracion    = (int)($input['duracion'] ?? 0);
        $temporada   = max(1, (int)($input['temporada'] ?? 1));
        $episodio    = max(1, (int)($input['episodio'] ?? 1));
        $catId       = (int)($input['categoria_id'] ?? 0) ?: null;
        $noticiaId   = (int)($input['noticia_id'] ?? 0) ?: null;
        $destacado   = (int)($input['destacado'] ?? 0);
        $activo      = (int)($input['activo'] ?? 1);
        $fechaPub    = trim($input['fecha_publicacion'] ?? '') ?: null;

        if (mb_strlen($titulo) < 3) {
            echo json_encode(['success'=>false,'message'=>'El título es obligatorio.']); exit;
        }
        if (empty($urlAudio)) {
            echo json_encode(['success'=>false,'message'=>'La URL del audio es obligatoria.']); exit;
        }

        try {
            $slug = generateSlug($titulo, 'podcasts', $id ?: 0);
            if ($id) {
                db()->execute(
                    "UPDATE podcasts SET titulo=?,slug=?,descripcion=?,url_audio=?,thumbnail=?,
                     duracion=?,temporada=?,episodio=?,categoria_id=?,noticia_id=?,
                     destacado=?,activo=?,fecha_publicacion=? WHERE id=?",
                    [$titulo,$slug,$descripcion,$urlAudio,$thumbnail,
                     $duracion,$temporada,$episodio,$catId,$noticiaId,
                     $destacado,$activo,$fechaPub,$id]
                );
                logActividad((int)$usuario['id'],'edit_podcast','podcasts',$id,"Editó podcast: $titulo");
                echo json_encode(['success'=>true,'message'=>'Podcast actualizado.','id'=>$id]);
            } else {
                $newId = db()->insert(
                    "INSERT INTO podcasts (titulo,slug,descripcion,url_audio,thumbnail,duracion,
                     temporada,episodio,categoria_id,autor_id,noticia_id,destacado,activo,
                     fecha_publicacion,fecha_creacion)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
                    [$titulo,$slug,$descripcion,$urlAudio,$thumbnail,
                     $duracion,$temporada,$episodio,$catId,(int)$usuario['id'],
                     $noticiaId,$destacado,$activo,$fechaPub]
                );
                logActividad((int)$usuario['id'],'create_podcast','podcasts',$newId,"Creó podcast: $titulo");
                echo json_encode(['success'=>true,'message'=>'Podcast creado.','id'=>$newId]);
            }
        } catch (\Throwable $e) {
            echo json_encode(['success'=>false,'message'=>'Error BD: '.$e->getMessage()]);
        }
        exit;
    }

    if ($ajaxAc === 'eliminar_podcast') {
        $id = (int)($input['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'message'=>'ID inválido.']); exit; }
        try {
            db()->execute("DELETE FROM podcasts WHERE id=?", [$id]);
            logActividad((int)$usuario['id'],'delete_podcast','podcasts',$id,"Eliminó podcast #$id");
            echo json_encode(['success'=>true,'message'=>'Podcast eliminado.']);
        } catch (\Throwable $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    if ($ajaxAc === 'toggle_podcast') {
        $id    = (int)($input['id'] ?? 0);
        $campo = trim($input['campo'] ?? '');
        if (!$id || !in_array($campo, ['activo','destacado'])) {
            echo json_encode(['success'=>false,'message'=>'Datos inválidos.']); exit;
        }
        db()->execute("UPDATE podcasts SET `$campo` = NOT `$campo` WHERE id=?", [$id]);
        $nuevo = (int)db()->fetchColumn("SELECT `$campo` FROM podcasts WHERE id=?", [$id]);
        echo json_encode(['success'=>true,'valor'=>$nuevo]);
        exit;
    }

    // ── Subir thumbnail de podcast/video ─────────────────────
    if ($ajaxAc === 'subir_thumbnail') {
        if (!isset($_FILES['thumbnail'])) {
            echo json_encode(['success'=>false,'message'=>'No se recibió archivo.']); exit;
        }
        $tipo   = trim($_POST['tipo'] ?? 'videos');
        $subdir = in_array($tipo, ['videos','podcasts']) ? $tipo : 'videos';
        $result = uploadImage($_FILES['thumbnail'], $subdir, 'thumb');
        if ($result['success']) {
            echo json_encode(['success'=>true,'path'=>$result['path'],'url'=>$result['url']]);
        } else {
            echo json_encode(['success'=>false,'message'=>$result['error'] ?? 'Error.']);
        }
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Acción desconocida.']); exit;
}

// ============================================================
// FUNCIÓN AUXILIAR: Extraer ID de YouTube
// ============================================================
function extraerYoutubeId(string $url): string {
    $patterns = [
        '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_\-]{11})/',
        '/youtube\.com\/shorts\/([a-zA-Z0-9_\-]{11})/',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $url, $m)) return $m[1];
    }
    // Si es un ID directo de 11 chars
    if (preg_match('/^[a-zA-Z0-9_\-]{11}$/', $url)) return $url;
    return '';
}

// ============================================================
// FUNCIÓN: Escanear imágenes del filesystem
// ============================================================
function escanearImagenes(string $carpeta = '', int $pagina = 1, int $porPagina = 40): array {
    $baseDir  = ROOT_PATH . '/assets/images/';
    $carpetas = [];

    // Listar todas las carpetas disponibles
    if (is_dir($baseDir)) {
        foreach (scandir($baseDir) as $item) {
            if ($item !== '.' && $item !== '..' && is_dir($baseDir . $item)) {
                $carpetas[] = $item;
            }
        }
    }

    // Determinar qué directorio escanear
    $scanDir = $carpeta && is_dir($baseDir . $carpeta) ? $baseDir . $carpeta : $baseDir;
    $exts    = ['jpg','jpeg','png','webp','gif','svg'];
    $archivos = [];

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($scanDir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, $exts)) continue;
        $fullPath = $file->getPathname();
        $relPath  = ltrim(str_replace(ROOT_PATH, '', $fullPath), '/');
        $archivos[] = [
            'path'     => $relPath,
            'url'      => APP_URL . '/' . $relPath,
            'filename' => $file->getFilename(),
            'size'     => $file->getSize(),
            'ext'      => $ext,
            'carpeta'  => basename($file->getPath()),
            'mtime'    => $file->getMTime(),
        ];
    }

    // Ordenar por fecha DESC
    usort($archivos, fn($a, $b) => $b['mtime'] - $a['mtime']);

    $total  = count($archivos);
    $offset = ($pagina - 1) * $porPagina;
    $paginas= (int)ceil($total / $porPagina);

    return [
        'archivos' => array_slice($archivos, $offset, $porPagina),
        'total'    => $total,
        'paginas'  => $paginas,
        'carpetas' => $carpetas,
    ];
}

// ── Formato de bytes ──────────────────────────────────────────
function formatBytes(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// ── Formato duración (segundos → mm:ss o hh:mm:ss) ───────────
function formatDuracion(int $segundos): string {
    if ($segundos <= 0) return '0:00';
    $h = intdiv($segundos, 3600);
    $m = intdiv($segundos % 3600, 60);
    $s = $segundos % 60;
    return $h > 0
        ? sprintf('%d:%02d:%02d', $h, $m, $s)
        : sprintf('%d:%02d', $m, $s);
}

// ============================================================
// CARGAR DATOS SEGÚN TAB
// ============================================================
$categorias = db()->fetchAll(
    "SELECT id, nombre, color FROM categorias WHERE activa=1 ORDER BY nombre",
    []
);

// ── Parámetros comunes de filtro/paginación ───────────────────
$busqueda   = trim($_GET['q'] ?? '');
$filtCat    = (int)($_GET['cat'] ?? 0);
$filtActivo = trim($_GET['activo'] ?? '');
$pagActual  = max(1, (int)($_GET['pag'] ?? 1));
$porPagina  = 20;

// ── Datos específicos por tab ─────────────────────────────────
$statsMedia = [];
$videosData = $podcastsData = [];
$imagenesScan = ['archivos'=>[],'total'=>0,'paginas'=>0,'carpetas'=>[]];

if ($tab === 'imagenes') {
    $carpetaFiltro = trim($_GET['carpeta'] ?? '');
    $imagenesScan  = escanearImagenes($carpetaFiltro, $pagActual, 40);

    // Calcular tamaño total de assets/images
    $totalSize = 0;
    try {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(ROOT_PATH.'/assets/images/', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) { if ($f->isFile()) $totalSize += $f->getSize(); }
    } catch (\Throwable $e) {}
    $statsMedia['total_imagenes'] = $imagenesScan['total'];
    $statsMedia['total_size']     = $totalSize;
    $statsMedia['carpetas']       = count($imagenesScan['carpetas']);

} elseif ($tab === 'videos' || $tab === 'youtube') {

    $tipoFiltro = ($tab === 'youtube') ? 'youtube' : trim($_GET['tipo'] ?? '');
    $where  = ['1=1'];
    $params = [];

    if (!empty($tipoFiltro)) {
        $where[]  = 'v.tipo = ?';
        $params[] = $tipoFiltro;
    }
    if (!empty($busqueda)) {
        $where[]  = '(v.titulo LIKE ? OR v.descripcion LIKE ?)';
        $b = '%'.$busqueda.'%';
        $params[] = $b; $params[] = $b;
    }
    if ($filtCat > 0) {
        $where[]  = 'v.categoria_id = ?';
        $params[] = $filtCat;
    }
    if ($filtActivo !== '') {
        $where[]  = 'v.activo = ?';
        $params[] = (int)$filtActivo;
    }

    $whereStr = implode(' AND ', $where);
    $total    = (int)db()->fetchColumn("SELECT COUNT(*) FROM videos v WHERE $whereStr", $params);
    $offset   = ($pagActual - 1) * $porPagina;

    $videosData = [
        'items'   => db()->fetchAll(
            "SELECT v.*, c.nombre AS cat_nombre, c.color AS cat_color,
                    u.nombre AS autor_nombre
             FROM videos v
             LEFT JOIN categorias c ON c.id=v.categoria_id
             LEFT JOIN usuarios   u ON u.id=v.autor_id
             WHERE $whereStr ORDER BY v.fecha_creacion DESC LIMIT ? OFFSET ?",
            array_merge($params, [$porPagina, $offset])
        ),
        'total'   => $total,
        'paginas' => (int)ceil($total / $porPagina),
    ];

    // Stats
    $statsMedia['total_videos']   = (int)db()->fetchColumn("SELECT COUNT(*) FROM videos WHERE tipo != 'youtube'");
    $statsMedia['total_youtube']  = (int)db()->fetchColumn("SELECT COUNT(*) FROM videos WHERE tipo = 'youtube'");
    $statsMedia['videos_activos'] = (int)db()->fetchColumn("SELECT COUNT(*) FROM videos WHERE activo=1");
    $statsMedia['total_vistas']   = (int)db()->fetchColumn("SELECT COALESCE(SUM(vistas),0) FROM videos");

} elseif ($tab === 'podcasts') {

    $where  = ['1=1'];
    $params = [];

    if (!empty($busqueda)) {
        $where[]  = '(p.titulo LIKE ? OR p.descripcion LIKE ?)';
        $b = '%'.$busqueda.'%';
        $params[] = $b; $params[] = $b;
    }
    if ($filtCat > 0) {
        $where[]  = 'p.categoria_id = ?';
        $params[] = $filtCat;
    }
    if ($filtActivo !== '') {
        $where[]  = 'p.activo = ?';
        $params[] = (int)$filtActivo;
    }
    $filtTemp = (int)($_GET['temporada'] ?? 0);
    if ($filtTemp > 0) {
        $where[]  = 'p.temporada = ?';
        $params[] = $filtTemp;
    }

    $whereStr = implode(' AND ', $where);
    $total    = (int)db()->fetchColumn("SELECT COUNT(*) FROM podcasts p WHERE $whereStr", $params);
    $offset   = ($pagActual - 1) * $porPagina;

    $podcastsData = [
        'items'   => db()->fetchAll(
            "SELECT p.*, c.nombre AS cat_nombre, c.color AS cat_color,
                    u.nombre AS autor_nombre
             FROM podcasts p
             LEFT JOIN categorias c ON c.id=p.categoria_id
             LEFT JOIN usuarios   u ON u.id=p.autor_id
             WHERE $whereStr ORDER BY p.temporada DESC, p.episodio DESC LIMIT ? OFFSET ?",
            array_merge($params, [$porPagina, $offset])
        ),
        'total'   => $total,
        'paginas' => (int)ceil($total / $porPagina),
    ];

    $statsMedia['total_podcasts']    = (int)db()->fetchColumn("SELECT COUNT(*) FROM podcasts");
    $statsMedia['podcasts_activos']  = (int)db()->fetchColumn("SELECT COUNT(*) FROM podcasts WHERE activo=1");
    $statsMedia['total_reproduc']    = (int)db()->fetchColumn("SELECT COALESCE(SUM(reproducciones),0) FROM podcasts");
    $maxTemp = (int)db()->fetchColumn("SELECT COALESCE(MAX(temporada),0) FROM podcasts");
    $statsMedia['temporadas']        = $maxTemp;
}

// Construcción de URL base para paginación
function buildPagUrl(array $extra = []): string {
    global $tab, $busqueda, $filtCat, $filtActivo;
    $base = ['tab'=>$tab];
    if ($busqueda)   $base['q']      = $busqueda;
    if ($filtCat)    $base['cat']    = $filtCat;
    if ($filtActivo !== '') $base['activo'] = $filtActivo;
    return APP_URL . '/admin/media.php?' . http_build_query(array_merge($base, $extra));
}

$pageTitle = match($tab) {
    'videos'    => 'Gestión de Videos',
    'podcasts'  => 'Gestión de Podcasts',
    'youtube'   => 'Videos de YouTube',
    default     => 'Biblioteca de Imágenes',
};
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
        /* ══════════════════════════════════════════
           ADMIN LAYOUT BASE (igual que noticias.php)
        ══════════════════════════════════════════ */
        body { padding-bottom:0; background:var(--bg-body); }
        .admin-wrapper { display:flex; min-height:100vh; }
        .admin-sidebar {
            width:260px; background:var(--secondary-dark);
            position:fixed; top:0; left:0; height:100vh;
            overflow-y:auto; z-index:var(--z-header);
            transition:transform var(--transition-base);
            display:flex; flex-direction:column;
        }
        .admin-sidebar::-webkit-scrollbar { width:4px; }
        .admin-sidebar::-webkit-scrollbar-thumb { background:rgba(255,255,255,.1); }
        .admin-sidebar__logo { padding:24px 20px 16px; border-bottom:1px solid rgba(255,255,255,.07); flex-shrink:0; }
        .admin-sidebar__logo a { display:flex; align-items:center; gap:10px; text-decoration:none; }
        .admin-sidebar__logo-icon { width:36px; height:36px; background:var(--primary); border-radius:10px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:1rem; flex-shrink:0; }
        .admin-sidebar__logo-text { font-family:var(--font-serif); font-size:1rem; font-weight:800; color:#fff; line-height:1.1; }
        .admin-sidebar__logo-sub { font-size:.65rem; color:rgba(255,255,255,.4); text-transform:uppercase; letter-spacing:.06em; }
        .admin-sidebar__user { padding:14px 20px; border-bottom:1px solid rgba(255,255,255,.07); display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .admin-sidebar__user img { width:36px; height:36px; border-radius:50%; object-fit:cover; border:2px solid rgba(255,255,255,.15); }
        .admin-sidebar__user-name { font-size:.82rem; font-weight:600; color:rgba(255,255,255,.9); display:block; line-height:1.2; }
        .admin-sidebar__user-role { font-size:.68rem; color:rgba(255,255,255,.4); }
        .admin-nav { flex:1; padding:12px 0; }
        .admin-nav__section { padding:14px 20px 6px; font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.1em; color:rgba(255,255,255,.25); }
        .admin-nav__item { display:flex; align-items:center; gap:10px; padding:10px 20px; color:rgba(255,255,255,.6); font-size:.82rem; font-weight:500; text-decoration:none; transition:all var(--transition-fast); position:relative; }
        .admin-nav__item:hover { background:rgba(255,255,255,.06); color:rgba(255,255,255,.9); }
        .admin-nav__item.active { background:rgba(230,57,70,.18); color:#fff; font-weight:600; }
        .admin-nav__item.active::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--primary); border-radius:0 3px 3px 0; }
        .admin-nav__item i { width:18px; text-align:center; font-size:.9rem; flex-shrink:0; }
        .admin-nav__badge { margin-left:auto; background:var(--primary); color:#fff; font-size:.6rem; font-weight:700; padding:2px 6px; border-radius:var(--border-radius-full); min-width:18px; text-align:center; }
        .admin-sidebar__footer { padding:16px 20px; border-top:1px solid rgba(255,255,255,.07); flex-shrink:0; }
        .admin-main { margin-left:260px; flex:1; min-height:100vh; display:flex; flex-direction:column; }
        .admin-topbar { background:var(--bg-surface); border-bottom:1px solid var(--border-color); padding:0 28px; height:62px; display:flex; align-items:center; gap:16px; position:sticky; top:0; z-index:var(--z-sticky); box-shadow:var(--shadow-sm); }
        .admin-topbar__toggle { display:none; color:var(--text-muted); font-size:1.2rem; padding:6px; border-radius:var(--border-radius-sm); transition:all var(--transition-fast); background:none; border:none; cursor:pointer; }
        .admin-topbar__toggle:hover { background:var(--bg-surface-2); }
        .admin-topbar__title { font-family:var(--font-serif); font-size:1.1rem; font-weight:700; color:var(--text-primary); }
        .admin-topbar__right { margin-left:auto; display:flex; align-items:center; gap:10px; }
        .admin-content { padding:28px; flex:1; }
        .admin-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:calc(var(--z-header) - 1); }

        /* ══════════════════════════════════════════
           TABS DE MEDIA
        ══════════════════════════════════════════ */
        .media-tabs {
            display:flex; gap:4px; padding:4px;
            background:var(--bg-surface);
            border:1px solid var(--border-color);
            border-radius:var(--border-radius-xl);
            margin-bottom:24px;
            flex-wrap:wrap;
        }
        .media-tab {
            flex:1; min-width:120px;
            display:flex; align-items:center; justify-content:center; gap:8px;
            padding:10px 16px;
            border-radius:var(--border-radius-lg);
            font-size:.82rem; font-weight:600;
            text-decoration:none;
            color:var(--text-muted);
            transition:all var(--transition-fast);
        }
        .media-tab:hover { background:var(--bg-surface-2); color:var(--text-primary); }
        .media-tab.active { background:var(--primary); color:#fff; box-shadow:var(--shadow-sm); }
        .media-tab .tab-badge {
            background:rgba(255,255,255,.25); color:inherit;
            font-size:.65rem; padding:2px 6px; border-radius:10px;
        }
        .media-tab:not(.active) .tab-badge { background:var(--bg-surface-3); }

        /* ══════════════════════════════════════════
           STATS CARDS
        ══════════════════════════════════════════ */
        .stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:22px; }
        .stat-card { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--border-radius-xl); padding:16px 18px; display:flex; align-items:center; gap:12px; }
        .stat-card__icon { width:42px; height:42px; border-radius:var(--border-radius-lg); display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }
        .stat-card__num { font-size:1.4rem; font-weight:900; color:var(--text-primary); line-height:1; }
        .stat-card__label { font-size:.72rem; color:var(--text-muted); margin-top:2px; }

        /* ══════════════════════════════════════════
           GALERÍA DE IMÁGENES
        ══════════════════════════════════════════ */
        .gallery-toolbar { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
        .gallery-grid {
            display:grid;
            grid-template-columns:repeat(auto-fill, minmax(160px, 1fr));
            gap:12px;
        }
        .gallery-item {
            position:relative; border-radius:var(--border-radius-lg);
            overflow:hidden; aspect-ratio:4/3;
            background:var(--bg-surface-2);
            border:2px solid transparent;
            cursor:pointer;
            transition:all var(--transition-fast);
            group: true;
        }
        .gallery-item:hover { border-color:var(--primary); transform:scale(1.02); }
        .gallery-item.selected { border-color:var(--primary); }
        .gallery-item.selected::after {
            content:'\f26b'; font-family:'bootstrap-icons';
            position:absolute; top:6px; right:6px;
            width:22px; height:22px; background:var(--primary);
            color:#fff; border-radius:50%; font-size:.75rem;
            display:flex; align-items:center; justify-content:center;
        }
        .gallery-item img { width:100%; height:100%; object-fit:cover; display:block; }
        .gallery-item__overlay {
            position:absolute; inset:0; background:rgba(0,0,0,.6);
            display:none; flex-direction:column;
            align-items:center; justify-content:center; gap:8px;
            padding:8px;
        }
        .gallery-item:hover .gallery-item__overlay { display:flex; }
        .gallery-item__name {
            position:absolute; bottom:0; left:0; right:0;
            background:linear-gradient(transparent,rgba(0,0,0,.7));
            padding:16px 8px 6px; font-size:.65rem; color:#fff;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
        }
        .gallery-btn {
            padding:5px 10px; border:none; border-radius:var(--border-radius-sm);
            font-size:.72rem; font-weight:600; cursor:pointer;
            display:flex; align-items:center; gap:4px; white-space:nowrap;
        }

        /* ══════════════════════════════════════════
           FILTROS / TOOLBAR
        ══════════════════════════════════════════ */
        .filters-bar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:16px; }
        .search-wrap { position:relative; flex:1; min-width:180px; }
        .search-wrap i { position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:.85rem; pointer-events:none; }
        .search-inp { width:100%; padding:9px 12px 9px 34px; border:1px solid var(--border-color); border-radius:var(--border-radius); background:var(--bg-body); color:var(--text-primary); font-size:.83rem; }
        .search-inp:focus { outline:none; border-color:var(--primary); }
        .filter-sel { padding:9px 12px; border:1px solid var(--border-color); border-radius:var(--border-radius); background:var(--bg-body); color:var(--text-primary); font-size:.82rem; cursor:pointer; }

        /* ══════════════════════════════════════════
           TABLA VIDEOS / PODCASTS
        ══════════════════════════════════════════ */
        .media-table-wrap { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--border-radius-xl); overflow:hidden; }
        .media-table { width:100%; border-collapse:collapse; }
        .media-table thead th { background:var(--bg-surface-2); font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); padding:12px 14px; text-align:left; border-bottom:2px solid var(--border-color); white-space:nowrap; }
        .media-table td { padding:10px 14px; border-bottom:1px solid var(--border-color); font-size:.82rem; color:var(--text-secondary); vertical-align:middle; }
        .media-table tr:last-child td { border-bottom:none; }
        .media-table tr:hover td { background:var(--bg-surface-2); }
        .media-table th:first-child, .media-table td:first-child { padding-left:20px; }

        /* Miniatura de video/podcast */
        .media-thumb { width:72px; height:50px; object-fit:cover; border-radius:var(--border-radius); background:var(--bg-surface-3); flex-shrink:0; }
        .media-thumb-wrap { position:relative; width:72px; height:50px; flex-shrink:0; }
        .media-thumb-play {
            position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
            background:rgba(0,0,0,.35); border-radius:var(--border-radius);
            color:#fff; font-size:1rem; opacity:0; transition:.2s;
        }
        .media-thumb-wrap:hover .media-thumb-play { opacity:1; }

        /* Tipo badge */
        .type-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 8px; border-radius:var(--border-radius-full); font-size:.65rem; font-weight:700; text-transform:uppercase; }
        .type-youtube  { background:rgba(255,0,0,.1); color:#cc0000; }
        .type-mp4      { background:rgba(59,130,246,.1); color:var(--info); }
        .type-vimeo    { background:rgba(26,183,234,.1); color:#1ab7ea; }
        .type-embed    { background:rgba(107,114,128,.1); color:var(--text-muted); }
        .type-podcast  { background:rgba(109,40,217,.1); color:#7c3aed; }

        /* Toggle switch */
        .tgl { position:relative; display:inline-block; width:34px; height:18px; }
        .tgl input { opacity:0; width:0; height:0; }
        .tgl-slider { position:absolute; cursor:pointer; inset:0; background:var(--bg-surface-3); border-radius:18px; transition:.3s; }
        .tgl-slider::before { content:''; position:absolute; width:12px; height:12px; border-radius:50%; background:#fff; left:3px; top:3px; transition:.3s; }
        .tgl input:checked + .tgl-slider { background:var(--primary); }
        .tgl input:checked + .tgl-slider::before { transform:translateX(16px); }

        /* Acciones de fila */
        .row-acts { display:flex; gap:4px; }
        .act-btn { display:flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:var(--border-radius-sm); font-size:.8rem; text-decoration:none; border:none; cursor:pointer; transition:all var(--transition-fast); }
        .act-edit  { color:var(--info);    background:rgba(59,130,246,.1); }
        .act-del   { color:var(--danger);  background:rgba(239,68,68,.1); }
        .act-view  { color:var(--success); background:rgba(34,197,94,.1); }
        .act-btn:hover { transform:scale(1.1); }

        /* ══════════════════════════════════════════
           MODAL FORMULARIO
        ══════════════════════════════════════════ */
        .modal-bg { position:fixed; inset:0; background:rgba(0,0,0,.65); z-index:1100; display:none; align-items:center; justify-content:center; padding:16px; }
        .modal-bg.open { display:flex; }
        .modal-panel { background:var(--bg-surface); border-radius:var(--border-radius-xl); width:100%; max-width:680px; max-height:90vh; overflow-y:auto; box-shadow:var(--shadow-xl); display:flex; flex-direction:column; }
        .modal-panel::-webkit-scrollbar { width:4px; }
        .modal-panel::-webkit-scrollbar-thumb { background:var(--border-color); }
        .modal-header { padding:20px 24px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; background:var(--bg-surface); z-index:1; }
        .modal-title { font-size:1rem; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:8px; }
        .modal-close { width:32px; height:32px; border-radius:50%; border:none; background:var(--bg-surface-2); color:var(--text-muted); cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:1rem; transition:all var(--transition-fast); }
        .modal-close:hover { background:var(--danger); color:#fff; }
        .modal-body { padding:24px; }
        .modal-footer { padding:16px 24px; border-top:1px solid var(--border-color); display:flex; gap:10px; justify-content:flex-end; }

        /* Formulario */
        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .form-group { margin-bottom:16px; }
        .form-label { display:block; font-size:.8rem; font-weight:600; color:var(--text-secondary); margin-bottom:5px; }
        .form-label span { color:var(--danger); }
        .form-ctrl { width:100%; padding:9px 12px; border:1px solid var(--border-color); border-radius:var(--border-radius); background:var(--bg-body); color:var(--text-primary); font-size:.875rem; }
        .form-ctrl:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(230,57,70,.08); }
        textarea.form-ctrl { resize:vertical; min-height:80px; }
        .form-hint { font-size:.7rem; color:var(--text-muted); margin-top:3px; }

        /* Thumbnail upload preview */
        .thumb-upload { border:2px dashed var(--border-color); border-radius:var(--border-radius-lg); padding:12px; text-align:center; cursor:pointer; transition:border-color var(--transition-fast); position:relative; }
        .thumb-upload:hover { border-color:var(--primary); }
        .thumb-upload.has-img { border-style:solid; padding:6px; }
        .thumb-upload img { width:100%; max-height:120px; object-fit:contain; border-radius:var(--border-radius-sm); }

        /* Upload zone de imágenes */
        .upload-zone { border:2px dashed var(--border-color); border-radius:var(--border-radius-xl); padding:40px 20px; text-align:center; cursor:pointer; transition:all var(--transition-fast); background:var(--bg-surface); }
        .upload-zone:hover, .upload-zone.drag-over { border-color:var(--primary); background:rgba(230,57,70,.03); }
        .upload-zone i { font-size:2.5rem; color:var(--text-muted); }
        .upload-zone p { font-size:.875rem; color:var(--text-muted); margin-top:8px; }
        .upload-zone strong { color:var(--primary); }

        /* Progress bar */
        .upload-progress-wrap { margin-top:12px; }
        .upload-progress-bar { height:6px; background:var(--bg-surface-3); border-radius:3px; overflow:hidden; }
        .upload-progress-fill { height:100%; background:var(--primary); border-radius:3px; transition:width .3s; width:0%; }

        /* Paginación */
        .pag-wrap { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-top:1px solid var(--border-color); flex-wrap:wrap; gap:10px; }
        .pag-info { font-size:.8rem; color:var(--text-muted); }
        .pag-links { display:flex; gap:4px; }
        .pag-btn { display:flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border:1px solid var(--border-color); border-radius:var(--border-radius-sm); font-size:.8rem; font-weight:600; text-decoration:none; color:var(--text-secondary); transition:all var(--transition-fast); }
        .pag-btn:hover { border-color:var(--primary); color:var(--primary); }
        .pag-btn.active { background:var(--primary); border-color:var(--primary); color:#fff; }
        .pag-btn.disabled { opacity:.4; pointer-events:none; }

        /* Toast */
        #toast-cnt { position:fixed; bottom:24px; right:24px; display:flex; flex-direction:column; gap:8px; z-index:9999; }
        .toast { padding:12px 18px; border-radius:var(--border-radius-lg); background:var(--secondary-dark); color:#fff; font-size:.85rem; font-weight:500; display:flex; align-items:center; gap:10px; box-shadow:var(--shadow-xl); animation:toastIn .3s ease; min-width:260px; }
        .toast.success { border-left:4px solid var(--success); }
        .toast.error   { border-left:4px solid var(--danger); }
        .toast.info    { border-left:4px solid var(--info); }
        @keyframes toastIn { from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)} }

        /* Modal confirmación */
        .confirm-modal { position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:1200; display:none; align-items:center; justify-content:center; padding:20px; }
        .confirm-modal.open { display:flex; }
        .confirm-box { background:var(--bg-surface); border-radius:var(--border-radius-xl); padding:28px; max-width:400px; width:100%; box-shadow:var(--shadow-xl); }
        .confirm-title { font-size:1.05rem; font-weight:700; margin-bottom:8px; }
        .confirm-body { font-size:.875rem; color:var(--text-secondary); margin-bottom:20px; }
        .confirm-acts { display:flex; gap:10px; justify-content:flex-end; }

        /* Video embed preview */
        .yt-preview { position:relative; padding-bottom:56.25%; height:0; overflow:hidden; border-radius:var(--border-radius-lg); background:#000; margin-top:8px; }
        .yt-preview iframe { position:absolute; inset:0; width:100%; height:100%; border:none; }
        .yt-preview-thumb { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
        .yt-play-btn { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.3); font-size:3rem; color:rgba(255,255,255,.9); pointer-events:none; }

        /* Carpeta chips */
        .folder-chips { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
        .folder-chip { padding:5px 12px; border-radius:var(--border-radius-full); font-size:.75rem; font-weight:600; border:1px solid var(--border-color); cursor:pointer; text-decoration:none; color:var(--text-secondary); transition:all var(--transition-fast); background:var(--bg-body); }
        .folder-chip:hover { border-color:var(--primary); color:var(--primary); }
        .folder-chip.active { background:var(--primary); border-color:var(--primary); color:#fff; }

        /* Botones principales */
        .btn-p { display:inline-flex; align-items:center; gap:7px; padding:10px 20px; background:var(--primary); color:#fff; border:none; border-radius:var(--border-radius-lg); font-size:.83rem; font-weight:700; cursor:pointer; transition:all var(--transition-fast); text-decoration:none; }
        .btn-p:hover { background:var(--primary-dark); transform:translateY(-1px); }
        .btn-s { display:inline-flex; align-items:center; gap:7px; padding:10px 16px; background:var(--bg-surface-2); color:var(--text-secondary); border:1px solid var(--border-color); border-radius:var(--border-radius-lg); font-size:.83rem; font-weight:600; cursor:pointer; transition:all var(--transition-fast); text-decoration:none; }
        .btn-s:hover { background:var(--bg-surface-3); }
        .btn-danger { display:inline-flex; align-items:center; gap:7px; padding:10px 18px; background:var(--danger); color:#fff; border:none; border-radius:var(--border-radius-lg); font-size:.83rem; font-weight:700; cursor:pointer; }

        /* Lightbox */
        .lightbox { position:fixed; inset:0; background:rgba(0,0,0,.92); z-index:1300; display:none; align-items:center; justify-content:center; flex-direction:column; gap:16px; padding:20px; }
        .lightbox.open { display:flex; }
        .lightbox img { max-width:100%; max-height:70vh; object-fit:contain; border-radius:var(--border-radius-lg); box-shadow:var(--shadow-xl); }
        .lightbox-info { color:#fff; text-align:center; }
        .lightbox-close { position:absolute; top:16px; right:16px; width:40px; height:40px; background:rgba(255,255,255,.15); border:none; border-radius:50%; color:#fff; font-size:1.1rem; cursor:pointer; display:flex; align-items:center; justify-content:center; }
        .lightbox-actions { display:flex; gap:10px; }
        .lightbox-act { padding:8px 16px; border-radius:var(--border-radius-lg); border:none; cursor:pointer; font-size:.8rem; font-weight:600; display:flex; align-items:center; gap:6px; }

        /* Responsive */
        @media (max-width:1024px) { .stats-row { grid-template-columns:repeat(2,1fr); } .form-row { grid-template-columns:1fr; } }
        @media (max-width:768px) {
            .admin-sidebar { transform:translateX(-100%); }
            .admin-sidebar.open { transform:translateX(0); box-shadow:var(--shadow-xl); }
            .admin-main { margin-left:0; }
            .admin-topbar__toggle { display:flex; }
            .admin-content { padding:14px; }
            .admin-overlay { display:block; }
            .media-tabs { flex-wrap:nowrap; overflow-x:auto; gap:3px; }
            .media-tab { min-width:auto; flex:0 0 auto; padding:9px 12px; font-size:.75rem; }
            .stats-row { grid-template-columns:repeat(2,1fr); gap:10px; }
            .gallery-grid { grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:8px; }
        }
        @media (max-width:480px) { .stats-row { grid-template-columns:1fr 1fr; } .gallery-grid { grid-template-columns:repeat(3,1fr); } }
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
            <i class="bi bi-collection-play" style="color:var(--primary);margin-right:6px"></i>
            Gestor Multimedia
        </h1>
        <div class="admin-topbar__right">
            <?php if ($tab === 'imagenes'): ?>
            <button class="btn-p" onclick="abrirUploadZone()">
                <i class="bi bi-cloud-upload"></i> Subir Imágenes
            </button>
            <?php elseif ($tab === 'videos' || $tab === 'youtube'): ?>
            <button class="btn-p" onclick="abrirModalVideo()">
                <i class="bi bi-plus-lg"></i> Nuevo Video
            </button>
            <?php elseif ($tab === 'podcasts'): ?>
            <button class="btn-p" onclick="abrirModalPodcast()">
                <i class="bi bi-plus-lg"></i> Nuevo Podcast
            </button>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/index.php" target="_blank" class="btn-s" style="padding:8px 12px">
                <i class="bi bi-box-arrow-up-right"></i>
            </a>
        </div>
    </div>

    <div class="admin-content">

        <!-- ── TABS ───────────────────────────────────────────── -->
        <div class="media-tabs">
            <?php
            $tabs = [
                'imagenes' => ['icon'=>'bi-images',      'label'=>'Imágenes',  'count'=>null],
                'videos'   => ['icon'=>'bi-play-circle', 'label'=>'Videos',    'count'=>null],
                'youtube'  => ['icon'=>'bi-youtube',     'label'=>'YouTube',   'count'=>null],
                'podcasts' => ['icon'=>'bi-mic',         'label'=>'Podcasts',  'count'=>null],
            ];
            // Obtener conteos rápidos para badges
            $countV  = (int)db()->fetchColumn("SELECT COUNT(*) FROM videos WHERE tipo != 'youtube'");
            $countYT = (int)db()->fetchColumn("SELECT COUNT(*) FROM videos WHERE tipo = 'youtube'");
            $countP  = (int)db()->fetchColumn("SELECT COUNT(*) FROM podcasts");
            $tabs['videos']['count']  = $countV;
            $tabs['youtube']['count'] = $countYT;
            $tabs['podcasts']['count']= $countP;
            foreach ($tabs as $t => $cfg):
            ?>
            <a href="<?= APP_URL ?>/admin/media.php?tab=<?= $t ?>"
               class="media-tab <?= $tab === $t ? 'active' : '' ?>">
                <i class="bi <?= $cfg['icon'] ?>"></i>
                <?= $cfg['label'] ?>
                <?php if ($cfg['count'] !== null): ?>
                <span class="tab-badge"><?= $cfg['count'] ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- ════════════════════════════════════════════════════════
             TAB: IMÁGENES
        ════════════════════════════════════════════════════════ -->
        <?php if ($tab === 'imagenes'): ?>

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-card__icon" style="background:rgba(59,130,246,.1);color:var(--info)"><i class="bi bi-images"></i></div>
                <div><div class="stat-card__num"><?= number_format($statsMedia['total_imagenes']) ?></div><div class="stat-card__label">Total Imágenes</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card__icon" style="background:rgba(34,197,94,.1);color:var(--success)"><i class="bi bi-folder2"></i></div>
                <div><div class="stat-card__num"><?= (int)$statsMedia['carpetas'] ?></div><div class="stat-card__label">Carpetas</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card__icon" style="background:rgba(245,158,11,.1);color:var(--warning)"><i class="bi bi-hdd"></i></div>
                <div><div class="stat-card__num"><?= formatBytes((int)$statsMedia['total_size']) ?></div><div class="stat-card__label">Espacio Usado</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card__icon" style="background:rgba(230,57,70,.1);color:var(--primary)"><i class="bi bi-upload"></i></div>
                <div>
                    <div class="stat-card__num" style="font-size:1rem">
                        <?= ini_get('upload_max_filesize') ?>
                    </div>
                    <div class="stat-card__label">Límite de Subida</div>
                </div>
            </div>
        </div>

        <!-- Zona de upload (colapsada por defecto) -->
        <div id="uploadZoneWrap" style="margin-bottom:20px;display:none">
            <div class="upload-zone" id="dropZone"
                 onclick="document.getElementById('filesInput').click()"
                 ondragover="event.preventDefault();this.classList.add('drag-over')"
                 ondragleave="this.classList.remove('drag-over')"
                 ondrop="handleDrop(event)">
                <i class="bi bi-cloud-upload-fill"></i>
                <p><strong>Haz clic para seleccionar</strong> o arrastra y suelta aquí</p>
                <p style="font-size:.75rem">JPG, PNG, WebP, GIF — Máx. <?= ini_get('upload_max_filesize') ?></p>
                <div style="margin-top:12px;display:flex;align-items:center;justify-content:center;gap:10px">
                    <label style="font-size:.8rem;color:var(--text-muted);font-weight:600">Carpeta:</label>
                    <select id="uploadCarpeta" class="filter-sel" onclick="event.stopPropagation()">
                        <option value="noticias">noticias</option>
                        <option value="galeria">galeria</option>
                        <option value="avatars">avatars</option>
                        <option value="videos">videos</option>
                        <option value="podcasts">podcasts</option>
                        <?php foreach ($imagenesScan['carpetas'] as $carp): ?>
                        <?php if (!in_array($carp,['noticias','galeria','avatars','videos','podcasts'])): ?>
                        <option value="<?= e($carp) ?>"><?= e($carp) ?></option>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <option value="__nueva__">+ Nueva carpeta...</option>
                    </select>
                </div>
            </div>
            <input type="file" id="filesInput" multiple
                   accept="image/jpeg,image/png,image/webp,image/gif"
                   style="display:none" onchange="processFiles(this.files)">
            <!-- Lista de progreso -->
            <div id="uploadQueue" style="margin-top:12px"></div>
        </div>

        <!-- Filtro por carpeta -->
        <div class="folder-chips">
            <a href="<?= APP_URL ?>/admin/media.php?tab=imagenes"
               class="folder-chip <?= empty($carpetaFiltro) ? 'active' : '' ?>">
                <i class="bi bi-grid-fill"></i> Todas
            </a>
            <?php foreach ($imagenesScan['carpetas'] as $carp): ?>
            <a href="<?= APP_URL ?>/admin/media.php?tab=imagenes&carpeta=<?= urlencode($carp) ?>"
               class="folder-chip <?= $carpetaFiltro === $carp ? 'active' : '' ?>">
                <i class="bi bi-folder"></i> <?= e($carp) ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Toolbar galería -->
        <div class="gallery-toolbar">
            <span style="font-size:.83rem;color:var(--text-muted)">
                <?= number_format($imagenesScan['total']) ?> imágenes
                <?= $carpetaFiltro ? "en /<strong>$carpetaFiltro</strong>" : 'en total' ?>
            </span>
            <div style="margin-left:auto;display:flex;gap:8px">
                <button id="btnSelectAll" onclick="toggleSelectAll()"
                        class="btn-s" style="font-size:.78rem;padding:7px 12px">
                    <i class="bi bi-check2-square"></i> Seleccionar todo
                </button>
                <button id="btnDeleteSelected" onclick="eliminarSeleccionadas()"
                        class="btn-s" style="font-size:.78rem;padding:7px 12px;display:none;color:var(--danger)">
                    <i class="bi bi-trash"></i> Eliminar (<span id="selCount">0</span>)
                </button>
                <div style="display:flex;border:1px solid var(--border-color);border-radius:var(--border-radius)">
                    <button onclick="setView('grid')" id="btnGrid"
                            style="padding:7px 10px;border:none;background:var(--primary);color:#fff;border-radius:var(--border-radius) 0 0 var(--border-radius);cursor:pointer">
                        <i class="bi bi-grid-3x3-gap"></i>
                    </button>
                    <button onclick="setView('list')" id="btnList"
                            style="padding:7px 10px;border:none;background:var(--bg-body);color:var(--text-muted);border-radius:0 var(--border-radius) var(--border-radius) 0;cursor:pointer">
                        <i class="bi bi-list-ul"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Galería -->
        <div id="galleryGrid" class="gallery-grid">
            <?php if (empty($imagenesScan['archivos'])): ?>
            <div style="grid-column:1/-1;padding:60px 20px;text-align:center">
                <i class="bi bi-images" style="font-size:3rem;color:var(--text-muted)"></i>
                <div style="font-size:1rem;font-weight:700;color:var(--text-primary);margin:10px 0 6px">
                    No hay imágenes
                </div>
                <div style="font-size:.85rem;color:var(--text-muted);margin-bottom:16px">
                    <?= $carpetaFiltro ? "La carpeta <strong>$carpetaFiltro</strong> está vacía." : 'Sube tu primera imagen.' ?>
                </div>
                <button class="btn-p" onclick="abrirUploadZone()">
                    <i class="bi bi-cloud-upload"></i> Subir imágenes
                </button>
            </div>
            <?php else: ?>
            <?php foreach ($imagenesScan['archivos'] as $img): ?>
            <div class="gallery-item" id="gitem-<?= md5($img['path']) ?>"
                 data-path="<?= e($img['path']) ?>"
                 data-url="<?= e($img['url']) ?>"
                 data-name="<?= e($img['filename']) ?>"
                 onclick="selectItem(this, event)">
                <img src="<?= e($img['url']) ?>" alt="<?= e($img['filename']) ?>" loading="lazy"
                     onerror="this.src='<?= APP_URL ?>/assets/images/default-news.jpg'">
                <div class="gallery-item__name"><?= e($img['filename']) ?></div>
                <div class="gallery-item__overlay">
                    <button class="gallery-btn"
                            style="background:var(--primary);color:#fff"
                            onclick="event.stopPropagation();abrirLightbox('<?= e($img['url']) ?>','<?= e($img['filename']) ?>','<?= e($img['path']) ?>','<?= formatBytes($img['size']) ?>')">
                        <i class="bi bi-zoom-in"></i> Ver
                    </button>
                    <button class="gallery-btn"
                            style="background:rgba(255,255,255,.2);color:#fff"
                            onclick="event.stopPropagation();copiarUrl('<?= e($img['url']) ?>')">
                        <i class="bi bi-clipboard"></i> Copiar URL
                    </button>
                    <button class="gallery-btn"
                            style="background:rgba(239,68,68,.8);color:#fff"
                            onclick="event.stopPropagation();confirmarEliminarImg('<?= e($img['path']) ?>','<?= e($img['filename']) ?>')">
                        <i class="bi bi-trash"></i> Eliminar
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Paginación de galería -->
        <?php if ($imagenesScan['paginas'] > 1): ?>
        <div class="pag-wrap" style="border:none;margin-top:16px">
            <div class="pag-info">
                Página <?= $pagActual ?> de <?= $imagenesScan['paginas'] ?>
                (<?= number_format($imagenesScan['total']) ?> imágenes)
            </div>
            <div class="pag-links">
                <?php
                $baseImgUrl = APP_URL . '/admin/media.php?tab=imagenes' . ($carpetaFiltro ? '&carpeta='.urlencode($carpetaFiltro) : '');
                echo '<a href="'.$baseImgUrl.'&pag='.($pagActual-1).'" class="pag-btn '.($pagActual<=1?'disabled':'').'">&laquo;</a>';
                for ($p = max(1,$pagActual-2); $p <= min($imagenesScan['paginas'],$pagActual+2); $p++) {
                    echo '<a href="'.$baseImgUrl.'&pag='.$p.'" class="pag-btn '.($p===$pagActual?'active':'').'">'.$p.'</a>';
                }
                echo '<a href="'.$baseImgUrl.'&pag='.($pagActual+1).'" class="pag-btn '.($pagActual>=$imagenesScan['paginas']?'disabled':'').'">&raquo;</a>';
                ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════════════
             TAB: VIDEOS y YOUTUBE
        ════════════════════════════════════════════════════ -->
        <?php elseif ($tab === 'videos' || $tab === 'youtube'): ?>

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-card__icon" style="background:rgba(230,57,70,.1);color:var(--primary)"><i class="bi bi-youtube"></i></div>
                <div><div class="stat-card__num"><?= number_format($statsMedia['total_youtube']) ?></div><div class="stat-card__label">Videos YouTube</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card__icon" style="background:rgba(59,130,246,.1);color:var(--info)"><i class="bi bi-play-circle"></i></div>
                <div><div class="stat-card__num"><?= number_format($statsMedia['total_videos']) ?></div><div class="stat-card__label">Videos MP4/Embed</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card__icon" style="background:rgba(34,197,94,.1);color:var(--success)"><i class="bi bi-check-circle"></i></div>
                <div><div class="stat-card__num"><?= number_format($statsMedia['videos_activos']) ?></div><div class="stat-card__label">Activos</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card__icon" style="background:rgba(245,158,11,.1);color:var(--warning)"><i class="bi bi-eye"></i></div>
                <div><div class="stat-card__num"><?= number_format($statsMedia['total_vistas']) ?></div><div class="stat-card__label">Total Vistas</div></div>
            </div>
        </div>

        <!-- Filtros -->
        <form method="GET" class="filters-bar">
            <input type="hidden" name="tab" value="<?= $tab ?>">
            <div class="search-wrap">
                <i class="bi bi-search"></i>
                <input type="text" name="q" class="search-inp"
                       placeholder="Buscar videos..." value="<?= e($busqueda) ?>">
            </div>
            <?php if ($tab === 'videos'): ?>
            <select name="tipo" class="filter-sel" onchange="this.form.submit()">
                <option value="">Todos los tipos</option>
                <option value="mp4"   <?= ($_GET['tipo']??'')==='mp4'   ?'selected':''?>>MP4</option>
                <option value="vimeo" <?= ($_GET['tipo']??'')==='vimeo' ?'selected':''?>>Vimeo</option>
                <option value="embed" <?= ($_GET['tipo']??'')==='embed' ?'selected':''?>>Embed</option>
            </select>
            <?php endif; ?>
            <select name="cat" class="filter-sel" onchange="this.form.submit()">
                <option value="">Todas las categorías</option>
                <?php foreach ($categorias as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $filtCat===$cat['id']?'selected':''?>>
                    <?= e($cat['nombre']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="activo" class="filter-sel" onchange="this.form.submit()">
                <option value="">Estado: todos</option>
                <option value="1" <?= $filtActivo==='1'?'selected':''?>>✅ Activos</option>
                <option value="0" <?= $filtActivo==='0'?'selected':''?>>❌ Inactivos</option>
            </select>
            <button type="submit" class="btn-p" style="padding:9px 16px;font-size:.82rem">
                <i class="bi bi-funnel-fill"></i> Filtrar
            </button>
            <?php if ($busqueda || $filtCat || $filtActivo !== ''): ?>
            <a href="<?= APP_URL ?>/admin/media.php?tab=<?= $tab ?>" class="btn-s" style="padding:9px 12px;font-size:.82rem">
                <i class="bi bi-x-lg"></i>
            </a>
            <?php endif; ?>
        </form>

        <!-- Tabla de videos -->
        <div class="media-table-wrap">
            <?php if (empty($videosData['items'])): ?>
            <div style="padding:60px 20px;text-align:center">
                <i class="bi bi-camera-video-off" style="font-size:3rem;color:var(--text-muted)"></i>
                <div style="font-size:1rem;font-weight:700;color:var(--text-primary);margin:10px 0 6px">
                    No hay videos
                </div>
                <div style="font-size:.85rem;color:var(--text-muted);margin-bottom:16px">
                    <?= $busqueda ? 'Sin resultados para la búsqueda.' : 'Agrega tu primer video.' ?>
                </div>
                <button class="btn-p" onclick="abrirModalVideo()">
                    <i class="bi bi-plus-lg"></i> Agregar Video
                </button>
            </div>
            <?php else: ?>
            <div style="overflow-x:auto">
            <table class="media-table">
                <thead>
                    <tr>
                        <th>Video</th>
                        <th>Tipo</th>
                        <th>Categoría</th>
                        <th>Vistas</th>
                        <th>Duración</th>
                        <th>Activo</th>
                        <th>Destacado</th>
                        <th>Fecha</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($videosData['items'] as $v): ?>
                <tr id="vrow-<?= (int)$v['id'] ?>">
                    <td style="max-width:280px">
                        <div style="display:flex;align-items:center;gap:10px">
                            <div class="media-thumb-wrap">
                                <?php
                                $thumb = '';
                                if (!empty($v['thumbnail'])) {
                                    $thumb = str_starts_with($v['thumbnail'],'http')
                                        ? $v['thumbnail']
                                        : APP_URL . '/' . $v['thumbnail'];
                                } elseif ($v['tipo'] === 'youtube') {
                                    $ytId = extraerYoutubeId($v['url']);
                                    $thumb = $ytId ? "https://img.youtube.com/vi/$ytId/mqdefault.jpg" : '';
                                }
                                ?>
                                <img class="media-thumb"
                                     src="<?= e($thumb ?: APP_URL.'/assets/images/default-news.jpg') ?>"
                                     alt="<?= e($v['titulo']) ?>"
                                     loading="lazy"
                                     onerror="this.src='<?= APP_URL ?>/assets/images/default-news.jpg'">
                                <div class="media-thumb-play"><i class="bi bi-play-circle-fill"></i></div>
                            </div>
                            <div style="min-width:0">
                                <div style="font-weight:600;color:var(--text-primary);font-size:.82rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                                    <?= e($v['titulo']) ?>
                                </div>
                                <div style="font-size:.68rem;color:var(--text-muted);margin-top:2px">
                                    <?= e($v['autor_nombre'] ?? '—') ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="type-badge type-<?= e($v['tipo']) ?>">
                            <?php
                            echo match($v['tipo']) {
                                'youtube' => '<i class="bi bi-youtube"></i> YouTube',
                                'mp4'     => '<i class="bi bi-file-play"></i> MP4',
                                'vimeo'   => '<i class="bi bi-vimeo"></i> Vimeo',
                                default   => '<i class="bi bi-code-slash"></i> Embed',
                            };
                            ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!empty($v['cat_nombre'])): ?>
                        <span style="font-size:.75rem;font-weight:600;color:<?= e($v['cat_color']) ?>">
                            <?= e($v['cat_nombre']) ?>
                        </span>
                        <?php else: ?>
                        <span style="color:var(--text-muted);font-size:.75rem">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong style="color:var(--text-primary)"><?= number_format((int)$v['vistas']) ?></strong>
                    </td>
                    <td>
                        <?= $v['duracion'] > 0 ? formatDuracion((int)$v['duracion']) : '—' ?>
                    </td>
                    <td>
                        <label class="tgl" title="Activar/Desactivar">
                            <input type="checkbox"
                                   <?= $v['activo'] ? 'checked' : '' ?>
                                   onchange="toggleVideo(<?= (int)$v['id'] ?>,'activo',this)">
                            <span class="tgl-slider"></span>
                        </label>
                    </td>
                    <td>
                        <label class="tgl" title="Destacado">
                            <input type="checkbox"
                                   <?= $v['destacado'] ? 'checked' : '' ?>
                                   onchange="toggleVideo(<?= (int)$v['id'] ?>,'destacado',this)">
                            <span class="tgl-slider"></span>
                        </label>
                    </td>
                    <td style="white-space:nowrap;font-size:.78rem;color:var(--text-muted)">
                        <?= !empty($v['fecha_publicacion'])
                            ? date('d/m/Y', strtotime($v['fecha_publicacion']))
                            : date('d/m/Y', strtotime($v['fecha_creacion'])) ?>
                    </td>
                    <td>
                        <div class="row-acts">
                            <button onclick="editarVideo(<?= htmlspecialchars(json_encode($v), ENT_QUOTES) ?>)"
                                    class="act-btn act-edit" title="Editar">
                                <i class="bi bi-pencil-fill"></i>
                            </button>
                            <?php if ($v['tipo'] === 'youtube'): ?>
                            <?php $ytId = extraerYoutubeId($v['url']); ?>
                            <?php if ($ytId): ?>
                            <a href="https://youtu.be/<?= e($ytId) ?>" target="_blank"
                               class="act-btn act-view" title="Ver en YouTube">
                                <i class="bi bi-youtube"></i>
                            </a>
                            <?php endif; ?>
                            <?php endif; ?>
                            <button onclick="eliminarVideo(<?= (int)$v['id'] ?>,'<?= e(addslashes($v['titulo'])) ?>')"
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
            <?php if ($videosData['paginas'] > 1 || $videosData['total'] > 0): ?>
            <div class="pag-wrap">
                <div class="pag-info">
                    <?= number_format($videosData['total']) ?> videos en total
                </div>
                <div class="pag-links">
                    <?php
                    echo '<a href="'.buildPagUrl(['pag'=>$pagActual-1]).'" class="pag-btn '.($pagActual<=1?'disabled':'').'">&laquo;</a>';
                    for ($p=max(1,$pagActual-2); $p<=min($videosData['paginas'],$pagActual+2); $p++) {
                        echo '<a href="'.buildPagUrl(['pag'=>$p]).'" class="pag-btn '.($p===$pagActual?'active':'').'">'.$p.'</a>';
                    }
                    echo '<a href="'.buildPagUrl(['pag'=>$pagActual+1]).'" class="pag-btn '.($pagActual>=$videosData['paginas']?'disabled':'').'">&raquo;</a>';
                    ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; // empty items ?>
        </div>

        <!-- ════════════════════════════════════════════════════
             TAB: PODCASTS
        ════════════════════════════════════════════════════ -->
        <?php elseif ($tab === 'podcasts'): ?>

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-card__icon" style="background:rgba(109,40,217,.1);color:#7c3aed"><i class="bi bi-mic-fill"></i></div>
                <div><div class="stat-card__num"><?= number_format($statsMedia['total_podcasts']) ?></div><div class="stat-card__label">Total Episodios</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card__icon" style="background:rgba(34,197,94,.1);color:var(--success)"><i class="bi bi-check-circle"></i></div>
                <div><div class="stat-card__num"><?= number_format($statsMedia['podcasts_activos']) ?></div><div class="stat-card__label">Activos</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card__icon" style="background:rgba(245,158,11,.1);color:var(--warning)"><i class="bi bi-headphones"></i></div>
                <div><div class="stat-card__num"><?= number_format($statsMedia['total_reproduc']) ?></div><div class="stat-card__label">Reproducciones</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-card__icon" style="background:rgba(59,130,246,.1);color:var(--info)"><i class="bi bi-collection-play"></i></div>
                <div><div class="stat-card__num"><?= (int)$statsMedia['temporadas'] ?></div><div class="stat-card__label">Temporadas</div></div>
            </div>
        </div>

        <!-- Filtros podcasts -->
        <form method="GET" class="filters-bar">
            <input type="hidden" name="tab" value="podcasts">
            <div class="search-wrap">
                <i class="bi bi-search"></i>
                <input type="text" name="q" class="search-inp"
                       placeholder="Buscar podcasts..." value="<?= e($busqueda) ?>">
            </div>
            <select name="cat" class="filter-sel" onchange="this.form.submit()">
                <option value="">Todas las categorías</option>
                <?php foreach ($categorias as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $filtCat===$cat['id']?'selected':''?>>
                    <?= e($cat['nombre']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="temporada" class="filter-sel" onchange="this.form.submit()">
                <option value="">Todas las temporadas</option>
                <?php for ($t=1; $t<=(int)$statsMedia['temporadas']; $t++): ?>
                <option value="<?= $t ?>" <?= ($filtTemp??0)===$t?'selected':''?>>
                    Temporada <?= $t ?>
                </option>
                <?php endfor; ?>
            </select>
            <select name="activo" class="filter-sel" onchange="this.form.submit()">
                <option value="">Estado: todos</option>
                <option value="1" <?= $filtActivo==='1'?'selected':''?>>✅ Activos</option>
                <option value="0" <?= $filtActivo==='0'?'selected':''?>>❌ Inactivos</option>
            </select>
            <button type="submit" class="btn-p" style="padding:9px 16px;font-size:.82rem">
                <i class="bi bi-funnel-fill"></i> Filtrar
            </button>
        </form>

        <!-- Tabla de podcasts -->
        <div class="media-table-wrap">
            <?php if (empty($podcastsData['items'])): ?>
            <div style="padding:60px 20px;text-align:center">
                <i class="bi bi-mic-mute" style="font-size:3rem;color:var(--text-muted)"></i>
                <div style="font-size:1rem;font-weight:700;color:var(--text-primary);margin:10px 0 6px">No hay podcasts</div>
                <div style="font-size:.85rem;color:var(--text-muted);margin-bottom:16px">Agrega tu primer episodio.</div>
                <button class="btn-p" onclick="abrirModalPodcast()">
                    <i class="bi bi-plus-lg"></i> Nuevo Podcast
                </button>
            </div>
            <?php else: ?>
            <div style="overflow-x:auto">
            <table class="media-table">
                <thead>
                    <tr>
                        <th>Episodio</th>
                        <th>Temp./Ep.</th>
                        <th>Categoría</th>
                        <th>Duración</th>
                        <th>Reproducc.</th>
                        <th>Activo</th>
                        <th>Destacado</th>
                        <th>Fecha</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($podcastsData['items'] as $p): ?>
                <tr id="prow-<?= (int)$p['id'] ?>">
                    <td style="max-width:280px">
                        <div style="display:flex;align-items:center;gap:10px">
                            <div class="media-thumb-wrap">
                                <?php $thumbP = !empty($p['thumbnail']) ? (str_starts_with($p['thumbnail'],'http') ? $p['thumbnail'] : APP_URL.'/'.$p['thumbnail']) : APP_URL.'/assets/images/default-news.jpg'; ?>
                                <img class="media-thumb"
                                     src="<?= e($thumbP) ?>"
                                     alt="<?= e($p['titulo']) ?>"
                                     loading="lazy"
                                     onerror="this.src='<?= APP_URL ?>/assets/images/default-news.jpg'">
                                <div class="media-thumb-play"
                                     style="background:rgba(109,40,217,.5)">
                                    <i class="bi bi-mic-fill"></i>
                                </div>
                            </div>
                            <div style="min-width:0">
                                <div style="font-weight:600;color:var(--text-primary);font-size:.82rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                                    <?= e($p['titulo']) ?>
                                </div>
                                <div style="font-size:.68rem;color:var(--text-muted);margin-top:2px">
                                    <?= e($p['autor_nombre'] ?? '—') ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span style="font-size:.8rem;font-weight:700;color:var(--text-primary)">
                            T<?= (int)$p['temporada'] ?> E<?= (int)$p['episodio'] ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!empty($p['cat_nombre'])): ?>
                        <span style="font-size:.75rem;font-weight:600;color:<?= e($p['cat_color']) ?>">
                            <?= e($p['cat_nombre']) ?>
                        </span>
                        <?php else: ?>
                        <span style="color:var(--text-muted)">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $p['duracion'] > 0 ? formatDuracion((int)$p['duracion']) : '—' ?></td>
                    <td><strong style="color:var(--text-primary)"><?= number_format((int)$p['reproducciones']) ?></strong></td>
                    <td>
                        <label class="tgl">
                            <input type="checkbox"
                                   <?= $p['activo'] ? 'checked' : '' ?>
                                   onchange="togglePodcast(<?= (int)$p['id'] ?>,'activo',this)">
                            <span class="tgl-slider"></span>
                        </label>
                    </td>
                    <td>
                        <label class="tgl">
                            <input type="checkbox"
                                   <?= $p['destacado'] ? 'checked' : '' ?>
                                   onchange="togglePodcast(<?= (int)$p['id'] ?>,'destacado',this)">
                            <span class="tgl-slider"></span>
                        </label>
                    </td>
                    <td style="white-space:nowrap;font-size:.78rem;color:var(--text-muted)">
                        <?= !empty($p['fecha_publicacion'])
                            ? date('d/m/Y', strtotime($p['fecha_publicacion']))
                            : date('d/m/Y', strtotime($p['fecha_creacion'])) ?>
                    </td>
                    <td>
                        <div class="row-acts">
                            <button onclick="editarPodcast(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)"
                                    class="act-btn act-edit" title="Editar">
                                <i class="bi bi-pencil-fill"></i>
                            </button>
                            <a href="<?= e($p['url_audio']) ?>" target="_blank"
                               class="act-btn act-view" title="Escuchar">
                                <i class="bi bi-headphones"></i>
                            </a>
                            <button onclick="eliminarPodcast(<?= (int)$p['id'] ?>,'<?= e(addslashes($p['titulo'])) ?>')"
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
            <!-- Paginación podcasts -->
            <?php if ($podcastsData['paginas'] > 1 || $podcastsData['total'] > 0): ?>
            <div class="pag-wrap">
                <div class="pag-info"><?= number_format($podcastsData['total']) ?> episodios en total</div>
                <div class="pag-links">
                    <?php
                    echo '<a href="'.buildPagUrl(['pag'=>$pagActual-1]).'" class="pag-btn '.($pagActual<=1?'disabled':'').'">&laquo;</a>';
                    for ($p=max(1,$pagActual-2); $p<=min($podcastsData['paginas'],$pagActual+2); $p++) {
                        echo '<a href="'.buildPagUrl(['pag'=>$p]).'" class="pag-btn '.($p===$pagActual?'active':'').'">'.$p.'</a>';
                    }
                    echo '<a href="'.buildPagUrl(['pag'=>$pagActual+1]).'" class="pag-btn '.($pagActual>=$podcastsData['paginas']?'disabled':'').'">&raquo;</a>';
                    ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; // empty items ?>
        </div>

        <?php endif; // tab ?>

    </div><!-- /admin-content -->
</main>
</div><!-- /admin-wrapper -->

<!-- ══════════════════════════════════════════════
     MODAL: CREAR / EDITAR VIDEO
══════════════════════════════════════════════ -->
<div class="modal-bg" id="videoModal">
    <div class="modal-panel">
        <div class="modal-header">
            <div class="modal-title" id="videoModalTitle">
                <i class="bi bi-play-circle" style="color:var(--primary)"></i>
                Nuevo Video
            </div>
            <button class="modal-close" onclick="cerrarModal('videoModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="videoId" value="0">

            <!-- Tipo de video -->
            <div class="form-group">
                <label class="form-label">Tipo de Video <span>*</span></label>
                <div style="display:flex;gap:8px;flex-wrap:wrap" id="tipoSelector">
                    <?php
                    $tipos = [
                        'youtube' => ['icon'=>'bi-youtube',       'label'=>'YouTube',  'color'=>'#cc0000'],
                        'mp4'     => ['icon'=>'bi-file-play',     'label'=>'MP4/URL',  'color'=>'var(--info)'],
                        'vimeo'   => ['icon'=>'bi-vimeo',         'label'=>'Vimeo',    'color'=>'#1ab7ea'],
                        'embed'   => ['icon'=>'bi-code-slash',    'label'=>'Embed',    'color'=>'var(--text-muted)'],
                    ];
                    foreach ($tipos as $tk => $tv):
                    ?>
                    <label style="cursor:pointer">
                        <input type="radio" name="videoTipo" value="<?= $tk ?>"
                               <?= $tk==='youtube'?'checked':'' ?>
                               onchange="cambiarTipoVideo('<?= $tk ?>')"
                               style="display:none">
                        <span class="tipo-chip" data-tipo="<?= $tk ?>"
                              style="display:inline-flex;align-items:center;gap:6px;
                                     padding:7px 14px;border-radius:var(--border-radius-full);
                                     border:2px solid <?= $tk==='youtube'?'#cc0000':'var(--border-color)' ?>;
                                     font-size:.78rem;font-weight:600;
                                     background:<?= $tk==='youtube'?'rgba(204,0,0,.1)':'var(--bg-body)' ?>;
                                     color:<?= $tk==='youtube'?'#cc0000':'var(--text-muted)' ?>">
                            <i class="bi <?= $tv['icon'] ?>"></i> <?= $tv['label'] ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- URL / ID del video -->
            <div class="form-group">
                <label class="form-label" id="urlVideoLabel">URL o ID de YouTube <span>*</span></label>
                <div style="display:flex;gap:8px">
                    <input type="text" id="videoUrl" class="form-ctrl"
                           placeholder="https://www.youtube.com/watch?v=... o ID directo"
                           oninput="previewYoutube(this.value)">
                    <button type="button" onclick="extraerInfoYT()"
                            id="btnExtractYT"
                            style="padding:9px 14px;border:1px solid var(--border-color);
                                   border-radius:var(--border-radius);background:var(--bg-body);
                                   color:var(--text-muted);cursor:pointer;white-space:nowrap;font-size:.8rem">
                        <i class="bi bi-magic"></i> Auto
                    </button>
                </div>
                <div class="form-hint" id="urlVideoHint">Pega la URL completa de YouTube o solo el ID del video.</div>
                <!-- Preview YouTube -->
                <div id="ytPreviewWrap" style="display:none;margin-top:10px">
                    <div class="yt-preview">
                        <img class="yt-preview-thumb" id="ytThumbPreview" src="" alt="Thumbnail">
                        <div class="yt-play-btn"><i class="bi bi-play-circle-fill"></i></div>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <!-- Título -->
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label" for="videoTitulo">Título <span>*</span></label>
                    <input type="text" id="videoTitulo" class="form-ctrl"
                           placeholder="Título del video..." maxlength="200">
                </div>
            </div>

            <!-- Descripción -->
            <div class="form-group">
                <label class="form-label" for="videoDesc">Descripción</label>
                <textarea id="videoDesc" class="form-ctrl" rows="3"
                          placeholder="Descripción del video..."></textarea>
            </div>

            <div class="form-row">
                <!-- Categoría -->
                <div class="form-group">
                    <label class="form-label">Categoría</label>
                    <select id="videoCat" class="form-ctrl">
                        <option value="">Sin categoría</option>
                        <?php foreach ($categorias as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= e($cat['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Duración -->
                <div class="form-group">
                    <label class="form-label">Duración (segundos)</label>
                    <input type="number" id="videoDuracion" class="form-ctrl"
                           placeholder="ej: 360 = 6 min" min="0">
                    <div class="form-hint" id="durPreview" style="color:var(--info)"></div>
                </div>
            </div>

            <!-- Thumbnail -->
            <div class="form-group">
                <label class="form-label">Thumbnail</label>
                <div style="display:flex;gap:10px;align-items:flex-start">
                    <input type="text" id="videoThumb" class="form-ctrl"
                           placeholder="URL del thumbnail o sube una imagen...">
                    <button type="button" onclick="document.getElementById('thumbFileVideo').click()"
                            class="btn-s" style="white-space:nowrap;padding:9px 12px">
                        <i class="bi bi-image"></i> Subir
                    </button>
                    <input type="file" id="thumbFileVideo" accept="image/*" style="display:none"
                           onchange="subirThumb(this,'video','videoThumb','videoThumbPreviewImg')">
                </div>
                <div id="videoThumbPreview" style="margin-top:8px;display:none">
                    <img id="videoThumbPreviewImg" style="max-height:80px;border-radius:var(--border-radius);border:1px solid var(--border-color)" src="" alt="">
                </div>
            </div>

            <div class="form-row">
                <!-- Fecha publicación -->
                <div class="form-group">
                    <label class="form-label">Fecha Publicación</label>
                    <input type="datetime-local" id="videoFecha" class="form-ctrl">
                </div>
                <!-- Opciones -->
                <div class="form-group">
                    <label class="form-label">Opciones</label>
                    <div style="display:flex;flex-direction:column;gap:8px;margin-top:4px">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.82rem">
                            <label class="tgl">
                                <input type="checkbox" id="videoActivo" checked>
                                <span class="tgl-slider"></span>
                            </label>
                            Activo
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.82rem">
                            <label class="tgl">
                                <input type="checkbox" id="videoDestacado">
                                <span class="tgl-slider"></span>
                            </label>
                            Destacado
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.82rem">
                            <label class="tgl">
                                <input type="checkbox" id="videoAutoplay">
                                <span class="tgl-slider"></span>
                            </label>
                            Autoplay
                        </label>
                    </div>
                </div>
            </div>
        </div><!-- /modal-body -->
        <div class="modal-footer">
            <button class="btn-s" onclick="cerrarModal('videoModal')">
                <i class="bi bi-x-lg"></i> Cancelar
            </button>
            <button class="btn-p" onclick="guardarVideo()">
                <i class="bi bi-check-lg"></i> <span id="btnVideoLabel">Crear Video</span>
            </button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════
     MODAL: CREAR / EDITAR PODCAST
══════════════════════════════════════════════ -->
<div class="modal-bg" id="podcastModal">
    <div class="modal-panel">
        <div class="modal-header">
            <div class="modal-title" id="podcastModalTitle">
                <i class="bi bi-mic" style="color:#7c3aed"></i>
                Nuevo Episodio
            </div>
            <button class="modal-close" onclick="cerrarModal('podcastModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="podcastId" value="0">

            <div class="form-group">
                <label class="form-label" for="podcastTitulo">Título del Episodio <span>*</span></label>
                <input type="text" id="podcastTitulo" class="form-ctrl"
                       placeholder="Ej: Entrevista con el presidente..." maxlength="200">
            </div>

            <div class="form-group">
                <label class="form-label" for="podcastUrlAudio">URL del Audio <span>*</span></label>
                <input type="url" id="podcastUrlAudio" class="form-ctrl"
                       placeholder="https://... (MP3, OGG, M4A, Anchor, Spotify...)">
                <div class="form-hint">Acepta URLs directas de MP3 o embeds de Anchor/Spotify/SoundCloud</div>
                <!-- Audio preview -->
                <div id="audioPreviewWrap" style="margin-top:10px;display:none">
                    <audio controls id="audioPreviewEl"
                           style="width:100%;height:40px;border-radius:var(--border-radius)">
                        <source id="audioPreviewSrc" src="" type="audio/mpeg">
                    </audio>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="podcastDesc">Descripción</label>
                <textarea id="podcastDesc" class="form-ctrl" rows="3"
                          placeholder="Descripción del episodio..."></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Categoría</label>
                    <select id="podcastCat" class="form-ctrl">
                        <option value="">Sin categoría</option>
                        <?php foreach ($categorias as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= e($cat['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Duración (seg)</label>
                    <input type="number" id="podcastDuracion" class="form-ctrl"
                           placeholder="ej: 2700 = 45 min" min="0">
                    <div class="form-hint" id="podDurPreview" style="color:var(--info)"></div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Temporada</label>
                    <input type="number" id="podcastTemporada" class="form-ctrl"
                           value="1" min="1" max="99">
                </div>
                <div class="form-group">
                    <label class="form-label">Número de Episodio</label>
                    <input type="number" id="podcastEpisodio" class="form-ctrl"
                           value="1" min="1">
                </div>
            </div>

            <!-- Thumbnail podcast -->
            <div class="form-group">
                <label class="form-label">Portada del Episodio</label>
                <div style="display:flex;gap:10px;align-items:flex-start">
                    <input type="text" id="podcastThumb" class="form-ctrl"
                           placeholder="URL de la imagen de portada...">
                    <button type="button" onclick="document.getElementById('thumbFilePodcast').click()"
                            class="btn-s" style="white-space:nowrap;padding:9px 12px">
                        <i class="bi bi-image"></i> Subir
                    </button>
                    <input type="file" id="thumbFilePodcast" accept="image/*" style="display:none"
                           onchange="subirThumb(this,'podcasts','podcastThumb','podThumbPreviewImg')">
                </div>
                <div id="podThumbPreview" style="margin-top:8px;display:none">
                    <img id="podThumbPreviewImg" style="max-height:80px;border-radius:var(--border-radius);border:1px solid var(--border-color)" src="" alt="">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Fecha Publicación</label>
                    <input type="datetime-local" id="podcastFecha" class="form-ctrl">
                </div>
                <div class="form-group">
                    <label class="form-label">Opciones</label>
                    <div style="display:flex;flex-direction:column;gap:8px;margin-top:4px">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.82rem">
                            <label class="tgl">
                                <input type="checkbox" id="podcastActivo" checked>
                                <span class="tgl-slider"></span>
                            </label>
                            Activo
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.82rem">
                            <label class="tgl">
                                <input type="checkbox" id="podcastDestacado">
                                <span class="tgl-slider"></span>
                            </label>
                            Destacado
                        </label>
                    </div>
                </div>
            </div>
        </div><!-- /modal-body -->
        <div class="modal-footer">
            <button class="btn-s" onclick="cerrarModal('podcastModal')">
                <i class="bi bi-x-lg"></i> Cancelar
            </button>
            <button class="btn-p" style="background:#7c3aed" onclick="guardarPodcast()">
                <i class="bi bi-check-lg"></i> <span id="btnPodcastLabel">Crear Episodio</span>
            </button>
        </div>
    </div>
</div>

<!-- Modal confirmar eliminación -->
<div class="confirm-modal" id="confirmModal">
    <div class="confirm-box">
        <div class="confirm-title" id="confirmTitle">⚠️ Confirmar eliminación</div>
        <div class="confirm-body" id="confirmBody">¿Estás seguro? Esta acción no se puede deshacer.</div>
        <div class="confirm-acts">
            <button class="btn-s" onclick="cerrarModal('confirmModal')">Cancelar</button>
            <button class="btn-danger" id="confirmBtn" onclick="ejecutarConfirm()">
                <i class="bi bi-trash-fill"></i> Eliminar
            </button>
        </div>
    </div>
</div>

<!-- Lightbox de imágenes -->
<div class="lightbox" id="lightbox" onclick="cerrarLightbox(event)">
    <button class="lightbox-close" onclick="cerrarModal('lightbox-close')"><i class="bi bi-x-lg"></i></button>
    <img id="lightboxImg" src="" alt="">
    <div class="lightbox-info">
        <div id="lightboxName" style="font-weight:700;margin-bottom:4px"></div>
        <div id="lightboxMeta" style="font-size:.8rem;opacity:.7"></div>
    </div>
    <div class="lightbox-actions" onclick="event.stopPropagation()">
        <button class="lightbox-act" style="background:var(--primary);color:#fff"
                onclick="copiarUrl(document.getElementById('lightboxImg').src)">
            <i class="bi bi-clipboard"></i> Copiar URL
        </button>
        <a id="lightboxDownload" href="" download class="lightbox-act"
           style="background:rgba(255,255,255,.15);color:#fff">
            <i class="bi bi-download"></i> Descargar
        </a>
        <button class="lightbox-act" style="background:rgba(239,68,68,.8);color:#fff"
                onclick="confirmarEliminarImg(document.getElementById('lightboxImg').dataset.path, document.getElementById('lightboxName').textContent)">
            <i class="bi bi-trash"></i> Eliminar
        </button>
    </div>
</div>

<!-- Toasts -->
<div id="toast-cnt"></div>

<!-- Campo CSRF para JS -->
<input type="hidden" id="csrfTokenField" value="<?= csrfToken() ?>">

<script>
/* ════════════════════════════════════════════════
   UTILS
════════════════════════════════════════════════ */
function getCsrf() {
    return document.getElementById('csrfTokenField')?.value ?? '';
}
function toggleSidebar() { document.getElementById('adminSidebar').classList.toggle('open'); }
function closeSidebar()  { document.getElementById('adminSidebar').classList.remove('open'); }

function showToast(msg, type = 'success', ms = 4000) {
    const c = document.getElementById('toast-cnt');
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    const icons = { success:'bi-check-circle-fill', error:'bi-x-circle-fill', info:'bi-info-circle-fill' };
    t.innerHTML = `<i class="bi ${icons[type]||'bi-info-circle-fill'}"></i>${msg}`;
    c.appendChild(t);
    setTimeout(() => { t.style.opacity='0'; t.style.transition='.3s'; }, ms-300);
    setTimeout(() => t.remove(), ms);
}

function post(data) {
    return fetch('<?= APP_URL ?>/admin/media.php', {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-Requested-With':'XMLHttpRequest' },
        body: JSON.stringify({ ...data, csrf: getCsrf() })
    }).then(r => r.json());
}

function cerrarModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('open');
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-bg.open, .confirm-modal.open, .lightbox.open')
                .forEach(m => m.classList.remove('open'));
    }
});

/* ════════════════════════════════════════════════
   SIDEBAR + UPLOAD ZONE
════════════════════════════════════════════════ */
function abrirUploadZone() {
    const w = document.getElementById('uploadZoneWrap');
    if (w) {
        w.style.display = w.style.display === 'none' ? 'block' : 'none';
        if (w.style.display === 'block') w.scrollIntoView({ behavior:'smooth' });
    }
}

/* ════════════════════════════════════════════════
   UPLOAD DE IMÁGENES
════════════════════════════════════════════════ */
function handleDrop(e) {
    e.preventDefault();
    document.getElementById('dropZone').classList.remove('drag-over');
    processFiles(e.dataTransfer.files);
}

function processFiles(files) {
    if (!files.length) return;
    const queue  = document.getElementById('uploadQueue');
    const carpeta = document.getElementById('uploadCarpeta')?.value;

    if (carpeta === '__nueva__') {
        const nombre = prompt('Nombre de la nueva carpeta (solo letras, números y guiones):');
        if (!nombre) return;
        const limpio = nombre.replace(/[^a-z0-9_\-]/gi,'').toLowerCase();
        if (!limpio) { showToast('Nombre de carpeta inválido.','error'); return; }
        document.getElementById('uploadCarpeta').value = limpio;
    }

    Array.from(files).forEach(file => {
        if (!file.type.startsWith('image/')) {
            showToast(`${file.name}: tipo no permitido.`, 'error'); return;
        }

        const uid  = 'up_' + Date.now() + Math.random().toString(36).slice(2,6);
        const item = document.createElement('div');
        item.id    = uid;
        item.style.cssText = 'background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--border-radius-lg);padding:10px 14px;margin-bottom:8px;display:flex;align-items:center;gap:12px';
        item.innerHTML = `
            <i class="bi bi-image" style="color:var(--info);font-size:1.1rem;flex-shrink:0"></i>
            <div style="flex:1;min-width:0">
                <div style="font-size:.82rem;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${file.name}</div>
                <div class="upload-progress-bar" style="margin-top:5px">
                    <div class="upload-progress-fill" id="${uid}_fill"></div>
                </div>
            </div>
            <span id="${uid}_status" style="font-size:.72rem;color:var(--text-muted);white-space:nowrap">Subiendo...</span>
        `;
        queue.prepend(item);

        const fd = new FormData();
        fd.append('archivo', file);
        fd.append('carpeta', document.getElementById('uploadCarpeta').value || 'galeria');
        fd.append('action', 'subir_imagen');
        fd.append('csrf_token', getCsrf());

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '<?= APP_URL ?>/admin/media.php');
        xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');

        xhr.upload.addEventListener('progress', e => {
            if (e.lengthComputable) {
                const pct = Math.round((e.loaded / e.total) * 100);
                const fill = document.getElementById(uid+'_fill');
                if (fill) fill.style.width = pct + '%';
            }
        });

        xhr.onload = () => {
            const fill   = document.getElementById(uid+'_fill');
            const status = document.getElementById(uid+'_status');
            try {
                const res = JSON.parse(xhr.responseText);
                if (res.success) {
                    if (fill)   fill.style.background = 'var(--success)';
                    if (status) status.innerHTML = '<span style="color:var(--success)"><i class="bi bi-check-circle-fill"></i> OK</span>';
                    showToast(`✅ ${file.name} subida correctamente.`, 'success');
                    // Agregar a la galería sin recargar
                    agregarAGaleria(res.url, res.filename, res.path);
                } else {
                    if (fill)   fill.style.background = 'var(--danger)';
                    if (status) status.innerHTML = `<span style="color:var(--danger)"><i class="bi bi-x-circle-fill"></i> Error</span>`;
                    showToast(`❌ ${file.name}: ${res.message}`, 'error');
                }
            } catch {
                showToast('Error al procesar respuesta.','error');
            }
        };
        xhr.onerror = () => showToast('Error de conexión al subir ' + file.name,'error');
        xhr.send(fd);
    });
}

function agregarAGaleria(url, filename, path) {
    const grid = document.getElementById('galleryGrid');
    if (!grid) return;
    const div = document.createElement('div');
    div.className = 'gallery-item';
    div.id        = 'gitem-' + btoa(path).replace(/=/g,'');
    div.dataset.path = path;
    div.dataset.url  = url;
    div.dataset.name = filename;
    div.setAttribute('onclick', "selectItem(this, event)");
    div.innerHTML = `
        <img src="${url}" alt="${filename}" loading="lazy" onerror="this.src='<?= APP_URL ?>/assets/images/default-news.jpg'">
        <div class="gallery-item__name">${filename}</div>
        <div class="gallery-item__overlay">
            <button class="gallery-btn" style="background:var(--primary);color:#fff"
                    onclick="event.stopPropagation();abrirLightbox('${url}','${filename}','${path}','')">
                <i class="bi bi-zoom-in"></i> Ver
            </button>
            <button class="gallery-btn" style="background:rgba(255,255,255,.2);color:#fff"
                    onclick="event.stopPropagation();copiarUrl('${url}')">
                <i class="bi bi-clipboard"></i> Copiar URL
            </button>
            <button class="gallery-btn" style="background:rgba(239,68,68,.8);color:#fff"
                    onclick="event.stopPropagation();confirmarEliminarImg('${path}','${filename}')">
                <i class="bi bi-trash"></i> Eliminar
            </button>
        </div>
    `;
    // Insertar al inicio
    grid.prepend(div);
}

/* ════════════════════════════════════════════════
   SELECCIÓN MÚLTIPLE EN GALERÍA
════════════════════════════════════════════════ */
const seleccionadas = new Set();

function selectItem(el, e) {
    if (e.ctrlKey || e.metaKey) {
        el.classList.toggle('selected');
        const path = el.dataset.path;
        if (el.classList.contains('selected')) {
            seleccionadas.add(path);
        } else {
            seleccionadas.delete(path);
        }
        updateSelBar();
    }
}

function updateSelBar() {
    const btn = document.getElementById('btnDeleteSelected');
    const cnt = document.getElementById('selCount');
    if (cnt) cnt.textContent = seleccionadas.size;
    if (btn) btn.style.display = seleccionadas.size > 0 ? 'inline-flex' : 'none';
}

function toggleSelectAll() {
    const items = document.querySelectorAll('.gallery-item');
    const allSelected = seleccionadas.size === items.length;
    seleccionadas.clear();
    items.forEach(item => {
        if (!allSelected) {
            item.classList.add('selected');
            seleccionadas.add(item.dataset.path);
        } else {
            item.classList.remove('selected');
        }
    });
    updateSelBar();
}

function eliminarSeleccionadas() {
    if (!seleccionadas.size) return;
    if (!confirm(`¿Eliminar las ${seleccionadas.size} imágenes seleccionadas? Esta acción no se puede deshacer.`)) return;
    const paths = Array.from(seleccionadas);
    let count = 0;
    paths.forEach(path => {
        post({ action:'eliminar_imagen', path }).then(d => {
            if (d.success) {
                const id = 'gitem-' + btoa(path).replace(/=/g,'');
                const md5Id = 'gitem-' + [...path].reduce((a,c) => (a^c.charCodeAt(0)*31)>>>0, 0).toString(16);
                document.querySelectorAll('.gallery-item').forEach(el => {
                    if (el.dataset.path === path) el.remove();
                });
                seleccionadas.delete(path);
                count++;
                if (count === paths.length) {
                    showToast(`✅ ${count} imágenes eliminadas.`,'success');
                    updateSelBar();
                }
            }
        });
    });
}

function setView(v) {
    const grid = document.getElementById('galleryGrid');
    const btnG = document.getElementById('btnGrid');
    const btnL = document.getElementById('btnList');
    if (!grid) return;
    if (v === 'list') {
        grid.style.gridTemplateColumns = '1fr';
        grid.querySelectorAll('.gallery-item').forEach(el => {
            el.style.aspectRatio = 'auto';
            el.style.height = '60px';
            el.style.flexDirection = 'row';
        });
        if (btnL) btnL.style.background = 'var(--primary)', btnL.style.color = '#fff';
        if (btnG) btnG.style.background = 'var(--bg-body)', btnG.style.color = 'var(--text-muted)';
    } else {
        grid.style.gridTemplateColumns = '';
        grid.querySelectorAll('.gallery-item').forEach(el => {
            el.style.aspectRatio = '4/3';
            el.style.height = '';
        });
        if (btnG) btnG.style.background = 'var(--primary)', btnG.style.color = '#fff';
        if (btnL) btnL.style.background = 'var(--bg-body)', btnL.style.color = 'var(--text-muted)';
    }
}

/* ════════════════════════════════════════════════
   LIGHTBOX
════════════════════════════════════════════════ */
function abrirLightbox(url, filename, path, size) {
    const lb   = document.getElementById('lightbox');
    const img  = document.getElementById('lightboxImg');
    const name = document.getElementById('lightboxName');
    const meta = document.getElementById('lightboxMeta');
    const dl   = document.getElementById('lightboxDownload');
    img.src       = url;
    img.dataset.path = path;
    name.textContent = filename;
    meta.textContent = size ? `Tamaño: ${size}` : '';
    dl.href         = url;
    dl.download     = filename;
    lb.classList.add('open');
}

function cerrarLightbox(e) {
    if (e.target === document.getElementById('lightbox')) {
        document.getElementById('lightbox').classList.remove('open');
    }
}

/* ════════════════════════════════════════════════
   COPIAR URL
════════════════════════════════════════════════ */
function copiarUrl(url) {
    navigator.clipboard.writeText(url).then(() => {
        showToast('✅ URL copiada al portapapeles.', 'info', 2500);
    }).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = url;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
        showToast('URL copiada.','info',2000);
    });
}

/* ════════════════════════════════════════════════
   ELIMINAR IMAGEN
════════════════════════════════════════════════ */
let _confirmCallback = null;

function confirmarEliminarImg(path, nombre) {
    document.getElementById('confirmTitle').textContent = '⚠️ Eliminar imagen';
    document.getElementById('confirmBody').innerHTML =
        `¿Eliminar <strong>${nombre}</strong>? Esta acción no se puede deshacer.`;
    _confirmCallback = () => {
        post({ action:'eliminar_imagen', path }).then(d => {
            cerrarModal('confirmModal');
            if (d.success) {
                showToast('🗑️ Imagen eliminada.', 'success');
                document.querySelectorAll('.gallery-item').forEach(el => {
                    if (el.dataset.path === path) {
                        el.style.transition = '.3s';
                        el.style.opacity = '0';
                        setTimeout(() => el.remove(), 300);
                    }
                });
                document.getElementById('lightbox').classList.remove('open');
            } else {
                showToast(d.message, 'error');
            }
        });
    };
    document.getElementById('confirmModal').classList.add('open');
}

function ejecutarConfirm() {
    if (_confirmCallback) { _confirmCallback(); _confirmCallback = null; }
}

/* ════════════════════════════════════════════════
   MODAL VIDEO — Abrir / Editar
════════════════════════════════════════════════ */
function abrirModalVideo(tipo) {
    document.getElementById('videoId').value = '0';
    document.getElementById('videoTitulo').value = '';
    document.getElementById('videoDesc').value   = '';
    document.getElementById('videoUrl').value    = '';
    document.getElementById('videoThumb').value  = '';
    document.getElementById('videoCat').value    = '';
    document.getElementById('videoDuracion').value = '';
    document.getElementById('videoFecha').value  = '';
    document.getElementById('videoActivo').checked    = true;
    document.getElementById('videoDestacado').checked = false;
    document.getElementById('videoAutoplay').checked  = false;
    document.getElementById('ytPreviewWrap').style.display   = 'none';
    document.getElementById('videoThumbPreview').style.display = 'none';
    document.getElementById('videoModalTitle').innerHTML =
        '<i class="bi bi-play-circle" style="color:var(--primary)"></i> Nuevo Video';
    document.getElementById('btnVideoLabel').textContent = 'Crear Video';

    // Si se especificó tipo (ej: desde tab YouTube)
    if (tipo) {
        const radio = document.querySelector(`input[name="videoTipo"][value="${tipo}"]`);
        if (radio) { radio.checked = true; cambiarTipoVideo(tipo); }
    } else {
        const r = document.querySelector('input[name="videoTipo"][value="youtube"]');
        if (r) { r.checked = true; cambiarTipoVideo('youtube'); }
    }

    document.getElementById('videoModal').classList.add('open');
}

function editarVideo(v) {
    document.getElementById('videoId').value          = v.id;
    document.getElementById('videoTitulo').value      = v.titulo;
    document.getElementById('videoDesc').value        = v.descripcion || '';
    document.getElementById('videoUrl').value         = v.url;
    document.getElementById('videoThumb').value       = v.thumbnail || '';
    document.getElementById('videoCat').value         = v.categoria_id || '';
    document.getElementById('videoDuracion').value    = v.duracion || '';
    document.getElementById('videoFecha').value       = v.fecha_publicacion
        ? v.fecha_publicacion.substring(0,16).replace(' ','T') : '';
    document.getElementById('videoActivo').checked    = !!parseInt(v.activo);
    document.getElementById('videoDestacado').checked = !!parseInt(v.destacado);
    document.getElementById('videoAutoplay').checked  = !!parseInt(v.autoplay);

    const radio = document.querySelector(`input[name="videoTipo"][value="${v.tipo}"]`);
    if (radio) { radio.checked = true; cambiarTipoVideo(v.tipo); }

    if (v.tipo === 'youtube') previewYoutube(v.url);

    if (v.thumbnail) {
        const url = v.thumbnail.startsWith('http') ? v.thumbnail : '<?= APP_URL ?>/' + v.thumbnail;
        const prev = document.getElementById('videoThumbPreview');
        const img  = document.getElementById('videoThumbPreviewImg');
        if (prev && img) { img.src = url; prev.style.display = 'block'; }
    }

    if (v.duracion > 0) {
        document.getElementById('durPreview').textContent = '= ' + formatDur(parseInt(v.duracion));
    }

    document.getElementById('videoModalTitle').innerHTML =
        '<i class="bi bi-pencil-fill" style="color:var(--info)"></i> Editar Video';
    document.getElementById('btnVideoLabel').textContent = 'Actualizar Video';
    document.getElementById('videoModal').classList.add('open');
}

function cambiarTipoVideo(tipo) {
    // Actualizar chips visuales
    document.querySelectorAll('.tipo-chip').forEach(c => {
        const isActive = c.dataset.tipo === tipo;
        const colors = {
            youtube:{ bg:'rgba(204,0,0,.1)', border:'#cc0000', text:'#cc0000' },
            mp4:    { bg:'rgba(59,130,246,.1)', border:'var(--info)', text:'var(--info)' },
            vimeo:  { bg:'rgba(26,183,234,.1)', border:'#1ab7ea', text:'#1ab7ea' },
            embed:  { bg:'rgba(107,114,128,.1)', border:'var(--border-color)', text:'var(--text-muted)' },
        };
        if (isActive) {
            const col = colors[tipo] || colors.embed;
            c.style.background = col.bg;
            c.style.borderColor = col.border;
            c.style.color = col.text;
        } else {
            c.style.background = 'var(--bg-body)';
            c.style.borderColor = 'var(--border-color)';
            c.style.color = 'var(--text-muted)';
        }
    });

    const hints = {
        youtube: 'Pega la URL completa de YouTube o solo el ID del video (11 caracteres).',
        mp4:     'URL directa del archivo MP4 (puede ser CDN o URL pública).',
        vimeo:   'URL del video de Vimeo (ej: https://vimeo.com/123456789).',
        embed:   'Pega el código <iframe> completo o la URL de embed del servicio.',
    };
    const labels = {
        youtube: 'URL o ID de YouTube',
        mp4:     'URL del archivo MP4',
        vimeo:   'URL de Vimeo',
        embed:   'Código iframe o URL embed',
    };
    const hintEl  = document.getElementById('urlVideoHint');
    const labelEl = document.getElementById('urlVideoLabel');
    if (hintEl)  hintEl.textContent  = hints[tipo] || '';
    if (labelEl) labelEl.innerHTML   = (labels[tipo]||'URL') + ' <span style="color:var(--danger)">*</span>';

    // Mostrar/ocultar botón Auto (solo YouTube)
    const btnAuto = document.getElementById('btnExtractYT');
    if (btnAuto) btnAuto.style.display = tipo === 'youtube' ? 'flex' : 'none';

    // Limpiar preview YouTube si cambió de tipo
    if (tipo !== 'youtube') {
        document.getElementById('ytPreviewWrap').style.display = 'none';
    }
}

function previewYoutube(url) {
    if (!url) return;
    const ytId = extraerIdYT(url);
    if (!ytId) { document.getElementById('ytPreviewWrap').style.display = 'none'; return; }
    const wrap  = document.getElementById('ytPreviewWrap');
    const img   = document.getElementById('ytThumbPreview');
    img.src     = `https://img.youtube.com/vi/${ytId}/mqdefault.jpg`;
    wrap.style.display = 'block';
    // Auto-completar thumbnail si está vacío
    const thumbField = document.getElementById('videoThumb');
    if (thumbField && !thumbField.value) {
        thumbField.value = `https://img.youtube.com/vi/${ytId}/maxresdefault.jpg`;
    }
}

function extraerIdYT(url) {
    const patterns = [
        /(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_\-]{11})/,
        /youtube\.com\/shorts\/([a-zA-Z0-9_\-]{11})/,
        /^([a-zA-Z0-9_\-]{11})$/,
    ];
    for (const p of patterns) {
        const m = url.match(p);
        if (m) return m[1];
    }
    return null;
}

async function extraerInfoYT() {
    const url  = document.getElementById('videoUrl').value.trim();
    const ytId = extraerIdYT(url);
    if (!ytId) { showToast('No se detectó un ID de YouTube válido.','error'); return; }
    // Intentar thumbnail
    document.getElementById('videoThumb').value =
        `https://img.youtube.com/vi/${ytId}/maxresdefault.jpg`;
    previewYoutube(url);
    showToast('Thumbnail de YouTube extraído.','info',2000);
}

/* ════════════════════════════════════════════════
   GUARDAR VIDEO
════════════════════════════════════════════════ */
async function guardarVideo() {
    const titulo = document.getElementById('videoTitulo').value.trim();
    const url    = document.getElementById('videoUrl').value.trim();
    const tipo   = document.querySelector('input[name="videoTipo"]:checked')?.value || 'youtube';

    if (!titulo) { showToast('El título es obligatorio.','error'); return; }
    if (!url)    { showToast('La URL es obligatoria.','error'); return; }

    const data = {
        action:            'guardar_video',
        id:                parseInt(document.getElementById('videoId').value) || 0,
        titulo,
        descripcion:       document.getElementById('videoDesc').value.trim(),
        tipo,
        url,
        thumbnail:         document.getElementById('videoThumb').value.trim(),
        categoria_id:      parseInt(document.getElementById('videoCat').value) || 0,
        duracion:          parseInt(document.getElementById('videoDuracion').value) || 0,
        fecha_publicacion: document.getElementById('videoFecha').value || '',
        activo:            document.getElementById('videoActivo').checked ? 1 : 0,
        destacado:         document.getElementById('videoDestacado').checked ? 1 : 0,
        autoplay:          document.getElementById('videoAutoplay').checked ? 1 : 0,
    };

    const btn = document.querySelector('#videoModal .btn-p');
    const origText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-arrow-repeat" style="animation:spin .8s linear infinite"></i> Guardando...';

    try {
        const d = await post(data);
        if (d.success) {
            showToast('✅ ' + d.message, 'success');
            cerrarModal('videoModal');
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast('❌ ' + d.message, 'error');
        }
    } catch {
        showToast('Error de conexión.','error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = origText;
    }
}

function eliminarVideo(id, titulo) {
    document.getElementById('confirmTitle').textContent = '⚠️ Eliminar video';
    document.getElementById('confirmBody').innerHTML =
        `¿Eliminar el video <strong>"${titulo.substring(0,50)}"</strong>?`;
    _confirmCallback = () => {
        post({ action:'eliminar_video', id }).then(d => {
            cerrarModal('confirmModal');
            if (d.success) {
                const row = document.getElementById('vrow-'+id);
                if (row) { row.style.opacity='0'; row.style.transition='.3s'; setTimeout(()=>row.remove(),300); }
                showToast('🗑️ Video eliminado.','success');
            } else {
                showToast(d.message,'error');
            }
        });
    };
    document.getElementById('confirmModal').classList.add('open');
}

function toggleVideo(id, campo, cb) {
    post({ action:'toggle_video', id, campo }).then(d => {
        if (d.success) {
            showToast('Estado actualizado.','info',1500);
        } else {
            cb.checked = !cb.checked;
            showToast(d.message,'error');
        }
    });
}

/* ════════════════════════════════════════════════
   MODAL PODCAST — Abrir / Editar
════════════════════════════════════════════════ */
function abrirModalPodcast() {
    document.getElementById('podcastId').value         = '0';
    document.getElementById('podcastTitulo').value     = '';
    document.getElementById('podcastDesc').value       = '';
    document.getElementById('podcastUrlAudio').value   = '';
    document.getElementById('podcastThumb').value      = '';
    document.getElementById('podcastCat').value        = '';
    document.getElementById('podcastDuracion').value   = '';
    document.getElementById('podcastTemporada').value  = '1';
    document.getElementById('podcastEpisodio').value   = '1';
    document.getElementById('podcastFecha').value      = '';
    document.getElementById('podcastActivo').checked   = true;
    document.getElementById('podcastDestacado').checked= false;
    document.getElementById('audioPreviewWrap').style.display  = 'none';
    document.getElementById('podThumbPreview').style.display   = 'none';
    document.getElementById('podcastModalTitle').innerHTML =
        '<i class="bi bi-mic" style="color:#7c3aed"></i> Nuevo Episodio';
    document.getElementById('btnPodcastLabel').textContent = 'Crear Episodio';
    document.getElementById('podcastModal').classList.add('open');
}

function editarPodcast(p) {
    document.getElementById('podcastId').value         = p.id;
    document.getElementById('podcastTitulo').value     = p.titulo;
    document.getElementById('podcastDesc').value       = p.descripcion || '';
    document.getElementById('podcastUrlAudio').value   = p.url_audio;
    document.getElementById('podcastThumb').value      = p.thumbnail || '';
    document.getElementById('podcastCat').value        = p.categoria_id || '';
    document.getElementById('podcastDuracion').value   = p.duracion || '';
    document.getElementById('podcastTemporada').value  = p.temporada || 1;
    document.getElementById('podcastEpisodio').value   = p.episodio  || 1;
    document.getElementById('podcastFecha').value      = p.fecha_publicacion
        ? p.fecha_publicacion.substring(0,16).replace(' ','T') : '';
    document.getElementById('podcastActivo').checked   = !!parseInt(p.activo);
    document.getElementById('podcastDestacado').checked= !!parseInt(p.destacado);

    if (p.url_audio && p.url_audio.match(/\.(mp3|ogg|m4a|wav)(\?|$)/i)) {
        const aw = document.getElementById('audioPreviewWrap');
        const as = document.getElementById('audioPreviewSrc');
        if (aw && as) { as.src = p.url_audio; aw.style.display = 'block'; }
    }

    if (p.thumbnail) {
        const url = p.thumbnail.startsWith('http') ? p.thumbnail : '<?= APP_URL ?>/' + p.thumbnail;
        const prev = document.getElementById('podThumbPreview');
        const img  = document.getElementById('podThumbPreviewImg');
        if (prev && img) { img.src = url; prev.style.display = 'block'; }
    }

    if (p.duracion > 0) {
        document.getElementById('podDurPreview').textContent = '= ' + formatDur(parseInt(p.duracion));
    }

    document.getElementById('podcastModalTitle').innerHTML =
        '<i class="bi bi-pencil-fill" style="color:var(--info)"></i> Editar Episodio';
    document.getElementById('btnPodcastLabel').textContent = 'Actualizar Episodio';
    document.getElementById('podcastModal').classList.add('open');
}

/* ════════════════════════════════════════════════
   GUARDAR PODCAST
════════════════════════════════════════════════ */
async function guardarPodcast() {
    const titulo   = document.getElementById('podcastTitulo').value.trim();
    const urlAudio = document.getElementById('podcastUrlAudio').value.trim();

    if (!titulo)   { showToast('El título es obligatorio.','error'); return; }
    if (!urlAudio) { showToast('La URL del audio es obligatoria.','error'); return; }

    const data = {
        action:            'guardar_podcast',
        id:                parseInt(document.getElementById('podcastId').value) || 0,
        titulo,
        descripcion:       document.getElementById('podcastDesc').value.trim(),
        url_audio:         urlAudio,
        thumbnail:         document.getElementById('podcastThumb').value.trim(),
        categoria_id:      parseInt(document.getElementById('podcastCat').value) || 0,
        duracion:          parseInt(document.getElementById('podcastDuracion').value) || 0,
        temporada:         parseInt(document.getElementById('podcastTemporada').value) || 1,
        episodio:          parseInt(document.getElementById('podcastEpisodio').value) || 1,
        fecha_publicacion: document.getElementById('podcastFecha').value || '',
        activo:            document.getElementById('podcastActivo').checked ? 1 : 0,
        destacado:         document.getElementById('podcastDestacado').checked ? 1 : 0,
    };

    const btn = document.querySelector('#podcastModal .btn-p');
    const origText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-arrow-repeat" style="animation:spin .8s linear infinite"></i> Guardando...';

    try {
        const d = await post(data);
        if (d.success) {
            showToast('✅ ' + d.message, 'success');
            cerrarModal('podcastModal');
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast('❌ ' + d.message, 'error');
        }
    } catch {
        showToast('Error de conexión.','error');
    } finally {
        btn.disabled   = false;
        btn.innerHTML  = origText;
    }
}

function eliminarPodcast(id, titulo) {
    document.getElementById('confirmTitle').textContent = '⚠️ Eliminar podcast';
    document.getElementById('confirmBody').innerHTML =
        `¿Eliminar el episodio <strong>"${titulo.substring(0,50)}"</strong>?`;
    _confirmCallback = () => {
        post({ action:'eliminar_podcast', id }).then(d => {
            cerrarModal('confirmModal');
            if (d.success) {
                const row = document.getElementById('prow-'+id);
                if (row) { row.style.opacity='0'; row.style.transition='.3s'; setTimeout(()=>row.remove(),300); }
                showToast('🗑️ Podcast eliminado.','success');
            } else {
                showToast(d.message,'error');
            }
        });
    };
    document.getElementById('confirmModal').classList.add('open');
}

function togglePodcast(id, campo, cb) {
    post({ action:'toggle_podcast', id, campo }).then(d => {
        if (d.success) {
            showToast('Estado actualizado.','info',1500);
        } else {
            cb.checked = !cb.checked;
            showToast(d.message,'error');
        }
    });
}

/* ════════════════════════════════════════════════
   SUBIR THUMBNAIL (video/podcast)
════════════════════════════════════════════════ */
function subirThumb(input, tipo, targetFieldId, previewImgId) {
    if (!input.files?.length) return;
    const file = input.files[0];
    const fd   = new FormData();
    fd.append('thumbnail', file);
    fd.append('tipo', tipo);
    fd.append('action', 'subir_thumbnail');
    fd.append('csrf_token', getCsrf());

    showToast('Subiendo imagen...','info',2000);

    fetch('<?= APP_URL ?>/admin/media.php', {
        method: 'POST',
        headers: { 'X-Requested-With':'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            document.getElementById(targetFieldId).value = d.path;
            const prev = document.getElementById(previewImgId);
            const wrap = prev?.parentElement;
            if (prev) { prev.src = d.url; }
            if (wrap) { wrap.style.display = 'block'; }
            showToast('✅ Imagen subida.','success');
        } else {
            showToast('❌ ' + (d.message || 'Error al subir.'),'error');
        }
    })
    .catch(() => showToast('Error de conexión.','error'));
}

/* ════════════════════════════════════════════════
   AUDIO PREVIEW en modal podcast
════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
    const audioUrlInp = document.getElementById('podcastUrlAudio');
    if (audioUrlInp) {
        audioUrlInp.addEventListener('blur', () => {
            const url = audioUrlInp.value.trim();
            if (url && url.match(/\.(mp3|ogg|m4a|wav)(\?|$)/i)) {
                const aw = document.getElementById('audioPreviewWrap');
                const as = document.getElementById('audioPreviewSrc');
                const ae = document.getElementById('audioPreviewEl');
                if (as && aw && ae) {
                    as.src = url;
                    ae.load();
                    aw.style.display = 'block';
                }
            }
        });
    }

    // Preview duración formateada
    ['videoDuracion','podcastDuracion'].forEach(id => {
        const el = document.getElementById(id);
        const prevId = id === 'videoDuracion' ? 'durPreview' : 'podDurPreview';
        if (el) {
            el.addEventListener('input', () => {
                const v = parseInt(el.value) || 0;
                const prev = document.getElementById(prevId);
                if (prev) prev.textContent = v > 0 ? '= ' + formatDur(v) : '';
            });
        }
    });
});

/* ════════════════════════════════════════════════
   UTILIDAD: Formatear duración (segundos → HH:MM:SS)
════════════════════════════════════════════════ */
function formatDur(seg) {
    const h = Math.floor(seg / 3600);
    const m = Math.floor((seg % 3600) / 60);
    const s = seg % 60;
    if (h > 0) return `${h}h ${m.toString().padStart(2,'0')}m`;
    return `${m}:${s.toString().padStart(2,'0')}`;
}

/* ════════════════════════════════════════════════
   CSS spin animation
════════════════════════════════════════════════ */
const styleEl = document.createElement('style');
styleEl.textContent = '@keyframes spin { from{transform:rotate(0)}to{transform:rotate(360deg)} }';
document.head.appendChild(styleEl);
</script>
</body>
</html>