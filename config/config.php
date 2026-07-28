<?php
/**
 * RentSphere - Core Configuration
 * Loaded by every page via includes/bootstrap.php
 */

// ---------------------------------------------------------
// Environment
// ---------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0'); // never show raw errors to users
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

define('APP_NAME', 'RentSphere');
define('APP_TAGLINE', 'Smart Property Management, Simplified.');
define('APP_URL', 'http://localhost/rentsphere'); // change to your local path
define('APP_ENV', 'development'); // 'development' | 'production'

// Branding
define('COLOR_PRIMARY', '#2563EB');
define('COLOR_SECONDARY', '#4F46E5');
define('COLOR_ACCENT', '#06B6D4');

// Paths
define('ROOT_PATH', dirname(__DIR__));
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('UPLOAD_URL', APP_URL . '/uploads');

// Security
define('SESSION_TIMEOUT_SECONDS', 20 * 60);      // auto-logout after 20 min idle
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION_SECONDS', 15 * 60);     // 15 min lockout after too many failed logins
define('OTP_EXPIRY_SECONDS', 10 * 60);
define('REMEMBER_ME_DAYS', 30);
define('PASSWORD_RESET_EXPIRY_SECONDS', 30 * 60);

// Mail (SMTP) - override actual secrets in config/mail.local.php (gitignored) if desired
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USERNAME', 'kelvinnjuguna.nj@gmail.com');
define('MAIL_PASSWORD', 'jquknnmhvaktuuzx');
define('MAIL_ENCRYPTION', 'tls');
define('MAIL_FROM_ADDRESS', 'no-reply@rentsphere.test');
define('MAIL_FROM_NAME', APP_NAME);

// ---------------------------------------------------------
// Timezone
// ---------------------------------------------------------
date_default_timezone_set('UTC');

// ---------------------------------------------------------
// Secure Session Setup
// Must run BEFORE session_start(). This file is included
// at the very top of bootstrap.php before anything else.
// ---------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,     // set true automatically once you're on HTTPS
        'httponly' => true,       // JS cannot read the session cookie
        'samesite' => 'Lax',
    ]);

    session_name('rentsphere_sid');
    session_start();

    // Idle timeout check
    if (isset($_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT_SECONDS) {
        // Session expired
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        session_start();
        $_SESSION['flash_error'] = 'Your session has expired. Please log in again.';
    }
    $_SESSION['last_activity'] = time();

    // Regenerate session ID periodically to prevent fixation
    if (empty($_SESSION['created_at'])) {
        $_SESSION['created_at'] = time();
    } elseif (time() - $_SESSION['created_at'] > 300) {
        session_regenerate_id(true);
        $_SESSION['created_at'] = time();
    }
}

// ---------------------------------------------------------
// CSRF Protection Helpers
// ---------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify(?string $token): bool
{
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_verify_or_die(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        http_response_code(403);
        die('Invalid or expired security token. Please refresh the page and try again.');
    }
}

// ---------------------------------------------------------
// Output / Input Helpers
// ---------------------------------------------------------
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function old(string $key, $default = '')
{
    return e($_SESSION['old_input'][$key] ?? $default);
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash_' . $type] = $message;
}

function get_flash(string $type): ?string
{
    if (!empty($_SESSION['flash_' . $type])) {
        $msg = $_SESSION['flash_' . $type];
        unset($_SESSION['flash_' . $type]);
        return $msg;
    }
    return null;
}
