<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Configuración y Base de Datos
 * ============================================================
 * Archivo  : config/database.php
 * Versión  : 2.0.0
 * Autor    : MM Lab Studio
 * ============================================================
 * RESPONSABILIDADES:
 *  1. Constantes de conexión y aplicación
 *  2. Clase Database (Singleton PDO) con todos los métodos
 *  3. Carga dinámica de configuración desde BD
 *  4. Configuración global de PHP
 * ============================================================
 */

declare(strict_types=1);

// ============================================================
// GUARD — Prevenir inclusión múltiple
// ============================================================
if (defined('APP_NAME')) {
    return;
}

// ============================================================
// 1. CONSTANTES DE CONEXIÓN A BASE DE DATOS
// ============================================================
define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'lnuazoql_elclickrdv2');
define('DB_USER',    'lnuazoql_elclickrdv2');    // ← cambiar por el tuyo
define('DB_PASS',    '*Camil7172*');    // ← cambiar por el tuyo
define('DB_CHARSET', 'utf8mb4');

// ============================================================
// 2. CONSTANTES DE APLICACIÓN (BASE — serán sobreescritas por BD)
// ============================================================
define('APP_NAME',     'El Click RD');
define('APP_TAGLINE',  'Tu fuente de noticias digitales');
define('APP_VERSION',  '2.0.0');
define('APP_DEBUG',    false);  // true solo en desarrollo
define('APP_TIMEZONE', 'America/Santo_Domingo');

// URL base del sitio (auto-detectada o manual)
if (!defined('APP_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('APP_URL', rtrim($protocol . '://' . $host, '/'));
}

// ============================================================
// 3. RUTAS DEL SISTEMA
// ============================================================
define('ROOT_PATH',   dirname(__DIR__));
define('UPLOAD_PATH', ROOT_PATH . '/assets/images/noticias/');
define('UPLOAD_URL',  APP_URL  . '/assets/images/noticias/');
define('AVATAR_PATH', ROOT_PATH . '/assets/images/avatars/');
define('AVATAR_URL',  APP_URL  . '/assets/images/avatars/');
define('ADS_PATH',    ROOT_PATH . '/assets/images/anuncios/');
define('ADS_URL',     APP_URL  . '/assets/images/anuncios/');
define('VIDEO_THUMB_PATH', ROOT_PATH . '/assets/images/videos/');
define('VIDEO_THUMB_URL',  APP_URL  . '/assets/images/videos/');
define('LOGS_PATH',   ROOT_PATH . '/logs/');

// ============================================================
// 4. CONSTANTES DE NEGOCIO
// ============================================================
define('NOTICIAS_POR_PAGINA',   12);
define('COMENTARIOS_POR_PAGINA', 20);
define('MAX_FILE_SIZE',  5 * 1024 * 1024);   // 5 MB
define('MAX_VIDEO_SIZE', 50 * 1024 * 1024);  // 50 MB
define('SESSION_LIFETIME', 86400 * 30);       // 30 días
define('SESSION_NAME',    'PDPRO_SID');
define('CSRF_TOKEN_NAME', 'csrf_token');
define('REMEMBER_COOKIE', 'pd_remember');
define('RATE_LIMIT_LOGIN', 5);                // intentos por IP
define('RATE_LIMIT_WINDOW', 900);             // 15 minutos

// Rutas de imágenes de fallback
define('IMG_DEFAULT_NEWS',   APP_URL . '/assets/images/default-news.jpg');
define('IMG_DEFAULT_AVATAR', APP_URL . '/assets/images/default-avatar.png');
define('IMG_DEFAULT_OG',     APP_URL . '/assets/images/og-default.jpg');

// ── API de Tasas de Cambio ────────────────────────────────────
// Plataforma: https://app.exchangerate-api.com/ (plan gratuito)
// Cron: 0 0,12 * * * /usr/local/bin/php /home/lnuazoql/elclickrd.com/update_rates.php
define('EXCHANGE_API_KEY', '49d6feee74b797bfef8a7bf4');  // ← Cambia por tu key real

// ============================================================
// 5. CLASE PRINCIPAL DE BASE DE DATOS (Singleton PDO)
// ============================================================
class Database
{
    /** @var Database|null Instancia única */
    private static ?Database $instance = null;

    /** @var \PDO Conexión PDO activa */
    private \PDO $connection;

    /** @var array Opciones PDO */
    private array $options = [
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES   => false,
        \PDO::ATTR_PERSISTENT         => false,
        \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci,
                                          time_zone='+00:00'",
    ];

    /** @var array Cache de queries para optimización */
    private array $queryCache = [];

    /** @var int Contador de queries ejecutadas */
    private int $queryCount = 0;

    // ----------------------------------------------------------
    // Constructor privado (Singleton)
    // ----------------------------------------------------------
    private function __construct()
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );

        try {
            $this->connection = new \PDO($dsn, DB_USER, DB_PASS, $this->options);
        } catch (\PDOException $e) {
            if (APP_DEBUG) {
                $this->renderError($e->getMessage());
            } else {
                $this->renderError('Error de conexión. Por favor intente más tarde.');
            }
            exit;
        }
    }

    private function __clone() {}
    public function __wakeup() { throw new \Exception("Cannot unserialize a Singleton."); }

    // ----------------------------------------------------------
    // Obtener instancia única
    // ----------------------------------------------------------
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ----------------------------------------------------------
    // Obtener conexión PDO cruda
    // ----------------------------------------------------------
    public function getConnection(): \PDO
    {
        return $this->connection;
    }

    // ----------------------------------------------------------
    // Preparar y ejecutar query con parámetros
    // ----------------------------------------------------------
    public function query(string $sql, array $params = []): \PDOStatement
    {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            $this->queryCount++;

            if (APP_DEBUG) {
                error_log("[DB Query #{$this->queryCount}] " . preg_replace('/\s+/', ' ', $sql));
            }

            return $stmt;
        } catch (\PDOException $e) {
            if (APP_DEBUG) {
                $this->renderError("Query error: " . $e->getMessage() . "\nSQL: $sql\nParams: " . print_r($params, true));
            }
            throw $e;
        }
    }

    // ----------------------------------------------------------
    // Obtener un solo registro
    // ----------------------------------------------------------
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();
        return $result ?: null;
    }

    // ----------------------------------------------------------
    // Obtener todos los registros
    // ----------------------------------------------------------
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    // ----------------------------------------------------------
    // Obtener un solo valor (primera columna)
    // ----------------------------------------------------------
    public function fetchColumn(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    // ----------------------------------------------------------
    // Insertar registro y retornar el último ID
    // ----------------------------------------------------------
    public function insert(string $sql, array $params = []): int
    {
        $this->query($sql, $params);
        return (int) $this->connection->lastInsertId();
    }

    // ----------------------------------------------------------
    // Actualizar / Eliminar y retornar filas afectadas
    // ----------------------------------------------------------
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    // ----------------------------------------------------------
    // Contar registros
    // ----------------------------------------------------------
    public function count(string $sql, array $params = []): int
    {
        $result = $this->query($sql, $params)->fetchColumn();
        return (int) ($result ?: 0);
    }

    // ----------------------------------------------------------
    // Insertar o actualizar (UPSERT) un registro
    // ----------------------------------------------------------
    public function upsert(string $table, array $data, array $updateFields = []): int
    {
        $columns      = array_keys($data);
        $placeholders = array_map(fn($c) => ":$c", $columns);
        $insertSql    = "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";

        if (!empty($updateFields)) {
            $updates   = array_map(fn($f) => "`$f` = VALUES(`$f`)", $updateFields);
            $insertSql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
        } else {
            $insertSql .= " ON DUPLICATE KEY UPDATE `" . $columns[0] . "` = VALUES(`" . $columns[0] . "`)";
        }

        $namedParams = [];
        foreach ($data as $key => $val) {
            $namedParams[":$key"] = $val;
        }

        return $this->insert($insertSql, $namedParams);
    }

    // ----------------------------------------------------------
    // TRANSACCIONES
    // ----------------------------------------------------------
    public function beginTransaction(): bool
    {
        return $this->connection->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->connection->commit();
    }

    public function rollback(): bool
    {
        return $this->connection->rollBack();
    }

    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    // ----------------------------------------------------------
    // Cache simple de queries (clave manual)
    // ----------------------------------------------------------
    public function cachedQuery(string $cacheKey, string $sql, array $params = [], int $ttl = 300): array
    {
        if (isset($this->queryCache[$cacheKey])) {
            $cached = $this->queryCache[$cacheKey];
            if (time() < $cached['expires']) {
                return $cached['data'];
            }
        }

        $data = $this->fetchAll($sql, $params);
        $this->queryCache[$cacheKey] = [
            'data'    => $data,
            'expires' => time() + $ttl,
        ];
        return $data;
    }

    public function invalidateCache(string $key): void
    {
        unset($this->queryCache[$key]);
    }

    public function invalidateCachePrefix(string $prefix): void
    {
        foreach (array_keys($this->queryCache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->queryCache[$key]);
            }
        }
    }

    // ----------------------------------------------------------
    // Obtener número de queries ejecutadas
    // ----------------------------------------------------------
    public function getQueryCount(): int
    {
        return $this->queryCount;
    }

    // ----------------------------------------------------------
    // Renderizar error de conexión con diseño
    // ----------------------------------------------------------
    private function renderError(string $message): void
    {
        http_response_code(500);
        // Suprimir output anterior
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error del Sistema</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:system-ui,sans-serif;background:#0a0a0f;color:#fff;
             display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
        .box{background:#111;border:1px solid #e63946;border-radius:16px;
             padding:40px;max-width:600px;width:100%;text-align:center}
        .icon{font-size:3rem;margin-bottom:20px}
        h1{color:#e63946;font-size:1.4rem;margin-bottom:10px}
        p{color:#999;font-size:.85rem;margin-top:15px;background:#0a0a0f;
          padding:15px;border-radius:8px;text-align:left;word-break:break-all;
          font-family:monospace}
    </style>
</head>
<body>
    <div class="box">
        <div class="icon">⚠️</div>
        <h1>Error de Base de Datos</h1>
        <small style="color:#666">Periódico Digital Pro v2.0</small>
        <p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>
    </div>
</body>
</html>';
    }
}

// ============================================================
// 6. FUNCIÓN GLOBAL DE ACCESO RÁPIDO A LA BD
// ============================================================
function db(): Database
{
    return Database::getInstance();
}

// ============================================================
// 7. CLASE DE CONFIGURACIÓN DINÁMICA (carga desde BD)
// ============================================================
class Config
{
    /** @var array Cache de configuración */
    private static array $cache = [];
    /** @var bool Si ya fue cargada desde la BD */
    private static bool $loaded = false;

    // ----------------------------------------------------------
    // Cargar toda la configuración de la BD (una sola vez)
    // ----------------------------------------------------------
    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        try {
            $rows = db()->fetchAll("SELECT clave, valor FROM configuracion_global");
            foreach ($rows as $row) {
                self::$cache[$row['clave']] = $row['valor'];
            }
            self::$loaded = true;
        } catch (\Throwable $e) {
            // Si falla (tabla no existe aún), usar valores por defecto
            self::$loaded = true;
        }
    }

    // ----------------------------------------------------------
    // Obtener valor de configuración
    // ----------------------------------------------------------
    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        return self::$cache[$key] ?? $default;
    }

    // ----------------------------------------------------------
    // Obtener valor como booleano
    // ----------------------------------------------------------
    public static function bool(string $key, bool $default = false): bool
    {
        $val = self::get($key);
        if ($val === null) return $default;
        return in_array(strtolower((string)$val), ['1', 'true', 'yes', 'on'], true);
    }

    // ----------------------------------------------------------
    // Obtener valor como entero
    // ----------------------------------------------------------
    public static function int(string $key, int $default = 0): int
    {
        $val = self::get($key);
        return $val !== null ? (int)$val : $default;
    }

    // ----------------------------------------------------------
    // Obtener valor como float
    // ----------------------------------------------------------
    public static function float(string $key, float $default = 0.0): float
    {
        $val = self::get($key);
        return $val !== null ? (float)$val : $default;
    }

    // ----------------------------------------------------------
    // Obtener configuración de un grupo completo
    // ----------------------------------------------------------
    public static function group(string $grupo): array
    {
        self::load();
        $result = [];
        foreach (self::$cache as $key => $val) {
            // Filtrar por prefijo del grupo
            if (str_starts_with($key, $grupo . '_') || $key === $grupo) {
                $result[$key] = $val;
            }
        }
        return $result;
    }

    // ----------------------------------------------------------
    // Establecer/actualizar una configuración
    // ----------------------------------------------------------
    public static function set(string $key, mixed $value): bool
    {
        try {
            db()->execute(
                "UPDATE configuracion_global SET valor = ? WHERE clave = ?",
                [(string)$value, $key]
            );
            self::$cache[$key] = (string)$value;
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ----------------------------------------------------------
    // Actualizar múltiples valores de configuración
    // ----------------------------------------------------------
    public static function setMultiple(array $data): bool
    {
        try {
            db()->beginTransaction();
            foreach ($data as $key => $value) {
                db()->execute(
                    "UPDATE configuracion_global SET valor = ? WHERE clave = ?",
                    [(string)$value, $key]
                );
                self::$cache[$key] = (string)$value;
            }
            db()->commit();
            return true;
        } catch (\Throwable $e) {
            db()->rollback();
            return false;
        }
    }

    // ----------------------------------------------------------
    // Invalidar cache de configuración
    // ----------------------------------------------------------
    public static function reload(): void
    {
        self::$cache  = [];
        self::$loaded = false;
        self::load();
    }

    // ----------------------------------------------------------
    // Obtener todas las configuraciones de un grupo para el panel
    // ----------------------------------------------------------
    public static function getAllByGroup(): array
    {
        try {
            $rows = db()->fetchAll(
                "SELECT id, clave, valor, tipo, grupo, descripcion FROM configuracion_global ORDER BY grupo, id"
            );
            $grouped = [];
            foreach ($rows as $row) {
                $grouped[$row['grupo']][] = $row;
            }
            return $grouped;
        } catch (\Throwable $e) {
            return [];
        }
    }
}

// ============================================================
// 8. CONFIGURACIÓN GLOBAL DE PHP
// ============================================================

// Cargar timezone desde configuración o usar default
$configTz = Config::get('site_timezone', APP_TIMEZONE);
date_default_timezone_set($configTz ?: APP_TIMEZONE);

// Configuración de errores según entorno
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    // Crear directorio de logs si no existe
    if (!is_dir(LOGS_PATH)) {
        @mkdir(LOGS_PATH, 0755, true);
    }
    ini_set('error_log', LOGS_PATH . 'php_errors.log');
}

// Buffer de salida para evitar "headers already sent"
if (ob_get_level() === 0) {
    ob_start();
}

// Límites de subida
ini_set('upload_max_filesize', '10M');
ini_set('post_max_size',       '12M');
ini_set('max_execution_time',  '60');
ini_set('memory_limit',        '256M');

// Crear directorios necesarios si no existen
foreach ([UPLOAD_PATH, AVATAR_PATH, ADS_PATH, VIDEO_THUMB_PATH, LOGS_PATH] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// ============================================================
// 9. MODO MANTENIMIENTO
// ============================================================
if (Config::bool('site_modo_mantenimiento') &&
    !str_contains($_SERVER['REQUEST_URI'] ?? '', '/admin/') &&
    !str_contains($_SERVER['REQUEST_URI'] ?? '', '/login.php')) {
    http_response_code(503);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Mantenimiento — ' . e(Config::get('site_nombre', APP_NAME)) . '</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{background:#0a0a0f;color:#fff;font-family:system-ui,sans-serif;
             display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center;padding:20px}
        h1{font-size:2rem;margin-bottom:10px;color:#e63946}
        p{color:#999;font-size:1rem}
        .icon{font-size:4rem;margin-bottom:20px}
    </style></head><body>
    <div><div class="icon">🔧</div>
    <h1>Sitio en Mantenimiento</h1>
    <p>Estamos mejorando para ofrecerte una mejor experiencia.<br>Volvemos muy pronto.</p></div>
    </body></html>';
    exit;
}

// ============================================================
// 10. FUNCIÓN HELPER DE ESCAPE HTML (global)
// ============================================================
if (!function_exists('e')) {
    function e(mixed $val): string {
        return htmlspecialchars((string)($val ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}