<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Sistema de Autenticación
 * ============================================================
 * Archivo : includes/auth.php
 * Versión : 2.0.0
 * ============================================================
 * RESPONSABILIDADES:
 *  1. Inicialización segura de sesiones
 *  2. Login / Logout / Registro
 *  3. Autenticación de Dos Factores (2FA por código email/SMS)
 *  4. Control de intentos de login (rate limiting por IP)
 *  5. Gestión de roles granular (super_admin, admin, auditor, user)
 *  6. Tokens CSRF
 *  7. Cookies "Recuérdame"
 *  8. Registro de sesiones activas
 *  9. Log de actividad admin
 * 10. Permisos granulares por módulo
 * ============================================================
 */

declare(strict_types=1);

if (!defined('APP_NAME')) {
    require_once dirname(__DIR__) . '/config/database.php';
}

// ============================================================
// INICIALIZACIÓN SEGURA DE SESIONES
// ============================================================

function initSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_name(SESSION_NAME);
        session_start();
    }
}

initSession();

// ============================================================
// CLASE AUTH — Gestión completa de autenticación
// ============================================================

class Auth
{
    private Database $db;

    // Permisos por módulo según rol
    private array $permissions = [
        'super_admin' => [
            'noticias'       => ['crear','editar','eliminar','publicar','ver'],
            'usuarios'       => ['crear','editar','eliminar','ver','cambiar_rol'],
            'comentarios'    => ['aprobar','eliminar','ver'],
            'categorias'     => ['crear','editar','eliminar','ver'],
            'anuncios'       => ['crear','editar','eliminar','ver'],
            'media'          => ['subir','eliminar','ver'],
            'configuracion'  => ['ver','editar'],
            'live_blog'      => ['crear','editar','eliminar','ver'],
            'videos'         => ['crear','editar','eliminar','ver'],
            'estadisticas'   => ['ver'],
            'backup'         => ['crear','restaurar'],
            'logs'           => ['ver','limpiar'],
        ],
        'admin' => [
            'noticias'       => ['crear','editar','eliminar','publicar','ver'],
            'usuarios'       => ['ver','editar'],
            'comentarios'    => ['aprobar','eliminar','ver'],
            'categorias'     => ['ver','editar'],
            'anuncios'       => ['crear','editar','ver'],
            'media'          => ['subir','eliminar','ver'],
            'configuracion'  => ['ver'],
            'live_blog'      => ['crear','editar','ver'],
            'videos'         => ['crear','editar','ver'],
            'estadisticas'   => ['ver'],
            'backup'         => [],
            'logs'           => ['ver'],
        ],
        'auditor' => [
            'noticias'       => ['ver'],
            'usuarios'       => ['ver'],
            'comentarios'    => ['ver'],
            'categorias'     => ['ver'],
            'anuncios'       => ['ver'],
            'media'          => ['ver'],
            'configuracion'  => [],
            'live_blog'      => ['ver'],
            'videos'         => ['ver'],
            'estadisticas'   => ['ver'],
            'backup'         => [],
            'logs'           => ['ver'],
        ],
        'user' => [],
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->procesarCookieRecuerdo();
    }

    // ----------------------------------------------------------
    // LOGIN
    // ----------------------------------------------------------

    public function login(string $email, string $password, bool $remember = false): array
    {
        $email    = trim(strtolower($email));
        $password = trim($password);

        // Validaciones básicas
        if (empty($email) || empty($password)) {
            return ['success' => false, 'message' => 'Por favor completa todos los campos.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'El correo electrónico no es válido.'];
        }

        // Rate limiting por IP
        $ip           = $this->getClientIp();
        $intentosFail = $this->contarIntentosFallidos($ip);
        if ($intentosFail >= RATE_LIMIT_LOGIN) {
            $tiempoRestante = $this->tiempoBloqueoRestante($ip);
            return [
                'success' => false,
                'message' => "Demasiados intentos fallidos. Espera {$tiempoRestante} minutos.",
                'blocked' => true,
            ];
        }

        // Buscar usuario
        $usuario = $this->db->fetchOne(
            "SELECT id, nombre, email, password, rol, activo, avatar,
                    two_factor_activo, premium, verificado, reputacion
             FROM usuarios
             WHERE email = ?
             LIMIT 1",
            [$email]
        );

        if (!$usuario || !password_verify($password, $usuario['password'])) {
            $this->registrarIntentoFallido($ip, $email);
            $restantes = max(0, RATE_LIMIT_LOGIN - $intentosFail - 1);
            return [
                'success'   => false,
                'message'   => 'Credenciales incorrectas.' . ($restantes > 0 ? " Te quedan {$restantes} intentos." : ''),
                'restantes' => $restantes,
            ];
        }

        if (!$usuario['activo']) {
            return ['success' => false, 'message' => 'Tu cuenta está desactivada. Contacta al administrador.'];
        }

        // Verificar si necesita 2FA
        if ($usuario['two_factor_activo']) {
            // Generar código y guardarlo en sesión temporal
            $codigo = $this->generarCodigo2FA((int)$usuario['id']);
            $_SESSION['2fa_pending_user_id'] = (int)$usuario['id'];
            $_SESSION['2fa_pending_email']   = $usuario['email'];
            $_SESSION['2fa_remember']        = $remember;

            return [
                'success'      => true,
                'requires_2fa' => true,
                'message'      => 'Se ha enviado un código de verificación a tu correo.',
                'codigo_debug' => APP_DEBUG ? $codigo : null, // solo en dev
            ];
        }

        // Login directo (sin 2FA)
        return $this->completarLogin($usuario, $remember);
    }

    // ----------------------------------------------------------
    // VERIFICAR CÓDIGO 2FA
    // ----------------------------------------------------------

    public function verificar2FA(string $codigo): array
    {
        $usuarioId = $_SESSION['2fa_pending_user_id'] ?? null;

        if (!$usuarioId) {
            return ['success' => false, 'message' => 'Sesión de verificación expirada.'];
        }

        $registro = $this->db->fetchOne(
            "SELECT id FROM dos_factor_auth
             WHERE usuario_id = ?
               AND codigo     = ?
               AND tipo       = 'login'
               AND usado      = 0
               AND expira     > NOW()
             ORDER BY fecha DESC
             LIMIT 1",
            [$usuarioId, trim($codigo)]
        );

        if (!$registro) {
            return ['success' => false, 'message' => 'Código incorrecto o expirado.'];
        }

        // Marcar código como usado
        $this->db->execute(
            "UPDATE dos_factor_auth SET usado = 1 WHERE id = ?",
            [$registro['id']]
        );

        // Obtener usuario completo
        $usuario = $this->db->fetchOne(
            "SELECT id, nombre, email, password, rol, activo, avatar,
                    two_factor_activo, premium, verificado, reputacion
             FROM usuarios WHERE id = ? LIMIT 1",
            [$usuarioId]
        );

        $remember = $_SESSION['2fa_remember'] ?? false;
        unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_email'], $_SESSION['2fa_remember']);

        return $this->completarLogin($usuario, $remember);
    }

    // ----------------------------------------------------------
    // COMPLETAR LOGIN (crear sesión + registrar)
    // ----------------------------------------------------------

    private function completarLogin(array $usuario, bool $remember): array
    {
        // Regenerar ID de sesión para prevenir session fixation
        session_regenerate_id(true);

        // Guardar en sesión
        $_SESSION['user_id']     = (int)$usuario['id'];
        $_SESSION['user_nombre'] = $usuario['nombre'];
        $_SESSION['user_email']  = $usuario['email'];
        $_SESSION['user_rol']    = $usuario['rol'];
        $_SESSION['user_avatar'] = $usuario['avatar'];
        $_SESSION['user_premium']= (bool)$usuario['premium'];
        $_SESSION['login_time']  = time();
        $_SESSION['last_activity']= time();
        $_SESSION['csrf_token']  = bin2hex(random_bytes(32));

        // Actualizar último acceso
        $this->db->execute(
            "UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?",
            [$usuario['id']]
        );

        // Cookie recuérdame
        if ($remember) {
            $this->crearCookieRecuerdo((int)$usuario['id']);
        }

        // Registrar sesión activa
        $this->registrarSesionActiva((int)$usuario['id']);

        // Limpiar intentos fallidos de esta IP
        $this->limpiarIntentosFallidos($this->getClientIp());

        // Determinar redirect según rol
        $redirect = match($usuario['rol']) {
            'super_admin', 'admin', 'auditor' => APP_URL . '/admin/dashboard.php',
            default                            => APP_URL . '/index.php',
        };

        return [
            'success'  => true,
            'message'  => '¡Bienvenido, ' . $usuario['nombre'] . '!',
            'redirect' => $redirect,
            'usuario'  => [
                'id'     => $usuario['id'],
                'nombre' => $usuario['nombre'],
                'rol'    => $usuario['rol'],
            ],
        ];
    }

    // ----------------------------------------------------------
    // REGISTRO DE NUEVO USUARIO
    // ----------------------------------------------------------

    public function register(array $data): array
    {
        $nombre   = trim($data['nombre']   ?? '');
        $email    = trim(strtolower($data['email'] ?? ''));
        $password = $data['password']      ?? '';
        $confirm  = $data['password_confirm'] ?? '';

        // Validaciones
        $errors = [];

        if (empty($nombre) || mb_strlen($nombre) < 2) {
            $errors[] = 'El nombre debe tener al menos 2 caracteres.';
        }
        if (mb_strlen($nombre) > 100) {
            $errors[] = 'El nombre no puede exceder 100 caracteres.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'El correo electrónico no es válido.';
        }
        if (mb_strlen($password) < 8) {
            $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'La contraseña debe contener al menos una letra mayúscula.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'La contraseña debe contener al menos un número.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Las contraseñas no coinciden.';
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors, 'message' => implode(' ', $errors)];
        }

        // Verificar si email ya existe
        $existe = $this->db->count(
            "SELECT COUNT(*) FROM usuarios WHERE email = ?",
            [$email]
        );
        if ($existe > 0) {
            return ['success' => false, 'message' => 'Este correo electrónico ya está registrado.'];
        }

        // Crear usuario
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $id   = $this->db->insert(
                "INSERT INTO usuarios (nombre, email, password, rol, activo, fecha_registro)
                 VALUES (?, ?, ?, 'user', 1, NOW())",
                [$nombre, $email, $hash]
            );

            // Login automático tras registro
            $usuario = $this->db->fetchOne(
                "SELECT id, nombre, email, password, rol, activo, avatar,
                        two_factor_activo, premium, verificado, reputacion
                 FROM usuarios WHERE id = ? LIMIT 1",
                [$id]
            );

            return $this->completarLogin($usuario, false);

        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Error al crear la cuenta. Intenta de nuevo.'];
        }
    }

    // ----------------------------------------------------------
    // LOGOUT
    // ----------------------------------------------------------

    public function logout(): void
    {
        // Registrar cierre de sesión activa
        if (isset($_SESSION['user_id'])) {
            $this->cerrarSesionActiva(session_id());
        }

        // Destruir sesión
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();

        // Eliminar cookie recuérdame
        if (isset($_COOKIE[REMEMBER_COOKIE])) {
            $this->eliminarCookieRecuerdo($_COOKIE[REMEMBER_COOKIE]);
            setcookie(REMEMBER_COOKIE, '', time() - 3600, '/', '', false, true);
        }
    }

    // ----------------------------------------------------------
    // VERIFICAR SI ESTÁ LOGUEADO
    // ----------------------------------------------------------

    public function isLoggedIn(): bool
    {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }

        // Verificar timeout de inactividad (2 horas)
        $inactivityLimit = 7200;
        if (isset($_SESSION['last_activity']) &&
            (time() - $_SESSION['last_activity']) > $inactivityLimit) {
            $this->logout();
            return false;
        }

        $_SESSION['last_activity'] = time();
        return true;
    }

    // ----------------------------------------------------------
    // OBTENER USUARIO ACTUAL
    // ----------------------------------------------------------

    public function currentUser(): ?array
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        return [
            'id'      => $_SESSION['user_id'],
            'nombre'  => $_SESSION['user_nombre'],
            'email'   => $_SESSION['user_email'],
            'rol'     => $_SESSION['user_rol'],
            'avatar'  => $_SESSION['user_avatar'],
            'premium' => $_SESSION['user_premium'] ?? false,
        ];
    }

    // ----------------------------------------------------------
    // OBTENER USUARIO COMPLETO DESDE BD (fresco)
    // ----------------------------------------------------------

    public function currentUserFull(): ?array
    {
        if (!$this->isLoggedIn()) {
            return null;
        }
        return $this->db->fetchOne(
            "SELECT u.*,
                    (SELECT COUNT(*) FROM comentarios WHERE usuario_id = u.id AND aprobado = 1) AS total_comentarios,
                    (SELECT COUNT(*) FROM favoritos   WHERE usuario_id = u.id)                  AS total_favoritos,
                    (SELECT COUNT(*) FROM noticias    WHERE autor_id   = u.id AND estado = 'publicado') AS total_noticias
             FROM usuarios u
             WHERE u.id = ? LIMIT 1",
            [$_SESSION['user_id']]
        );
    }

    // ----------------------------------------------------------
    // VERIFICACIÓN DE ROLES
    // ----------------------------------------------------------

    public function isAdmin(): bool
    {
        return $this->hasRole('super_admin', 'admin');
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function isAuditor(): bool
    {
        return $this->hasRole('super_admin', 'admin', 'auditor');
    }

    public function hasRole(string ...$roles): bool
    {
        if (!$this->isLoggedIn()) return false;
        return in_array($_SESSION['user_rol'] ?? '', $roles, true);
    }

    // ----------------------------------------------------------
    // VERIFICAR PERMISO GRANULAR
    // ----------------------------------------------------------

    public function can(string $modulo, string $accion): bool
    {
        if (!$this->isLoggedIn()) return false;

        $rol = $_SESSION['user_rol'] ?? 'user';

        // Super admin puede todo
        if ($rol === 'super_admin') return true;

        return in_array($accion, $this->permissions[$rol][$modulo] ?? [], true);
    }

    // ----------------------------------------------------------
    // REQUERIR AUTENTICACIÓN (redirect si no)
    // ----------------------------------------------------------

    public function requireLogin(): void
    {
        if (!$this->isLoggedIn()) {
            $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
            header('Location: ' . APP_URL . '/login.php?redirect=' . $redirect);
            exit;
        }
    }

    public function requireAdmin(): void
    {
        $this->requireLogin();
        if (!$this->isAdmin() && !$this->isAuditor()) {
            header('Location: ' . APP_URL . '/index.php?error=acceso_denegado');
            exit;
        }
    }

    public function requireSuperAdmin(): void
    {
        $this->requireLogin();
        if (!$this->isSuperAdmin()) {
            header('Location: ' . APP_URL . '/admin/dashboard.php?error=sin_permiso');
            exit;
        }
    }

    public function requirePermission(string $modulo, string $accion): void
    {
        $this->requireLogin();
        if (!$this->can($modulo, $accion)) {
            if (isAjax()) {
                jsonResponse(['success' => false, 'message' => 'Sin permiso para esta acción.'], 403);
            }
            header('Location: ' . APP_URL . '/admin/dashboard.php?error=sin_permiso');
            exit;
        }
    }

    // ----------------------------------------------------------
    // CSRF TOKEN
    // ----------------------------------------------------------

    public function getCSRF(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public function verifyCSRF(string $token): bool
    {
        $stored = $_SESSION['csrf_token'] ?? '';
        if (empty($stored) || empty($token)) return false;
        return hash_equals($stored, $token);
    }

    public function regenerateCSRF(): string
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }

    // ----------------------------------------------------------
    // RECUPERACIÓN DE CONTRASEÑA
    // ----------------------------------------------------------

    public function solicitarRecuperacion(string $email): array
    {
        $email   = trim(strtolower($email));
        $usuario = $this->db->fetchOne(
            "SELECT id, nombre, email FROM usuarios WHERE email = ? AND activo = 1 LIMIT 1",
            [$email]
        );

        // Siempre responder igual para no revelar si el email existe
        $mensajeGenerico = 'Si ese correo está registrado, recibirás un enlace en breve.';

        if (!$usuario) {
            return ['success' => true, 'message' => $mensajeGenerico];
        }

        // Generar token único
        $token   = bin2hex(random_bytes(32));
        $expira  = date('Y-m-d H:i:s', time() + 3600); // 1 hora

        // Guardar como código 2FA tipo cambio_password
        $this->db->execute(
            "DELETE FROM dos_factor_auth
             WHERE usuario_id = ? AND tipo = 'cambio_password'",
            [$usuario['id']]
        );
        $this->db->insert(
            "INSERT INTO dos_factor_auth (usuario_id, codigo, tipo, expira)
             VALUES (?, ?, 'cambio_password', ?)",
            [$usuario['id'], $token, $expira]
        );

        // En producción: enviar email con el link
        // $link = APP_URL . '/login.php?action=reset&token=' . $token;
        // sendEmail($usuario['email'], 'Recuperar contraseña', renderEmailRecuperar($link));

        if (APP_DEBUG) {
            return ['success' => true, 'message' => $mensajeGenerico, 'token_debug' => $token];
        }

        return ['success' => true, 'message' => $mensajeGenerico];
    }

    public function resetPassword(string $token, string $nuevaPassword): array
    {
        if (mb_strlen($nuevaPassword) < 8) {
            return ['success' => false, 'message' => 'La contraseña debe tener al menos 8 caracteres.'];
        }

        $registro = $this->db->fetchOne(
            "SELECT id, usuario_id FROM dos_factor_auth
             WHERE codigo = ?
               AND tipo   = 'cambio_password'
               AND usado  = 0
               AND expira > NOW()
             LIMIT 1",
            [$token]
        );

        if (!$registro) {
            return ['success' => false, 'message' => 'El enlace es inválido o ha expirado.'];
        }

        $hash = password_hash($nuevaPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        $this->db->execute(
            "UPDATE usuarios SET password = ? WHERE id = ?",
            [$hash, $registro['usuario_id']]
        );

        $this->db->execute(
            "UPDATE dos_factor_auth SET usado = 1 WHERE id = ?",
            [$registro['id']]
        );

        // Cerrar todas las sesiones activas del usuario por seguridad
        $this->db->execute(
            "UPDATE sesiones_activas SET activa = 0 WHERE usuario_id = ?",
            [$registro['usuario_id']]
        );

        return ['success' => true, 'message' => 'Contraseña actualizada correctamente. Ya puedes iniciar sesión.'];
    }

    // ----------------------------------------------------------
    // CAMBIAR CONTRASEÑA (usuario logueado)
    // ----------------------------------------------------------

    public function cambiarPassword(int $usuarioId, string $actual, string $nueva, string $confirmar): array
    {
        if ($nueva !== $confirmar) {
            return ['success' => false, 'message' => 'Las contraseñas nuevas no coinciden.'];
        }

        if (mb_strlen($nueva) < 8) {
            return ['success' => false, 'message' => 'La contraseña debe tener al menos 8 caracteres.'];
        }

        $usuario = $this->db->fetchOne(
            "SELECT id, password FROM usuarios WHERE id = ? LIMIT 1",
            [$usuarioId]
        );

        if (!$usuario || !password_verify($actual, $usuario['password'])) {
            return ['success' => false, 'message' => 'La contraseña actual es incorrecta.'];
        }

        $hash = password_hash($nueva, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->db->execute(
            "UPDATE usuarios SET password = ? WHERE id = ?",
            [$hash, $usuarioId]
        );

        $this->logActividad($usuarioId, 'cambio_password', 'usuarios', $usuarioId, 'Usuario cambió su contraseña');

        return ['success' => true, 'message' => 'Contraseña actualizada correctamente.'];
    }

    // ----------------------------------------------------------
    // 2FA — Generar y guardar código
    // ----------------------------------------------------------

    public function generarCodigo2FA(int $usuarioId, string $tipo = 'login'): string
    {
        // Eliminar códigos anteriores del mismo tipo
        $this->db->execute(
            "DELETE FROM dos_factor_auth WHERE usuario_id = ? AND tipo = ?",
            [$usuarioId, $tipo]
        );

        $codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expira = date('Y-m-d H:i:s', time() + 600); // 10 minutos

        $this->db->insert(
            "INSERT INTO dos_factor_auth (usuario_id, codigo, tipo, expira)
             VALUES (?, ?, ?, ?)",
            [$usuarioId, $codigo, $tipo, $expira]
        );

        return $codigo;
    }

    // ----------------------------------------------------------
    // 2FA — Activar/Desactivar para usuario
    // ----------------------------------------------------------

    public function toggle2FA(int $usuarioId, bool $activar): bool
    {
        $affected = $this->db->execute(
            "UPDATE usuarios SET two_factor_activo = ? WHERE id = ?",
            [(int)$activar, $usuarioId]
        );

        if ($affected) {
            $this->logActividad(
                $usuarioId, 'toggle_2fa', 'usuarios', $usuarioId,
                $activar ? 'Activó 2FA' : 'Desactivó 2FA'
            );
        }

        return $affected > 0;
    }

    // ----------------------------------------------------------
    // SESIONES ACTIVAS — Registrar
    // ----------------------------------------------------------

    public function registrarSesionActiva(int $usuarioId): void
    {
        try {
            $ip         = $this->getClientIp();
            $userAgent  = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $dispositivo = $this->detectarDispositivo($userAgent);
            $sessionId  = session_id();

            // Upsert en sesiones activas
            $this->db->execute(
                "INSERT INTO sesiones_activas
                    (usuario_id, session_id, ip, user_agent, dispositivo, activa)
                 VALUES (?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE
                    ip = VALUES(ip),
                    user_agent = VALUES(user_agent),
                    dispositivo = VALUES(dispositivo),
                    ultimo_acceso = NOW(),
                    activa = 1",
                [$usuarioId, $sessionId, $ip, mb_substr($userAgent, 0, 500), $dispositivo]
            );

            // Limpiar sesiones viejas (más de 30 días inactivas)
            $this->db->execute(
                "DELETE FROM sesiones_activas
                 WHERE usuario_id = ?
                   AND ultimo_acceso < NOW() - INTERVAL 30 DAY",
                [$usuarioId]
            );
        } catch (\Throwable $e) {
            // No crítico
        }
    }

    private function cerrarSesionActiva(string $sessionId): void
    {
        try {
            $this->db->execute(
                "UPDATE sesiones_activas SET activa = 0 WHERE session_id = ?",
                [$sessionId]
            );
        } catch (\Throwable $e) {}
    }

    // ----------------------------------------------------------
    // CERRAR SESIÓN REMOTA (desde panel)
    // ----------------------------------------------------------

    public function cerrarSesionRemota(int $usuarioId, int $sessionDbId): bool
    {
        $sesion = $this->db->fetchOne(
            "SELECT session_id FROM sesiones_activas
             WHERE id = ? AND usuario_id = ?",
            [$sessionDbId, $usuarioId]
        );

        if (!$sesion) return false;

        $this->db->execute(
            "UPDATE sesiones_activas SET activa = 0 WHERE id = ?",
            [$sessionDbId]
        );

        return true;
    }

    public function cerrarTodasLasSesiones(int $usuarioId): void
    {
        $this->db->execute(
            "UPDATE sesiones_activas SET activa = 0 WHERE usuario_id = ?",
            [$usuarioId]
        );
    }

    // ----------------------------------------------------------
    // OBTENER SESIONES ACTIVAS DE UN USUARIO
    // ----------------------------------------------------------

    public function getSesionesActivas(int $usuarioId): array
    {
        return $this->db->fetchAll(
            "SELECT id, ip, user_agent, dispositivo, pais,
                    ultimo_acceso, fecha_inicio, activa,
                    (session_id = ?) AS es_actual
             FROM sesiones_activas
             WHERE usuario_id = ? AND activa = 1
             ORDER BY ultimo_acceso DESC",
            [session_id(), $usuarioId]
        );
    }

    // ----------------------------------------------------------
    // LOG DE ACTIVIDAD ADMIN
    // ----------------------------------------------------------

    public function logActividad(
        int $usuarioId,
        string $accion,
        string $modulo,
        ?int $itemId = null,
        ?string $descripcion = null
    ): void {
        try {
            $ip        = $this->getClientIp();
            $userAgent = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

            $this->db->insert(
                "INSERT INTO actividad_admin
                    (usuario_id, accion, modulo, item_id, descripcion, ip, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$usuarioId, $accion, $modulo, $itemId, $descripcion, $ip, $userAgent]
            );
        } catch (\Throwable $e) {
            // No crítico
        }
    }

    // ----------------------------------------------------------
    // COOKIE "RECUÉRDAME"
    // ----------------------------------------------------------

    private function crearCookieRecuerdo(int $usuarioId): void
    {
        $token    = bin2hex(random_bytes(32));
        $hash     = hash('sha256', $token);
        $expira   = time() + SESSION_LIFETIME;
        $valorCookie = $usuarioId . ':' . $token;

        // Guardar hash en BD como sesión activa con token especial
        // (usamos el campo session_id para guardar el hash del token)
        $this->db->execute(
            "INSERT INTO sesiones_activas
                (usuario_id, session_id, ip, dispositivo, activa)
             VALUES (?, ?, ?, 'desktop', 1)
             ON DUPLICATE KEY UPDATE
                activa = 1, ultimo_acceso = NOW()",
            [$usuarioId, 'remember_' . $hash, $this->getClientIp()]
        );

        setcookie(
            REMEMBER_COOKIE,
            base64_encode($valorCookie),
            [
                'expires'  => $expira,
                'path'     => '/',
                'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'httponly' => true,
                'samesite' => 'Strict',
            ]
        );
    }

    private function procesarCookieRecuerdo(): void
    {
        if ($this->isLoggedIn()) return;
        if (empty($_COOKIE[REMEMBER_COOKIE])) return;

        $valorRaw = base64_decode($_COOKIE[REMEMBER_COOKIE]);
        if (!$valorRaw || !str_contains($valorRaw, ':')) return;

        [$usuarioId, $token] = explode(':', $valorRaw, 2);
        $usuarioId = (int)$usuarioId;

        if ($usuarioId <= 0 || empty($token)) return;

        $hash    = hash('sha256', $token);
        $sesion  = $this->db->fetchOne(
            "SELECT id FROM sesiones_activas
             WHERE usuario_id   = ?
               AND session_id   = ?
               AND activa       = 1
               AND ultimo_acceso > NOW() - INTERVAL 30 DAY
             LIMIT 1",
            [$usuarioId, 'remember_' . $hash]
        );

        if (!$sesion) return;

        $usuario = $this->db->fetchOne(
            "SELECT id, nombre, email, password, rol, activo, avatar,
                    two_factor_activo, premium, verificado, reputacion
             FROM usuarios
             WHERE id = ? AND activo = 1
             LIMIT 1",
            [$usuarioId]
        );

        if (!$usuario) return;

        $this->completarLogin($usuario, true);
    }

    private function eliminarCookieRecuerdo(string $cookieValor): void
    {
        $valorRaw = base64_decode($cookieValor);
        if (!$valorRaw || !str_contains($valorRaw, ':')) return;

        [$usuarioId, $token] = explode(':', $valorRaw, 2);
        $hash = hash('sha256', $token);

        $this->db->execute(
            "UPDATE sesiones_activas SET activa = 0
             WHERE usuario_id = ? AND session_id = ?",
            [(int)$usuarioId, 'remember_' . $hash]
        );
    }

    // ----------------------------------------------------------
    // RATE LIMITING — Intentos de login
    // ----------------------------------------------------------

    private function contarIntentosFallidos(string $ip): int
    {
        // Usamos el log de actividad admin (tabla actividad_admin)
        // para usuarios no logueados usamos la tabla actividad con usuario_id = 0
        // Más simple: guardamos en sesión temporal
        $key = 'login_fails_' . md5($ip);
        $data = $_SESSION[$key] ?? ['count' => 0, 'first' => time()];

        // Resetear si pasó la ventana de tiempo
        if ((time() - ($data['first'] ?? time())) > RATE_LIMIT_WINDOW) {
            unset($_SESSION[$key]);
            return 0;
        }

        return (int)($data['count'] ?? 0);
    }

    private function registrarIntentoFallido(string $ip, string $email): void
    {
        $key  = 'login_fails_' . md5($ip);
        $data = $_SESSION[$key] ?? ['count' => 0, 'first' => time()];
        $data['count']++;
        $_SESSION[$key] = $data;

        error_log(sprintf(
            '[AUTH] Login fallido: email=%s | IP=%s | intento=%d | %s',
            $email, $ip, $data['count'], date('Y-m-d H:i:s')
        ));
    }

    private function limpiarIntentosFallidos(string $ip): void
    {
        $key = 'login_fails_' . md5($ip);
        unset($_SESSION[$key]);
    }

    private function tiempoBloqueoRestante(string $ip): int
    {
        $key  = 'login_fails_' . md5($ip);
        $data = $_SESSION[$key] ?? ['first' => time()];
        $elapsed = time() - ($data['first'] ?? time());
        return (int)ceil((RATE_LIMIT_WINDOW - $elapsed) / 60);
    }

    // ----------------------------------------------------------
    // UTILIDADES PRIVADAS
    // ----------------------------------------------------------

    private function detectarDispositivo(string $userAgent): string
    {
        $ua = strtolower($userAgent);
        if (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) {
            return 'tablet';
        }
        if (str_contains($ua, 'mobile') || str_contains($ua, 'android') ||
            str_contains($ua, 'iphone')) {
            return 'mobile';
        }
        return 'desktop';
    }

    private function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',   // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// ============================================================
// INSTANCIA GLOBAL DE AUTH
// ============================================================

$auth = new Auth();

// ============================================================
// HELPERS GLOBALES
// ============================================================

function isLoggedIn(): bool
{
    global $auth;
    return $auth->isLoggedIn();
}

function currentUser(): ?array
{
    global $auth;
    return $auth->currentUser();
}

function currentUserFull(): ?array
{
    global $auth;
    return $auth->currentUserFull();
}

function isAdmin(): bool
{
    global $auth;
    return $auth->isAdmin();
}

function isSuperAdmin(): bool
{
    global $auth;
    return $auth->isSuperAdmin();
}

function isAuditor(): bool
{
    global $auth;
    return $auth->isAuditor();
}

function hasRole(string ...$roles): bool
{
    global $auth;
    return $auth->hasRole(...$roles);
}

function can(string $modulo, string $accion): bool
{
    global $auth;
    return $auth->can($modulo, $accion);
}

function csrfToken(): string
{
    global $auth;
    return $auth->getCSRF();
}

function csrfField(): string
{
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . csrfToken() . '">';
}

function isPremium(): bool
{
    $u = currentUser();
    return $u ? (bool)($u['premium'] ?? false) : false;
}

function logActividad(
    int $usuarioId,
    string $accion,
    string $modulo,
    ?int $itemId = null,
    ?string $desc = null
): void {
    global $auth;
    $auth->logActividad($usuarioId, $accion, $modulo, $itemId, $desc);
}

// ============================================================
// FUNCIONES DE RESPUESTA AJAX Y FLASH (necesarias para auth)
// ============================================================

function isAjax(): bool
{
    return (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
         strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        (isset($_SERVER['HTTP_ACCEPT']) &&
         str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
        (isset($_SERVER['CONTENT_TYPE']) &&
         str_contains($_SERVER['CONTENT_TYPE'], 'application/json'))
    );
}

function jsonResponse(array $data, int $statusCode = 200): never
{
    if (ob_get_level() > 0) ob_end_clean();
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function setFlashMessage(string $type, string $message): void
{
    $_SESSION['flash_type']    = $type;
    $_SESSION['flash_message'] = $message;
}

function getFlashMessage(): ?array
{
    if (!isset($_SESSION['flash_message'])) return null;
    $flash = [
        'type'    => $_SESSION['flash_type']    ?? 'info',
        'message' => $_SESSION['flash_message'] ?? '',
    ];
    unset($_SESSION['flash_type'], $_SESSION['flash_message']);
    return $flash;
}

function redirectWithMessage(string $url, string $type, string $message): never
{
    setFlashMessage($type, $message);
    header('Location: ' . $url);
    exit;
}