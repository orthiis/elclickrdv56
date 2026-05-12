<?php
/**
 * ============================================================
 * PERIÓDICO DIGITAL PRO v2.0 — Login / Registro / 2FA
 * ============================================================
 * Archivo : login.php
 * Versión : 2.0.0
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// ── Si ya está logueado, redirigir ────────────────────────────
if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

// ── Parámetros ────────────────────────────────────────────────
$action   = cleanInput($_GET['action']   ?? 'login');
$redirect = cleanInput($_GET['redirect'] ?? '');
$token    = cleanInput($_GET['token']    ?? '');

// Validar redirect para evitar open redirect
if (!empty($redirect) && !str_starts_with($redirect, '/')) {
    $redirect = '';
}

// ── PROCESAR FORMULARIOS POST ─────────────────────────────────
$errors     = [];
$successMsg = '';
$formData   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verificar CSRF
    if (!$auth->verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de seguridad inválido. Recarga la página.';
    } else {

        $postAction = cleanInput($_POST['action'] ?? 'login');

        // ── LOGIN ─────────────────────────────────────────────
        if ($postAction === 'login') {
            $email    = cleanInput($_POST['email']    ?? '');
            $password = $_POST['password']            ?? '';
            $remember = isset($_POST['remember']);

            $result = $auth->login($email, $password, $remember);

            if ($result['success']) {
                if (!empty($result['requires_2fa'])) {
                    // Redirigir a verificación 2FA
                    $redirectUrl = !empty($redirect)
                        ? APP_URL . $redirect
                        : APP_URL . '/index.php';
                    header('Location: ' . APP_URL . '/login.php?action=2fa&redirect=' .
                           urlencode($redirect));
                    exit;
                }
                // Login exitoso
                $redirectTo = !empty($redirect)
                    ? APP_URL . $redirect
                    : $result['redirect'];
                header('Location: ' . $redirectTo);
                exit;
            } else {
                $errors[]  = $result['message'];
                $formData  = ['email' => $email];
            }
        }

        // ── REGISTRO ──────────────────────────────────────────
        elseif ($postAction === 'register') {
            $result = $auth->register([
                'nombre'           => $_POST['nombre']           ?? '',
                'email'            => $_POST['email']            ?? '',
                'password'         => $_POST['password']         ?? '',
                'password_confirm' => $_POST['password_confirm'] ?? '',
            ]);

            if ($result['success']) {
                $redirectTo = !empty($redirect)
                    ? APP_URL . $redirect
                    : APP_URL . '/index.php';
                setFlashMessage('success',
                    '¡Bienvenido! Tu cuenta fue creada exitosamente.');
                header('Location: ' . $redirectTo);
                exit;
            } else {
                $errors[]  = $result['message'];
                $formData  = [
                    'nombre' => cleanInput($_POST['nombre'] ?? ''),
                    'email'  => cleanInput($_POST['email']  ?? ''),
                ];
            }
        }

        // ── RECUPERAR CONTRASEÑA ──────────────────────────────
        elseif ($postAction === 'forgot') {
            $email  = cleanInput($_POST['email'] ?? '');
            $result = $auth->solicitarRecuperacion($email);
            if ($result['success']) {
                $successMsg = $result['message'];
                if (APP_DEBUG && !empty($result['token_debug'])) {
                    $successMsg .= ' [DEBUG Token: ' . $result['token_debug'] . ']';
                }
            } else {
                $errors[] = $result['message'];
            }
        }

        // ── RESET CONTRASEÑA ──────────────────────────────────
        elseif ($postAction === 'reset') {
            $tokenPost = cleanInput($_POST['token']            ?? '');
            $nueva     = $_POST['password']                    ?? '';
            $confirmar = $_POST['password_confirm']            ?? '';

            if ($nueva !== $confirmar) {
                $errors[] = 'Las contraseñas no coinciden.';
            } else {
                $result = $auth->resetPassword($tokenPost, $nueva);
                if ($result['success']) {
                    setFlashMessage('success', $result['message']);
                    header('Location: ' . APP_URL . '/login.php');
                    exit;
                } else {
                    $errors[] = $result['message'];
                }
            }
        }

        // ── VERIFICAR 2FA ─────────────────────────────────────
        elseif ($postAction === '2fa') {
            // Unir los dígitos del código
            $digitos = '';
            for ($i = 1; $i <= 6; $i++) {
                $digitos .= cleanInput($_POST["digit_$i"] ?? '');
            }
            // También aceptar campo único
            if (empty($digitos)) {
                $digitos = cleanInput($_POST['codigo'] ?? '');
            }

            $result = $auth->verificar2FA($digitos);

            if ($result['success']) {
                $redirectTo = !empty($redirect)
                    ? APP_URL . $redirect
                    : $result['redirect'];
                header('Location: ' . $redirectTo);
                exit;
            } else {
                $errors[] = $result['message'];
            }
        }
    }
}

// ── Reset con token GET ───────────────────────────────────────
if ($action === 'reset' && !empty($token)) {
    // Verificar que el token es válido
    $tokenValido = db()->fetchOne(
        "SELECT id, usuario_id FROM dos_factor_auth
         WHERE codigo = ?
           AND tipo   = 'cambio_password'
           AND usado  = 0
           AND expira > NOW()
         LIMIT 1",
        [$token]
    );
    if (!$tokenValido) {
        setFlashMessage('error', 'El enlace de recuperación es inválido o ha expirado.');
        header('Location: ' . APP_URL . '/login.php?action=forgot');
        exit;
    }
}

// ── SEO ───────────────────────────────────────────────────────
$pageTitles = [
    'login'    => 'Iniciar Sesión',
    'register' => 'Crear Cuenta',
    'forgot'   => 'Recuperar Contraseña',
    'reset'    => 'Nueva Contraseña',
    '2fa'      => 'Verificación de Seguridad',
];

$pageTitle = ($pageTitles[$action] ?? 'Acceder') .
             ' — ' . Config::get('site_nombre', APP_NAME);
$bodyClass = 'page-auth';

$siteName = Config::get('site_nombre', APP_NAME);
$siteLogo = Config::get('site_logo', '');
$colorPrimario = Config::get('apariencia_color_primario', '#e63946');
?>
<!DOCTYPE html>
<html lang="es" data-theme="auto">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= e($pageTitle) ?></title>
    <meta name="robots" content="noindex, nofollow">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@700;800&display=swap"
          rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- CSS -->
    <link rel="stylesheet"
          href="<?= APP_URL ?>/assets/css/style.css?v=<?= APP_VERSION ?>">

    <style>
        /* ── Auth Page Layout ─────────────────────────────── */
        body.page-auth {
            min-height: 100vh;
            background: var(--secondary-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            padding-bottom: 20px; /* Sin bottom nav en auth */
        }

        .auth-wrapper {
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 100vh;
            width: 100%;
            max-width: 1100px;
            margin: auto;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 40px 80px rgba(0,0,0,.5);
        }

        /* Panel izquierdo: ilustración/branding */
        .auth-panel-left {
            background: linear-gradient(160deg,
                var(--primary) 0%,
                var(--secondary) 60%,
                var(--secondary-dark) 100%);
            padding: 60px 48px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .auth-panel-left::before {
            content: '';
            position: absolute;
            top: -100px;
            right: -100px;
            width: 400px;
            height: 400px;
            background: rgba(255,255,255,.04);
            border-radius: 50%;
        }

        .auth-panel-left::after {
            content: '';
            position: absolute;
            bottom: -80px;
            left: -80px;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,.03);
            border-radius: 50%;
        }

        .auth-brand {
            position: relative;
            z-index: 1;
        }

        .auth-brand-logo {
            font-family: var(--font-serif);
            font-size: 2.2rem;
            font-weight: 900;
            color: #fff;
            text-decoration: none;
            display: block;
            margin-bottom: 8px;
        }

        .auth-brand-tagline {
            font-size: .85rem;
            color: rgba(255,255,255,.6);
            text-transform: uppercase;
            letter-spacing: .12em;
        }

        .auth-panel-features {
            position: relative;
            z-index: 1;
        }

        .auth-feature-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 24px;
        }

        .auth-feature-icon {
            width: 42px;
            height: 42px;
            background: rgba(255,255,255,.12);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .auth-feature-text strong {
            display: block;
            color: #fff;
            font-size: .9rem;
            margin-bottom: 3px;
        }

        .auth-feature-text span {
            color: rgba(255,255,255,.55);
            font-size: .8rem;
            line-height: 1.4;
        }

        .auth-panel-footer {
            position: relative;
            z-index: 1;
            font-size: .75rem;
            color: rgba(255,255,255,.3);
        }

        /* Panel derecho: formulario */
        .auth-panel-right {
            background: var(--bg-surface);
            padding: 60px 48px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            overflow-y: auto;
        }

        .auth-form-header {
            margin-bottom: 36px;
        }

        .auth-form-title {
            font-family: var(--font-serif);
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 6px;
        }

        .auth-form-subtitle {
            color: var(--text-muted);
            font-size: .875rem;
        }

        /* Tabs login/registro */
        .auth-tabs {
            display: flex;
            background: var(--bg-surface-2);
            border-radius: var(--border-radius-full);
            padding: 4px;
            margin-bottom: 28px;
            gap: 4px;
        }

        .auth-tab-btn {
            flex: 1;
            padding: 10px 16px;
            border-radius: var(--border-radius-full);
            font-size: .875rem;
            font-weight: 600;
            color: var(--text-muted);
            transition: all .2s ease;
            text-align: center;
        }

        .auth-tab-btn.active {
            background: var(--bg-surface);
            color: var(--primary);
            box-shadow: var(--shadow-sm);
        }

        /* Input con icono */
        .input-group {
            position: relative;
        }

        .input-group-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            pointer-events: none;
            font-size: .9rem;
        }

        .input-group .form-input {
            padding-left: 40px;
        }

        /* Botón principal */
        .btn-auth {
            width: 100%;
            padding: 14px;
            background: var(--primary);
            color: #fff;
            border-radius: var(--border-radius-lg);
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: .02em;
            transition: all .2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 8px;
        }

        .btn-auth:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(230,57,70,.35);
        }

        .btn-auth:disabled {
            opacity: .6;
            cursor: not-allowed;
            transform: none;
        }

        /* Password strength */
        .password-strength {
            height: 4px;
            background: var(--bg-surface-3);
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            border-radius: 2px;
            transition: all .3s ease;
            width: 0%;
        }

        .strength-weak   { background: var(--danger);  }
        .strength-fair   { background: var(--warning); }
        .strength-good   { background: var(--info);    }
        .strength-strong { background: var(--success); }

        .password-strength-label {
            font-size: .72rem;
            margin-top: 4px;
        }

        /* 2FA digits */
        .twofa-container {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 24px 0;
        }

        .twofa-input {
            width: 50px;
            height: 62px;
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            background: var(--bg-surface);
            color: var(--text-primary);
            transition: border-color .2s ease;
        }

        .twofa-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(230,57,70,.15);
        }

        /* Separador */
        .auth-sep {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 20px 0;
            color: var(--text-muted);
            font-size: .8rem;
        }
        .auth-sep::before,
        .auth-sep::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border-color);
        }

        /* Error / Success alerts */
        .auth-alert {
            padding: 12px 16px;
            border-radius: var(--border-radius);
            font-size: .875rem;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .auth-alert-error {
            background: rgba(239,68,68,.1);
            color: #dc2626;
            border: 1px solid rgba(239,68,68,.25);
        }
        .auth-alert-success {
            background: rgba(34,197,94,.1);
            color: #16a34a;
            border: 1px solid rgba(34,197,94,.25);
        }

        /* Checkbox personalizado */
        .custom-check {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            font-size: .875rem;
            color: var(--text-secondary);
        }
        .custom-check input {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
        }

        /* Responsive */
        @media (max-width: 768px) {
            body.page-auth { padding: 0; }

            .auth-wrapper {
                grid-template-columns: 1fr;
                border-radius: 0;
                min-height: 100vh;
                box-shadow: none;
            }

            .auth-panel-left { display: none; }

            .auth-panel-right {
                padding: 40px 24px 60px;
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body class="page-auth">

<div class="auth-wrapper">

    <!-- ── PANEL IZQUIERDO (Branding) ──────────────────────── -->
    <div class="auth-panel-left">
        <div class="auth-brand">
            <a href="<?= APP_URL ?>/index.php" class="auth-brand-logo">
                <?php if ($siteLogo): ?>
                <img src="<?= APP_URL ?>/<?= e(ltrim($siteLogo, '/')) ?>"
                     alt="<?= e($siteName) ?>"
                     height="48" style="filter:brightness(0) invert(1)">
                <?php else: ?>
                <?= e($siteName) ?>
                <?php endif; ?>
            </a>
            <span class="auth-brand-tagline">
                <?= e(Config::get('site_tagline', APP_TAGLINE)) ?>
            </span>
        </div>

        <div class="auth-panel-features">
            <div class="auth-feature-item">
                <div class="auth-feature-icon">
                    <i class="bi bi-newspaper"></i>
                </div>
                <div class="auth-feature-text">
                    <strong>Noticias en tiempo real</strong>
                    <span>Las últimas noticias actualizadas las 24 horas del día, los 7 días de la semana.</span>
                </div>
            </div>
            <div class="auth-feature-item">
                <div class="auth-feature-icon">
                    <i class="bi bi-bookmark-heart-fill"></i>
                </div>
                <div class="auth-feature-text">
                    <strong>Guarda tus favoritas</strong>
                    <span>Crea tu biblioteca personal de noticias y accede a ellas desde cualquier dispositivo.</span>
                </div>
            </div>
            <div class="auth-feature-item">
                <div class="auth-feature-icon">
                    <i class="bi bi-bell-fill"></i>
                </div>
                <div class="auth-feature-text">
                    <strong>Alertas personalizadas</strong>
                    <span>Recibe notificaciones de las categorías que más te interesan.</span>
                </div>
            </div>
            <div class="auth-feature-item">
                <div class="auth-feature-icon">
                    <i class="bi bi-chat-dots-fill"></i>
                </div>
                <div class="auth-feature-text">
                    <strong>Participa en la conversación</strong>
                    <span>Comenta, reacciona y comparte tu opinión con la comunidad.</span>
                </div>
            </div>
        </div>

        <div class="auth-panel-footer">
            &copy; <?= date('Y') ?> <?= e($siteName) ?> · Todos los derechos reservados
        </div>
    </div>

    <!-- ── PANEL DERECHO (Formulario) ──────────────────────── -->
    <div class="auth-panel-right">

        <!-- Link volver (móvil) -->
        <div style="margin-bottom:24px">
            <a href="<?= APP_URL ?>/index.php"
               style="display:inline-flex;align-items:center;gap:6px;
                      color:var(--text-muted);font-size:.82rem;
                      text-decoration:none;transition:color .2s ease">
                <i class="bi bi-arrow-left"></i>
                Volver al inicio
            </a>
        </div>

        <!-- Logo en móvil -->
        <div style="display:none;margin-bottom:28px" id="mobileLogo">
            <a href="<?= APP_URL ?>/index.php"
               style="font-family:var(--font-serif);font-size:1.8rem;
                      font-weight:900;color:var(--primary);text-decoration:none">
                <?= e($siteName) ?>
            </a>
        </div>

        <!-- ── ERRORES Y MENSAJES ─────────────────────────── -->
        <?php if (!empty($errors)): ?>
        <div class="auth-alert auth-alert-error">
            <i class="bi bi-exclamation-circle-fill"
               style="font-size:1rem;flex-shrink:0;margin-top:1px"></i>
            <div>
                <?php foreach ($errors as $err): ?>
                <div><?= e($err) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($successMsg): ?>
        <div class="auth-alert auth-alert-success">
            <i class="bi bi-check-circle-fill"
               style="font-size:1rem;flex-shrink:0;margin-top:1px"></i>
            <div><?= e($successMsg) ?></div>
        </div>
        <?php endif; ?>

        <?php
        // ── FLASH MESSAGE ─────────────────────────────────────
        $flash = getFlashMessage();
        if ($flash):
        ?>
        <div class="auth-alert auth-alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>">
            <i class="bi bi-<?= $flash['type'] === 'error' ? 'exclamation-circle' : 'check-circle' ?>-fill"></i>
            <div><?= e($flash['message']) ?></div>
        </div>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════════
             FORMULARIO: LOGIN
             ════════════════════════════════════════════════ -->
        <?php if ($action === 'login'): ?>

        <div class="auth-form-header">
            <h1 class="auth-form-title">Bienvenido de nuevo</h1>
            <p class="auth-form-subtitle">
                Inicia sesión para acceder a tu cuenta
            </p>
        </div>

        <!-- Tabs Login / Registro -->
        <div class="auth-tabs">
            <button class="auth-tab-btn active"
                    onclick="setTab('login')">
                <i class="bi bi-box-arrow-in-right"></i>
                Iniciar Sesión
            </button>
            <button class="auth-tab-btn"
                    onclick="setTab('register')">
                <i class="bi bi-person-plus-fill"></i>
                Registrarse
            </button>
        </div>

        <form method="POST"
              action="<?= APP_URL ?>/login.php<?= $redirect ? '?redirect=' . urlencode($redirect) : '' ?>"
              id="loginForm"
              novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="login">

            <div class="form-group">
                <label class="form-label" for="loginEmail">
                    Correo electrónico
                </label>
                <div class="input-group">
                    <i class="bi bi-envelope-fill input-group-icon"></i>
                    <input type="email"
                           id="loginEmail"
                           name="email"
                           class="form-input"
                           value="<?= e($formData['email'] ?? '') ?>"
                           placeholder="tu@correo.com"
                           required
                           autocomplete="email"
                           maxlength="150">
                </div>
            </div>

            <div class="form-group">
                <div style="display:flex;justify-content:space-between;
                            align-items:center;margin-bottom:6px">
                    <label class="form-label" for="loginPassword"
                           style="margin:0">
                        Contraseña
                    </label>
                    <a href="<?= APP_URL ?>/login.php?action=forgot"
                       style="font-size:.78rem;color:var(--primary)">
                        ¿La olvidaste?
                    </a>
                </div>
                <div class="input-group">
                    <i class="bi bi-lock-fill input-group-icon"></i>
                    <input type="password"
                           id="loginPassword"
                           name="password"
                           class="form-input"
                           placeholder="Tu contraseña"
                           required
                           autocomplete="current-password"
                           maxlength="255">
                    <button type="button"
                            class="form-input-icon"
                            data-toggle-password="loginPassword"
                            style="pointer-events:all;cursor:pointer">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <div style="display:flex;align-items:center;
                        justify-content:space-between;margin-bottom:20px">
                <label class="custom-check">
                    <input type="checkbox" name="remember" value="1">
                    Mantener sesión iniciada
                </label>
            </div>

            <button type="submit" class="btn-auth" id="loginBtn">
                <i class="bi bi-box-arrow-in-right"></i>
                Iniciar Sesión
            </button>
        </form>

        <div class="auth-sep">o continúa con</div>

        <!-- Opciones sociales (placeholder) -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px">
            <button onclick="PDApp?.showToast('Próximamente disponible','info')"
                    style="display:flex;align-items:center;justify-content:center;
                           gap:8px;padding:11px;border:2px solid var(--border-color);
                           border-radius:var(--border-radius-lg);font-size:.82rem;
                           font-weight:600;color:var(--text-secondary);
                           background:var(--bg-surface);cursor:pointer;
                           transition:all .2s ease">
                <svg width="18" height="18" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                </svg>
                Google
            </button>
            <button onclick="PDApp?.showToast('Próximamente disponible','info')"
                    style="display:flex;align-items:center;justify-content:center;
                           gap:8px;padding:11px;border:2px solid var(--border-color);
                           border-radius:var(--border-radius-lg);font-size:.82rem;
                           font-weight:600;color:var(--text-secondary);
                           background:var(--bg-surface);cursor:pointer;
                           transition:all .2s ease">
                <i class="bi bi-facebook"
                   style="color:#1877F2;font-size:1rem"></i>
                Facebook
            </button>
        </div>

        <p style="text-align:center;font-size:.82rem;color:var(--text-muted)">
            ¿No tienes cuenta?
            <a href="#" onclick="setTab('register');return false"
               style="color:var(--primary);font-weight:600">
                Regístrate gratis
            </a>
        </p>

        <!-- ════════════════════════════════════════════════
             FORMULARIO: REGISTRO
             ════════════════════════════════════════════════ -->
        <?php elseif ($action === 'register'): ?>

        <div class="auth-form-header">
            <h1 class="auth-form-title">Crear cuenta gratis</h1>
            <p class="auth-form-subtitle">
                Únete a miles de lectores de <?= e($siteName) ?>
            </p>
        </div>

        <div class="auth-tabs">
            <button class="auth-tab-btn"
                    onclick="setTab('login')">
                <i class="bi bi-box-arrow-in-right"></i>
                Iniciar Sesión
            </button>
            <button class="auth-tab-btn active"
                    onclick="setTab('register')">
                <i class="bi bi-person-plus-fill"></i>
                Registrarse
            </button>
        </div>

        <form method="POST"
              action="<?= APP_URL ?>/login.php?action=register<?= $redirect ? '&redirect=' . urlencode($redirect) : '' ?>"
              id="registerForm"
              novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="register">

            <div class="form-group">
                <label class="form-label" for="regNombre">
                    Nombre completo
                </label>
                <div class="input-group">
                    <i class="bi bi-person-fill input-group-icon"></i>
                    <input type="text"
                           id="regNombre"
                           name="nombre"
                           class="form-input"
                           value="<?= e($formData['nombre'] ?? '') ?>"
                           placeholder="Tu nombre"
                           required
                           autocomplete="name"
                           minlength="2"
                           maxlength="100">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="regEmail">
                    Correo electrónico
                </label>
                <div class="input-group">
                    <i class="bi bi-envelope-fill input-group-icon"></i>
                    <input type="email"
                           id="regEmail"
                           name="email"
                           class="form-input"
                           value="<?= e($formData['email'] ?? '') ?>"
                           placeholder="tu@correo.com"
                           required
                           autocomplete="email"
                           maxlength="150">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="regPassword">
                    Contraseña
                </label>
                <div class="input-group">
                    <i class="bi bi-lock-fill input-group-icon"></i>
                    <input type="password"
                           id="regPassword"
                           name="password"
                           class="form-input"
                           placeholder="Mínimo 8 caracteres"
                           required
                           autocomplete="new-password"
                           minlength="8"
                           maxlength="255">
                    <button type="button"
                            class="form-input-icon"
                            data-toggle-password="regPassword"
                            style="pointer-events:all;cursor:pointer">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <!-- Medidor de seguridad -->
                <div class="password-strength">
                    <div class="password-strength-bar"
                         id="strengthBar"></div>
                </div>
                <div class="password-strength-label"
                     id="strengthLabel"
                     style="color:var(--text-muted)"></div>
            </div>

            <div class="form-group">
                <label class="form-label" for="regConfirm">
                    Confirmar contraseña
                </label>
                <div class="input-group">
                    <i class="bi bi-lock-fill input-group-icon"></i>
                    <input type="password"
                           id="regConfirm"
                           name="password_confirm"
                           class="form-input"
                           placeholder="Repite tu contraseña"
                           required
                           autocomplete="new-password"
                           maxlength="255">
                    <button type="button"
                            class="form-input-icon"
                            data-toggle-password="regConfirm"
                            style="pointer-events:all;cursor:pointer">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <div id="confirmMsg"
                     style="font-size:.72rem;margin-top:4px"></div>
            </div>

            <label class="custom-check"
                   style="margin-bottom:20px;align-items:flex-start">
                <input type="checkbox" required style="margin-top:2px">
                <span>
                    Acepto los
                    <a href="#" style="color:var(--primary)">Términos de Servicio</a>
                    y la
                    <a href="#" style="color:var(--primary)">Política de Privacidad</a>
                </span>
            </label>

            <button type="submit" class="btn-auth" id="registerBtn">
                <i class="bi bi-person-check-fill"></i>
                Crear Cuenta Gratis
            </button>
        </form>

        <p style="text-align:center;font-size:.82rem;
                   color:var(--text-muted);margin-top:16px">
            ¿Ya tienes cuenta?
            <a href="<?= APP_URL ?>/login.php<?= $redirect ? '?redirect=' . urlencode($redirect) : '' ?>"
               style="color:var(--primary);font-weight:600">
                Inicia sesión
            </a>
        </p>

        <!-- ════════════════════════════════════════════════
             FORMULARIO: RECUPERAR CONTRASEÑA
             ════════════════════════════════════════════════ -->
        <?php elseif ($action === 'forgot'): ?>

        <div class="auth-form-header">
            <div style="width:56px;height:56px;background:rgba(230,57,70,.1);
                        border-radius:14px;display:flex;align-items:center;
                        justify-content:center;margin-bottom:16px">
                <i class="bi bi-key-fill"
                   style="font-size:1.5rem;color:var(--primary)"></i>
            </div>
            <h1 class="auth-form-title">Recuperar contraseña</h1>
            <p class="auth-form-subtitle">
                Ingresa tu correo y te enviaremos instrucciones para recuperar el acceso.
            </p>
        </div>

        <?php if (!$successMsg): ?>
        <form method="POST"
              action="<?= APP_URL ?>/login.php?action=forgot"
              id="forgotForm"
              novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="forgot">

            <div class="form-group">
                <label class="form-label" for="forgotEmail">
                    Correo electrónico registrado
                </label>
                <div class="input-group">
                    <i class="bi bi-envelope-fill input-group-icon"></i>
                    <input type="email"
                           id="forgotEmail"
                           name="email"
                           class="form-input"
                           placeholder="tu@correo.com"
                           required
                           autocomplete="email">
                </div>
            </div>

            <button type="submit" class="btn-auth">
                <i class="bi bi-send-fill"></i>
                Enviar instrucciones
            </button>
        </form>
        <?php endif; ?>

        <p style="text-align:center;margin-top:20px;font-size:.82rem;
                   color:var(--text-muted)">
            <a href="<?= APP_URL ?>/login.php"
               style="color:var(--primary);font-weight:600;
                      display:inline-flex;align-items:center;gap:6px">
                <i class="bi bi-arrow-left"></i>
                Volver a iniciar sesión
            </a>
        </p>

        <!-- ════════════════════════════════════════════════
             FORMULARIO: NUEVA CONTRASEÑA (reset)
             ════════════════════════════════════════════════ -->
        <?php elseif ($action === 'reset' && !empty($token)): ?>

        <div class="auth-form-header">
            <div style="width:56px;height:56px;background:rgba(34,197,94,.1);
                        border-radius:14px;display:flex;align-items:center;
                        justify-content:center;margin-bottom:16px">
                <i class="bi bi-shield-lock-fill"
                   style="font-size:1.5rem;color:var(--success)"></i>
            </div>
            <h1 class="auth-form-title">Nueva contraseña</h1>
            <p class="auth-form-subtitle">
                Elige una contraseña segura para tu cuenta.
            </p>
        </div>

        <form method="POST"
              action="<?= APP_URL ?>/login.php?action=reset"
              novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <div class="form-group">
                <label class="form-label" for="resetPass">
                    Nueva contraseña
                </label>
                <div class="input-group">
                    <i class="bi bi-lock-fill input-group-icon"></i>
                    <input type="password"
                           id="resetPass"
                           name="password"
                           class="form-input"
                           placeholder="Mínimo 8 caracteres"
                           required
                           minlength="8">
                    <button type="button"
                            class="form-input-icon"
                            data-toggle-password="resetPass"
                            style="pointer-events:all;cursor:pointer">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <div class="password-strength">
                    <div class="password-strength-bar" id="strengthBar"></div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="resetConfirm">
                    Confirmar contraseña
                </label>
                <div class="input-group">
                    <i class="bi bi-lock-fill input-group-icon"></i>
                    <input type="password"
                           id="resetConfirm"
                           name="password_confirm"
                           class="form-input"
                           placeholder="Repite la contraseña"
                           required>
                </div>
            </div>

            <button type="submit" class="btn-auth">
                <i class="bi bi-check-circle-fill"></i>
                Guardar nueva contraseña
            </button>
        </form>

        <!-- ════════════════════════════════════════════════
             FORMULARIO: 2FA
             ════════════════════════════════════════════════ -->
        <?php elseif ($action === '2fa'): ?>

        <div class="auth-form-header" style="text-align:center">
            <div style="width:72px;height:72px;background:rgba(230,57,70,.1);
                        border-radius:20px;display:flex;align-items:center;
                        justify-content:center;margin:0 auto 20px;
                        animation:pulse 2s infinite">
                <i class="bi bi-shield-lock-fill"
                   style="font-size:2rem;color:var(--primary)"></i>
            </div>
            <h1 class="auth-form-title">Verificación de seguridad</h1>
            <p class="auth-form-subtitle">
                Ingresa el código de 6 dígitos que enviamos a tu correo electrónico.
            </p>
        </div>

        <form method="POST"
              action="<?= APP_URL ?>/login.php?action=2fa<?= $redirect ? '&redirect=' . urlencode($redirect) : '' ?>"
              id="twoFaForm"
              autocomplete="off"
              novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="2fa">

            <!-- Inputs de dígitos -->
            <div class="twofa-container" id="twofaContainer">
                <?php for ($i = 1; $i <= 6; $i++): ?>
                <input type="text"
                       class="twofa-input"
                       name="digit_<?= $i ?>"
                       id="digit<?= $i ?>"
                       maxlength="1"
                       pattern="[0-9]"
                       inputmode="numeric"
                       autocomplete="off"
                       required>
                <?php endfor; ?>
            </div>

            <!-- Campo oculto con código completo -->
            <input type="hidden" name="codigo" id="codigoCompleto">

            <button type="submit" class="btn-auth" id="twoFaBtn">
                <i class="bi bi-shield-check-fill"></i>
                Verificar código
            </button>
        </form>

        <div style="text-align:center;margin-top:20px">
            <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:8px">
                ¿No recibiste el código?
            </p>
            <button onclick="resendCode()"
                    id="resendBtn"
                    style="color:var(--primary);font-size:.82rem;
                           font-weight:600;background:none;border:none;
                           cursor:pointer">
                <i class="bi bi-arrow-clockwise"></i>
                Reenviar código
            </button>
            <div id="resendCountdown"
                 style="font-size:.78rem;color:var(--text-muted);
                        margin-top:4px;display:none">
            </div>
        </div>

        <p style="text-align:center;margin-top:20px;font-size:.82rem;
                   color:var(--text-muted)">
            <a href="<?= APP_URL ?>/login.php"
               style="color:var(--primary);font-weight:600;
                      display:inline-flex;align-items:center;gap:6px">
                <i class="bi bi-arrow-left"></i>
                Cancelar e iniciar sesión
            </a>
        </p>

        <?php endif; ?>

    </div><!-- /.auth-panel-right -->

</div><!-- /.auth-wrapper -->

<!-- Toast container -->
<div class="toast-container" id="toastContainer"></div>

<!-- JS -->
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const action = '<?= e($action) ?>';

    // ── Modo oscuro ───────────────────────────────────────────
    const saved = localStorage.getItem('pd_theme');
    if (saved) {
        document.documentElement.setAttribute('data-theme', saved);
    } else if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.setAttribute('data-theme', 'dark');
    }

    // ── Logo en móvil ─────────────────────────────────────────
    if (window.innerWidth <= 768) {
        document.getElementById('mobileLogo').style.display = 'block';
    }

    // ── Mostrar/ocultar contraseña ────────────────────────────
    document.querySelectorAll('[data-toggle-password]').forEach(btn => {
        btn.addEventListener('click', function () {
            const input = document.getElementById(this.dataset.togglePassword);
            if (!input) return;
            const isPass = input.type === 'password';
            input.type   = isPass ? 'text' : 'password';
            const icon   = this.querySelector('i');
            if (icon) {
                icon.className = isPass ? 'bi bi-eye-slash' : 'bi bi-eye';
            }
        });
    });

    // ── Tabs login/registro ───────────────────────────────────
    window.setTab = function (tab) {
        if (tab === 'register') {
            window.location.href = '<?= APP_URL ?>/login.php?action=register<?= $redirect ? "&redirect=" . urlencode($redirect) : "" ?>';
        } else {
            window.location.href = '<?= APP_URL ?>/login.php<?= $redirect ? "?redirect=" . urlencode($redirect) : "" ?>';
        }
    };

    // ── Medidor de seguridad de contraseña ────────────────────
    const passInput   = document.getElementById('regPassword')
                     ?? document.getElementById('resetPass');
    const strengthBar = document.getElementById('strengthBar');
    const strengthLbl = document.getElementById('strengthLabel');

    passInput?.addEventListener('input', function () {
        const val   = this.value;
        const score = calcPasswordStrength(val);
        const data  = [
            { pct: '0%',   color: '',                       label: '' },
            { pct: '25%',  color: 'var(--danger)',          label: '⚠️ Muy débil' },
            { pct: '50%',  color: 'var(--warning)',         label: '📊 Débil' },
            { pct: '75%',  color: 'var(--info)',            label: '👍 Buena' },
            { pct: '100%', color: 'var(--success)',         label: '🔒 Muy segura' },
        ][score];

        if (strengthBar) {
            strengthBar.style.width      = data.pct;
            strengthBar.style.background = data.color;
        }
        if (strengthLbl) {
            strengthLbl.textContent = data.label;
            strengthLbl.style.color = data.color;
        }
    });

    function calcPasswordStrength(password) {
        if (!password || password.length < 1) return 0;
        let score = 0;
        if (password.length >= 8)  score++;
        if (password.length >= 12) score++;
        if (/[A-Z]/.test(password)) score++;
        if (/[0-9]/.test(password)) score++;
        if (/[^A-Za-z0-9]/.test(password)) score++;
        return Math.min(4, Math.ceil(score / 1.2));
    }

    // ── Confirmar contraseña ──────────────────────────────────
    const confirmInput = document.getElementById('regConfirm');
    const confirmMsg   = document.getElementById('confirmMsg');

    confirmInput?.addEventListener('input', function () {
        const pass = passInput?.value ?? '';
        if (!this.value) {
            confirmMsg.textContent = '';
            return;
        }
        if (this.value === pass) {
            confirmMsg.textContent   = '✅ Las contraseñas coinciden';
            confirmMsg.style.color   = 'var(--success)';
            this.style.borderColor   = 'var(--success)';
        } else {
            confirmMsg.textContent   = '❌ Las contraseñas no coinciden';
            confirmMsg.style.color   = 'var(--danger)';
            this.style.borderColor   = 'var(--danger)';
        }
    });

    // ── 2FA: inputs navegación automática ────────────────────
    if (action === '2fa') {
        const inputs = document.querySelectorAll('.twofa-input');

        inputs.forEach((input, index) => {
            // Solo números
            input.addEventListener('keypress', (e) => {
                if (!/[0-9]/.test(e.key)) e.preventDefault();
            });

            input.addEventListener('input', function () {
                // Limpiar a 1 dígito
                this.value = this.value.replace(/[^0-9]/g, '').slice(-1);

                // Avanzar al siguiente
                if (this.value && index < inputs.length - 1) {
                    inputs[index + 1].focus();
                }

                // Si todos llenos, auto-submit
                const code = Array.from(inputs).map(i => i.value).join('');
                if (code.length === 6) {
                    document.getElementById('codigoCompleto').value = code;
                    // Pequeña pausa visual antes de submit
                    setTimeout(() => {
                        document.getElementById('twoFaBtn')?.click();
                    }, 200);
                }
            });

            input.addEventListener('keydown', function (e) {
                // Retroceder al anterior con Backspace
                if (e.key === 'Backspace' && !this.value && index > 0) {
                    inputs[index - 1].focus();
                    inputs[index - 1].value = '';
                }
                // Navegar con flechas
                if (e.key === 'ArrowRight' && index < inputs.length - 1) {
                    inputs[index + 1].focus();
                }
                if (e.key === 'ArrowLeft' && index > 0) {
                    inputs[index - 1].focus();
                }
            });

            // Pegar código completo
            input.addEventListener('paste', function (e) {
                e.preventDefault();
                const pasted = e.clipboardData.getData('text').replace(/[^0-9]/g, '');
                if (pasted.length >= 6) {
                    inputs.forEach((inp, i) => {
                        inp.value = pasted[i] ?? '';
                    });
                    document.getElementById('codigoCompleto').value = pasted.slice(0, 6);
                    inputs[5].focus();
                }
            });
        });

        // Focus en primer input
        inputs[0]?.focus();

        // Reenviar código con countdown
        let countdownInterval;
        let countdownTime = 0;

        window.resendCode = async function () {
            if (countdownTime > 0) return;

            const btn = document.getElementById('resendBtn');
            const countdown = document.getElementById('resendCountdown');

            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Enviando...';

            // En producción: llamada al servidor para reenviar
            // Por ahora simulamos éxito
            setTimeout(() => {
                btn.innerHTML = '<i class="bi bi-check-circle"></i> Código enviado';

                // Countdown de 60 segundos
                countdownTime = 60;
                countdown.style.display = 'block';

                countdownInterval = setInterval(() => {
                    countdownTime--;
                    countdown.textContent = `Puedes reenviar en ${countdownTime}s`;

                    if (countdownTime <= 0) {
                        clearInterval(countdownInterval);
                        btn.disabled  = false;
                        btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Reenviar código';
                        countdown.style.display = 'none';
                    }
                }, 1000);
            }, 1000);
        };
    }

    // ── Botones de submit con loading ─────────────────────────
    ['loginForm', 'registerForm', 'forgotForm', 'twoFaForm'].forEach(id => {
        const form = document.getElementById(id);
        form?.addEventListener('submit', function (e) {
            const btn  = this.querySelector('[type="submit"]');
            if (!btn || btn.disabled) return;

            // Validación básica antes de enviar
            const inputs = this.querySelectorAll('[required]');
            let valid = true;
            inputs.forEach(input => {
                if (!input.value.trim()) valid = false;
            });

            if (!valid) {
                e.preventDefault();
                PDApp?.showToast('Por favor completa todos los campos.', 'warning');
                return;
            }

            btn.disabled = true;
            const orig   = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Procesando...';

            // Por si el servidor demora, restaurar después de 8s
            setTimeout(() => {
                btn.disabled  = false;
                btn.innerHTML = orig;
            }, 8000);
        });
    });

    // ── Foco automático en primer campo ──────────────────────
    if (action === 'login') {
        document.getElementById('loginEmail')?.focus();
    } else if (action === 'register') {
        document.getElementById('regNombre')?.focus();
    } else if (action === 'forgot') {
        document.getElementById('forgotEmail')?.focus();
    }

    // ── Animación de entrada ──────────────────────────────────
    document.querySelector('.auth-panel-right').style.animation =
        'slideUp 0.4s ease';

});
</script>

</body>
</html>