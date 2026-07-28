<?php
/**
 * RentSphere - Shared Functions
 */

// ---------------------------------------------------------
// Auth Helpers
// ---------------------------------------------------------
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function current_user(): ?array
{
    if (!is_logged_in()) return null;
    static $cached = null;
    if ($cached !== null) return $cached;

    $db = Database::getConnection();
    $stmt = $db->prepare("SELECT id, company_id, role, first_name, last_name, email, photo_path
                           FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['user_id']]);
    $cached = $stmt->fetch() ?: null;
    return $cached;
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect(APP_URL . '/auth/login.php');
    }
}

function require_role(array $roles): void
{
    require_login();
    $user = current_user();
    if (!$user || !in_array($user['role'], $roles, true)) {
        http_response_code(403);
        die('Access denied. You do not have permission to view this page.');
    }
}

// ---------------------------------------------------------
// Activity / Audit Logging
// ---------------------------------------------------------
function log_activity(string $action, string $description = '', ?int $userId = null, ?int $companyId = null): void
{
    try {
        $db = Database::getConnection();
        $userId = $userId ?? ($_SESSION['user_id'] ?? null);
        $companyId = $companyId ?? ($_SESSION['company_id'] ?? null);
        $stmt = $db->prepare("INSERT INTO activity_logs (user_id, company_id, action, description, ip_address)
                               VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $companyId, $action, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {
        error_log('log_activity failed: ' . $e->getMessage());
    }
}

function log_login_attempt(int $userId, string $status): void
{
    $db = Database::getConnection();
    $stmt = $db->prepare("INSERT INTO login_history (user_id, ip_address, user_agent, status)
                           VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null, $status]);
}

// ---------------------------------------------------------
// Notifications
// ---------------------------------------------------------
function create_notification(int $companyId, string $type, string $title, string $message,
                              ?int $userId = null, ?int $tenantId = null): void
{
    $db = Database::getConnection();
    $stmt = $db->prepare("INSERT INTO notifications (company_id, recipient_user_id, recipient_tenant_id, type, title, message)
                           VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$companyId, $userId, $tenantId, $type, $title, $message]);
}

function unread_notification_count(int $userId): int
{
    $db = Database::getConnection();
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

// ---------------------------------------------------------
// Rate Limiting / Brute Force Protection
// ---------------------------------------------------------
function is_account_locked(array $user): bool
{
    return !empty($user['locked_until']) && strtotime($user['locked_until']) > time();
}

function register_failed_login(int $userId): void
{
    $db = Database::getConnection();
    $stmt = $db->prepare("SELECT failed_login_attempts FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $attempts = (int) $stmt->fetchColumn() + 1;

    if ($attempts >= MAX_LOGIN_ATTEMPTS) {
        $lockUntil = date('Y-m-d H:i:s', time() + LOCKOUT_DURATION_SECONDS);
        $stmt = $db->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?");
        $stmt->execute([$attempts, $lockUntil, $userId]);
    } else {
        $stmt = $db->prepare("UPDATE users SET failed_login_attempts = ? WHERE id = ?");
        $stmt->execute([$attempts, $userId]);
    }
}

function reset_failed_logins(int $userId): void
{
    $db = Database::getConnection();
    $stmt = $db->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?");
    $stmt->execute([$userId]);
}

// ---------------------------------------------------------
// Formatting
// ---------------------------------------------------------
function money(float $amount): string
{
    return number_format($amount, 2);
}

function format_date(?string $date, string $format = 'M j, Y'): string
{
    if (empty($date)) return '—';
    return date($format, strtotime($date));
}

// ---------------------------------------------------------
// Mailer (PHPMailer wrapper)
// Requires PHPMailer installed via Composer in /vendor,
// or the 3 PHPMailer source files placed in /vendor/phpmailer/.
// ---------------------------------------------------------
function send_email(string $toEmail, string $toName, string $subject, string $htmlBody): bool
{
    require_once ROOT_PATH . '/vendor/autoload.php'; // composer autoload

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = MAIL_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_USERNAME;
        $mail->Password = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION;
        $mail->Port = MAIL_PORT;

        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Mail error: ' . ($mail->ErrorInfo ?? $e->getMessage()));
        echo '<pre style="background-color: #fee; padding: 10px;">MAIL ERROR: ' . htmlspecialchars($mail->ErrorInfo ?? $e->getMessage()) . '</pre>';
        return false;
    }
}

// ---------------------------------------------------------
// OTP Generation
// ---------------------------------------------------------
function generate_otp(): string
{
    return (string) random_int(100000, 999999);
}
