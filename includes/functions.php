<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Funciones Globales del Sistema
 * ============================================================
 * Archivo : includes/functions.php
 * Versión : 2.0.0
 * ============================================================
 * ÍNDICE DE SECCIONES:
 *  01. Utilidades generales (escape, limpieza, slugs)
 *  02. Formateo de fechas y números
 *  03. Paginación
 *  04. SEO y Meta tags
 *  05. Imágenes y archivos
 *  06. Noticias (CRUD + queries)
 *  07. Categorías
 *  08. Tags
 *  09. Comentarios
 *  10. Reacciones
 *  11. Encuestas
 *  12. Favoritos
 *  13. Anuncios
 *  14. Videos
 *  15. Live Blog
 *  16. Usuarios y perfiles
 *  17. Seguidores
 *  18. Insignias y reputación
 *  19. Notificaciones
 *  20. Newsletter
 *  21. Compartidos y analytics
 *  22. Configuración global
 *  23. Clima
 *  24. Búsqueda avanzada
 *  25. PWA y Mobile
 *  26. Utilidades admin
 * ============================================================
 */

declare(strict_types=1);

if (!defined('APP_NAME')) {
    require_once dirname(__DIR__) . '/config/database.php';
}

// ============================================================
// 01. UTILIDADES GENERALES
// ============================================================

/**
 * Escape HTML seguro (alias global)
 */
if (!function_exists('e')) {
    function e(mixed $val): string {
        return htmlspecialchars(
            (string)($val ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

/**
 * Limpiar input de texto
 */
function cleanInput(mixed $val, int $maxLength = 0): string
{
    $val = trim(strip_tags((string)($val ?? '')));
    $val = htmlspecialchars_decode($val, ENT_QUOTES);
    $val = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $val);
    if ($maxLength > 0) {
        $val = mb_substr($val, 0, $maxLength);
    }
    return trim($val);
}

/**
 * Limpiar entero
 */
function cleanInt(mixed $val, int $min = 0, int $max = PHP_INT_MAX): int
{
    $int = (int)filter_var($val, FILTER_SANITIZE_NUMBER_INT);
    return max($min, min($max, $int));
}

/**
 * Limpiar float
 */
function cleanFloat(mixed $val): float
{
    return (float)filter_var($val, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
}

/**
 * Obtener IP del cliente
 */
function getClientIp(): string
{
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR',
    ];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Generar slug único para tabla
 */
function generateSlug(string $texto, string $tabla = 'noticias', int $excludeId = 0): string
{
    // Transliterar caracteres especiales
    $mapa = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
        'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u',
        'ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o','ü'=>'u',
        'â'=>'a','ê'=>'e','î'=>'i','ô'=>'o','û'=>'u',
        'ñ'=>'n','ç'=>'c','ß'=>'ss','ø'=>'o','å'=>'a',
        'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u',
        'Ñ'=>'n','Ü'=>'u',
    ];
    $texto    = strtr($texto, $mapa);
    $baseSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $texto), '-'));
    $baseSlug = preg_replace('/-+/', '-', $baseSlug);
    $baseSlug = mb_substr($baseSlug, 0, 180);

    $slug    = $baseSlug;
    $counter = 2;

    while (true) {
        $sql = $excludeId > 0
            ? "SELECT COUNT(*) FROM `$tabla` WHERE slug = ? AND id != ?"
            : "SELECT COUNT(*) FROM `$tabla` WHERE slug = ?";
        $params = $excludeId > 0 ? [$slug, $excludeId] : [$slug];
        $exists = db()->count($sql, $params);

        if ($exists === 0) break;
        $slug = $baseSlug . '-' . $counter++;
    }

    return $slug;
}

/**
 * Truncar texto a N palabras
 */
function truncateText(string $text, int $words = 20, string $suffix = '…'): string
{
    $text  = strip_tags($text);
    $parts = explode(' ', trim($text));
    if (count($parts) <= $words) return $text;
    return implode(' ', array_slice($parts, 0, $words)) . $suffix;
}

/**
 * Truncar texto a N caracteres
 */
function truncateChars(string $text, int $chars = 150, string $suffix = '…'): string
{
    $text = strip_tags($text);
    if (mb_strlen($text) <= $chars) return $text;
    return mb_substr($text, 0, $chars) . $suffix;
}

/**
 * Calcular tiempo de lectura estimado
 */
function calcularTiempoLectura(string $contenido): int
{
    $texto    = strip_tags($contenido);
    $palabras = str_word_count($texto);
    $minutos  = (int)ceil($palabras / 200);
    return max(1, $minutos);
}

/**
 * Formatear número (1K, 1M)
 */
function formatNumber(int|float $n): string
{
    if ($n >= 1_000_000) return round($n / 1_000_000, 1) . 'M';
    if ($n >= 1_000)     return round($n / 1_000, 1) . 'K';
    return number_format((float)$n, 0, ',', '.');
}

/**
 * Generar color hexadecimal aleatorio
 */
function randomColor(): string
{
    return sprintf('#%06x', random_int(0, 0xFFFFFF));
}

/**
 * Detectar si es dispositivo móvil
 */
function isMobile(): bool
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return (bool)preg_match(
        '/android|webos|iphone|ipad|ipod|blackberry|windows phone/i',
        $ua
    );
}

/**
 * Obtener extensión de un archivo
 */
function getFileExtension(string $filename): string
{
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

// ============================================================
// 02. FORMATEO DE FECHAS
// ============================================================

/**
 * Fecha relativa (hace X tiempo)
 */
function timeAgo(string $datetime): string
{
    $now  = new DateTime();
    $then = new DateTime($datetime);
    $diff = $now->diff($then);

    if ($diff->y >= 1) return $diff->y === 1 ? 'hace 1 año'    : "hace {$diff->y} años";
    if ($diff->m >= 1) return $diff->m === 1 ? 'hace 1 mes'    : "hace {$diff->m} meses";
    if ($diff->d >= 7) {
        $w = (int)floor($diff->d / 7);
        return $w === 1 ? 'hace 1 semana' : "hace {$w} semanas";
    }
    if ($diff->d >= 1) return $diff->d === 1 ? 'hace 1 día'    : "hace {$diff->d} días";
    if ($diff->h >= 1) return $diff->h === 1 ? 'hace 1 hora'   : "hace {$diff->h} horas";
    if ($diff->i >= 1) return $diff->i === 1 ? 'hace 1 minuto' : "hace {$diff->i} minutos";
    return 'hace un momento';
}

/**
 * Formatear fecha en español
 */
function formatDate(string $datetime, string $format = 'full'): string
{
    static $meses = [
        1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',
        5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',
        9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre',
    ];
    static $dias = [
        'Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles',
        'Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado','Sunday'=>'Domingo',
    ];

    try {
        $dt     = new DateTime($datetime);
        $dia    = $dt->format('j');
        $mes    = $meses[(int)$dt->format('n')];
        $anio   = $dt->format('Y');
        $hora   = $dt->format('g:i A');
        $diaSem = $dias[$dt->format('l')] ?? $dt->format('l');

        return match($format) {
            'short'   => "$dia de $mes de $anio",
            'time'    => $hora,
            'day'     => "$diaSem, $dia de $mes",
            'medium'  => "$dia $mes $anio",
            'iso'     => $dt->format('Y-m-d'),
            'iso_full'=> $dt->format('Y-m-d H:i:s'),
            'full'    => "$diaSem $dia de $mes de $anio · $hora",
            default   => "$dia de $mes de $anio",
        };
    } catch (\Throwable $e) {
        return $datetime;
    }
}

/**
 * Formatear duración en segundos a mm:ss o hh:mm:ss
 */
function formatDuration(int $segundos): string
{
    $h = (int)floor($segundos / 3600);
    $m = (int)floor(($segundos % 3600) / 60);
    $s = $segundos % 60;

    if ($h > 0) {
        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }
    return sprintf('%d:%02d', $m, $s);
}

// ============================================================
// 03. PAGINACIÓN
// ============================================================

/**
 * Generar datos de paginación
 */
function paginate(int $total, int $currentPage, int $perPage = NOTICIAS_POR_PAGINA): array
{
    $totalPages  = max(1, (int)ceil($total / $perPage));
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset      = ($currentPage - 1) * $perPage;

    return [
        'total'        => $total,
        'per_page'     => $perPage,
        'current_page' => $currentPage,
        'total_pages'  => $totalPages,
        'offset'       => $offset,
        'has_prev'     => $currentPage > 1,
        'has_next'     => $currentPage < $totalPages,
        'prev_page'    => $currentPage - 1,
        'next_page'    => $currentPage + 1,
        'from'         => $offset + 1,
        'to'           => min($offset + $perPage, $total),
    ];
}

/**
 * Renderizar HTML de paginación
 */
function renderPagination(array $pagination, string $baseUrl = ''): string
{
    if ($pagination['total_pages'] <= 1) return '';

    $baseUrl = $baseUrl ?: APP_URL . strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $sep     = str_contains($baseUrl, '?') ? '&' : '?';
    $cp      = $pagination['current_page'];
    $tp      = $pagination['total_pages'];
    $range   = 2;

    $html  = '<nav aria-label="Paginación" class="pagination-nav">';
    $html .= '<ul class="pagination">';

    // Botón anterior
    if ($pagination['has_prev']) {
        $html .= sprintf(
            '<li class="page-item"><a class="page-link" href="%s%spagina=%d" aria-label="Anterior">'.
            '<i class="bi bi-chevron-left"></i></a></li>',
            $baseUrl, $sep, $pagination['prev_page']
        );
    }

    for ($i = 1; $i <= $tp; $i++) {
        if ($i === 1 || $i === $tp ||
            ($i >= $cp - $range && $i <= $cp + $range)) {
            $active = $i === $cp ? ' active" aria-current="page' : '';
            $html  .= sprintf(
                '<li class="page-item%s"><a class="page-link" href="%s%spagina=%d">%d</a></li>',
                $active, $baseUrl, $sep, $i, $i
            );
        } elseif ($i === $cp - $range - 1 || $i === $cp + $range + 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
    }

    // Botón siguiente
    if ($pagination['has_next']) {
        $html .= sprintf(
            '<li class="page-item"><a class="page-link" href="%s%spagina=%d" aria-label="Siguiente">'.
            '<i class="bi bi-chevron-right"></i></a></li>',
            $baseUrl, $sep, $pagination['next_page']
        );
    }

    $html .= '</ul></nav>';
    return $html;
}

// ============================================================
// 04. SEO Y META TAGS
// ============================================================

/**
 * Generar meta tags completos para SEO y Open Graph
 */
function generateMetaTags(array $meta = []): string
{
    $siteName   = Config::get('site_nombre', APP_NAME);
    $title      = e($meta['title']       ?? $siteName);
    $desc       = e($meta['description'] ?? Config::get('site_descripcion_seo', APP_TAGLINE));
    $image      = e($meta['image']       ?? IMG_DEFAULT_OG);
    $url        = e($meta['url']         ?? APP_URL . ($_SERVER['REQUEST_URI'] ?? '/'));
    $type       = e($meta['type']        ?? 'website');
    $author     = e($meta['author']      ?? $siteName);
    $keywords   = e($meta['keywords']    ?? '');

    $tags  = "<title>{$title}</title>\n";
    $tags .= "    <meta name=\"description\" content=\"{$desc}\">\n";
    if ($keywords) {
        $tags .= "    <meta name=\"keywords\" content=\"{$keywords}\">\n";
    }
    $tags .= "    <meta name=\"author\" content=\"{$author}\">\n";
    $tags .= "    <meta name=\"robots\" content=\"index, follow\">\n";
    $tags .= "    <link rel=\"canonical\" href=\"{$url}\">\n";

    // Open Graph
    $tags .= "    <meta property=\"og:title\" content=\"{$title}\">\n";
    $tags .= "    <meta property=\"og:description\" content=\"{$desc}\">\n";
    $tags .= "    <meta property=\"og:image\" content=\"{$image}\">\n";
    $tags .= "    <meta property=\"og:url\" content=\"{$url}\">\n";
    $tags .= "    <meta property=\"og:type\" content=\"{$type}\">\n";
    $tags .= "    <meta property=\"og:site_name\" content=\"" . e($siteName) . "\">\n";
    $tags .= "    <meta property=\"og:locale\" content=\"es_DO\">\n";

    // Twitter Card
    $tags .= "    <meta name=\"twitter:card\" content=\"summary_large_image\">\n";
    $tags .= "    <meta name=\"twitter:title\" content=\"{$title}\">\n";
    $tags .= "    <meta name=\"twitter:description\" content=\"{$desc}\">\n";
    $tags .= "    <meta name=\"twitter:image\" content=\"{$image}\">\n";

    $twitter = Config::get('social_twitter', '');
    if ($twitter) {
        $handle = '@' . ltrim(basename(rtrim($twitter, '/')), '@');
        $tags  .= "    <meta name=\"twitter:site\" content=\"" . e($handle) . "\">\n";
    }

    // Article meta
    if ($type === 'article') {
        if (!empty($meta['published_time'])) {
            $tags .= "    <meta property=\"article:published_time\" content=\"" . e($meta['published_time']) . "\">\n";
        }
        if (!empty($meta['modified_time'])) {
            $tags .= "    <meta property=\"article:modified_time\" content=\"" . e($meta['modified_time']) . "\">\n";
        }
        if (!empty($meta['section'])) {
            $tags .= "    <meta property=\"article:section\" content=\"" . e($meta['section']) . "\">\n";
        }
    }

    // Schema.org JSON-LD para noticias
    if ($type === 'article' && !empty($meta['schema'])) {
        $schema = $meta['schema'];
        $tags  .= "    <script type=\"application/ld+json\">\n";
        $tags  .= '    ' . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        $tags  .= "    </script>\n";
    }

    return $tags;
}

/**
 * Generar Schema.org para noticia individual
 */
function generateNewsSchema(array $noticia): array
{
    return [
        '@context'         => 'https://schema.org',
        '@type'            => 'NewsArticle',
        'headline'         => $noticia['titulo'],
        'description'      => $noticia['resumen'] ?? truncateChars(strip_tags($noticia['contenido']), 160),
        'image'            => getImageUrl($noticia['imagen'] ?? ''),
        'datePublished'    => $noticia['fecha_publicacion'] ?? '',
        'dateModified'     => $noticia['fecha_update']      ?? $noticia['fecha_publicacion'] ?? '',
        'author'           => [
            '@type' => 'Person',
            'name'  => $noticia['autor_nombre'] ?? Config::get('site_nombre', APP_NAME),
        ],
        'publisher'        => [
            '@type' => 'Organization',
            'name'  => Config::get('site_nombre', APP_NAME),
            'logo'  => [
                '@type' => 'ImageObject',
                'url'   => APP_URL . '/' . ltrim(Config::get('site_logo', ''), '/'),
            ],
        ],
        'mainEntityOfPage' => APP_URL . '/noticia.php?slug=' . ($noticia['slug'] ?? ''),
    ];
}

// ============================================================
// 05. IMÁGENES Y ARCHIVOS
// ============================================================

/**
 * Obtener URL de imagen con fallback
 */
function getImageUrl(?string $imagen, string $type = 'news'): string
{
    if (empty($imagen)) {
        return match($type) {
            'avatar' => IMG_DEFAULT_AVATAR,
            default  => IMG_DEFAULT_NEWS,
        };
    }
    if (str_starts_with($imagen, 'http')) return $imagen;
    return APP_URL . '/' . ltrim($imagen, '/');
}

/**
 * Subir imagen al servidor con validación y optimización
 */
function uploadImage(array $file, string $subdir = 'noticias', string $prefix = 'img'): array
{
    $allowedTypes = ['image/jpeg','image/png','image/webp','image/gif'];
    $allowedExts  = ['jpg','jpeg','png','webp','gif'];

    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE   => 'Archivo demasiado grande (límite PHP.ini).',
            UPLOAD_ERR_FORM_SIZE  => 'Archivo demasiado grande (límite formulario).',
            UPLOAD_ERR_NO_FILE    => 'No se seleccionó ningún archivo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Directorio temporal no disponible.',
        ];
        return ['success'=>false, 'error' => $errors[$file['error']] ?? 'Error al cargar archivo.'];
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success'=>false, 'error' => 'El archivo excede el tamaño máximo de 5MB.'];
    }

    // Verificar tipo MIME real
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowedTypes, true)) {
        return ['success'=>false, 'error' => 'Tipo de archivo no permitido. Solo JPG, PNG, WebP y GIF.'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts, true)) {
        return ['success'=>false, 'error' => 'Extensión no permitida.'];
    }

    // Directorio destino
    $destDir = ROOT_PATH . '/assets/images/' . $subdir . '/';
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);

    $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $destDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return ['success'=>false, 'error' => 'No se pudo guardar la imagen.'];
    }

    $relativePath = 'assets/images/' . $subdir . '/' . $filename;

    return [
        'success'  => true,
        'filename' => $filename,
        'path'     => $relativePath,
        'url'      => APP_URL . '/' . $relativePath,
    ];
}

/**
 * Eliminar imagen del servidor
 */
function deleteImage(?string $path): bool
{
    if (empty($path)) return false;
    $fullPath = ROOT_PATH . '/' . ltrim($path, '/');
    if (file_exists($fullPath) && is_file($fullPath)) {
        return @unlink($fullPath);
    }
    return false;
}

/**
 * Obtener dimensiones de imagen
 */
function getImageDimensions(string $path): array
{
    $fullPath = ROOT_PATH . '/' . ltrim($path, '/');
    if (!file_exists($fullPath)) return ['width'=>0,'height'=>0];
    [$w, $h] = @getimagesize($fullPath) ?: [0,0];
    return ['width'=>$w,'height'=>$h];
}

// ============================================================
// 06. NOTICIAS
// ============================================================

/**
 * Obtener noticias destacadas para el hero
 */
function getNoticiasDestacadas(int $limit = 5): array
{
    return db()->fetchAll(
        "SELECT n.id, n.titulo, n.slug, n.resumen, n.imagen, n.vistas,
                n.fecha_publicacion, n.breaking, n.es_premium, n.tiempo_lectura,
                n.total_compartidos, n.total_reacciones,
                u.nombre AS autor_nombre, u.avatar AS autor_avatar,
                c.nombre AS cat_nombre, c.slug AS cat_slug,
                c.color AS cat_color, c.icono AS cat_icono
         FROM noticias n
         INNER JOIN usuarios   u ON u.id = n.autor_id
         INNER JOIN categorias c ON c.id = n.categoria_id
         WHERE n.estado = 'publicado'
           AND n.destacado = 1
           AND n.fecha_publicacion <= NOW()
         ORDER BY n.fecha_publicacion DESC
         LIMIT ?",
        [$limit]
    );
}

/**
 * Obtener noticias recientes con paginación y filtros
 */
function getNoticiasRecientes(
    int $limit = NOTICIAS_POR_PAGINA,
    int $offset = 0,
    ?int $categoriaId = null,
    string $orden = 'recientes'
): array {
    $where  = ["n.estado = 'publicado'", "n.fecha_publicacion <= NOW()"];
    $params = [];

    if ($categoriaId !== null) {
        $where[]  = 'n.categoria_id = ?';
        $params[] = $categoriaId;
    }

    $orderBy = match($orden) {
        'populares'  => 'n.vistas DESC',
        'comentados' => '(SELECT COUNT(*) FROM comentarios co WHERE co.noticia_id = n.id AND co.aprobado = 1) DESC',
        'aleatorio'  => 'RAND()',
        default      => 'n.fecha_publicacion DESC',
    };

    $params[] = $limit;
    $params[] = $offset;

    return db()->fetchAll(
        "SELECT n.id, n.titulo, n.slug, n.resumen, n.imagen, n.vistas,
                n.fecha_publicacion, n.destacado, n.breaking, n.es_premium,
                n.tiempo_lectura, n.total_compartidos,
                u.nombre AS autor_nombre,
                c.nombre AS cat_nombre, c.slug AS cat_slug,
                c.color AS cat_color, c.icono AS cat_icono,
                (SELECT COUNT(*) FROM comentarios co
                 WHERE co.noticia_id = n.id AND co.aprobado = 1) AS total_comentarios
         FROM noticias n
         INNER JOIN usuarios   u ON u.id = n.autor_id
         INNER JOIN categorias c ON c.id = n.categoria_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY $orderBy
         LIMIT ? OFFSET ?",
        $params
    );
}

/**
 * Contar noticias publicadas
 */
function countNoticias(?int $categoriaId = null): int
{
    if ($categoriaId !== null) {
        return db()->count(
            "SELECT COUNT(*) FROM noticias
             WHERE estado = 'publicado' AND fecha_publicacion <= NOW() AND categoria_id = ?",
            [$categoriaId]
        );
    }
    return db()->count(
        "SELECT COUNT(*) FROM noticias
         WHERE estado = 'publicado' AND fecha_publicacion <= NOW()"
    );
}

/**
 * Obtener noticia por slug
 */
function getNoticiaPorSlug(string $slug): ?array
{
    return db()->fetchOne(
        "SELECT n.*,
                u.nombre AS autor_nombre, u.bio AS autor_bio,
                u.avatar AS autor_avatar, u.verificado AS autor_verificado,
                u.reputacion AS autor_reputacion,
                c.nombre AS cat_nombre, c.slug AS cat_slug,
                c.color AS cat_color, c.icono AS cat_icono
         FROM noticias n
         INNER JOIN usuarios   u ON u.id = n.autor_id
         INNER JOIN categorias c ON c.id = n.categoria_id
         WHERE n.slug = ?
           AND n.estado = 'publicado'
           AND n.fecha_publicacion <= NOW()
         LIMIT 1",
        [$slug]
    );
}

/**
 * Obtener noticia por ID (para admin)
 */
function getNoticiaById(int $id): ?array
{
    return db()->fetchOne(
        "SELECT n.*,
                u.nombre AS autor_nombre,
                c.nombre AS cat_nombre, c.color AS cat_color
         FROM noticias n
         INNER JOIN usuarios   u ON u.id = n.autor_id
         INNER JOIN categorias c ON c.id = n.categoria_id
         WHERE n.id = ? LIMIT 1",
        [$id]
    );
}

/**
 * Obtener noticias relacionadas
 */
function getNoticiasRelacionadas(int $noticiaId, int $categoriaId, int $limit = 4): array
{
    return db()->fetchAll(
        "SELECT n.id, n.titulo, n.slug, n.imagen,
                n.fecha_publicacion, n.vistas, n.tiempo_lectura,
                c.nombre AS cat_nombre, c.color AS cat_color
         FROM noticias n
         INNER JOIN categorias c ON c.id = n.categoria_id
         WHERE n.estado = 'publicado'
           AND n.categoria_id = ?
           AND n.id != ?
           AND n.fecha_publicacion <= NOW()
         ORDER BY n.fecha_publicacion DESC
         LIMIT ?",
        [$categoriaId, $noticiaId, $limit]
    );
}

/**
 * Obtener noticias trending (más vistas últimas 48h)
 */
function getNoticiasTrending(int $limit = 8, string $periodo = '48 HOUR'): array
{
    return db()->fetchAll(
        "SELECT n.id, n.titulo, n.slug, n.imagen,
                n.vistas, n.fecha_publicacion, n.tiempo_lectura,
                n.total_compartidos, n.total_reacciones,
                c.nombre AS cat_nombre, c.color AS cat_color
         FROM noticias n
         INNER JOIN categorias c ON c.id = n.categoria_id
         WHERE n.estado = 'publicado'
           AND n.fecha_publicacion >= NOW() - INTERVAL $periodo
         ORDER BY (n.vistas + n.total_compartidos * 3 + n.total_reacciones * 2) DESC
         LIMIT ?",
        [$limit]
    );
}

/**
 * Obtener noticias más populares por período
 */
function getNoticiasPopulares(int $limit = 10, string $periodo = 'semana'): array
{
    $intervalo = match($periodo) {
        'mes'    => '30 DAY',
        'anio'   => '365 DAY',
        default  => '7 DAY',
    };

    return db()->fetchAll(
        "SELECT n.id, n.titulo, n.slug, n.imagen, n.vistas,
                n.fecha_publicacion, n.total_compartidos, n.total_reacciones,
                u.nombre AS autor_nombre,
                c.nombre AS cat_nombre, c.color AS cat_color,
                (SELECT COUNT(*) FROM comentarios co
                 WHERE co.noticia_id = n.id AND co.aprobado = 1) AS total_comentarios
         FROM noticias n
         INNER JOIN usuarios   u ON u.id = n.autor_id
         INNER JOIN categorias c ON c.id = n.categoria_id
         WHERE n.estado = 'publicado'
           AND n.fecha_publicacion >= NOW() - INTERVAL $intervalo
         ORDER BY (n.vistas + n.total_compartidos * 3 + n.total_reacciones * 2) DESC
         LIMIT ?",
        [$limit]
    );
}

/**
 * Obtener breaking news activas
 */
function getBreakingNews(): array
{
    return db()->fetchAll(
        "SELECT titulo, slug FROM noticias
         WHERE estado = 'publicado'
           AND breaking = 1
           AND fecha_publicacion <= NOW()
         ORDER BY fecha_publicacion DESC
         LIMIT 8"
    );
}

/**
 * Obtener noticias de opinión
 */
function getNoticiasOpinion(int $limit = 4): array
{
    return db()->fetchAll(
        "SELECT n.id, n.titulo, n.slug, n.imagen,
                n.fecha_publicacion, n.vistas, n.tiempo_lectura,
                u.nombre AS autor_nombre, u.avatar AS autor_avatar,
                u.bio    AS autor_bio,
                c.nombre AS cat_nombre, c.color AS cat_color
         FROM noticias n
         INNER JOIN usuarios   u ON u.id = n.autor_id
         INNER JOIN categorias c ON c.id = n.categoria_id
         WHERE n.estado = 'publicado'
           AND c.slug   = 'opinion'
           AND n.fecha_publicacion <= NOW()
         ORDER BY n.fecha_publicacion DESC
         LIMIT ?",
        [$limit]
    );
}

/**
 * Obtener noticias recomendadas para un usuario (basado en categorías seguidas)
 */
function getNoticiasRecomendadas(int $usuarioId, int $limit = 6): array
{
    // Obtener categorías que sigue el usuario
    $cats = db()->fetchAll(
        "SELECT categoria_id FROM seguir_categorias WHERE usuario_id = ?",
        [$usuarioId]
    );
    $catIds = array_column($cats, 'categoria_id');

    // Obtener noticias ya leídas (favoritos)
    $leidas = db()->fetchAll(
        "SELECT noticia_id FROM favoritos WHERE usuario_id = ?",
        [$usuarioId]
    );
    $leidasIds = array_column($leidas, 'noticia_id');

    if (empty($catIds)) {
        // Si no sigue categorías, retornar trending
        return getNoticiasTrending($limit);
    }

    $placeholdersCats = implode(',', array_fill(0, count($catIds), '?'));
    $params = $catIds;

    $excludeClause = '';
    if (!empty($leidasIds)) {
        $placeholdersLeidas = implode(',', array_fill(0, count($leidasIds), '?'));
        $excludeClause = "AND n.id NOT IN ($placeholdersLeidas)";
        $params = array_merge($params, $leidasIds);
    }

    $params[] = $limit;

    return db()->fetchAll(
        "SELECT n.id, n.titulo, n.slug, n.imagen,
                n.fecha_publicacion, n.vistas, n.tiempo_lectura,
                c.nombre AS cat_nombre, c.color AS cat_color
         FROM noticias n
         INNER JOIN categorias c ON c.id = n.categoria_id
         WHERE n.estado = 'publicado'
           AND n.categoria_id IN ($placeholdersCats)
           AND n.fecha_publicacion <= NOW()
           $excludeClause
         ORDER BY n.fecha_publicacion DESC
         LIMIT ?",
        $params
    );
}

/**
 * Registrar visita a noticia
 * CORRECCIÓN: sp_incrementar_visita requiere 4 parámetros (noticia_id, ip, user_agent, dispositivo)
 */
function registrarVisita(int $noticiaId): void
{
    try {
        $ip        = getClientIp();
        $userAgent = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

        // Detectar dispositivo para el 4° parámetro requerido por sp_incrementar_visita
        $ua = strtolower($userAgent);
        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')) {
            $dispositivo = 'tablet';
        } elseif (
            str_contains($ua, 'mobile')  ||
            str_contains($ua, 'android') ||
            str_contains($ua, 'iphone')  ||
            str_contains($ua, 'ipod')
        ) {
            $dispositivo = 'mobile';
        } else {
            $dispositivo = 'desktop';
        }

        db()->query(
            "CALL sp_incrementar_visita(?, ?, ?, ?)",
            [$noticiaId, $ip, $userAgent, $dispositivo]
        );
    } catch (\Throwable $e) {
        // No crítico — registrar en log de errores si está disponible
        error_log('[registrarVisita] Error: ' . $e->getMessage());
    }
}

/**
 * Publicar / guardar noticia (crear o editar)
 */
function guardarNoticia(array $data, ?int $noticiaId = null): array
{
    $titulo    = cleanInput($data['titulo']    ?? '', 300);
    $resumen   = cleanInput($data['resumen']   ?? '', 500);
    $contenido = $data['contenido'] ?? '';
    $catId     = cleanInt($data['categoria_id'] ?? 0);
    $autorId   = cleanInt($data['autor_id']     ?? 0);
    $estado    = in_array($data['estado'] ?? '', ['publicado','borrador','programado','revision'])
                 ? $data['estado'] : 'borrador';
    $destacado = isset($data['destacado'])  ? 1 : 0;
    $breaking  = isset($data['breaking'])   ? 1 : 0;
    $esPremium = isset($data['es_premium']) ? 1 : 0;
    $fechaProg = !empty($data['fecha_publicacion']) ? $data['fecha_publicacion'] : null;
    $imagen    = cleanInput($data['imagen'] ?? '');
    $videoUrl  = cleanInput($data['video_url'] ?? '', 500);
    $fuente    = cleanInput($data['fuente']    ?? '', 255);
    $permitirCom = isset($data['permitir_comentarios']) ? 1 : 0;

    // Validar
    if (empty($titulo))   return ['success'=>false,'error'=>'El título es obligatorio.'];
    if (empty($contenido))return ['success'=>false,'error'=>'El contenido es obligatorio.'];
    if (!$catId)          return ['success'=>false,'error'=>'Selecciona una categoría.'];
    if (!$autorId)        return ['success'=>false,'error'=>'El autor es requerido.'];

    // Calcular tiempo de lectura
    $tiempoLectura = calcularTiempoLectura($contenido);

    // Fecha de publicación
    $fechaPublicacion = ($estado === 'programado' && $fechaProg)
        ? $fechaProg
        : ($estado === 'publicado' ? date('Y-m-d H:i:s') : null);

    if ($noticiaId) {
        // Editar
        $slug = generateSlug($titulo, 'noticias', $noticiaId);
        db()->execute(
            "UPDATE noticias SET
                titulo = ?, slug = ?, resumen = ?, contenido = ?,
                categoria_id = ?, autor_id = ?, estado = ?,
                destacado = ?, breaking = ?, es_premium = ?,
                imagen = ?, video_url = ?, fuente = ?,
                tiempo_lectura = ?, permitir_comentarios = ?,
                fecha_publicacion = ?, fecha_update = NOW()
             WHERE id = ?",
            [
                $titulo, $slug, $resumen, $contenido,
                $catId, $autorId, $estado,
                $destacado, $breaking, $esPremium,
                $imagen, $videoUrl, $fuente,
                $tiempoLectura, $permitirCom,
                $fechaPublicacion, $noticiaId,
            ]
        );
        $id = $noticiaId;
    } else {
        // Crear
        $slug = generateSlug($titulo, 'noticias');
        $id   = db()->insert(
            "INSERT INTO noticias
                (titulo, slug, resumen, contenido, categoria_id, autor_id,
                 estado, destacado, breaking, es_premium, imagen, video_url,
                 fuente, tiempo_lectura, permitir_comentarios, fecha_publicacion, fecha_creacion)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
            [
                $titulo, $slug, $resumen, $contenido, $catId, $autorId,
                $estado, $destacado, $breaking, $esPremium, $imagen, $videoUrl,
                $fuente, $tiempoLectura, $permitirCom, $fechaPublicacion,
            ]
        );
    }

    // Sincronizar tags
    if (isset($data['tags']) && is_array($data['tags'])) {
        sincronizarTagsNoticia($id, array_map('intval', $data['tags']));
    }

    return ['success'=>true,'id'=>$id,'slug'=>$slug ?? ''];
}

// ============================================================
// 07. CATEGORÍAS
// ============================================================

/**
 * Obtener todas las categorías activas con conteo
 */
function getCategorias(bool $conConteo = true): array
{
    if ($conConteo) {
        return db()->cachedQuery('categorias_con_conteo',
            "SELECT c.*,
                    COUNT(n.id) AS total_noticias
             FROM categorias c
             LEFT JOIN noticias n ON n.categoria_id = c.id
                 AND n.estado = 'publicado'
                 AND n.fecha_publicacion <= NOW()
             WHERE c.activa = 1
             GROUP BY c.id
             ORDER BY c.orden ASC",
            [], 300
        );
    }

    return db()->cachedQuery('categorias_simple',
        "SELECT * FROM categorias WHERE activa = 1 ORDER BY orden ASC",
        [], 300
    );
}

/**
 * Obtener categoría por slug
 */
function getCategoriaPorSlug(string $slug): ?array
{
    return db()->fetchOne(
        "SELECT * FROM categorias WHERE slug = ? AND activa = 1 LIMIT 1",
        [$slug]
    );
}

/**
 * Obtener categoría por ID
 */
function getCategoriaById(int $id): ?array
{
    return db()->fetchOne(
        "SELECT * FROM categorias WHERE id = ? LIMIT 1",
        [$id]
    );
}

// ============================================================
// 08. TAGS
// ============================================================

/**
 * Obtener tags de una noticia
 */
function getTagsNoticia(int $noticiaId): array
{
    return db()->fetchAll(
        "SELECT t.id, t.nombre, t.slug
         FROM tags t
         INNER JOIN noticia_tags nt ON nt.tag_id = t.id
         WHERE nt.noticia_id = ?
         ORDER BY t.nombre ASC",
        [$noticiaId]
    );
}

/**
 * Obtener tags populares
 */
function getTagsPopulares(int $limit = 20): array
{
    return db()->fetchAll(
        "SELECT t.id, t.nombre, t.slug,
                COUNT(nt.noticia_id) AS total
         FROM tags t
         INNER JOIN noticia_tags nt ON nt.tag_id = t.id
         INNER JOIN noticias n      ON n.id = nt.noticia_id
             AND n.estado = 'publicado'
         GROUP BY t.id
         ORDER BY total DESC
         LIMIT ?",
        [$limit]
    );
}

/**
 * Sincronizar tags de una noticia (eliminar los viejos + insertar nuevos)
 */
function sincronizarTagsNoticia(int $noticiaId, array $tagIds): void
{
    db()->execute(
        "DELETE FROM noticia_tags WHERE noticia_id = ?",
        [$noticiaId]
    );

    foreach (array_unique($tagIds) as $tagId) {
        if ($tagId > 0) {
            try {
                db()->execute(
                    "INSERT IGNORE INTO noticia_tags (noticia_id, tag_id) VALUES (?,?)",
                    [$noticiaId, $tagId]
                );
            } catch (\Throwable $e) {}
        }
    }
}

/**
 * Obtener o crear tag por nombre
 */
function getOrCreateTag(string $nombre): int
{
    $nombre = cleanInput($nombre, 80);
    $slug   = generateSlug($nombre, 'tags');

    $tag = db()->fetchOne(
        "SELECT id FROM tags WHERE nombre = ? LIMIT 1",
        [$nombre]
    );

    if ($tag) return (int)$tag['id'];

    return db()->insert(
        "INSERT INTO tags (nombre, slug) VALUES (?,?)",
        [$nombre, $slug]
    );
}

// ============================================================
// 09. COMENTARIOS
// ============================================================

/**
 * Obtener comentarios de una noticia (raíz + anidados)
 * VERSIÓN CORREGIDA: compatible con cualquier estado de la BD
 */
function getComentarios(
    int $noticiaId,
    int $limit  = COMENTARIOS_POR_PAGINA,
    int $offset = 0
): array {
    // Detectar columnas disponibles de forma segura
    static $columnasCache = null;
    if ($columnasCache === null) {
        try {
            $cols = db()->fetchAll("SHOW COLUMNS FROM comentarios");
            $columnasCache = array_column($cols, 'Field');
        } catch (\Throwable $e) {
            $columnasCache = ['id','usuario_id','noticia_id','padre_id',
                             'comentario','aprobado','fecha'];
        }
    }

    $hasLikes    = in_array('likes',    $columnasCache);
    $hasDislikes = in_array('dislikes', $columnasCache);
    $hasReportes = in_array('reportes', $columnasCache);
    $hasEditado  = in_array('editado',  $columnasCache);

    $selectExtra = '';
    if ($hasLikes)    $selectExtra .= ', co.likes';
    if ($hasDislikes) $selectExtra .= ', co.dislikes';
    if ($hasReportes) $selectExtra .= ', co.reportes';
    if ($hasEditado)  $selectExtra .= ', co.editado';

    // Comentarios raíz (sin padre)
    $raiz = db()->fetchAll(
        "SELECT co.id, co.comentario, co.fecha, co.padre_id
                $selectExtra,
                u.id       AS usuario_id,
                u.nombre   AS usuario_nombre,
                u.avatar   AS usuario_avatar,
                u.rol      AS usuario_rol,
                u.verificado AS usuario_verificado
         FROM comentarios co
         INNER JOIN usuarios u ON u.id = co.usuario_id
         WHERE co.noticia_id = ?
           AND co.aprobado   = 1
           AND co.padre_id  IS NULL
         ORDER BY co.fecha DESC
         LIMIT ? OFFSET ?",
        [$noticiaId, $limit, $offset]
    );

    // Asegurar valores por defecto para columnas que puedan no existir
    foreach ($raiz as &$com) {
        $com['likes']    = isset($com['likes'])    ? (int)$com['likes']    : 0;
        $com['dislikes'] = isset($com['dislikes']) ? (int)$com['dislikes'] : 0;
        $com['reportes'] = isset($com['reportes']) ? (int)$com['reportes'] : 0;
        $com['editado']  = isset($com['editado'])  ? (bool)$com['editado'] : false;

        // Cargar respuestas de este comentario
        $com['respuestas'] = db()->fetchAll(
            "SELECT co.id, co.comentario, co.fecha, co.padre_id
                    $selectExtra,
                    u.id       AS usuario_id,
                    u.nombre   AS usuario_nombre,
                    u.avatar   AS usuario_avatar,
                    u.rol      AS usuario_rol,
                    u.verificado AS usuario_verificado
             FROM comentarios co
             INNER JOIN usuarios u ON u.id = co.usuario_id
             WHERE co.noticia_id = ?
               AND co.aprobado   = 1
               AND co.padre_id   = ?
             ORDER BY co.fecha ASC
             LIMIT 10",
            [$noticiaId, $com['id']]
        );

        // Defaults para respuestas también
        foreach ($com['respuestas'] as &$resp) {
            $resp['likes']    = isset($resp['likes'])    ? (int)$resp['likes']    : 0;
            $resp['dislikes'] = isset($resp['dislikes']) ? (int)$resp['dislikes'] : 0;
            $resp['reportes'] = isset($resp['reportes']) ? (int)$resp['reportes'] : 0;
            $resp['editado']  = isset($resp['editado'])  ? (bool)$resp['editado'] : false;
        }
        unset($resp);
    }
    unset($com);

    return $raiz;
}

/**
 * Contar comentarios de una noticia
 */
function countComentarios(int $noticiaId): int
{
    return db()->count(
        "SELECT COUNT(*) FROM comentarios
         WHERE noticia_id = ? AND aprobado = 1",
        [$noticiaId]
    );
}

/**
 * Guardar comentario
 */
function guardarComentario(
    int $usuarioId,
    int $noticiaId,
    string $comentario,
    ?int $padreId = null
): array {
    $comentario = cleanInput($comentario);

    if (mb_strlen($comentario) < 3) {
        return ['success' => false, 'message' => 'El comentario es demasiado corto.'];
    }
    if (mb_strlen($comentario) > 2000) {
        return ['success' => false, 'message' => 'El comentario no puede exceder 2000 caracteres.'];
    }

    // Anti-spam: 1 comentario por minuto por usuario
    $reciente = db()->count(
        "SELECT COUNT(*) FROM comentarios
         WHERE usuario_id = ?
           AND noticia_id = ?
           AND fecha > NOW() - INTERVAL 1 MINUTE",
        [$usuarioId, $noticiaId]
    );

    if ($reciente > 0) {
        return ['success' => false, 'message' => 'Espera un momento antes de comentar de nuevo.'];
    }

    try {
        $id = db()->insert(
            "INSERT INTO comentarios
                (usuario_id, noticia_id, padre_id, comentario, aprobado, fecha)
             VALUES (?, ?, ?, ?, 1, NOW())",
            [$usuarioId, $noticiaId, $padreId, $comentario]
        );

        // Intentar otorgar insignias (no crítico)
        try {
            db()->query("CALL sp_otorgar_insignias(?)", [$usuarioId]);
        } catch (\Throwable $e) {}

        return [
            'success' => true,
            'id'      => $id,
            'message' => 'Comentario publicado correctamente.',
        ];
    } catch (\Throwable $e) {
        return ['success' => false, 'message' => 'Error al guardar el comentario.'];
    }
}

/**
 * Votar comentario (like/dislike)
 */
function votarComentario(int $usuarioId, int $comentarioId, string $tipo): array
{
    if (!in_array($tipo, ['like','dislike'], true)) {
        return ['success'=>false,'message'=>'Tipo de voto inválido.'];
    }

    // Verificar voto previo
    $previo = db()->fetchOne(
        "SELECT id, tipo FROM votos_comentarios
         WHERE usuario_id = ? AND comentario_id = ?",
        [$usuarioId, $comentarioId]
    );

    if ($previo) {
        if ($previo['tipo'] === $tipo) {
            // Quitar voto
            db()->execute(
                "DELETE FROM votos_comentarios WHERE id = ?",
                [$previo['id']]
            );
            $campo = $tipo === 'like' ? 'likes' : 'dislikes';
            db()->execute(
                "UPDATE comentarios SET $campo = GREATEST(0, $campo - 1) WHERE id = ?",
                [$comentarioId]
            );
            return ['success'=>true,'action'=>'removed','tipo'=>$tipo];
        } else {
            // Cambiar tipo
            db()->execute(
                "UPDATE votos_comentarios SET tipo = ? WHERE id = ?",
                [$tipo, $previo['id']]
            );
            $campo_add = $tipo === 'like' ? 'likes' : 'dislikes';
            $campo_sub = $tipo === 'like' ? 'dislikes' : 'likes';
            db()->execute(
                "UPDATE comentarios
                 SET $campo_add = $campo_add + 1,
                     $campo_sub = GREATEST(0, $campo_sub - 1)
                 WHERE id = ?",
                [$comentarioId]
            );
            return ['success'=>true,'action'=>'changed','tipo'=>$tipo];
        }
    }

    // Nuevo voto
    db()->insert(
        "INSERT INTO votos_comentarios (usuario_id, comentario_id, tipo)
         VALUES (?,?,?)",
        [$usuarioId, $comentarioId, $tipo]
    );
    $campo = $tipo === 'like' ? 'likes' : 'dislikes';
    db()->execute(
        "UPDATE comentarios SET $campo = $campo + 1 WHERE id = ?",
        [$comentarioId]
    );

    // Obtener totales actualizados
    $com = db()->fetchOne(
        "SELECT likes, dislikes FROM comentarios WHERE id = ?",
        [$comentarioId]
    );

    return [
        'success' => true,
        'action'  => 'added',
        'tipo'    => $tipo,
        'likes'   => (int)($com['likes']    ?? 0),
        'dislikes'=> (int)($com['dislikes'] ?? 0),
    ];
}

/**
 * Reportar contenido
 */
function reportarContenido(
    string $tipo,
    int $itemId,
    string $motivo,
    ?int $usuarioId,
    ?string $descripcion = null
): array {
    if (!in_array($tipo, ['noticia','comentario','usuario'], true)) {
        return ['success'=>false,'message'=>'Tipo de reporte inválido.'];
    }

    $ip = getClientIp();

    // Verificar que no haya reportado ya
    $previo = db()->count(
        "SELECT COUNT(*) FROM reportes_contenido
         WHERE tipo = ? AND item_id = ? AND ip = ?
           AND fecha > NOW() - INTERVAL 24 HOUR",
        [$tipo, $itemId, $ip]
    );

    if ($previo > 0) {
        return ['success'=>false,'message'=>'Ya reportaste este contenido recientemente.'];
    }

    db()->insert(
        "INSERT INTO reportes_contenido
            (tipo, item_id, usuario_id, ip, motivo, descripcion)
         VALUES (?,?,?,?,?,?)",
        [$tipo, $itemId, $usuarioId, $ip, $motivo, $descripcion]
    );

    return ['success'=>true,'message'=>'Reporte enviado. Gracias por tu colaboración.'];
}

// ============================================================
// 10. REACCIONES
// ============================================================

/**
 * Reaccionar a una noticia (toggle)
 */
function reaccionarNoticia(int $noticiaId, string $tipo, ?int $usuarioId): array
{
    $tiposValidos = ['me_gusta','me_encanta','me_divierte','me_sorprende','me_entristece','me_enoja'];
    if (!in_array($tipo, $tiposValidos, true)) {
        return ['success'=>false,'message'=>'Tipo de reacción inválido.'];
    }

    $ip = getClientIp();

    // Buscar reacción previa
    $where    = $usuarioId ? 'usuario_id = ? AND noticia_id = ?' : 'ip = ? AND noticia_id = ?';
    $paramsBuscar = $usuarioId ? [$usuarioId, $noticiaId] : [$ip, $noticiaId];

    $previa = db()->fetchOne(
        "SELECT id, tipo FROM reacciones WHERE $where LIMIT 1",
        $paramsBuscar
    );

    if ($previa) {
        if ($previa['tipo'] === $tipo) {
            // Quitar reacción
            db()->execute("DELETE FROM reacciones WHERE id = ?", [$previa['id']]);
            db()->execute(
                "UPDATE noticias SET total_reacciones = GREATEST(0, total_reacciones - 1) WHERE id = ?",
                [$noticiaId]
            );
            $accion = 'removed';
        } else {
            // Cambiar tipo
            db()->execute(
                "UPDATE reacciones SET tipo = ? WHERE id = ?",
                [$tipo, $previa['id']]
            );
            $accion = 'changed';
        }
    } else {
        // Nueva reacción
        try {
            db()->insert(
                "INSERT INTO reacciones (noticia_id, usuario_id, ip, tipo)
                 VALUES (?,?,?,?)",
                [$noticiaId, $usuarioId, $ip, $tipo]
            );
            db()->execute(
                "UPDATE noticias SET total_reacciones = total_reacciones + 1 WHERE id = ?",
                [$noticiaId]
            );
            $accion = 'added';
        } catch (\Throwable $e) {
            return ['success'=>false,'message'=>'Ya reaccionaste a esta noticia.'];
        }
    }

    // Obtener conteos actualizados por tipo
    $conteos = db()->fetchAll(
        "SELECT tipo, COUNT(*) AS total FROM reacciones WHERE noticia_id = ? GROUP BY tipo",
        [$noticiaId]
    );
    $totales = array_column($conteos, 'total', 'tipo');

    return [
        'success' => true,
        'accion'  => $accion,
        'tipo'    => $tipo,
        'totales' => $totales,
        'total'   => array_sum($totales),
    ];
}

/**
 * Obtener resumen de reacciones de una noticia
 */
function getReaccionesNoticia(int $noticiaId, ?int $usuarioId = null): array
{
    $conteos = db()->fetchAll(
        "SELECT tipo, COUNT(*) AS total FROM reacciones WHERE noticia_id = ? GROUP BY tipo",
        [$noticiaId]
    );
    $totales = array_column($conteos, 'total', 'tipo');

    $miReaccion = null;
    if ($usuarioId) {
        $mia = db()->fetchOne(
            "SELECT tipo FROM reacciones WHERE noticia_id = ? AND usuario_id = ? LIMIT 1",
            [$noticiaId, $usuarioId]
        );
        $miReaccion = $mia['tipo'] ?? null;
    }

    return [
        'totales'     => $totales,
        'total'       => array_sum($totales),
        'mi_reaccion' => $miReaccion,
        'emojis'      => [
            'me_gusta'       => ['emoji'=>'👍','label'=>'Me gusta'],
            'me_encanta'     => ['emoji'=>'❤️','label'=>'Me encanta'],
            'me_divierte'    => ['emoji'=>'😄','label'=>'Me divierte'],
            'me_sorprende'   => ['emoji'=>'😮','label'=>'Me sorprende'],
            'me_entristece'  => ['emoji'=>'😢','label'=>'Me entristece'],
            'me_enoja'       => ['emoji'=>'😡','label'=>'Me enoja'],
        ],
    ];
}

// ============================================================
// 11. ENCUESTAS
// ============================================================

/**
 * Obtener encuesta de una noticia
 */
function getEncuestaNoticia(int $noticiaId): ?array
{
    $encuesta = db()->fetchOne(
        "SELECT * FROM encuestas WHERE noticia_id = ? AND activa = 1 LIMIT 1",
        [$noticiaId]
    );

    if (!$encuesta) return null;

    $encuesta['opciones'] = db()->fetchAll(
        "SELECT * FROM encuesta_opciones WHERE encuesta_id = ? ORDER BY orden ASC",
        [$encuesta['id']]
    );

    return $encuesta;
}

/**
 * Votar en una encuesta (con soporte de cambio de voto)
 * ============================================================
 */
function votarEncuesta(int $encuestaId, int $opcionId, ?int $usuarioId): array
{
    $ip = getClientIp();

    // ── Verificar encuesta ────────────────────────────────────
    $encuesta = db()->fetchOne(
        "SELECT id, activa, fecha_cierre, tipo, puede_cambiar_voto, mostrar_resultados
         FROM encuestas WHERE id = ? LIMIT 1",
        [$encuestaId]
    );

    if (!$encuesta || !$encuesta['activa']) {
        return ['success' => false, 'message' => 'Esta encuesta no está disponible.'];
    }

    if (!empty($encuesta['fecha_cierre']) && $encuesta['fecha_cierre'] < date('Y-m-d H:i:s')) {
        return ['success' => false, 'message' => 'Esta encuesta ya cerró.'];
    }

    // ── Verificar que la opción pertenece a la encuesta ───────
    $opcion = db()->fetchOne(
        "SELECT id FROM encuesta_opciones WHERE id = ? AND encuesta_id = ? LIMIT 1",
        [$opcionId, $encuestaId]
    );
    if (!$opcion) {
        return ['success' => false, 'message' => 'Opción inválida.'];
    }

    // ── Verificar voto previo ─────────────────────────────────
    if ($usuarioId) {
        $votosAnt = db()->fetchAll(
            "SELECT id, opcion_id FROM encuesta_votos
             WHERE encuesta_id = ? AND usuario_id = ?",
            [$encuestaId, $usuarioId]
        );
    } else {
        $votosAnt = db()->fetchAll(
            "SELECT id, opcion_id FROM encuesta_votos
             WHERE encuesta_id = ? AND ip = ? AND usuario_id IS NULL",
            [$encuestaId, $ip]
        );
    }

    $tieneVotoPrevio = !empty($votosAnt);

    // ── Si ya votó esta opción exacta, no hacer nada ──────────
    $opcionesYaVotadas = array_column($votosAnt, 'opcion_id');
    if (in_array($opcionId, $opcionesYaVotadas)) {
        // Ya votó por esta opción: retornar resultados actuales sin error
        $opciones   = db()->fetchAll(
            "SELECT id, opcion, votos FROM encuesta_opciones
             WHERE encuesta_id = ? ORDER BY orden ASC",
            [$encuestaId]
        );
        $totalVotos = (int)db()->fetchColumn(
            "SELECT total_votos FROM encuestas WHERE id = ?",
            [$encuestaId]
        );
        return [
            'success'     => true,
            'message'     => 'Ya habías votado por esta opción.',
            'ya_votado'   => true,
            'opciones'    => $opciones,
            'total_votos' => $totalVotos,
        ];
    }

    // ── Si tiene voto previo pero NO puede cambiar → error ────
    if ($tieneVotoPrevio && !$encuesta['puede_cambiar_voto'] && $encuesta['tipo'] === 'unica') {
        return ['success' => false, 'message' => 'Ya votaste en esta encuesta y no se permite cambiar el voto.'];
    }

    // ── Para encuesta única con cambio de voto: actualizar ────
    if ($tieneVotoPrevio && $encuesta['tipo'] === 'unica' && $encuesta['puede_cambiar_voto']) {
        try {
            // Descontar voto anterior
            $opAnterior = (int)$votosAnt[0]['opcion_id'];
            db()->execute(
                "UPDATE encuesta_opciones SET votos = GREATEST(0, votos - 1) WHERE id = ?",
                [$opAnterior]
            );
            // Actualizar registro
            if ($usuarioId) {
                db()->execute(
                    "UPDATE encuesta_votos SET opcion_id = ?, fecha_cambio = NOW()
                     WHERE encuesta_id = ? AND usuario_id = ?",
                    [$opcionId, $encuestaId, $usuarioId]
                );
            } else {
                db()->execute(
                    "UPDATE encuesta_votos SET opcion_id = ?, fecha_cambio = NOW()
                     WHERE encuesta_id = ? AND ip = ? AND usuario_id IS NULL",
                    [$opcionId, $encuestaId, $ip]
                );
            }
            // Sumar nuevo voto
            db()->execute(
                "UPDATE encuesta_opciones SET votos = votos + 1 WHERE id = ?",
                [$opcionId]
            );
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Error al actualizar el voto.'];
        }
    } else {
        // ── Primer voto o múltiple: insertar ─────────────────
        try {
            db()->insert(
                "INSERT INTO encuesta_votos (encuesta_id, opcion_id, usuario_id, ip, fecha)
                 VALUES (?,?,?,?,NOW())",
                [$encuestaId, $opcionId, $usuarioId, $ip]
            );
            db()->execute(
                "UPDATE encuesta_opciones SET votos = votos + 1 WHERE id = ?",
                [$opcionId]
            );
            // Incrementar total_votos solo en primer voto de una encuesta única
            // Para múltiple el total se recalcula aparte
            if ($encuesta['tipo'] === 'unica' || !$tieneVotoPrevio) {
                db()->execute(
                    "UPDATE encuestas SET total_votos = total_votos + 1 WHERE id = ?",
                    [$encuestaId]
                );
            }
        } catch (\Throwable $e) {
            // Unique constraint o error similar = ya votó
            return ['success' => false, 'message' => 'Ya votaste en esta encuesta.'];
        }
    }

    // ── Para encuesta múltiple: recalcular total desde sub-votos ─
    if ($encuesta['tipo'] === 'multiple') {
        // Para múltiple: total = número de participantes únicos
        if ($usuarioId) {
            $participante = (bool)db()->count(
                "SELECT COUNT(*) FROM encuesta_votos
                 WHERE encuesta_id = ? AND usuario_id = ? AND opcion_id != ?",
                [$encuestaId, $usuarioId, $opcionId]
            );
        } else {
            $participante = (bool)db()->count(
                "SELECT COUNT(*) FROM encuesta_votos
                 WHERE encuesta_id = ? AND ip = ? AND usuario_id IS NULL AND opcion_id != ?",
                [$encuestaId, $ip, $opcionId]
            );
        }
        if (!$participante) {
            db()->execute(
                "UPDATE encuestas SET total_votos = total_votos + 1 WHERE id = ?",
                [$encuestaId]
            );
        }
    }

    // ── Retornar resultados actualizados ──────────────────────
    $opciones = db()->fetchAll(
        "SELECT id, opcion, votos FROM encuesta_opciones
         WHERE encuesta_id = ? ORDER BY orden ASC",
        [$encuestaId]
    );
    $totalVotos = (int)db()->fetchColumn(
        "SELECT total_votos FROM encuestas WHERE id = ?",
        [$encuestaId]
    );

    return [
        'success'     => true,
        'message'     => '¡Voto registrado!',
        'cambiado'    => $tieneVotoPrevio,
        'opciones'    => $opciones,
        'total_votos' => $totalVotos,
    ];
}

/**
 * Obtener encuestas públicas (para listado y sidebar)
 */
function getEncuestasPublicas(int $limit = 9, int $offset = 0, ?int $catId = null): array
{
    $where  = ['e.activa = 1', 'e.es_standalone = 1', '(e.fecha_cierre IS NULL OR e.fecha_cierre > NOW())'];
    $params = [];
    if ($catId) { $where[] = 'e.categoria_id = ?'; $params[] = $catId; }
    $whereSQL = implode(' AND ', $where);
    $params[] = $limit;
    $params[] = $offset;

    $encuestas = db()->fetchAll(
        "SELECT e.*, c.nombre AS cat_nombre, c.color AS cat_color,
                u.nombre AS autor_nombre,
                (SELECT COUNT(*) FROM encuesta_opciones eo WHERE eo.encuesta_id = e.id) AS num_opciones
         FROM encuestas e
         LEFT JOIN categorias c ON c.id = e.categoria_id
         LEFT JOIN usuarios   u ON u.id = e.autor_id
         WHERE $whereSQL
         ORDER BY e.fecha_creacion DESC
         LIMIT ? OFFSET ?",
        $params
    );

    foreach ($encuestas as &$enc) {
        $enc['opciones'] = db()->fetchAll(
            "SELECT id, opcion, votos FROM encuesta_opciones
             WHERE encuesta_id = ? ORDER BY votos DESC, orden ASC LIMIT 5",
            [$enc['id']]
        );
    }
    unset($enc);

    return $encuestas;
}

/**
 * Obtener encuesta aleatoria activa para sidebar
 */
function getEncuestaSidebarAleatoria(): ?array
{
    $enc = db()->fetchOne(
        "SELECT e.id, e.pregunta, e.slug, e.total_votos, e.color,
                e.tipo, e.puede_cambiar_voto, e.mostrar_resultados
         FROM encuestas e
         WHERE e.activa = 1
           AND e.es_standalone = 1
           AND (e.fecha_cierre IS NULL OR e.fecha_cierre > NOW())
         ORDER BY RAND()
         LIMIT 1"
    );

    if (!$enc) return null;

    $enc['opciones'] = db()->fetchAll(
        "SELECT id, opcion, votos FROM encuesta_opciones
         WHERE encuesta_id = ? ORDER BY orden ASC LIMIT 6",
        [$enc['id']]
    );

    return $enc;
}

// ============================================================
// 12. FAVORITOS
// ============================================================

/**
 * Verificar si una noticia es favorita
 */
function esFavorito(int $usuarioId, int $noticiaId): bool
{
    return db()->count(
        "SELECT COUNT(*) FROM favoritos WHERE usuario_id = ? AND noticia_id = ?",
        [$usuarioId, $noticiaId]
    ) > 0;
}

/**
 * Toggle favorito
 */
function toggleFavorito(int $usuarioId, int $noticiaId): array
{
    if (esFavorito($usuarioId, $noticiaId)) {
        db()->execute(
            "DELETE FROM favoritos WHERE usuario_id = ? AND noticia_id = ?",
            [$usuarioId, $noticiaId]
        );
        return ['success'=>true,'action'=>'removed','message'=>'Eliminado de guardados.'];
    }

    db()->insert(
        "INSERT INTO favoritos (usuario_id, noticia_id, fecha) VALUES (?,?,NOW())",
        [$usuarioId, $noticiaId]
    );
    return ['success'=>true,'action'=>'added','message'=>'Guardado en favoritos.'];
}

/**
 * Obtener favoritos de un usuario
 */
function getFavoritosUsuario(int $usuarioId, int $limit = 20, int $offset = 0): array
{
    return db()->fetchAll(
        "SELECT n.id, n.titulo, n.slug, n.imagen,
                n.fecha_publicacion, n.vistas, n.tiempo_lectura,
                f.fecha AS fecha_guardado,
                c.nombre AS cat_nombre, c.color AS cat_color
         FROM favoritos f
         INNER JOIN noticias    n ON n.id = f.noticia_id
         INNER JOIN categorias  c ON c.id = n.categoria_id
         WHERE f.usuario_id = ? AND n.estado = 'publicado'
         ORDER BY f.fecha DESC
         LIMIT ? OFFSET ?",
        [$usuarioId, $limit, $offset]
    );
}

// ============================================================
// 13. ANUNCIOS
// ============================================================

/**
 * Obtener anuncios activos por posición
 */
function getAnuncios(
    string $posicion,
    ?int $categoriaId = null,
    int $limit = 3
): array {
    if (!Config::bool('ads_activos_global')) return [];

    // Mapeo de posición → clave de configuración
    $configKey = match($posicion) {
        'header'                     => 'ads_header',
        'hero'                       => 'ads_header',        // Hero usa el mismo flag que header
        'entre_noticias'             => 'ads_entre_noticias',
        'sidebar'                    => 'ads_sidebar',
        'in_article', 'articulo'     => 'ads_dentro_articulo', // Acepta ambos nombres por compatibilidad
        'footer'                     => 'ads_header',        // Footer usa flag header como fallback
        'popup'                      => 'ads_header',        // Popup usa flag header como fallback
        'mobile_banner'              => 'ads_header',        // Banner móvil usa flag header como fallback
        default                      => 'ads_header',
    };
    
    if (!Config::bool($configKey)) return [];

    $params = [$posicion];
    $catClause = '';

    if ($categoriaId) {
        $catClause = 'AND (a.categoria_id IS NULL OR a.categoria_id = ?)';
        $params[]  = $categoriaId;
    }

    $params[] = $limit;

    $anuncios = db()->fetchAll(
        "SELECT id, nombre, tipo, tamano, posicion,
                contenido, url_destino, imagen
         FROM anuncios a
         WHERE a.posicion = ?
           AND a.activo = 1
           AND (a.fecha_inicio IS NULL OR a.fecha_inicio <= NOW())
           AND (a.fecha_fin   IS NULL OR a.fecha_fin   >= NOW())
           $catClause
         ORDER BY a.prioridad ASC, RAND()
         LIMIT ?",
        $params
    );

    return $anuncios;
}

/**
 * Registrar impresión de anuncio
 */
function registrarImpresionAnuncio(int $anuncioId): void
{
    try {
        db()->execute(
            "UPDATE anuncios SET impresiones = impresiones + 1 WHERE id = ?",
            [$anuncioId]
        );
        db()->insert(
            "INSERT INTO anuncio_log (anuncio_id, tipo, ip, user_agent)
             VALUES (?,  'impresion', ?, ?)",
            [
                $anuncioId,
                getClientIp(),
                mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]
        );
    } catch (\Throwable $e) {}
}

/**
 * Renderizar HTML de un anuncio
 * Maneja correctamente AdSense, HTML personalizado, imagen y video
 */
function renderAnuncio(array $anuncio): string
{
    static $adsenseLoaded = false; // Evitar cargar adsbygoogle.js más de una vez

    $id  = (int)$anuncio['id'];
    $url = e($anuncio['url_destino'] ?? '#');
    registrarImpresionAnuncio($id);

    // ── Tipo HTML / Google AdSense ────────────────────────────
    if ($anuncio['tipo'] === 'html') {
        $contenidoHtml = $anuncio['contenido'] ?? '';

        // Si es AdSense y ya cargamos el script, eliminar el <script src="adsbygoogle.js">
        // para evitar carga duplicada (solo conservar el push)
        if ($adsenseLoaded && stripos($contenidoHtml, 'adsbygoogle.js') !== false) {
            $contenidoHtml = preg_replace(
                '/<script[^>]+pagead2\.googlesyndication\.com[^>]*>.*?<\/script>/is',
                '',
                $contenidoHtml
            );
        }
        if (stripos($contenidoHtml, 'adsbygoogle.js') !== false) {
            $adsenseLoaded = true;
        }

        // Contenedor limpio — overflow:visible es CRÍTICO para AdSense
        return "\n<div class=\"ad-wrapper ad-html ad-{$anuncio['tamano']}\" "
             . "data-ad-id=\"$id\">\n"
             . $contenidoHtml . "\n"
             . "<span class=\"ad-label\">Publicidad</span>\n"
             . "</div>\n";
    }

    // ── Tipo Imagen ───────────────────────────────────────────
    $html = "<div class=\"ad-wrapper ad-{$anuncio['tamano']}\" data-ad-id=\"$id\">";

    if ($anuncio['tipo'] === 'imagen') {
        // Prioridad: archivo subido → URL manual en contenido
        $imgSrc = '';
        if (!empty($anuncio['imagen'])) {
            $imgSrc = getImageUrl($anuncio['imagen'], 'ad');
        } elseif (!empty($anuncio['contenido'])) {
            $imgSrc = e($anuncio['contenido']);
        }

        if ($imgSrc) {
            $html .= "<a href=\"$url\" target=\"_blank\" rel=\"noopener sponsored\" "
                   . "onclick=\"trackAdClick($id)\">";
            $html .= "<img src=\"$imgSrc\" alt=\"" . e($anuncio['nombre']) . "\" "
                   . "class=\"ad-image\" loading=\"lazy\">";
            $html .= "</a>";
        }

    // ── Tipo Video ────────────────────────────────────────────
    } elseif ($anuncio['tipo'] === 'video' && !empty($anuncio['contenido'])) {
        $html .= "<video src=\"" . e($anuncio['contenido']) . "\" "
               . "autoplay muted loop playsinline class=\"ad-video\"></video>";
    }

    $html .= "<span class=\"ad-label\">Publicidad</span></div>";
    return $html;
}

// ============================================================
// 14. VIDEOS
// ============================================================

/**
 * Obtener videos activos para la sección de videos
 */
function getVideos(int $limit = 10, ?int $categoriaId = null, bool $soloDestacados = false): array
{
    $where  = ['v.activo = 1'];
    $params = [];

    if ($categoriaId) {
        $where[]  = 'v.categoria_id = ?';
        $params[] = $categoriaId;
    }
    if ($soloDestacados) {
        $where[] = 'v.destacado = 1';
    }

    $params[] = $limit;

    return db()->fetchAll(
        "SELECT v.*, c.nombre AS cat_nombre, c.color AS cat_color,
                u.nombre AS autor_nombre
         FROM videos v
         LEFT JOIN categorias c ON c.id = v.categoria_id
         LEFT JOIN usuarios   u ON u.id = v.autor_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY v.destacado DESC, v.orden ASC, v.fecha_creacion DESC
         LIMIT ?",
        $params
    );
}

/**
 * Obtener embed URL según tipo de video
 */
function getVideoEmbedUrl(array $video): string
{
    return match($video['tipo']) {
        'youtube' => 'https://www.youtube.com/embed/' . extractYoutubeId($video['url']),
        'vimeo'   => 'https://player.vimeo.com/video/' . extractVimeoId($video['url']),
        'mp4'     => getImageUrl($video['url']),
        default   => $video['url'],
    };
}

/**
 * Extraer ID de YouTube de una URL
 */
function extractYoutubeId(string $url): string
{
    preg_match(
        '/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/',
        $url,
        $matches
    );
    return $matches[1] ?? $url;
}

/**
 * Extraer ID de Vimeo
 */
function extractVimeoId(string $url): string
{
    preg_match('/vimeo\.com\/(\d+)/', $url, $matches);
    return $matches[1] ?? $url;
}

/**
 * Obtener thumbnail de YouTube
 */
function getYoutubeThumbnail(string $url, string $size = 'hqdefault'): string
{
    $id = extractYoutubeId($url);
    return "https://img.youtube.com/vi/{$id}/{$size}.jpg";
}

// ============================================================
// 15. LIVE BLOG
// ============================================================

/**
 * Obtener live blogs activos
 */
function getLiveBlogsActivos(int $limit = 5): array
{
    return db()->fetchAll(
        "SELECT lb.*, c.nombre AS cat_nombre, c.color AS cat_color,
                u.nombre AS autor_nombre
         FROM live_blog lb
         LEFT JOIN categorias c ON c.id = lb.categoria_id
         INNER JOIN usuarios  u ON u.id = lb.autor_id
         WHERE lb.estado = 'activo'
         ORDER BY lb.fecha_inicio DESC
         LIMIT ?",
        [$limit]
    );
}

/**
 * Obtener live blog por slug
 */
function getLiveBlogPorSlug(string $slug): ?array
{
    return db()->fetchOne(
        "SELECT lb.*, c.nombre AS cat_nombre, c.color AS cat_color,
                u.nombre AS autor_nombre, u.avatar AS autor_avatar
         FROM live_blog lb
         LEFT JOIN categorias c ON c.id = lb.categoria_id
         INNER JOIN usuarios  u ON u.id = lb.autor_id
         WHERE lb.slug = ? LIMIT 1",
        [$slug]
    );
}

/**
 * Obtener actualizaciones de live blog
 */
function getLiveBlogUpdates(int $blogId, ?int $despuesDeId = null): array
{
    $params = [$blogId];
    $extra  = '';

    if ($despuesDeId) {
        $extra    = 'AND lbu.id > ?';
        $params[] = $despuesDeId;
    }

    return db()->fetchAll(
        "SELECT lbu.*, u.nombre AS autor_nombre, u.avatar AS autor_avatar
         FROM live_blog_updates lbu
         INNER JOIN usuarios u ON u.id = lbu.autor_id
         WHERE lbu.blog_id = ? $extra
         ORDER BY lbu.fecha DESC
         LIMIT 50",
        $params
    );
}

/**
 * Publicar actualización en live blog
 */
function publicarUpdateLiveBlog(int $blogId, int $autorId, array $data): array
{
    $contenido   = cleanInput($data['contenido'] ?? '');
    $tipo        = in_array($data['tipo'] ?? 'texto',
                    ['texto','imagen','video','cita','breaking','alerta'], true)
                   ? $data['tipo'] : 'texto';
    $esDestacado = isset($data['es_destacado']) ? 1 : 0;
    $imagen      = cleanInput($data['imagen']    ?? '');
    $videoUrl    = cleanInput($data['video_url'] ?? '', 500);

    if (empty($contenido)) {
        return ['success'=>false,'message'=>'El contenido es requerido.'];
    }

    $id = db()->insert(
        "INSERT INTO live_blog_updates
            (blog_id, autor_id, contenido, tipo, imagen, video_url, es_destacado, fecha)
         VALUES (?,?,?,?,?,?,?,NOW())",
        [$blogId, $autorId, $contenido, $tipo, $imagen, $videoUrl, $esDestacado]
    );

    db()->execute(
        "UPDATE live_blog SET total_updates = total_updates + 1 WHERE id = ?",
        [$blogId]
    );

    return ['success'=>true,'id'=>$id,'message'=>'Actualización publicada.'];
}

// ============================================================
// 16. USUARIOS Y PERFILES
// ============================================================

/**
 * Obtener perfil público de un usuario
 */
function getPerfilUsuario(int $usuarioId): ?array
{
    return db()->fetchOne(
        "SELECT u.id, u.nombre, u.email, u.bio, u.avatar, u.rol,
                u.website, u.twitter, u.facebook, u.instagram,
                u.ciudad, u.reputacion, u.verificado, u.premium,
                u.total_seguidores, u.total_seguidos,
                u.fecha_registro, u.ultimo_acceso,
                (SELECT COUNT(*) FROM noticias
                 WHERE autor_id = u.id AND estado = 'publicado') AS total_noticias,
                (SELECT COUNT(*) FROM comentarios
                 WHERE usuario_id = u.id AND aprobado = 1) AS total_comentarios,
                (SELECT COUNT(*) FROM favoritos
                 WHERE usuario_id = u.id) AS total_favoritos
         FROM usuarios u
         WHERE u.id = ? AND u.activo = 1
         LIMIT 1",
        [$usuarioId]
    );
}

/**
 * Obtener insignias de un usuario
 */
function getInsigniasUsuario(int $usuarioId): array
{
    return db()->fetchAll(
        "SELECT i.id, i.nombre, i.descripcion, i.icono, i.color,
                ui.fecha AS fecha_obtenida
         FROM usuario_insignias ui
         INNER JOIN insignias i ON i.id = ui.insignia_id
         WHERE ui.usuario_id = ?
         ORDER BY ui.fecha DESC",
        [$usuarioId]
    );
}

/**
 * Actualizar perfil de usuario
 */
function actualizarPerfil(int $usuarioId, array $data): array
{
    $nombre    = cleanInput($data['nombre']    ?? '', 100);
    $bio       = cleanInput($data['bio']       ?? '', 500);
    $website   = cleanInput($data['website']   ?? '', 255);
    $twitter   = cleanInput($data['twitter']   ?? '', 100);
    $facebook  = cleanInput($data['facebook']  ?? '', 100);
    $instagram = cleanInput($data['instagram'] ?? '', 100);
    $ciudad    = cleanInput($data['ciudad']    ?? '', 100);

    if (empty($nombre)) {
        return ['success'=>false,'message'=>'El nombre es requerido.'];
    }

    db()->execute(
        "UPDATE usuarios
         SET nombre = ?, bio = ?, website = ?, twitter = ?,
             facebook = ?, instagram = ?, ciudad = ?
         WHERE id = ?",
        [$nombre, $bio, $website, $twitter, $facebook, $instagram, $ciudad, $usuarioId]
    );

    return ['success'=>true,'message'=>'Perfil actualizado correctamente.'];
}

// ============================================================
// 17. SEGUIDORES
// ============================================================

/**
 * Seguir / dejar de seguir a un usuario
 */
function toggleSeguirUsuario(int $seguidorId, int $seguidoId): array
{
    if ($seguidorId === $seguidoId) {
        return ['success'=>false,'message'=>'No puedes seguirte a ti mismo.'];
    }

    $sigueYa = db()->count(
        "SELECT COUNT(*) FROM seguidores_usuarios
         WHERE seguidor_id = ? AND seguido_id = ?",
        [$seguidorId, $seguidoId]
    );

    if ($sigueYa) {
        db()->execute(
            "DELETE FROM seguidores_usuarios WHERE seguidor_id = ? AND seguido_id = ?",
            [$seguidorId, $seguidoId]
        );
        db()->execute(
            "UPDATE usuarios SET total_seguidores = GREATEST(0, total_seguidores - 1) WHERE id = ?",
            [$seguidoId]
        );
        db()->execute(
            "UPDATE usuarios SET total_seguidos = GREATEST(0, total_seguidos - 1) WHERE id = ?",
            [$seguidorId]
        );
        return ['success'=>true,'action'=>'unfollowed','message'=>'Dejaste de seguir a este usuario.'];
    }

    db()->insert(
        "INSERT INTO seguidores_usuarios (seguidor_id, seguido_id) VALUES (?,?)",
        [$seguidorId, $seguidoId]
    );
    db()->execute(
        "UPDATE usuarios SET total_seguidores = total_seguidores + 1 WHERE id = ?",
        [$seguidoId]
    );
    db()->execute(
        "UPDATE usuarios SET total_seguidos = total_seguidos + 1 WHERE id = ?",
        [$seguidorId]
    );

    return ['success'=>true,'action'=>'followed','message'=>'Ahora sigues a este usuario.'];
}

/**
 * Verificar si un usuario sigue a otro
 */
function sigueA(int $seguidorId, int $seguidoId): bool
{
    return db()->count(
        "SELECT COUNT(*) FROM seguidores_usuarios
         WHERE seguidor_id = ? AND seguido_id = ?",
        [$seguidorId, $seguidoId]
    ) > 0;
}

/**
 * Toggle seguir categoría
 */
function toggleSeguirCategoria(int $usuarioId, int $categoriaId): array
{
    $sigueYa = db()->count(
        "SELECT COUNT(*) FROM seguir_categorias
         WHERE usuario_id = ? AND categoria_id = ?",
        [$usuarioId, $categoriaId]
    );

    if ($sigueYa) {
        db()->execute(
            "DELETE FROM seguir_categorias WHERE usuario_id = ? AND categoria_id = ?",
            [$usuarioId, $categoriaId]
        );
        return ['success'=>true,'action'=>'unfollowed','message'=>'Dejaste de seguir esta categoría.'];
    }

    db()->insert(
        "INSERT INTO seguir_categorias (usuario_id, categoria_id) VALUES (?,?)",
        [$usuarioId, $categoriaId]
    );
    return ['success'=>true,'action'=>'followed','message'=>'Ahora sigues esta categoría.'];
}

/**
 * Obtener categorías que sigue un usuario
 */
function getCategoriasSeguidasUsuario(int $usuarioId): array
{
    return db()->fetchAll(
        "SELECT c.id, c.nombre, c.slug, c.color, c.icono
         FROM seguir_categorias sc
         INNER JOIN categorias c ON c.id = sc.categoria_id
         WHERE sc.usuario_id = ?
         ORDER BY c.nombre ASC",
        [$usuarioId]
    );
}

// ============================================================
// 18. INSIGNIAS Y REPUTACIÓN
// ============================================================

/**
 * Verificar y otorgar insignias a un usuario
 */
function verificarInsignias(int $usuarioId): void
{
    try {
        db()->query("CALL sp_otorgar_insignias(?)", [$usuarioId]);
    } catch (\Throwable $e) {}
}

/**
 * Obtener ranking de autores por vistas
 */
function getRankingAutores(int $limit = 10): array
{
    return db()->fetchAll(
        "SELECT u.id, u.nombre, u.avatar, u.verificado, u.reputacion,
                COUNT(n.id)  AS total_noticias,
                SUM(n.vistas) AS total_vistas,
                c2.total_comentarios
         FROM usuarios u
         INNER JOIN noticias n ON n.autor_id = u.id AND n.estado = 'publicado'
         LEFT JOIN (
             SELECT co.usuario_id, COUNT(*) AS total_comentarios
             FROM comentarios co WHERE co.aprobado = 1
             GROUP BY co.usuario_id
         ) c2 ON c2.usuario_id = u.id
         WHERE u.activo = 1
         GROUP BY u.id
         ORDER BY total_vistas DESC
         LIMIT ?",
        [$limit]
    );
}

// ============================================================
// 19. NOTIFICACIONES
// ============================================================

/**
 * Contar notificaciones no leídas
 */
function countNotificacionesNoLeidas(int $usuarioId): int
{
    return db()->count(
        "SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND leida = 0",
        [$usuarioId]
    );
}

/**
 * Obtener notificaciones de un usuario
 */
function getNotificaciones(int $usuarioId, int $limit = 20): array
{
    return db()->fetchAll(
        "SELECT * FROM notificaciones
         WHERE usuario_id = ?
         ORDER BY fecha DESC
         LIMIT ?",
        [$usuarioId, $limit]
    );
}

/**
 * Marcar notificaciones como leídas
 */
function marcarNotificacionesLeidas(int $usuarioId, ?int $notifId = null): void
{
    if ($notifId) {
        db()->execute(
            "UPDATE notificaciones SET leida = 1 WHERE id = ? AND usuario_id = ?",
            [$notifId, $usuarioId]
        );
    } else {
        db()->execute(
            "UPDATE notificaciones SET leida = 1 WHERE usuario_id = ?",
            [$usuarioId]
        );
    }
}

/**
 * Crear notificación para un usuario
 */
function crearNotificacion(int $usuarioId, string $mensaje, string $url = '', string $tipo = 'sistema'): void
{
    try {
        db()->insert(
            "INSERT INTO notificaciones (usuario_id, tipo, mensaje, url, fecha)
             VALUES (?,?,?,?,NOW())",
            [$usuarioId, $tipo, $mensaje, $url]
        );
    } catch (\Throwable $e) {}
}

// ============================================================
// 20. NEWSLETTER
// ============================================================

/**
 * Suscribir al newsletter
 */
function suscribirNewsletter(string $email, string $nombre = ''): array
{
    $email  = strtolower(trim(filter_var($email, FILTER_SANITIZE_EMAIL)));
    $nombre = cleanInput($nombre, 100);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success'=>false,'message'=>'Email no válido.'];
    }

    $existe = db()->fetchOne(
        "SELECT id, activo, confirmado FROM newsletter_suscriptores WHERE email = ? LIMIT 1",
        [$email]
    );

    if ($existe) {
        if ($existe['activo'] && $existe['confirmado']) {
            return ['success'=>false,'message'=>'Este email ya está suscrito.'];
        }
        // Reactivar
        db()->execute(
            "UPDATE newsletter_suscriptores SET activo = 1, nombre = ? WHERE email = ?",
            [$nombre ?: $existe['nombre'], $email]
        );
        return ['success'=>true,'message'=>'¡Suscripción reactivada! Bienvenido de nuevo.'];
    }

    $token = bin2hex(random_bytes(32));
    db()->insert(
        "INSERT INTO newsletter_suscriptores
            (email, nombre, token, confirmado, activo, fecha_registro)
         VALUES (?,?,?,1,1,NOW())",
        [$email, $nombre, $token]
    );

    return ['success'=>true,'message'=>'¡Suscripción exitosa! Bienvenido.'];
}

// ============================================================
// 21. COMPARTIDOS Y ANALYTICS
// ============================================================

/**
 * Registrar compartido de una noticia
 */
function registrarCompartido(int $noticiaId, string $red, ?int $usuarioId = null): void
{
    try {
        $ip = getClientIp();
        db()->query(
            "CALL sp_registrar_compartido(?,?,?,?)",
            [$noticiaId, $red, $usuarioId, $ip]
        );
    } catch (\Throwable $e) {}
}

/**
 * Registrar datos de scroll (analytics)
 */
function registrarScroll(int $noticiaId, int $porcentaje, int $tiempoSeg): void
{
    if (!Config::bool('analytics_scroll_tracking')) return;

    try {
        $ip        = getClientIp();
        $usuarioId = currentUser()['id'] ?? null;

        // Solo registrar si el porcentaje es significativo
        if ($porcentaje < 10) return;

        db()->insert(
            "INSERT INTO scroll_tracking
                (noticia_id, usuario_id, ip, porcentaje, tiempo_seg)
             VALUES (?,?,?,?,?)",
            [$noticiaId, $usuarioId, $ip, min(100, $porcentaje), $tiempoSeg]
        );
    } catch (\Throwable $e) {}
}

/**
 * Registrar clic en heatmap
 */
function registrarHeatmapClic(string $pagina, string $elemento, int $x, int $y, ?int $noticiaId = null): void
{
    if (!Config::bool('analytics_heatmap')) return;

    try {
        db()->insert(
            "INSERT INTO heatmap_clics (pagina, noticia_id, elemento, x_pos, y_pos, ip)
             VALUES (?,?,?,?,?,?)",
            [$pagina, $noticiaId, $elemento, $x, $y, getClientIp()]
        );
    } catch (\Throwable $e) {}
}

/**
 * Obtener estadísticas de compartidos por red
 */
function getEstadisticasCompartidos(int $noticiaId): array
{
    $rows = db()->fetchAll(
        "SELECT red, COUNT(*) AS total FROM compartidos
         WHERE noticia_id = ?
         GROUP BY red ORDER BY total DESC",
        [$noticiaId]
    );
    return array_column($rows, 'total', 'red');
}

// ============================================================
// 22. CONFIGURACIÓN GLOBAL
// ============================================================

/**
 * Obtener configuración (wrapper corto)
 */
function cfg(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/**
 * Obtener URL de redes sociales configuradas
 */
function getRedesSociales(): array
{
    return [
        'facebook'  => Config::get('social_facebook',  ''),
        'twitter'   => Config::get('social_twitter',   ''),
        'instagram' => Config::get('social_instagram', ''),
        'tiktok'    => Config::get('social_tiktok',    ''),
        'youtube'   => Config::get('social_youtube',   ''),
        'whatsapp'  => Config::get('social_whatsapp',  ''),
        'telegram'  => Config::get('social_telegram',  ''),
    ];
}

/**
 * Generar botones de compartir para una noticia
 */
function getShareButtons(array $noticia): array
{
    $url   = urlencode(APP_URL . '/noticia.php?slug=' . $noticia['slug']);
    $title = urlencode($noticia['titulo']);
    $redes = getRedesSociales();

    $buttons = [];

    $buttons[] = [
        'red'    => 'whatsapp',
        'label'  => 'WhatsApp',
        'icon'   => 'bi-whatsapp',
        'color'  => '#25D366',
        'url'    => "https://wa.me/?text={$title}%20{$url}",
    ];
    $buttons[] = [
        'red'    => 'facebook',
        'label'  => 'Facebook',
        'icon'   => 'bi-facebook',
        'color'  => '#1877F2',
        'url'    => "https://www.facebook.com/sharer/sharer.php?u={$url}",
    ];
    $buttons[] = [
        'red'    => 'twitter',
        'label'  => 'Twitter/X',
        'icon'   => 'bi-twitter-x',
        'color'  => '#000000',
        'url'    => "https://twitter.com/intent/tweet?text={$title}&url={$url}",
    ];
    $buttons[] = [
        'red'    => 'telegram',
        'label'  => 'Telegram',
        'icon'   => 'bi-telegram',
        'color'  => '#229ED9',
        'url'    => "https://t.me/share/url?url={$url}&text={$title}",
    ];
    $buttons[] = [
        'red'    => 'linkedin',
        'label'  => 'LinkedIn',
        'icon'   => 'bi-linkedin',
        'color'  => '#0077B5',
        'url'    => "https://www.linkedin.com/shareArticle?url={$url}&title={$title}",
    ];

    return $buttons;
}

// ============================================================
// 23. CLIMA — Versión corregida (soluciona error 401)
// ============================================================

/**
 * Obtener datos del clima (OpenWeatherMap)
 * Correcciones v2.1:
 *   - trim() en apiKey para evitar espacios invisibles en BD
 *   - Verificación de sesión activa antes de usar caché
 *   - Logging en modo debug para diagnóstico del error 401
 *   - Fallback con ciudad + código país (ej: "Santo Domingo,DO")
 *   - Timeout aumentado a 8s
 */
function getClima(?string $ciudad = null): ?array
{
    $ciudad = trim($ciudad ?: Config::get('clima_ciudad', 'Santo Domingo'));
    $apiKey = trim(Config::get('clima_api_key', ''));   // ← trim() elimina espacios/saltos guardados en BD
    $unidad = Config::get('clima_unidad', 'C') === 'F' ? 'imperial' : 'metric';

    if (empty($apiKey)) return null;

    // ── Caché en sesión por 30 minutos ──────────────────────
    // Verificar que la sesión esté iniciada antes de usarla
    $sessionOk = session_status() === PHP_SESSION_ACTIVE;
    $cacheKey  = 'clima_' . md5($ciudad . $unidad);

    if ($sessionOk &&
        isset($_SESSION[$cacheKey], $_SESSION[$cacheKey . '_ts']) &&
        $_SESSION[$cacheKey . '_ts'] > time() - 1800) {
        return $_SESSION[$cacheKey];
    }

    try {
        // ── Construir URL con encoding correcto ─────────────
        $params = http_build_query([
            'q'     => $ciudad,
            'appid' => $apiKey,
            'units' => $unidad,
            'lang'  => 'es',
        ]);
        $url = "https://api.openweathermap.org/data/2.5/weather?" . $params;

        $ctx = stream_context_create([
            'http' => [
                'timeout'       => 8,                          // aumentado de 5 a 8s
                'ignore_errors' => true,                       // para leer respuestas 4xx
                'method'        => 'GET',
                'header'        => 'User-Agent: ElClickRD/2.0 PHP/' . PHP_VERSION . "\r\n",
            ],
        ]);

        $json = @file_get_contents($url, false, $ctx);

        if ($json === false || empty($json)) {
            if (APP_DEBUG) {
                error_log("[getClima] No se pudo conectar con OpenWeatherMap. URL: $url");
            }
            return null;
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            if (APP_DEBUG) error_log("[getClima] JSON inválido recibido");
            return null;
        }

        // ── Manejar errores de la API ────────────────────────
        $cod = $data['cod'] ?? 0;

        if ((string)$cod === '401') {
            // API Key inválida o no activada aún (tarda ~2h en activarse)
            if (APP_DEBUG) {
                error_log("[getClima] Error 401: API key inválida o no activada. Key: " . substr($apiKey, 0, 8) . '...');
            }
            return null;
        }

        if ((string)$cod === '404') {
            // Ciudad no encontrada — intentar con formato "Ciudad,XX"
            if (!str_contains($ciudad, ',')) {
                // Reintento con Santo Domingo específico de RD
                $paramsRetry = http_build_query([
                    'q'     => $ciudad . ',DO',
                    'appid' => $apiKey,
                    'units' => $unidad,
                    'lang'  => 'es',
                ]);
                $jsonRetry = @file_get_contents(
                    "https://api.openweathermap.org/data/2.5/weather?" . $paramsRetry,
                    false,
                    $ctx
                );
                if ($jsonRetry) {
                    $dataRetry = json_decode($jsonRetry, true);
                    if (($dataRetry['cod'] ?? 0) == 200) {
                        $data = $dataRetry;
                        $cod  = 200;
                    }
                }
            }
        }

        if ((int)$cod !== 200) {
            if (APP_DEBUG) {
                error_log("[getClima] Error API cod={$cod} msg=" . ($data['message'] ?? 'n/a'));
            }
            return null;
        }

        $result = [
            'ciudad'      => $data['name'],
            'pais'        => $data['sys']['country'] ?? 'DO',
            'temp'        => round($data['main']['temp']),
            'sensacion'   => round($data['main']['feels_like']),
            'descripcion' => ucfirst($data['weather'][0]['description'] ?? ''),
            'icono'       => $data['weather'][0]['icon'] ?? '01d',
            'icono_url'   => "https://openweathermap.org/img/wn/{$data['weather'][0]['icon']}@2x.png",
            'humedad'     => $data['main']['humidity'] ?? 0,
            'viento'      => round($data['wind']['speed'] ?? 0),
            'unidad'      => Config::get('clima_unidad', 'C'),
        ];

        // Guardar en caché de sesión
        if ($sessionOk) {
            $_SESSION[$cacheKey]         = $result;
            $_SESSION[$cacheKey . '_ts'] = time();
        }

        return $result;

    } catch (\Throwable $e) {
        if (APP_DEBUG) {
            error_log("[getClima] Excepción: " . $e->getMessage());
        }
        return null;
    }
}

// ============================================================
// TASAS DE CAMBIO — USD/EUR → DOP
// ============================================================

/**
 * Obtener tasas de cambio guardadas en BD por el cron update_rates.php
 *
 * @return array ['usd_dop' => float, 'eur_dop' => float, 'ultima_actualizacion' => string]
 */
function getTasasCambio(): array
{
    static $cache = null;

    if ($cache !== null) return $cache;

    $defecto = [
        'usd_dop'              => 0.0,
        'eur_dop'              => 0.0,
        'ultima_actualizacion' => '',
        'disponible'           => false,
    ];

    try {
        $usdDOP = (float)Config::get('tasa_usd_dop', '0');
        $eurDOP = (float)Config::get('tasa_eur_dop', '0');
        $ultima = Config::get('tasa_ultima_actualizacion', '');

        if ($usdDOP <= 0 || $eurDOP <= 0) {
            $cache = $defecto;
            return $cache;
        }

        $cache = [
            'usd_dop'              => $usdDOP,
            'eur_dop'              => $eurDOP,
            'ultima_actualizacion' => $ultima,
            'disponible'           => true,
        ];

        return $cache;

    } catch (\Throwable $e) {
        $cache = $defecto;
        return $cache;
    }
}

// ============================================================
// 24. BÚSQUEDA AVANZADA
// ============================================================

/**
 * Buscar noticias con filtros múltiples
 */
function buscarNoticias(
    string $texto,
    ?int $categoriaId    = null,
    ?string $fechaDesde  = null,
    ?string $fechaHasta  = null,
    int $limit           = NOTICIAS_POR_PAGINA,
    int $offset          = 0
): array {
    $where  = ["n.estado = 'publicado'", "n.fecha_publicacion <= NOW()"];
    $params = [];

    if (!empty($texto)) {
        $where[]  = "MATCH(n.titulo, n.resumen, n.contenido) AGAINST(? IN BOOLEAN MODE)";
        $params[] = $texto . '*';
    }
    if ($categoriaId) {
        $where[]  = 'n.categoria_id = ?';
        $params[] = $categoriaId;
    }
    if ($fechaDesde) {
        $where[]  = 'DATE(n.fecha_publicacion) >= ?';
        $params[] = $fechaDesde;
    }
    if ($fechaHasta) {
        $where[]  = 'DATE(n.fecha_publicacion) <= ?';
        $params[] = $fechaHasta;
    }

    $whereStr = implode(' AND ', $where);

    $orderBy = !empty($texto)
        ? "ORDER BY MATCH(n.titulo, n.resumen, n.contenido) AGAINST(? IN BOOLEAN MODE) DESC, n.fecha_publicacion DESC"
        : "ORDER BY n.fecha_publicacion DESC";

    if (!empty($texto)) {
        array_unshift($params, $texto . '*');
    }

    $params[] = $limit;
    $params[] = $offset;

    return db()->fetchAll(
        "SELECT n.id, n.titulo, n.slug, n.resumen, n.imagen,
                n.vistas, n.fecha_publicacion, n.tiempo_lectura,
                u.nombre AS autor_nombre,
                c.nombre AS cat_nombre, c.slug AS cat_slug, c.color AS cat_color
         FROM noticias n
         INNER JOIN usuarios   u ON u.id = n.autor_id
         INNER JOIN categorias c ON c.id = n.categoria_id
         WHERE $whereStr
         $orderBy
         LIMIT ? OFFSET ?",
        $params
    );
}

/**
 * Contar resultados de búsqueda
 */
function countBusqueda(
    string $texto,
    ?int $categoriaId   = null,
    ?string $fechaDesde = null,
    ?string $fechaHasta = null
): int {
    $where  = ["n.estado = 'publicado'", "n.fecha_publicacion <= NOW()"];
    $params = [];

    if (!empty($texto)) {
        $where[]  = "MATCH(n.titulo, n.resumen, n.contenido) AGAINST(? IN BOOLEAN MODE)";
        $params[] = $texto . '*';
    }
    if ($categoriaId) {
        $where[]  = 'n.categoria_id = ?';
        $params[] = $categoriaId;
    }
    if ($fechaDesde) {
        $where[]  = 'DATE(n.fecha_publicacion) >= ?';
        $params[] = $fechaDesde;
    }
    if ($fechaHasta) {
        $where[]  = 'DATE(n.fecha_publicacion) <= ?';
        $params[] = $fechaHasta;
    }

    return db()->count(
        "SELECT COUNT(*) FROM noticias n
         INNER JOIN usuarios   u ON u.id = n.autor_id
         INNER JOIN categorias c ON c.id = n.categoria_id
         WHERE " . implode(' AND ', $where),
        $params
    );
}

// ============================================================
// 25. PWA Y MOBILE
// ============================================================

/**
 * Generar manifest.json dinámico para PWA
 */
function generatePWAManifest(): string
{
    $nombre  = Config::get('site_nombre',  APP_NAME);
    $color   = Config::get('apariencia_color_primario', '#e63946');
    $logo    = Config::get('site_logo', '');
    $iconUrl = $logo ? APP_URL . '/' . ltrim($logo, '/') : APP_URL . '/assets/images/icon-192.png';

    $manifest = [
        'name'             => $nombre,
        'short_name'       => mb_substr($nombre, 0, 12),
        'description'      => Config::get('site_descripcion_seo', APP_TAGLINE),
        'start_url'        => '/',
        'display'          => 'standalone',
        'background_color' => '#ffffff',
        'theme_color'      => $color,
        'orientation'      => 'portrait-primary',
        'categories'       => ['news', 'magazines'],
        'lang'             => 'es',
        'icons'            => [
            ['src'=>$iconUrl,'sizes'=>'192x192','type'=>'image/png','purpose'=>'any maskable'],
            ['src'=>$iconUrl,'sizes'=>'512x512','type'=>'image/png','purpose'=>'any maskable'],
        ],
        'shortcuts'        => [
            [
                'name'     => 'Inicio',
                'url'      => '/',
                'icons'    => [['src'=>$iconUrl,'sizes'=>'96x96']],
            ],
            [
                'name'     => 'Buscar',
                'url'      => '/buscar.php',
                'icons'    => [['src'=>$iconUrl,'sizes'=>'96x96']],
            ],
        ],
    ];

    return json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

/**
 * Generar etiquetas PWA para el head
 */
function getPWAHeadTags(): string
{
    $color  = Config::get('apariencia_color_primario', '#e63946');
    $nombre = Config::get('site_nombre', APP_NAME);

    return "
    <link rel=\"manifest\" href=\"" . APP_URL . "/manifest.json\">
    <meta name=\"theme-color\" content=\"{$color}\">
    <meta name=\"mobile-web-app-capable\" content=\"yes\">
    <meta name=\"apple-mobile-web-app-capable\" content=\"yes\">
    <meta name=\"apple-mobile-web-app-status-bar-style\" content=\"default\">
    <meta name=\"apple-mobile-web-app-title\" content=\"" . e($nombre) . "\">
    <link rel=\"apple-touch-icon\" href=\"" . APP_URL . "/assets/images/icon-192.png\">";
}

// ============================================================
// 26. UTILIDADES ADMIN
// ============================================================

/**
 * Obtener estadísticas generales para el dashboard
 */
function getEstadisticasAdmin(): array
{
    try {
        return db()->fetchOne("SELECT * FROM v_estadisticas_admin") ?? [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Obtener visitas diarias para gráfico (últimos N días)
 *
 * Fuente primaria  → visitas_log (registro real por IP/fecha)
 * Fuente fallback  → SUM(noticias.vistas) agrupado por fecha_publicacion
 *                    Se activa automáticamente cuando visitas_log aún no tiene
 *                    datos (tabla vacía o sitio recién instalado).
 */
function getVisitasDiarias(int $dias = 14): array
{
    $labels = $data = [];

    try {
        // ── 1. Intentar desde visitas_log (fuente real de tracking) ──
        $visitasBD = db()->fetchAll(
            "SELECT DATE(fecha) AS dia, COUNT(*) AS total
             FROM visitas_log
             WHERE fecha >= NOW() - INTERVAL ? DAY
             GROUP BY DATE(fecha)
             ORDER BY dia ASC",
            [$dias]
        );

        $mapaVisitas = array_column($visitasBD, 'total', 'dia');
        $totalLog    = array_sum(array_map('intval', $mapaVisitas));

        // ── 2. Si visitas_log está vacía, usar noticias.vistas como fallback ──
        if ($totalLog === 0) {
            $visitasBD = db()->fetchAll(
                "SELECT DATE(n.fecha_publicacion) AS dia,
                        SUM(n.vistas)             AS total
                 FROM noticias n
                 WHERE n.estado          = 'publicado'
                   AND n.fecha_publicacion >= NOW() - INTERVAL ? DAY
                   AND n.fecha_publicacion <= NOW()
                 GROUP BY DATE(n.fecha_publicacion)
                 ORDER BY dia ASC",
                [$dias]
            );
            $mapaVisitas = array_column($visitasBD, 'total', 'dia');
        }

    } catch (\Throwable $e) {
        $mapaVisitas = [];
        error_log('[getVisitasDiarias] Error: ' . $e->getMessage());
    }

    // ── 3. Construir serie de fechas consecutivas ─────────────
    for ($i = $dias - 1; $i >= 0; $i--) {
        $date     = date('Y-m-d', strtotime("-{$i} days"));
        $labels[] = date('d/m', strtotime($date));
        $data[]   = (int)($mapaVisitas[$date] ?? 0);
    }

    return ['labels' => $labels, 'data' => $data];
}

/**
 * Obtener log de actividad admin
 */
function getActividadAdmin(int $limit = 50, ?int $usuarioId = null): array
{
    $params = [];
    $where  = '';

    if ($usuarioId) {
        $where    = 'WHERE aa.usuario_id = ?';
        $params[] = $usuarioId;
    }

    $params[] = $limit;

    return db()->fetchAll(
        "SELECT aa.*, u.nombre AS usuario_nombre, u.avatar AS usuario_avatar
         FROM actividad_admin aa
         INNER JOIN usuarios u ON u.id = aa.usuario_id
         $where
         ORDER BY aa.fecha DESC
         LIMIT ?",
        $params
    );
}

/**
 * Obtener reportes pendientes
 */
function getReportesPendientes(int $limit = 20): array
{
    return db()->fetchAll(
        "SELECT * FROM reportes_contenido
         WHERE estado = 'pendiente'
         ORDER BY fecha DESC
         LIMIT ?",
        [$limit]
    );
}

/**
 * Obtener sesiones activas del sistema (para admin)
 */
function getSesionesActivasAdmin(int $limit = 50): array
{
    return db()->fetchAll(
        "SELECT sa.*, u.nombre AS usuario_nombre, u.rol AS usuario_rol
         FROM sesiones_activas sa
         INNER JOIN usuarios u ON u.id = sa.usuario_id
         WHERE sa.activa = 1
           AND sa.ultimo_acceso > NOW() - INTERVAL 2 HOUR
         ORDER BY sa.ultimo_acceso DESC
         LIMIT ?",
        [$limit]
    );
}

/**
 * Crear backup de la base de datos
 */
function crearBackup(?int $adminId = null): array
{
    $backupDir = ROOT_PATH . '/backups/';
    if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

    $filename = 'backup_' . DB_NAME . '_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $backupDir . $filename;

    $cmd = sprintf(
        'mysqldump --user=%s --password=%s --host=%s %s > %s 2>&1',
        escapeshellarg(DB_USER),
        escapeshellarg(DB_PASS),
        escapeshellarg(DB_HOST),
        escapeshellarg(DB_NAME),
        escapeshellarg($filepath)
    );

    @exec($cmd, $output, $returnCode);

    $tamano = file_exists($filepath) ? filesize($filepath) : 0;
    $estado = ($returnCode === 0 && $tamano > 0) ? 'completado' : 'fallido';

    // Registrar en log
    try {
        db()->insert(
            "INSERT INTO backup_log (tipo, archivo, tamano, usuario_id, estado)
             VALUES ('manual', ?, ?, ?, ?)",
            [$filename, $tamano, $adminId, $estado]
        );
    } catch (\Throwable $e) {}

    if ($estado === 'completado') {
        return ['success'=>true,'archivo'=>$filename,'tamano'=>$tamano];
    }

    return ['success'=>false,'error'=>'No se pudo crear el backup. Verifica permisos y mysqldump.'];
}

/**
 * Helper global para verificar CSRF token
 */
function verifyCsrf(string $token): bool
{
    global $auth;
    return $auth->verifyCSRF($token);
}

// ============================================================
// MÓDULO: PRECIO DE COMBUSTIBLES — Funciones
// ============================================================

/**
 * Obtiene los precios de la semana actual (es_actual=1, publicado=1)
 * con JOIN a combustibles_tipos para tener icono, color, nombre.
 */
function getCombustiblesVigentes(): array
{
    try {
        return db()->fetchAll(
            "SELECT
                cp.id,
                cp.combustible_id,
                cp.precio,
                cp.precio_anterior,
                cp.variacion,
                cp.variacion_pct,
                cp.semana_inicio,
                cp.semana_fin,
                cp.fecha_vigencia,
                cp.nota,
                ct.nombre,
                ct.slug        AS tipo_slug,
                ct.icono,
                ct.color,
                ct.unidad,
                ct.orden
             FROM combustibles_precios cp
             INNER JOIN combustibles_tipos ct ON ct.id = cp.combustible_id
             INNER JOIN combustibles_semanas cs
                     ON cs.semana_inicio = cp.semana_inicio
             WHERE cs.es_actual  = 1
               AND cs.publicado  = 1
               AND cp.publicado  = 1
               AND ct.activo     = 1
             ORDER BY ct.orden ASC"
        );
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Obtiene la semana actual (es_actual=1 y publicado=1).
 */
function getCombustiblesSemanaActual(): ?array
{
    try {
        return db()->fetchOne(
            "SELECT *
             FROM combustibles_semanas
             WHERE es_actual = 1
               AND publicado = 1
             LIMIT 1"
        ) ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Obtiene semanas publicadas paginadas (para página pública).
 */
function getCombustiblesSemanas(int $limit = 10, int $offset = 0): array
{
    try {
        return db()->fetchAll(
            "SELECT cs.*,
                    u.nombre AS autor_nombre
             FROM combustibles_semanas cs
             LEFT JOIN usuarios u ON u.id = cs.autor_id
             WHERE cs.publicado = 1
             ORDER BY cs.fecha_vigencia DESC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Cuenta semanas publicadas (para paginación pública).
 */
function countCombustiblesSemanas(): int
{
    try {
        return (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM combustibles_semanas WHERE publicado = 1"
        );
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * Obtiene precios de una semana específica por semana_inicio.
 */
function getCombustiblesHistorico(string $semanaInicio): array
{
    try {
        return db()->fetchAll(
            "SELECT
                cp.*,
                ct.nombre,
                ct.icono,
                ct.color,
                ct.unidad,
                ct.orden
             FROM combustibles_precios cp
             INNER JOIN combustibles_tipos ct ON ct.id = cp.combustible_id
             WHERE cp.semana_inicio = ?
               AND cp.publicado     = 1
               AND ct.activo        = 1
             ORDER BY ct.orden ASC",
            [$semanaInicio]
        );
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Retorna datos de las últimas N semanas para Chart.js.
 * Estructura: ['labels'=>[...], 'tipos'=>[...], 'datasets'=>[...]]
 */
function getCombustiblesTendencia(int $ultimasSemanas = 8): array
{
    try {
        // Obtener últimas N semanas publicadas
        $semanas = db()->fetchAll(
            "SELECT semana_inicio, fecha_vigencia
             FROM combustibles_semanas
             WHERE publicado = 1
             ORDER BY fecha_vigencia DESC
             LIMIT ?",
            [$ultimasSemanas]
        );

        if (empty($semanas)) {
            return ['labels' => [], 'tipos' => [], 'datasets' => []];
        }

        // Invertir para orden cronológico ascendente
        $semanas = array_reverse($semanas);

        // Obtener tipos activos
        $tipos = db()->fetchAll(
            "SELECT id, nombre, color, slug FROM combustibles_tipos
             WHERE activo = 1 ORDER BY orden ASC"
        );

        // Labels (fechas formateadas)
        $labels = array_map(function ($s) {
            $ts = strtotime($s['fecha_vigencia']);
            return date('d/m', $ts);
        }, $semanas);

        // Construir datasets
        $datasets = [];
        $semanasInicio = array_column($semanas, 'semana_inicio');

        foreach ($tipos as $tipo) {
            $data = [];
            foreach ($semanasInicio as $si) {
                $precio = db()->fetchColumn(
                    "SELECT precio FROM combustibles_precios
                     WHERE combustible_id = ?
                       AND semana_inicio  = ?
                       AND publicado      = 1
                     LIMIT 1",
                    [$tipo['id'], $si]
                );
                $data[] = $precio !== false ? (float) $precio : null;
            }
            $datasets[] = [
                'id'    => $tipo['id'],
                'label' => $tipo['nombre'],
                'color' => $tipo['color'],
                'slug'  => $tipo['slug'],
                'data'  => $data,
            ];
        }

        return [
            'labels'   => $labels,
            'tipos'    => $tipos,
            'datasets' => $datasets,
        ];
    } catch (\Throwable $e) {
        return ['labels' => [], 'tipos' => [], 'datasets' => []];
    }
}

/**
 * Retorna la fecha del próximo viernes (o el siguiente si hoy es viernes).
 */
function getProximoViernes(): string
{
    $hoy = new \DateTime();
    $dow = (int) $hoy->format('N'); // 1=lunes, 5=viernes, 7=domingo
    $diasHastaViernes = (5 - $dow + 7) % 7;
    if ($diasHastaViernes === 0) {
        $diasHastaViernes = 7; // Si hoy es viernes, el próximo
    }
    $hoy->modify("+{$diasHastaViernes} days");
    return $hoy->format('Y-m-d');
}

/**
 * Dado un viernes, retorna el lunes y domingo de esa semana.
 */
function getLunesYDomingoDeViernes(string $viernes): array
{
    $dt   = new \DateTime($viernes);
    $dow  = (int) $dt->format('N'); // 5 para viernes
    $lunes = clone $dt;
    $lunes->modify('-' . ($dow - 1) . ' days');
    $domingo = clone $lunes;
    $domingo->modify('+6 days');
    return [
        'inicio' => $lunes->format('Y-m-d'),
        'fin'    => $domingo->format('Y-m-d'),
    ];
}

/**
 * Formatea una fecha en español.
 * Ejemplo: '2026-05-09' → 'Viernes, 09 de Mayo de 2026'
 */
function formatFechaEsp(string $fecha, string $formato = 'completo'): string
{
    if (empty($fecha)) return '';
    $ts = strtotime($fecha);
    if (!$ts) return '';

    $dias   = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    $meses  = [
        1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',
        6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',
        10=>'Octubre',11=>'Noviembre',12=>'Diciembre'
    ];

    $diaSem = $dias[(int) date('w', $ts)];
    $diaNum = date('d', $ts);
    $mes    = $meses[(int) date('n', $ts)];
    $año    = date('Y', $ts);

    return match($formato) {
        'corto'    => "{$diaNum}/{$mes}/{$año}",
        'medio'    => "{$diaNum} {$mes} {$año}",
        'semana'   => "{$diaNum} {$mes}",
        default    => "{$diaSem}, {$diaNum} de {$mes} de {$año}",
    };
}

/**
 * Obtiene todos los tipos de combustible activos.
 */
function getCombustiblesTipos(bool $soloActivos = true): array
{
    try {
        $where = $soloActivos ? 'WHERE activo = 1' : '';
        return db()->fetchAll(
            "SELECT * FROM combustibles_tipos {$where} ORDER BY orden ASC"
        );
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Obtiene todas las semanas (admin) con filtros.
 */
function getCombustiblesSemanasAdmin(
    int    $limit    = 15,
    int    $offset   = 0,
    string $estado   = '',
    string $busqueda = ''
): array {
    try {
        $where  = ['1=1'];
        $params = [];

        if ($estado === 'publicado') {
            $where[] = 'cs.publicado = 1';
        } elseif ($estado === 'borrador') {
            $where[] = 'cs.publicado = 0';
        }

        if (!empty($busqueda)) {
            $where[]  = '(cs.titulo LIKE ? OR cs.fecha_vigencia LIKE ?)';
            $params[] = "%{$busqueda}%";
            $params[] = "%{$busqueda}%";
        }

        $wSQL = implode(' AND ', $where);
        return db()->fetchAll(
            "SELECT cs.*, u.nombre AS autor_nombre
             FROM combustibles_semanas cs
             LEFT JOIN usuarios u ON u.id = cs.autor_id
             WHERE {$wSQL}
             ORDER BY cs.fecha_vigencia DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Cuenta semanas admin con filtros.
 */
function countCombustiblesSemanasAdmin(
    string $estado   = '',
    string $busqueda = ''
): int {
    try {
        $where  = ['1=1'];
        $params = [];

        if ($estado === 'publicado') {
            $where[] = 'publicado = 1';
        } elseif ($estado === 'borrador') {
            $where[] = 'publicado = 0';
        }

        if (!empty($busqueda)) {
            $where[]  = '(titulo LIKE ? OR fecha_vigencia LIKE ?)';
            $params[] = "%{$busqueda}%";
            $params[] = "%{$busqueda}%";
        }

        $wSQL = implode(' AND ', $where);
        return (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM combustibles_semanas WHERE {$wSQL}",
            $params
        );
    } catch (\Throwable $e) {
        return 0;
    }
}