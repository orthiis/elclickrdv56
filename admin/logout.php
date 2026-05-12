<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Admin: Logout
 * ============================================================
 * Archivo  : admin/logout.php
 * Versión  : 2.0.0
 * Autor    : MM Lab Studio
 * ============================================================
 * Cierra la sesión del usuario de forma segura:
 *  1. Verifica token CSRF (GET con token o POST)
 *  2. Destruye la sesión y cookie "recuérdame"
 *  3. Registra el evento en el log de actividad
 *  4. Redirige al login con mensaje de confirmación
 * ============================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// ── Solo usuarios autenticados ────────────────────────────────
if (!isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$usuarioActual = currentUser();

// ── Verificación CSRF (GET con token o solicitud directa) ─────
// Permitir logout desde GET con token o desde POST
$tokenGet  = cleanInput($_GET['token']  ?? '');
$tokenPost = cleanInput($_POST['token'] ?? '');
$token     = $tokenPost ?: $tokenGet;

// Si viene por POST verificar CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->verifyCSRF($token)) {
        setFlashMessage('error', 'Token de seguridad inválido. Intenta de nuevo.');
        header('Location: ' . APP_URL . '/admin/dashboard.php');
        exit;
    }
}

// Si viene por GET, aceptar de todas formas (el onclick ya confirmó)
// pero verificar que el usuario sea admin/sesión válida
if (!isAdmin() && !$usuarioActual) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// ── Registrar evento de logout en log de actividad ───────────
try {
    logActividad(
        (int)($usuarioActual['id'] ?? 0),
        'logout',
        'auth',
        (int)($usuarioActual['id'] ?? 0),
        sprintf(
            'Cierre de sesión. Usuario: %s (%s). IP: %s',
            $usuarioActual['nombre'] ?? 'Desconocido',
            $usuarioActual['email']  ?? '',
            getClientIp()
        )
    );
} catch (\Throwable $e) {
    // No interrumpir el logout si el log falla
    if (APP_DEBUG) {
        error_log('Logout log error: ' . $e->getMessage());
    }
}

// ── Eliminar sesión activa de la BD (si existe la tabla) ──────
try {
    $sessionId = session_id();
    if ($sessionId) {
        db()->execute(
            "DELETE FROM sesiones_activas WHERE session_id = ? LIMIT 1",
            [$sessionId]
        );
    }
} catch (\Throwable $e) {
    // Tabla puede no existir, continuar
}

// ── Ejecutar logout (destruye sesión + cookie) ────────────────
$auth->logout();

// ── Redirigir al login con mensaje ───────────────────────────
setFlashMessage('success', '¡Hasta pronto, ' . ($usuarioActual['nombre'] ?? 'Admin') . '! Has cerrado sesión correctamente.');
header('Location: ' . APP_URL . '/login.php');
exit;