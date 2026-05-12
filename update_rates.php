<?php
/**
 * ============================================================
 * EL CLICK RD — Actualizador de Tasas de Cambio
 * ============================================================
 * Archivo  : update_rates.php
 * Ubicación: /home/lnuazoql/elclickrd.com/update_rates.php
 * ============================================================
 * Cron Jobs (ejecutar 2 veces al día):
 *   0 0,12 * * * /usr/local/bin/php /home/lnuazoql/elclickrd.com/update_rates.php
 *
 * API usada: https://app.exchangerate-api.com/
 * Plan: Free (1,500 req/mes — 2 req/día = 60 req/mes ✓)
 *
 * Monedas:
 *   USD → DOP  (Dólar estadounidense a Peso Dominicano)
 *   EUR → DOP  (Euro a Peso Dominicano)
 * ============================================================
 */

declare(strict_types=1);

// ── Tiempo máximo de ejecución para CLI ──────────────────────
set_time_limit(60);
ini_set('memory_limit', '64M');

// ── Determinar si corre por CLI o HTTP ───────────────────────
$esCLI = (php_sapi_name() === 'cli');

// Solo permitir por CLI o IP local en HTTP
if (!$esCLI) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1', 'localhost'], true)) {
        http_response_code(403);
        die("Acceso denegado. Este script solo se ejecuta vía Cron Job.\n");
    }
}

// ── Log helper ───────────────────────────────────────────────
function logMsg(string $msg, string $tipo = 'INFO'): void
{
    $linea = '[' . date('Y-m-d H:i:s') . '] [' . $tipo . '] ' . $msg . PHP_EOL;
    $logPath = __DIR__ . '/logs/update_rates.log';

    // Crear carpeta si no existe
    if (!is_dir(dirname($logPath))) {
        @mkdir(dirname($logPath), 0755, true);
    }

    // Rotar log si supera 500KB
    if (file_exists($logPath) && filesize($logPath) > 512000) {
        @rename($logPath, $logPath . '.bak');
    }

    @file_put_contents($logPath, $linea, FILE_APPEND | LOCK_EX);

    if (php_sapi_name() === 'cli') {
        echo $linea;
    }
}

logMsg('════════════════════════════════════════════');
logMsg('Iniciando actualización de tasas de cambio');

// ── Cargar base de datos ──────────────────────────────────────
$configFile = __DIR__ . '/config/database.php';
if (!file_exists($configFile)) {
    logMsg('ERROR: No se encontró config/database.php', 'ERROR');
    exit(1);
}

require_once $configFile;

// ── Obtener API Key ───────────────────────────────────────────
// Prioridad 1: constante en database.php
// Prioridad 2: configuracion_global en BD
$apiKey = '';

if (defined('EXCHANGE_API_KEY') && !empty(EXCHANGE_API_KEY)) {
    $apiKey = trim(EXCHANGE_API_KEY);
    logMsg("API Key cargada desde constante EXCHANGE_API_KEY");
} else {
    try {
        $row = db()->fetchOne(
            "SELECT valor FROM configuracion_global WHERE clave = 'exchange_api_key' LIMIT 1"
        );
        $apiKey = trim($row['valor'] ?? '');
        logMsg("API Key cargada desde configuracion_global BD");
    } catch (\Throwable $e) {
        logMsg("No se pudo leer API Key de BD: " . $e->getMessage(), 'WARN');
    }
}

if (empty($apiKey)) {
    logMsg('ERROR: No hay API Key configurada. Define EXCHANGE_API_KEY en database.php o en la BD.', 'ERROR');
    logMsg('Agrega en config/database.php: define("EXCHANGE_API_KEY", "tu_api_key");');
    exit(1);
}

// ============================================================
// FUNCIÓN: Llamar a ExchangeRate-API
// ============================================================
/**
 * Obtiene todas las tasas de cambio para una moneda base
 * desde la API de ExchangeRate-API.com
 *
 * Endpoint gratuito: https://v6.exchangerate-api.com/v6/{API_KEY}/latest/{BASE}
 *
 * @param string $apiKey  Tu API Key de ExchangeRate-API
 * @param string $base    Moneda base (USD, EUR, etc.)
 * @return array|null     Array con ['result', 'conversion_rates', etc.] o null si falla
 */
function fetchExchangeRates(string $apiKey, string $base): ?array
{
    $url = "https://v6.exchangerate-api.com/v6/{$apiKey}/latest/{$base}";

    $ctx = stream_context_create([
        'http' => [
            'timeout'        => 15,
            'ignore_errors'  => true,
            'method'         => 'GET',
            'header'         => [
                'User-Agent: ElClickRD/2.0 PHP/' . PHP_VERSION,
                'Accept: application/json',
            ],
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $json = @file_get_contents($url, false, $ctx);

    if ($json === false) {
        logMsg("Error: No se pudo conectar con la API para base=$base", 'ERROR');
        return null;
    }

    $data = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        logMsg("Error: Respuesta JSON inválida de la API", 'ERROR');
        return null;
    }

    return $data;
}

// ============================================================
// FUNCIÓN: Guardar tasa en BD
// ============================================================
/**
 * Inserta o actualiza una clave en configuracion_global
 */
function saveTasa(string $clave, string $valor, string $descripcion): bool
{
    try {
        // Verificar si ya existe
        $existe = db()->fetchOne(
            "SELECT id FROM configuracion_global WHERE clave = ? LIMIT 1",
            [$clave]
        );

        if ($existe) {
            db()->execute(
                "UPDATE configuracion_global SET valor = ?, fecha_update = NOW() WHERE clave = ?",
                [$valor, $clave]
            );
        } else {
            db()->execute(
                "INSERT INTO configuracion_global (clave, valor, tipo, grupo, descripcion, fecha_update)
                 VALUES (?, ?, 'numero', 'tasas', ?, NOW())",
                [$clave, $valor, $descripcion]
            );
        }
        return true;
    } catch (\Throwable $e) {
        logMsg("Error BD al guardar {$clave}: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

// ============================================================
// PASO 1: Obtener tasas desde USD (base USD → DOP)
// ============================================================
logMsg("Solicitando tasas base USD a ExchangeRate-API...");

$dataUSD = fetchExchangeRates($apiKey, 'USD');

if (!$dataUSD) {
    logMsg("FALLÓ la petición para USD. Abortando.", 'ERROR');
    exit(1);
}

// Verificar respuesta OK
if (($dataUSD['result'] ?? '') !== 'success') {
    $errorType = $dataUSD['error-type'] ?? 'unknown';
    logMsg("API respondió con error para USD: {$errorType}", 'ERROR');
    logMsg("Respuesta completa: " . json_encode($dataUSD), 'DEBUG');
    exit(1);
}

// Extraer tasas
$rates = $dataUSD['conversion_rates'] ?? [];

if (empty($rates)) {
    logMsg("No se recibieron tasas de conversión en la respuesta", 'ERROR');
    exit(1);
}

// USD → DOP
$usdDOP = $rates['DOP'] ?? null;
if ($usdDOP === null) {
    logMsg("No se encontró la tasa USD→DOP en la respuesta", 'ERROR');
    exit(1);
}
$usdDOP = round((float)$usdDOP, 4);

// EUR → DOP (USD→EUR de la misma respuesta, luego calculamos)
// Método: USD→EUR y USD→DOP → EUR→DOP = DOP/EUR
$usdEUR = $rates['EUR'] ?? null;
$eurDOP = null;

if ($usdEUR !== null && $usdEUR > 0) {
    // EUR→DOP = USD→DOP / USD→EUR
    $eurDOP = round($usdDOP / (float)$usdEUR, 4);
    logMsg("EUR→DOP calculado desde ratios: {$eurDOP}");
}

logMsg("Tasa obtenida: 1 USD = {$usdDOP} DOP");

// ============================================================
// PASO 2: Obtener EUR→DOP directamente (verificación)
// ============================================================
logMsg("Solicitando tasas base EUR a ExchangeRate-API...");

$dataEUR = fetchExchangeRates($apiKey, 'EUR');

if ($dataEUR && ($dataEUR['result'] ?? '') === 'success') {
    $ratesEUR = $dataEUR['conversion_rates'] ?? [];
    $eurDOPdirecto = $ratesEUR['DOP'] ?? null;

    if ($eurDOPdirecto !== null) {
        $eurDOP = round((float)$eurDOPdirecto, 4);
        logMsg("EUR→DOP directo desde API: {$eurDOP}");
    }
} else {
    logMsg("No se pudo obtener base EUR directamente. Usando cálculo derivado.", 'WARN');
}

// Si aún no tenemos EUR→DOP, abortar
if ($eurDOP === null) {
    logMsg("ERROR: No se pudo calcular EUR→DOP. Abortando.", 'ERROR');
    exit(1);
}

logMsg("Tasas finales: 1 USD = {$usdDOP} DOP | 1 EUR = {$eurDOP} DOP");

// ============================================================
// PASO 3: Guardar en base de datos
// ============================================================
logMsg("Guardando tasas en base de datos...");

$okUSD = saveTasa(
    'tasa_usd_dop',
    (string)$usdDOP,
    'Tasa de cambio USD a DOP (Peso Dominicano)'
);

$okEUR = saveTasa(
    'tasa_eur_dop',
    (string)$eurDOP,
    'Tasa de cambio EUR a DOP (Peso Dominicano)'
);

// Guardar timestamp de última actualización
saveTasa(
    'tasa_ultima_actualizacion',
    date('Y-m-d H:i:s'),
    'Última actualización automática de tasas de cambio'
);

// Guardar también la tasa USD→EUR por si se necesita
$usdEURfinal = $rates['EUR'] ?? 0;
saveTasa(
    'tasa_usd_eur',
    (string)round((float)$usdEURfinal, 6),
    'Tasa de cambio USD a EUR'
);

// ============================================================
// PASO 4: Informe final
// ============================================================
if ($okUSD && $okEUR) {
    logMsg("✅ Tasas guardadas correctamente:");
    logMsg("   1 USD = {$usdDOP} DOP");
    logMsg("   1 EUR = {$eurDOP} DOP");
    logMsg("   Fuente: ExchangeRate-API.com");
    logMsg("   Próxima actualización: " . date('Y-m-d H:i:s', strtotime('+12 hours')));
    exit(0);
} else {
    logMsg("⚠️  Tasas obtenidas pero hubo problemas al guardar en BD.", 'WARN');
    exit(1);
}